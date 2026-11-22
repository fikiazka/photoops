<?php
define('ROOT', dirname(__DIR__));
define('HARI_INI', '2026-11-07');
// Versi 3: kategori dan unit inventaris pindah ke PostgreSQL. Data session lama diganti seed baru agar alokasi
// di session tidak menunjuk unit buatan session (id 24 dst.) yang tidak ada atau berbeda di PostgreSQL.
// Versi 4 (Sprint Backend 3A): proyek dan anggota proyek pindah ke PostgreSQL. Session di-seed ulang agar kebutuhan/alokasi
// session tidak menunjuk proyek buatan session (id 11 dst.) yang nanti dipakai proyek lain di PostgreSQL.
// Versi 5 (Sprint Backend 3C): kebutuhan dan alokasi pindah ke PostgreSQL. Checklist persiapan dan serah terima di session
// menyimpan id alokasi; session lama bisa berisi alokasi buatan session (id 41 dst.) yang di PostgreSQL adalah alokasi lain,
// jadi session di-seed ulang. Seed session diselaraskan dengan seed PostgreSQL (alokasi #41, TRX-044/045).
// Versi 6 (Sprint Backend 4A): serah terima (transaksi_barang, detail_transaksi) pindah ke PostgreSQL. Session lama bisa berisi
// transaksi buatan session (TRX-046 dst.) yang tidak ada di PostgreSQL, serta catatan insiden dan konfirmasi persiapan yang
// merujuk transaksi/detail itu; session di-seed ulang agar semua rujukan cocok dengan data PostgreSQL.
define('VERSI_DATA', 6);

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

// Data session untuk modul yang belum dimigrasikan: hanya Insiden (laporan insiden dan log aktivitas Insiden).
if (!isset($_SESSION['db']) || ($_SESSION['db_versi'] ?? 0) !== VERSI_DATA) {
    $_SESSION['db'] = seed_data();
    $_SESSION['db_versi'] = VERSI_DATA;
}
// Semua data bisnis lain hanya dibaca dari PostgreSQL. Salinan lama di session (pengguna, inventaris, proyek, preset,
// kebutuhan, alokasi, persiapan, serah terima) dibuang; log session untuk aksi yang kini di PostgreSQL juga dibuang agar
// tidak ada dua sumber data. Persiapan (Sprint 4B) tidak menaikkan VERSI_DATA: insiden yang sudah ada di session tetap utuh.
$_SESSION['db'] = array_intersect_key($_SESSION['db'], ['insiden' => true, 'riwayat' => true]) + ['insiden' => [], 'riwayat' => []];
$_SESSION['db']['riwayat'] = array_filter($_SESSION['db']['riwayat'], fn ($r) => !in_array($r['aksi'] ?? '', AKSI_RIWAYAT_DB, true));

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
