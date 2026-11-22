<?php
// Uji Sprint Backend 3C: Kebutuhan Proyek dan Alokasi Barang di PostgreSQL + kompatibilitas Persiapan/Serah Terima (PHP Session).
// HANYA untuk database uji (nama berakhiran _test), karena uji ini menambah dan mengubah data:
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_kebutuhan_alokasi.php
//
// Uji ini tidak mensyaratkan database uji yang baru dibuat ulang:
//   - proyek uji dibuat sendiri (nama berawalan "UJI3C", jadwal Januari 2027 agar tidak bentrok dengan data contoh) dan dihapus
//     lagi di awal/akhir uji; kebutuhan dan alokasinya ikut terhapus (ON DELETE CASCADE, proyek uji tidak punya transaksi di PostgreSQL);
//   - kebutuhan 1–29 dan alokasi 1–41 contoh hanya dibaca (dicek dulu masih sesuai seed).
// Setiap request = proses PHP terpisah dengan session tertentu (= browser).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_uji3c_sesi');

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
    // Pesan flash dari request sebelumnya dibuang agar setiap pemeriksaan hanya melihat pesan request ini.
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

// Mode anak: session "lama" (VERSI_DATA 4, sebelum 3C) yang masih menyimpan kebutuhan/alokasi buatan session.
if (($argv[1] ?? '') === '--sesi-lama') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $_SESSION = ['db_versi' => 4, 'db' => [
        'kebutuhan' => [30 => ['id' => 30, 'proyek_id' => 3, 'nama' => 'Kebutuhan Buatan Session', 'acuan' => '', 'kategori_id' => 1, 'jumlah' => 1]],
        'alokasi' => [42 => ['id' => 42, 'proyek_id' => 3, 'kebutuhan_id' => 30, 'sumber' => 'Rental', 'perlengkapan_id' => null, 'nama_barang' => 'Barang Session',
            'vendor' => 'Vendor Session', 'pemilik_id' => null, 'tgl_mulai' => '2026-11-06', 'tgl_selesai' => '2026-11-08', 'status' => 'Dikonfirmasi', 'catatan' => '']],
        'persiapan' => [3 => ['id' => 3, 'proyek_id' => 3, 'diperiksa' => [13, 42], 'diperbarui_pada' => null, 'diperbarui_oleh' => null]],
    ]];
    session_write_close();
    exit;
}

// Mode anak: membaca isi session apa adanya (tanpa bootstrap).
if (($argv[1] ?? '') === '--periksa-sesi') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $db = $_SESSION['db'] ?? [];
    $persiapan = [];
    foreach ($db['persiapan'] ?? [] as $p) {
        $persiapan[(int) $p['proyek_id']] = array_map('intval', $p['diperiksa'] ?? []);
    }
    echo json_encode([
        'versi' => $_SESSION['db_versi'] ?? null,
        'ada_kebutuhan' => isset($db['kebutuhan']),
        'ada_alokasi' => isset($db['alokasi']),
        'persiapan' => $persiapan,
        'transaksi' => array_values(array_column($db['transaksi'] ?? [], 'kode')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mode anak: memanggil fungsi PHP langsung (uji bersamaan dan rollback).
if (($argv[1] ?? '') === '--fungsi') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id('fungsi' . getmypid());
    session_start();
    require AKAR_UJI . '/includes/bootstrap.php';
    restore_exception_handler();
    $jenis = $argv[2];
    $a = array_map('intval', array_slice($argv, 3));
    $_SESSION['user_id'] = 1;
    $studio = fn (int $unit) => ['sumber' => 'Studio', 'perlengkapan_id' => $unit, 'nama_barang' => '', 'vendor' => '', 'pemilik_id' => null,
        'jumlah' => 1, 'status' => 'Dikonfirmasi', 'catatan' => ''];
    $rental = fn (int $jumlah) => ['sumber' => 'Rental', 'perlengkapan_id' => null, 'nama_barang' => 'UJI3C Sewa Paralel', 'vendor' => 'Vendor Uji',
        'pemilik_id' => null, 'jumlah' => $jumlah, 'status' => 'Dikonfirmasi', 'catatan' => ''];
    if ($jenis === 'alokasi_studio') {
        echo json_encode(tambah_alokasi($a[0], $a[1], $studio($a[2]))['status']);
    } elseif ($jenis === 'alokasi_rental') {
        echo json_encode(tambah_alokasi($a[0], $a[1], $rental($a[2]))['status']);
    } elseif ($jenis === 'status_alokasi') {
        echo json_encode(ubah_status_alokasi($a[0], $a[1], $argv[5])['status']);
    } elseif ($jenis === 'lepas_kru') {
        echo json_encode(lepas_kru($a[0], $a[1]));
    } elseif (str_starts_with($jenis, 'rb_')) {
        // pengguna_id 99999 tidak ada: INSERT riwayat_aktivitas melanggar FOREIGN KEY -> seluruh transaksi harus ROLLBACK.
        $_SESSION['user_id'] = 99999;
        try {
            if ($jenis === 'rb_kebutuhan_tambah') {
                simpan_kebutuhan($a[0], 0, ['nama' => 'UJI3C Rollback', 'acuan' => '', 'kategori_id' => 1, 'jumlah' => 1]);
            } elseif ($jenis === 'rb_kebutuhan_ubah') {
                simpan_kebutuhan($a[0], $a[1], ['nama' => 'UJI3C Rollback Ubah', 'acuan' => '', 'kategori_id' => 5, 'jumlah' => 9]);
            } elseif ($jenis === 'rb_kebutuhan_hapus') {
                hapus_kebutuhan($a[0], $a[1]);
            } elseif ($jenis === 'rb_isi_preset') {
                isi_kebutuhan_dari_preset($a[0], 2);
            } elseif ($jenis === 'rb_alokasi') {
                tambah_alokasi($a[0], $a[1], $studio($a[2]));
            } elseif ($jenis === 'rb_status') {
                ubah_status_alokasi($a[0], $a[1], 'batalkan');
            } else {
                simpan_catatan_rental($a[0], ['diterima_pada' => '2026-11-07 08:00', 'diterima_oleh' => 1, 'kondisi_terima' => 'Baik', 'catatan_terima' => ''],
                    "status = 'Dikonfirmasi' AND diterima_pada IS NULL", [], ['UJI3C', 'Rollback rental']);
            }
            echo 'TANPA_GALAT';
        } catch (PDOException $e) {
            echo 'GAGAL_PDO:' . $e->getCode();
        }
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

// Kebutuhan dan alokasi contoh harus masih sesuai seed (uji membaca data ini).
$sig_kebutuhan = satu("SELECT COUNT(*) || ':' || SUM(jumlah) FROM kebutuhan_proyek WHERE id <= 29");
$sig_alokasi = satu("SELECT string_agg(id || ':' || kebutuhan_id || ':' || COALESCE(perlengkapan_id, 0) || ':' || status, ',' ORDER BY id) FROM alokasi_barang WHERE id <= 41");
if ($sig_kebutuhan !== '29:42' || md5((string) $sig_alokasi) !== '53eb8da0a12155d805e73f878af3a7bf') {
    echo "[BERHENTI] Kebutuhan/alokasi contoh di database uji tidak lagi sesuai seed. Buat ulang database uji dari 001 + 002 + 003.\n";
    exit(1);
}
define('ALOKASI_SEED', $sig_alokasi);

// Bersihkan sisa uji sebelumnya: proyek UJI3C dihapus (kebutuhan, alokasi, kru, persiapan ikut terhapus lewat CASCADE);
// proyek uji yang sudah punya transaksi serah terima (K17, Sprint 4A) diarsipkan karena transaksi tidak boleh dihapus.
require __DIR__ . '/bantu_bersihkan.php';
function bersihkan(): void
{
    arsipkan_proyek_uji('UJI3C');
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

function paralel(array $arg_a, array $arg_b): array
{
    $pr = [proses_anak($arg_a), proses_anak($arg_b)];
    $hasil = [];
    foreach ($pr as [$proses, $pipa]) {
        fclose($pipa[0]);
        $hasil[] = json_decode(stream_get_contents($pipa[1]) . stream_get_contents($pipa[2]), true);
        proc_close($proses);
    }
    return $hasil;
}

function bersih(array $h): bool
{
    $teks = $h['html'] . $h['mentah'];
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|Parse error)\b|Layanan database PhotoOps sedang tidak tersedia/', $teks);
}

function riwayat_baru(string $aksi, string $keterangan_like): int
{
    return (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = :a AND keterangan LIKE :k AND id > :awal',
        ['a' => $aksi, 'k' => $keterangan_like, 'awal' => RIWAYAT_AWAL]);
}

const SA = 1, PI = 2, RIZKY = 3, SARI = 4, BAGAS = 5, NINA = 7;
$L = 'pages/proyek.php';
$D = 'pages/proyek-detail.php';

// Proyek uji. Jadwal alokasi = tanggal batas persiapan s.d. tanggal selesai acara.
function buat_proyek_uji(string $nama, string $mulai, string $selesai, string $deadline, int $pic, string $status = 'Dijadwalkan'): int
{
    global $L;
    minta('admin', SA, $L, [], ['aksi' => 'simpan_proyek', 'id' => '', 'nama' => $nama, 'klien' => 'Klien Uji', 'lokasi' => 'Malang',
        'mulai' => $mulai, 'selesai' => $selesai, 'deadline_persiapan' => $deadline, 'pic_id' => (string) $pic, 'status' => $status, 'catatan' => '']);
    return (int) satu('SELECT id FROM proyek WHERE nama = :n', ['n' => $nama]);
}

function kebutuhan(int $pid, string $nama): ?array
{
    $b = semua('SELECT id, nama, acuan, kategori_id, jumlah FROM kebutuhan_proyek WHERE proyek_id = :p AND nama = :n ORDER BY id DESC LIMIT 1', ['p' => $pid, 'n' => $nama]);
    return $b[0] ?? null;
}

function tambah_kebutuhan(string $sid, int $pid, string $nama, int $kategori, int $jumlah, int $user = SA): array
{
    global $D;
    $h = minta($sid, $user, $D, ['id' => (string) $pid], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => $nama, 'acuan' => '', 'kategori_id' => (string) $kategori, 'jumlah' => (string) $jumlah]);
    $h['id'] = (int) (kebutuhan($pid, $nama)['id'] ?? 0);
    return $h;
}

function alokasikan(string $sid, int $pid, int $kid, array $isi, int $user = SA): array
{
    global $D;
    return minta($sid, $user, $D, ['id' => (string) $pid], $isi + ['aksi' => 'tambah_alokasi', 'kebutuhan_id' => (string) $kid, 'sumber' => 'Studio',
        'perlengkapan_id' => '', 'nama_barang' => '', 'vendor' => '', 'pemilik_id' => '', 'jumlah' => '1', 'status' => 'Dikonfirmasi', 'catatan' => '']);
}

function alokasi_db(int $kid): array
{
    return semua("SELECT id, sumber, perlengkapan_id, nama_barang, vendor, pemilik_id, jumlah, status, TO_CHAR(tgl_mulai, 'YYYY-MM-DD') AS tgl_mulai,
                         TO_CHAR(tgl_selesai, 'YYYY-MM-DD') AS tgl_selesai, diterima_pada FROM alokasi_barang WHERE kebutuhan_id = :k ORDER BY id", ['k' => $kid]);
}

function aksi_alokasi(string $sid, int $pid, int $aid, string $aksi, int $user = SA): array
{
    global $D;
    return minta($sid, $user, $D, ['id' => (string) $pid], ['aksi' => $aksi, 'id' => (string) $aid]);
}

echo "Database uji: {$k['dbname']}\n\n== Persiapan data uji\n";

// Unit Studio kategori Kamera (1) yang aktif dan Baik, serta satu unit kategori Lensa (2) dan satu unit yang tidak layak.
$unit_kamera = semua("SELECT id, kode_unit FROM perlengkapan WHERE kategori_id = 1 AND status_aktif = 'Aktif' AND kondisi = 'Baik' ORDER BY id LIMIT 3");
$unit_lensa = semua("SELECT id, kode_unit FROM perlengkapan WHERE kategori_id = 2 AND status_aktif = 'Aktif' AND kondisi = 'Baik' ORDER BY id LIMIT 1");
$unit_rusak = semua("SELECT id, kode_unit, kategori_id, kondisi, status_aktif FROM perlengkapan WHERE status_aktif <> 'Aktif' OR kondisi <> 'Baik' ORDER BY id LIMIT 1");
[$U1, $U2, $U3] = array_pad(array_map(fn ($u) => (int) $u['id'], $unit_kamera), 3, 0);
$kode_u1 = $unit_kamera[0]['kode_unit'] ?? '?';
$UL = (int) ($unit_lensa[0]['id'] ?? 0);
if (count($unit_kamera) < 3 || !$UL) {
    echo "[BERHENTI] Database uji tidak punya cukup unit Kamera/Lensa yang aktif dan Baik.\n";
    exit(1);
}

$pA = buat_proyek_uji('UJI3C Alfa', '2027-01-10T08:00', '2027-01-11T17:00', '2027-01-09T20:00', RIZKY);   // jadwal 9–11 Jan
$pB = buat_proyek_uji('UJI3C Beta', '2027-01-11T08:00', '2027-01-12T17:00', '2027-01-10T20:00', SARI);    // 10–12 Jan, bentrok A
$pC = buat_proyek_uji('UJI3C Gamma', '2027-01-20T08:00', '2027-01-21T17:00', '2027-01-19T20:00', RIZKY);  // 19–21 Jan, tidak bentrok
$pD = buat_proyek_uji('UJI3C Delta', '2027-01-11T08:00', '2027-01-11T17:00', '2027-01-10T20:00', BAGAS);  // 10–11 Jan, bentrok A dan B
$pE = buat_proyek_uji('UJI3C Batal', '2027-01-25T08:00', '2027-01-25T17:00', '2027-01-24T20:00', RIZKY, 'Dibatalkan');
$pF = buat_proyek_uji('UJI3C Foxtrot', '2027-02-02T08:00', '2027-02-02T17:00', '2027-02-01T20:00', RIZKY);  // 1–2 Feb
minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) NINA]);
cek('Persiapan: enam proyek uji dibuat, Nina kru di Alfa', $pA && $pB && $pC && $pD && $pE && $pF
    && (int) satu('SELECT COUNT(*) FROM anggota_proyek WHERE proyek_id = :p AND pengguna_id = :u', ['p' => $pA, 'u' => NINA]) === 1);

echo "\n== K01–K04 Kebutuhan\n";

// K01 tambah
$h = tambah_kebutuhan('admin', $pA, 'UJI3C Kamera', 1, 2);
$kA = $h['id'];
cek('K01a tambah kebutuhan tersimpan di kebutuhan_proyek', $kA > 0 && (int) kebutuhan($pA, 'UJI3C Kamera')['jumlah'] === 2 && str_contains($h['pesan'], 'ditambahkan'), $h['pesan']);
cek('K01b riwayat Kebutuhan tercatat di PostgreSQL', riwayat_baru('Kebutuhan', 'UJI3C Kamera × 2 ditambahkan') === 1);
$s = json_decode(anak(['--periksa-sesi', 'admin']), true);
cek('K01c session tidak lagi menyimpan salinan kebutuhan/alokasi', ($s['ada_kebutuhan'] ?? true) === false && ($s['ada_alokasi'] ?? true) === false);

// K02 ubah dan hapus
$h = minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'simpan_kebutuhan', 'id' => (string) $kA, 'nama' => 'UJI3C Kamera', 'acuan' => 'Sony A7 III', 'kategori_id' => '1', 'jumlah' => '3']);
$kb = kebutuhan($pA, 'UJI3C Kamera');
cek('K02a ubah jumlah dan acuan kebutuhan tersimpan', (int) $kb['jumlah'] === 3 && $kb['acuan'] === 'Sony A7 III' && riwayat_baru('Kebutuhan', 'UJI3C Kamera diperbarui') === 1, $h['pesan']);
$h = tambah_kebutuhan('admin', $pA, 'UJI3C Hapus', 4, 1);
$kHapus = $h['id'];
$h = minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'hapus_kebutuhan', 'id' => (string) $kHapus]);
cek('K02b hapus kebutuhan tanpa alokasi berhasil + riwayat', kebutuhan($pA, 'UJI3C Hapus') === null && riwayat_baru('Kebutuhan', 'UJI3C Hapus dihapus') === 1, $h['pesan']);

// K03 isi dari preset
$jumlah_item = (int) satu('SELECT COUNT(*) FROM detail_preset WHERE preset_id = 1');
$h = minta('admin', SA, $D, ['id' => (string) $pB], ['aksi' => 'isi_preset', 'preset_id' => '1']);
$salin = (int) satu('SELECT COUNT(*) FROM kebutuhan_proyek k JOIN detail_preset d ON d.preset_id = 1 AND d.nama_perlengkapan = k.nama
                     AND k.acuan = d.nama_perlengkapan AND k.kategori_id = d.kategori_id AND k.jumlah = d.jumlah WHERE k.proyek_id = :p', ['p' => $pB]);
cek('K03a isi dari preset menyalin semua item (nama, acuan, kategori, jumlah) ke kebutuhan PostgreSQL', $salin === $jumlah_item && str_contains($h['pesan'], $jumlah_item . ' kebutuhan dari preset'), $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pB], ['aksi' => 'isi_preset', 'preset_id' => '1']);
cek('K03b isi ulang melewati model yang sudah ada', str_contains($h['pesan'], '0 kebutuhan') && str_contains($h['pesan'], $jumlah_item . ' dilewati')
    && (int) satu('SELECT COUNT(*) FROM kebutuhan_proyek WHERE proyek_id = :p', ['p' => $pB]) === $jumlah_item, $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pB], ['aksi' => 'isi_preset', 'preset_id' => '999999']);
cek('K03c preset yang tidak ada ditolak', str_contains($h['galat'], 'Pilih preset'));

// K04 validasi
$awal = (int) satu('SELECT COUNT(*) FROM kebutuhan_proyek');
foreach (['jumlah 0' => ['jumlah' => '0'], 'jumlah 51' => ['jumlah' => '51'], 'jumlah teks' => ['jumlah' => '2x'], 'kategori tidak ada' => ['kategori_id' => '999'],
    'kategori bukan angka' => ['kategori_id' => '1 OR 1=1'], 'nama 1 karakter' => ['nama' => 'U'], 'nama 61 karakter' => ['nama' => str_repeat('n', 61)],
    'acuan 61 karakter' => ['acuan' => str_repeat('a', 61)]] as $ket => $ubah) {
    $h = minta('admin', SA, $D, ['id' => (string) $pA], $ubah + ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'UJI3C Valid', 'acuan' => '', 'kategori_id' => '1', 'jumlah' => '1']);
    cek('K04 kebutuhan ditolak: ' . $ket, (int) satu('SELECT COUNT(*) FROM kebutuhan_proyek') === $awal && $h['galat'] !== '', $h['galat']);
}
$h = tambah_kebutuhan('admin', $pE, 'UJI3C Di Proyek Batal', 1, 1);
cek('K04 kebutuhan di proyek Dibatalkan ditolak', $h['id'] === 0 && str_contains($h['pesan'], 'Dibatalkan'), $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pB], ['aksi' => 'simpan_kebutuhan', 'id' => (string) $kA, 'nama' => 'UJI3C Bajak', 'acuan' => '', 'kategori_id' => '1', 'jumlah' => '1']);
cek('K04 mengubah kebutuhan proyek lain lewat proyek B ditolak', kebutuhan($pA, 'UJI3C Kamera') !== null && str_contains($h['pesan'], 'tidak ditemukan'), $h['pesan']);
$h = minta('admin', SA, $D, ['id' => '999999']);
cek('K04 proyek yang tidak ada ditolak', str_contains($h['pesan'], 'Proyek tidak ditemukan'));

echo "\n== K05–K09 Alokasi\n";

// K05 Studio
$h = alokasikan('admin', $pA, $kA, ['perlengkapan_id' => (string) $U1]);
$al = alokasi_db($kA);
$aStudio = (int) ($al[0]['id'] ?? 0);
cek('K05a alokasi Studio tersimpan (unit, jumlah 1, jadwal = batas persiapan s.d. selesai acara)',
    count($al) === 1 && (int) $al[0]['perlengkapan_id'] === $U1 && (int) $al[0]['jumlah'] === 1 && $al[0]['tgl_mulai'] === '2027-01-09' && $al[0]['tgl_selesai'] === '2027-01-11', $h['pesan']);
cek('K05b riwayat Alokasi tercatat di PostgreSQL', riwayat_baru('Alokasi', 'Studio: % untuk UJI3C Kamera') === 1);
$h = alokasikan('admin', $pA, $kA, ['perlengkapan_id' => (string) $UL]);
cek('K05c unit berkategori lain ditolak', count(alokasi_db($kA)) === 1 && str_contains($h['galat'], 'kategori'), $h['galat']);
if ($unit_rusak) {
    $ur = $unit_rusak[0];
    $kRusak = tambah_kebutuhan('admin', $pA, 'UJI3C Unit Rusak', (int) $ur['kategori_id'], 1)['id'];
    $h = alokasikan('admin', $pA, $kRusak, ['perlengkapan_id' => (string) $ur['id']]);
    cek('K05d unit nonaktif / tidak Baik ditolak', alokasi_db($kRusak) === [] && $h['galat'] !== '', $h['galat']);
}
$h = alokasikan('admin', $pA, $kA, ['perlengkapan_id' => '999999']);
cek('K05e unit yang tidak ada ditolak', count(alokasi_db($kA)) === 1 && str_contains($h['galat'], 'Pilih unit'));

// K06 Rental
$kR = tambah_kebutuhan('admin', $pA, 'UJI3C Lighting', 3, 2)['id'];
$h = alokasikan('admin', $pA, $kR, ['sumber' => 'Rental', 'nama_barang' => 'Aputure 300d', 'vendor' => 'Vendor Uji', 'jumlah' => '2', 'status' => 'Direncanakan']);
$al = alokasi_db($kR);
$aRental = (int) ($al[0]['id'] ?? 0);
cek('K06a alokasi Rental (jumlah 2, Direncanakan) tersimpan tanpa unit Studio', count($al) === 1 && $al[0]['perlengkapan_id'] === null
    && $al[0]['vendor'] === 'Vendor Uji' && (int) $al[0]['jumlah'] === 2 && $al[0]['status'] === 'Direncanakan', $h['pesan']);
$h = alokasikan('admin', $pA, $kA, ['sumber' => 'Rental', 'nama_barang' => 'Kamera Sewa', 'vendor' => '', 'jumlah' => '1']);
cek('K06b Rental tanpa vendor ditolak', count(alokasi_db($kA)) === 1 && str_contains($h['galat'], 'vendor'), $h['galat'] . $h['pesan']);
$h = alokasikan('admin', $pA, $kA, ['sumber' => 'Rental', 'nama_barang' => 'Kamera Sewa', 'vendor' => str_repeat('v', 61), 'jumlah' => '1']);
cek('K06c nama vendor lebih dari 60 karakter ditolak di form (bukan galat database)', count(alokasi_db($kA)) === 1 && str_contains($h['galat'], 'maksimal 60') && bersih($h), $h['galat']);
$stok_sebelum = satu("SELECT string_agg(id || kondisi || status_aktif, ',' ORDER BY id) FROM perlengkapan");

// K07 Milik Kru
$kK = tambah_kebutuhan('admin', $pA, 'UJI3C SD Card', 5, 3)['id'];
$h = alokasikan('admin', $pA, $kK, ['sumber' => 'Milik Kru', 'nama_barang' => 'SD Card Nina', 'pemilik_id' => (string) NINA, 'jumlah' => '2']);
$al = alokasi_db($kK);
$aKru = (int) ($al[0]['id'] ?? 0);
cek('K07a alokasi Milik Kru (pemilik kru proyek) tersimpan', count($al) === 1 && (int) $al[0]['pemilik_id'] === NINA && (int) $al[0]['jumlah'] === 2, $h['pesan']);
$h = alokasikan('admin', $pA, $kK, ['sumber' => 'Milik Kru', 'nama_barang' => 'SD Card Bagas', 'pemilik_id' => (string) BAGAS, 'jumlah' => '1']);
cek('K07b pemilik yang bukan anggota tim ditolak', count(alokasi_db($kK)) === 1 && str_contains($h['galat'], 'anggota tim'));
$h = alokasikan('admin', $pA, $kK, ['sumber' => 'Milik Kru', 'nama_barang' => 'SD Card PIC', 'pemilik_id' => (string) RIZKY, 'jumlah' => '1']);
cek('K07c PIC juga boleh menjadi pemilik barang', count(alokasi_db($kK)) === 2);
cek('K07d alokasi Rental/Milik Kru tidak mengubah data unit Studio', satu("SELECT string_agg(id || kondisi || status_aktif, ',' ORDER BY id) FROM perlengkapan") === $stok_sebelum);

// K08 double booking (dari browser lain)
$kB = tambah_kebutuhan('petugasB', $pB, 'UJI3C Kamera B', 1, 2, PI)['id'];
$h = alokasikan('petugasB', $pB, $kB, ['perlengkapan_id' => (string) $U1], PI);
cek('K08a unit yang sama di proyek lain dengan jadwal bertabrakan ditolak (browser lain)', alokasi_db($kB) === [] && str_contains($h['galat'], 'Bentrok dengan'), $h['galat']);
$kA2 = tambah_kebutuhan('admin', $pA, 'UJI3C Kamera Cadangan', 1, 1)['id'];
$h = alokasikan('admin', $pA, $kA2, ['perlengkapan_id' => (string) $U1]);
cek('K08b unit yang sama dua kali di proyek yang sama ditolak', alokasi_db($kA2) === [] && str_contains($h['galat'], 'Sudah dipakai di proyek ini'), $h['galat']);
$h = minta('petugasB', PI, $D, ['id' => (string) $pB, 'tab' => 'kebutuhan']);
cek('K08c daftar unit di dialog alokasi proyek B menandai unit itu bentrok', bersih($h) && str_contains($h['html'], 'Bentrok dengan ' . satu('SELECT kode FROM proyek WHERE id = :p', ['p' => $pA])));

// K09 jadwal tidak bertabrakan
$kC = tambah_kebutuhan('admin', $pC, 'UJI3C Kamera C', 1, 1)['id'];
$h = alokasikan('admin', $pC, $kC, ['perlengkapan_id' => (string) $U1]);
$aC = (int) (alokasi_db($kC)[0]['id'] ?? 0);
cek('K09 unit yang sama di proyek lain dengan jadwal tidak bertabrakan diterima', $aC > 0, $h['pesan'] . $h['galat']);

echo "\n== K10 Bersamaan\n";
$kD = tambah_kebutuhan('admin', $pD, 'UJI3C Kamera D', 1, 1)['id'];
$hasil_paralel = [];
foreach ([[$U2, 'pertama'], [$U3, 'kedua']] as [$unit, $ket]) {
    [$x, $y] = paralel(['--fungsi', 'alokasi_studio', (string) $pB, (string) $kB, (string) $unit], ['--fungsi', 'alokasi_studio', (string) $pD, (string) $kD, (string) $unit]);
    $status = [$x, $y];
    sort($status);
    $aktif = (int) satu("SELECT COUNT(*) FROM alokasi_barang WHERE perlengkapan_id = :u AND status <> 'Dibatalkan' AND kebutuhan_id IN (:b, :d)", ['u' => $unit, 'b' => $kB, 'd' => $kD]);
    cek('K10a (' . $ket . ') dua proyek bentrok memesan unit yang sama bersamaan -> satu berhasil, satu ditolak', $status === ['bentrok', 'ok'] && $aktif === 1, json_encode([$x, $y]));
    if ($ket === 'pertama') {
        // Batalkan pemenang agar putaran kedua dimulai dari kondisi yang sama.
        $menang = (int) satu("SELECT id FROM alokasi_barang WHERE perlengkapan_id = :u AND status <> 'Dibatalkan' AND kebutuhan_id IN (:b, :d)", ['u' => $unit, 'b' => $kB, 'd' => $kD]);
        $pid_menang = (int) satu('SELECT k.proyek_id FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id WHERE a.id = :a', ['a' => $menang]);
        anak(['--fungsi', 'status_alokasi', (string) $pid_menang, (string) $menang, 'hapus']);
    }
}
$kSisa = tambah_kebutuhan('admin', $pB, 'UJI3C Sewa Bersamaan', 3, 3)['id'];
[$x, $y] = paralel(['--fungsi', 'alokasi_rental', (string) $pB, (string) $kSisa, '2'], ['--fungsi', 'alokasi_rental', (string) $pB, (string) $kSisa, '2']);
$status = [$x, $y];
sort($status);
cek('K10b dua alokasi bersamaan ke kebutuhan yang sama tidak melebihi jumlah kebutuhan', $status === ['melebihi', 'ok']
    && (int) satu("SELECT SUM(jumlah) FROM alokasi_barang WHERE kebutuhan_id = :k AND status <> 'Dibatalkan'", ['k' => $kSisa]) === 2, json_encode([$x, $y]));

echo "\n== K11, K13 Perubahan, pembatalan, batas kebutuhan\n";

// K13 batas kebutuhan
$h = alokasikan('admin', $pA, $kR, ['sumber' => 'Rental', 'nama_barang' => 'Lampu Tambahan', 'vendor' => 'Vendor Uji', 'jumlah' => '1']);
cek('K13a kebutuhan yang sudah penuh menolak alokasi baru', count(alokasi_db($kR)) === 1 && str_contains($h['pesan'], 'teralokasi penuh'), $h['pesan']);
$h = alokasikan('admin', $pA, $kK, ['sumber' => 'Milik Kru', 'nama_barang' => 'SD Card Lagi', 'pemilik_id' => (string) NINA, 'jumlah' => '1']);
cek('K13b kebutuhan Milik Kru 3 unit (2 + 1 terisi) menolak tambahan', count(alokasi_db($kK)) === 2 && str_contains($h['pesan'], 'teralokasi penuh'), $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'simpan_kebutuhan', 'id' => (string) $kR, 'nama' => 'UJI3C Lighting', 'acuan' => '', 'kategori_id' => '3', 'jumlah' => '1']);
cek('K13c jumlah kebutuhan tidak bisa diturunkan di bawah unit yang sudah dialokasikan', (int) kebutuhan($pA, 'UJI3C Lighting')['jumlah'] === 2 && str_contains($h['galat'], 'Tidak bisa kurang dari 2'), $h['galat']);
$h = minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'simpan_kebutuhan', 'id' => (string) $kR, 'nama' => 'UJI3C Lighting', 'acuan' => '', 'kategori_id' => '4', 'jumlah' => '2']);
cek('K13d kategori kebutuhan yang punya alokasi aktif tidak bisa diubah', (int) kebutuhan($pA, 'UJI3C Lighting')['kategori_id'] === 3 && str_contains($h['galat'], 'Kategori tidak bisa diubah'), $h['galat']);
$h = minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'hapus_kebutuhan', 'id' => (string) $kR]);
cek('K13e kebutuhan yang masih punya alokasi aktif tidak bisa dihapus', kebutuhan($pA, 'UJI3C Lighting') !== null && str_contains($h['pesan'], 'masih punya alokasi aktif'), $h['pesan']);

// K11 konfirmasi, batal, hapus
$h = aksi_alokasi('admin', $pA, $aRental, 'konfirmasi_alokasi');
cek('K11a konfirmasi alokasi Direncanakan -> Dikonfirmasi + riwayat', satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aRental]) === 'Dikonfirmasi'
    && riwayat_baru('Alokasi', 'Alokasi dikonfirmasi') >= 1, $h['pesan']);
$h = aksi_alokasi('admin', $pA, $aRental, 'konfirmasi_alokasi');
cek('K11b konfirmasi alokasi yang sudah Dikonfirmasi ditolak', str_contains($h['pesan'], 'Hanya alokasi berstatus Direncanakan'), $h['pesan']);
$h = minta('admin', SA, 'pages/perlengkapan.php', ['tab' => 'eksternal'], ['aksi' => 'terima_rental', 'id' => (string) $aRental, 'proyek_id' => (string) $pA,
    'jumlah' => '2', 'waktu' => '2026-11-07T00:05', 'kondisi' => 'Baik', 'catatan' => '', 'konfirmasi' => '1']);
cek('K11c penerimaan Rental dari vendor tersimpan di baris alokasi PostgreSQL + riwayat Rental',
    satu("SELECT TO_CHAR(diterima_pada, 'YYYY-MM-DD HH24:MI') FROM alokasi_barang WHERE id = :a", ['a' => $aRental]) === '2026-11-07 00:05'
    && riwayat_baru('Rental', '2 unit diterima dari Vendor Uji%') === 1, $h['pesan'] . $h['galat']);
$h = minta('adminLain', SA, 'pages/perlengkapan.php', ['tab' => 'eksternal'], ['aksi' => 'terima_rental', 'id' => (string) $aRental, 'proyek_id' => (string) $pA,
    'jumlah' => '2', 'waktu' => '2026-11-07T00:06', 'kondisi' => 'Baik', 'catatan' => '', 'konfirmasi' => '1']);
cek('K11d penerimaan yang sama dari browser lain ditolak (tidak tercatat dua kali)', riwayat_baru('Rental', '2 unit diterima dari Vendor Uji%') === 1
    && str_contains($h['pesan'] . $h['galat'], 'sudah tercatat diterima'), $h['pesan']);
$h = aksi_alokasi('adminLain', $pA, $aRental, 'batalkan_alokasi');
cek('K11e Rental yang sudah diterima dari vendor tidak bisa dibatalkan', satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aRental]) === 'Dikonfirmasi'
    && str_contains($h['pesan'], 'diterima dari vendor'), $h['pesan']);
$aKru2 = (int) alokasi_db($kK)[1]['id'];
$h = aksi_alokasi('admin', $pA, $aKru2, 'batalkan_alokasi');
cek('K11f batalkan alokasi -> Dibatalkan, sisa kebutuhan bertambah lagi', satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aKru2]) === 'Dibatalkan', $h['pesan']);
$h = aksi_alokasi('admin', $pA, $aKru2, 'batalkan_alokasi');
cek('K11g membatalkan dua kali ditolak', str_contains($h['pesan'], 'sudah dibatalkan'), $h['pesan']);
$h = alokasikan('admin', $pA, $kK, ['sumber' => 'Milik Kru', 'nama_barang' => 'SD Card Pengganti', 'pemilik_id' => (string) NINA, 'jumlah' => '1']);
cek('K11h setelah pembatalan, sisa kebutuhan bisa dialokasikan lagi', count(alokasi_db($kK)) === 3, $h['pesan'] . $h['galat']);
$aGanti = (int) alokasi_db($kK)[2]['id'];
$h = aksi_alokasi('admin', $pA, $aGanti, 'hapus_alokasi');
cek('K11i hapus alokasi menghapus barisnya + riwayat', count(alokasi_db($kK)) === 2 && riwayat_baru('Alokasi', 'Alokasi dihapus') >= 1, $h['pesan']);
$h = aksi_alokasi('admin', $pB, $aStudio, 'hapus_alokasi');
cek('K11j alokasi proyek lain tidak bisa diubah lewat proyek B', count(alokasi_db($kA)) === 1 && str_contains($h['pesan'], 'tidak ditemukan'), $h['pesan']);
// Alokasi contoh yang punya serah terima di PostgreSQL (detail_transaksi) dikunci di dalam transaksi.
$seed_serah = semua("SELECT a.id, k.proyek_id FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id JOIN proyek p ON p.id = k.proyek_id
                     WHERE p.status NOT IN ('Selesai', 'Dibatalkan') AND EXISTS (SELECT 1 FROM detail_transaksi d WHERE d.alokasi_id = a.id) ORDER BY a.id LIMIT 1");
if ($seed_serah) {
    $r = json_decode(anak(['--fungsi', 'status_alokasi', (string) $seed_serah[0]['proyek_id'], (string) $seed_serah[0]['id'], 'hapus']), true);
    cek('K11k alokasi yang punya serah terima di PostgreSQL tidak bisa dihapus (dicek di transaksi)', $r === 'punya_serah'
        && (int) satu('SELECT COUNT(*) FROM alokasi_barang WHERE id = :a', ['a' => $seed_serah[0]['id']]) === 1, (string) $r);
}

echo "\n== K12 Rollback\n";
$foto = fn () => md5(json_encode([semua("SELECT * FROM kebutuhan_proyek WHERE proyek_id IN (SELECT id FROM proyek WHERE nama LIKE 'UJI3C%') ORDER BY id"),
    semua("SELECT a.* FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id JOIN proyek p ON p.id = k.proyek_id WHERE p.nama LIKE 'UJI3C%' ORDER BY a.id")]));
$kKosong = tambah_kebutuhan('admin', $pC, 'UJI3C Kosong', 1, 1)['id'];
$kRentalC = tambah_kebutuhan('admin', $pC, 'UJI3C Sewa C', 3, 1)['id'];
alokasikan('admin', $pC, $kRentalC, ['sumber' => 'Rental', 'nama_barang' => 'Lampu Sewa', 'vendor' => 'Vendor Uji', 'jumlah' => '1']);
$aRentalC = (int) (alokasi_db($kRentalC)[0]['id'] ?? 0);
$sebelum = $foto();
$hasil_rb = [
    'tambah kebutuhan' => anak(['--fungsi', 'rb_kebutuhan_tambah', (string) $pC]),
    'ubah kebutuhan' => anak(['--fungsi', 'rb_kebutuhan_ubah', (string) $pC, (string) $kKosong]),
    'hapus kebutuhan' => anak(['--fungsi', 'rb_kebutuhan_hapus', (string) $pC, (string) $kKosong]),
    'isi dari preset' => anak(['--fungsi', 'rb_isi_preset', (string) $pC]),
    'alokasi Studio' => anak(['--fungsi', 'rb_alokasi', (string) $pC, (string) $kKosong, (string) $U2]),
    'batalkan alokasi' => anak(['--fungsi', 'rb_status', (string) $pC, (string) $aC]),
    'terima rental' => anak(['--fungsi', 'rb_rental', (string) $aRentalC]),
];
foreach ($hasil_rb as $ket => $r) {
    cek('K12 ' . $ket . ': riwayat gagal (FK) -> galat PDO', str_starts_with($r, 'GAGAL_PDO:23503'), $r);
}
cek('K12 tidak ada perubahan sebagian: kebutuhan dan alokasi proyek uji sama persis seperti sebelum', $foto() === $sebelum);

echo "\n== K14 Hak akses dan CSRF\n";
$sebelum = $foto();
$h = tambah_kebutuhan('rizky', $pA, 'UJI3C Oleh Kru', 1, 1, RIZKY);
cek('K14a User/Kru (PIC proyek) menambah kebutuhan lewat POST langsung ditolak', $h['id'] === 0 && str_contains($h['pesan'], 'tidak memiliki izin'), $h['pesan']);
$h = alokasikan('rizky', $pA, $kA2, ['perlengkapan_id' => (string) $U2], RIZKY);
cek('K14b User/Kru menambah alokasi lewat POST langsung ditolak', alokasi_db($kA2) === [] && str_contains($h['pesan'], 'tidak memiliki izin'), $h['pesan']);
$h = aksi_alokasi('rizky', $pA, $aStudio, 'hapus_alokasi', RIZKY);
cek('K14c User/Kru menghapus alokasi lewat POST langsung ditolak', count(alokasi_db($kA)) === 1 && str_contains($h['pesan'], 'tidak memiliki izin'), $h['pesan']);
$h = minta('rizky', RIZKY, 'pages/perlengkapan.php', [], ['aksi' => 'terima_rental', 'id' => (string) $aRentalC, 'proyek_id' => (string) $pC,
    'jumlah' => '1', 'waktu' => '2026-11-07T00:05', 'kondisi' => 'Baik', 'catatan' => '', 'konfirmasi' => '1']);
cek('K14d User/Kru mencatat penerimaan rental ditolak', satu('SELECT diterima_pada FROM alokasi_barang WHERE id = :a', ['a' => $aRentalC]) === null);
minta('tamu', null, $D, ['id' => (string) $pA], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'UJI3C Tamu', 'acuan' => '', 'kategori_id' => '1', 'jumlah' => '1']);
cek('K14e tanpa login: POST ditolak', kebutuhan($pA, 'UJI3C Tamu') === null);
$h = minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'UJI3C CSRF', 'acuan' => '', 'kategori_id' => '1', 'jumlah' => '1'], false);
cek('K14f POST kebutuhan tanpa token CSRF ditolak', kebutuhan($pA, 'UJI3C CSRF') === null && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'hapus_alokasi', 'id' => (string) $aStudio], 'token-salah');
cek('K14g POST hapus alokasi dengan token CSRF salah ditolak', count(alokasi_db($kA)) === 1 && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
cek('K14h tidak ada perubahan data akibat request yang ditolak', $foto() === $sebelum);

echo "\n== K15–K16 Lintas browser dan relasi\n";
$h = minta('petugasLain', PI, $D, ['id' => (string) $pA, 'tab' => 'kebutuhan']);
cek('K15a kebutuhan dan alokasi buatan browser A tampil di browser B', bersih($h) && str_contains($h['html'], 'UJI3C Kamera') && str_contains($h['html'], $kode_u1)
    && str_contains($h['html'], 'Aputure 300d') && str_contains($h['html'], 'SD Card Nina'));
$kLintas = tambah_kebutuhan('admin', $pB, 'UJI3C Hapus Lintas Browser', 4, 1)['id'];
$ada_di_b = str_contains(minta('petugasB', PI, $D, ['id' => (string) $pB, 'tab' => 'kebutuhan'])['html'], 'UJI3C Hapus Lintas Browser');
minta('admin', SA, $D, ['id' => (string) $pB], ['aksi' => 'hapus_kebutuhan', 'id' => (string) $kLintas]);
$h = minta('petugasB', PI, $D, ['id' => (string) $pB, 'tab' => 'kebutuhan']);
cek('K15b kebutuhan yang dihapus di browser A hilang di browser B', $ada_di_b && bersih($h) && !str_contains($h['html'], 'UJI3C Hapus Lintas Browser')
    && kebutuhan($pB, 'UJI3C Hapus Lintas Browser') === null);
$id_nina = (int) satu('SELECT id FROM anggota_proyek WHERE proyek_id = :p AND pengguna_id = :u', ['p' => $pA, 'u' => NINA]);
$h = minta('petugasLain2', SA, $D, ['id' => (string) $pA], ['aksi' => 'lepas_anggota', 'id' => (string) $id_nina]);
cek('K16a kru yang membawa barang Milik Kru tidak bisa dilepas, juga dari browser lain', (int) satu('SELECT COUNT(*) FROM anggota_proyek WHERE id = :i', ['i' => $id_nina]) === 1
    && str_contains($h['pesan'], 'masih tercatat membawa'), $h['pesan']);
cek('K16b lepas_kru() menolak di dalam transaksi (bawa_barang)', json_decode(anak(['--fungsi', 'lepas_kru', (string) $pA, (string) $id_nina]), true) === 'bawa_barang');
cek('K16c relasi utuh: alokasi Studio sekategori dengan kebutuhannya, tidak ada alokasi tanpa kebutuhan',
    (int) satu("SELECT COUNT(*) FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id JOIN perlengkapan u ON u.id = a.perlengkapan_id WHERE u.kategori_id <> k.kategori_id") === 0
    && (int) satu('SELECT COUNT(*) FROM alokasi_barang a WHERE NOT EXISTS (SELECT 1 FROM kebutuhan_proyek k WHERE k.id = a.kebutuhan_id)') === 0);
cek('K16d tidak ada unit Studio yang dipesan dua proyek pada jadwal bertabrakan', (int) satu("SELECT COUNT(*) FROM alokasi_barang a JOIN alokasi_barang b
    ON a.perlengkapan_id = b.perlengkapan_id AND a.id < b.id AND a.status <> 'Dibatalkan' AND b.status <> 'Dibatalkan'
    AND a.tgl_mulai <= b.tgl_selesai AND b.tgl_mulai <= a.tgl_selesai") === 0);
cek('K16e data contoh kebutuhan/alokasi 1–41 dan isi preset tidak berubah', satu("SELECT string_agg(id || ':' || kebutuhan_id || ':' || COALESCE(perlengkapan_id, 0) || ':' || status, ',' ORDER BY id)
    FROM alokasi_barang WHERE id <= 41") === ALOKASI_SEED && (int) satu('SELECT COUNT(*) FROM detail_preset WHERE preset_id = 1') === $jumlah_item);

echo "\n== K17 Persiapan, Serah Terima, dashboard\n";
// Proyek Gamma: kebutuhan Kamera C (unit U1) dan Sewa C. Hapus kebutuhan lain agar persiapan bisa lengkap.
minta('admin', SA, $D, ['id' => (string) $pC], ['aksi' => 'hapus_alokasi', 'id' => (string) $aRentalC]);
minta('admin', SA, $D, ['id' => (string) $pC], ['aksi' => 'hapus_kebutuhan', 'id' => (string) $kRentalC]);
minta('admin', SA, $D, ['id' => (string) $pC], ['aksi' => 'hapus_kebutuhan', 'id' => (string) $kKosong]);
$h = minta('admin', SA, $D, ['id' => (string) $pC], ['aksi' => 'simpan_persiapan', 'diperiksa' => [(string) $aC]]);
$s = json_decode(anak(['--periksa-sesi', 'admin']), true);
cek('K17a checklist persiapan (session) menyimpan id alokasi PostgreSQL', in_array($aC, $s['persiapan'][$pC] ?? [], true), $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pC], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => (string) $pC, 'konfirmasi' => '1']);
cek('K17b persiapan proyek dengan alokasi PostgreSQL bisa dikonfirmasi', str_contains($h['pesan'], 'dikonfirmasi selesai'), $h['pesan']);
$h = minta('admin', SA, 'pages/serah-terima.php', [], ['aksi' => 'checkout', 'proyek_id' => (string) $pC, 'alokasi' => [(string) $aC], 'jumlah' => [(string) $aC => '1'],
    'penerima_id' => (string) RIZKY, 'waktu' => '2026-11-07T' . date('H:i'), 'catatan' => '', 'konfirmasi' => '1']);
cek('K17c Serah Terima mencatat barang keluar untuk alokasi PostgreSQL', str_contains($h['pesan'], 'unit keluar untuk'), $h['pesan'] . $h['galat']);
$h = aksi_alokasi('admin', $pC, $aC, 'hapus_alokasi');
// Di browser yang mencatatnya, kunci "persiapan sudah dikonfirmasi" atau kunci "sudah tercatat di serah terima" berlaku.
cek('K17d alokasi yang sudah keluar (serah terima di browser ini) dikunci', (int) satu('SELECT COUNT(*) FROM alokasi_barang WHERE id = :a', ['a' => $aC]) === 1
    && str_contains($h['pesan'], 'dikunci'), $h['pesan']);
$h = minta('admin', SA, 'pages/perlengkapan.php');
$pos = strpos($h['html'], $kode_u1);
cek('K17e unit yang keluar tampil Dipinjam di Inventaris browser yang mencatatnya', bersih($h) && $pos !== false && str_contains(substr($h['html'], $pos, 1500), 'Dipinjam'));
tambah_kebutuhan('admin', $pF, 'UJI3C Kamera F', 1, 1);
$h = minta('admin', SA, $D, ['id' => (string) $pF, 'tab' => 'kebutuhan']);
cek('K17f catatan "masih keluar" muncul untuk unit itu di proyek lain yang jadwalnya tidak bentrok', bersih($h) && str_contains($h['html'], 'Masih keluar di ' . satu('SELECT kode FROM proyek WHERE id = :p', ['p' => $pC])));
$h = minta('baru', SA, $D, ['id' => '7', 'tab' => 'serah']);
$s = json_decode(anak(['--periksa-sesi', 'baru']), true);
// Sejak Sprint 4A serah terima (TRX-044/045) dibaca dari PostgreSQL; session hanya menyimpan centang persiapan.
cek('K17g seed session selaras dengan PostgreSQL: alokasi #41 dicentang di persiapan PRJ-025, TRX-044/045 dari PostgreSQL', bersih($h) && str_contains($h['html'], 'SD Card 64GB')
    && in_array(41, $s['persiapan'][7] ?? [], true) && ($s['transaksi'] ?? ['x']) === [] && str_contains($h['html'], 'TRX-044'));
$masalah = [];
foreach ([[SA, 'pages/dashboard-admin.php', []], [PI, 'pages/dashboard-petugas.php', []], [RIZKY, 'pages/dashboard-user.php', []], [SA, 'pages/serah-terima.php', []],
    [SA, 'pages/perlengkapan.php', ['tab' => 'eksternal']], [SA, 'pages/riwayat.php', []], [SA, $D, ['id' => (string) $pA, 'tab' => 'persiapan']],
    [SA, $D, ['id' => (string) $pC, 'tab' => 'serah']], [RIZKY, $D, ['id' => (string) $pA]], [PI, $D, ['id' => '3', 'tab' => 'kebutuhan']]] as [$uid, $hal, $get]) {
    $h = minta('dasbor' . $uid, $uid, $hal, $get);
    if (!bersih($h) || $h['html'] === '' || str_contains($h['pesan'], 'tidak termasuk hak akses') || str_contains($h['pesan'], 'tidak ditugaskan')) {
        $masalah[] = $hal . json_encode($get) . ' ' . $h['pesan'] . ' ' . substr(strip_tags($h['mentah']), 0, 200);
    }
}
cek('K17h dashboard tiga peran, Serah Terima, Barang eksternal, Riwayat, dan tab proyek tampil tanpa galat', !$masalah, implode(' || ', $masalah));
$h = minta('dasbor1', SA, 'pages/riwayat.php');
cek('K17i halaman Riwayat menampilkan log Kebutuhan/Alokasi/Rental dari PostgreSQL', str_contains($h['html'], 'UJI3C Kamera × 2 ditambahkan') && str_contains($h['html'], '2 unit diterima dari Vendor Uji'));
$h = minta('ninaDasbor', NINA, 'pages/dashboard-user.php');
cek('K17j dashboard User/Kru menampilkan barang Milik Kru miliknya dari PostgreSQL (browser lain)', bersih($h) && str_contains($h['html'], 'SD Card Nina'));

echo "\n== K18 Session lama\n";
anak(['--sesi-lama', 'sesiLama']);
$h = minta('sesiLama', SA, $D, ['id' => '3', 'tab' => 'kebutuhan']);
$s = json_decode(anak(['--periksa-sesi', 'sesiLama']), true);
cek('K18a session lama (versi 4) di-seed ulang ke versi terbaru tanpa salinan kebutuhan/alokasi', ($s['versi'] ?? 0) >= 5 && !$s['ada_kebutuhan'] && !$s['ada_alokasi'] && bersih($h));
cek('K18b kebutuhan/alokasi buatan session lama tidak tampil, centang persiapan lama (#42) dibuang', !str_contains($h['html'], 'Kebutuhan Buatan Session')
    && !in_array(42, $s['persiapan'][3] ?? [], true));

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
