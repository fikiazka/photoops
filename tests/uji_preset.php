<?php
// Uji Sprint Backend 3B: Preset Perlengkapan dan Item Preset di PostgreSQL + kompatibilitas modul PHP Session.
// HANYA untuk database uji (nama berakhiran _test), karena uji ini menambah dan mengubah data:
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_preset.php
//
// Seperti uji_proyek.php, uji ini TIDAK mensyaratkan database uji yang baru dibuat ulang:
//   - preset uji dibuat sendiri (nama berawalan "UJI3B") dan dihapus lagi di awal/akhir uji (itemnya ikut terhapus);
//   - preset contoh 1–4 hanya dibaca (dicek dulu masih sesuai seed);
//   - kebutuhan yang diisi dari preset hanya masuk ke session uji (folder session sementara), bukan ke PostgreSQL.
// Setiap request = proses PHP terpisah dengan session tertentu (= browser).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_uji3b_sesi');

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
                'user_id' => $_SESSION['user_id'] ?? null,
                'html' => $html,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        });
    });
    chdir(dirname($_SERVER['SCRIPT_FILENAME']));
    require $_SERVER['SCRIPT_FILENAME'];
    exit;
}

// Mode anak: membuat session "lama" yang masih menyimpan salinan preset di $_SESSION['db'] (seperti sebelum 3B).
if (($argv[1] ?? '') === '--sesi-lama') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    // Sejak Sprint 4C data/seed.php sudah tidak ada; session lama disusun langsung.
    $db = ['insiden' => [], 'riwayat' => []];
    $db['preset'][99] = ['id' => 99, 'nama' => 'Preset Buatan Session', 'deskripsi' => '', 'dibuat_oleh' => 1, 'diubah_pada' => '2026-11-01 10:00'];
    $db['detail_preset'][99] = ['id' => 99, 'preset_id' => 99, 'nama_perlengkapan' => 'Model Session', 'kategori_id' => 1, 'jumlah' => 1];
    $_SESSION = ['db_versi' => 4, 'db' => $db];
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
    $pid = (int) ($argv[3] ?? 0);
    echo json_encode([
        'versi' => $_SESSION['db_versi'] ?? null,
        'ada_preset' => isset($db['preset']) || isset($db['detail_preset']),
        'kebutuhan' => array_values(array_map(fn ($k) => $k['nama'] . '|' . $k['kategori_id'] . '|' . $k['jumlah'],
            array_filter($db['kebutuhan'] ?? [], fn ($k) => (int) $k['proyek_id'] === $pid))),
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
    $_SESSION['user_id'] = 1;
    if ($jenis === 'buat_preset') {
        echo json_encode(simpan_preset(0, ['nama' => $argv[3], 'deskripsi' => ''], 1)['status']);
    } elseif ($jenis === 'tambah_item') {
        echo json_encode(simpan_item_preset((int) $argv[3], 0, ['nama_perlengkapan' => $argv[4], 'kategori_id' => 1, 'jumlah' => 1])['status']);
    } elseif (str_starts_with($jenis, 'rollback_')) {
        // pengguna_id 99999 tidak ada: INSERT riwayat_aktivitas melanggar FOREIGN KEY -> seluruh transaksi harus ROLLBACK.
        $_SESSION['user_id'] = 99999;
        $id = (int) ($argv[3] ?? 0);
        $item = (int) ($argv[4] ?? 0);
        try {
            if ($jenis === 'rollback_buat') {
                simpan_preset(0, ['nama' => 'UJI3B Rollback Baru', 'deskripsi' => ''], 1);
            } elseif ($jenis === 'rollback_ubah') {
                simpan_preset($id, ['nama' => 'UJI3B Rollback Ubah', 'deskripsi' => 'diubah'], 1);
            } elseif ($jenis === 'rollback_hapus') {
                hapus_preset($id);
            } elseif ($jenis === 'rollback_item_tambah') {
                simpan_item_preset($id, 0, ['nama_perlengkapan' => 'UJI3B Rollback Item', 'kategori_id' => 2, 'jumlah' => 3]);
            } elseif ($jenis === 'rollback_item_ubah') {
                simpan_item_preset($id, $item, ['nama_perlengkapan' => 'UJI3B Rollback Item Ubah', 'kategori_id' => 2, 'jumlah' => 9]);
            } else {
                hapus_item_preset($item);
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

// Preset contoh 1–4 dan isinya harus masih sesuai seed (uji membaca data ini).
$seed_preset = '1:Wedding Basic:5,2:Prewedding Outdoor:4,3:Event / Konser:6,4:Company Profile:6';
$isi_seed = satu("SELECT string_agg(p.id || ':' || p.nama || ':' || (SELECT COUNT(*) FROM detail_preset d WHERE d.preset_id = p.id), ',' ORDER BY p.id)
                  FROM preset_perlengkapan p WHERE p.id <= 4");
if ($isi_seed !== $seed_preset) {
    echo "[BERHENTI] Preset contoh 1–4 di database uji tidak lagi sesuai seed. Buat ulang database uji dari 001 + 002 + 003.\n";
    echo "           Ditemukan: $isi_seed\n";
    exit(1);
}

// Bersihkan sisa uji sebelumnya. detail_preset ikut terhapus (ON DELETE CASCADE). Riwayat tetap (log hanya ditambah).
function bersihkan(): void
{
    jalankan("DELETE FROM preset_perlengkapan WHERE nama ILIKE 'UJI3B%'");
    // Sejak Sprint 3C "Isi dari preset" menulis kebutuhan ke PostgreSQL (proyek contoh); kebutuhan uji dihapus lagi.
    jalankan("DELETE FROM kebutuhan_proyek WHERE nama LIKE 'UJI3B%'");
}
bersihkan();
define('RIWAYAT_AWAL', (int) satu('SELECT COALESCE(MAX(id), 0) FROM riwayat_aktivitas'));
register_shutdown_function('bersihkan');
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

// Satu request halaman. $csrf: true = token benar, false = tanpa token, string = token salah.
function minta(string $sid, ?int $user, string $halaman, array $get = [], ?array $post = null, $csrf = true): array
{
    [$proses, $pipa] = proses_anak(['--permintaan']);
    fwrite($pipa[0], json_encode(['sid' => $sid, 'user_id' => $user, 'halaman' => $halaman, 'get' => $get, 'post' => $post, 'csrf' => $csrf]));
    fclose($pipa[0]);
    $keluar = stream_get_contents($pipa[1]);
    $galat = stream_get_contents($pipa[2]);
    proc_close($proses);
    $pos = strrpos($keluar, '@@HASIL@@');
    $hasil = $pos === false ? ['flash' => [], 'form' => null, 'user_id' => null, 'html' => ''] : json_decode(substr($keluar, $pos + 9), true);
    $hasil['mentah'] = ($pos === false ? $keluar : substr($keluar, 0, $pos)) . $galat;
    $hasil['pesan'] = implode(' | ', array_column($hasil['flash'], 'pesan'));
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

// Dua proses anak berjalan bersamaan; hasilnya (JSON) dikembalikan per proses.
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

function preset_db(string $nama): ?array
{
    $b = semua("SELECT id, nama, deskripsi, dibuat_oleh, TO_CHAR(diubah_pada, 'YYYY-MM-DD HH24:MI:SS') AS diubah_pada
                FROM preset_perlengkapan WHERE nama = :n", ['n' => $nama]);
    return $b[0] ?? null;
}

function item_db(int $preset_id): array
{
    return semua('SELECT id, nama_perlengkapan, kategori_id, jumlah FROM detail_preset WHERE preset_id = :p ORDER BY id', ['p' => $preset_id]);
}

function jumlah_uji(): int
{
    return (int) satu("SELECT COUNT(*) FROM preset_perlengkapan WHERE nama ILIKE 'UJI3B%'");
}

function jumlah_riwayat(string $objek, string $keterangan): int
{
    // Hanya log sejak uji ini dimulai (riwayat_aktivitas hanya ditambah, log putaran uji sebelumnya tetap ada).
    return (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Preset' AND objek = :o AND keterangan = :k AND id > :awal",
        ['o' => $objek, 'k' => $keterangan, 'awal' => RIWAYAT_AWAL]);
}

function form_preset(array $ubah = []): array
{
    return $ubah + ['aksi' => 'simpan_preset', 'id' => '', 'nama' => 'UJI3B Preset', 'deskripsi' => ''];
}

function form_item(int $preset_id, array $ubah = []): array
{
    return $ubah + ['aksi' => 'simpan_item', 'id' => '', 'preset_id' => (string) $preset_id, 'nama_perlengkapan' => 'UJI3B Model', 'kategori_id' => '1', 'jumlah' => '1'];
}

function e_uji(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

const SA = 1, PI = 2, RIZKY = 3;
$P = 'pages/preset.php';
$D = 'pages/proyek-detail.php';

echo "Database uji: {$k['dbname']}\n\n== A. Pembacaan dan penulisan preset\n";

// T01 pembacaan
$h = minta('petugas', PI, $P);
$nama_seed = array_column(semua('SELECT nama FROM preset_perlengkapan WHERE id <= 4'), 'nama');
cek('T01a daftar preset menampilkan keempat preset contoh dari PostgreSQL',
    bersih($h) && !array_filter($nama_seed, fn ($n) => !str_contains($h['html'], e_uji($n))));
jalankan("INSERT INTO preset_perlengkapan (nama, deskripsi, dibuat_oleh, diubah_pada) VALUES ('UJI3B Langsung DB', 'ditulis langsung', 2, '2026-11-05 09:00')");
$id_langsung = (int) preset_db('UJI3B Langsung DB')['id'];
jalankan("INSERT INTO detail_preset (preset_id, nama_perlengkapan, kategori_id, jumlah) VALUES (:p, 'Sony A7 III', 1, 2)", ['p' => $id_langsung]);
$h = minta('petugas', PI, $P, ['id' => (string) $id_langsung]);
cek('T01b preset yang ditulis langsung ke PostgreSQL tampil (halaman tidak membaca salinan session)',
    bersih($h) && str_contains($h['html'], 'UJI3B Langsung DB') && str_contains($h['html'], 'ditulis langsung') && str_contains($h['html'], '1 model · 2 unit'));
cek('T01c detail preset menampilkan pembuat (dibuat_oleh) dan waktu ubah', str_contains($h['html'], 'dibuat ' . e_uji(satu('SELECT nama FROM pengguna WHERE id = 2'))) && str_contains($h['html'], 'Diubah'));
$s = json_decode(anak(['--periksa-sesi', 'petugas', '0']), true);
cek('T01d session tidak lagi menyimpan salinan preset/detail_preset', isset($s['ada_preset']) && $s['ada_preset'] === false);

// T02 tambah preset
$h = minta('admin', SA, $P, [], form_preset(['nama' => 'UJI3B Satu', 'deskripsi' => 'Preset uji']));
$p1 = preset_db('UJI3B Satu');
cek('T02a Super Admin membuat preset -> tersimpan di PostgreSQL', $p1 && $p1['deskripsi'] === 'Preset uji' && (int) $p1['dibuat_oleh'] === SA && str_contains($h['pesan'], 'dibuat'), $h['pesan']);
cek('T02b riwayat "Preset baru dibuat" tercatat di PostgreSQL', jumlah_riwayat('UJI3B Satu', 'Preset baru dibuat') === 1);
$pid1 = (int) ($p1['id'] ?? 0);
$h = minta('petugas', PI, $P, [], form_preset(['nama' => 'UJI3B Dua']));
$p2 = preset_db('UJI3B Dua');
cek('T02c Petugas Inventaris juga boleh membuat preset (preset.kelola)', $p2 && (int) $p2['dibuat_oleh'] === PI);
$pid2 = (int) ($p2['id'] ?? 0);

// T03 edit preset + validasi
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_preset(['id' => (string) $pid1, 'nama' => 'UJI3B Satu Baru', 'deskripsi' => 'Deskripsi baru']));
$h2 = minta('adminLain', SA, $P, ['id' => (string) $pid1]);
cek('T03a nama dan deskripsi preset diubah, terlihat setelah refresh di browser lain',
    preset_db('UJI3B Satu') === null && (preset_db('UJI3B Satu Baru')['deskripsi'] ?? '') === 'Deskripsi baru' && str_contains($h2['html'], 'UJI3B Satu Baru'));
cek('T03b riwayat "Nama preset diubah" menyimpan nama lama dan baru',
    (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Preset' AND keterangan = 'Nama preset diubah' AND lama = 'UJI3B Satu' AND baru = 'UJI3B Satu Baru' AND id > " . RIWAYAT_AWAL) === 1);
$awal = jumlah_uji();
$kasus = [
    'nama 2 karakter' => ['nama' => 'UJ'],
    'nama 51 karakter' => ['nama' => 'UJI3B ' . str_repeat('x', 45)],
    'deskripsi 161 karakter' => ['nama' => 'UJI3B Deskripsi', 'deskripsi' => str_repeat('d', 161)],
    'nama sama dengan preset lain (beda huruf besar/kecil)' => ['nama' => 'wedding basic'],
];
foreach ($kasus as $ket => $ubah) {
    $h = minta('admin', SA, $P, [], form_preset($ubah));
    cek('T03c preset baru ditolak: ' . $ket, jumlah_uji() === $awal && !empty($h['form']['galat']), json_encode($h['form']['galat'] ?? null, JSON_UNESCAPED_UNICODE));
}
$h = minta('admin', SA, $P, ['id' => (string) $pid2], form_preset(['id' => (string) $pid2, 'nama' => 'UJI3B Satu Baru']));
cek('T03d mengganti nama menjadi nama preset lain ditolak', preset_db('UJI3B Dua') !== null && ($h['form']['galat']['nama'] ?? '') === 'Nama preset sudah dipakai.');
$h = minta('admin', SA, $P, [], form_preset(['id' => '999999', 'nama' => 'UJI3B Hantu']));
cek('T03e mengubah preset yang tidak ada tidak membuat preset baru', preset_db('UJI3B Hantu') === null && str_contains($h['pesan'], 'tidak ditemukan'), $h['pesan']);

echo "\n== B. Item preset\n";

// T04 tambah item. diubah_pada tersimpan per detik: tunggu 1 detik agar pembaruannya pasti terlihat (tanpa ini T04d bisa gagal acak
// bila preset dibuat dan itemnya ditambah pada detik yang sama).
$sebelum = preset_db('UJI3B Satu Baru')['diubah_pada'];
sleep(1);
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['nama_perlengkapan' => 'Sony A7 III', 'kategori_id' => '3', 'jumlah' => '2']));
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['nama_perlengkapan' => 'UJI3B Drone Sewaan', 'kategori_id' => '4', 'jumlah' => '1']));
$item = item_db($pid1);
cek('T04a dua item tersimpan di detail_preset', count($item) === 2, json_encode($item));
cek('T04b model inventaris memakai kategori unitnya (bukan kategori isian form)', ($item[0]['nama_perlengkapan'] ?? '') === 'Sony A7 III' && (int) ($item[0]['kategori_id'] ?? 0) === 1);
cek('T04c model di luar inventaris tersimpan dengan kategori pilihan', ($item[1]['nama_perlengkapan'] ?? '') === 'UJI3B Drone Sewaan' && (int) ($item[1]['kategori_id'] ?? 0) === 4);
cek('T04d riwayat penambahan item tercatat dan diubah_pada preset diperbarui',
    jumlah_riwayat('UJI3B Satu Baru', 'Sony A7 III × 2 ditambahkan') === 1 && preset_db('UJI3B Satu Baru')['diubah_pada'] !== $sebelum);
$id_sony = (int) ($item[0]['id'] ?? 0);
$id_drone = (int) ($item[1]['id'] ?? 0);

// T05 ubah jumlah
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['id' => (string) $id_sony, 'nama_perlengkapan' => 'Sony A7 III', 'kategori_id' => '1', 'jumlah' => '5']));
cek('T05a jumlah item diubah 2 -> 5', (int) satu('SELECT jumlah FROM detail_preset WHERE id = :i', ['i' => $id_sony]) === 5 && count(item_db($pid1)) === 2);
cek('T05b riwayat ubah item menyimpan nilai lama dan baru',
    (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Preset' AND keterangan = 'Sony A7 III diperbarui' AND lama = 'Sony A7 III × 2' AND baru = 'Sony A7 III × 5' AND id > " . RIWAYAT_AWAL) === 1);

// T06 validasi jumlah
foreach (['0', '-1', '21', 'abc', '2.5', '', '1e1'] as $jumlah) {
    $h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['id' => (string) $id_sony, 'nama_perlengkapan' => 'Sony A7 III', 'jumlah' => $jumlah]));
    cek('T06 jumlah "' . $jumlah . '" ditolak', (int) satu('SELECT jumlah FROM detail_preset WHERE id = :i', ['i' => $id_sony]) === 5
        && ($h['form']['galat']['jumlah'] ?? '') === 'Jumlah 1 sampai 20.');
}
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['nama_perlengkapan' => 'UJI3B Batas Atas', 'kategori_id' => '2', 'jumlah' => '20']));
cek('T06 jumlah 20 (batas atas) diterima', count(item_db($pid1)) === 3);

// T07 validasi relasi
$jumlah_item = (int) satu('SELECT COUNT(*) FROM detail_preset');
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['nama_perlengkapan' => 'UJI3B Kategori Salah', 'kategori_id' => '999']));
cek('T07a kategori_id yang tidak ada ditolak', ($h['form']['galat']['kategori_id'] ?? '') === 'Pilih kategori.' && (int) satu('SELECT COUNT(*) FROM detail_preset') === $jumlah_item);
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['nama_perlengkapan' => 'UJI3B Kategori Teks', 'kategori_id' => '1 OR 1=1']));
cek('T07b kategori_id bukan angka ditolak', !empty($h['form']['galat']['kategori_id']) && (int) satu('SELECT COUNT(*) FROM detail_preset') === $jumlah_item);
$h = minta('admin', SA, $P, ['id' => '999999'], form_item(999999, ['nama_perlengkapan' => 'UJI3B Preset Hilang']));
cek('T07c item ke preset yang tidak ada ditolak (tidak jatuh ke preset pertama)',
    (int) satu('SELECT COUNT(*) FROM detail_preset') === $jumlah_item && str_contains($h['pesan'], 'Preset tidak ditemukan'), $h['pesan']);
$h = minta('admin', SA, $P, ['id' => (string) $pid2], form_item($pid2, ['id' => (string) $id_sony, 'nama_perlengkapan' => 'Sony A7 III', 'jumlah' => '9']));
cek('T07d mengubah item milik preset lain lewat preset B ditolak', (int) satu('SELECT jumlah FROM detail_preset WHERE id = :i', ['i' => $id_sony]) === 5
    && item_db($pid2) === [] && str_contains($h['pesan'], 'Item tidak ditemukan'), $h['pesan']);
$h = minta('admin', SA, $P, ['id' => (string) $pid1], ['aksi' => 'hapus_item', 'id' => '999999']);
cek('T07e menghapus item yang tidak ada tidak mengubah apa pun', bersih($h) && (int) satu('SELECT COUNT(*) FROM detail_preset') === $jumlah_item);

// T08 duplikasi
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['nama_perlengkapan' => 'sony a7 iii', 'jumlah' => '1']));
cek('T08a model yang sudah ada (beda huruf besar/kecil) ditolak', count(item_db($pid1)) === 3
    && ($h['form']['galat']['nama_perlengkapan'] ?? '') === 'Model ini sudah ada di preset. Ubah jumlahnya saja.');
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['id' => (string) $id_drone, 'nama_perlengkapan' => 'Sony A7 III', 'jumlah' => '1']));
cek('T08b mengganti nama item menjadi model lain yang sudah ada ditolak',
    satu('SELECT nama_perlengkapan FROM detail_preset WHERE id = :i', ['i' => $id_drone]) === 'UJI3B Drone Sewaan' && !empty($h['form']['galat']['nama_perlengkapan']));
$h = minta('admin', SA, $P, ['id' => (string) $pid2], form_item($pid2, ['nama_perlengkapan' => 'Sony A7 III', 'jumlah' => '1']));
cek('T08c model yang sama di preset lain diperbolehkan', count(item_db($pid2)) === 1);
[$a, $b] = paralel(['--fungsi', 'tambah_item', (string) $pid2, 'UJI3B Paralel'], ['--fungsi', 'tambah_item', (string) $pid2, 'uji3b PARALEL']);
$status = [$a, $b];
sort($status);
cek('T08d dua browser menambah model yang sama bersamaan -> satu tersimpan, satu ditolak',
    $status === ['duplikat', 'ok'] && (int) satu("SELECT COUNT(*) FROM detail_preset WHERE preset_id = :p AND LOWER(nama_perlengkapan) = 'uji3b paralel'", ['p' => $pid2]) === 1, json_encode([$a, $b]));
[$a, $b] = paralel(['--fungsi', 'buat_preset', 'UJI3B Nama Paralel'], ['--fungsi', 'buat_preset', 'uji3b nama PARALEL']);
$status = [$a, $b];
sort($status);
cek('T08e dua browser membuat preset bernama sama bersamaan -> satu tersimpan, satu ditolak',
    $status === ['nama_dipakai', 'ok'] && (int) satu("SELECT COUNT(*) FROM preset_perlengkapan WHERE LOWER(nama) = 'uji3b nama paralel'") === 1, json_encode([$a, $b]));

echo "\n== C. Transaksi, hak akses, CSRF\n";

// T09 rollback
$foto = fn () => json_encode([semua('SELECT * FROM preset_perlengkapan WHERE id IN (:a, :b) ORDER BY id', ['a' => $pid1, 'b' => $pid2]),
    semua('SELECT * FROM detail_preset WHERE preset_id IN (:a, :b) ORDER BY id', ['a' => $pid1, 'b' => $pid2])]);
$awal_foto = $foto();
$awal_jumlah = jumlah_uji();
$hasil_rb = [
    'buat' => anak(['--fungsi', 'rollback_buat']),
    'ubah' => anak(['--fungsi', 'rollback_ubah', (string) $pid1]),
    'item_tambah' => anak(['--fungsi', 'rollback_item_tambah', (string) $pid1]),
    'item_ubah' => anak(['--fungsi', 'rollback_item_ubah', (string) $pid1, (string) $id_sony]),
    'item_hapus' => anak(['--fungsi', 'rollback_item_hapus', '0', (string) $id_drone]),
    'hapus' => anak(['--fungsi', 'rollback_hapus', (string) $pid1]),
];
foreach ($hasil_rb as $jenis => $hasil) {
    cek('T09 ' . $jenis . ': riwayat gagal (FK) -> galat PDO, transaksi dibatalkan', str_starts_with($hasil, 'GAGAL_PDO:'), $hasil);
}
cek('T09 tidak ada perubahan sebagian: preset, item, dan waktu ubah sama persis seperti sebelum', $foto() === $awal_foto && jumlah_uji() === $awal_jumlah);

// T10 hak akses
$awal = jumlah_uji();
$h = minta('rizky', RIZKY, $P);
cek('T10a User/Kru membuka halaman Preset ditolak', str_contains($h['pesan'], 'tidak termasuk hak akses') && !str_contains($h['html'], 'Wedding Basic'), $h['pesan']);
$h = minta('rizky', RIZKY, $P, [], form_preset(['nama' => 'UJI3B Oleh Kru']));
cek('T10b User/Kru membuat preset lewat POST langsung ditolak', jumlah_uji() === $awal && str_contains($h['pesan'], 'tidak termasuk hak akses'));
$h = minta('rizky', RIZKY, $P, ['id' => (string) $pid1], form_item($pid1, ['id' => (string) $id_sony, 'nama_perlengkapan' => 'Sony A7 III', 'jumlah' => '1']));
cek('T10c User/Kru mengubah item lewat POST langsung ditolak', (int) satu('SELECT jumlah FROM detail_preset WHERE id = :i', ['i' => $id_sony]) === 5);
$h = minta('rizky', RIZKY, $P, [], ['aksi' => 'hapus_preset', 'id' => (string) $pid1]);
cek('T10d User/Kru menghapus preset lewat POST langsung ditolak', preset_db('UJI3B Satu Baru') !== null);
$h = minta('tamu', null, $P, [], form_preset(['nama' => 'UJI3B Tanpa Login']));
cek('T10e tanpa login: POST ditolak', jumlah_uji() === $awal && !str_contains($h['html'], 'Wedding Basic'));

// T11 CSRF
$h = minta('admin', SA, $P, [], form_preset(['nama' => 'UJI3B CSRF']), false);
cek('T11a POST tanpa token CSRF ditolak', jumlah_uji() === $awal && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
$h = minta('admin', SA, $P, ['id' => (string) $pid1], ['aksi' => 'hapus_item', 'id' => (string) $id_sony], 'token-salah');
cek('T11b POST hapus item dengan token CSRF salah ditolak', count(item_db($pid1)) === 3 && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);

echo "\n== D. Lintas browser, kebutuhan, stok\n";

// T12 lintas browser + isi kebutuhan dari preset (kebutuhan masih session)
$h = minta('admin', SA, $P, [], form_preset(['nama' => 'UJI3B Isi Kebutuhan']));
$pk = (int) (preset_db('UJI3B Isi Kebutuhan')['id'] ?? 0);
minta('admin', SA, $P, ['id' => (string) $pk], form_item($pk, ['nama_perlengkapan' => 'UJI3B Model Kebutuhan A', 'kategori_id' => '2', 'jumlah' => '3']));
minta('admin', SA, $P, ['id' => (string) $pk], form_item($pk, ['nama_perlengkapan' => 'UJI3B Model Kebutuhan B', 'kategori_id' => '5', 'jumlah' => '4']));
$h = minta('petugasB', PI, $P);
cek('T12a preset buatan browser A muncul di daftar browser B', bersih($h) && str_contains($h['html'], 'UJI3B Isi Kebutuhan') && str_contains($h['html'], '2 model · 7 unit'));
$proyek_isi = 0;
foreach (range(1, 10) as $pid) {
    $h = minta('petugasB', PI, $D, ['id' => (string) $pid], ['aksi' => 'isi_preset', 'preset_id' => (string) $pk]);
    if (str_contains($h['pesan'], '2 kebutuhan dari preset UJI3B Isi Kebutuhan ditambahkan')) {
        $proyek_isi = $pid;
        break;
    }
}
$isi_pg = array_column(semua("SELECT nama || '|' || kategori_id || '|' || jumlah AS x FROM kebutuhan_proyek WHERE proyek_id = :p", ['p' => $proyek_isi]), 'x');
cek('T12b "Isi dari preset" di browser B menyalin item preset (nama, kategori, jumlah) ke kebutuhan proyek di PostgreSQL',
    $proyek_isi > 0 && in_array('UJI3B Model Kebutuhan A|2|3', $isi_pg, true) && in_array('UJI3B Model Kebutuhan B|5|4', $isi_pg, true),
    'proyek #' . $proyek_isi);
$h = minta('petugasB', PI, $D, ['id' => (string) $proyek_isi], ['aksi' => 'isi_preset', 'preset_id' => (string) $pk]);
cek('T12c mengisi ulang dari preset yang sama melewati model yang sudah ada', str_contains($h['pesan'], '0 kebutuhan') && str_contains($h['pesan'], '2 dilewati'), $h['pesan']);
$h = minta('petugasB', PI, $D, ['id' => (string) $proyek_isi], ['aksi' => 'isi_preset', 'preset_id' => '999999']);
cek('T12d isi dari preset yang tidak ada ditolak', ($h['form']['galat']['preset_id'] ?? '') === 'Pilih preset.');
$h = minta('petugasB', PI, $D, ['id' => (string) $proyek_isi, 'tab' => 'kebutuhan']);
cek('T12e pilihan preset di dialog "Isi dari preset" berasal dari PostgreSQL', str_contains($h['html'], 'UJI3B Isi Kebutuhan · 2 item'));

// T13 stok
$h = minta('petugas', PI, $P, ['id' => '1']);
$total_pg = (int) satu("SELECT COUNT(*) FROM perlengkapan WHERE nama = 'Sony A7 III' AND status_aktif = 'Aktif'");
$baik_pg = (int) satu("SELECT COUNT(*) FROM perlengkapan WHERE nama = 'Sony A7 III' AND status_aktif = 'Aktif' AND kondisi = 'Baik'");
$pos = strpos($h['html'], '<strong>Sony A7 III</strong>');
$cocok = $pos !== false && preg_match('/(\d+) dari (\d+) unit tersedia/', $h['html'], $m, 0, $pos);
cek('T13a total stok model = jumlah unit Studio aktif di PostgreSQL', $cocok && (int) $m[2] === $total_pg, ($cocok ? $m[0] : '-') . ' / PG ' . $total_pg);
cek('T13b unit tersedia tidak melebihi unit aktif berkondisi Baik di PostgreSQL', $cocok && (int) $m[1] <= $baik_pg, ($cocok ? $m[1] : '-') . ' <= ' . $baik_pg);
cek('T13c halaman menyatakan stok = kondisi hari ini, belum memperhitungkan jadwal proyek lain', str_contains($h['html'], 'belum memperhitungkan jadwal proyek lain'));
$h = minta('petugas', PI, $P, ['id' => (string) $pid1]);
$pos = strpos($h['html'], '<strong>UJI3B Drone Sewaan</strong>');
cek('T13d model di luar inventaris ditandai perlu rental', $pos !== false && str_contains(substr($h['html'], $pos, 600), 'perlu rental'));

echo "\n== E. Hapus, session lama, riwayat\n";

// T16 hapus item dan preset
$h = minta('admin', SA, $P, ['id' => (string) $pid1], ['aksi' => 'hapus_item', 'id' => (string) $id_drone]);
cek('T16a hapus item: baris hilang, riwayat tercatat', count(item_db($pid1)) === 2 && jumlah_riwayat('UJI3B Satu Baru', 'UJI3B Drone Sewaan dihapus dari preset') === 1, $h['pesan']);
$h = minta('admin', SA, $P, [], ['aksi' => 'hapus_preset', 'id' => (string) $pk]);
cek('T16b hapus preset: preset dan semua itemnya terhapus (CASCADE), riwayat tercatat',
    preset_db('UJI3B Isi Kebutuhan') === null && item_db($pk) === [] && jumlah_riwayat('UJI3B Isi Kebutuhan', 'Preset dihapus') === 1);
cek('T16c kebutuhan proyek yang dulu diisi dari preset itu tetap ada',
    (int) satu("SELECT COUNT(*) FROM kebutuhan_proyek WHERE proyek_id = :p AND nama LIKE 'UJI3B Model Kebutuhan%'", ['p' => $proyek_isi]) === 2);

// T17 session lama yang masih menyimpan salinan preset
anak(['--sesi-lama', 'sesiLama']);
$h = minta('sesiLama', SA, $P);
$s = json_decode(anak(['--periksa-sesi', 'sesiLama', '0']), true);
cek('T17 session lama: salinan preset dibuang, preset buatan session tidak tampil', bersih($h) && ($s['ada_preset'] ?? true) === false
    && !str_contains($h['html'], 'Preset Buatan Session') && str_contains($h['html'], 'Wedding Basic'));

// T18 riwayat Preset dari PostgreSQL tampil di browser lain
$h = minta('adminLain', SA, 'pages/riwayat.php');
cek('T18 halaman Riwayat menampilkan log Preset dari PostgreSQL', bersih($h) && str_contains($h['html'], 'UJI3B Drone Sewaan dihapus dari preset'));

echo "\n== F. Frontend dan regresi\n";

// T15 frontend
$h = minta('petugas', PI, $P, ['id' => (string) $pid1]);
$elemen = ['Preset Perlengkapan', 'Preset baru', 'Tambah item', 'id="dialog-preset"', 'id="dialog-item-preset"', 'name="preset_id" value="' . $pid1 . '"',
    '<th>Model</th>', '<th>Kategori</th>', 'Stok studio', 'aria-label="Daftar preset"', 'aria-current="true"', 'pages/preset.php?id=' . $pid1];
$hilang = array_filter($elemen, fn ($x) => !str_contains($h['html'], $x));
cek('T15a halaman Preset (Petugas): daftar, tabel, tombol, dialog, navigasi lengkap', bersih($h) && !$hilang, implode(', ', $hilang));
$h = minta('admin', SA, $P);
$urut = array_map(fn ($n) => strpos($h['html'], '<span class="daftar-judul">' . e_uji($n) . '</span>'), ['Company Profile', 'Event / Konser', 'Prewedding Outdoor', 'Wedding Basic']);
cek('T15b daftar preset tetap urut nama, preset pertama terpilih bila tanpa ?id', !in_array(false, $urut, true) && $urut === array_values(array_unique($urut))
    && $urut[0] < $urut[1] && $urut[1] < $urut[2] && $urut[2] < $urut[3] && str_contains($h['html'], 'name="preset_id" value="' . satu("SELECT id FROM preset_perlengkapan ORDER BY nama LIMIT 1") . '"'));
$h = minta('admin', SA, $P, ['id' => (string) $pid1], form_item($pid1, ['nama_perlengkapan' => 'X', 'jumlah' => '0']));
$h = minta('admin', SA, $P, ['id' => (string) $pid1]);
cek('T15c galat validasi tampil kembali di dialog item (nilai lama dipertahankan)', str_contains($h['html'], 'Isi model barang.') && str_contains($h['html'], 'Jumlah 1 sampai 20.'));

// T14 regresi: login, halaman semua role
$akun = semua("SELECT id, username, password_hash FROM pengguna WHERE status = 'Aktif' AND id IN (1, 2, 3) ORDER BY id");
$akun_demo = array_values(array_filter($akun, fn ($a) => password_verify('nicio123', $a['password_hash'])));
if ($akun_demo) {
    $h = minta('loginBaru', null, 'auth.php', [], ['username' => $akun_demo[0]['username'], 'password' => 'nicio123']);
    cek('T14a login dengan akun demo berhasil (user_id tersimpan di session)', (int) $h['user_id'] === (int) $akun_demo[0]['id'], $h['pesan']);
} else {
    echo "[TIDAK DIJALANKAN] T14a login sukses: kata sandi akun 1–3 di DB uji bukan sandi demo.\n";
}
$h = minta('loginSalah', null, 'auth.php', [], ['username' => $akun[0]['username'] ?? 'x', 'password' => 'salah-sandi']);
cek('T14b login dengan kata sandi salah ditolak', $h['user_id'] === null && ($h['form']['galat']['umum'] ?? '') === 'Nama pengguna atau kata sandi salah.');
$halaman_role = [
    SA => ['pages/dashboard-admin.php' => [], 'pages/proyek.php' => [], $D . '#3' => ['id' => '3'], $P => [], 'pages/serah-terima.php' => [],
        'pages/riwayat.php' => [], 'pages/pengguna.php' => [], 'pages/perlengkapan.php' => [], 'pages/perlengkapan.php#e' => ['tab' => 'eksternal']],
    PI => ['pages/dashboard-petugas.php' => [], 'pages/proyek.php' => [], $D . '#4' => ['id' => '4', 'tab' => 'kebutuhan'], $P => [], 'pages/perlengkapan.php' => []],
    RIZKY => ['pages/dashboard-user.php' => [], 'pages/proyek.php' => [], $D . '#3' => ['id' => '3']],
];
foreach ($halaman_role as $uid => $daftar) {
    $masalah = [];
    foreach ($daftar as $hal => $get) {
        $h = minta('regresi' . $uid, $uid, explode('#', $hal)[0], $get);
        if (!bersih($h) || $h['html'] === '' || str_contains($h['pesan'], 'tidak termasuk hak akses') || str_contains($h['pesan'], 'tidak ditugaskan')) {
            $masalah[] = $hal . ' ' . $h['pesan'] . ' ' . substr(strip_tags($h['mentah']), 0, 200);
        }
    }
    cek('T14c ' . count($daftar) . ' halaman role ' . ['1' => 'sa', '2' => 'pi', '3' => 'us'][$uid] . ' tampil tanpa galat PHP/DB', !$masalah, implode(' || ', $masalah));
}

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
