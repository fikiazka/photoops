<?php
// Uji koneksi PostgreSQL (CLI): php tests/cek_koneksi_db.php
// Membaca config/database.local.php. Hanya membaca data; aplikasi PHP belum memakai koneksi ini.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$berkas = dirname(__DIR__) . '/config/database.local.php';
if (!is_file($berkas)) {
    echo "[GAGAL] config/database.local.php belum ada (salin dari config/database.example.php)\n";
    exit(1);
}
$k = require $berkas;
foreach (['PHOTOOPS_DB_NAME' => 'dbname'] as $env => $kunci) {
    if (getenv($env)) {
        $k[$kunci] = getenv($env);
    }
}

$gagal = 0;
$cek = function (string $nama, bool $lulus, string $info = '') use (&$gagal): void {
    echo ($lulus ? '[LULUS] ' : '[GAGAL] ') . $nama . ($info !== '' ? ' — ' . $info : '') . PHP_EOL;
    $gagal += $lulus ? 0 : 1;
};

try {
    $pdo = new PDO("pgsql:host={$k['host']};port={$k['port']};dbname={$k['dbname']}", $k['user'], $k['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $info = $pdo->query("SELECT current_database() AS db, current_user AS pengguna,
                                current_setting('is_superuser') AS superuser,
                                current_setting('TimeZone') AS zona,
                                (SELECT COUNT(*) FROM pengguna) AS total_pengguna")->fetch();
} catch (PDOException $e) {
    // Pesan PDO tidak memuat password.
    $cek('Koneksi PDO', false, $e->getMessage());
    exit(1);
}

$cek('Koneksi PDO', true, "pgsql {$k['host']}:{$k['port']}");
$cek("current_database() = {$k['dbname']}", $info['db'] === $k['dbname'], $info['db']);
$cek("current_user = {$k['user']}", $info['pengguna'] === $k['user'], $info['pengguna']);
$cek('Bukan superuser', $info['superuser'] === 'off');
$cek('Zona waktu database Asia/Jakarta', $info['zona'] === 'Asia/Jakarta', $info['zona']);
$cek('Membaca tabel pengguna', (int) $info['total_pengguna'] > 0, $info['total_pengguna'] . ' pengguna');

echo ($gagal ? 'GAGAL' : 'OK') . PHP_EOL;
exit($gagal ? 1 : 0);
