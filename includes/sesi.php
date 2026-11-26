<?php
defined('ROOT') || exit;

// PHP Session PhotoOps ($_SESSION: user_id, csrf, flash, _form, _sandi_sementara).
//   PHOTOOPS_SESSION=db   disimpan di tabel PostgreSQL sesi_php. Wajib di produksi: Vercel menjalankan banyak instance
//                         container tanpa disk bersama, jadi session file tidak bisa dipakai.
//   tidak diisi / file    session file bawaan PHP (development lokal, seperti sebelumnya).
// Tidak ada fallback: bila mode db aktif dan database tidak bisa diakses, request berhenti (503), bukan pindah ke file.

const SESI_UMUR = 7200;            // detik tanpa aktivitas sebelum session kedaluwarsa (2 jam)
const SESI_KUNCI_NS = 470117;      // namespace advisory lock PhotoOps (argumen pertama pg_try_advisory_lock(int, int))
const SESI_TUNGGU_KUNCI = 8.0;     // detik maksimal menunggu request lain dengan session yang sama

// Handler session PostgreSQL. Memakai koneksi PDO yang sama dengan modul bisnis (satu koneksi per request).
//
// Penguncian: seperti session file PHP (flock), request dengan session ID yang sama dijalankan bergiliran, sehingga dua
// tab yang mengirim form bersamaan tidak saling menimpa flash/CSRF/form. Kuncinya advisory lock tingkat koneksi
// (pg_try_advisory_lock), bukan transaksi: transaksi bisnis (sql_transaksi, FOR UPDATE, LOCK TABLE) tetap berjalan
// normal, dan tidak ada siklus deadlock karena request kedua menunggu di awal request sebelum memegang kunci lain.
// Kunci dilepas di close() (request normal, exception, maupun fatal error: session ditutup di shutdown), dan koneksi baru
// selalu memulai dengan pg_advisory_unlock_all() (includes/koneksi.php), sehingga kunci tidak terbawa bila pooler memakai
// ulang koneksi backend. Fail-closed: bila kunci tidak didapat dalam SESI_TUNGGU_KUNCI detik, read() melempar galat 503
// SEBELUM data session dibaca. Session tidak dimulai, tidak ditulis, dan tidak dihapus; request berikutnya memakai data
// session yang utuh.
final class SesiPostgres implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?string $terkunci = null;

    public function __construct(private int $umur)
    {
    }

    public function open(string $path, string $name): bool
    {
        pdo(); // database tidak tersedia -> RuntimeException 503 (fail-closed)
        return true;
    }

    public function close(): bool
    {
        $this->lepas_kunci();
        return true;
    }

    public function read(string $id): string|false
    {
        $this->kunci($id);
        $baris = sql_satu("SELECT data FROM sesi_php WHERE id = ? AND diperbarui_pada > now() - CAST(? AS integer) * INTERVAL '1 second'",
            [$id, $this->umur]);
        if ($baris === null) {
            return '';
        }
        $data = base64_decode($baris['data'], true);
        if ($data === false) {
            error_log('PhotoOps: data session rusak, diabaikan.');
            return '';
        }
        return $data;
    }

    public function write(string $id, string $data): bool
    {
        return $this->simpan('INSERT INTO sesi_php (id, data, diperbarui_pada) VALUES (?, ?, now())
            ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, diperbarui_pada = now()', [$id, base64_encode($data)]);
    }

    // session.lazy_write: isi session tidak berubah, cukup perpanjang masa aktifnya.
    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            if (sql_jalankan('UPDATE sesi_php SET diperbarui_pada = now() WHERE id = ?', [$id])->rowCount() > 0) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('PhotoOps: gagal memperbarui waktu session: ' . $e->getMessage());
            return false;
        }
        return $this->write($id, $data);
    }

    // session.use_strict_mode: ID dari cookie hanya diterima bila session-nya ada dan belum kedaluwarsa. ID buatan
    // penyerang (session fixation) atau ID lama ditolak, lalu PHP membuat ID baru.
    public function validateId(string $id): bool
    {
        return sql_satu("SELECT 1 AS ada FROM sesi_php WHERE id = ? AND diperbarui_pada > now() - CAST(? AS integer) * INTERVAL '1 second'",
            [$id, $this->umur]) !== null;
    }

    public function destroy(string $id): bool
    {
        return $this->simpan('DELETE FROM sesi_php WHERE id = ?', [$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return sql_jalankan("DELETE FROM sesi_php WHERE diperbarui_pada < now() - CAST(? AS integer) * INTERVAL '1 second'",
                [$max_lifetime])->rowCount();
        } catch (Throwable $e) {
            error_log('PhotoOps: gc session gagal: ' . $e->getMessage());
            return false;
        }
    }

    private function simpan(string $sql, array $param): bool
    {
        try {
            $koneksi = pdo();
            // Transaksi bisnis yang tertinggal terbuka (tidak normal) tidak boleh ikut "menelan" penulisan session.
            if ($koneksi->inTransaction()) {
                error_log('PhotoOps: transaksi masih terbuka saat menulis session; dibatalkan.');
                $koneksi->rollBack();
            }
            sql_jalankan($sql, $param);
            return true;
        } catch (Throwable $e) {
            error_log('PhotoOps: gagal menulis session: ' . $e->getMessage());
            return false;
        }
    }

    private function kunci(string $id): void
    {
        if ($this->terkunci === $id) {
            return;
        }
        $this->lepas_kunci();
        $batas = microtime(true) + SESI_TUNGGU_KUNCI;
        $jeda = 20000;
        while (true) {
            $dapat = sql_satu('SELECT pg_try_advisory_lock(CAST(? AS integer), hashtext(?)) AS dapat', [SESI_KUNCI_NS, $id])['dapat'];
            if ($dapat) {
                $this->terkunci = $id;
                return;
            }
            if (microtime(true) >= $batas) {
                error_log('PhotoOps: session masih dikunci request lain setelah ' . SESI_TUNGGU_KUNCI . ' detik; request dihentikan (503).');
                throw new RuntimeException('Session sedang dipakai request lain.', 503);
            }
            usleep($jeda);
            $jeda = min($jeda * 2, 200000);
        }
    }

    private function lepas_kunci(): void
    {
        if ($this->terkunci === null) {
            return;
        }
        $id = $this->terkunci;
        $this->terkunci = null;
        try {
            sql_jalankan('SELECT pg_advisory_unlock(CAST(? AS integer), hashtext(?))', [SESI_KUNCI_NS, $id]);
        } catch (Throwable $e) {
            error_log('PhotoOps: gagal melepas kunci session: ' . $e->getMessage());
        }
    }
}

function mode_sesi(): string
{
    $mode = env_photoops('PHOTOOPS_SESSION') ?? 'file';
    if (!in_array($mode, ['file', 'db'], true)) {
        throw galat_konfigurasi('PHOTOOPS_SESSION harus "db" atau "file".');
    }
    if (mode_produksi() && $mode !== 'db') {
        throw galat_konfigurasi('mode produksi membutuhkan PHOTOOPS_SESSION=db (session file tidak bertahan antar-instance).');
    }
    return $mode;
}

// Cookie Secure ditentukan konfigurasi server (produksi selalu HTTPS di Vercel) atau koneksi HTTPS langsung,
// bukan dari header X-Forwarded-Proto yang bisa dipalsukan klien.
function sesi_https(): bool
{
    return mode_produksi() || (($_SERVER['HTTPS'] ?? '') !== '' && strtolower($_SERVER['HTTPS']) !== 'off');
}

function mulai_sesi(): void
{
    // CLI uji (tests/) memulai session-nya sendiri sebelum memuat halaman.
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $mode = mode_sesi();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) SESI_UMUR);
    $https = sesi_https();
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    // Prefix __Host- (hanya lewat HTTPS): browser menolak cookie ini bila diset tanpa Secure, dengan Domain, atau dari subdomain.
    session_name($https ? '__Host-photoops' : 'photoops');
    if ($mode === 'db') {
        session_set_save_handler(new SesiPostgres(SESI_UMUR), false);
        // Session ditulis PALING AKHIR (sesudah shutdown function lain, misalnya sinkron persiapan yang memanggil flash()),
        // selagi koneksi PDO masih hidup.
        register_shutdown_function(function () {
            register_shutdown_function('session_write_close');
        });
    }
    if (!session_start()) {
        throw galat_konfigurasi('session tidak dapat dimulai.');
    }
}
