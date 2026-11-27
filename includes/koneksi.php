<?php
defined('ROOT') || exit;

// Satu koneksi PostgreSQL (PDO) per request, dipakai semua modul dan handler session (includes/sesi.php).
// Sumber konfigurasi:
//  - Cloud/produksi (Supabase Session Pooler): environment PHOTOOPS_DB_HOST, PHOTOOPS_DB_PORT, PHOTOOPS_DB_NAME,
//    PHOTOOPS_DB_USER, PHOTOOPS_DB_PASSWORD, PHOTOOPS_DB_SSLMODE, PHOTOOPS_DB_SSLROOTCERT. Dipakai bila PHOTOOPS_DB_HOST
//    diisi. Di mode produksi sumber ini WAJIB lengkap; tidak ada fallback diam-diam ke database lain.
//  - Development lokal: config/database.local.php (diabaikan Git); PHOTOOPS_DB_NAME boleh menimpa nama database (uji).

// Root CA Supabase di image container (lihat Dockerfile); dipakai bila PHOTOOPS_DB_SSLROOTCERT tidak diisi.
const CA_DB_PRODUKSI = '/opt/photoops/supabase-ca.crt';

// Dijalankan sekali saat koneksi dibuka (satu round trip), hasilnya true bila role berhak istimewa:
//  1. Aplikasi hanya boleh memakai role terbatas, bukan admin database (superuser, atau role Supabase `postgres` yang
//     punya CREATEROLE/BYPASSRLS) -> koneksi ditolak.
//  2. pg_advisory_unlock_all(): melepas advisory lock TINGKAT SESI yang masih menempel di koneksi backend ini, bila
//     pooler (Supavisor session mode) memakai ulang backend tanpa reset. Hanya memengaruhi koneksi ini sendiri (backend
//     session mode tidak dipakai bersama klien lain), tidak memengaruhi lock transaksi (FOR UPDATE/FOR SHARE/LOCK TABLE,
//     pg_advisory_xact_lock), dan pada titik ini request belum memegang kunci apa pun. PhotoOps memakai advisory lock
//     hanya untuk penguncian session (includes/sesi.php). Transaction Pooler (port 6543), tempat backend dipakai bersama,
//     ditolak di konfigurasi.
const SQL_CEK_KONEKSI = "WITH lepas AS (SELECT pg_advisory_unlock_all())
    SELECT current_setting('is_superuser') = 'on' OR r.rolcreaterole OR r.rolcreatedb OR r.rolreplication OR r.rolbypassrls
    FROM pg_roles r CROSS JOIN lepas WHERE r.rolname = current_user";

function konfigurasi_db(): array
{
    if (env_photoops('PHOTOOPS_DB_HOST') !== null) {
        return konfigurasi_db_env();
    }
    if (mode_produksi()) {
        throw galat_konfigurasi('mode produksi membutuhkan PHOTOOPS_DB_HOST dan variabel database lainnya.');
    }
    $berkas = ROOT . '/config/database.local.php';
    $k = is_file($berkas) ? require $berkas : [];
    if (env_photoops('PHOTOOPS_DB_NAME') !== null) {
        $k['dbname'] = env_photoops('PHOTOOPS_DB_NAME');
    }
    return $k + ['host' => 'localhost', 'port' => '5432', 'dbname' => 'photoops_si3_dev', 'user' => 'photoops_si3_app', 'password' => '',
        'sslmode' => null, 'sslrootcert' => null];
}

function konfigurasi_db_env(): array
{
    $kurang = array_values(array_filter(['PHOTOOPS_DB_NAME', 'PHOTOOPS_DB_USER', 'PHOTOOPS_DB_PASSWORD'],
        fn ($nama) => env_photoops($nama) === null));
    if ($kurang) {
        throw galat_konfigurasi('variabel database belum diisi: ' . implode(', ', $kurang) . '.');
    }
    $port = env_photoops('PHOTOOPS_DB_PORT') ?? '5432';
    if (!ctype_digit($port)) {
        throw galat_konfigurasi('PHOTOOPS_DB_PORT harus angka.');
    }
    if ($port === '6543') {
        throw galat_konfigurasi('port 6543 (Transaction Pooler) tidak mendukung prepared statement; pakai Session Pooler port 5432.');
    }
    // Produksi wajib terenkripsi; bawaannya verify-full (sertifikat dan nama host diverifikasi dengan root CA Supabase).
    $boleh = mode_produksi() ? ['require', 'verify-ca', 'verify-full'] : ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
    $sslmode = env_photoops('PHOTOOPS_DB_SSLMODE') ?? (mode_produksi() ? 'verify-full' : 'prefer');
    if (!in_array($sslmode, $boleh, true)) {
        throw galat_konfigurasi('PHOTOOPS_DB_SSLMODE harus salah satu dari: ' . implode(', ', $boleh) . '.');
    }
    $ca = env_photoops('PHOTOOPS_DB_SSLROOTCERT') ?? (mode_produksi() ? CA_DB_PRODUKSI : null);
    if (str_starts_with($sslmode, 'verify-')) {
        if ($ca === null || !is_file($ca)) {
            throw galat_konfigurasi("sslmode $sslmode membutuhkan berkas root CA (PHOTOOPS_DB_SSLROOTCERT) yang ada.");
        }
        // libpq membaca "\" sebagai karakter escape di string koneksi; Windows juga menerima "/". Spasi dan tanda kutip
        // memotong nilai di string koneksi. Path produksi (/opt/photoops/supabase-ca.crt) tidak terpengaruh.
        if (PHP_OS_FAMILY === 'Windows') {
            $ca = str_replace('\\', '/', $ca);
        }
        if (preg_match('/[\s\'"\\\\]/', $ca)) {
            throw galat_konfigurasi('PHOTOOPS_DB_SSLROOTCERT tidak boleh memuat spasi, tanda kutip, atau backslash.');
        }
    } else {
        $ca = null;
    }
    $k = [
        'host' => env_photoops('PHOTOOPS_DB_HOST'), 'port' => $port, 'dbname' => env_photoops('PHOTOOPS_DB_NAME'),
        'user' => env_photoops('PHOTOOPS_DB_USER'), 'password' => getenv('PHOTOOPS_DB_PASSWORD'),
        'sslmode' => $sslmode, 'sslrootcert' => $ca,
    ];
    // Nilai DSN dipisah titik koma, jadi tidak boleh memuatnya (password tidak masuk DSN).
    foreach (['host', 'dbname', 'sslrootcert'] as $kunci) {
        if ($k[$kunci] !== null && strpbrk($k[$kunci], ';') !== false) {
            throw galat_konfigurasi("nilai $kunci database tidak valid.");
        }
    }
    return $k;
}

function pdo(): PDO
{
    static $pdo = null;
    static $gagal = false;
    if ($pdo) {
        return $pdo;
    }
    if ($gagal) {
        throw new RuntimeException('Database tidak tersedia.', 503);
    }
    $gagal = true;
    $k = konfigurasi_db();
    $dsn = "pgsql:host={$k['host']};port={$k['port']};dbname={$k['dbname']};connect_timeout=5;application_name=photoops";
    if (!empty($k['sslmode'])) {
        $dsn .= ";sslmode={$k['sslmode']}";
    }
    if (!empty($k['sslrootcert'])) {
        $dsn .= ";sslrootcert={$k['sslrootcert']}";
    }
    try {
        // Prepared statement native (bukan emulasi): didukung Supabase Session Pooler, tidak didukung Transaction Pooler.
        $koneksi = new PDO($dsn, $k['user'], $k['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $istimewa = $koneksi->query(SQL_CEK_KONEKSI)->fetchColumn();
    } catch (PDOException $e) {
        // Pesan PDO tidak memuat password; detailnya hanya ke log server, bukan ke layar.
        error_log('PhotoOps: koneksi database gagal: ' . $e->getMessage());
        throw new RuntimeException('Database tidak tersedia.', 503);
    }
    if ($istimewa !== false) {
        error_log('PhotoOps: koneksi ditolak karena memakai role database dengan hak istimewa (admin).');
        throw new RuntimeException('Database tidak tersedia.', 503);
    }
    $gagal = false;
    return $pdo = $koneksi;
}

// Menjalankan kueri dengan prepared statement. Input pengguna selalu lewat $param, tidak digabung ke teks SQL.
function sql_jalankan(string $sql, array $param = []): PDOStatement
{
    $perintah = pdo()->prepare($sql);
    $perintah->execute($param);
    return $perintah;
}

function sql_semua(string $sql, array $param = []): array
{
    return sql_jalankan($sql, $param)->fetchAll();
}

function sql_satu(string $sql, array $param = []): ?array
{
    $baris = sql_jalankan($sql, $param)->fetch();
    return $baris === false ? null : $baris;
}

// BEGIN ... COMMIT; bila ada error di tengah, semua perubahan dibatalkan (ROLLBACK).
function sql_transaksi(callable $kerja)
{
    $koneksi = pdo();
    $koneksi->beginTransaction();
    try {
        $hasil = $kerja();
        $koneksi->commit();
        return $hasil;
    } catch (Throwable $e) {
        $koneksi->rollBack();
        throw $e;
    }
}

function galat_db(Throwable $e): bool
{
    return $e instanceof PDOException || ($e instanceof RuntimeException && $e->getCode() === 503);
}

// Galat database yang tidak ditangani halaman: dicatat di log server, layar hanya menampilkan pesan umum.
function tangani_galat_db(Throwable $e): void
{
    if (!galat_db($e)) {
        if (!mode_produksi()) {
            throw $e;
        }
        // Produksi: galat lain juga tidak ditampilkan (bisa memuat path/query); detailnya hanya di log server.
        error_log('PhotoOps: galat tak tertangani: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Terjadi kesalahan</title>'
            . '<p>Terjadi kesalahan pada PhotoOps. Coba beberapa saat lagi.</p></html>';
        return;
    }
    error_log('PhotoOps: galat database: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Layanan tidak tersedia</title>'
        . '<p>Layanan database PhotoOps sedang tidak tersedia. Coba beberapa saat lagi.</p></html>';
}
