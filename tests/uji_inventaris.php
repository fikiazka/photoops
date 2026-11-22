<?php
// Uji Sprint Backend 2: Inventaris Studio di PostgreSQL + kompatibilitas modul PHP Session.
// HANYA untuk database uji (nama berakhiran _test), karena uji ini menambah dan mengubah data:
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_inventaris.php
// Database uji dibuat dari 001_schema.sql + 002_seed_demo.sql + 003_hak_akses.sql (data contoh masih asli).
//
// Cara kerja: setiap "request" dijalankan sebagai proses PHP terpisah (mode --permintaan) yang memuat halaman asli
// (pages/*.php) dengan $_SESSION, $_GET, $_POST tertentu. Session disimpan di folder sementara, sehingga beberapa
// request bisa memakai session yang sama (seperti satu browser) atau session berbeda (seperti browser lain).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_uji_sesi');

// ---------------------------------------------------------------------------------------------
// Mode anak: menjalankan satu request halaman, lalu mencetak hasilnya sebagai JSON.
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
    // Didaftarkan di dalam shutdown agar berjalan PALING AKHIR (sesudah sinkron persiapan di bootstrap).
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

// Mode anak: membuat session "lama" (VERSI_DATA 2, sebelum migrasi) yang masih menyimpan unit buatan session.
if (($argv[1] ?? '') === '--sesi-lama') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $_SESSION = ['db_versi' => 2, 'db' => [
        'kategori' => [1 => ['id' => 1, 'nama' => 'Kamera', 'kode_awalan' => 'CAM', 'deskripsi' => '']],
        'perlengkapan' => [
            1 => ['id' => 1, 'kode_unit' => 'CAM-001', 'nama' => 'Sony A7 III', 'merek' => 'Sony', 'kategori_id' => 1, 'kondisi' => 'Rusak', 'status_aktif' => 'Aktif', 'catatan' => 'versi session'],
            24 => ['id' => 24, 'kode_unit' => 'CAM-006', 'nama' => 'Unit Buatan Session', 'merek' => 'X', 'kategori_id' => 1, 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => ''],
        ],
        'alokasi' => [99 => ['id' => 99, 'proyek_id' => 4, 'kebutuhan_id' => 12, 'sumber' => 'Studio', 'perlengkapan_id' => 24, 'nama_barang' => '', 'vendor' => '', 'pemilik_id' => null,
            'tgl_mulai' => '2026-11-11', 'tgl_selesai' => '2026-11-12', 'status' => 'Dikonfirmasi', 'catatan' => '']],
    ]];
    session_write_close();
    exit;
}

// Mode anak: membaca isi session apa adanya (tanpa bootstrap) untuk diperiksa.
if (($argv[1] ?? '') === '--periksa-sesi') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $db = $_SESSION['db'] ?? [];
    $kebutuhan = $db['kebutuhan'] ?? [];
    $studio = [];
    foreach ($db['alokasi'] ?? [] as $a) {
        if ($a['sumber'] === 'Studio') {
            $studio[] = ['alokasi' => (int) $a['id'], 'unit' => (int) $a['perlengkapan_id'], 'kategori_kebutuhan' => (int) ($kebutuhan[$a['kebutuhan_id']]['kategori_id'] ?? 0)];
        }
    }
    echo json_encode([
        'versi' => $_SESSION['db_versi'] ?? null,
        'ada_perlengkapan' => isset($db['perlengkapan']),
        'ada_kategori' => isset($db['kategori']),
        'alokasi_ids' => array_keys($db['alokasi'] ?? []),
        'studio' => $studio,
        'insiden_unit' => array_values(array_map(fn ($i) => (int) $i['perlengkapan_id'], $db['insiden'] ?? [])),
    ]);
    exit;
}

// Mode anak: memanggil fungsi PHP langsung (uji rollback dan uji bersamaan).
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
    if ($jenis === 'tambah_banyak') {
        $_SESSION['user_id'] = 2;
        $kode = [];
        for ($i = 0; $i < (int) $argv[3]; $i++) {
            $kode[] = tambah_unit(5, ['nama' => 'SD Card Uji Bersamaan', 'merek' => 'SanDisk', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '']);
        }
        echo json_encode($kode);
    } elseif ($jenis === 'rollback_tambah' || $jenis === 'rollback_ubah') {
        // pengguna_id 99999 tidak ada: INSERT riwayat_aktivitas melanggar FOREIGN KEY -> transaksi harus ROLLBACK.
        $_SESSION['user_id'] = 99999;
        try {
            if ($jenis === 'rollback_tambah') {
                tambah_unit(1, ['nama' => 'Kamera Rollback', 'merek' => 'Uji', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '']);
            } else {
                ubah_unit(1, ['nama' => 'NAMA ROLLBACK', 'merek' => 'Uji', 'kondisi' => 'Rusak', 'status_aktif' => 'Nonaktif', 'catatan' => '']);
            }
            echo 'TIDAK_GAGAL';
        } catch (PDOException $e) {
            echo 'GAGAL_PDO:' . $e->getCode();
        }
    } elseif ($jenis === 'login') {
        $akun = cari_akun_login('budi.santoso');
        echo json_encode(['ada' => (bool) $akun, 'cocok' => $akun && password_verify('nicio123', $akun['password_hash']), 'peran' => $akun['peran'] ?? '']);
    } elseif ($jenis === 'pengembalian_gagal_tanpa' || $jenis === 'pengembalian_gagal_dengan') {
        // Simulasi database gagal DI TENGAH pengembalian: barang pertama (alokasi #25, LGT-002) kembali Rusak,
        // barang kedua (alokasi #26, LGT-003) memakai kondisi 'Hilang' sehingga UPDATE kedua ditolak CHECK constraint.
        // Alokasi #25 dan #26 (PRJ-025) masih tercatat keluar di data contoh PostgreSQL (TRX-043).
        // Sejak Sprint 4A serah terima ada di PostgreSQL dan pengembalian selalu lewat catat_pengembalian() (transaksi +
        // pemulihan session), jadi kedua varian memakai jalur itu.
        $_SESSION['user_id'] = 2;
        $trx_awal = count(semua_transaksi());
        $insiden_awal = count(db('insiden'));
        $proyek = cari_proyek(7);
        $item = [
            ['alokasi' => cari_alokasi(25), 'per_kondisi' => ['Baik' => 0, 'Maintenance' => 0, 'Rusak' => 1]],
            ['alokasi' => cari_alokasi(26), 'per_kondisi' => ['Baik' => 0, 'Maintenance' => 0, 'Rusak' => 0, 'Hilang' => 1]],
        ];
        $galat = '';
        try {
            catat_pengembalian($proyek, $item, null, '2026-11-07 00:20', 'Simulasi gagal di tengah');
        } catch (PDOException $e) {
            $galat = $e->getCode();
        }
        echo json_encode(['galat' => $galat, 'trx_bertambah' => count(semua_transaksi(true)) - $trx_awal,
            'insiden_bertambah' => count(db('insiden')) - $insiden_awal, 'tanda_berubah' => inventaris_berubah()]);
    } elseif ($jenis === 'riwayat') {
        $per_aksi = [];
        foreach (daftar_riwayat() as $r) {
            $per_aksi[$r['aksi']] = ($per_aksi[$r['aksi']] ?? 0) + 1;
        }
        echo json_encode($per_aksi);
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
if ((int) $pdo->query("SELECT COUNT(*) FROM perlengkapan")->fetchColumn() !== 23) {
    echo "[BERHENTI] Database uji harus berisi data contoh asli (23 unit). Buat ulang dari 001 + 002 + 003.\n";
    exit(1);
}
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

function satu(string $sql, array $p = [])
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

function proses_anak(array $arg): array
{
    $perintah = array_merge([PHP_BINARY, __FILE__], $arg);
    $env = getenv();
    return [proc_open($perintah, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa, AKAR_UJI, $env), $pipa];
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

function fungsi(array $arg): string
{
    return fungsi_lepas(array_merge(['--fungsi'], $arg));
}

function fungsi_lepas(array $arg): string
{
    [$proses, $pipa] = proses_anak($arg);
    fclose($pipa[0]);
    $keluar = stream_get_contents($pipa[1]) . stream_get_contents($pipa[2]);
    proc_close($proses);
    return trim($keluar);
}

// Tanda galat PHP/DB di keluaran halaman.
function bersih(array $h): bool
{
    $teks = $h['html'] . $h['mentah'];
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|Parse error)\b|Layanan database PhotoOps sedang tidak tersedia/', $teks);
}

function unit_db(string $kode): ?array
{
    global $pdo;
    $s = $pdo->prepare('SELECT * FROM perlengkapan WHERE kode_unit = :k');
    $s->execute(['k' => $kode]);
    return $s->fetch() ?: null;
}

const SA = 1, PI = 2, US = 3;
$P = 'pages/perlengkapan.php';

echo "Database uji: {$k['dbname']}\n\n== A. Inventaris Studio\n";

// A1 daftar unit dari PostgreSQL
$h = minta('browserA', PI, $P);
cek('A01 halaman Perlengkapan tampil tanpa galat (Petugas)', bersih($h) && str_contains($h['html'], 'CAM-001') && str_contains($h['html'], 'MEM-004'));
cek('A02 KPI total unit dan kategori dibaca dari PostgreSQL', (bool) preg_match('/Total unit<\/p><p class="kpi-angka">23<\/p><p class="kpi-ket">5 kategori/', $h['html']));
$sebelum_riwayat = (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Perlengkapan'");

// A2 tambah unit
$h = minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => '', 'kategori_id' => '1', 'nama' => 'Sony A7 IV', 'merek' => 'Sony', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => 'Unit uji']);
$baru = unit_db('CAM-006');
cek('A03 tambah unit tersimpan di PostgreSQL dengan kode CAM-006', $baru && $baru['nama'] === 'Sony A7 IV' && (int) $baru['kategori_id'] === 1, $h['pesan']);
cek('A04 nomor_terakhir kategori Kamera naik menjadi 6', (int) satu('SELECT nomor_terakhir FROM kategori_perlengkapan WHERE id = 1') === 6);
cek('A05 riwayat "Unit baru ditambahkan" tercatat di PostgreSQL oleh Petugas',
    (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Perlengkapan' AND objek = 'CAM-006 · Sony A7 IV' AND keterangan = 'Unit baru ditambahkan ke inventaris' AND pengguna_id = 2") === 1);
$id_baru = (int) $baru['id'];

// A3 edit data unit; kategori yang dikirim diabaikan (kategori dan kode tetap)
$h = minta('browserA', SA, $P, [], ['aksi' => 'simpan_unit', 'id' => (string) $id_baru, 'kategori_id' => '2', 'nama' => 'Sony A7 IV Body', 'merek' => 'Sony Japan', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => 'Catatan diubah']);
$u = unit_db('CAM-006');
cek('A06 ubah nama, merek, catatan tersimpan (Super Admin)', $u['nama'] === 'Sony A7 IV Body' && $u['merek'] === 'Sony Japan' && $u['catatan'] === 'Catatan diubah', $h['pesan']);
cek('A07 kategori dan kode unit tidak berubah saat edit', (int) $u['kategori_id'] === 1 && $u['kode_unit'] === 'CAM-006');

// A4 ubah kondisi
minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => (string) $id_baru, 'nama' => 'Sony A7 IV Body', 'merek' => 'Sony Japan', 'kondisi' => 'Maintenance', 'status_aktif' => 'Aktif', 'catatan' => '']);
cek('A08 ubah kondisi menjadi Maintenance', unit_db('CAM-006')['kondisi'] === 'Maintenance');
cek('A09 riwayat "Kondisi unit diubah" Baik -> Maintenance',
    (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Perlengkapan' AND keterangan = 'Kondisi unit diubah' AND lama = 'Baik' AND baru = 'Maintenance'") === 1);

// A5 nonaktif / aktif
minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => (string) $id_baru, 'nama' => 'Sony A7 IV Body', 'merek' => 'Sony Japan', 'kondisi' => 'Maintenance', 'status_aktif' => 'Nonaktif', 'catatan' => '']);
$u1 = unit_db('CAM-006')['status_aktif'];
minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => (string) $id_baru, 'nama' => 'Sony A7 IV Body', 'merek' => 'Sony Japan', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '']);
cek('A10 unit bisa dinonaktifkan lalu diaktifkan kembali', $u1 === 'Nonaktif' && unit_db('CAM-006')['status_aktif'] === 'Aktif');
cek('A11 riwayat "Status unit diubah" Aktif -> Nonaktif',
    (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Perlengkapan' AND keterangan = 'Status unit diubah' AND lama = 'Aktif' AND baru = 'Nonaktif'") === 1);

// A6 kode unik & berurutan per kategori
minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => '', 'kategori_id' => '3', 'nama' => 'Godox SL60W', 'merek' => 'Godox', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '']);
minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => '', 'kategori_id' => '3', 'nama' => 'Godox SL60W', 'merek' => 'Godox', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '']);
cek('A12 dua unit Pencahayaan mendapat kode LGT-004 dan LGT-005', unit_db('LGT-004') && unit_db('LGT-005'));
cek('A13 tidak ada kode_unit ganda', (int) satu('SELECT COUNT(*) - COUNT(DISTINCT kode_unit) FROM perlengkapan') === 0);

// A7 validasi
$jumlah_awal = (int) satu('SELECT COUNT(*) FROM perlengkapan');
$nomor_awal = satu('SELECT string_agg(nomor_terakhir::text, \',\' ORDER BY id) FROM kategori_perlengkapan');
$tidak_valid = [
    'kategori tidak ada (99)' => ['kategori_id' => '99'],
    'kategori bukan angka' => ['kategori_id' => '1 OR 1=1'],
    'nama 1 karakter' => ['nama' => 'X'],
    'nama 61 karakter' => ['nama' => str_repeat('a', 61)],
    'merek kosong' => ['merek' => ''],
    'merek 31 karakter' => ['merek' => str_repeat('m', 31)],
    'kondisi Hilang' => ['kondisi' => 'Hilang'],
    'catatan 121 karakter' => ['catatan' => str_repeat('c', 121)],
];
foreach ($tidak_valid as $ket => $ubah) {
    $h = minta('browserA', PI, $P, [], array_merge(['aksi' => 'simpan_unit', 'id' => '', 'kategori_id' => '1', 'nama' => 'Valid', 'merek' => 'Valid', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => ''], $ubah));
    cek('A14 ditolak: ' . $ket, !empty($h['form']['galat']) && (int) satu('SELECT COUNT(*) FROM perlengkapan') === $jumlah_awal, implode(' ', $h['form']['galat'] ?? []));
}
cek('A15 data tidak valid tidak menaikkan nomor_terakhir', satu('SELECT string_agg(nomor_terakhir::text, \',\' ORDER BY id) FROM kategori_perlengkapan') === $nomor_awal);
$h = minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => '999999', 'nama' => 'Hantu', 'merek' => 'X', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '']);
cek('A16 edit unit yang tidak ada ditolak (tidak membuat unit baru)', str_contains($h['pesan'], 'Unit tidak ditemukan') && (int) satu('SELECT COUNT(*) FROM perlengkapan') === $jumlah_awal);

// A8 lintas session / browser
$h = minta('browserB', SA, $P);
cek('A17 unit baru terlihat dari browser lain (session berbeda)', str_contains($h['html'], 'CAM-006') && str_contains($h['html'], 'LGT-005'));
$h = minta('browserA', null, 'logout.php', [], []);
$h = minta('browserA', PI, $P);
cek('A18 data tetap ada setelah logout lalu login ulang', str_contains($h['html'], 'Sony A7 IV Body'));

// Hapus unit: kebijakan sementara (ditolak)
$h = minta('browserA', PI, $P, [], ['aksi' => 'hapus_unit', 'id' => (string) $id_baru]);
cek('A19 hapus unit ditolak, unit tetap ada, sarankan Nonaktif', unit_db('CAM-006') !== null && str_contains($h['pesan'], 'Nonaktif'), $h['pesan']);

// A10 hak akses
$h = minta('browserU', US, $P);
cek('A20 User/Kru tidak bisa membuka halaman Perlengkapan', str_contains($h['pesan'], 'tidak termasuk hak akses') && !str_contains($h['html'], 'CAM-001'));
$jumlah = (int) satu('SELECT COUNT(*) FROM perlengkapan');
$h = minta('browserU', US, $P, [], ['aksi' => 'simpan_unit', 'id' => '', 'kategori_id' => '1', 'nama' => 'Ilegal', 'merek' => 'X', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '']);
cek('A21 POST tambah unit oleh User/Kru ditolak', (int) satu('SELECT COUNT(*) FROM perlengkapan') === $jumlah);
$h = minta('browserU', US, $P, [], ['aksi' => 'simpan_unit', 'id' => '1', 'nama' => 'Ilegal', 'merek' => 'X', 'kondisi' => 'Rusak', 'status_aktif' => 'Nonaktif', 'catatan' => '']);
cek('A22 POST ubah unit oleh User/Kru ditolak', unit_db('CAM-001')['nama'] === 'Sony A7 III' && unit_db('CAM-001')['kondisi'] === 'Baik');
$h = minta('browserX', null, $P, [], ['aksi' => 'simpan_unit', 'id' => '', 'kategori_id' => '1', 'nama' => 'Anonim', 'merek' => 'X', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '']);
cek('A23 POST tanpa login ditolak', (int) satu('SELECT COUNT(*) FROM perlengkapan') === $jumlah && str_contains($h['pesan'], 'masuk'));

echo "\n== C. Keamanan\n";
$h = minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => '', 'kategori_id' => '1', 'nama' => 'Tanpa Token', 'merek' => 'X', 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => ''], false);
cek('C01 POST tanpa token CSRF ditolak', str_contains($h['pesan'], 'kedaluwarsa') && (int) satu('SELECT COUNT(*) FROM perlengkapan') === $jumlah);
$h = minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => '1', 'nama' => 'Token Salah', 'merek' => 'X', 'kondisi' => 'Rusak', 'status_aktif' => 'Aktif', 'catatan' => ''], 'token-palsu');
cek('C02 POST dengan token CSRF salah ditolak', str_contains($h['pesan'], 'kedaluwarsa') && unit_db('CAM-001')['nama'] === 'Sony A7 III');
$jahat = "Lensa'); DROP TABLE perlengkapan; --";
minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => '', 'kategori_id' => '2', 'nama' => $jahat, 'merek' => "O'Brien", 'kondisi' => 'Baik', 'status_aktif' => 'Aktif', 'catatan' => '<script>alert(1)</script>']);
cek('C03 input berisi SQL disimpan apa adanya (prepared statement), tabel tetap utuh', unit_db('LNS-006')['nama'] === $jahat && unit_db('LNS-006')['merek'] === "O'Brien");
$h = minta('browserA', PI, $P);
cek('C04 teks <script> ditampilkan ter-escape di halaman', !str_contains($h['html'], '<script>alert(1)</script>') && str_contains($h['html'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
$jumlah = (int) satu('SELECT COUNT(*) FROM perlengkapan');
$nomor_kamera = (int) satu('SELECT nomor_terakhir FROM kategori_perlengkapan WHERE id = 1');
$r = fungsi(['rollback_tambah']);
cek('C05 audit gagal (FOREIGN KEY) -> tambah unit dibatalkan', str_starts_with($r, 'GAGAL_PDO:23503') && (int) satu('SELECT COUNT(*) FROM perlengkapan') === $jumlah, $r);
cek('C06 rollback juga mengembalikan nomor_terakhir (nomor tidak terbuang)', (int) satu('SELECT nomor_terakhir FROM kategori_perlengkapan WHERE id = 1') === $nomor_kamera);
$r = fungsi(['rollback_ubah']);
cek('C07 audit gagal -> ubah unit dibatalkan', str_starts_with($r, 'GAGAL_PDO:23503') && unit_db('CAM-001')['nama'] === 'Sony A7 III' && unit_db('CAM-001')['kondisi'] === 'Baik', $r);

// Dua petugas menambah unit bersamaan: dua proses paralel, masing-masing 15 unit Penyimpanan.
$nomor_mem = (int) satu('SELECT nomor_terakhir FROM kategori_perlengkapan WHERE id = 5');
$pr = [proses_anak(['--fungsi', 'tambah_banyak', '15']), proses_anak(['--fungsi', 'tambah_banyak', '15'])];
$kode = [];
foreach ($pr as [$proses, $pipa]) {
    fclose($pipa[0]);
    $keluar = stream_get_contents($pipa[1]) . stream_get_contents($pipa[2]);
    proc_close($proses);
    $kode = array_merge($kode, json_decode($keluar, true) ?: []);
}
cek('C08 30 unit dari dua proses bersamaan semuanya berhasil', count($kode) === 30 && !in_array(null, $kode, true), count($kode) . ' kode');
cek('C09 tidak ada kode duplikat dari penambahan bersamaan', count(array_unique($kode)) === 30 && (int) satu('SELECT COUNT(*) - COUNT(DISTINCT kode_unit) FROM perlengkapan') === 0);
cek('C10 nomor_terakhir Penyimpanan = nomor awal + 30', (int) satu('SELECT nomor_terakhir FROM kategori_perlengkapan WHERE id = 5') === $nomor_mem + 30);
cek('C11 kode unit selalu sesuai awalan kategorinya',
    (int) satu("SELECT COUNT(*) FROM perlengkapan p JOIN kategori_perlengkapan k ON k.id = p.kategori_id WHERE p.kode_unit NOT LIKE k.kode_awalan || '-%'") === 0);
cek('C12 tidak ada kode dengan nomor melebihi nomor_terakhir kategorinya',
    (int) satu("SELECT COUNT(*) FROM perlengkapan p JOIN kategori_perlengkapan k ON k.id = p.kategori_id WHERE CAST(SPLIT_PART(p.kode_unit, '-', 2) AS INTEGER) > k.nomor_terakhir") === 0);

echo "\n== B. Kompatibilitas modul Session\n";
$r = json_decode(fungsi(['login']), true);
cek('B01 login PostgreSQL tetap berjalan (cari_akun_login + password_verify)', ($r['cocok'] ?? false) && $r['peran'] === 'pi');
$h = minta('browserB', SA, 'pages/pengguna.php');
cek('B02 Manajemen Pengguna tetap tampil', bersih($h) && str_contains($h['html'], 'budi.santoso'));

// Regresi halaman GET untuk tiga role.
$halaman_role = [
    SA => ['pages/dashboard-admin.php' => [], 'pages/proyek.php' => [], 'pages/preset.php' => [], 'pages/serah-terima.php' => [], 'pages/riwayat.php' => [],
        'pages/perlengkapan.php' => ['tab' => 'eksternal'], 'pages/perlengkapan.php#insiden' => ['tab' => 'insiden']],
    PI => ['pages/dashboard-petugas.php' => [], 'pages/proyek.php' => [], 'pages/preset.php' => [], 'pages/serah-terima.php' => [], 'pages/riwayat.php' => []],
    US => ['pages/dashboard-user.php' => [], 'pages/proyek.php' => []],
];
foreach ([3, 4, 7] as $pid) {
    foreach (['ringkasan', 'kebutuhan', 'persiapan', 'serah', 'tim'] as $tab) {
        $halaman_role[SA]['pages/proyek-detail.php#' . $pid . $tab] = ['id' => (string) $pid, 'tab' => $tab];
        $halaman_role[PI]['pages/proyek-detail.php#' . $pid . $tab] = ['id' => (string) $pid, 'tab' => $tab];
    }
}
$halaman_role[US]['pages/proyek-detail.php#4'] = ['id' => '4', 'tab' => 'kebutuhan'];
$nama_role = [SA => 'sa', PI => 'pi', US => 'us'];
foreach ($halaman_role as $uid => $daftar) {
    $masalah = [];
    foreach ($daftar as $hal => $get) {
        $h = minta('regresi' . $uid, $uid, explode('#', $hal)[0], $get);
        if (!bersih($h) || $h['html'] === '' || str_contains($h['pesan'], 'tidak termasuk hak akses')) {
            $masalah[] = $hal . ' ' . json_encode($get) . ' ' . $h['pesan'] . ' ' . substr(strip_tags($h['mentah']), 0, 200);
        }
    }
    cek('B03 ' . count($daftar) . ' halaman role ' . $nama_role[$uid] . ' tampil tanpa galat PHP/DB', !$masalah, implode(' || ', $masalah));
}
foreach (['pages/serah-terima.php', 'pages/riwayat.php', 'pages/pengguna.php', 'pages/preset.php'] as $hal) {
    $h = minta('regresi' . US, US, $hal);
    cek('B04 User/Kru tetap ditolak di ' . $hal, str_contains($h['pesan'], 'tidak termasuk hak akses'));
}

// Kebutuhan & Alokasi: dialog alokasi hanya muncul untuk kebutuhan yang belum terpenuhi. Di data contoh semua kebutuhan
// Kamera/Aksesori sudah penuh, jadi dibuat dua kebutuhan baru di PRJ-023 (Draf) lewat modul Session.
minta('browserA', PI, 'pages/proyek-detail.php', ['id' => '5'], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'Kamera cadangan', 'acuan' => '', 'kategori_id' => '1', 'jumlah' => '1']);
minta('browserA', PI, 'pages/proyek-detail.php', ['id' => '5'], ['aksi' => 'simpan_kebutuhan', 'id' => '', 'nama' => 'Stand cadangan', 'acuan' => '', 'kategori_id' => '4', 'jumlah' => '1']);
$h = minta('browserA', PI, 'pages/proyek-detail.php', ['id' => '5', 'tab' => 'kebutuhan']);
// Isi satu dialog alokasi (dari id dialog sampai </dialog>) berdasarkan nama kebutuhannya.
$dialog = function (string $nama) use ($h): array {
    preg_match_all('/<dialog[^>]*id="dialog-alokasi-(\d+)".*?<\/dialog>/s', $h['html'], $m, PREG_SET_ORDER);
    foreach ($m as $d) {
        if (str_contains($d[0], 'Alokasi: ' . $nama)) {
            return ['id' => (int) $d[1], 'html' => $d[0]];
        }
    }
    return ['id' => 0, 'html' => ''];
};
$d_kamera = $dialog('Kamera cadangan');
$d_stand = $dialog('Stand cadangan');
cek('B05 unit baru CAM-006 (PostgreSQL) muncul dan bisa dipilih di alokasi kebutuhan Kamera',
    (bool) preg_match('/<option value="' . $id_baru . '">\s*CAM-006 · Sony A7 IV Body — Tersedia/u', $d_kamera['html']), 'dialog #' . $d_kamera['id']);
cek('B06 unit Rusak STD-001 tampil tetapi terkunci (disabled) di alokasi kebutuhan Aksesori',
    (bool) preg_match('/<option value="14" disabled>\s*STD-001 · Light Stand 2\.8m — Kondisi Rusak/u', $d_stand['html']), 'dialog #' . $d_stand['id']);
cek('B07 unit Rental/Milik Kru tidak masuk tabel perlengkapan',
    (int) satu("SELECT COUNT(*) FROM perlengkapan WHERE nama IN ('Sony FX3', 'Tripod Manfrotto', 'Gimbal DJI RS3', 'SD Card 128GB ×2')") === 0);

// Alokasi unit baru lewat modul Session.
$kb = $d_kamera['id'];
$h = minta('browserA', PI, 'pages/proyek-detail.php', ['id' => '5'], ['aksi' => 'tambah_alokasi', 'kebutuhan_id' => (string) $kb, 'sumber' => 'Studio', 'perlengkapan_id' => (string) $id_baru, 'status' => 'Dikonfirmasi', 'catatan' => '']);
cek('B08 unit baru dari PostgreSQL bisa dialokasikan oleh modul Session', str_contains($h['pesan'], 'dialokasikan'), $h['pesan']);

// Serah Terima: kembalikan CAM-003 (alokasi #8, PRJ-020) dalam kondisi Baik, lalu CAM-005 (alokasi #27, PRJ-025) Rusak.
$h = minta('browserA', PI, 'pages/serah-terima.php', [], ['aksi' => 'checkin', 'proyek_id' => '2', 'alokasi' => ['8'], 'saldo' => ['8' => '1'], 'kondisi' => ['8' => 'Baik'], 'waktu' => '2026-11-07T00:10', 'penyerah_id' => '', 'catatan' => '', 'konfirmasi' => '1']);
cek('B09 pengembalian Studio kondisi Baik tercatat (modul Session)', str_contains($h['pesan'], 'kembali') && empty($h['form']['galat']), $h['pesan'] . json_encode($h['form']['galat'] ?? []));
cek('B10 kondisi CAM-003 tetap Baik di PostgreSQL', unit_db('CAM-003')['kondisi'] === 'Baik');
$h = minta('browserA', PI, 'pages/serah-terima.php', [], ['aksi' => 'checkin', 'proyek_id' => '7', 'alokasi' => ['27'], 'saldo' => ['27' => '1'], 'kondisi' => ['27' => 'Rusak'], 'waktu' => '2026-11-07T00:10', 'penyerah_id' => '', 'catatan' => 'Layar retak saat sesi kedua', 'konfirmasi' => '1']);
cek('B11 pengembalian Rusak tercatat dan membuat insiden', str_contains($h['pesan'], 'INS-013'), $h['pesan'] . json_encode($h['form']['galat'] ?? []));
cek('B12 kondisi CAM-005 menjadi Rusak di PostgreSQL', unit_db('CAM-005')['kondisi'] === 'Rusak');
$h = minta('browserB', SA, $P);
cek('B13 browser lain melihat CAM-005 Rusak (data unit bersama)', (bool) preg_match('/<strong>CAM-005<\/strong>.*?badge-merah">Rusak/s', $h['html']));

// Insiden selesai -> kondisi kembali Baik di PostgreSQL.
$h = minta('browserA', PI, $P, ['tab' => 'insiden']);
preg_match('/&quot;aksi&quot;:&quot;selesaikan_insiden&quot;,&quot;id&quot;:(\d+),&quot;_judul&quot;:&quot;Selesaikan INS-013/', $h['html'], $m);
$h = minta('browserA', PI, $P, [], ['aksi' => 'selesaikan_insiden', 'id' => $m[1] ?? '0']);
cek('B14 insiden selesai mengembalikan kondisi CAM-005 menjadi Baik di PostgreSQL', unit_db('CAM-005')['kondisi'] === 'Baik', $h['pesan']);
// Lapor insiden manual dari halaman Perlengkapan.
$h = minta('browserA', PI, $P, [], ['aksi' => 'lapor_insiden', 'perlengkapan_id' => (string) unit_db('LGT-003')['id'], 'jenis' => 'Masalah Teknis', 'kondisi' => 'Maintenance', 'proyek_id' => '', 'deskripsi' => 'Lampu berkedip']);
cek('B15 lapor insiden manual mengubah kondisi LGT-003 di PostgreSQL', unit_db('LGT-003')['kondisi'] === 'Maintenance', $h['pesan']);

// Persiapan: konfirmasi PRJ-022 (semua unit Studio), lalu ubah kondisi unit di Inventaris -> konfirmasi dicabut otomatis.
$h = minta('browserA', PI, 'pages/proyek-detail.php', ['id' => '4'], ['aksi' => 'simpan_persiapan', 'diperiksa' => ['20', '21', '22', '23', '24']]);
cek('B16 checklist persiapan PRJ-022 lengkap dan boleh dikonfirmasi', str_contains($h['pesan'], 'seluruh syarat terpenuhi'), $h['pesan']);
$h = minta('browserA', PI, 'pages/proyek-detail.php', ['id' => '4'], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => '4', 'konfirmasi' => '1']);
cek('B17 persiapan PRJ-022 dikonfirmasi selesai', str_contains($h['pesan'], 'dikonfirmasi selesai'), $h['pesan']);
$h = minta('browserA', PI, $P, [], ['aksi' => 'simpan_unit', 'id' => '8', 'nama' => 'Sony FE 85mm f/1.8', 'merek' => 'Sony', 'kondisi' => 'Maintenance', 'status_aktif' => 'Aktif', 'catatan' => 'Fokus macet']);
cek('B18 ubah kondisi LNS-003 di Inventaris mencabut konfirmasi persiapan PRJ-022', str_contains($h['pesan'], 'Konfirmasi persiapan PRJ-022 tidak berlaku lagi'), $h['pesan']);
$h = minta('browserA', PI, 'pages/proyek-detail.php', ['id' => '4', 'tab' => 'serah']);
cek('B19 halaman proyek tetap tampil setelah konfirmasi dicabut', bersih($h));

echo "\n== D. Riwayat Aktivitas\n";
$r = json_decode(fungsi(['riwayat']), true);
$db_perlengkapan = (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Perlengkapan'");
cek('D01 riwayat Perlengkapan di halaman Riwayat = jumlah baris di PostgreSQL (tidak ganda)', ($r['Perlengkapan'] ?? 0) === $db_perlengkapan, ($r['Perlengkapan'] ?? 0) . ' vs ' . $db_perlengkapan);
// Log session (seed) berisi 5 "Barang Keluar"; tabel riwayat_aktivitas berisi 6 (5 yang sama + 1 data dummy).
// Bila baris database ikut digabung, jumlahnya menjadi 11. Yang benar: hanya 5 dari session.
cek('D02 riwayat contoh (Barang Keluar dll.) dari PostgreSQL tidak ikut tampil ganda', ($r['Barang Keluar'] ?? 0) === 5, json_encode($r));
$h = minta('browserA', PI, 'pages/riwayat.php');
cek('D03 halaman Riwayat menampilkan aktivitas Perlengkapan', bersih($h) && str_contains($h['html'], 'Kondisi unit diubah'));
$sesi_perlengkapan = 0;
foreach (glob(FOLDER_SESI . '/sess_*') as $f) {
    $sesi_perlengkapan += substr_count(file_get_contents($f), 's:4:"aksi";s:12:"Perlengkapan"');
}
cek('D04 tidak ada riwayat Perlengkapan yang ditulis ke session', $sesi_perlengkapan === 0);
cek('D05 tidak ada salinan kategori/perlengkapan di session', !array_filter(glob(FOLDER_SESI . '/sess_*'), fn ($f) => str_contains(file_get_contents($f), 's:12:"perlengkapan";a:') || str_contains(file_get_contents($f), 's:8:"kategori";a:')));

echo "\n== E. Konsistensi Session + PostgreSQL\n";
function json_akhir(string $keluar): array
{
    $pos = strrpos($keluar, '{"galat"');
    return $pos === false ? [] : (json_decode(substr($keluar, $pos), true) ?: []);
}
$kondisi_lgt002 = unit_db('LGT-002')['kondisi'];
// E01 lama ("bukti risiko" tanpa pelindung) tidak dijalankan lagi: sejak Sprint 4A pengembalian tersimpan di PostgreSQL,
// sehingga varian tanpa transaksi akan menulis transaksi permanen ke data contoh. Jalur aplikasi diuji di E02–E05.
$r = json_akhir(fungsi(['pengembalian_gagal_dengan']));
cek('E02 catat_pengembalian(): error database di tengah pengembalian tetap dilaporkan', ($r['galat'] ?? '') === '23514', json_encode($r));
cek('E03 kondisi unit pertama di PostgreSQL di-ROLLBACK (tetap ' . $kondisi_lgt002 . ')', unit_db('LGT-002')['kondisi'] === $kondisi_lgt002);
cek('E04 session dikembalikan: tidak ada transaksi atau insiden setengah jadi', ($r['trx_bertambah'] ?? -1) === 0 && ($r['insiden_bertambah'] ?? -1) === 0);
cek('E05 tanda inventaris_berubah dihapus sehingga sinkron persiapan tidak berjalan dari data yang dibatalkan', ($r['tanda_berubah'] ?? true) === false);

// VERSI_DATA: session lama (versi 2) berisi unit buatan session #24 dan alokasi #99 yang merujuknya.
fungsi_lepas(['--sesi-lama', 'sesiLama']);
$h = minta('sesiLama', PI, 'pages/proyek-detail.php', ['id' => '4', 'tab' => 'kebutuhan']);
$s = json_decode(fungsi_lepas(['--periksa-sesi', 'sesiLama']), true);
cek('E06 session lama (VERSI_DATA 2) diganti seed baru (versi terbaru)', ($s['versi'] ?? 0) >= 3 && bersih($h));
cek('E07 salinan kategori/perlengkapan session lama dibuang', !$s['ada_perlengkapan'] && !$s['ada_kategori']);
cek('E08 alokasi session lama yang merujuk unit buatan session (#99 -> unit 24) ikut hilang', !in_array(99, $s['alokasi_ids'], true));
cek('E09 kondisi unit dari session lama (CAM-001 Rusak) tidak menimpa PostgreSQL', unit_db('CAM-001')['kondisi'] === 'Baik' && !str_contains($h['html'], 'versi session'));
$tidak_ada = [];
$beda_kategori = [];
foreach ($s['studio'] as $baris) {
    $kat = satu('SELECT kategori_id FROM perlengkapan WHERE id = :id', ['id' => $baris['unit']]);
    if ($kat === false) {
        $tidak_ada[] = $baris['alokasi'] . '->' . $baris['unit'];
    } elseif ((int) $kat !== $baris['kategori_kebutuhan']) {
        $beda_kategori[] = $baris['alokasi'] . '->' . $baris['unit'];
    }
}
cek('E10 setiap alokasi Studio di seed session (' . count($s['studio']) . ') merujuk unit yang ada di PostgreSQL', !$tidak_ada, implode(', ', $tidak_ada));
cek('E11 kategori unit PostgreSQL cocok dengan kategori kebutuhan di session', !$beda_kategori, implode(', ', $beda_kategori));
$ins_hilang = array_filter($s['insiden_unit'], fn ($u) => satu('SELECT 1 FROM perlengkapan WHERE id = :id', ['id' => $u]) === false);
cek('E12 setiap insiden di seed session merujuk unit yang ada di PostgreSQL', !$ins_hilang);

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
