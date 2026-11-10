<?php
require __DIR__ . '/includes/bootstrap.php';

$pengguna = wajib_login();

if (!is_post()) {
    arahkan(beranda($pengguna['peran']));
}

cek_csrf();

$_SESSION['db'] = seed_data();

if (!db_cari('pengguna', $pengguna['id'])) {
    unset($_SESSION['user_id']);
    flash('info', 'Data demo direset. Silakan masuk kembali.');
    arahkan('index.php');
}

flash('sukses', 'Data demo dikembalikan ke kondisi awal.');
arahkan(beranda($pengguna['peran']));
