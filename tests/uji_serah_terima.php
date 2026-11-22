<?php
// Uji Sprint Backend 4A: Serah Terima (barang keluar dan kembali) di PostgreSQL.
// HANYA untuk database uji (nama berakhiran _test), karena uji ini menambah dan mengubah data:
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_serah_terima.php
//
// - Proyek uji berawalan "UJI4A" (jadwal Maret–April 2027) dibuat sendiri. Transaksi serah terima tidak bisa dihapus
//   (sengaja, sama dengan aturan aplikasi), jadi proyek uji yang bertransaksi diarsipkan oleh tests/bantu_bersihkan.php.
// - Unit Studio yang dipakai dipilih otomatis (aktif, Baik, tidak sedang keluar); kondisi unit dikembalikan di akhir uji.
// - Data contoh (proyek 1–10, alokasi 1–41, TRX-038..045) hanya dibaca.
// Setiap request = proses PHP terpisah dengan session tertentu (= browser).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_uji4a_sesi');

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

// Mode anak: session "lama" (VERSI_DATA 5, sebelum 4A) yang masih menyimpan transaksi buatan session.
if (($argv[1] ?? '') === '--sesi-lama') {
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[2]);
    session_start();
    $_SESSION = ['db_versi' => 5, 'db' => [
        'transaksi' => [14 => ['id' => 14, 'kode' => 'TRX-SESI', 'proyek_id' => 3, 'jenis' => 'Keluar', 'waktu' => '2026-11-06 08:00',
            'petugas_id' => 2, 'penerima' => 'Rizky Pratama', 'catatan' => '']],
        'detail_transaksi' => [45 => ['id' => 45, 'transaksi_id' => 14, 'alokasi_id' => 13, 'kondisi' => 'Baik', 'jumlah' => 1]],
        'insiden' => [], 'persiapan' => [], 'riwayat' => [],
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
    echo json_encode([
        'versi' => $_SESSION['db_versi'] ?? null,
        'ada_transaksi' => isset($db['transaksi']) || isset($db['detail_transaksi']),
        'insiden' => array_values(array_map(fn ($i) => $i['kode'] . '|' . ($i['detail_id'] ?? '') . '|' . $i['perlengkapan_id'], $db['insiden'] ?? [])),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mode anak: memanggil fungsi PHP langsung (bersamaan dan rollback), tanpa gerbang persiapan di halaman.
if (($argv[1] ?? '') === '--fungsi') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('session.save_path', FOLDER_SESI);
    session_name('photoops');
    session_id($argv[3] ?? ('fungsi' . getmypid()));
    session_start();
    require AKAR_UJI . '/includes/bootstrap.php';
    restore_exception_handler();
    $jenis = $argv[2];
    $pid = (int) ($argv[4] ?? 0);
    $aid = (int) ($argv[5] ?? 0);
    $_SESSION['user_id'] = 2;
    $proyek = cari_proyek($pid);
    $a = cari_alokasi($aid);
    $kini = date('Y-m-d H:i', strtotime(sekarang()));
    $per = fn (string $kondisi, int $jumlah) => array_merge(['Baik' => 0, 'Maintenance' => 0, 'Rusak' => 0], [$kondisi => $jumlah]);
    $sabotase = function () {
        // Langkah terakhir gagal (aksi riwayat tidak sah -> CHECK constraint): SEMUA yang sudah ditulis harus ROLLBACK.
        sql_jalankan("INSERT INTO riwayat_aktivitas (waktu, pengguna_id, aksi) VALUES (NOW(), 2, 'Tidak Sah')");
    };
    try {
        if ($jenis === 'keluar') {
            $h = catat_barang_keluar($proyek, [['alokasi' => $a, 'jumlah' => jumlah_alokasi($a)]], cari_pengguna(3), $kini, 'uji');
            echo json_encode(['status' => $h['status'], 'masalah' => implode(' ', $h['masalah'] ?? [])]);
        } elseif ($jenis === 'kembali') {
            $h = catat_pengembalian($proyek, [['alokasi' => $a, 'per_kondisi' => $per($argv[6], (int) $argv[7])]], null, $kini, 'uji pengembalian bersamaan');
            echo json_encode(['status' => $h['status'], 'masalah' => implode(' ', $h['masalah'] ?? [])]);
        } elseif ($jenis === 'buka') {
            echo json_encode(['status' => cabut_konfirmasi_persiapan($pid, 'uji buka ulang bersamaan', 2)['status'], 'masalah' => '']);
        } elseif ($jenis === 'status') {
            echo json_encode(['status' => ubah_status_alokasi($pid, $aid, $argv[6])['status'], 'masalah' => '']);
        } elseif ($jenis === 'rb_keluar') {
            sql_transaksi(function () use ($proyek, $a, $kini, $sabotase) {
                proses_checkout($proyek, [['alokasi' => $a, 'jumlah' => jumlah_alokasi($a)]], cari_pengguna(3), $kini, 'uji rollback');
                $sabotase();
            });
            echo 'TANPA_GALAT';
        } elseif ($jenis === 'rb_kembali') {
            proses_session_dan_inventaris(function () use ($proyek, $a, $kini, $per, $sabotase) {
                proses_pengembalian($proyek, [['alokasi' => $a, 'per_kondisi' => $per('Rusak', 1)]], null, $kini, 'uji rollback rusak');
                $sabotase();
            });
            echo 'TANPA_GALAT';
        }
    } catch (PDOException $e) {
        echo 'GAGAL_PDO:' . $e->getCode() . ':insiden=' . count(db('insiden'));
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

// Transaksi contoh TRX-038..045 harus masih utuh (uji membaca data ini).
if (satu("SELECT string_agg(kode, ',' ORDER BY id) FROM transaksi_barang WHERE id <= 13")
    !== 'TRX-038,TRX-039,TRX-041,TRX-042,TRX-043,TRX-030,TRX-031,TRX-033,TRX-034,TRX-036,TRX-037,TRX-044,TRX-045'
    || (int) satu('SELECT COUNT(*) FROM detail_transaksi WHERE id <= 44') !== 44) {
    echo "[BERHENTI] Transaksi contoh di database uji tidak lagi sesuai seed. Buat ulang database uji dari 001 + 002 + 003.\n";
    exit(1);
}

require __DIR__ . '/bantu_bersihkan.php';
$kondisi_asal = [];
function bersihkan(): void
{
    global $kondisi_asal;
    arsipkan_proyek_uji('UJI4A');
    foreach ($kondisi_asal as $id => $kondisi) {
        jalankan('UPDATE perlengkapan SET kondisi = :k WHERE id = :id', ['k' => $kondisi, 'id' => $id]);
    }
}
bersihkan();
register_shutdown_function('bersihkan');
define('RIWAYAT_AWAL', (int) satu('SELECT COALESCE(MAX(id), 0) FROM riwayat_aktivitas'));
define('TRX_AWAL', (int) satu('SELECT COALESCE(MAX(id), 0) FROM transaksi_barang'));
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

function fungsi(string $jenis, int $pid, int $aid, array $tambahan = []): array
{
    return json_decode(anak(array_merge(['--fungsi', $jenis, 'fungsi' . mt_rand(), (string) $pid, (string) $aid], $tambahan)), true) ?? [];
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

function riwayat_baru(string $aksi, string $objek_like, string $ket_like = '%'): int
{
    return (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = :a AND objek LIKE :o AND keterangan LIKE :k AND id > :awal',
        ['a' => $aksi, 'o' => $objek_like, 'k' => $ket_like, 'awal' => RIWAYAT_AWAL]);
}

function saldo(int $aid): array
{
    return semua('SELECT jumlah_keluar, jumlah_kembali, sisa FROM v_saldo_alokasi WHERE alokasi_id = :a', ['a' => $aid])[0]
        ?? ['jumlah_keluar' => 0, 'jumlah_kembali' => 0, 'sisa' => 0];
}

function jumlah_keluar_detail(int $aid): int
{
    return (int) satu("SELECT COUNT(*) FROM detail_transaksi d JOIN transaksi_barang t ON t.id = d.transaksi_id WHERE d.alokasi_id = :a AND t.jenis = 'Keluar'", ['a' => $aid]);
}

function trx_baru(): int
{
    return (int) satu('SELECT COUNT(*) FROM transaksi_barang WHERE id > :awal', ['awal' => TRX_AWAL]);
}

function waktu_kini(): string
{
    return '2026-11-07T' . date('H:i');
}

const SA = 1, PI = 2, RIZKY = 3, NINA = 7;
$L = 'pages/proyek.php';
$D = 'pages/proyek-detail.php';
$S = 'pages/serah-terima.php';

function buat_proyek_uji(string $nama, string $tanggal): int
{
    global $L, $D;
    $sebelum = date('Y-m-d', strtotime($tanggal . ' -1 day'));
    minta('admin', SA, $L, [], ['aksi' => 'simpan_proyek', 'id' => '', 'nama' => $nama, 'klien' => 'Klien Uji', 'lokasi' => 'Malang',
        'mulai' => $tanggal . 'T08:00', 'selesai' => $tanggal . 'T17:00', 'deadline_persiapan' => $sebelum . 'T20:00', 'pic_id' => (string) RIZKY,
        'status' => 'Dijadwalkan', 'catatan' => '']);
    $pid = (int) satu('SELECT id FROM proyek WHERE nama = :n', ['n' => $nama]);
    minta('admin', SA, $D, ['id' => (string) $pid], ['aksi' => 'tambah_anggota', 'pengguna_id' => (string) NINA]);
    return $pid;
}

// Kebutuhan + alokasi lewat halaman (browser admin). Hasil: id alokasi.
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

function uji_konfirmasi_persiapan(string $sid, int $pid, array $aids): array
{
    global $D;
    minta($sid, SA, $D, ['id' => (string) $pid], ['aksi' => 'simpan_persiapan', 'diperiksa' => array_map('strval', $aids)]);
    return minta($sid, SA, $D, ['id' => (string) $pid], ['aksi' => 'konfirmasi_persiapan', 'proyek_id' => (string) $pid, 'konfirmasi' => '1']);
}

function form_keluar(int $pid, array $jumlah): array
{
    return ['aksi' => 'checkout', 'proyek_id' => (string) $pid, 'alokasi' => array_map('strval', array_keys($jumlah)),
        'jumlah' => array_map('strval', $jumlah), 'penerima_id' => (string) RIZKY, 'waktu' => waktu_kini(), 'catatan' => '', 'konfirmasi' => '1'];
}

// $baris: [alokasi_id => ['saldo' => n, 'kondisi' => 'Baik'] (Studio) | ['saldo' => n, 'jumlah' => ['Baik' => n, ...]]]
function form_kembali(int $pid, array $baris, string $catatan = ''): array
{
    $form = ['aksi' => 'checkin', 'proyek_id' => (string) $pid, 'alokasi' => [], 'saldo' => [], 'kondisi' => [], 'jumlah' => [],
        'penyerah_id' => (string) NINA, 'waktu' => waktu_kini(), 'catatan' => $catatan, 'konfirmasi' => '1'];
    foreach ($baris as $aid => $b) {
        $form['alokasi'][] = (string) $aid;
        $form['saldo'][$aid] = (string) $b['saldo'];
        if (isset($b['kondisi'])) {
            $form['kondisi'][$aid] = $b['kondisi'];
        } else {
            $form['jumlah'][$aid] = array_map('strval', $b['jumlah']);
        }
    }
    return $form;
}

echo "Database uji: {$k['dbname']}\n\n== Persiapan data uji\n";

// Unit Studio aktif, Baik, tidak sedang keluar, dan tidak punya alokasi aktif di Maret–April 2027.
$unit = semua("SELECT u.id, u.kode_unit, u.kategori_id, u.kondisi FROM perlengkapan u
               WHERE u.status_aktif = 'Aktif' AND u.kondisi = 'Baik'
                 AND NOT EXISTS (SELECT 1 FROM alokasi_barang a JOIN v_saldo_alokasi v ON v.alokasi_id = a.id WHERE a.perlengkapan_id = u.id AND v.sisa > 0)
                 AND NOT EXISTS (SELECT 1 FROM alokasi_barang a WHERE a.perlengkapan_id = u.id AND a.status <> 'Dibatalkan' AND a.tgl_selesai >= '2027-03-01')
               ORDER BY u.id LIMIT 6");
if (count($unit) < 6) {
    echo "[BERHENTI] Database uji tidak punya 6 unit Studio aktif berkondisi Baik yang bebas.\n";
    exit(1);
}
foreach ($unit as $u) {
    $kondisi_asal[(int) $u['id']] = $u['kondisi'];
}
[$U1, $U2, $U3, $U4, $U5, $U6] = $unit;

$p1 = buat_proyek_uji('UJI4A Satu', '2027-03-10');
$aStudio = alokasi_uji($p1, 'UJI4A Kamera', (int) $U1['kategori_id'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U1['id']]);
$aRental = alokasi_uji($p1, 'UJI4A Lighting', 3, ['sumber' => 'Rental', 'nama_barang' => 'Lampu Sewa 4A', 'vendor' => 'Vendor 4A', 'jumlah' => '3']);
$aKru = alokasi_uji($p1, 'UJI4A SD Card', 5, ['sumber' => 'Milik Kru', 'nama_barang' => 'SD Card Nina 4A', 'pemilik_id' => (string) NINA, 'jumlah' => '2']);
minta('admin', SA, 'pages/perlengkapan.php', ['tab' => 'eksternal'], ['aksi' => 'terima_rental', 'id' => (string) $aRental, 'proyek_id' => (string) $p1,
    'jumlah' => '3', 'waktu' => '2026-11-07T00:01', 'kondisi' => 'Baik', 'catatan' => '', 'konfirmasi' => '1']);
$rental_awal = semua('SELECT vendor, nama_barang, jumlah, diterima_pada, dikembalikan_vendor_pada, pemilik_id FROM alokasi_barang WHERE id IN (:a, :b) ORDER BY id',
    ['a' => $aRental, 'b' => $aKru]);
$h = uji_konfirmasi_persiapan('admin', $p1, [$aStudio, $aRental, $aKru]);
cek('Persiapan: proyek uji, alokasi Studio/Rental/Milik Kru, rental diterima, persiapan dikonfirmasi di browser A',
    $p1 && $aStudio && $aRental && $aKru && str_contains($h['pesan'], 'dikonfirmasi selesai'), $h['pesan']);

echo "\n== S01–S03 Barang keluar, persistensi, lintas browser\n";
$h = minta('admin', SA, $S, [], form_keluar($p1, [$aStudio => 1, $aRental => 3, $aKru => 2]));
$trx = semua("SELECT id, kode, jenis, penerima, penerima_id, petugas_id FROM transaksi_barang WHERE proyek_id = :p AND jenis = 'Keluar'", ['p' => $p1]);
$kode_keluar = $trx[0]['kode'] ?? '?';
cek('S01a barang keluar tersimpan di transaksi_barang + detail_transaksi (Studio 1, Rental 3, Milik Kru 2)', count($trx) === 1 && str_contains($h['pesan'], 'unit keluar')
    && saldo($aStudio)['sisa'] === 1 && saldo($aRental)['sisa'] === 3 && saldo($aKru)['sisa'] === 2 && (int) $trx[0]['penerima_id'] === RIZKY && (int) $trx[0]['petugas_id'] === SA, $h['pesan'] . $h['galat']);
cek('S01b kode transaksi dari sequence seq_kode_transaksi', (bool) preg_match('/^TRX-\d{3,}$/', $kode_keluar) && (int) substr($kode_keluar, 4) > 45, $kode_keluar);
$h = minta('adminLoginUlang', SA, $S);
cek('S02 transaksi tetap ada setelah login ulang (session baru)', bersih($h) && str_contains($h['html'], $kode_keluar));
$h = minta('petugasB', PI, $S);
cek('S03a browser lain (Petugas) melihat transaksi yang sama', bersih($h) && str_contains($h['html'], $kode_keluar));
$h = minta('petugasB', PI, $D, ['id' => (string) $p1, 'tab' => 'serah']);
cek('S03b tab Serah Terima proyek di browser lain menampilkan barang keluar', bersih($h) && str_contains($h['html'], $kode_keluar));

echo "\n== S07–S08 Keluar ganda dan alokasi yang sudah keluar\n";
$sebelum = trx_baru();
$h = minta('admin', SA, $S, [], form_keluar($p1, [$aStudio => 1]));
cek('S07a mencatat keluar lagi untuk alokasi yang sudah keluar ditolak (halaman)', trx_baru() === $sebelum && jumlah_keluar_detail($aStudio) === 1, $h['pesan'] . $h['galat']);
$r = fungsi('keluar', $p1, $aKru);
cek('S07b ditolak juga di dalam transaksi PostgreSQL (tanpa gerbang halaman)', ($r['status'] ?? '') === 'ditolak' && str_contains($r['masalah'] ?? '', 'sudah tercatat keluar') && jumlah_keluar_detail($aKru) === 1, $r['masalah'] ?? '');
foreach (['batalkan_alokasi' => 'dibatalkan', 'hapus_alokasi' => 'dihapus'] as $aksi => $ket) {
    $h = minta('browserB', SA, $D, ['id' => (string) $p1], ['aksi' => $aksi, 'id' => (string) $aStudio]);
    // Sejak Sprint 4B dua kunci berlaku: persiapan terkonfirmasi (PostgreSQL) dan serah terima. Keduanya "dikunci".
    cek('S08a alokasi yang barangnya masih keluar tidak bisa ' . $ket . ' dari browser lain', satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aStudio]) === 'Dikonfirmasi'
        && str_contains($h['pesan'], 'dikunci'), $h['pesan']);
}
$st_hapus = fungsi('status', $p1, $aKru, ['hapus'])['status'] ?? '';
$st_batal = fungsi('status', $p1, $aKru, ['batalkan'])['status'] ?? '';
cek('S08b ubah_status_alokasi() menolak di dalam transaksi (persiapan terkunci / punya serah terima)',
    in_array($st_hapus, ['persiapan_terkunci', 'punya_serah'], true) && in_array($st_batal, ['persiapan_terkunci', 'punya_serah'], true), $st_hapus . ' ' . $st_batal);
$r = fungsi('buka', $p1, 0);
cek('S08f persiapan yang barangnya sudah keluar tidak bisa dibuka ulang (sehingga alokasinya tetap terkunci)', ($r['status'] ?? '') === 'beku', json_encode($r));
$kidStudio = (int) satu('SELECT kebutuhan_id FROM alokasi_barang WHERE id = :a', ['a' => $aStudio]);
$h = minta('browserB', SA, $D, ['id' => (string) $p1], ['aksi' => 'hapus_kebutuhan', 'id' => (string) $kidStudio]);
cek('S08c kebutuhan yang alokasinya sudah keluar tidak bisa dihapus', (int) satu('SELECT COUNT(*) FROM kebutuhan_proyek WHERE id = :k', ['k' => $kidStudio]) === 1, $h['pesan']);
$galat_fk = '';
try {
    jalankan('DELETE FROM alokasi_barang WHERE id = :a', ['a' => $aStudio]);
} catch (PDOException $e) {
    $galat_fk = $e->getCode();
}
cek('S08d pengaman terakhir: DELETE langsung (peran aplikasi) ditolak FOREIGN KEY detail_transaksi', $galat_fk === '23503'
    && (int) satu('SELECT COUNT(*) FROM alokasi_barang WHERE id = :a', ['a' => $aStudio]) === 1, (string) $galat_fk);
$id_nina = (int) satu('SELECT id FROM anggota_proyek WHERE proyek_id = :p AND pengguna_id = :u', ['p' => $p1, 'u' => NINA]);
$h = minta('browserB', SA, $D, ['id' => (string) $p1], ['aksi' => 'lepas_anggota', 'id' => (string) $id_nina]);
cek('S08e kru yang barang Milik Kru-nya masih keluar tidak bisa dilepas', (int) satu('SELECT COUNT(*) FROM anggota_proyek WHERE id = :i', ['i' => $id_nina]) === 1);

echo "\n== S16 Inventaris (status fisik)\n";
$h = minta('petugasB', PI, 'pages/perlengkapan.php');
$pos = strpos($h['html'], $U1['kode_unit']);
cek('S16a unit yang keluar tampil Dipinjam di browser lain', bersih($h) && $pos !== false && str_contains(substr($h['html'], $pos, 1500), 'Dipinjam'));

echo "\n== S04–S06, S12 Pengembalian\n";
// Rental 3 unit: sebagian dari browser B (1 Baik), lalu sisanya dari browser A (1 Baik + 1 Maintenance).
$h = minta('petugasB', PI, $S, [], form_kembali($p1, [$aRental => ['saldo' => 3, 'jumlah' => ['Baik' => '1']]]));
cek('S05a pengembalian sebagian dari browser lain: sisa 3 -> 2', saldo($aRental)['sisa'] === 2 && saldo($aRental)['jumlah_kembali'] === 1, $h['pesan'] . $h['galat']);
$h = minta('admin', SA, $S, [], form_kembali($p1, [$aRental => ['saldo' => 3, 'jumlah' => ['Baik' => '1']]]));
cek('S05b formulir dengan saldo lama (sebelum pengembalian browser lain) ditolak, tidak tercatat dua kali', saldo($aRental)['sisa'] === 2, $h['galat'] . $h['pesan']);
$h = minta('admin', SA, $S, [], form_kembali($p1, [$aRental => ['saldo' => 2, 'jumlah' => ['Baik' => '1', 'Maintenance' => '1']]], 'Satu lampu kedip-kedip'));
cek('S04a pengembalian sisa (1 Baik + 1 Maintenance) menuntaskan saldo Rental', saldo($aRental)['sisa'] === 0 && saldo($aRental)['jumlah_kembali'] === 3, $h['pesan'] . $h['galat']);
$h = minta('admin', SA, $S, [], form_kembali($p1, [$aRental => ['saldo' => 0, 'jumlah' => ['Baik' => '1']]]));
cek('S06a pengembalian ketiga setelah seluruh unit kembali ditolak', saldo($aRental)['jumlah_kembali'] === 3, $h['galat'] . $h['pesan']);
$h = minta('admin', SA, $S, [], form_kembali($p1, [$aKru => ['saldo' => 2, 'jumlah' => ['Baik' => '3']]]));
cek('S06b jumlah kembali melebihi sisa (3 > 2) ditolak', saldo($aKru)['sisa'] === 2 && str_contains($h['galat'], 'melebihi'), $h['galat']);
$r = fungsi('kembali', $p1, $aKru, ['Baik', '3']);
cek('S06c ditolak juga di dalam transaksi PostgreSQL', ($r['status'] ?? '') === 'ditolak' && saldo($aKru)['sisa'] === 2, $r['masalah'] ?? '');
$h = minta('admin', SA, $S, [], form_kembali($p1, [$aStudio => ['saldo' => 1, 'kondisi' => 'Rusak']], 'Lensa mount retak saat bongkar'));
$detail_rusak = semua("SELECT d.id, d.kondisi, t.catatan, t.penyerah_id FROM detail_transaksi d JOIN transaksi_barang t ON t.id = d.transaksi_id
                       WHERE d.alokasi_id = :a AND t.jenis = 'Kembali'", ['a' => $aStudio]);
cek('S04b pengembalian penuh unit Studio tercatat', saldo($aStudio)['sisa'] === 0 && count($detail_rusak) === 1, $h['pesan'] . $h['galat']);
cek('S12a kondisi kembali, catatan, dan penyerah tersimpan di transaksi', ($detail_rusak[0]['kondisi'] ?? '') === 'Rusak'
    && ($detail_rusak[0]['catatan'] ?? '') === 'Lensa mount retak saat bongkar' && (int) ($detail_rusak[0]['penyerah_id'] ?? 0) === NINA);
cek('S12b kondisi unit di Inventaris ikut menjadi Rusak (transaksi yang sama)', satu('SELECT kondisi FROM perlengkapan WHERE id = :u', ['u' => $U1['id']]) === 'Rusak');
$s = json_decode(anak(['--periksa-sesi', 'admin']), true);
$ins = array_values(array_filter($s['insiden'] ?? [], fn ($x) => str_ends_with($x, '|' . $detail_rusak[0]['id'] . '|' . $U1['id'])));
cek('S12c insiden otomatis tercatat dan menunjuk detail_transaksi PostgreSQL', count($ins) === 1 && str_contains($h['pesan'], 'Insiden dicatat'), json_encode($s['insiden'] ?? []));
cek('S12d riwayat Pengembalian mencatat kondisi dan kode insiden', riwayat_baru('Pengembalian', '%', '%kondisi 1 Rusak%insiden INS-%') === 1);
$h = minta('petugasB', PI, 'pages/perlengkapan.php');
$pos = strpos($h['html'], $U1['kode_unit']);
cek('S16b setelah kembali, unit tidak lagi Dipinjam di browser lain (status mengikuti kondisi Rusak)', bersih($h) && $pos !== false
    && !str_contains(substr($h['html'], $pos, 1500), 'Dipinjam'));

echo "\n== S17 Rental dan Milik Kru\n";
$rental_akhir = semua('SELECT vendor, nama_barang, jumlah, diterima_pada, dikembalikan_vendor_pada, pemilik_id FROM alokasi_barang WHERE id IN (:a, :b) ORDER BY id',
    ['a' => $aRental, 'b' => $aKru]);
cek('S17a serah terima tidak mengubah data vendor/penerimaan Rental maupun pemilik Milik Kru', $rental_akhir === $rental_awal);
$h = minta('admin', SA, 'pages/perlengkapan.php', ['tab' => 'eksternal'], ['aksi' => 'kembalikan_vendor', 'id' => (string) $aRental, 'proyek_id' => (string) $p1,
    'jumlah' => '3', 'waktu' => waktu_kini(), 'kondisi' => 'Baik', 'catatan' => '', 'konfirmasi' => '1']);
cek('S17b Rental yang seluruhnya sudah kembali dari proyek bisa dikembalikan ke vendor', satu('SELECT dikembalikan_vendor_pada FROM alokasi_barang WHERE id = :a', ['a' => $aRental]) !== null,
    $h['pesan'] . $h['galat']);
$p2 = buat_proyek_uji('UJI4A Dua', '2027-03-20');
$aRental2 = alokasi_uji($p2, 'UJI4A Lighting Dua', 3, ['sumber' => 'Rental', 'nama_barang' => 'Lampu Dua', 'vendor' => 'Vendor 4A', 'jumlah' => '1']);
minta('admin', SA, 'pages/perlengkapan.php', ['tab' => 'eksternal'], ['aksi' => 'terima_rental', 'id' => (string) $aRental2, 'proyek_id' => (string) $p2,
    'jumlah' => '1', 'waktu' => '2026-11-07T00:01', 'kondisi' => 'Baik', 'catatan' => '', 'konfirmasi' => '1']);
uji_konfirmasi_persiapan('admin', $p2, [$aRental2]);
fungsi('keluar', $p2, $aRental2);
$h = minta('admin', SA, 'pages/perlengkapan.php', ['tab' => 'eksternal'], ['aksi' => 'kembalikan_vendor', 'id' => (string) $aRental2, 'proyek_id' => (string) $p2,
    'jumlah' => '1', 'waktu' => waktu_kini(), 'kondisi' => 'Baik', 'catatan' => '', 'konfirmasi' => '1']);
cek('S17c Rental yang masih keluar di proyek tidak bisa dikembalikan ke vendor (semua browser)', saldo($aRental2)['sisa'] === 1
    && satu('SELECT dikembalikan_vendor_pada FROM alokasi_barang WHERE id = :a', ['a' => $aRental2]) === null, $h['pesan']);

echo "\n== S09–S11 Bersamaan\n";
// S09a: dua request keluar untuk alokasi yang sama.
$p3 = buat_proyek_uji('UJI4A Tiga', '2027-03-25');
$a3 = alokasi_uji($p3, 'UJI4A Unit Tiga', (int) $U2['kategori_id'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U2['id']]);
uji_konfirmasi_persiapan('admin', $p3, [$a3]);
[$x, $y] = paralel(['--fungsi', 'keluar', 'pA' . mt_rand(), (string) $p3, (string) $a3], ['--fungsi', 'keluar', 'pB' . mt_rand(), (string) $p3, (string) $a3]);
$st = [$x['status'] ?? '?', $y['status'] ?? '?'];
sort($st);
cek('S09a dua request keluar bersamaan untuk alokasi yang sama -> satu tercatat, satu ditolak', $st === ['ditolak', 'ok'] && jumlah_keluar_detail($a3) === 1, json_encode([$x, $y], JSON_UNESCAPED_UNICODE));
// S09b: unit yang sama di dua proyek (jadwal tidak bentrok) dikeluarkan bersamaan.
$p4 = buat_proyek_uji('UJI4A Empat', '2027-04-05');
$p5 = buat_proyek_uji('UJI4A Lima', '2027-04-15');
$a4 = alokasi_uji($p4, 'UJI4A Unit Empat', (int) $U3['kategori_id'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U3['id']]);
$a5 = alokasi_uji($p5, 'UJI4A Unit Lima', (int) $U3['kategori_id'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U3['id']]);
uji_konfirmasi_persiapan('admin', $p4, [$a4]);
uji_konfirmasi_persiapan('admin', $p5, [$a5]);
[$x, $y] = paralel(['--fungsi', 'keluar', 'pA' . mt_rand(), (string) $p4, (string) $a4], ['--fungsi', 'keluar', 'pB' . mt_rand(), (string) $p5, (string) $a5]);
$st = [$x['status'] ?? '?', $y['status'] ?? '?'];
sort($st);
cek('S09b satu unit fisik tidak bisa keluar di dua proyek bersamaan', $st === ['ditolak', 'ok'] && jumlah_keluar_detail($a4) + jumlah_keluar_detail($a5) === 1
    && str_contains(($x['masalah'] ?? '') . ($y['masalah'] ?? ''), 'masih keluar di'), json_encode([$x, $y], JSON_UNESCAPED_UNICODE));
cek('S09c kode transaksi tetap unik', (int) satu('SELECT COUNT(*) - COUNT(DISTINCT kode) FROM transaksi_barang') === 0);
// S10: dua pengembalian bersamaan (Milik Kru sisa 2, masing-masing 2).
[$x, $y] = paralel(['--fungsi', 'kembali', 'pA' . mt_rand(), (string) $p1, (string) $aKru, 'Baik', '2'], ['--fungsi', 'kembali', 'pB' . mt_rand(), (string) $p1, (string) $aKru, 'Baik', '2']);
$st = [$x['status'] ?? '?', $y['status'] ?? '?'];
sort($st);
cek('S10 dua pengembalian bersamaan tidak melebihi sisa -> satu tercatat, satu ditolak', $st === ['ditolak', 'ok'] && saldo($aKru)['jumlah_kembali'] === 2 && saldo($aKru)['sisa'] === 0,
    json_encode([$x, $y], JSON_UNESCAPED_UNICODE));
// S11: sejak Sprint 4B alokasi tidak bisa dibatalkan selama persiapan dikonfirmasi, sedangkan barang keluar MENSYARATKAN
// konfirmasi. Jalur pembatalannya: buka ulang persiapan lalu batalkan. Yang diuji: barang keluar bersamaan dengan buka ulang
// persiapan (dua putaran, unit berbeda), lalu pembatalan alokasi.
foreach ([$U4, $U5] as $i => $u) {
    $pid = buat_proyek_uji('UJI4A Balapan ' . ($i + 1), '2027-04-2' . ($i + 1));
    $aid = alokasi_uji($pid, 'UJI4A Unit Balapan ' . ($i + 1), (int) $u['kategori_id'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $u['id']]);
    uji_konfirmasi_persiapan('admin', $pid, [$aid]);
    [$x, $y] = paralel(['--fungsi', 'keluar', 'pA' . mt_rand(), (string) $pid, (string) $aid], ['--fungsi', 'buka', 'pB' . mt_rand(), (string) $pid, '0']);
    $batal = fungsi('status', $pid, $aid, ['batalkan']);
    $status = satu('SELECT status FROM alokasi_barang WHERE id = :a', ['a' => $aid]);
    $keluar = jumlah_keluar_detail($aid);
    $sah = ($keluar === 1 && $status === 'Dikonfirmasi' && ($x['status'] ?? '') === 'ok' && ($y['status'] ?? '') === 'beku' && in_array($batal['status'] ?? '', ['persiapan_terkunci', 'punya_serah'], true))
        || ($keluar === 0 && $status === 'Dibatalkan' && ($y['status'] ?? '') === 'ok' && ($x['status'] ?? '') === 'ditolak' && ($batal['status'] ?? '') === 'ok');
    cek('S11 (' . ($i + 1) . ') keluar vs buka ulang persiapan bersamaan, lalu batal -> hasil sah (keluar & alokasi tetap, ATAU dibuka, batal, tidak keluar)', $sah,
        json_encode(['keluar' => $x, 'buka' => $y, 'batal' => $batal, 'status' => $status, 'detail_keluar' => $keluar], JSON_UNESCAPED_UNICODE));
}

echo "\n== S13–S15 Hak akses, CSRF, rollback\n";
$p6 = buat_proyek_uji('UJI4A Enam', '2027-04-28');
$a6 = alokasi_uji($p6, 'UJI4A Unit Enam', (int) $U6['kategori_id'], ['sumber' => 'Studio', 'perlengkapan_id' => (string) $U6['id']]);
uji_konfirmasi_persiapan('admin', $p6, [$a6]);
$sebelum = trx_baru();
$h = minta('rizky', RIZKY, $S, [], form_keluar($p6, [$a6 => 1]));
cek('S13a User/Kru mencatat barang keluar lewat POST langsung ditolak', trx_baru() === $sebelum, $h['pesan']);
$h = minta('rizky', RIZKY, $S, [], form_kembali($p3, [$a3 => ['saldo' => 1, 'kondisi' => 'Baik']]));
cek('S13b User/Kru mencatat pengembalian lewat POST langsung ditolak', trx_baru() === $sebelum && saldo($a3)['sisa'] === 1, $h['pesan']);
minta('tamu', null, $S, [], form_keluar($p6, [$a6 => 1]));
cek('S13c tanpa login: POST ditolak', trx_baru() === $sebelum);
$h = minta('admin', SA, $S, [], form_keluar($p6, [$a6 => 1]), false);
cek('S14a barang keluar tanpa token CSRF ditolak', trx_baru() === $sebelum && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
$h = minta('admin', SA, $S, [], form_kembali($p3, [$a3 => ['saldo' => 1, 'kondisi' => 'Baik']]), 'token-salah');
cek('S14b pengembalian dengan token CSRF salah ditolak', trx_baru() === $sebelum && saldo($a3)['sisa'] === 1 && str_contains($h['pesan'], 'kedaluwarsa'), $h['pesan']);
$riwayat_sebelum = (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas');
$r = anak(['--fungsi', 'rb_keluar', 'rb1', (string) $p6, (string) $a6]);
cek('S15a barang keluar: langkah terakhir gagal -> transaksi, rincian, dan riwayat dibatalkan', str_starts_with($r, 'GAGAL_PDO:23514') && trx_baru() === $sebelum
    && jumlah_keluar_detail($a6) === 0 && (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas') === $riwayat_sebelum, $r);
$kondisi_u2 = satu('SELECT kondisi FROM perlengkapan WHERE id = :u', ['u' => $U2['id']]);
$r = anak(['--fungsi', 'rb_kembali', 'rb2', (string) $p3, (string) $a3]);
cek('S15b pengembalian Rusak: langkah terakhir gagal -> transaksi, kondisi unit, riwayat, dan insiden session dibatalkan', str_starts_with($r, 'GAGAL_PDO:23514')
    && str_ends_with($r, 'insiden=3') && saldo($a3)['sisa'] === 1 && satu('SELECT kondisi FROM perlengkapan WHERE id = :u', ['u' => $U2['id']]) === $kondisi_u2
    && (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas') === $riwayat_sebelum, $r);

echo "\n== S18 Persiapan\n";
// Sejak Sprint 4B konfirmasi persiapan ada di PostgreSQL: tanpa konfirmasi semua browser ditolak; setelah dikonfirmasi
// (di browser mana pun), browser lain yang berwenang boleh mencatat barang keluar.
$p7 = buat_proyek_uji('UJI4A Tujuh', '2027-04-30');
$a7 = alokasi_uji($p7, 'UJI4A Kru Tujuh', 5, ['sumber' => 'Milik Kru', 'nama_barang' => 'SD Card Tujuh', 'pemilik_id' => (string) NINA, 'jumlah' => '1']);
$h = minta('browserBaru', SA, $S, [], form_keluar($p7, [$a7 => 1]));
cek('S18a tanpa konfirmasi persiapan (PostgreSQL), barang keluar ditolak (gerbang tidak terlewati)', jumlah_keluar_detail($a7) === 0
    && str_contains($h['pesan'], 'belum bisa dicatat'), $h['pesan']);
$r = fungsi('keluar', $p7, $a7);
cek('S18e ditolak juga di dalam transaksi PostgreSQL (tanpa gerbang halaman)', ($r['status'] ?? '') === 'ditolak' && jumlah_keluar_detail($a7) === 0, $r['masalah'] ?? '');
uji_konfirmasi_persiapan('admin', $p7, [$a7]);
$h = minta('browserBaru', SA, $S, [], form_keluar($p7, [$a7 => 1]));
cek('S18b persiapan dikonfirmasi di browser A, barang keluar dicatat dari browser B', jumlah_keluar_detail($a7) === 1, $h['pesan'] . $h['galat']);
$h = minta('admin', SA, $S, [], form_keluar($p7, [$a7 => 1]));
cek('S18c setelah keluar, browser lain tetap tidak bisa mengeluarkannya lagi', jumlah_keluar_detail($a7) === 1, $h['pesan']);
$h = minta('admin', SA, $S, [], form_keluar($p6, [$a6 => 1]));
cek('S18f proyek lain yang dikonfirmasi tetap bisa mengeluarkan barang', jumlah_keluar_detail($a6) === 1, $h['pesan'] . $h['galat']);
$h = minta('browserBaru', SA, $D, ['id' => (string) $p6, 'tab' => 'persiapan']);
cek('S18d halaman Persiapan di browser lain tetap tampil (barang sudah keluar terbaca dari PostgreSQL)', bersih($h) && $h['html'] !== '');

echo "\n== S19 Riwayat\n";
$h = minta('riwayatB', PI, 'pages/riwayat.php');
$kode_p1 = satu('SELECT kode FROM proyek WHERE id = :p', ['p' => $p1]);
cek('S19a log Barang Keluar dan Pengembalian dari PostgreSQL tampil di browser lain', bersih($h) && str_contains($h['html'], $kode_keluar)
    && riwayat_baru('Barang Keluar', $kode_p1 . ' · ' . $kode_keluar) === 1 && riwayat_baru('Pengembalian', $kode_p1 . ' · %') === 4);
cek('S19b setiap transaksi baru punya tepat satu log riwayat (tidak ganda)', (int) satu("SELECT COUNT(*) FROM transaksi_barang t WHERE t.id > :awal AND t.catatan <> 'Pembersihan data uji otomatis'
    AND (SELECT COUNT(*) FROM riwayat_aktivitas r WHERE r.aksi IN ('Barang Keluar', 'Pengembalian') AND r.objek LIKE '% · ' || t.kode) <> 1", ['awal' => TRX_AWAL]) === 0);
cek('S19c log contoh TRX-044/045 tampil sekali (tidak ganda antara session dan PostgreSQL)', substr_count($h['html'], 'PRJ-025 · TRX-044') === 1);

echo "\n== S20 Regresi halaman dan session lama\n";
$masalah = [];
foreach ([[SA, 'pages/dashboard-admin.php', []], [PI, 'pages/dashboard-petugas.php', []], [RIZKY, 'pages/dashboard-user.php', []], [SA, $S, []], [PI, $S, []],
    [SA, 'pages/perlengkapan.php', []], [SA, 'pages/perlengkapan.php', ['tab' => 'eksternal']], [SA, 'pages/perlengkapan.php', ['tab' => 'insiden']],
    [SA, 'pages/riwayat.php', []], [SA, $D, ['id' => (string) $p1, 'tab' => 'serah']], [SA, $D, ['id' => (string) $p1, 'tab' => 'kebutuhan']],
    [PI, $D, ['id' => '7', 'tab' => 'serah']], [PI, $D, ['id' => '2', 'tab' => 'persiapan']], [RIZKY, $D, ['id' => (string) $p1]], [SA, 'pages/proyek.php', []]] as [$uid, $hal, $get]) {
    $h = minta('regresi' . $uid, $uid, $hal, $get);
    if (!bersih($h) || $h['html'] === '' || str_contains($h['pesan'], 'tidak termasuk hak akses') || str_contains($h['pesan'], 'tidak ditugaskan')) {
        $masalah[] = $hal . json_encode($get) . ' ' . $h['pesan'] . ' ' . substr(strip_tags($h['mentah']), 0, 200);
    }
}
cek('S20a dashboard tiga peran, Serah Terima, Inventaris (3 tab), Riwayat, dan detail proyek tampil tanpa galat', !$masalah, implode(' || ', $masalah));
$h = minta('regresi1', SA, $S);
cek('S20b daftar transaksi menampilkan data contoh (TRX-044) dan barang di luar dari PostgreSQL', str_contains($h['html'], 'TRX-044') && str_contains($h['html'], 'TRX-045'));
anak(['--sesi-lama', 'sesiLama']);
$h = minta('sesiLama', SA, $S);
$s = json_decode(anak(['--periksa-sesi', 'sesiLama']), true);
cek('S20c session lama (versi 5) di-seed ulang ke versi 6; transaksi buatan session tidak tampil', ($s['versi'] ?? 0) === 6 && !$s['ada_transaksi']
    && !str_contains($h['html'], 'TRX-SESI') && bersih($h));

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
