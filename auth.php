<?php
require __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    arahkan('index.php');
}

cek_csrf();

$username = input('username');
$sandi = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$galat = [];

if ($username === '') {
    $galat['username'] = 'Nama pengguna wajib diisi.';
}
if ($sandi === '') {
    $galat['password'] = 'Kata sandi wajib diisi.';
}

$pengguna = null;
if (!$galat) {
    $pengguna = cari_pengguna_username($username);
    if (!$pengguna || !password_verify($sandi, $pengguna['password'])) {
        $galat['umum'] = 'Nama pengguna atau kata sandi salah.';
    } elseif ($pengguna['status'] !== 'Aktif') {
        $galat['umum'] = 'Akun ini dinonaktifkan. Hubungi Super Admin.';
    }
}

if ($galat) {
    simpan_form('', ['username' => $username], $galat);
    arahkan('index.php');
}

session_regenerate_id(true);
$_SESSION['user_id'] = $pengguna['id'];
arahkan(beranda($pengguna['peran']));
