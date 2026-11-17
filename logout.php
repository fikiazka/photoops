<?php
require __DIR__ . '/includes/bootstrap.php';

// Logout hanya lewat tombol Keluar (POST + CSRF), sehingga tidak bisa dipicu tautan dari situs lain.
if (!is_post()) {
    arahkan('index.php');
}

cek_csrf();

// Identitas login dihapus. Data prototipe modul operasional ($_SESSION['db']) tetap disimpan seperti sebelumnya.
unset($_SESSION['user_id'], $_SESSION['csrf'], $_SESSION['_form'], $_SESSION['_sandi_sementara']);
session_regenerate_id(true);
flash('info', 'Anda telah keluar.');
arahkan('index.php');
