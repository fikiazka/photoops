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

// Data prototipe modul yang belum dimigrasikan (persiapan, insiden, log session) masih disimpan di session.
if (!isset($_SESSION['db']) || ($_SESSION['db_versi'] ?? 0) !== VERSI_DATA) {
    $_SESSION['db'] = seed_data();
    $_SESSION['db_versi'] = VERSI_DATA;
}
// Akun pengguna, kategori, unit inventaris, proyek, anggota proyek, preset, kebutuhan, alokasi, dan serah terima hanya dibaca
// dari PostgreSQL; salinannya di data session tidak dipakai. Preset (Sprint 3B) tidak memerlukan kenaikan VERSI_DATA karena
// tidak ada data session yang menyimpan id preset (kebutuhan dari preset berupa salinan nama/kategori/jumlah).
unset($_SESSION['db']['pengguna'], $_SESSION['db']['kategori'], $_SESSION['db']['perlengkapan'],
    $_SESSION['db']['proyek'], $_SESSION['db']['anggota_proyek'], $_SESSION['db']['preset'], $_SESSION['db']['detail_preset'],
    $_SESSION['db']['kebutuhan'], $_SESSION['db']['alokasi'], $_SESSION['db']['transaksi'], $_SESSION['db']['detail_transaksi']);

// Sesudah POST yang benar-benar mengubah data, konfirmasi persiapan yang syaratnya tidak lagi terpenuhi dicabut secara terpusat.
// Perubahan unit dan proyek/tim terjadi di PostgreSQL (tidak mengubah session), jadi ditandai lewat inventaris_berubah()
// dan proyek_berubah().
if (is_post()) {
    $jejak_db_awal = md5(serialize($_SESSION['db']));
    register_shutdown_function(function () use ($jejak_db_awal) {
        if (isset($_SESSION['db']) && (inventaris_berubah() || proyek_berubah() || md5(serialize($_SESSION['db'])) !== $jejak_db_awal)) {
            sinkron_konfirmasi_persiapan();
        }
    });
}
