<?php
// Uji Sprint Backend 3A: Proyek dan Anggota Proyek di PostgreSQL + kompatibilitas modul PHP Session.
// HANYA untuk database uji (nama berakhiran _test), karena uji ini menambah dan mengubah data:
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_proyek.php
//
// Berbeda dengan uji_inventaris.php, uji ini TIDAK mensyaratkan database uji yang baru dibuat ulang:
//   - proyek uji dibuat sendiri (nama berawalan "UJI3A") dan dihapus lagi di awal/akhir uji;
//   - proyek contoh 1–10 hanya dibaca (dicek dulu masih sesuai seed);
//   - akun Dimas (id 6) dinonaktifkan sementara untuk uji PIC/kru nonaktif, lalu diaktifkan kembali.
// Skenario persiapan memakai alokasi Milik Kru di proyek uji, sehingga tidak bergantung pada kondisi unit Studio.
// Cara kerja sama dengan uji_inventaris.php: setiap request = proses PHP terpisah dengan session tertentu (= browser).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_uji3a_sesi');

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

// Mode anak: membuat session "lama" (VERSI_DATA 3, sebelum 3A) berisi proyek buatan session #11 dan kebutuhannya.
if (($argv[1] ?? '') === '--sesi-lama') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $_SESSION = ['db_versi' => 3, 'db' => [
        'proyek' => [11 => ['id' => 11, 'kode' => 'PRJ-026', 'nama' => 'Proyek Buatan Session', 'klien' => 'X', 'lokasi' => 'X',
            'mulai' => '2026-11-20 08:00', 'selesai' => '2026-11-20 17:00', 'deadline_persiapan' => '2026-11-19 20:00',
            'status' => 'Dijadwalkan', 'pic_id' => 3, 'catatan' => '', 'dibuat_oleh' => 1]],
        'anggota_proyek' => [21 => ['id' => 21, 'proyek_id' => 11, 'pengguna_id' => 3, 'peran' => 'PIC']],
        'kebutuhan' => [99 => ['id' => 99, 'proyek_id' => 11, 'nama' => 'Kebutuhan Session', 'acuan' => '', 'kategori_id' => 1, 'jumlah' => 1]],
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
    $pid = (int) ($argv[3] ?? 0);
    echo json_encode([
        'versi' => $_SESSION['db_versi'] ?? null,
        'ada_proyek' => isset($db['proyek']),
        'ada_anggota' => isset($db['anggota_proyek']),
        'kebutuhan' => array_values(array_map(fn ($k) => (int) $k['id'], array_filter($db['kebutuhan'] ?? [], fn ($k) => (int) $k['proyek_id'] === $pid))),
        'alokasi' => array_values(array_map(fn ($a) => (int) $a['id'], array_filter($db['alokasi'] ?? [], fn ($a) => (int) $a['proyek_id'] === $pid))),
        'kebutuhan_proyek_11' => count(array_filter($db['kebutuhan'] ?? [], fn ($k) => (int) $k['proyek_id'] === 11)),
        'konfirmasi' => array_values(array_filter($db['riwayat'] ?? [], fn ($r) => $r['aksi'] === 'Persiapan' && $r['baru'] === 'Dikonfirmasi Selesai')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mode anak: memanggil fungsi PHP langsung (uji bersamaan, rollback, fungsi baca).
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
    $isi = fn (string $nama, int $pic) => ['nama' => $nama, 'klien' => 'Klien Uji', 'lokasi' => 'Malang', 'mulai' => '2026-11-25 08:00',
        'selesai' => '2026-11-25 17:00', 'deadline_persiapan' => '2026-11-24 20:00', 'status' => 'Draf', 'pic_id' => $pic, 'catatan' => ''];
    if ($jenis === 'buat_banyak') {
        $kode = [];
        for ($i = 0; $i < (int) $argv[3]; $i++) {
            $kode[] = buat_proyek($isi('UJI3A Bersamaan', 4), 1)['kode'] ?? null;
        }
        echo json_encode($kode);
    } elseif ($jenis === 'tambah_kru') {
        echo json_encode(tambah_kru((int) $argv[3], (int) $argv[4]));
    } elseif (str_starts_with($jenis, 'rollback_')) {
        // pengguna_id 99999 tidak ada: INSERT riwayat_aktivitas melanggar FOREIGN KEY -> seluruh transaksi harus ROLLBACK.
        $_SESSION['user_id'] = 99999;
        try {
            if ($jenis === 'rollback_buat') {
                buat_proyek($isi('UJI3A Rollback', 4), 1);
            } elseif ($jenis === 'rollback_ubah') {
                $p = cari_proyek((int) $argv[3]);
                ubah_proyek($p['id'], ['nama' => 'UJI3A NAMA ROLLBACK', 'pic_id' => 5, 'status' => 'Dibatalkan', 'deadline_persiapan' => '2026-11-01 08:00']
                    + array_intersect_key($p, array_flip(['klien', 'lokasi', 'mulai', 'selesai', 'catatan'])));
            } elseif ($jenis === 'rollback_tambah') {
                tambah_kru((int) $argv[3], (int) $argv[4]);
            } else {
                lepas_kru((int) $argv[3], (int) $argv[4]);
            }
            echo 'TANPA_GALAT';
        } catch (PDOException $e) {
            echo 'GAGAL_PDO:' . $e->getCode();
        }
    } elseif ($jenis === 'deadline_lama') {
        // Sejak Sprint 4B log konfirmasi di PostgreSQL: deadline saat konfirmasi = nilai 'lama' perubahan deadline pertama
        // yang id log-nya lebih besar dari id log konfirmasi terakhir proyek ini.
        $proyek = cari_proyek((int) $argv[3]);
        $id_log = (int) (sql_satu("SELECT MAX(id) AS id FROM riwayat_aktivitas WHERE aksi = 'Persiapan' AND baru = 'Dikonfirmasi Selesai' AND objek = :o",
            ['o' => $proyek['kode']])['id'] ?? 0);
        echo json_encode(deadline_saat_konfirmasi(konteks_log_proyek(), $proyek, $id_log));
    } elseif ($jenis === 'id_proyek') {
        echo json_encode(id_proyek_pengguna((int) $argv[3]));
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

function jalankan(string $sql, array $p = []): void
{
    global $pdo;
    $pdo->prepare($sql)->execute($p);
}

// Proyek contoh 1–10 dan kru-nya harus masih sesuai seed (uji membaca data ini untuk hak akses User/Kru).
$pic_seed = '1:4,2:5,3:3,4:4,5:5,6:7,7:6,8:7,9:5,10:4';
$kru_seed = '1:3,2:7,3:4,3:6,4:3,5:3,7:7,8:3,9:6,10:3';
if (satu("SELECT string_agg(id || ':' || pic_id, ',' ORDER BY id) FROM proyek WHERE id <= 10") !== $pic_seed
    || satu("SELECT string_agg(proyek_id || ':' || pengguna_id, ',' ORDER BY proyek_id, pengguna_id) FROM anggota_proyek WHERE proyek_id <= 10") !== $kru_seed) {
    echo "[BERHENTI] Proyek/kru contoh 1–10 di database uji tidak lagi sesuai seed. Buat ulang database uji dari 001 + 002 + 003.\n";
    exit(1);
}

// Bersihkan sisa uji sebelumnya. Proyek uji tidak punya transaksi serah terima, jadi DELETE berjalan;
// anggota_proyek dan persiapan_proyek ikut terhapus (ON DELETE CASCADE). Riwayatnya tetap (log hanya ditambah).
function bersihkan(): void
{
    jalankan("DELETE FROM proyek WHERE nama LIKE 'UJI3A%'");
    jalankan("UPDATE pengguna SET status = 'Aktif' WHERE id = 6");
}
bersihkan();
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
    $hasil = $pos === false ? ['flash' => [], 'form' => null, 'html' => ''] : json_decode(substr($keluar, $pos + 9), true);
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

function proyek_db(string $nama): ?array
{
    global $pdo;
    $s = $pdo->prepare("SELECT id, kode, nama, status, pic_id, TO_CHAR(deadline_persiapan, 'YYYY-MM-DD HH24:MI') AS deadline_persiapan
                        FROM proyek WHERE nama = :n ORDER BY id DESC LIMIT 1");
    $s->execute(['n' => $nama]);
    return $s->fetch() ?: null;
}

function kru_db(int $pid): array
{
    return array_map('intval', array_filter(explode(',', (string) satu('SELECT string_agg(pengguna_id::text, \',\' ORDER BY pengguna_id) FROM anggota_proyek WHERE proyek_id = :p', ['p' => $pid]))));
}

function jumlah_uji(): int
{
    return (int) satu("SELECT COUNT(*) FROM proyek WHERE nama LIKE 'UJI3A%'");
}

// Isian form proyek (format input datetime-local seperti dari browser).
function form_proyek(array $ubah = []): array
{
    return $ubah + ['aksi' => 'simpan_proyek', 'id' => '', 'nama' => 'UJI3A Proyek', 'klien' => 'Klien Uji', 'lokasi' => 'Malang',
        'mulai' => '2026-11-21T08:00', 'selesai' => '2026-11-21T17:00', 'deadline_persiapan' => '2026-11-20T20:00',
        'pic_id' => '3', 'status' => 'Draf', 'catatan' => ''];
}

function form_dari_db(array $p, array $ubah = []): array
{
    $isi = satu("SELECT json_build_object('nama', nama, 'klien', klien, 'lokasi', lokasi, 'mulai', TO_CHAR(mulai, 'YYYY-MM-DD\"T\"HH24:MI'),
                 'selesai', TO_CHAR(selesai, 'YYYY-MM-DD\"T\"HH24:MI'), 'deadline_persiapan', TO_CHAR(deadline_persiapan, 'YYYY-MM-DD\"T\"HH24:MI'),
                 'pic_id', pic_id::text, 'status', status, 'catatan', catatan) FROM proyek WHERE id = :id", ['id' => $p['id']]);
    return $ubah + ['aksi' => 'simpan_proyek', 'id' => (string) $p['id']] + json_decode($isi, true);
}

const SA = 1, PI = 2, RIZKY = 3, SARI = 4, BAGAS = 5, DIMAS = 6, NINA = 7;
$L = 'pages/proyek.php';
$D = 'pages/proyek-detail.php';

echo "Database uji: {$k['dbname']}\n\n== A. Proyek di PostgreSQL\n";

// Q01 buat proyek
$h = minta('admin', SA, $L, [], form_proyek(['nama' => 'UJI3A Satu']));
$p1 = proyek_db('UJI3A Satu');
cek('Q01a Super Admin membuat proyek -> tersimpan di PostgreSQL dengan kode PRJ-xxx dari sequence',
    $p1 && preg_match('/^PRJ-\d{3,}$/', $p1['kode']) && (int) substr($p1['kode'], 4) > 25 && str_contains($h['pesan'], $p1['kode'] . ' dibuat'), $h['pesan']);
cek('Q01b tepat satu baris persiapan_proyek terbentuk', $p1 && (int) satu('SELECT COUNT(*) FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $p1['id']]) === 1);
cek('Q01c riwayat "Proyek baru dibuat" tercatat di PostgreSQL oleh Super Admin',
    $p1 && (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Proyek' AND objek = :o AND keterangan = 'Proyek baru dibuat' AND pengguna_id = 1", ['o' => $p1['kode'] . ' · UJI3A Satu']) === 1);
cek('Q01d PIC tersimpan di proyek.pic_id, bukan di anggota_proyek', $p1 && (int) $p1['pic_id'] === RIZKY && kru_db((int) $p1['id']) === []);
$pid1 = (int) ($p1['id'] ?? 0);

// Q02 terlihat dari browser lain
$h = minta('petugasLain', PI, $L);
cek('Q02a proyek baru terlihat di daftar proyek browser lain (Petugas)', bersih($h) && str_contains($h['html'], $p1['kode'] ?? '#'));
$h = minta('petugasLain', PI, $D, ['id' => (string) $pid1]);
cek('Q02b detail proyek baru terbuka di browser lain, PIC tampil di tim', bersih($h) && str_contains($h['html'], 'UJI3A Satu') && str_contains($h['html'], 'Rizky Pratama'));

// Q03 kode unik saat dibuat bersamaan
[$a, $b] = paralel(['--fungsi', 'buat_banyak', '10'], ['--fungsi', 'buat_banyak', '10']);
$kode = array_merge($a ?: [], $b ?: []);
cek('Q03a 20 proyek dari dua proses bersamaan semuanya berhasil', count($kode) === 20 && !in_array(null, $kode, true), count($kode) . ' kode');
cek('Q03b tidak ada kode proyek ganda', count(array_unique($kode)) === 20 && (int) satu('SELECT COUNT(*) - COUNT(DISTINCT kode) FROM proyek') === 0);
cek('Q03c setiap proyek bersamaan punya tepat satu persiapan_proyek',
    (int) satu("SELECT COUNT(*) FROM proyek p WHERE p.nama = 'UJI3A Bersamaan' AND (SELECT COUNT(*) FROM persiapan_proyek s WHERE s.proyek_id = p.id) <> 1") === 0);

// Q04 validasi
jalankan("UPDATE pengguna SET status = 'Nonaktif' WHERE id = 6");
$tidak_valid = [
    'nama 81 karakter' => ['nama' => 'UJI3A ' . str_repeat('x', 75)],
    'klien kosong' => ['klien' => ''],
    'catatan 301 karakter' => ['catatan' => str_repeat('c', 301)],
    'selesai sebelum mulai' => ['selesai' => '2026-11-21T07:00'],
    'deadline setelah mulai' => ['deadline_persiapan' => '2026-11-21T09:00'],
    'status tidak sah' => ['status' => 'Ditunda'],
    'PIC Petugas Inventaris' => ['pic_id' => '2'],
    'PIC tidak ada' => ['pic_id' => '99999'],
    'PIC bukan angka' => ['pic_id' => '3 OR 1=1'],
    'PIC Nonaktif' => ['pic_id' => '6'],
];
foreach ($tidak_valid as $ket => $ubah) {
    $awal = jumlah_uji();
    $h = minta('admin', SA, $L, [], form_proyek($ubah + ['nama' => 'UJI3A Invalid']));
    cek('Q04 ditolak dengan pesan form: ' . $ket, bersih($h) && !empty($h['form']['galat']) && jumlah_uji() === $awal, implode(' ', $h['form']['galat'] ?? []) . ' ' . $h['pesan']);
}
$h = minta('admin', SA, $L, [], form_dari_db($p1, ['pic_id' => '6']));
cek('Q04 ubah proyek dengan PIC Nonaktif ditolak', !empty($h['form']['galat']['pic_id']) && (int) proyek_db('UJI3A Satu')['pic_id'] === RIZKY);

echo "\n== B. Ubah proyek, PIC, dan kru\n";
// Q05 ubah status
$h = minta('admin', SA, $D, ['id' => (string) $pid1], form_dari_db($p1, ['status' => 'Dijadwalkan', 'nama' => 'UJI3A Satu']));
cek('Q05a status proyek diubah di PostgreSQL', proyek_db('UJI3A Satu')['status'] === 'Dijadwalkan', $h['pesan']);
cek('Q05b riwayat "Status proyek diubah" Draf -> Dijadwalkan tercatat',
    (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Proyek' AND objek LIKE :o AND keterangan = 'Status proyek diubah' AND lama = 'Draf' AND baru = 'Dijadwalkan'", ['o' => $p1['kode'] . ' %']) === 1);

// Q07 tambah kru
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) SARI]);
cek('Q07a tambah kru Sari -> 1 baris anggota_proyek + riwayat Tim', kru_db($pid1) === [SARI] && str_contains($h['pesan'], 'ditambahkan')
    && (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Tim' AND objek = :o AND keterangan LIKE 'Sari Dewi ditambahkan%'", ['o' => $p1['kode']]) === 1, $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) SARI]);
cek('Q07b tambah kru yang sama lagi ditolak "sudah ada di tim"', kru_db($pid1) === [SARI] && str_contains($h['form']['galat']['pengguna_id'] ?? '', 'sudah ada'));
[$a, $b] = paralel(['--fungsi', 'tambah_kru', (string) $pid1, (string) NINA], ['--fungsi', 'tambah_kru', (string) $pid1, (string) NINA]);
$hasil = [$a, $b];
sort($hasil);
cek('Q07c dua proses menambah Nina bersamaan -> tepat 1 baris (ok + sudah_ada)', $hasil === ['ok', 'sudah_ada'] && kru_db($pid1) === [SARI, NINA], json_encode([$a, $b]));
$h = minta('tim', SA, $D, ['id' => (string) $pid1]);
cek('Q07d daftar tim menampilkan PIC lalu kru dengan badge peran', bersih($h) && preg_match('/Rizky Pratama.*?PIC.*?(Nina Kartika|Sari Dewi).*?Kru/s', $h['html']) === 1);

// Q08 aturan tambah kru
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) RIZKY]);
cek('Q08a PIC tidak bisa ditambahkan sebagai kru', !in_array(RIZKY, kru_db($pid1), true) && str_contains($h['form']['galat']['pengguna_id'] ?? '', 'PIC'));
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) PI]);
cek('Q08b Petugas Inventaris tidak bisa menjadi kru', !in_array(PI, kru_db($pid1), true) && !empty($h['form']['galat']['pengguna_id']));
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) DIMAS]);
cek('Q08c User/Kru Nonaktif tidak bisa menjadi kru', !in_array(DIMAS, kru_db($pid1), true) && !empty($h['form']['galat']['pengguna_id']));
jalankan("UPDATE pengguna SET status = 'Aktif' WHERE id = 6");

// Q06 ganti PIC: Rizky -> Sari (Sari sebelumnya kru)
$h = minta('admin', SA, $D, ['id' => (string) $pid1], form_dari_db(proyek_db('UJI3A Satu'), ['pic_id' => (string) SARI]));
cek('Q06a PIC baru tersimpan di proyek.pic_id', (int) proyek_db('UJI3A Satu')['pic_id'] === SARI, $h['pesan']);
cek('Q06b PIC lama (Rizky) menjadi kru, PIC baru (Sari) tidak tercatat ganda sebagai kru', kru_db($pid1) === [RIZKY, NINA]);
cek('Q06c riwayat Tim "PIC diganti" Rizky -> Sari tercatat',
    (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Tim' AND objek = :o AND keterangan LIKE 'PIC diganti%' AND lama = 'Rizky Pratama' AND baru = 'Sari Dewi'", ['o' => $p1['kode']]) === 1);
$h = minta('rizky', RIZKY, $D, ['id' => (string) $pid1]);
cek('Q06d mantan PIC masih bisa membuka proyek sebagai kru', bersih($h) && str_contains($h['html'], 'UJI3A Satu') && !str_contains($h['pesan'], 'tidak ditugaskan'));

// Q09 lepas kru: ditolak bila masih membawa barang Milik Kru, lalu berhasil setelah alokasinya tidak ada
minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'SD Card cadangan', 'acuan' => '', 'kategori_id' => '5', 'jumlah' => '1']);
// Sejak Sprint 3C kebutuhan dan alokasi ada di PostgreSQL.
$kb = (int) satu('SELECT id FROM kebutuhan_proyek WHERE proyek_id = :p ORDER BY id DESC LIMIT 1', ['p' => $pid1]);
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'tambah_alokasi', 'kebutuhan_id' => (string) $kb, 'sumber' => 'Milik Kru',
    'nama_barang' => 'SD Card milik Nina', 'pemilik_id' => (string) NINA, 'jumlah' => '1', 'status' => 'Dikonfirmasi', 'catatan' => '']);
$alokasi_nina = (int) satu('SELECT id FROM alokasi_barang WHERE kebutuhan_id = :k ORDER BY id DESC LIMIT 1', ['k' => $kb]);
cek('Q09a (persiapan uji) alokasi Milik Kru Nina tercatat di PostgreSQL untuk proyek uji', $kb > 0 && $alokasi_nina > 0, $h['pesan']);
$id_nina = (int) satu('SELECT id FROM anggota_proyek WHERE proyek_id = :p AND pengguna_id = :u', ['p' => $pid1, 'u' => NINA]);
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'lepas_anggota', 'id' => (string) $id_nina]);
cek('Q09b lepas kru yang masih membawa barang Milik Kru ditolak', in_array(NINA, kru_db($pid1), true) && str_contains($h['pesan'], 'masih tercatat membawa'), $h['pesan']);
$id_rizky = (int) satu('SELECT id FROM anggota_proyek WHERE proyek_id = :p AND pengguna_id = :u', ['p' => $pid1, 'u' => RIZKY]);
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'lepas_anggota', 'id' => (string) $id_rizky]);
cek('Q09c lepas kru tanpa barang bawaan berhasil + riwayat Tim', kru_db($pid1) === [NINA] && str_contains($h['pesan'], 'dilepas')
    && (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Tim' AND objek = :o AND keterangan = 'Rizky Pratama dilepas dari tim'", ['o' => $p1['kode']]) === 1, $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'lepas_anggota', 'id' => '0']);
cek('Q09d PIC (baris id 0) tidak bisa dilepas', (int) proyek_db('UJI3A Satu')['pic_id'] === SARI && str_contains($h['pesan'], 'PIC diganti lewat Ubah proyek'));

echo "\n== C. Hak akses, CSRF, hapus, rollback\n";
// Q10 visibilitas User/Kru (Rizky: PIC PRJ-021, kru PRJ-019/022/023/016/018; bukan anggota PRJ-020/024/025/017)
$h = minta('rizky', RIZKY, $L);
$terlihat = ['PRJ-019', 'PRJ-021', 'PRJ-022', 'PRJ-023', 'PRJ-016', 'PRJ-018'];
$tersembunyi = ['PRJ-020', 'PRJ-024', 'PRJ-025', 'PRJ-017', $p1['kode']];
cek('Q10a User/Kru hanya melihat proyek tempat ia menjadi PIC atau kru',
    bersih($h) && !array_filter($terlihat, fn ($kd) => !str_contains($h['html'], $kd)) && !array_filter($tersembunyi, fn ($kd) => str_contains($h['html'], $kd)));
$h = minta('rizky', RIZKY, $D, ['id' => '2']);
cek('Q10b User/Kru membuka detail proyek orang lain ditolak', str_contains($h['pesan'], 'tidak ditugaskan') && !str_contains($h['html'], 'Senandika'));
$h = minta('rizky', RIZKY, $D, ['id' => (string) $pid1]);
cek('Q10c kru yang sudah dilepas tidak lagi bisa membuka proyek', str_contains($h['pesan'], 'tidak ditugaskan'));
$h = minta('nina', NINA, $D, ['id' => (string) $pid1]);
cek('Q10d kru yang ditambahkan dari browser admin bisa membuka proyek di browsernya sendiri', bersih($h) && str_contains($h['html'], 'UJI3A Satu'));
$h = minta('petugasLain', PI, $L);
cek('Q10e Petugas Inventaris melihat semua proyek', bersih($h) && str_contains($h['html'], 'PRJ-020') && str_contains($h['html'], $p1['kode']));

// Q11 RBAC tulis
$awal = jumlah_uji();
foreach ([PI => 'Petugas', RIZKY => 'User/Kru', null => 'tanpa login'] as $uid => $ket) {
    $h = minta('rbac' . $ket, $uid === '' ? null : $uid, $L, [], form_proyek(['nama' => 'UJI3A RBAC']));
    cek('Q11 buat proyek oleh ' . $ket . ' ditolak', jumlah_uji() === $awal);
}
$h = minta('rbacPetugas', PI, $D, ['id' => (string) $pid1], form_dari_db(proyek_db('UJI3A Satu'), ['nama' => 'UJI3A Diubah Petugas']));
cek('Q11 ubah proyek oleh Petugas ditolak', proyek_db('UJI3A Satu') !== null && proyek_db('UJI3A Diubah Petugas') === null && str_contains($h['pesan'], 'tidak memiliki izin'));
$h = minta('rbacPetugas', PI, $D, ['id' => (string) $pid1], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) BAGAS]);
cek('Q11 tambah kru oleh Petugas ditolak', !in_array(BAGAS, kru_db($pid1), true) && str_contains($h['pesan'], 'tidak memiliki izin'));
$h = minta('nina', NINA, $D, ['id' => (string) $pid1], ['aksi' => 'lepas_anggota', 'id' => (string) $id_nina]);
cek('Q11 lepas kru oleh User/Kru (anggota proyek itu sendiri) ditolak', in_array(NINA, kru_db($pid1), true) && str_contains($h['pesan'], 'tidak memiliki izin'));

// Q12 CSRF
$awal = jumlah_uji();
$h = minta('admin', SA, $L, [], form_proyek(['nama' => 'UJI3A CSRF']), false);
cek('Q12a POST tanpa token CSRF ditolak', jumlah_uji() === $awal && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) BAGAS], 'token-salah');
cek('Q12b POST dengan token CSRF salah ditolak', !in_array(BAGAS, kru_db($pid1), true) && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);

// Q13 hapus proyek ditunda
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'hapus_proyek', 'id' => (string) $pid1]);
cek('Q13 hapus proyek ditolak, proyek tetap ada, disarankan status Dibatalkan', proyek_db('UJI3A Satu') !== null && str_contains($h['pesan'], 'Dibatalkan'), $h['pesan']);

// Q14 rollback: pencatatan riwayat gagal (FOREIGN KEY) -> perubahan proyek/tim ikut dibatalkan
$awal = jumlah_uji();
$r = anak(['--fungsi', 'rollback_buat']);
cek('Q14a buat proyek: riwayat gagal -> tidak ada proyek/persiapan yang tersimpan', str_starts_with($r, 'GAGAL_PDO:23503') && jumlah_uji() === $awal
    && (int) satu("SELECT COUNT(*) FROM persiapan_proyek s LEFT JOIN proyek p ON p.id = s.proyek_id WHERE p.id IS NULL") === 0, $r);
$sebelum = proyek_db('UJI3A Satu');
$kru_sebelum = kru_db($pid1);
$r = anak(['--fungsi', 'rollback_ubah', (string) $pid1]);
cek('Q14b ubah proyek + ganti PIC: riwayat gagal -> proyek dan kru tidak berubah', str_starts_with($r, 'GAGAL_PDO:23503') && proyek_db('UJI3A Satu') == $sebelum && kru_db($pid1) === $kru_sebelum, $r);
$r = anak(['--fungsi', 'rollback_tambah', (string) $pid1, (string) BAGAS]);
cek('Q14c tambah kru: riwayat gagal -> kru tidak bertambah', str_starts_with($r, 'GAGAL_PDO:23503') && kru_db($pid1) === $kru_sebelum, $r);
// Nina masih membawa barang Milik Kru (Q09), jadi sejak Sprint 3C lepas_kru() menolaknya di dalam transaksi sebelum riwayat
// dicatat. Uji rollback memakai kru tambahan tanpa barang bawaan, lalu baris itu dihapus lagi.
jalankan('INSERT INTO anggota_proyek (proyek_id, pengguna_id) VALUES (:p, :u)', ['p' => $pid1, 'u' => BAGAS]);
$id_bagas = (int) satu('SELECT id FROM anggota_proyek WHERE proyek_id = :p AND pengguna_id = :u', ['p' => $pid1, 'u' => BAGAS]);
$kru_q14 = kru_db($pid1);
$r = anak(['--fungsi', 'rollback_lepas', (string) $pid1, (string) $id_bagas]);
cek('Q14d lepas kru: riwayat gagal -> kru tidak berkurang', str_starts_with($r, 'GAGAL_PDO:23503') && kru_db($pid1) === $kru_q14, $r);
jalankan('DELETE FROM anggota_proyek WHERE id = :i', ['i' => $id_bagas]);

echo "\n== D. Deadline persiapan dan riwayat\n";
// Q15 konfirmasi persiapan (PostgreSQL sejak 4B), lalu deadline diubah lebih awal
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'simpan_persiapan', 'diperiksa' => [(string) $alokasi_nina]]);
$h = minta('admin', SA, $D, ['id' => (string) $pid1], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => (string) $pid1, 'konfirmasi' => '1']);
// Sejak Sprint 4B konfirmasi dan lognya di PostgreSQL; batas persiapan saat konfirmasi tercatat di keterangan log.
$konf = (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Persiapan' AND objek = :o AND baru = 'Dikonfirmasi Selesai'
                    AND keterangan LIKE '%batas persiapan 2026-11-20 20:00'", ['o' => $p1['kode']]);
cek('Q15a persiapan proyek PostgreSQL dikonfirmasi; log konfirmasi mencatat batas persiapan saat itu',
    str_contains($h['pesan'], 'dikonfirmasi selesai') && $konf === 1
    && satu('SELECT dikonfirmasi_pada FROM persiapan_proyek WHERE proyek_id = :p', ['p' => $pid1]) !== null, $h['pesan']);
$h = minta('admin', SA, $D, ['id' => (string) $pid1], form_dari_db(proyek_db('UJI3A Satu'), ['deadline_persiapan' => '2026-11-01T20:00']));
cek('Q15b deadline diubah lebih awal + riwayat "Deadline persiapan diubah" (lama -> baru) di PostgreSQL',
    proyek_db('UJI3A Satu')['deadline_persiapan'] === '2026-11-01 20:00'
    && (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Proyek' AND objek LIKE :o AND keterangan = 'Deadline persiapan diubah' AND lama = '2026-11-20 20:00' AND baru = '2026-11-01 20:00'", ['o' => $p1['kode'] . ' %']) === 1, $h['pesan']);
$h = minta('admin', SA, 'pages/riwayat.php');
$pos = strpos($h['html'], 'Persiapan Dikonfirmasi Selesai · ' . $p1['kode']);
$baris = $pos === false ? '' : substr($h['html'], $pos, (strpos($h['html'], '</li>', $pos) ?: $pos + 1500) - $pos);
cek('Q15c Riwayat: konfirmasi tetap "Tepat Waktu" terhadap deadline saat konfirmasi, dengan catatan deadline diubah',
    bersih($h) && str_contains($baris, 'Tepat Waktu') && !str_contains($baris, 'Terlambat') && str_contains($baris, 'Deadline diubah setelah konfirmasi'), strip_tags(substr($baris, 0, 400)));
$r = json_decode(anak(['--fungsi', 'deadline_lama', (string) $pid1]), true);
cek('Q15d deadline saat konfirmasi dihitung dari urutan id log PostgreSQL (nilai lama perubahan deadline sesudahnya)',
    ($r['deadline'] ?? '') === '2026-11-20 20:00' && ($r['berubah'] ?? false) === true, json_encode($r));
$h = minta('admin', SA, $D, ['id' => (string) $pid1, 'tab' => 'persiapan']);
cek('Q15e perubahan deadline tidak mencabut konfirmasi persiapan', bersih($h) && str_contains($h['html'], 'Persiapan Dikonfirmasi Selesai'));

// Q16 status "lewat deadline" memakai deadline terbaru (proyek uji kedua, kebutuhan belum teralokasi)
minta('admin', SA, $L, [], form_proyek(['nama' => 'UJI3A Dua', 'pic_id' => (string) BAGAS]));
$p2 = proyek_db('UJI3A Dua');
minta('admin', SA, $D, ['id' => (string) $p2['id']], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'Kamera utama', 'acuan' => '', 'kategori_id' => '1', 'jumlah' => '1']);
$h = minta('admin', SA, $D, ['id' => (string) $p2['id']]);
$sebelum = bersih($h) && !str_contains($h['html'], 'Lewat deadline');
minta('admin', SA, $D, ['id' => (string) $p2['id']], form_dari_db($p2, ['deadline_persiapan' => '2026-11-01T20:00']));
$h = minta('admin', SA, $D, ['id' => (string) $p2['id']]);
cek('Q16 status "Lewat deadline" mengikuti deadline terbaru (sebelum: tidak, sesudah: ya)', $sebelum && bersih($h) && str_contains($h['html'], 'Lewat deadline'));

echo "\n== E. Kompatibilitas modul Session dan halaman\n";
// Q17 modul Session untuk proyek contoh dan proyek PostgreSQL baru
$masalah = [];
foreach ([3, 4, 7, $pid1] as $pid) {
    foreach (['ringkasan', 'kebutuhan', 'persiapan', 'serah'] as $tab) {
        $h = minta('admin', SA, $D, ['id' => (string) $pid, 'tab' => $tab]);
        if (!bersih($h) || $h['html'] === '') {
            $masalah[] = $pid . '/' . $tab . ' ' . substr(strip_tags($h['mentah']), 0, 200);
        }
    }
}
cek('Q17a tab Ringkasan, Kebutuhan, Persiapan, Serah Terima tampil untuk proyek contoh dan proyek baru', !$masalah, implode(' || ', $masalah));
$h = minta('admin', SA, $D, ['id' => '4', 'tab' => 'kebutuhan']);
cek('Q17b kebutuhan & alokasi session proyek contoh PRJ-022 tetap terbaca', bersih($h) && str_contains($h['html'], 'CAM-003'));
$h = minta('admin', SA, 'pages/serah-terima.php');
cek('Q17c Serah Terima menampilkan kode proyek dari PostgreSQL', bersih($h) && str_contains($h['html'], 'PRJ-02'));

// Q18 semua halaman tiga role
$halaman_role = [
    SA => ['pages/dashboard-admin.php' => [], $L => [], 'pages/preset.php' => [], 'pages/serah-terima.php' => [], 'pages/riwayat.php' => [],
        'pages/pengguna.php' => [], 'pages/perlengkapan.php' => [], 'pages/perlengkapan.php#e' => ['tab' => 'eksternal'], 'pages/perlengkapan.php#i' => ['tab' => 'insiden']],
    PI => ['pages/dashboard-petugas.php' => [], $L => [], 'pages/preset.php' => [], 'pages/serah-terima.php' => [], 'pages/riwayat.php' => [], 'pages/perlengkapan.php' => []],
    RIZKY => ['pages/dashboard-user.php' => [], $L => [], $D . '#3' => ['id' => '3'], $D . '#4' => ['id' => '4', 'tab' => 'kebutuhan']],
];
foreach ($halaman_role as $uid => $daftar) {
    $masalah = [];
    foreach ($daftar as $hal => $get) {
        $h = minta('regresi' . $uid, $uid, explode('#', $hal)[0], $get);
        if (!bersih($h) || $h['html'] === '' || str_contains($h['pesan'], 'tidak termasuk hak akses') || str_contains($h['pesan'], 'tidak ditugaskan')) {
            $masalah[] = $hal . ' ' . $h['pesan'] . ' ' . substr(strip_tags($h['mentah']), 0, 200);
        }
    }
    cek('Q18 ' . count($daftar) . ' halaman role ' . ['1' => 'sa', '2' => 'pi', '3' => 'us'][$uid] . ' tampil tanpa galat PHP/DB', !$masalah, implode(' || ', $masalah));
}

// Q19 hitungan proyek per pengguna sama di semua browser dan sesuai PostgreSQL
$harap = (int) satu('SELECT COUNT(*) FROM proyek p WHERE p.pic_id = 3 OR EXISTS (SELECT 1 FROM anggota_proyek a WHERE a.proyek_id = p.id AND a.pengguna_id = 3)');
$angka = [];
foreach (['admin', 'adminLain'] as $sid) {
    $h = minta($sid, SA, 'pages/pengguna.php');
    $pos = strpos($h['html'], 'rizky.p');
    $angka[] = $pos !== false && preg_match('/data-label="Proyek" class="angka">(\d+)</', $h['html'], $m, 0, $pos) ? (int) $m[1] : -1;
}
$fungsi_id = json_decode(anak(['--fungsi', 'id_proyek', '3']), true) ?: [];
cek('Q19 kolom Proyek di Manajemen Pengguna (PIC + kru) sama di dua browser dan = PostgreSQL', $angka === [$harap, $harap] && count($fungsi_id) === $harap, json_encode($angka) . ' harap ' . $harap);

// Q20 session lama (VERSI_DATA 3) yang masih berisi proyek buatan session
anak(['--sesi-lama', 'sesiLama']);
$h = minta('sesiLama', SA, $L);
$s = json_decode(anak(['--periksa-sesi', 'sesiLama', '11']), true);
cek('Q20a session lama di-seed ulang (versi terbaru) tanpa salinan proyek/anggota', ($s['versi'] ?? 0) >= 4 && !$s['ada_proyek'] && !$s['ada_anggota'] && bersih($h));
cek('Q20b kebutuhan session yang merujuk proyek buatan session (#11) ikut dibuang', ($s['kebutuhan_proyek_11'] ?? -1) === 0 && !str_contains($h['html'], 'Proyek Buatan Session'));

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
