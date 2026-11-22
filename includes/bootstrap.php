<?php
define('ROOT', dirname(__DIR__));
define('HARI_INI', '2026-11-07');

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

foreach (glob(ROOT . '/data/logic_*.php') as $berkas_logika) {
    require $berkas_logika;
}

set_exception_handler('tangani_galat_db');

// Sejak Sprint Backend 4C semua data bisnis (termasuk insiden dan riwayat) hanya ada di PostgreSQL. Session dipakai untuk
// login (user_id), CSRF, flash, isian form sesaat, dan sandi sementara. Salinan data prototipe dari versi lama
// ($_SESSION['db'], 'db_versi') dibuang bila masih ada di session browser.
unset($_SESSION['db'], $_SESSION['db_versi']);

// Sesudah POST yang mengubah data di PostgreSQL, konfirmasi persiapan yang syaratnya tidak lagi terpenuhi (misalnya unit
// menjadi Rusak atau rental dikembalikan ke vendor) dicabut secara terpusat. Perubahan ditandai lewat inventaris_berubah()
// dan proyek_berubah() (juga dipasang oleh perubahan kebutuhan, alokasi, persiapan, dan serah terima).
if (is_post()) {
    register_shutdown_function(function () {
        if (inventaris_berubah() || proyek_berubah()) {
            sinkron_konfirmasi_persiapan();
        }
    });
}
