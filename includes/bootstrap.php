<?php
define('ROOT', dirname(__DIR__));
define('HARI_INI', '2026-11-07');
// Versi 3: kategori dan unit inventaris pindah ke PostgreSQL. Data session lama diganti seed baru agar alokasi
// di session tidak menunjuk unit buatan session (id 24 dst.) yang tidak ada atau berbeda di PostgreSQL.
define('VERSI_DATA', 3);

date_default_timezone_set('Asia/Jakarta');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_name('photoops');
    session_start();
}

require ROOT . '/includes/helpers.php';
require ROOT . '/includes/koneksi.php';
require ROOT . '/includes/akses.php';
require ROOT . '/data/seed.php';

foreach (glob(ROOT . '/data/logic_*.php') as $berkas_logika) {
    require $berkas_logika;
}

set_exception_handler('tangani_galat_db');

// Data prototipe modul operasional (proyek, alokasi, serah terima, dll.) masih disimpan di session.
if (!isset($_SESSION['db']) || ($_SESSION['db_versi'] ?? 0) !== VERSI_DATA) {
    $_SESSION['db'] = seed_data();
    $_SESSION['db_versi'] = VERSI_DATA;
}
// Akun pengguna, kategori, dan unit inventaris hanya dibaca dari PostgreSQL; salinannya di data session tidak dipakai.
unset($_SESSION['db']['pengguna'], $_SESSION['db']['kategori'], $_SESSION['db']['perlengkapan']);

// Sesudah POST yang benar-benar mengubah data, konfirmasi persiapan yang syaratnya tidak lagi terpenuhi dicabut secara terpusat.
// Perubahan kondisi/status unit terjadi di PostgreSQL (tidak mengubah session), jadi ditandai lewat inventaris_berubah().
if (is_post()) {
    $jejak_db_awal = md5(serialize($_SESSION['db']));
    register_shutdown_function(function () use ($jejak_db_awal) {
        if (isset($_SESSION['db']) && (inventaris_berubah() || md5(serialize($_SESSION['db'])) !== $jejak_db_awal)) {
            sinkron_konfirmasi_persiapan();
        }
    });
}
