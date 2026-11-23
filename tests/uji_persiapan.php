<?php
// Uji Sprint Backend 4B: Persiapan H-1 (checklist, konfirmasi, pembatalan) di PostgreSQL + gerbang Barang Keluar.
// HANYA untuk database uji (nama berakhiran _test), karena uji ini menambah dan mengubah data:
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_persiapan.php
//
// - Proyek uji berawalan "UJI4B" (jadwal Mei–Juni 2027, plus satu proyek dengan batas persiapan yang sudah lewat).
//   Proyek uji yang sampai bertransaksi diarsipkan oleh tests/bantu_bersihkan.php (transaksi tidak boleh dihapus).
// - Unit Studio dipilih otomatis (aktif, Baik, tidak sedang keluar); kondisinya dikembalikan di akhir uji.
// - Data contoh hanya dibaca.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_uji4b_sesi');

// ---------------------------------------------------------------------------------------------
// Mode anak: satu request halaman, hasilnya dicetak sebagai JSON.
// ---------------------------------------------------------------------------------------------
if (($argv[1] ?? '') === '--permintaan') {
    $r = json_decode(stream_get_contents(STDIN), true);
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($r['sid']);
    session_start();
    if ($r['user_id'] === null) {
        unset($_SESSION['user_id']);
    } else {
        $_SESSION['user_id'] = $r['user_id'];
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    unset($_SESSION['flash']);
    $_SERVER['REQUEST_METHOD'] = $r['post'] === null ? 'GET' : 'POST';
    $_SERVER['SCRIPT_FILENAME'] = AKAR_UJI . '/' . $r['halaman'];
    $_SERVER['SCRIPT_NAME'] = '/photoops/' . $r['halaman'];
    $_SERVER['REQUEST_URI'] = '/photoops/' . $r['halaman'] . ($r['get'] ? '?' . http_build_query($r['get']) : '');
    $_GET = $r['get'];
    $_POST = $r['post'] ?? [];
    if ($r['post'] !== null && $r['csrf'] !== false) {
        $_POST['csrf'] = $r['csrf'] === true ? $_SESSION['csrf'] : $r['csrf'];
    }
    ob_start();
    register_shutdown_function(function () {
        register_shutdown_function(function () {
            $html = '';
            while (ob_get_level() > 0) {
                $html = ob_get_clean() . $html;
            }
            echo "\n@@HASIL@@" . json_encode([
                'flash' => $_SESSION['flash'] ?? [],
                'form' => $_SESSION['_form'] ?? null,
                'html' => $html,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        });
    });
    chdir(dirname($_SERVER['SCRIPT_FILENAME']));
    require $_SERVER['SCRIPT_FILENAME'];
    exit;
}

// Mode anak: session "lama" (sebelum 4B) yang mengklaim persiapan proyek uji sudah dikonfirmasi dan dicentang.
if (($argv[1] ?? '') === '--sesi-lama') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $pid = (int) $argv[3];
    $_SESSION = ['db_versi' => 6, 'db' => [
        'persiapan' => [99 => ['id' => 99, 'proyek_id' => $pid, 'diperiksa' => array_map('intval', explode(',', $argv[4])),
            'diperbarui_pada' => '2026-11-07 08:00', 'diperbarui_oleh' => 2, 'dikonfirmasi_pada' => '2026-11-07 08:05', 'dikonfirmasi_oleh' => 2]],
        'insiden' => [],
        'riwayat' => [500 => ['id' => 500, 'waktu' => '2026-11-07 08:05', 'pengguna_id' => 2, 'aksi' => 'Persiapan', 'objek' => 'PRJ-SESI',
            'keterangan' => 'Persiapan dikonfirmasi selesai (session lama)', 'lama' => 'Siap untuk Dikonfirmasi', 'baru' => 'Dikonfirmasi Selesai']],
    ]];
    session_write_close();
    exit;
}

if (($argv[1] ?? '') === '--periksa-sesi') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $db = $_SESSION['db'] ?? [];
    echo json_encode(['kunci' => array_keys($db), 'riwayat_aksi' => array_values(array_unique(array_column($db['riwayat'] ?? [], 'aksi')))]);
    exit;
}

// Mode anak: memanggil fungsi PHP langsung (bersamaan dan rollback), tanpa pemeriksaan halaman.
if (($argv[1] ?? '') === '--fungsi') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id('fungsi' . getmypid() . mt_rand());
    session_start();
    require AKAR_UJI . '/includes/bootstrap.php';
    restore_exception_handler();
    $jenis = $argv[2];
    $pid = (int) ($argv[3] ?? 0);
    $arg = array_slice($argv, 4);
    $_SESSION['user_id'] = 2;
    $kini = date('Y-m-d H:i', strtotime(sekarang()));
    try {
        if ($jenis === 'konfirmasi') {
            echo json_encode(['status' => konfirmasi_persiapan($pid, 2)['status']]);
        } elseif ($jenis === 'checklist') {
            echo json_encode(['status' => simpan_checklist_persiapan($pid, $arg[0] === '' ? [] : array_map('intval', explode(',', $arg[0])), 2)['status']]);
        } elseif ($jenis === 'status') {
            echo json_encode(['status' => ubah_status_alokasi($pid, (int) $arg[0], $arg[1])['status']]);
        } elseif ($jenis === 'kebutuhan') {
            echo json_encode(['status' => simpan_kebutuhan($pid, 0, ['nama' => 'UJI4B Tambahan', 'acuan' => '', 'kategori_id' => 1, 'jumlah' => 1])['status']]);
        } elseif ($jenis === 'buka') {
            echo json_encode(['status' => cabut_konfirmasi_persiapan($pid, 'uji buka ulang', 2)['status']]);
        } elseif ($jenis === 'keluar') {
            $a = cari_alokasi((int) $arg[0]);
            $h = catat_barang_keluar(cari_proyek($pid), [['alokasi' => $a, 'jumlah' => jumlah_alokasi($a)]], cari_pengguna(3), $kini, 'uji');
            echo json_encode(['status' => $h['status'], 'masalah' => implode(' ', $h['masalah'] ?? [])]);
        } elseif ($jenis === 'kondisi_unit') {
            // Perubahan kondisi unit di Inventaris (sama dengan POST halaman Inventaris), lalu sinkron seperti bootstrap.
            sql_transaksi(fn () => ubah_kondisi_unit((int) $arg[0], $arg[1]));
            sinkron_konfirmasi_persiapan();
            echo json_encode(['status' => 'ok', 'flash' => implode(' ', array_column($_SESSION['flash'] ?? [], 'pesan'))]);
        } elseif (str_starts_with($jenis, 'rb_')) {
            // Pengguna sesi 99999 tidak ada: INSERT riwayat_aktivitas (langkah terakhir) melanggar FOREIGN KEY -> ROLLBACK.
            $_SESSION['user_id'] = 99999;
            if ($jenis === 'rb_konfirmasi') {
                konfirmasi_persiapan($pid, 2);
            } elseif ($jenis === 'rb_checklist') {
                simpan_checklist_persiapan($pid, [], 2);
            } else {
                cabut_konfirmasi_persiapan($pid, 'uji rollback buka ulang', 2);
            }
            echo json_encode(['status' => 'TANPA_GALAT']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'GAGAL_PDO:' . $e->getCode()]);
    }
    exit;
}

// ---------------------------------------------------------------------------------------------
// Mode utama
// ---------------------------------------------------------------------------------------------
date_default_timezone_set('Asia/Jakarta');
$k = require AKAR_UJI . '/config/database.local.php';
if (getenv('PHOTOOPS_DB_NAME')) {
    $k['dbname'] = getenv('PHOTOOPS_DB_NAME');
}
if (!preg_match('/_test$/', $k['dbname'])) {
    echo "[BERHENTI] Uji ini mengubah data. Jalankan dengan PHOTOOPS_DB_NAME=photoops_si3_test (sekarang: {$k['dbname']}).\n";
    exit(1);
}
$pdo = new PDO("pgsql:host={$k['host']};port={$k['port']};dbname={$k['dbname']}", $k['user'], $k['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function satu(string $sql, array $p = [])
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

function semua(string $sql, array $p = []): array
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchAll();
}

function jalankan(string $sql, array $p = []): void
{
    global $pdo;
    $pdo->prepare($sql)->execute($p);
}

// Checklist contoh PRJ-021 (proyek 3) harus masih sesuai seed: alokasi 13, 14, 16, 17 dicentang, belum dikonfirmasi.
if (satu("SELECT string_agg(a.id::text, ',' ORDER BY a.id) FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id WHERE k.proyek_id = 3 AND a.diperiksa") !== '13,14,16,17'
    || satu('SELECT dikonfirmasi_pada FROM persiapan_proyek WHERE proyek_id = 3') !== null) {
    echo "[BERHENTI] Persiapan contoh PRJ-021 di database uji tidak lagi sesuai seed. Buat ulang database uji dari 001 + 002 + 003.\n";
    exit(1);
}

require __DIR__ . '/bantu_bersihkan.php';
$kondisi_asal = [];
function bersihkan(): void
{
    global $kondisi_asal;
    arsipkan_proyek_uji('UJI4B');
    foreach ($kondisi_asal as $id => $kondisi) {
        jalankan('UPDATE perlengkapan SET kondisi = :k WHERE id = :id', ['k' => $kondisi, 'id' => $id]);
    }
}
bersihkan();
register_shutdown_function('bersihkan');
define('RIWAYAT_AWAL', (int) satu('SELECT COALESCE(MAX(id), 0) FROM riwayat_aktivitas'));
if (!is_dir(FOLDER_SESI)) {
    mkdir(FOLDER_SESI);
}
foreach (glob(FOLDER_SESI . '/sess_*') as $lama) {
    unlink($lama);
}

$lulus = 0;
$gagal = 0;
function cek(string $nama, bool $hasil, string $info = ''): void
{
    global $lulus, $gagal;
    echo ($hasil ? '[LULUS] ' : '[GAGAL] ') . $nama . ($info !== '' ? ' — ' . $info : '') . PHP_EOL;
    $hasil ? $lulus++ : $gagal++;
}

function proses_anak(array $arg): array
{
    $perintah = array_merge([PHP_BINARY, __FILE__], $arg);
    return [proc_open($perintah, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa, AKAR_UJI, getenv()), $pipa];
}

function minta(string $sid, ?int $user, string $halaman, array $get = [], ?array $post = null, $csrf = true): array
{
    [$proses, $pipa] = proses_anak(['--permintaan']);
    fwrite($pipa[0], json_encode(['sid' => $sid, 'user_id' => $user, 'halaman' => $halaman, 'get' => $get, 'post' => $post, 'csrf' => $csrf]));
    fclose($pipa[0]);
    $keluar = stream_get_contents($pipa[1]);
    $galat = stream_get_contents($pipa[2]);
    proc_close($proses);
    $pos = strrpos($keluar, '@@HASIL@@');
    $hasil = $pos === false ? ['flash' => [], 'form' => null, 'html' => ''] : json_decode(substr($keluar, $pos + 9), true);
    $hasil['mentah'] = ($pos === false ? $keluar : substr($keluar, 0, $pos)) . $galat;
    $hasil['pesan'] = implode(' | ', array_column($hasil['flash'], 'pesan'));
    $hasil['galat'] = implode(' | ', $hasil['form']['galat'] ?? []);
    return $hasil;
}

function anak(array $arg): string
{
    [$proses, $pipa] = proses_anak($arg);
    fclose($pipa[0]);
    $keluar = stream_get_contents($pipa[1]) . stream_get_contents($pipa[2]);
    proc_close($proses);
    return trim($keluar);
}

function fungsi(string $jenis, int $pid, array $tambahan = []): array
{
    return json_decode(anak(array_merge(['--fungsi', $jenis, (string) $pid], array_map('strval', $tambahan))), true) ?? [];
}

function paralel(array $arg_a, array $arg_b): array
{
    $pr = [proses_anak($arg_a), proses_anak($arg_b)];
    $hasil = [];
    foreach ($pr as [$proses, $pipa]) {
        fclose($pipa[0]);
        $hasil[] = json_decode(stream_get_contents($pipa[1]) . stream_get_contents($pipa[2]), true) ?? [];
        proc_close($proses);
    }
    return $hasil;
}

function bersih(array $h): bool
{
    $teks = $h['html'] . $h['mentah'];
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|Parse error)\b|Layanan database PhotoOps sedang tidak tersedia/', $teks);
}

function riwayat_baru(string $objek, string $ket_like, string $baru = '%'): int
{
    return (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Persiapan' AND objek = :o AND keterangan LIKE :k AND baru LIKE :b AND id > :awal",
        ['o' => $objek, 'k' => $ket_like, 'b' => $baru, 'awal' => RIWAYAT_AWAL]);
}

function dikonfirmasi(int $pid): bool
{
    return satu('SELECT dikonfirmasi_pada FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $pid]) !== null;
}

function centang(int $pid): string
{
    return (string) satu("SELECT COALESCE(string_agg(a.id::text, ',' ORDER BY a.id), '') FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
                          WHERE k.proyek_id = :p AND a.diperiksa", ['p' => $pid]);
}

function tercentang_di_html(string $html, int $aid): bool
{
    return (bool) preg_match('/name="diperiksa\[\]" value="' . $aid . '" checked/', $html);
}

function jumlah_keluar(int $aid): int
{
    return (int) satu("SELECT COUNT(*) FROM detail_transaksi d JOIN transaksi_barang t ON t.id = d.transaksi_id WHERE d.alokasi_id = :a AND t.jenis = 'Keluar'", ['a' => $aid]);
}

function waktu_kini(): string
{
    return '2026-11-07T' . date('H:i');
}

const SA = 1, PI = 2, RIZKY = 3, NINA = 7;
$L = 'pages/proyek.php';
$D = 'pages/proyek-detail.php';
$S = 'pages/serah-terima.php';

function buat_proyek_uji(string $nama, string $mulai, string $deadline): int
{
    global $L, $D;
    minta('admin', SA, $L, [], ['aksi' => 'simpan_proyek', 'id' => '', 'nama' => $nama, 'klien' => 'Klien Uji', 'lokasi' => 'Malang',
        'mulai' => $mulai . 'T08:00', 'selesai' => $mulai . 'T17:00', 'deadline_persiapan' => $deadline, 'pic_id' => (string) RIZKY,
        'status' => 'Dijadwalkan', 'catatan' => '']);
    $pid = (int) satu('SELECT id FROM proyek WHERE nama = :n', ['n' => $nama]);
    minta('admin', SA, $D, ['id' => (string) $pid], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) NINA]);
    return $pid;
}

function alokasi_uji(int $pid, string $nama, int $kategori, array $isi): int
{
    global $D;
    $jumlah = $isi['sumber'] === 'Studio' ? 1 : (int) $isi['jumlah'];
    minta('admin', SA, $D, ['id' => (string) $pid], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => $nama, 'acuan' => '', 'kategori_id' => (string) $kategori, 'jumlah' => (string) $jumlah]);
    $kid = (int) satu('SELECT id FROM kebutuhan_proyek WHERE proyek_id = :p AND nama = :n', ['p' => $pid, 'n' => $nama]);
    minta('admin', SA, $D, ['id' => (string) $pid], $isi + ['aksi' => 'tambah_alokasi', 'kebutuhan_id' => (string) $kid, 'perlengkapan_id' => '', 'nama_barang' => '',
        'vendor' => '', 'pemilik_id' => '', 'jumlah' => '1', 'status' => 'Dikonfirmasi', 'catatan' => '']);
    return (int) satu('SELECT id FROM alokasi_barang WHERE kebutuhan_id = :k', ['k' => $kid]);
}

function kru_uji(int $pid, string $nama): int
{
    return alokasi_uji($pid, $nama, 5, ['sumber' => 'Milik Kru', 'nama_barang' => $nama . ' barang', 'pemilik_id' => (string) NINA, 'jumlah' => '1']);
}

function simpan_centang(string $sid, int $user, int $pid, array $aids, $csrf = true): array
{
    global $D;
    return minta($sid, $user, $D, ['id' => (string) $pid], ['aksi' => 'simpan_persiapan', 'diperiksa' => array_map('strval', $aids)], $csrf);
}

function konfirmasi(string $sid, int $user, int $pid, $csrf = true): array
{
    global $D;
    return minta($sid, $user, $D, ['id' => (string) $pid], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => (string) $pid, 'konfirmasi' => '1'], $csrf);
}

function form_keluar(int $pid, array $jumlah): array
{
    return ['aksi' => 'checkout', 'proyek_id' => (string) $pid, 'alokasi' => array_map('strval', array_keys($jumlah)),
        'jumlah' => array_map('strval', $jumlah), 'penerima_id' => (string) RIZKY, 'waktu' => waktu_kini(), 'catatan' => '', 'konfirmasi' => '1'];
}

echo "Database uji: {$k['dbname']}\n\n== Persiapan data uji\n";
$unit = semua("SELECT u.id, u.kode_unit, u.kategori_id, u.kondisi FROM perlengkapan u
               WHERE u.status_aktif = 'Aktif' AND u.kondisi = 'Baik'
                 AND NOT EXISTS (SELECT 1 FROM alokasi_barang a JOIN v_saldo_alokasi v ON v.alokasi_id = a.id WHERE a.perlengkapan_id = u.id AND v.sisa > 0)
                 AND NOT EXISTS (SELECT 1 FROM alokasi_barang a WHERE a.perlengkapan_id = u.id AND a.status <> 'Dibatalkan' AND a.tgl_selesai >= '2027-05-01')
               ORDER BY u.id LIMIT 2");
if (count($unit) < 2) {
    echo "[BERHENTI] Database uji tidak punya 2 unit Studio aktif berkondisi Baik yang bebas.\n";
    exit(1);
}
foreach ($unit as $u) {
    $kondisi_asal[(int) $u['id']] = $u['kondisi'];
}
[$U1, $U2] = $unit;

$p1 = buat_proyek_uji('UJI4B Satu', '2027-05-10', '2027-05-09T20:00');
$aStudio = alokasi_uji($p1, 'UJI4B Kamera', (int) $U1['kategori_id'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U1['id']]);
$aRental = alokasi_uji($p1, 'UJI4B Lighting', 3, ['sumber' => 'Rental', 'nama_barang' => 'Lampu 4B', 'vendor' => 'Vendor 4B', 'jumlah' => '2']);
$aKru = kru_uji($p1, 'UJI4B SD');
$kode1 = (string) satu('SELECT kode FROM proyek WHERE id = :p', ['p' => $p1]);
cek('Persiapan: proyek uji dengan alokasi Studio, Rental (belum diterima), Milik Kru; persiapan belum dikonfirmasi',
    $p1 && $aStudio && $aRental && $aKru && !dikonfirmasi($p1) && centang($p1) === '');

echo "\n== P01–P04 Checklist\n";
$h = minta('petugasB', PI, $D, ['id' => '3', 'tab' => 'persiapan']);
cek('P01a checklist contoh PRJ-021 dibaca dari PostgreSQL (alokasi 13, 14, 16, 17 tercentang)', bersih($h)
    && tercentang_di_html($h['html'], 13) && tercentang_di_html($h['html'], 17) && !tercentang_di_html($h['html'], 15));
jalankan('UPDATE alokasi_barang SET diperiksa = TRUE WHERE id = :a', ['a' => $aKru]);
$h = minta('petugasB', PI, $D, ['id' => (string) $p1, 'tab' => 'persiapan']);
cek('P01b centang yang ditulis langsung ke PostgreSQL tampil di halaman (bukan dari session)', tercentang_di_html($h['html'], $aKru) && !tercentang_di_html($h['html'], $aStudio));
jalankan('UPDATE alokasi_barang SET diperiksa = FALSE WHERE id = :a', ['a' => $aKru]);
$h = simpan_centang('admin', SA, $p1, [$aStudio, $aRental, 999999]);
cek('P02a simpan checklist: centang tersimpan di alokasi_barang.diperiksa; rental belum diterima dan id asing diabaikan',
    centang($p1) === (string) $aStudio && str_contains($h['pesan'], '2 centang diabaikan'), $h['pesan']);
cek('P02b waktu dan pengguna yang memperbarui tersimpan di persiapan_proyek', (int) satu('SELECT diperbarui_oleh FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $p1]) === SA
    && satu('SELECT diperbarui_pada FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $p1]) !== null);
cek('P02c riwayat progres checklist tercatat di PostgreSQL', riwayat_baru($kode1, '1 dari 3 barang diperiksa') === 1);
$h = minta('petugasB', PI, $D, ['id' => (string) $p1, 'tab' => 'persiapan']);
cek('P03 perubahan checklist terlihat di browser lain', bersih($h) && tercentang_di_html($h['html'], $aStudio) && str_contains($h['html'], '1 dari 3'));
$h = minta('adminLoginUlang', SA, $D, ['id' => (string) $p1, 'tab' => 'persiapan']);
cek('P04 checklist tetap ada setelah login ulang (session baru)', bersih($h) && tercentang_di_html($h['html'], $aStudio));

echo "\n== P06, P15 Konfirmasi sebelum lengkap\n";
$h = konfirmasi('admin', SA, $p1);
cek('P06a konfirmasi sebelum semua barang diperiksa ditolak', !dikonfirmasi($p1) && str_contains($h['pesan'], 'belum bisa dikonfirmasi'), $h['pesan']);
cek('P15a rental yang belum diterima dari vendor menahan konfirmasi (tidak ada kesiapan palsu)', str_contains($h['pesan'], 'belum tercatat diterima dari vendor'), $h['pesan']);
cek('P06b ditolak juga di dalam transaksi PostgreSQL', (fungsi('konfirmasi', $p1)['status'] ?? '') === 'belum_siap' && !dikonfirmasi($p1));
jalankan('UPDATE alokasi_barang SET diperiksa = TRUE WHERE id = :a', ['a' => $aRental]);
cek('P15b centang rental yang ditulis sebelum penerimaan tetap tidak membuat persiapan siap', (fungsi('konfirmasi', $p1)['status'] ?? '') === 'belum_siap' && !dikonfirmasi($p1));
minta('admin', SA, 'pages/perlengkapan.php', ['tab' => 'eksternal'], ['aksi' => 'terima_rental', 'id' => (string) $aRental, 'proyek_id' => (string) $p1,
    'jumlah' => '2', 'waktu' => '2026-11-07T00:01', 'kondisi' => 'Baik', 'catatan' => '', 'konfirmasi' => '1']);
cek('P15c penerimaan vendor melepas centang lama rental (harus diperiksa ulang)', centang($p1) === (string) $aStudio);
simpan_centang('admin', SA, $p1, [$aStudio, $aRental]);
$h = konfirmasi('admin', SA, $p1);
cek('P06c konfirmasi saat Milik Kru belum dicentang ditolak', !dikonfirmasi($p1) && str_contains($h['pesan'], 'belum diperiksa'), $h['pesan']);

echo "\n== P05, P07, P08 Konfirmasi\n";
simpan_centang('admin', SA, $p1, [$aStudio, $aRental, $aKru]);
$h = konfirmasi('admin', SA, $p1);
cek('P05a konfirmasi persiapan yang lengkap tersimpan di persiapan_proyek (waktu dan pengguna)', dikonfirmasi($p1) && str_contains($h['pesan'], 'dikonfirmasi selesai')
    && (int) satu('SELECT dikonfirmasi_oleh FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $p1]) === SA, $h['pesan']);
cek('P05b riwayat konfirmasi di PostgreSQL mencatat batas persiapan saat itu', riwayat_baru($kode1, '%batas persiapan 2027-05-09 20:00', 'Dikonfirmasi Selesai') === 1);
$h = konfirmasi('petugasB', PI, $p1);
cek('P07a konfirmasi kedua (browser lain) ditolak, tidak dicatat dua kali', str_contains($h['pesan'], 'tidak dicatat dua kali') && riwayat_baru($kode1, '%', 'Dikonfirmasi Selesai') === 1, $h['pesan']);
$p2 = buat_proyek_uji('UJI4B Dua', '2027-05-20', '2027-05-19T20:00');
$a2 = kru_uji($p2, 'UJI4B Dua');
simpan_centang('admin', SA, $p2, [$a2]);
$kode2 = (string) satu('SELECT kode FROM proyek WHERE id = :p', ['p' => $p2]);
[$x, $y] = paralel(['--fungsi', 'konfirmasi', (string) $p2], ['--fungsi', 'konfirmasi', (string) $p2]);
$st = [$x['status'] ?? '?', $y['status'] ?? '?'];
sort($st);
cek('P07b dua konfirmasi bersamaan -> satu tercatat, satu ditolak; riwayat tidak ganda', $st === ['ok', 'sudah'] && dikonfirmasi($p2)
    && riwayat_baru($kode2, '%', 'Dikonfirmasi Selesai') === 1, json_encode([$x, $y]));
$h = minta('petugasB', PI, $D, ['id' => (string) $p1, 'tab' => 'persiapan']);
cek('P08a browser lain melihat status Persiapan Dikonfirmasi Selesai', bersih($h) && str_contains($h['html'], 'Persiapan Dikonfirmasi Selesai'));
$h = minta('rizkyB', RIZKY, $D, ['id' => (string) $p1, 'tab' => 'persiapan']);
cek('P08b User/Kru (PIC) di browser lain melihat status yang sama', bersih($h) && str_contains($h['html'], 'Persiapan Dikonfirmasi Selesai'));

echo "\n== P11 Perubahan setelah konfirmasi\n";
$kid = (int) satu('SELECT kebutuhan_id FROM alokasi_barang WHERE id = :a', ['a' => $aKru]);
$cek_ditolak = [
    'tambah kebutuhan' => ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'UJI4B Baru', 'acuan' => '', 'kategori_id' => '1', 'jumlah' => '1'],
    'batalkan alokasi' => ['aksi' => 'batalkan_alokasi', 'id' => (string) $aKru],
    'hapus alokasi' => ['aksi' => 'hapus_alokasi', 'id' => (string) $aKru],
    'hapus kebutuhan' => ['aksi' => 'hapus_kebutuhan', 'id' => (string) $kid],
    'ubah checklist' => ['aksi' => 'simpan_persiapan', 'diperiksa' => [(string) $aStudio]],
];
foreach ($cek_ditolak as $ket => $post) {
    $h = minta('petugasB', PI, $D, ['id' => (string) $p1], $post);
    cek('P11a ' . $ket . ' dari browser lain ditolak selama konfirmasi berlaku', str_contains($h['pesan'], 'dikunci') && dikonfirmasi($p1), $h['pesan']);
}
cek('P11b data tidak berubah: alokasi dan centang tetap', satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aKru]) === 'Dikonfirmasi'
    && centang($p1) === implode(',', [$aStudio, $aRental, $aKru]));
cek('P11c pengaman di dalam transaksi: kebutuhan/alokasi/checklist ditolak tanpa pemeriksaan halaman',
    (fungsi('kebutuhan', $p1)['status'] ?? '') === 'persiapan_terkunci' && (fungsi('status', $p1, [$aKru, 'batalkan'])['status'] ?? '') === 'persiapan_terkunci'
    && (fungsi('checklist', $p1, [''])['status'] ?? '') === 'terkunci');
// Perubahan di luar Persiapan (unit menjadi Maintenance di Inventaris) -> konfirmasi dicabut otomatis, tidak "Siap" palsu.
$r = fungsi('kondisi_unit', 0, [$U1['id'], 'Maintenance']);
cek('P11d unit Studio menjadi Maintenance -> konfirmasi dicabut otomatis + riwayat "Perlu konfirmasi ulang"', !dikonfirmasi($p1)
    && riwayat_baru($kode1, 'Konfirmasi persiapan selesai tidak berlaku lagi:%', 'Perlu konfirmasi ulang') === 1
    && (int) satu('SELECT batal_otomatis::int FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $p1]) === 1, $r['flash'] ?? '');
$h = minta('petugasB', PI, $S, [], form_keluar($p1, [$aKru => 1]));
cek('P11e setelah dicabut otomatis, barang keluar ditolak di semua browser', jumlah_keluar($aKru) === 0 && str_contains($h['pesan'], 'belum bisa dicatat'), $h['pesan']);
jalankan("UPDATE perlengkapan SET kondisi = 'Baik' WHERE id = :u", ['u' => $U1['id']]);
$h = konfirmasi('petugasB', PI, $p1);
cek('P11f setelah unit kembali Baik, persiapan bisa dikonfirmasi ulang dari browser lain', dikonfirmasi($p1), $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $p1], ['aksi' => 'buka_ulang_persiapan', 'proyek_id' => (string) $p1, 'alasan' => 'Ganti unit kamera']);
cek('P11g buka ulang manual: konfirmasi dicabut, alasan dan pelaku tersimpan, riwayat "Dibuka ulang"', !dikonfirmasi($p1)
    && satu('SELECT batal_alasan FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $p1]) === 'Ganti unit kamera'
    && (int) satu('SELECT batal_otomatis::int FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $p1]) === 0
    && riwayat_baru($kode1, 'Persiapan dibuka ulang: Ganti unit kamera', 'Dibuka ulang') === 1, $h['pesan']);
$h = minta('petugasB', PI, $D, ['id' => (string) $p1], ['aksi' => 'batalkan_alokasi', 'id' => (string) $aKru]);
cek('P11h setelah dibuka ulang, alokasi bisa dibatalkan (centangnya ikut dilepas)', satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aKru]) === 'Dibatalkan'
    && !str_contains(centang($p1), (string) $aKru), $h['pesan']);
// Alokasi pengganti untuk kebutuhan yang sama (kebutuhan lama kurang 1 unit setelah alokasinya dibatalkan).
minta('admin', SA, $D, ['id' => (string) $p1], ['aksi' => 'tambah_alokasi', 'kebutuhan_id' => (string) $kid, 'sumber' => 'Milik Kru', 'perlengkapan_id' => '',
    'nama_barang' => 'SD Card pengganti', 'vendor' => '', 'pemilik_id' => (string) NINA, 'jumlah' => '1', 'status' => 'Dikonfirmasi', 'catatan' => '']);
$aKru = (int) satu("SELECT id FROM alokasi_barang WHERE kebutuhan_id = :k AND status = 'Dikonfirmasi'", ['k' => $kid]);
simpan_centang('admin', SA, $p1, [$aStudio, $aRental, $aKru]);
$h = konfirmasi('admin', SA, $p1);
cek('P11i setelah perubahan, konfirmasi ulang berhasil', dikonfirmasi($p1), $h['pesan']);

echo "\n== P12 Konfirmasi bersamaan dengan perubahan\n";
foreach ([1, 2] as $i) {
    $pid = buat_proyek_uji('UJI4B Balapan ' . $i, '2027-06-0' . ($i + 1), '2027-06-0' . $i . 'T20:00');
    $aid = kru_uji($pid, 'UJI4B Balapan ' . $i);
    simpan_centang('admin', SA, $pid, [$aid]);
    [$x, $y] = paralel(['--fungsi', 'konfirmasi', (string) $pid], ['--fungsi', 'status', (string) $pid, (string) $aid, 'batalkan']);
    $status = satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aid]);
    $sah = (dikonfirmasi($pid) && $status === 'Dikonfirmasi' && ($x['status'] ?? '') === 'ok' && ($y['status'] ?? '') === 'persiapan_terkunci')
        || (!dikonfirmasi($pid) && $status === 'Dibatalkan' && ($y['status'] ?? '') === 'ok' && ($x['status'] ?? '') === 'belum_siap');
    cek('P12a (' . $i . ') konfirmasi vs batal alokasi bersamaan -> tidak ada "Siap" palsu', $sah, json_encode(['konfirmasi' => $x, 'batal' => $y, 'status' => $status]));
}
$pid = buat_proyek_uji('UJI4B Balapan 3', '2027-06-05', '2027-06-04T20:00');
$aid = kru_uji($pid, 'UJI4B Balapan 3');
simpan_centang('admin', SA, $pid, [$aid]);
[$x, $y] = paralel(['--fungsi', 'konfirmasi', (string) $pid], ['--fungsi', 'checklist', (string) $pid, '']);
$sah = (dikonfirmasi($pid) && centang($pid) === (string) $aid && ($y['status'] ?? '') === 'terkunci')
    || (!dikonfirmasi($pid) && centang($pid) === '' && ($x['status'] ?? '') === 'belum_siap');
cek('P12b konfirmasi vs hapus centang bersamaan -> tidak ada "Siap" palsu', $sah, json_encode(['konfirmasi' => $x, 'checklist' => $y, 'centang' => centang($pid)]));

echo "\n== P09, P10, P20 Barang keluar dan pengembalian\n";
$p3 = buat_proyek_uji('UJI4B Tiga', '2027-06-10', '2027-06-09T20:00');
$a3 = kru_uji($p3, 'UJI4B Tiga');
$h = minta('admin', SA, $S, [], form_keluar($p3, [$a3 => 1]));
cek('P10a tanpa konfirmasi, barang keluar ditolak (halaman)', jumlah_keluar($a3) === 0 && str_contains($h['pesan'], 'belum bisa dicatat'), $h['pesan']);
cek('P10b tanpa konfirmasi, barang keluar ditolak di dalam transaksi PostgreSQL', (fungsi('keluar', $p3, [$a3])['status'] ?? '') === 'ditolak' && jumlah_keluar($a3) === 0);
anak(['--sesi-lama', 'sesiPalsu', (string) $p3, (string) $a3]);
$h = minta('sesiPalsu', SA, $S, [], form_keluar($p3, [$a3 => 1]));
cek('P18a session lama yang mengklaim persiapan dikonfirmasi tidak bisa melewati gerbang', jumlah_keluar($a3) === 0 && str_contains($h['pesan'], 'belum bisa dicatat'), $h['pesan']);
$s = json_decode(anak(['--periksa-sesi', 'sesiPalsu']), true);
cek('P18b salinan persiapan dan log Persiapan di session lama dibuang (sejak 4C tidak ada data bisnis di session)', ($s['kunci'] ?? ['x']) === []
    && !in_array('Persiapan', $s['riwayat_aksi'] ?? ['Persiapan'], true), json_encode($s));
$h = minta('sesiPalsu', SA, $D, ['id' => (string) $p3, 'tab' => 'persiapan']);
cek('P18c halaman menampilkan status PostgreSQL (belum dikonfirmasi, belum dicentang)', bersih($h) && !str_contains($h['html'], 'Persiapan Dikonfirmasi Selesai')
    && !tercentang_di_html($h['html'], $a3));
simpan_centang('admin', SA, $p3, [$a3]);
konfirmasi('admin', SA, $p3);
$h = minta('petugasLain', PI, $S, [], form_keluar($p3, [$a3 => 1]));
cek('P09 persiapan dikonfirmasi di browser A, barang keluar dicatat dari browser B (gerbang membaca PostgreSQL)', jumlah_keluar($a3) === 1, $h['pesan'] . $h['galat']);
$h = minta('admin', SA, $D, ['id' => (string) $p3], ['aksi' => 'buka_ulang_persiapan', 'proyek_id' => (string) $p3, 'alasan' => 'Coba buka setelah keluar']);
cek('P20a setelah barang keluar, persiapan tidak bisa dibuka ulang (beku)', dikonfirmasi($p3) && str_contains($h['pesan'], 'sudah tercatat keluar'), $h['pesan']);
$h = minta('admin', SA, $S, [], ['aksi' => 'checkin', 'proyek_id' => (string) $p3, 'alokasi' => [(string) $a3], 'saldo' => [$a3 => '1'], 'kondisi' => [],
    'jumlah' => [$a3 => ['Baik' => '1']], 'penyerah_id' => (string) NINA, 'waktu' => waktu_kini(), 'catatan' => '', 'konfirmasi' => '1']);
cek('P20b pengembalian setelah barang keluar tetap berjalan', (int) satu('SELECT sisa FROM v_saldo_alokasi WHERE alokasi_id = :a', ['a' => $a3]) === 0, $h['pesan'] . $h['galat']);
$h = minta('petugasLain', PI, $D, ['id' => (string) $p3, 'tab' => 'persiapan']);
cek('P20c halaman Persiapan tetap konsisten setelah keluar-kembali (masih dikonfirmasi)', bersih($h) && str_contains($h['html'], 'Persiapan Dikonfirmasi Selesai'));

echo "\n== P13, P14 Deadline\n";
$h = minta('admin', SA, $L, [], ['aksi' => 'simpan_proyek', 'id' => (string) $p2, 'nama' => 'UJI4B Dua', 'klien' => 'Klien Uji', 'lokasi' => 'Malang',
    'mulai' => '2027-05-20T08:00', 'selesai' => '2027-05-20T17:00', 'deadline_persiapan' => '2026-11-01T20:00', 'pic_id' => (string) RIZKY, 'status' => 'Dijadwalkan', 'catatan' => '']);
$h = minta('riwayatB', PI, 'pages/riwayat.php');
$pos = strpos($h['html'], 'Persiapan Dikonfirmasi Selesai · ' . $kode2);
$baris = $pos === false ? '' : substr($h['html'], $pos, (strpos($h['html'], '</li>', $pos) ?: $pos + 1500) - $pos);
cek('P13a deadline dimajukan setelah konfirmasi: penilaian historis tetap Tepat Waktu (deadline saat konfirmasi)', bersih($h)
    && str_contains($baris, 'Tepat Waktu') && !str_contains($baris, 'Terlambat') && str_contains($baris, 'Deadline diubah setelah konfirmasi'), strip_tags(substr($baris, 0, 300)));
cek('P13b konfirmasi tidak dicabut karena perubahan deadline', dikonfirmasi($p2));
$p4 = buat_proyek_uji('UJI4B Lewat', '2027-06-20', '2026-11-05T20:00');
$a4 = kru_uji($p4, 'UJI4B Lewat');
$kode4 = (string) satu('SELECT kode FROM proyek WHERE id = :p', ['p' => $p4]);
$h = minta('petugasB', PI, $D, ['id' => (string) $p4, 'tab' => 'persiapan']);
cek('P14a batas persiapan sudah lewat (menurut tanggal aplikasi HARI_INI) dan belum siap -> "Lewat deadline"', bersih($h) && str_contains($h['html'], 'Lewat deadline'));
$h = minta('petugasB', PI, $L);
$pos = strpos($h['html'], $kode4);
cek('P14b daftar proyek (browser lain) menandai proyek itu Lewat deadline', $pos !== false && str_contains(substr($h['html'], $pos, 2500), 'Lewat deadline'));
simpan_centang('admin', SA, $p4, [$a4]);
konfirmasi('admin', SA, $p4);
$h = minta('riwayatB', PI, 'pages/riwayat.php');
$pos = strpos($h['html'], 'Persiapan Dikonfirmasi Selesai · ' . $kode4);
$baris = $pos === false ? '' : substr($h['html'], $pos, (strpos($h['html'], '</li>', $pos) ?: $pos + 1500) - $pos);
cek('P14c konfirmasi setelah batas persiapan dinilai Terlambat', str_contains($baris, 'Terlambat'), strip_tags(substr($baris, 0, 300)));
$h = minta('petugasB', PI, $D, ['id' => (string) $p4, 'tab' => 'persiapan']);
cek('P14d setelah dikonfirmasi, status lewat deadline tidak lagi ditampilkan', !str_contains($h['html'], 'Lewat deadline'));

echo "\n== P16 Hak akses dan CSRF\n";
$p5 = buat_proyek_uji('UJI4B Lima', '2027-06-25', '2027-06-24T20:00');
$a5 = kru_uji($p5, 'UJI4B Lima');
$h = simpan_centang('rizky', RIZKY, $p5, [$a5]);
cek('P16a User/Kru menyimpan checklist lewat POST langsung ditolak', centang($p5) === '' && str_contains($h['pesan'], 'tidak memiliki izin'), $h['pesan']);
simpan_centang('admin', SA, $p5, [$a5]);
$h = konfirmasi('rizky', RIZKY, $p5);
cek('P16b User/Kru mengonfirmasi persiapan lewat POST langsung ditolak', !dikonfirmasi($p5) && str_contains($h['pesan'], 'tidak memiliki izin'), $h['pesan']);
$h = konfirmasi('admin', SA, $p5, false);
cek('P16c konfirmasi tanpa token CSRF ditolak', !dikonfirmasi($p5) && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
$h = simpan_centang('admin', SA, $p5, [], 'token-salah');
cek('P16d simpan checklist dengan token CSRF salah ditolak', centang($p5) === (string) $a5 && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
minta('tamu', null, 'pages/proyek-detail.php', ['id' => (string) $p5], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => (string) $p5, 'konfirmasi' => '1']);
cek('P16e tanpa login: konfirmasi ditolak', !dikonfirmasi($p5));

echo "\n== P17 Rollback\n";
$kode5 = (string) satu('SELECT kode FROM proyek WHERE id = :p', ['p' => $p5]);
$r = fungsi('rb_konfirmasi', $p5);
cek('P17a konfirmasi: riwayat gagal -> konfirmasi dibatalkan', str_starts_with($r['status'] ?? '', 'GAGAL_PDO:23503') && !dikonfirmasi($p5), $r['status'] ?? '');
$r = fungsi('rb_checklist', $p5);
cek('P17b simpan checklist: riwayat gagal -> centang tetap seperti sebelum', str_starts_with($r['status'] ?? '', 'GAGAL_PDO:23503') && centang($p5) === (string) $a5, $r['status'] ?? '');
konfirmasi('admin', SA, $p5);
$r = fungsi('rb_buka', $p5);
cek('P17c buka ulang: riwayat gagal -> konfirmasi tetap berlaku', str_starts_with($r['status'] ?? '', 'GAGAL_PDO:23503') && dikonfirmasi($p5)
    && satu('SELECT batal_pada FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $p5]) === null, $r['status'] ?? '');

echo "\n== P19 Dashboard dan halaman\n";
$masalah = [];
foreach ([[SA, 'pages/dashboard-admin.php', []], [PI, 'pages/dashboard-petugas.php', []], [RIZKY, 'pages/dashboard-user.php', []], [SA, $L, []],
    [SA, $D, ['id' => (string) $p1, 'tab' => 'ringkasan']], [SA, $D, ['id' => (string) $p1, 'tab' => 'persiapan']], [SA, $D, ['id' => (string) $p1, 'tab' => 'kebutuhan']],
    [PI, $S, []], [SA, 'pages/riwayat.php', []], [SA, 'pages/perlengkapan.php', ['tab' => 'eksternal']], [PI, $D, ['id' => '7', 'tab' => 'persiapan']]] as [$uid, $hal, $get]) {
    $h = minta('dasbor' . $uid, $uid, $hal, $get);
    if (!bersih($h) || $h['html'] === '' || str_contains($h['pesan'], 'tidak termasuk hak akses') || str_contains($h['pesan'], 'tidak ditugaskan')) {
        $masalah[] = $hal . json_encode($get) . ' ' . $h['pesan'] . ' ' . substr(strip_tags($h['mentah']), 0, 200);
    }
}
cek('P19a dashboard tiga peran, daftar proyek, tab proyek, Serah Terima, Riwayat tampil tanpa galat', !$masalah, implode(' || ', $masalah));
$h1 = minta('dasbor1', SA, $L);
$h2 = minta('petugasB', PI, $L);
$potong = fn (string $html) => ($pos = strpos($html, $kode1)) === false ? '' : substr($html, $pos, 1200);
cek('P19b daftar proyek di dua browser (dua akun) menampilkan progres persiapan yang sama', $potong($h1['html']) !== '' && $potong($h1['html']) === $potong($h2['html']));
$h = minta('dasbor2', PI, 'pages/proyek-detail.php', ['id' => '7', 'tab' => 'persiapan']);
cek('P19c data contoh: PRJ-025 tampil dikonfirmasi (persiapan_proyek PostgreSQL)', str_contains($h['html'], 'Persiapan Dikonfirmasi Selesai'));

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
