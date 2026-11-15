<?php
define('ROOT', dirname(__DIR__));
define('HARI_INI', '2026-11-07');
define('VERSI_DATA', 2);

date_default_timezone_set('Asia/Jakarta');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('photoops');
    session_start();
}

require ROOT . '/includes/helpers.php';
require ROOT . '/includes/akses.php';
require ROOT . '/data/seed.php';

foreach (glob(ROOT . '/data/logic_*.php') as $berkas_logika) {
    require $berkas_logika;
}

if (!isset($_SESSION['db']) || ($_SESSION['db_versi'] ?? 0) !== VERSI_DATA) {
    $_SESSION['db'] = seed_data();
    $_SESSION['db_versi'] = VERSI_DATA;
}

// Sesudah POST yang benar-benar mengubah data, konfirmasi persiapan yang syaratnya tidak lagi terpenuhi dicabut secara terpusat.
if (is_post()) {
    $jejak_db_awal = md5(serialize($_SESSION['db']));
    register_shutdown_function(function () use ($jejak_db_awal) {
        if (isset($_SESSION['db']) && md5(serialize($_SESSION['db'])) !== $jejak_db_awal) {
            sinkron_konfirmasi_persiapan();
        }
    });
}
