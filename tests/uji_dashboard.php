<?php
// Uji dashboard tiga peran (Super Admin, Petugas Inventaris, User/Kru): angka dan daftar dibandingkan dengan kueri SQL
// independen pada PostgreSQL, tautan tindakan, RBAC, status kosong, dan galat PHP.
// HANYA untuk database uji (nama berakhiran _test):
//   PHOTOOPS_DB_NAME=photoops_si3_test php tests/uji_dashboard.php
//
// Tidak mensyaratkan data contoh asli: angka harapan dihitung dari isi database saat uji berjalan. Data yang dibuat uji
// (akun "ujidb.kosong" dan satu insiden "UJIDB") dibersihkan/ditutup kembali; kondisi unit yang dipakai dipulihkan.
// Tata letak desktop/mobile (D09) diperiksa terpisah lewat browser; uji ini memeriksa HTML hasil server.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
define('FOLDER_SESI', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_ujidb_sesi');

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
// Tanggal aplikasi di development/uji = tanggal data contoh (includes/konfigurasi.php), kecuali PHOTOOPS_TANGGAL_DEMO diisi.
if (getenv('PHOTOOPS_TANGGAL_DEMO') || getenv('PHOTOOPS_ENV') === 'production') {
    echo "[BERHENTI] Jalankan uji tanpa PHOTOOPS_TANGGAL_DEMO dan bukan mode produksi (tanggal harus deterministik).\n";
    exit(1);
}
if (!preg_match("/const TANGGAL_DEMO_LOKAL = '([0-9-]+)'/", (string) file_get_contents(AKAR_UJI . '/includes/konfigurasi.php'), $m)) {
    echo "[BERHENTI] TANGGAL_DEMO_LOKAL tidak ditemukan di includes/konfigurasi.php.\n";
    exit(1);
}
define('HARI', $m[1]);

function satu(string $sql, array $p = [])
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

function kolom(string $sql, array $p = []): array
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

function jalankan(string $sql, array $p = []): void
{
    global $pdo;
    $pdo->prepare($sql)->execute($p);
}

$kondisi_asal = [];
function bersihkan(): void
{
    global $kondisi_asal;
    jalankan("UPDATE laporan_insiden SET status = 'Selesai' WHERE deskripsi LIKE 'UJIDB%' AND status <> 'Selesai'");
    foreach ($kondisi_asal as $id => $kondisi) {
        jalankan('UPDATE perlengkapan SET kondisi = :k WHERE id = :id', ['k' => $kondisi, 'id' => $id]);
    }
    jalankan("DELETE FROM pengguna WHERE username = 'ujidb.kosong' AND NOT EXISTS (SELECT 1 FROM riwayat_aktivitas WHERE pengguna_id = pengguna.id)");
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

function minta(string $sid, ?int $user, string $halaman, array $get = [], ?array $post = null, $csrf = true): array
{
    $perintah = [PHP_BINARY, __FILE__, '--permintaan'];
    $proses = proc_open($perintah, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa, AKAR_UJI, getenv());
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

function bersih(array $h): bool
{
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|Parse error)\b|Layanan database PhotoOps sedang tidak tersedia/', $h['html'] . $h['mentah']);
}

function kpi(array $h, string $penanda): ?int
{
    return preg_match('/' . preg_quote($penanda, '/') . '>(\d+)</', $h['html'], $m) ? (int) $m[1] : null;
}

// Butir "Perlu tindakan": [judul, keterangan] (HTML di-decode).
function butir_tindakan(array $h): array
{
    if (!preg_match('/<section class="kartu" data-perlu-tindakan>(.*?)<\/section>/s', $h['html'], $m)) {
        return [];
    }
    preg_match_all('/<span class="daftar-judul">(.*?)<\/span>\s*<span class="daftar-sub">(.*?)<\/span>/s', $m[1], $b, PREG_SET_ORDER);
    return array_map(fn ($x) => [html_entity_decode($x[1], ENT_QUOTES, 'UTF-8'), html_entity_decode($x[2], ENT_QUOTES, 'UTF-8')], $b);
}

function kode_butir(array $butir, string $awal_judul): array
{
    $hasil = [];
    foreach ($butir as [$judul, $ket]) {
        if (str_starts_with($judul, $awal_judul) || ($awal_judul[0] === '/' && preg_match($awal_judul, $judul))) {
            preg_match_all('/PRJ-\d{3,}/', $ket, $m);
            $hasil = array_merge($hasil, $m[0]);
        }
    }
    $hasil = array_values(array_unique($hasil));
    sort($hasil);
    return $hasil;
}

function urut(array $a): array
{
    $a = array_values(array_unique($a));
    sort($a);
    return $a;
}

const SA = 1, PI = 2, RIZKY = 3;
$kini = HARI . ' ' . date('H:i:s');
$segera14 = date('Y-m-d', strtotime(HARI . ' +14 days'));
echo "Database uji: {$k['dbname']} · tanggal aplikasi " . HARI . "\n";

// Kueri harapan (independen dari kode PHP aplikasi). Persiapan "lewat" = aktif, punya alokasi/kekurangan, belum Siap, dan batas lewat;
// Siap = semua alokasi aktif sudah dicentang dan bisa diperiksa (Dikonfirmasi; Rental sudah diterima) dan tidak ada kekurangan.
const SQL_RINGKAS = "WITH al AS (SELECT a.kebutuhan_id, k.proyek_id, a.status, a.sumber, a.diterima_pada, a.diperiksa,
                                        CASE WHEN a.sumber = 'Studio' THEN 1 ELSE a.jumlah END AS qty
                                 FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id WHERE a.status <> 'Dibatalkan'),
     kb AS (SELECT k.proyek_id, GREATEST(0, k.jumlah - COALESCE((SELECT SUM(qty) FROM al WHERE al.kebutuhan_id = k.id), 0)) AS sisa FROM kebutuhan_proyek k)
SELECT p.kode, p.deadline_persiapan, p.mulai,
       (SELECT COUNT(*) FROM al WHERE al.proyek_id = p.id) AS total,
       (SELECT COUNT(*) FROM al WHERE al.proyek_id = p.id AND al.status = 'Dikonfirmasi' AND (al.sumber <> 'Rental' OR al.diterima_pada IS NOT NULL) AND al.diperiksa) AS selesai,
       (SELECT COALESCE(SUM(sisa), 0) FROM kb WHERE kb.proyek_id = p.id) AS kurang
FROM proyek p WHERE p.status NOT IN ('Selesai', 'Dibatalkan')";
$ringkas = (function () use ($kini) {
    global $pdo;
    $hasil = [];
    foreach ($pdo->query(SQL_RINGKAS) as $r) {
        $siap = $r['total'] > 0 && (int) $r['selesai'] === (int) $r['total'] && (int) $r['kurang'] === 0;
        $r['lewat'] = ($r['total'] > 0 || $r['kurang'] > 0) && !$siap && $r['deadline_persiapan'] < $kini;
        $hasil[$r['kode']] = $r;
    }
    return $hasil;
})();
$harap_lewat = urut(array_keys(array_filter($ringkas, fn ($r) => $r['lewat'])));
$harap_kurang = urut(array_keys(array_filter($ringkas, fn ($r) => (int) $r['kurang'] > 0)));
$harap_aktif = (int) satu("SELECT COUNT(*) FROM proyek WHERE status IN ('Dijadwalkan', 'Berlangsung')");
$harap_studio_luar = (int) satu("SELECT COALESCE(SUM(sisa), 0) FROM v_saldo_alokasi WHERE sumber = 'Studio' AND sisa > 0");
$harap_semua_luar = (int) satu('SELECT COALESCE(SUM(sisa), 0) FROM v_saldo_alokasi WHERE sisa > 0');
$harap_insiden = (int) satu("SELECT COUNT(*) FROM laporan_insiden WHERE status <> 'Selesai'");
$tanpa_serah = "NOT EXISTS (SELECT 1 FROM detail_transaksi d WHERE d.alokasi_id = a.id)";
$harap_rental_rencana = urut(kolom("SELECT p.kode FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id JOIN proyek p ON p.id = k.proyek_id
    WHERE a.sumber = 'Rental' AND a.status = 'Direncanakan' AND p.status NOT IN ('Selesai', 'Dibatalkan') AND $tanpa_serah"));
$harap_rental_terima = urut(kolom("SELECT p.kode FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id JOIN proyek p ON p.id = k.proyek_id
    WHERE a.sumber = 'Rental' AND a.status = 'Dikonfirmasi' AND a.diterima_pada IS NULL AND p.status NOT IN ('Selesai', 'Dibatalkan')
      AND p.mulai::date <= :b AND $tanpa_serah", ['b' => $segera14]));

echo "\n== D01 Dashboard Super Admin = PostgreSQL\n";
$h = minta('sa', SA, 'pages/dashboard-admin.php');
cek('D01a halaman tampil tanpa galat PHP/DB', bersih($h) && str_contains($h['html'], 'Perlu tindakan'));
cek('D01b Proyek aktif = jumlah proyek Dijadwalkan + Berlangsung (Draf/Selesai/Dibatalkan tidak dihitung)', kpi($h, 'data-kpi-proyek-aktif') === $harap_aktif, kpi($h, 'data-kpi-proyek-aktif') . ' vs ' . $harap_aktif);
cek('D01c Persiapan lewat batas = kueri SQL (aktif, belum Siap, batas < sekarang)', kpi($h, 'data-kpi-persiapan-lewat') === count($harap_lewat), kpi($h, 'data-kpi-persiapan-lewat') . ' vs ' . json_encode($harap_lewat));
cek('D01d Unit Studio belum kembali = saldo serah terima Studio (v_saldo_alokasi), bukan banyaknya alokasi', kpi($h, 'data-kpi-studio-di-proyek') === $harap_studio_luar, kpi($h, 'data-kpi-studio-di-proyek') . ' vs ' . $harap_studio_luar);
cek('D01e Insiden terbuka = laporan berstatus selain Selesai', (bool) preg_match('/Insiden terbuka<\/p>\s*<p class="kpi-angka[^"]*">' . $harap_insiden . '</', $h['html']), (string) $harap_insiden);
$kondisi_db = [];
foreach (kolom("SELECT kondisi || ':' || COUNT(*) FROM perlengkapan WHERE status_aktif = 'Aktif' GROUP BY kondisi") as $baris) {
    [$kk, $n] = explode(':', $baris);
    $kondisi_db[$kk] = (int) $n;
}
$cocok_kondisi = true;
foreach (['Baik', 'Maintenance', 'Rusak'] as $kk) {
    $cocok_kondisi = $cocok_kondisi && (bool) preg_match('/<p class="daftar-judul">' . $kk . '<\/p>.*?<span class="badge[^"]*">' . ($kondisi_db[$kk] ?? 0) . '<\/span>/s', $h['html']);
}
cek('D01f Kondisi aset Baik/Maintenance/Rusak = jumlah unit aktif per kondisi', $cocok_kondisi, json_encode($kondisi_db));
$aktivitas_db = kolom('SELECT keterangan FROM riwayat_aktivitas ORDER BY waktu DESC, id DESC LIMIT 1');
cek('D01g Aktivitas terbaru diambil dari riwayat_aktivitas (log terbaru tampil)', isset($aktivitas_db[0]) && str_contains($h['html'], htmlspecialchars($aktivitas_db[0], ENT_QUOTES, 'UTF-8')));
cek('D01h komponen statistik historis tidak lagi di dashboard (utilisasi, sumber alokasi, frekuensi, grafik mingguan)',
    !preg_match('/Utilisasi perlengkapan|Sumber alokasi|Frekuensi pemakaian|Barang keluar vs kembali/', $h['html']));

echo "\n== D04 Perlu tindakan sesuai data\n";
$butir = butir_tindakan($h);
cek('D04a "Persiapan lewat batas" = proyek yang persiapannya lewat (SQL)', kode_butir($butir, 'Persiapan lewat batas') === $harap_lewat, json_encode(kode_butir($butir, 'Persiapan lewat batas')) . ' vs ' . json_encode($harap_lewat));
cek('D04b "unit kebutuhan belum dialokasikan" = proyek aktif dengan kekurangan alokasi (SQL)', kode_butir($butir, '/unit kebutuhan belum dialokasikan$/') === $harap_kurang,
    json_encode(kode_butir($butir, '/unit kebutuhan belum dialokasikan$/')) . ' vs ' . json_encode($harap_kurang));
cek('D04c "Rental menunggu konfirmasi vendor" = rental Direncanakan di proyek aktif', kode_butir($butir, 'Rental menunggu konfirmasi vendor') === $harap_rental_rencana,
    json_encode(kode_butir($butir, 'Rental menunggu konfirmasi vendor')) . ' vs ' . json_encode($harap_rental_rencana));
cek('D04d "Rental belum diterima dari vendor" = rental Dikonfirmasi belum diterima, proyek mulai <= 14 hari', kode_butir($butir, 'Rental belum diterima dari vendor') === $harap_rental_terima,
    json_encode(kode_butir($butir, 'Rental belum diterima dari vendor')) . ' vs ' . json_encode($harap_rental_terima));
$tutup = kolom("SELECT kode FROM proyek WHERE status IN ('Selesai', 'Dibatalkan')");
$kode_semua = kode_butir($butir, '/./');
$kecuali_luar = array_merge(...array_map(fn ($b) => str_contains($b[0], 'belum kembali ke studio') ? (preg_match('/Masih di (PRJ-\d+)/', $b[1], $m) ? [$m[1]] : []) : [], $butir ?: [['', '']]));
cek('D04e tidak ada tindakan untuk proyek Selesai/Dibatalkan (kecuali asal unit yang belum kembali)', !array_diff(array_intersect($kode_semua, $tutup), $kecuali_luar), json_encode(array_intersect($kode_semua, $tutup)));
cek('D04f tidak ada peringatan "terlambat dikembalikan" (tanggal selesai acara bukan tenggat pengembalian)', !preg_grep('/terlambat dikembalikan|terlambat kembali/i', array_merge(...($butir ?: [[]]))));
$urutan_merah = array_map(fn ($x) => str_contains($x, 'badge-merah'), (function () use ($h) {
    preg_match('/<section class="kartu" data-perlu-tindakan>(.*?)<\/section>/s', $h['html'], $m);
    preg_match_all('/<span class="badge badge-(merah|kuning)[^"]*"/', $m[1] ?? '', $b);
    return $b[0];
})());
cek('D04g butir mendesak (merah) selalu di atas butir kuning', $urutan_merah === array_values(array_merge(array_filter($urutan_merah), array_filter($urutan_merah, fn ($x) => !$x))), json_encode($urutan_merah));

echo "\n== D02 Dashboard Petugas Inventaris\n";
$hp = minta('pi', PI, 'pages/dashboard-petugas.php');
cek('D02a halaman tampil tanpa galat', bersih($hp) && str_contains($hp['html'], 'Rental &amp; unit perlu ditindak'));
cek('D02b Barang belum kembali = saldo serah terima semua sumber (kuantitas)', kpi($hp, 'data-kpi-di-proyek') === $harap_semua_luar, kpi($hp, 'data-kpi-di-proyek') . ' vs ' . $harap_semua_luar);
$harap_terbuka = (int) satu("SELECT COUNT(*) FROM proyek p JOIN persiapan_proyek s ON s.proyek_id = p.id
    WHERE p.status NOT IN ('Selesai', 'Dibatalkan') AND p.selesai::date >= :h AND p.mulai::date <= :b AND s.dikonfirmasi_pada IS NULL
      AND NOT EXISTS (SELECT 1 FROM detail_transaksi d JOIN transaksi_barang t ON t.id = d.transaksi_id AND t.jenis = 'Keluar'
                      JOIN alokasi_barang a ON a.id = d.alokasi_id JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
                      WHERE k.proyek_id = p.id AND a.status <> 'Dibatalkan')", ['h' => HARI, 'b' => $segera14]);
cek('D02c Persiapan belum dikonfirmasi = proyek aktif dalam 14 hari tanpa konfirmasi dan belum ada barang keluar', kpi($hp, 'data-kpi-persiapan-terbuka') === $harap_terbuka,
    kpi($hp, 'data-kpi-persiapan-terbuka') . ' vs ' . $harap_terbuka);
$hs = minta('pi', PI, 'pages/serah-terima.php');
$siap_serah = preg_match('/Siap keluar<\/p><p class="kpi-angka">(\d+)</', $hs['html'], $m) ? (int) $m[1] : -1;
cek('D02d Siap diserahkan = KPI "Siap keluar" di halaman Serah Terima (definisi sama)', kpi($hp, 'data-kpi-siap-keluar') === $siap_serah, kpi($hp, 'data-kpi-siap-keluar') . ' vs ' . $siap_serah);
$unit_rusak = kolom("SELECT kode_unit FROM perlengkapan WHERE status_aktif = 'Aktif' AND kondisi <> 'Baik'");
cek('D02e daftar unit perlu ditindak = semua unit aktif berkondisi Maintenance/Rusak', !array_filter($unit_rusak, fn ($u) => !str_contains($hp['html'], '<span class="daftar-judul">' . $u . ' · ')),
    json_encode($unit_rusak));
cek('D02f dashboard Petugas tidak menampilkan tombol kelola proyek/pengguna (di luar RBAC Petugas)', !str_contains($hp['html'], 'dialog-proyek') && !str_contains($hp['html'], 'pages/pengguna.php'));

echo "\n== D03 Dashboard User/Kru hanya proyeknya sendiri\n";
$hu = minta('us', RIZKY, 'pages/dashboard-user.php');
$milik = kolom('SELECT kode FROM proyek WHERE pic_id = :u UNION SELECT p.kode FROM proyek p JOIN anggota_proyek a ON a.proyek_id = p.id WHERE a.pengguna_id = :v', ['u' => RIZKY, 'v' => RIZKY]);
$bukan = kolom('SELECT kode FROM proyek WHERE kode NOT IN (' . (implode(',', array_map(fn ($x) => $pdo->quote($x), $milik)) ?: "''") . ')');
preg_match_all('/PRJ-\d{3,}/', strip_tags($hu['html']), $m);
$tampil = urut($m[0]);
cek('D03a semua kode proyek yang tampil adalah proyek tempat Rizky menjadi PIC/kru', !array_diff($tampil, $milik) && $tampil, json_encode($tampil));
cek('D03b proyek orang lain tidak tampil', !array_intersect($tampil, $bukan), json_encode(array_intersect($tampil, $bukan)));
cek('D03c tidak ada informasi global inventaris/insiden/pengguna di dashboard User', bersih($hu) && !preg_match('/Insiden terbuka|Perlu tindakan|Kondisi aset|Barang belum kembali|Manajemen Pengguna/', $hu['html']));
$aktif_rizky = (int) satu("SELECT COUNT(*) FROM proyek WHERE kode = ANY (CAST(:k AS text[])) AND status NOT IN ('Selesai', 'Dibatalkan') AND selesai::date >= :h",
    ['k' => '{' . implode(',', $milik) . '}', 'h' => HARI]);
cek('D03d jumlah "Jadwal saya" = proyek aktif Rizky yang belum lewat', (bool) preg_match('/<h2>Jadwal saya<\/h2><p>' . $aktif_rizky . ' proyek aktif<\/p>/', $hu['html']), (string) $aktif_rizky);

echo "\n== D05 Tautan tindakan\n";
$masalah_tautan = [];
foreach ([[SA, $h], [PI, $hp], [RIZKY, $hu]] as [$uid, $hh]) {
    preg_match_all('/href="\/photoops\/(pages\/[a-z-]+\.php)(?:\?([^"#]*))?"/', $hh['html'], $tt, PREG_SET_ORDER);
    $dicek = [];
    foreach ($tt as $t) {
        $kunci = $t[1] . '?' . ($t[2] ?? '');
        if (isset($dicek[$kunci])) {
            continue;
        }
        $dicek[$kunci] = true;
        parse_str(html_entity_decode($t[2] ?? '', ENT_QUOTES), $get);
        $r = minta('tautan' . $uid, $uid, $t[1], $get);
        if (!bersih($r) || $r['html'] === '' || str_contains($r['pesan'], 'tidak termasuk hak akses') || str_contains($r['pesan'], 'tidak ditugaskan')) {
            $masalah_tautan[] = $uid . ':' . $kunci . ' ' . $r['pesan'];
        }
    }
}
cek('D05 setiap tautan di dashboard tiga peran membuka halaman yang boleh diakses peran itu tanpa galat', !$masalah_tautan, implode(' || ', $masalah_tautan));
cek('D05b tautan "Perlu tindakan" menuju tab yang menangani masalahnya', str_contains($h['html'], 'tab=persiapan') && str_contains($h['html'], 'tab=kebutuhan'));

echo "\n== D06 Status kosong\n";
jalankan("INSERT INTO pengguna (nama, username, email, password_hash, peran, jenis_anggota) VALUES ('UJIDB Tanpa Proyek', 'ujidb.kosong', 'ujidb.kosong@nicio.id', :h, 'us', 'Freelancer')",
    ['h' => password_hash('acak-' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT)]);
$id_kosong = (int) satu("SELECT id FROM pengguna WHERE username = 'ujidb.kosong'");
$hk = minta('kosong', $id_kosong, 'pages/dashboard-user.php');
cek('D06a User tanpa proyek: status kosong "Belum ada jadwal" dan "Tidak ada barang pribadi", tanpa galat', bersih($hk) && str_contains($hk['html'], 'Belum ada jadwal')
    && str_contains($hk['html'], 'Tidak ada barang pribadi') && !preg_match('/PRJ-\d{3,}/', strip_tags($hk['html'])));
jalankan("DELETE FROM pengguna WHERE id = :id", ['id' => $id_kosong]);

echo "\n== D07 Perubahan data tercermin setelah refresh (browser lain)\n";
// Unit Baik yang tidak sedang keluar; kondisinya dipulihkan saat pembersihan.
$unit = $pdo->query("SELECT p.id, p.kode_unit FROM perlengkapan p WHERE p.status_aktif = 'Aktif' AND p.kondisi = 'Baik'
    AND NOT EXISTS (SELECT 1 FROM v_saldo_alokasi s JOIN alokasi_barang a ON a.id = s.alokasi_id WHERE a.perlengkapan_id = p.id AND s.sisa > 0) ORDER BY p.id DESC LIMIT 1")->fetch();
$kondisi_asal[(int) $unit['id']] = 'Baik';
$sebelum = $harap_insiden;
minta('pi', PI, 'pages/perlengkapan.php', ['tab' => 'insiden'], ['aksi' => 'lapor_insiden', 'perlengkapan_id' => (string) $unit['id'], 'jenis' => 'Masalah Teknis',
    'kondisi' => 'Maintenance', 'proyek_id' => '', 'deskripsi' => 'UJIDB uji dashboard']);
$h2 = minta('sa2', SA, 'pages/dashboard-admin.php');
$hp2 = minta('pi2', PI, 'pages/dashboard-petugas.php');
cek('D07a insiden baru dari Petugas langsung menambah KPI Insiden terbuka di dashboard Super Admin (browser lain)',
    (bool) preg_match('/Insiden terbuka<\/p>\s*<p class="kpi-angka[^"]*">' . ($sebelum + 1) . '</', $h2['html']), (string) ($sebelum + 1));
cek('D07b unit yang dilaporkan muncul di antrean Petugas dan ringkasan Kondisi aset', str_contains($hp2['html'], '<span class="daftar-judul">' . $unit['kode_unit'] . ' · ')
    && (bool) preg_match('/<p class="daftar-judul">Maintenance<\/p>.*?<span class="badge[^"]*">' . (($kondisi_db['Maintenance'] ?? 0) + 1) . '<\/span>/s', $h2['html']));
$id_ins = (int) satu("SELECT id FROM laporan_insiden WHERE deskripsi = 'UJIDB uji dashboard' ORDER BY id DESC LIMIT 1");
minta('pi', PI, 'pages/perlengkapan.php', ['tab' => 'insiden'], ['aksi' => 'selesaikan_insiden', 'id' => (string) $id_ins, 'kondisi_akhir' => 'Baik']);
$h3 = minta('sa2', SA, 'pages/dashboard-admin.php');
cek('D07c setelah insiden diselesaikan (kondisi akhir Baik), KPI kembali ke angka semula', (bool) preg_match('/Insiden terbuka<\/p>\s*<p class="kpi-angka[^"]*">' . $sebelum . '</', $h3['html']));

echo "\n== D08 RBAC\n";
$r = minta('pi', PI, 'pages/dashboard-admin.php');
cek('D08a Petugas membuka dashboard Super Admin ditolak', str_contains($r['pesan'], 'tidak termasuk hak akses') && !str_contains($r['html'], 'data-kpi-proyek-aktif'), $r['pesan']);
$r = minta('us', RIZKY, 'pages/dashboard-admin.php');
cek('D08b User/Kru membuka dashboard Super Admin ditolak', str_contains($r['pesan'], 'tidak termasuk hak akses') && !str_contains($r['html'], 'data-kpi-proyek-aktif'), $r['pesan']);
// CLI tidak mencatat header Location, jadi pengalihan dibuktikan dari halaman yang tidak dirender sama sekali (arahkan() langsung exit).
$r = minta('us', RIZKY, 'pages/dashboard-petugas.php');
cek('D08c User/Kru membuka dashboard Petugas: dialihkan, tidak ada isi dashboard Petugas yang dirender', trim($r['html']) === '' && bersih($r));
$r = minta('sa', SA, 'pages/dashboard-petugas.php');
cek('D08d Super Admin membuka dashboard Petugas: dialihkan (dashboard per peran)', trim($r['html']) === '' && bersih($r));
$r = minta('anon', null, 'pages/dashboard-user.php');
cek('D08e tanpa login: tidak ada isi yang dirender dan diminta masuk', trim($r['html']) === '' && str_contains($r['pesan'], 'Silakan masuk'), $r['pesan']);
$jumlah_proyek = (int) satu('SELECT COUNT(*) FROM proyek');
minta('pi', PI, 'pages/dashboard-admin.php', [], ['aksi' => 'simpan_proyek', 'id' => '', 'nama' => 'UJIDB Ilegal', 'klien' => 'X', 'lokasi' => 'X', 'mulai' => '2027-12-01T08:00',
    'selesai' => '2027-12-01T10:00', 'deadline_persiapan' => '2027-11-30T08:00', 'pic_id' => (string) RIZKY, 'status' => 'Draf', 'catatan' => '']);
cek('D08f Petugas tidak bisa membuat proyek lewat POST ke dashboard Super Admin', (int) satu('SELECT COUNT(*) FROM proyek') === $jumlah_proyek);

echo "\n== D10 Halaman Riwayat dan galat\n";
$hr = minta('sa', SA, 'pages/riwayat.php');
$total_log = (int) satu('SELECT COUNT(*) FROM riwayat_aktivitas');
cek('D10a Riwayat tanpa grafik Barang keluar vs kembali; daftar aktivitas langsung setelah saringan', bersih($hr)
    && !preg_match('/Barang keluar vs kembali|class="grafik|data-tren-serah/', $hr['html'])
    && (bool) preg_match('/<\/form>\s*<section class="kartu">\s*<div class="kartu-kepala">\s*<div><h2>' . $total_log . ' aktivitas<\/h2>/', $hr['html']), (string) $total_log);
// Log Persiapan ditampilkan dengan teks olahan, jadi urutan diperiksa pada log lain: terbaru harus di atas yang terlama.
$baru = kolom("SELECT keterangan FROM riwayat_aktivitas WHERE aksi <> 'Persiapan' ORDER BY waktu DESC, id DESC LIMIT 1")[0];
$lama = kolom("SELECT keterangan FROM riwayat_aktivitas WHERE aksi <> 'Persiapan' ORDER BY waktu, id LIMIT 1")[0];
$pos_baru = strpos($hr['html'], htmlspecialchars($baru, ENT_QUOTES, 'UTF-8'));
$pos_lama = strpos($hr['html'], htmlspecialchars($lama, ENT_QUOTES, 'UTF-8'));
cek('D10b daftar Riwayat dari PostgreSQL urut terbaru di atas (log terbaru tampil sebelum log terlama)', $pos_baru !== false && $pos_lama !== false && $pos_baru < $pos_lama,
    json_encode([$baru, $lama]));
$n_insiden = (int) satu("SELECT COUNT(*) FROM riwayat_aktivitas WHERE aksi = 'Insiden'");
$hr = minta('sa', SA, 'pages/riwayat.php', ['aksi' => 'Insiden']);
cek('D10c saringan jenis aktivitas tetap berfungsi (jumlah = baris Insiden di PostgreSQL)', bersih($hr) && str_contains($hr['html'], '<h2>' . $n_insiden . ' aktivitas</h2>')
    && str_contains($hr['html'], 'disaring dari ' . $total_log), (string) $n_insiden);
$hr = minta('pi', PI, 'pages/riwayat.php', ['q' => 'TRX-043']);
cek('D10d pencarian tetap berfungsi dan Petugas tetap boleh membuka Riwayat', bersih($hr) && str_contains($hr['html'], 'TRX-043') && !str_contains($hr['html'], 'TRX-041'));
$hr = minta('us', RIZKY, 'pages/riwayat.php');
cek('D10e User/Kru tetap tidak boleh membuka Riwayat (RBAC tidak berubah)', trim($hr['html']) === '' && str_contains($hr['pesan'], 'tidak termasuk hak akses'), $hr['pesan']);
cek('D10f ketiga dashboard tanpa warning/notice/fatal', bersih($h) && bersih($hp) && bersih($hu) && bersih($h2) && bersih($hp2));

echo PHP_EOL . ($gagal ? "GAGAL: $gagal" : 'OK') . " ($lulus lulus, $gagal gagal)" . PHP_EOL;
exit($gagal ? 1 : 0);
