<?php
// Uji Final Backend Stabilization: perubahan jadwal/status proyek terhadap alokasi (reservasi unit Studio) di PostgreSQL.
// HANYA untuk database uji (nama berakhiran _test), karena uji ini menambah dan mengubah data:
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_jadwal_proyek.php
//
// - Proyek uji berawalan "UJIJD" (jadwal September–Oktober 2027) dibuat sendiri; yang bertransaksi diarsipkan oleh
//   tests/bantu_bersihkan.php (transaksi tidak boleh dihapus), sisanya dihapus. Tidak mensyaratkan database yang baru dibuat ulang.
// - Rentang reservasi alokasi = tanggal batas persiapan (atau mulai, mana yang lebih awal) s.d. tanggal selesai acara.
// Setiap request = proses PHP terpisah dengan session tertentu (= browser); uji bersamaan memakai dua proses sekaligus.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_ujijd_sesi');

// Mode anak: satu request halaman, hasilnya dicetak sebagai JSON.
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
    // Pesan flash dan galat form dari request sebelumnya dibuang agar setiap pemeriksaan hanya melihat hasil request ini.
    unset($_SESSION['flash']);
    if ($r['post'] !== null) {
        unset($_SESSION['_form']);
    }
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
            echo "\n@@HASIL@@" . json_encode(['flash' => $_SESSION['flash'] ?? [], 'form' => $_SESSION['_form'] ?? null, 'html' => $html],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        });
    });
    chdir(dirname($_SERVER['SCRIPT_FILENAME']));
    require $_SERVER['SCRIPT_FILENAME'];
    exit;
}

// Mode anak: memanggil fungsi aplikasi langsung (uji bersamaan dan rollback).
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
    $_SESSION['user_id'] = 1;
    try {
        if ($jenis === 'jadwal' || $jenis === 'rb_jadwal') {
            // argv: proyek_id, mulai, selesai, deadline (format Y-m-d H:i)
            $p = cari_proyek((int) $argv[3]);
            $isi = ['nama' => $p['nama'], 'klien' => $p['klien'], 'lokasi' => $p['lokasi'], 'mulai' => $argv[4], 'selesai' => $argv[5],
                'deadline_persiapan' => $argv[6], 'status' => $p['status'], 'pic_id' => $p['pic_id'], 'catatan' => $p['catatan']];
            if ($jenis === 'rb_jadwal') {
                $_SESSION['user_id'] = 99999; // INSERT riwayat (langkah terakhir) melanggar FOREIGN KEY -> seluruh transaksi ROLLBACK.
            }
            echo json_encode(ubah_proyek((int) $argv[3], $isi)['status']);
        } elseif ($jenis === 'alokasi_studio') {
            echo json_encode(tambah_alokasi((int) $argv[3], (int) $argv[4], ['sumber' => 'Studio', 'perlengkapan_id' => (int) $argv[5], 'nama_barang' => '',
                'vendor' => '', 'pemilik_id' => null, 'jumlah' => 1, 'status' => 'Dikonfirmasi', 'catatan' => ''])['status']);
        }
    } catch (PDOException $e) {
        echo json_encode('GAGAL_PDO:' . $e->getCode());
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
if ($pdo->query('SELECT current_database()')->fetchColumn() !== $k['dbname']) {
    echo "[BERHENTI] Koneksi bukan ke {$k['dbname']}.\n";
    exit(1);
}

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

require __DIR__ . '/bantu_bersihkan.php';
function bersihkan(): void
{
    arsipkan_proyek_uji('UJIJD');
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

function fungsi(array $arg): string
{
    [$proses, $pipa] = proses_anak(array_merge(['--fungsi'], $arg));
    fclose($pipa[0]);
    $keluar = stream_get_contents($pipa[1]) . stream_get_contents($pipa[2]);
    proc_close($proses);
    return (string) json_decode(trim($keluar), true);
}

function paralel(array $arg_a, array $arg_b): array
{
    $pr = [proses_anak(array_merge(['--fungsi'], $arg_a)), proses_anak(array_merge(['--fungsi'], $arg_b))];
    $hasil = [];
    foreach ($pr as [$proses, $pipa]) {
        fclose($pipa[0]);
        $hasil[] = (string) json_decode(trim(stream_get_contents($pipa[1]) . stream_get_contents($pipa[2])), true);
        proc_close($proses);
    }
    return $hasil;
}

function bersih(array $h): bool
{
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|Parse error)\b|Layanan database PhotoOps sedang tidak tersedia/', $h['html'] . $h['mentah']);
}

function riwayat_baru(string $aksi, string $keterangan_like): int
{
    return (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = :a AND keterangan LIKE :k AND id > :awal',
        ['a' => $aksi, 'k' => $keterangan_like, 'awal' => RIWAYAT_AWAL]);
}

const SA = 1, PI = 2, RIZKY = 3, NINA = 7;
$L = 'pages/proyek.php';
$D = 'pages/proyek-detail.php';
$S = 'pages/serah-terima.php';

function proyek_db(int $pid): array
{
    return semua("SELECT kode, nama, status, TO_CHAR(mulai, 'YYYY-MM-DD HH24:MI') AS mulai, TO_CHAR(selesai, 'YYYY-MM-DD HH24:MI') AS selesai,
                         TO_CHAR(deadline_persiapan, 'YYYY-MM-DD HH24:MI') AS deadline, pic_id, catatan, klien, lokasi FROM proyek WHERE id = :p", ['p' => $pid])[0];
}

// Rentang semua alokasi aktif proyek: "mulai..selesai" per baris, urut id.
function rentang_alokasi_db(int $pid): array
{
    return array_column(semua("SELECT TO_CHAR(a.tgl_mulai, 'YYYY-MM-DD') || '..' || TO_CHAR(a.tgl_selesai, 'YYYY-MM-DD') AS r
                               FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
                               WHERE k.proyek_id = :p AND a.status <> 'Dibatalkan' ORDER BY a.id", ['p' => $pid]), 'r');
}

function buat_proyek_uji(string $nama, string $mulai, string $selesai, string $deadline): int
{
    global $L, $D;
    minta('admin', SA, $L, [], ['aksi' => 'simpan_proyek', 'id' => '', 'nama' => $nama, 'klien' => 'Klien Uji', 'lokasi' => 'Malang',
        'mulai' => $mulai, 'selesai' => $selesai, 'deadline_persiapan' => $deadline, 'pic_id' => (string) RIZKY, 'status' => 'Dijadwalkan', 'catatan' => '']);
    $pid = (int) satu('SELECT id FROM proyek WHERE nama = :n', ['n' => $nama]);
    minta('admin', SA, $D, ['id' => (string) $pid], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) NINA]);
    return $pid;
}

// Satu kebutuhan + satu alokasi. Hasil: [kebutuhan_id, alokasi_id, pesan].
function alokasi_uji(int $pid, string $nama, int $kategori, array $isi): array
{
    global $D;
    $jumlah = $isi['sumber'] === 'Studio' ? 1 : (int) ($isi['jumlah'] ?? 1);
    minta('admin', SA, $D, ['id' => (string) $pid], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => $nama, 'acuan' => '', 'kategori_id' => (string) $kategori, 'jumlah' => (string) $jumlah]);
    $kid = (int) satu('SELECT id FROM kebutuhan_proyek WHERE proyek_id = :p AND nama = :n', ['p' => $pid, 'n' => $nama]);
    $h = minta('admin', SA, $D, ['id' => (string) $pid], $isi + ['aksi' => 'tambah_alokasi', 'kebutuhan_id' => (string) $kid, 'perlengkapan_id' => '', 'nama_barang' => '',
        'vendor' => '', 'pemilik_id' => '', 'jumlah' => (string) $jumlah, 'status' => 'Dikonfirmasi', 'catatan' => '']);
    return [$kid, (int) satu("SELECT id FROM alokasi_barang WHERE kebutuhan_id = :k AND status <> 'Dibatalkan' ORDER BY id DESC LIMIT 1", ['k' => $kid]), $h['pesan'] . $h['galat']];
}

// Kirim form "Ubah proyek" dengan data proyek saat ini, ditimpa $ubah (waktu dalam format datetime-local).
function ubah_proyek_form(int $pid, array $ubah, int $user = SA, string $sid = 'admin'): array
{
    global $D;
    $p = proyek_db($pid);
    $t = fn ($w) => str_replace(' ', 'T', $w);
    return minta($sid, $user, $D, ['id' => (string) $pid], $ubah + ['aksi' => 'simpan_proyek', 'id' => (string) $pid, 'nama' => $p['nama'], 'klien' => $p['klien'],
        'lokasi' => $p['lokasi'], 'mulai' => $t($p['mulai']), 'selesai' => $t($p['selesai']), 'deadline_persiapan' => $t($p['deadline']),
        'pic_id' => (string) $p['pic_id'], 'status' => $p['status'], 'catatan' => $p['catatan']]);
}

// Tidak ada unit Studio yang dipesan dua proyek AKTIF pada rentang beririsan (reservasi proyek tertutup tidak memblokir).
function pesanan_ganda(): int
{
    return (int) satu("SELECT COUNT(*) FROM alokasi_barang a JOIN kebutuhan_proyek ka ON ka.id = a.kebutuhan_id JOIN proyek pa ON pa.id = ka.proyek_id
                       JOIN alokasi_barang b ON b.perlengkapan_id = a.perlengkapan_id AND b.id > a.id
                       JOIN kebutuhan_proyek kb ON kb.id = b.kebutuhan_id JOIN proyek pb ON pb.id = kb.proyek_id
                       WHERE a.sumber = 'Studio' AND b.sumber = 'Studio' AND a.status <> 'Dibatalkan' AND b.status <> 'Dibatalkan'
                         AND pa.status NOT IN ('Selesai', 'Dibatalkan') AND pb.status NOT IN ('Selesai', 'Dibatalkan')
                         AND a.tgl_mulai <= b.tgl_selesai AND b.tgl_mulai <= a.tgl_selesai");
}

echo "Database uji: {$k['dbname']}\n\n== Persiapan data uji\n";
$kamera = semua("SELECT id, kode_unit FROM perlengkapan WHERE kategori_id = 1 AND status_aktif = 'Aktif' AND kondisi = 'Baik' ORDER BY id LIMIT 3");
if (count($kamera) < 3) {
    echo "[BERHENTI] Database uji tidak punya 3 unit Kamera yang aktif dan Baik.\n";
    exit(1);
}
[$U1, $U2, $U3] = array_map(fn ($u) => (int) $u['id'], $kamera);
[$K1, $K2, $K3] = array_column($kamera, 'kode_unit');

$pA = buat_proyek_uji('UJIJD Alfa', '2027-09-10T08:00', '2027-09-11T17:00', '2027-09-09T20:00');   // reservasi 9–11 Sep
$pB = buat_proyek_uji('UJIJD Beta', '2027-09-20T08:00', '2027-09-21T17:00', '2027-09-19T20:00');   // reservasi 19–21 Sep
[, $aA, $m] = alokasi_uji($pA, 'UJIJD Kamera A', 1, ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U1]);
[, $aAr] = alokasi_uji($pA, 'UJIJD Sewa A', 1, ['sumber' => 'Rental', 'nama_barang' => 'UJIJD Lensa Sewa', 'vendor' => 'Vendor Uji', 'jumlah' => '2']);
[, $aAk] = alokasi_uji($pA, 'UJIJD Kru A', 4, ['sumber' => 'Milik Kru', 'nama_barang' => 'UJIJD Tripod Kru', 'pemilik_id' => (string) RIZKY]);
[, $aB] = alokasi_uji($pB, 'UJIJD Kamera B', 1, ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U1]);
cek('J00 data uji: unit ' . $K1 . ' dipesan Alfa (9–11 Sep) dan Beta (19–21 Sep), plus alokasi Rental dan Milik Kru di Alfa',
    $aA && $aAr && $aAk && $aB && rentang_alokasi_db($pA) === array_fill(0, 3, '2027-09-09..2027-09-11') && rentang_alokasi_db($pB) === ['2027-09-19..2027-09-21'], $m);

echo "\n== J01–J05 Perubahan jadwal proyek dengan alokasi aktif\n";
$h = ubah_proyek_form($pA, ['mulai' => '2027-09-13T08:00', 'selesai' => '2027-09-14T17:00', 'deadline_persiapan' => '2027-09-12T20:00']);
cek('J01a jadwal digeser ke tanggal yang bebas: proyek tersimpan dan SEMUA alokasi aktif (Studio, Rental, Milik Kru) ikut ke 12–14 Sep',
    proyek_db($pA)['mulai'] === '2027-09-13 08:00' && rentang_alokasi_db($pA) === array_fill(0, 3, '2027-09-12..2027-09-14')
    && str_contains($h['pesan'], 'Jadwal 3 alokasi barang ikut disesuaikan'), $h['pesan'] . $h['galat']);
cek('J01b riwayat mencatat perubahan jadwal acara dan penyesuaian alokasi (lama -> baru)',
    riwayat_baru('Proyek', 'Jadwal acara diubah') === 1
    && (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Alokasi' AND keterangan = 'Jadwal 3 alokasi disesuaikan dengan jadwal proyek'
                   AND lama = '2027-09-09 s.d. 2027-09-11' AND baru = '2027-09-12 s.d. 2027-09-14' AND id > :a", ['a' => RIWAYAT_AWAL]) === 1);

// Bug lama: reservasi tertinggal di tanggal lama sehingga unit bisa dipesan proyek lain di tanggal baru.
$pC = buat_proyek_uji('UJIJD Gamma', '2027-09-13T08:00', '2027-09-13T17:00', '2027-09-12T20:00');     // 12–13 Sep (tanggal BARU Alfa)
[$kC, , $m] = alokasi_uji($pC, 'UJIJD Kamera C', 1, ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U1]);
cek('J02 unit yang sama TIDAK bisa dipesan proyek lain di tanggal baru Alfa (perlindungan double booking ikut pindah)',
    (int) satu("SELECT COUNT(*) FROM alokasi_barang WHERE kebutuhan_id = :k", ['k' => $kC]) === 0 && str_contains($m, 'Bentrok dengan ' . proyek_db($pA)['kode']), $m);
$pD = buat_proyek_uji('UJIJD Delta', '2027-09-10T08:00', '2027-09-10T17:00', '2027-09-09T20:00');     // 9–10 Sep (tanggal LAMA Alfa)
[, $aD, $m] = alokasi_uji($pD, 'UJIJD Kamera D', 1, ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U1]);
cek('J03 tanggal lama Alfa sudah bebas: unit bisa dipesan proyek lain di 9–10 Sep', $aD > 0 && pesanan_ganda() === 0, $m);

$sebelum_p = proyek_db($pA);
$sebelum_a = rentang_alokasi_db($pA);
$riwayat_sebelum = (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas');
$h = ubah_proyek_form($pA, ['mulai' => '2027-09-20T08:00', 'selesai' => '2027-09-20T17:00', 'deadline_persiapan' => '2027-09-19T20:00', 'nama' => 'UJIJD Alfa Baru']);
cek('J04a jadwal yang bentrok dengan Beta ditolak dengan pesan yang menyebut unit dan proyeknya',
    str_contains($h['galat'], 'bentrok') && str_contains($h['galat'], $K1 . ' sudah dipesan ' . proyek_db($pB)['kode']), $h['galat']);
cek('J04b penolakan = rollback penuh: proyek (termasuk nama), alokasi, dan riwayat tidak berubah',
    proyek_db($pA) === $sebelum_p && rentang_alokasi_db($pA) === $sebelum_a && (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas') === $riwayat_sebelum);
$h = ubah_proyek_form($pA, ['deadline_persiapan' => '2027-09-10T20:00']);
cek('J05a batas persiapan dimajukan ke 10 Sep (rentang mulai ikut maju) bentrok dengan Delta -> ditolak',
    str_contains($h['galat'], proyek_db($pD)['kode']) && proyek_db($pA)['deadline'] === '2027-09-12 20:00', $h['galat']);
$h = ubah_proyek_form($pA, ['mulai' => '2027-09-13T10:00', 'selesai' => '2027-09-14T21:00']);
cek('J05b ubah jam saja (tanggal sama): diterima, alokasi tidak disentuh, tidak ada log penyesuaian alokasi baru',
    proyek_db($pA)['mulai'] === '2027-09-13 10:00' && rentang_alokasi_db($pA) === $sebelum_a && riwayat_baru('Alokasi', 'Jadwal % alokasi disesuaikan%') === 1
    && !str_contains($h['pesan'], 'ikut disesuaikan'), $h['pesan'] . $h['galat']);
$r = fungsi(['rb_jadwal', (string) $pA, '2027-09-24 08:00', '2027-09-24 17:00', '2027-09-23 20:00']);
cek('J05c riwayat gagal ditulis (FOREIGN KEY) -> perubahan jadwal proyek DAN alokasi ikut dibatalkan',
    str_starts_with($r, 'GAGAL_PDO:23503') && proyek_db($pA)['mulai'] === '2027-09-13 10:00' && rentang_alokasi_db($pA) === $sebelum_a, $r);

echo "\n== J06–J07 Permintaan bersamaan\n";
$pE = buat_proyek_uji('UJIJD Epsilon', '2027-10-02T08:00', '2027-10-02T17:00', '2027-10-01T20:00');   // 1–2 Okt
$pF = buat_proyek_uji('UJIJD Zeta', '2027-10-11T08:00', '2027-10-11T17:00', '2027-10-10T20:00');      // 10–11 Okt
alokasi_uji($pE, 'UJIJD Kamera E', 1, ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U2]);
alokasi_uji($pF, 'UJIJD Kamera F', 1, ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U2]);
[$x, $y] = paralel(['jadwal', (string) $pE, '2027-10-21 08:00', '2027-10-21 17:00', '2027-10-20 20:00'],
    ['jadwal', (string) $pF, '2027-10-21 08:00', '2027-10-21 17:00', '2027-10-20 20:00']);
$st = [$x, $y];
sort($st);
cek('J06 dua proyek digeser BERSAMAAN ke tanggal yang sama dengan unit yang sama -> satu berhasil, satu ditolak bentrok', $st === ['bentrok', 'ok'] && pesanan_ganda() === 0, json_encode([$x, $y]));
$pG = buat_proyek_uji('UJIJD Eta', '2027-10-28T08:00', '2027-10-28T17:00', '2027-10-27T20:00');       // 27–28 Okt
minta('admin', SA, $D, ['id' => (string) $pG], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'UJIJD Kamera G', 'acuan' => '', 'kategori_id' => '1', 'jumlah' => '1']);
$kG = (int) satu('SELECT id FROM kebutuhan_proyek WHERE proyek_id = :p', ['p' => $pG]);
$pindah = $x === 'ok' ? $pF : $pE;   // proyek yang tadi gagal masih di tanggal lamanya; geser ke 27–28 Okt sambil Eta memesan unit yang sama
[$x, $y] = paralel(['jadwal', (string) $pindah, '2027-10-28 08:00', '2027-10-28 17:00', '2027-10-27 20:00'], ['alokasi_studio', (string) $pG, (string) $kG, (string) $U2]);
cek('J07 geser jadwal BERSAMAAN dengan alokasi baru unit yang sama di proyek lain -> tepat satu berhasil',
    (($x === 'ok') xor ($y === 'ok')) && in_array($x === 'ok' ? $y : $x, ['bentrok'], true) && pesanan_ganda() === 0, json_encode([$x, $y]));

echo "\n== J08 Jadwal saat barang sudah tercatat keluar\n";
$pH = buat_proyek_uji('UJIJD Theta', '2027-09-26T08:00', '2027-09-26T17:00', '2027-09-25T20:00');
[, $aH] = alokasi_uji($pH, 'UJIJD Kamera H', 1, ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U3]);
minta('admin', SA, $D, ['id' => (string) $pH], ['aksi' => 'simpan_persiapan', 'diperiksa' => [(string) $aH]]);
minta('admin', SA, $D, ['id' => (string) $pH], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => (string) $pH, 'konfirmasi' => '1']);
$h = minta('admin', SA, $S, [], ['aksi' => 'checkout', 'proyek_id' => (string) $pH, 'alokasi' => [(string) $aH], 'jumlah' => [$aH => '1'],
    'penerima_id' => (string) RIZKY, 'waktu' => '2026-11-07T' . date('H:i'), 'catatan' => '', 'konfirmasi' => '1']);
$keluar = (int) satu('SELECT COUNT(*) FROM v_saldo_alokasi WHERE alokasi_id = :a AND sisa > 0', ['a' => $aH]) === 1;
cek('J08a data uji: barang Theta tercatat keluar', $keluar, $h['pesan'] . $h['galat']);
$h = ubah_proyek_form($pH, ['mulai' => '2027-09-27T08:00', 'selesai' => '2027-09-27T17:00', 'deadline_persiapan' => '2027-09-26T20:00']);
cek('J08b tanggal proyek yang barangnya sudah keluar TIDAK bisa digeser (reservasi tetap sesuai transaksi) + pesan jelas',
    str_contains($h['galat'], 'sudah tercatat di Serah Terima') && rentang_alokasi_db($pH) === ['2027-09-25..2027-09-26'] && proyek_db($pH)['mulai'] === '2027-09-26 08:00', $h['galat']);
$h = ubah_proyek_form($pH, ['selesai' => '2027-09-26T22:00', 'catatan' => 'UJIJD jam diperpanjang']);
cek('J08c jam di tanggal yang sama dan data lain tetap bisa diubah', proyek_db($pH)['selesai'] === '2027-09-26 22:00' && proyek_db($pH)['catatan'] === 'UJIJD jam diperpanjang', $h['pesan'] . $h['galat']);
$trx = (int) satu('SELECT COUNT(*) FROM transaksi_barang WHERE proyek_id = :p', ['p' => $pH]);
cek('J08d transaksi serah terima tidak tersentuh', $trx === 1);

echo "\n== J09–J13 Proyek Selesai/Dibatalkan\n";
$h = ubah_proyek_form($pB, ['status' => 'Dibatalkan']);
cek('J09a proyek dengan alokasi aktif bisa dibatalkan; alokasinya TIDAK dihapus/dibatalkan otomatis', proyek_db($pB)['status'] === 'Dibatalkan'
    && satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aB]) === 'Dikonfirmasi', $h['pesan'] . $h['galat']);
$h = ubah_proyek_form($pB, ['nama' => 'UJIJD Beta Diubah']);
cek('J09b data proyek tertutup tidak bisa diubah (backend menolak, pesan menjelaskan cara membuka kembali)', proyek_db($pB)['nama'] === 'UJIJD Beta'
    && str_contains($h['galat'], 'hanya bisa dibuka kembali'), $h['galat']);
$h = ubah_proyek_form($pB, ['status' => 'Selesai']);
cek('J09c status tertutup ke tertutup lain (Dibatalkan -> Selesai) ditolak', proyek_db($pB)['status'] === 'Dibatalkan', $h['galat']);
$h = ubah_proyek_form($pB, ['status' => 'Dijadwalkan', 'mulai' => '2027-09-22T08:00', 'selesai' => '2027-09-22T17:00', 'deadline_persiapan' => '2027-09-21T20:00']);
cek('J09d membuka kembali sambil mengubah jadwal ditolak (buka dulu, ubah jadwal sesudah aktif)', proyek_db($pB)['status'] === 'Dibatalkan'
    && proyek_db($pB)['mulai'] === '2027-09-20 08:00' && str_contains($h['galat'], 'hanya bisa dibuka kembali'), $h['galat']);
$h = minta('admin', SA, $D, ['id' => (string) $pB]);
cek('J09e halaman proyek tertutup menjelaskan data hanya bisa dilihat dan cara membuka kembali', bersih($h) && str_contains($h['html'], 'Untuk membuka kembali, ubah statusnya'));

$pI = buat_proyek_uji('UJIJD Iota', '2027-09-20T08:00', '2027-09-21T17:00', '2027-09-19T20:00');   // tanggal sama dengan Beta yang dibatalkan
$h = minta('admin', SA, $D, ['id' => (string) $pI, 'tab' => 'kebutuhan']);
[, $aI, $m] = alokasi_uji($pI, 'UJIJD Kamera I', 1, ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U1]);
cek('J10 reservasi proyek Dibatalkan tidak memblokir: unit Beta bisa dipesan proyek lain di tanggal yang sama', $aI > 0 && pesanan_ganda() === 0, $m);
$h = minta('admin', SA, $D, ['id' => (string) $pI, 'tab' => 'kebutuhan']);
cek('J11 dialog alokasi tidak lagi menandai unit itu bentrok dengan proyek Dibatalkan', bersih($h) && !str_contains($h['html'], 'Bentrok dengan ' . proyek_db($pB)['kode']));

$h = ubah_proyek_form($pB, ['status' => 'Dijadwalkan']);
cek('J12 membuka kembali proyek yang reservasinya sudah dipakai proyek lain ditolak (bentrok dicek ulang)', proyek_db($pB)['status'] === 'Dibatalkan'
    && str_contains($h['galat'], 'tidak bisa dibuka kembali') && str_contains($h['galat'], proyek_db($pI)['kode']) && pesanan_ganda() === 0, $h['galat']);
minta('admin', SA, $D, ['id' => (string) $pI], ['aksi' => 'batalkan_alokasi', 'id' => (string) $aI]);
$h = ubah_proyek_form($pB, ['status' => 'Dijadwalkan']);
cek('J13a setelah unit bebas, proyek berhasil dibuka kembali + riwayat status', proyek_db($pB)['status'] === 'Dijadwalkan'
    && riwayat_baru('Proyek', 'Status proyek diubah') >= 2 && pesanan_ganda() === 0, $h['pesan'] . $h['galat']);
$h = ubah_proyek_form($pB, ['nama' => 'UJIJD Beta Aktif Lagi']);
cek('J13b sesudah aktif, data proyek bisa diubah lagi', proyek_db($pB)['nama'] === 'UJIJD Beta Aktif Lagi', $h['galat']);
$h = ubah_proyek_form($pH, ['status' => 'Selesai']);
cek('J13c proyek dengan barang masih keluar tetap bisa ditandai Selesai (pengembalian dicatat kemudian)', proyek_db($pH)['status'] === 'Selesai', $h['galat']);

echo "\n== J14 Hak akses\n";
$sebelum_p = proyek_db($pA);
$h = ubah_proyek_form($pA, ['mulai' => '2027-09-15T08:00', 'selesai' => '2027-09-15T17:00', 'deadline_persiapan' => '2027-09-14T20:00'], PI, 'petugas');
cek('J14a Petugas Inventaris tidak bisa mengubah jadwal proyek (POST langsung)', proyek_db($pA) === $sebelum_p && str_contains($h['pesan'], 'tidak memiliki izin'), $h['pesan']);
$h = ubah_proyek_form($pA, ['mulai' => '2027-09-15T08:00', 'selesai' => '2027-09-15T17:00', 'deadline_persiapan' => '2027-09-14T20:00'], RIZKY, 'rizky');
cek('J14b User/Kru (PIC) tidak bisa mengubah jadwal proyek', proyek_db($pA) === $sebelum_p);
$h = minta('admin', SA, $D, ['id' => (string) $pA], ['aksi' => 'simpan_proyek', 'id' => (string) $pA] + ['mulai' => '2027-09-15T08:00'], false);
cek('J14c tanpa token CSRF ditolak', proyek_db($pA) === $sebelum_p && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);

cek('J15 invarian akhir: tidak ada unit Studio yang dipesan dua proyek aktif pada rentang beririsan', pesanan_ganda() === 0);

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
