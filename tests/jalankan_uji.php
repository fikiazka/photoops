<?php
// Menjalankan seluruh suite uji pada database uji photoops_si3_test, dengan urutan yang benar.
//   php tests/jalankan_uji.php                 semua suite; uji_inventaris dilewati bila database uji bukan data contoh asli
//   php tests/jalankan_uji.php --reset         buat ulang photoops_si3_test dulu (database/pendukung/reset_db_uji.sql), lalu semua suite
//   php tests/jalankan_uji.php uji_proyek ...  hanya suite tertentu (boleh digabung dengan --reset)
//   php tests/jalankan_uji.php -v              tampilkan seluruh keluaran setiap suite
//
// --reset menjalankan psql sebagai superuser (bawaan: postgres; ubah lewat PHOTOOPS_PG_SUPERUSER, lokasi psql lewat PHOTOOPS_PSQL).
// Password superuser diminta psql (atau dibaca dari pgpass); tidak pernah ditulis di berkas atau argumen.
// Nama database uji ditulis tetap di sini dan di reset_db_uji.sql, sehingga runner ini tidak bisa mengenai database development.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const DB_UJI = 'photoops_si3_test';
// uji_inventaris harus pertama: ia mensyaratkan data contoh asli (23 unit). Suite lain membersihkan/mengarsipkan data ujinya sendiri.
const URUTAN = ['cek_koneksi_db', 'uji_inventaris', 'uji_proyek', 'uji_preset', 'uji_kebutuhan_alokasi', 'uji_serah_terima',
    'uji_persiapan', 'uji_insiden', 'uji_jadwal_proyek', 'uji_dashboard'];

$akar = dirname(__DIR__);
$arg = array_slice($argv, 1);
$reset = in_array('--reset', $arg, true);
$rinci = in_array('-v', $arg, true);
$pilih = array_values(array_diff($arg, ['--reset', '-v']));
$suite = $pilih ?: URUTAN;
foreach ($suite as $s) {
    if (!in_array($s, URUTAN, true)) {
        echo "[BERHENTI] Suite tidak dikenal: $s\n";
        exit(1);
    }
}

$k = require $akar . '/config/database.local.php';
echo 'Server ' . $k['host'] . ':' . $k['port'] . ', database uji ' . DB_UJI . PHP_EOL;

if ($reset) {
    $skrip = $akar . '/database/pendukung/reset_db_uji.sql';
    preg_match_all('/DROP DATABASE IF EXISTS (\w+)/', (string) file_get_contents($skrip), $m);
    if ($m[1] !== [DB_UJI]) {
        echo "[BERHENTI] reset_db_uji.sql tidak hanya menghapus " . DB_UJI . ".\n";
        exit(1);
    }
    $psql = getenv('PHOTOOPS_PSQL') ?: 'psql';
    $super = getenv('PHOTOOPS_PG_SUPERUSER') ?: 'postgres';
    $perintah = implode(' ', array_map('escapeshellarg', [$psql, '-h', $k['host'], '-p', (string) $k['port'], '-U', $super, '-d', 'postgres',
        '-v', 'ON_ERROR_STOP=1', '-q', '-f', $skrip]));
    echo "Membuat ulang " . DB_UJI . " ...\n";
    passthru($perintah, $kode);
    if ($kode !== 0) {
        echo "[BERHENTI] Reset database uji gagal (kode $kode).\n";
        exit(1);
    }
}

$pdo = new PDO("pgsql:host={$k['host']};port={$k['port']};dbname=" . DB_UJI, $k['user'], $k['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT current_database()')->fetchColumn() !== DB_UJI) {
    echo "[BERHENTI] Koneksi bukan ke " . DB_UJI . ".\n";
    exit(1);
}
$data_asli = (int) $pdo->query('SELECT COUNT(*) FROM perlengkapan')->fetchColumn() === 23;
$pdo = null;

putenv('PHOTOOPS_DB_NAME=' . DB_UJI);
$hasil = [];
foreach ($suite as $s) {
    if ($s === 'uji_inventaris' && !$data_asli) {
        echo "\n-- uji_inventaris DILEWATI: database uji bukan data contoh asli (sisa uji sebelumnya). Jalankan dengan --reset.\n";
        $hasil[$s] = 'DILEWATI';
        continue;
    }
    $mulai = microtime(true);
    $keluar = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $s . '.php') . ' 2>&1', $keluar, $kode);
    $ringkas = '';
    foreach ($keluar as $baris) {
        if (preg_match('/^(OK|GAGAL: \d+)( \(.*\))?$/', $baris)) {
            $ringkas = $baris;
        }
    }
    $hasil[$s] = ($kode === 0 ? 'LULUS' : 'GAGAL') . ' ' . ($ringkas ?: '(tanpa ringkasan)') . sprintf(' · %.0f dtk', microtime(true) - $mulai);
    echo "\n-- $s: {$hasil[$s]}\n";
    foreach ($keluar as $baris) {
        if ($rinci || preg_match('/^\[(GAGAL|BERHENTI)\]|PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $baris)) {
            echo '   ' . $baris . PHP_EOL;
        }
    }
}

echo "\nRingkasan:\n";
$gagal = 0;
foreach ($hasil as $s => $h) {
    printf("  %-24s %s\n", $s, $h);
    $gagal += str_starts_with($h, 'GAGAL') ? 1 : 0;
}
exit($gagal ? 1 : 0);
