<?php
define('ROOT', dirname(__DIR__));

date_default_timezone_set('Asia/Jakarta');

require ROOT . '/includes/konfigurasi.php';
terapkan_mode_error();

require ROOT . '/includes/helpers.php';
require ROOT . '/includes/koneksi.php';
require ROOT . '/includes/sesi.php';
require ROOT . '/includes/akses.php';

foreach (glob(ROOT . '/data/logic_*.php') as $berkas_logika) {
    require $berkas_logika;
}

// Dipasang sebelum tanggal dan session: galat konfigurasi atau database (termasuk saat membaca session PostgreSQL)
// berakhir dengan halaman 503 umum, bukan pesan teknis.
set_exception_handler('tangani_galat_db');

// Produksi: tanggal nyata; demo: PHOTOOPS_TANGGAL_DEMO; development lokal & uji: tanggal data contoh (includes/konfigurasi.php).
define('HARI_INI', tanggal_aplikasi());

// Session dimulai sesudah koneksi tersedia, karena mode produksi menyimpannya di PostgreSQL (includes/sesi.php).
mulai_sesi();

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
