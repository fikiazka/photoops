<?php
// Uji Sprint Backend 4C: Laporan Insiden dan Riwayat Aktivitas di PostgreSQL (tanpa data bisnis di session).
// HANYA untuk database uji (nama berakhiran _test), karena uji ini menambah dan mengubah data:
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_insiden.php
//
// - Proyek uji berawalan "UJI4C" (jadwal Juli 2027) diarsipkan oleh tests/bantu_bersihkan.php (transaksi tidak boleh dihapus).
// - Laporan insiden hanya bisa ditambah dan diubah statusnya (aplikasi tidak punya hak DELETE), jadi insiden uji tetap ada
//   di database uji; uji menghitung selisih, bukan jumlah absolut. Kondisi unit yang dipakai dikembalikan di akhir uji.
// - Data contoh (INS-010..012) hanya dibaca.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_uji4c_sesi');

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
                'kunci_sesi' => array_keys($_SESSION),
                'html' => $html,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        });
    });
    chdir(dirname($_SERVER['SCRIPT_FILENAME']));
    require $_SERVER['SCRIPT_FILENAME'];
    exit;
}

// Mode anak: session lama (sebelum 4C) yang masih menyimpan insiden dan log Insiden di $_SESSION['db'].
if (($argv[1] ?? '') === '--sesi-lama') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $_SESSION = ['db_versi' => 6, 'db' => [
        'insiden' => [90 => ['id' => 90, 'kode' => 'INS-SESI', 'perlengkapan_id' => 1, 'proyek_id' => null, 'jenis' => 'Rusak', 'waktu' => '2026-11-07 09:00',
            'deskripsi' => 'Insiden buatan session lama', 'status' => 'Dilaporkan', 'pelapor_id' => 2]],
        'riwayat' => [90 => ['id' => 90, 'waktu' => '2026-11-07 09:00', 'pengguna_id' => 2, 'aksi' => 'Insiden', 'objek' => 'CAM-001',
            'keterangan' => 'INS-SESI · Insiden buatan session lama', 'lama' => 'Baik', 'baru' => 'Rusak']],
    ]];
    session_write_close();
    exit;
}

if (($argv[1] ?? '') === '--periksa-sesi') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    echo json_encode(['kunci' => array_keys($_SESSION)]);
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
    $arg = array_slice($argv, 3);
    $_SESSION['user_id'] = 2;
    $kini = date('Y-m-d H:i', strtotime(sekarang()));
    try {
        if ($jenis === 'lapor') {
            $h = catat_insiden_manual((int) $arg[0], null, 'Masalah Teknis', 'UJI4C laporan bersamaan', 'Maintenance');
            echo json_encode(['status' => 'ok', 'kode' => $h['kode'] ?? '']);
        } elseif ($jenis === 'status') {
            echo json_encode(['status' => ubah_status_insiden((int) $arg[0], $arg[1])['status']]);
        } elseif ($jenis === 'kembali') {
            $a = cari_alokasi((int) $arg[1]);
            $h = catat_pengembalian(cari_proyek((int) $arg[0]), [['alokasi' => $a, 'per_kondisi' => ['Baik' => 0, 'Maintenance' => 0, 'Rusak' => 1]]], null, $kini, 'UJI4C pengembalian bersamaan');
            echo json_encode(['status' => $h['status'], 'masalah' => implode(' ', $h['masalah'] ?? [])]);
        } elseif ($jenis === 'rb_status') {
            // Pengguna sesi 99999 tidak ada: INSERT riwayat (langkah terakhir) melanggar FOREIGN KEY -> ROLLBACK.
            $_SESSION['user_id'] = 99999;
            ubah_status_insiden((int) $arg[0], $arg[1]);
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

if (satu("SELECT string_agg(kode || ':' || perlengkapan_id || ':' || jenis, ',' ORDER BY id) FROM laporan_insiden WHERE id <= 3")
    !== 'INS-010:10:Masalah Teknis,INS-011:14:Rusak,INS-012:11:Masalah Teknis') {
    echo "[BERHENTI] Insiden contoh INS-010..012 di database uji tidak lagi sesuai seed. Buat ulang database uji dari 001 + 002 + 003.\n";
    exit(1);
}

require __DIR__ . '/bantu_bersihkan.php';
$kondisi_asal = [];
function bersihkan(): void
{
    global $kondisi_asal;
    arsipkan_proyek_uji('UJI4C');
    // Insiden uji yang masih terbuka ditutup agar tidak memengaruhi hitungan "insiden terbuka" putaran berikutnya.
    jalankan("UPDATE laporan_insiden SET status = 'Selesai' WHERE deskripsi LIKE '%UJI4C%' AND status <> 'Selesai'");
    foreach ($kondisi_asal as $id => $kondisi) {
        jalankan('UPDATE perlengkapan SET kondisi = :k WHERE id = :id', ['k' => $kondisi, 'id' => $id]);
    }
}
bersihkan();
register_shutdown_function('bersihkan');
define('RIWAYAT_AWAL', (int) satu('SELECT COALESCE(MAX(id), 0) FROM riwayat_aktivitas'));
define('INSIDEN_AWAL', (int) satu('SELECT COALESCE(MAX(id), 0) FROM laporan_insiden'));
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
    $hasil = $pos === false ? ['flash' => [], 'form' => null, 'html' => '', 'kunci_sesi' => []] : json_decode(substr($keluar, $pos + 9), true);
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

function fungsi(string $jenis, array $arg = []): array
{
    return json_decode(anak(array_merge(['--fungsi', $jenis], array_map('strval', $arg))), true) ?? [];
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

function insiden(string $kolom, string $nilai): ?array
{
    $b = semua("SELECT i.*, TO_CHAR(i.waktu, 'YYYY-MM-DD HH24:MI') AS waktu_teks FROM laporan_insiden i WHERE $kolom = :v ORDER BY id DESC LIMIT 1", ['v' => $nilai]);
    return $b[0] ?? null;
}

function insiden_baru(string $syarat = 'TRUE', array $p = []): int
{
    return (int) satu("SELECT COUNT(*) FROM laporan_insiden WHERE id > :awal AND $syarat", ['awal' => INSIDEN_AWAL] + $p);
}

function kondisi(int $unit): string
{
    return (string) satu('SELECT kondisi FROM perlengkapan WHERE id = :u', ['u' => $unit]);
}

function riwayat_insiden(string $ket_like, string $lama = '%', string $baru = '%'): int
{
    return (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Insiden' AND keterangan LIKE :k AND lama LIKE :l AND baru LIKE :b AND id > :awal",
        ['k' => $ket_like, 'l' => $lama, 'b' => $baru, 'awal' => RIWAYAT_AWAL]);
}

function waktu_kini(): string
{
    return '2026-11-07T' . date('H:i');
}

const SA = 1, PI = 2, RIZKY = 3, NINA = 7;
$L = 'pages/proyek.php';
$D = 'pages/proyek-detail.php';
$S = 'pages/serah-terima.php';
$P = 'pages/perlengkapan.php';

function buat_proyek_uji(string $nama, string $tanggal): int
{
    global $L, $D;
    minta('admin', SA, $L, [], ['aksi' => 'simpan_proyek', 'id' => '', 'nama' => $nama, 'klien' => 'Klien Uji', 'lokasi' => 'Malang',
        'mulai' => $tanggal . 'T08:00', 'selesai' => $tanggal . 'T17:00', 'deadline_persiapan' => date('Y-m-d', strtotime($tanggal . ' -1 day')) . 'T20:00',
        'pic_id' => (string) RIZKY, 'status' => 'Dijadwalkan', 'catatan' => '']);
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

function form_kembali_studio(int $pid, int $aid, string $kondisi, string $catatan): array
{
    return ['aksi' => 'checkin', 'proyek_id' => (string) $pid, 'alokasi' => [(string) $aid], 'saldo' => [$aid => '1'], 'kondisi' => [$aid => $kondisi],
        'jumlah' => [], 'penyerah_id' => (string) NINA, 'waktu' => waktu_kini(), 'catatan' => $catatan, 'konfirmasi' => '1'];
}

function form_insiden(array $ubah = []): array
{
    return $ubah + ['aksi' => 'lapor_insiden', 'perlengkapan_id' => '', 'jenis' => 'Masalah Teknis', 'kondisi' => 'Maintenance', 'proyek_id' => '', 'deskripsi' => 'UJI4C laporan manual'];
}

function status_insiden(string $sid, int $user, int $id, string $aksi, $csrf = true): array
{
    global $P;
    return minta($sid, $user, $P, ['tab' => 'insiden'], ['aksi' => $aksi, 'id' => (string) $id], $csrf);
}

echo "Database uji: {$k['dbname']}\n\n== Persiapan data uji\n";
$unit = semua("SELECT u.id, u.kode_unit, u.kategori_id, u.kondisi FROM perlengkapan u
               WHERE u.status_aktif = 'Aktif' AND u.kondisi = 'Baik'
                 AND NOT EXISTS (SELECT 1 FROM alokasi_barang a JOIN v_saldo_alokasi v ON v.alokasi_id = a.id WHERE a.perlengkapan_id = u.id AND v.sisa > 0)
                 AND NOT EXISTS (SELECT 1 FROM alokasi_barang a WHERE a.perlengkapan_id = u.id AND a.status <> 'Dibatalkan' AND a.tgl_selesai >= '2027-07-01')
                 AND NOT EXISTS (SELECT 1 FROM laporan_insiden i WHERE i.perlengkapan_id = u.id AND i.status <> 'Selesai')
               ORDER BY u.id LIMIT 5");
if (count($unit) < 5) {
    echo "[BERHENTI] Database uji tidak punya 5 unit Studio aktif berkondisi Baik yang bebas.\n";
    exit(1);
}
foreach ($unit as $u) {
    $kondisi_asal[(int) $u['id']] = $u['kondisi'];
}
[$U1, $U2, $U3, $U4, $U5] = array_map(fn ($u) => ['id' => (int) $u['id'], 'kode' => $u['kode_unit'], 'kategori' => (int) $u['kategori_id']], $unit);

$p1 = buat_proyek_uji('UJI4C Satu', '2027-07-10');
$aid = [];
foreach (['U1' => $U1, 'U2' => $U2, 'U3' => $U3, 'U5' => $U5] as $nama => $u) {
    $aid[$nama] = alokasi_uji($p1, 'UJI4C Unit ' . $nama, $u['kategori'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $u['id']]);
}
$aid['Kru'] = alokasi_uji($p1, 'UJI4C SD Kru', 5, ['sumber' => 'Milik Kru', 'nama_barang' => 'SD Card UJI4C', 'pemilik_id' => (string) NINA, 'jumlah' => '1']);
minta('admin', SA, $D, ['id' => (string) $p1], ['aksi' => 'simpan_persiapan', 'diperiksa' => array_map('strval', array_values($aid))]);
minta('admin', SA, $D, ['id' => (string) $p1], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => (string) $p1, 'konfirmasi' => '1']);
$h = minta('admin', SA, $S, [], ['aksi' => 'checkout', 'proyek_id' => (string) $p1, 'alokasi' => array_map('strval', array_values($aid)),
    'jumlah' => array_fill_keys(array_values($aid), '1'), 'penerima_id' => (string) RIZKY, 'waktu' => waktu_kini(), 'catatan' => '', 'konfirmasi' => '1']);
$kode_p1 = (string) satu('SELECT kode FROM proyek WHERE id = :p', ['p' => $p1]);
cek('Persiapan: proyek uji dengan 4 unit Studio + 1 Milik Kru sudah keluar (persiapan dikonfirmasi)',
    (int) satu("SELECT COUNT(*) FROM v_saldo_alokasi WHERE proyek_id = :p AND sisa > 0", ['p' => $p1]) === 5, $h['pesan'] . $h['galat']);

echo "\n== I01–I05 Laporan insiden manual\n";
$h = minta('petugasB', PI, $P, ['tab' => 'insiden']);
$total_pg = (int) satu('SELECT COUNT(*) FROM laporan_insiden');
$terbuka_pg = (int) satu("SELECT COUNT(*) FROM laporan_insiden WHERE status <> 'Selesai'");
cek('I01a daftar insiden dibaca dari PostgreSQL (INS-010..012 dan jumlahnya sama dengan tabel)', bersih($h)
    && str_contains($h['html'], 'INS-010') && str_contains($h['html'], 'INS-012') && str_contains($h['html'], $terbuka_pg . ' terbuka dari ' . $total_pg . ' laporan'));
cek('I01b pelapor, unit, dan proyek insiden contoh tampil benar', str_contains($h['html'], 'STD-001') && str_contains($h['html'], 'dilapor Budi'));
$h = minta('admin', SA, $P, ['tab' => 'insiden'], form_insiden(['perlengkapan_id' => (string) $U4['id'], 'proyek_id' => (string) $p1]));
$i2 = insiden('i.deskripsi', 'UJI4C laporan manual');
cek('I02a laporan manual tersimpan di laporan_insiden (status Dilaporkan, pelapor, proyek, tanpa detail transaksi)', $i2 && $i2['status'] === 'Dilaporkan'
    && (int) $i2['pelapor_id'] === SA && (int) $i2['proyek_id'] === $p1 && $i2['detail_id'] === null && (int) $i2['perlengkapan_id'] === $U4['id'], $h['pesan']);
cek('I02b kode dari seq_kode_insiden (bukan MAX + 1) dan kondisi unit ikut menjadi Maintenance', $i2 && preg_match('/^INS-\d{3,}$/', $i2['kode'])
    && (int) substr($i2['kode'], 4) > 12 && kondisi($U4['id']) === 'Maintenance');
cek('I02c riwayat Insiden tercatat di PostgreSQL (kondisi Baik -> Maintenance)', $i2 && riwayat_insiden($i2['kode'] . ' · UJI4C laporan manual', 'Baik', 'Maintenance') === 1);
$sebelum = insiden_baru();
$kasus = [
    'unit tidak ada' => ['perlengkapan_id' => '999999'], 'unit bukan angka' => ['perlengkapan_id' => '1 OR 1=1'], 'jenis tidak sah' => ['jenis' => 'Hilang'],
    'kondisi tidak sah' => ['kondisi' => 'Baik'], 'proyek tidak ada' => ['proyek_id' => '999999'], 'deskripsi terlalu pendek' => ['deskripsi' => 'abc'],
    'deskripsi lebih dari 300 karakter' => ['deskripsi' => 'UJI4C ' . str_repeat('x', 300)],
];
foreach ($kasus as $ket => $ubah) {
    $h = minta('admin', SA, $P, ['tab' => 'insiden'], form_insiden($ubah + ['perlengkapan_id' => (string) $U4['id']]));
    cek('I02d laporan ditolak: ' . $ket, insiden_baru() === $sebelum && $h['galat'] !== '', $h['galat']);
}
$h = minta('adminLoginUlang', SA, $P, ['tab' => 'insiden']);
cek('I03 insiden tetap ada setelah login ulang (session baru)', bersih($h) && str_contains($h['html'], $i2['kode'] ?? '#'));
$h = minta('petugasB', PI, $P, ['tab' => 'insiden']);
cek('I04a insiden yang sama tampil di browser lain (Petugas)', bersih($h) && str_contains($h['html'], $i2['kode'] ?? '#') && str_contains($h['html'], 'UJI4C laporan manual'));
$h = minta('petugasB', PI, 'pages/dashboard-petugas.php');
$terbuka_pg = (int) satu("SELECT COUNT(*) FROM laporan_insiden WHERE status <> 'Selesai'");
cek('I04b KPI "Insiden terbuka" di dashboard browser lain = jumlah di PostgreSQL', (bool) preg_match('/Insiden terbuka<\/p><p class="kpi-angka[^"]*">' . $terbuka_pg . '</', $h['html']), (string) $terbuka_pg);
[$x, $y] = paralel(['--fungsi', 'lapor', (string) $U4['id']], ['--fungsi', 'lapor', (string) $U4['id']]);
cek('I05 dua laporan bersamaan mendapat kode berbeda (sequence)', ($x['kode'] ?? 'a') !== ($y['kode'] ?? 'a') && preg_match('/^INS-\d+$/', $x['kode'] ?? '')
    && preg_match('/^INS-\d+$/', $y['kode'] ?? '') && (int) satu('SELECT COUNT(*) - COUNT(DISTINCT kode) FROM laporan_insiden') === 0, json_encode([$x, $y]));

echo "\n== I06–I09 Insiden otomatis dari Serah Terima\n";
$h = minta('admin', SA, $S, [], form_kembali_studio($p1, $aid['U1'], 'Rusak', 'UJI4C lensa retak'));
$d1 = semua("SELECT d.id, d.kondisi, d.alokasi_id, t.jenis, t.kode FROM detail_transaksi d JOIN transaksi_barang t ON t.id = d.transaksi_id
             WHERE d.alokasi_id = :a AND t.jenis = 'Kembali'", ['a' => $aid['U1']])[0] ?? null;
$i6 = $d1 ? insiden('i.detail_id', (string) $d1['id']) : null;
cek('I06a pengembalian Rusak membuat insiden otomatis jenis Rusak di PostgreSQL', $i6 && $i6['jenis'] === 'Rusak' && $i6['status'] === 'Dilaporkan'
    && (int) $i6['perlengkapan_id'] === $U1['id'] && (int) $i6['proyek_id'] === $p1 && str_contains($h['pesan'], 'Insiden dicatat'), $h['pesan'] . $h['galat']);
cek('I06b kondisi unit menjadi Rusak (transaksi yang sama)', kondisi($U1['id']) === 'Rusak');
cek('I08 insiden menunjuk rincian pengembalian yang benar (alokasi, kondisi Rusak, transaksi Kembali)', $d1 && $d1['kondisi'] === 'Rusak' && $d1['jenis'] === 'Kembali'
    && (int) $d1['alokasi_id'] === $aid['U1'] && str_contains($i6['deskripsi'] ?? '', $d1['kode']));
$h = minta('admin', SA, $S, [], form_kembali_studio($p1, $aid['U2'], 'Maintenance', 'UJI4C kipas berisik'));
$i7 = insiden('i.perlengkapan_id', (string) $U2['id']);
cek('I07a pengembalian Maintenance membuat insiden jenis Masalah Teknis, unit Maintenance', $i7 && (int) $i7['id'] > INSIDEN_AWAL && $i7['jenis'] === 'Masalah Teknis'
    && $i7['detail_id'] !== null && kondisi($U2['id']) === 'Maintenance', $h['pesan']);
$jumlah = insiden_baru();
$h = minta('admin', SA, $S, [], form_kembali_studio($p1, $aid['U5'], 'Baik', ''));
cek('I07b pengembalian Baik tidak membuat insiden', insiden_baru() === $jumlah && kondisi($U5['id']) === 'Baik'
    && (int) satu('SELECT sisa FROM v_saldo_alokasi WHERE alokasi_id = :a', ['a' => $aid['U5']]) === 0, $h['pesan']);
$h = minta('admin', SA, $S, [], ['aksi' => 'checkin', 'proyek_id' => (string) $p1, 'alokasi' => [(string) $aid['Kru']], 'saldo' => [$aid['Kru'] => '1'], 'kondisi' => [],
    'jumlah' => [$aid['Kru'] => ['Rusak' => '1']], 'penyerah_id' => (string) NINA, 'waktu' => waktu_kini(), 'catatan' => 'UJI4C SD kru rusak', 'konfirmasi' => '1']);
cek('I07c barang Milik Kru yang kembali Rusak tidak dibuat insiden (insiden hanya untuk unit Studio)', insiden_baru() === $jumlah
    && (int) satu('SELECT sisa FROM v_saldo_alokasi WHERE alokasi_id = :a', ['a' => $aid['Kru']]) === 0, $h['pesan']);
$h = minta('admin', SA, $S, [], form_kembali_studio($p1, $aid['U1'], 'Rusak', 'UJI4C kirim ulang'));
cek('I09a pengembalian yang sama dikirim ulang ditolak, tidak ada insiden ganda', insiden_baru('perlengkapan_id = :u', ['u' => $U1['id']]) === 1, $h['galat'] . $h['pesan']);
[$x, $y] = paralel(['--fungsi', 'kembali', (string) $p1, (string) $aid['U3']], ['--fungsi', 'kembali', (string) $p1, (string) $aid['U3']]);
$st = [$x['status'] ?? '?', $y['status'] ?? '?'];
sort($st);
cek('I09b dua pengembalian Rusak bersamaan -> satu tercatat, tepat satu insiden', $st === ['ditolak', 'ok'] && insiden_baru('perlengkapan_id = :u', ['u' => $U3['id']]) === 1,
    json_encode([$x, $y]));

echo "\n== I15 Rollback pengembalian bila insiden gagal\n";
// Penghalang: ambil satu nomor dari sequence, lalu isi kode nomor BERIKUTNYA. Insiden otomatis pada pengembalian berikut
// akan bentrok UNIQUE(kode) tepat di INSERT laporan_insiden -> seluruh pengembalian harus di-ROLLBACK.
$p2 = buat_proyek_uji('UJI4C Dua', '2027-07-20');
$U6 = semua("SELECT id, kategori_id FROM perlengkapan WHERE status_aktif = 'Aktif' AND kondisi = 'Baik' AND id NOT IN (" . implode(',', array_column($unit, 'id')) . ")
             AND NOT EXISTS (SELECT 1 FROM alokasi_barang a JOIN v_saldo_alokasi v ON v.alokasi_id = a.id WHERE a.perlengkapan_id = perlengkapan.id AND v.sisa > 0)
             AND NOT EXISTS (SELECT 1 FROM alokasi_barang a WHERE a.perlengkapan_id = perlengkapan.id AND a.status <> 'Dibatalkan' AND a.tgl_selesai >= '2027-07-01')
             ORDER BY id LIMIT 1")[0];
$kondisi_asal[(int) $U6['id']] = 'Baik';
$a6 = alokasi_uji($p2, 'UJI4C Unit Enam', (int) $U6['kategori_id'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U6['id']]);
minta('admin', SA, $D, ['id' => (string) $p2], ['aksi' => 'simpan_persiapan', 'diperiksa' => [(string) $a6]]);
minta('admin', SA, $D, ['id' => (string) $p2], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => (string) $p2, 'konfirmasi' => '1']);
minta('admin', SA, $S, [], ['aksi' => 'checkout', 'proyek_id' => (string) $p2, 'alokasi' => [(string) $a6], 'jumlah' => [$a6 => '1'],
    'penerima_id' => (string) RIZKY, 'waktu' => waktu_kini(), 'catatan' => '', 'konfirmasi' => '1']);
$n = (int) satu("SELECT nextval('seq_kode_insiden')");
jalankan("INSERT INTO laporan_insiden (kode, perlengkapan_id, jenis, waktu, deskripsi, status, pelapor_id)
          VALUES (:k, :u, 'Masalah Teknis', NOW(), 'UJI4C penghalang kode (sengaja, untuk uji rollback)', 'Selesai', 1)",
    ['k' => 'INS-' . str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT), 'u' => $U4['id']]);
$trx_sebelum = (int) satu("SELECT COUNT(*) FROM transaksi_barang WHERE proyek_id = :p AND jenis = 'Kembali'", ['p' => $p2]);
$riwayat_sebelum = (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas');
$h = minta('admin', SA, $S, [], form_kembali_studio($p2, $a6, 'Rusak', 'UJI4C rollback'));
cek('I15a INSERT insiden otomatis gagal -> pengembalian, kondisi unit, dan riwayat ikut di-ROLLBACK', str_contains($h['pesan'], 'ada data yang berubah')
    && (int) satu("SELECT COUNT(*) FROM transaksi_barang WHERE proyek_id = :p AND jenis = 'Kembali'", ['p' => $p2]) === $trx_sebelum
    && (int) satu('SELECT sisa FROM v_saldo_alokasi WHERE alokasi_id = :a', ['a' => $a6]) === 1 && kondisi((int) $U6['id']) === 'Baik'
    && (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas') === $riwayat_sebelum, $h['pesan']);
$h = minta('admin', SA, $S, [], form_kembali_studio($p2, $a6, 'Rusak', 'UJI4C rollback'));
cek('I15b setelah penghalang terlewati, pengembalian yang sama berhasil dengan insidennya', (int) satu('SELECT sisa FROM v_saldo_alokasi WHERE alokasi_id = :a', ['a' => $a6]) === 0
    && insiden_baru('perlengkapan_id = :u AND detail_id IS NOT NULL', ['u' => $U6['id']]) === 1 && kondisi((int) $U6['id']) === 'Rusak', $h['pesan']);

echo "\n== I10–I14, I16, I17 Status dan kondisi\n";
$id6 = (int) $i6['id'];
$h = status_insiden('petugasB', PI, $id6, 'tangani_insiden');
cek('I10a Dilaporkan -> Ditangani dari browser lain + riwayat', satu('SELECT status FROM laporan_insiden WHERE id = :i', ['i' => $id6]) === 'Ditangani'
    && riwayat_insiden($i6['kode'] . ' mulai ditangani', 'Dilaporkan', 'Ditangani') === 1 && kondisi($U1['id']) === 'Rusak', $h['pesan']);
$h = status_insiden('admin', SA, $id6, 'tangani_insiden');
cek('I11a menandai ditangani dua kali ditolak', str_contains($h['pesan'], 'sudah berstatus Ditangani') && riwayat_insiden($i6['kode'] . ' mulai ditangani') === 1, $h['pesan']);
$h = status_insiden('admin', SA, $id6, 'selesaikan_insiden');
cek('I10b Ditangani -> Selesai; tidak ada insiden lain untuk unit itu, kondisi kembali Baik (aturan aplikasi) + riwayat', satu('SELECT status FROM laporan_insiden WHERE id = :i', ['i' => $id6]) === 'Selesai'
    && kondisi($U1['id']) === 'Baik' && riwayat_insiden($i6['kode'] . ' selesai, unit kembali layak pakai', 'Rusak', 'Baik') === 1, $h['pesan']);
$h1 = status_insiden('admin', SA, $id6, 'selesaikan_insiden');
$h2 = status_insiden('admin', SA, $id6, 'tangani_insiden');
cek('I11b insiden Selesai tidak bisa diselesaikan atau ditangani lagi', str_contains($h1['pesan'], 'sudah berstatus Selesai') && str_contains($h2['pesan'], 'sudah berstatus Selesai'));
$h = status_insiden('admin', SA, 999999, 'tangani_insiden');
cek('I11c insiden yang tidak ada ditolak', str_contains($h['pesan'], 'tidak ditemukan'), $h['pesan']);
$h = minta('admin', SA, $P, ['tab' => 'insiden'], ['aksi' => 'hapus_insiden', 'id' => (string) $i7['id']]);
cek('I11d aksi yang tidak dikenal tidak mengubah apa pun', satu('SELECT status FROM laporan_insiden WHERE id = :i', ['i' => $i7['id']]) === 'Dilaporkan');
// I14: unit U2 punya insiden otomatis (Maintenance) + laporan manual Rusak -> menyelesaikan satu tidak membuat unit Baik.
minta('admin', SA, $P, ['tab' => 'insiden'], form_insiden(['perlengkapan_id' => (string) $U2['id'], 'jenis' => 'Rusak', 'kondisi' => 'Rusak', 'deskripsi' => 'UJI4C retak kedua']));
$i14 = insiden('i.deskripsi', 'UJI4C retak kedua');
cek('I14a laporan manual kedua untuk unit yang sama mengubah kondisi menjadi Rusak', $i14 && kondisi($U2['id']) === 'Rusak');
$h = status_insiden('admin', SA, (int) $i7['id'], 'selesaikan_insiden');
cek('I14b menyelesaikan satu insiden saat masih ada insiden terbuka: kondisi unit TIDAK diubah', satu('SELECT status FROM laporan_insiden WHERE id = :i', ['i' => $i7['id']]) === 'Selesai'
    && kondisi($U2['id']) === 'Rusak' && str_contains($h['pesan'], 'masih punya 1 insiden terbuka'), $h['pesan']);
$p3 = buat_proyek_uji('UJI4C Tiga', '2027-07-25');
$kb = (int) (function () use ($p3, $U2) {
    global $D;
    minta('admin', SA, $D, ['id' => (string) $p3], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'UJI4C Cek Unit', 'acuan' => '', 'kategori_id' => (string) $U2['kategori'], 'jumlah' => '1']);
    return satu('SELECT id FROM kebutuhan_proyek WHERE proyek_id = :p', ['p' => $p3]);
})();
$h = minta('admin', SA, $D, ['id' => (string) $p3], ['aksi' => 'tambah_alokasi', 'kebutuhan_id' => (string) $kb, 'sumber' => 'Studio', 'perlengkapan_id' => (string) $U2['id'],
    'nama_barang' => '', 'vendor' => '', 'pemilik_id' => '', 'jumlah' => '1', 'status' => 'Dikonfirmasi', 'catatan' => '']);
cek('I14c unit yang masih Rusak (insiden lain terbuka) tidak bisa dialokasikan', (int) satu('SELECT COUNT(*) FROM alokasi_barang WHERE kebutuhan_id = :k', ['k' => $kb]) === 0
    && str_contains($h['galat'], 'Kondisi Rusak'), $h['galat']);
$r = fungsi('rb_status', [(int) $i14['id'], 'selesaikan']);
cek('I16 status Selesai: riwayat gagal -> status dan kondisi unit tidak berubah', str_starts_with($r['status'] ?? '', 'GAGAL_PDO:23503')
    && satu('SELECT status FROM laporan_insiden WHERE id = :i', ['i' => $i14['id']]) === 'Dilaporkan' && kondisi($U2['id']) === 'Rusak', $r['status'] ?? '');
[$x, $y] = paralel(['--fungsi', 'status', (string) $i14['id'], 'tangani'], ['--fungsi', 'status', (string) $i14['id'], 'tangani']);
$st = [$x['status'] ?? '?', $y['status'] ?? '?'];
sort($st);
cek('I17a dua "tangani" bersamaan -> satu berhasil, satu ditolak; riwayat tidak ganda', $st === ['ok', 'transisi_tidak_sah']
    && riwayat_insiden($i14['kode'] . ' mulai ditangani') === 1, json_encode([$x, $y]));
[$x, $y] = paralel(['--fungsi', 'status', (string) $i14['id'], 'selesaikan'], ['--fungsi', 'status', (string) $i14['id'], 'selesaikan']);
$st = [$x['status'] ?? '?', $y['status'] ?? '?'];
sort($st);
cek('I17b dua "selesaikan" bersamaan -> satu berhasil, unit Baik, riwayat Selesai satu kali', $st === ['ok', 'transisi_tidak_sah'] && kondisi($U2['id']) === 'Baik'
    && riwayat_insiden($i14['kode'] . ' selesai%') === 1, json_encode([$x, $y]));
$h = status_insiden('admin', SA, (int) $i2['id'], 'selesaikan_insiden');
cek('I10c Dilaporkan -> Selesai langsung (alur yang sudah ada) diterima', satu('SELECT status FROM laporan_insiden WHERE id = :i', ['i' => $i2['id']]) === 'Selesai', $h['pesan']);

echo "\n== I12, I13 Hak akses dan CSRF\n";
$sebelum = insiden_baru();
$h = minta('rizky', RIZKY, $P, ['tab' => 'insiden'], form_insiden(['perlengkapan_id' => (string) $U4['id']]));
cek('I12a User/Kru membuat laporan insiden lewat POST langsung ditolak', insiden_baru() === $sebelum && str_contains($h['pesan'], 'tidak termasuk hak akses'), $h['pesan']);
$id_terbuka = (int) satu("SELECT id FROM laporan_insiden WHERE status = 'Dilaporkan' AND id > :a ORDER BY id LIMIT 1", ['a' => INSIDEN_AWAL]);
$h = status_insiden('rizky', RIZKY, $id_terbuka, 'tangani_insiden');
cek('I12b User/Kru mengubah status insiden lewat POST langsung ditolak', satu('SELECT status FROM laporan_insiden WHERE id = :i', ['i' => $id_terbuka]) === 'Dilaporkan', $h['pesan']);
minta('tamu', null, $P, ['tab' => 'insiden'], form_insiden(['perlengkapan_id' => (string) $U4['id']]));
cek('I12c tanpa login: laporan ditolak', insiden_baru() === $sebelum);
$h = minta('admin', SA, $P, ['tab' => 'insiden'], form_insiden(['perlengkapan_id' => (string) $U4['id']]), false);
cek('I13a laporan insiden tanpa token CSRF ditolak', insiden_baru() === $sebelum && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
$h = status_insiden('admin', SA, $id_terbuka, 'tangani_insiden', 'token-salah');
cek('I13b ubah status dengan token CSRF salah ditolak', satu('SELECT status FROM laporan_insiden WHERE id = :i', ['i' => $id_terbuka]) === 'Dilaporkan'
    && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);

echo "\n== I18, I19 Riwayat dan session\n";
$h = minta('riwayatB', PI, 'pages/riwayat.php', ['aksi' => 'Insiden']);
$log_pg = semua("SELECT keterangan FROM riwayat_aktivitas WHERE aksi = 'Insiden' AND id > :a", ['a' => RIWAYAT_AWAL]);
$ganda = array_filter($log_pg, fn ($r) => substr_count($h['html'], htmlspecialchars($r['keterangan'], ENT_QUOTES, 'UTF-8')) !== 1);
cek('I18a setiap log Insiden baru tampil tepat sekali di halaman Riwayat (browser lain)', bersih($h) && $log_pg && !$ganda, count($log_pg) . ' log, ganda/hilang: ' . count($ganda));
cek('I18b log contoh INS-011 tampil sekali (tidak ganda antara session dan PostgreSQL)', substr_count($h['html'], 'Kaki stand patah saat dibongkar') === 1);
$h = minta('riwayatB', PI, 'pages/riwayat.php');
$pos_baru = strpos($h['html'], $i14['kode']);
$pos_lama = strpos($h['html'], 'Kaki stand patah saat dibongkar');
cek('I18c urutan: log terbaru (4C) tampil di atas log contoh lama', $pos_baru !== false && $pos_lama !== false && $pos_baru < $pos_lama);
anak(['--sesi-lama', 'sesiLama']);
$h = minta('sesiLama', SA, $P, ['tab' => 'insiden']);
$s = json_decode(anak(['--periksa-sesi', 'sesiLama']), true);
cek('I19a insiden buatan session lama tidak tampil; salinan session dibuang', bersih($h) && !str_contains($h['html'], 'INS-SESI') && !in_array('db', $s['kunci'] ?? ['db'], true));
$h = minta('admin', SA, $P, ['tab' => 'insiden'], form_insiden(['perlengkapan_id' => (string) $U4['id'], 'deskripsi' => 'UJI4C cek session']));
cek('I19b membuat insiden tidak menulis data bisnis ke session (hanya user_id/csrf/flash/_form)',
    !array_diff($h['kunci_sesi'] ?? ['db'], ['user_id', 'csrf', 'flash', '_form']) && insiden('i.deskripsi', 'UJI4C cek session') !== null, json_encode($h['kunci_sesi'] ?? []));

echo "\n== I20 Frontend dan integrasi\n";
$masalah = [];
foreach ([[SA, 'pages/dashboard-admin.php', []], [PI, 'pages/dashboard-petugas.php', []], [RIZKY, 'pages/dashboard-user.php', []], [SA, $P, []], [SA, $P, ['tab' => 'insiden']],
    [PI, $P, ['tab' => 'eksternal']], [SA, $S, []], [SA, 'pages/riwayat.php', []], [SA, 'pages/pengguna.php', []], [SA, 'pages/preset.php', []], [SA, $L, []],
    [SA, $D, ['id' => (string) $p1, 'tab' => 'serah']], [PI, $D, ['id' => '3', 'tab' => 'persiapan']], [RIZKY, $D, ['id' => (string) $p1]]] as [$uid, $hal, $get]) {
    $h = minta('dasbor' . $uid, $uid, $hal, $get);
    if (!bersih($h) || $h['html'] === '' || str_contains($h['pesan'], 'tidak termasuk hak akses') || str_contains($h['pesan'], 'tidak ditugaskan')) {
        $masalah[] = $hal . json_encode($get) . ' ' . $h['pesan'] . ' ' . substr(strip_tags($h['mentah']), 0, 200);
    }
}
cek('I20a dashboard tiga peran, Inventaris (unit, insiden, eksternal), Serah Terima, Riwayat, Pengguna, Preset, Proyek tampil tanpa galat', !$masalah, implode(' || ', $masalah));
$h = minta('dasbor1', SA, 'pages/dashboard-admin.php');
$terbuka_pg = (int) satu("SELECT COUNT(*) FROM laporan_insiden WHERE status <> 'Selesai'");
cek('I20b dashboard admin: KPI insiden terbuka sama dengan PostgreSQL', (bool) preg_match('/Insiden terbuka<\/p>\s*<p class="kpi-angka[^"]*">' . $terbuka_pg . '</', $h['html']), (string) $terbuka_pg);
$h = minta('dasbor1', SA, $S);
cek('I20c Serah Terima menampilkan transaksi pengembalian uji beserta kondisinya', str_contains($h['html'], $kode_p1) && str_contains($h['html'], 'Rusak'));

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
