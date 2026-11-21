<?php
require __DIR__ . '/includes/bootstrap.php';

// Hash pembanding untuk username yang tidak ada, agar waktu proses dan pesannya sama dengan password salah.
const HASH_PEMBANDING = '$2y$10$FVvRPR8lPBnziToeErCipOcUMNq25Y0jBln9Ozr7aTymUpyljNQn2';

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
    try {
        $akun = cari_akun_login($username);
        $cocok = password_verify($sandi, $akun['password_hash'] ?? HASH_PEMBANDING);
        if (!$akun || !$cocok) {
            $galat['umum'] = 'Nama pengguna atau kata sandi salah.';
        } elseif ($akun['status'] !== 'Aktif') {
            $galat['umum'] = 'Akun ini dinonaktifkan. Hubungi Super Admin.';
        } else {
            $pengguna = $akun;
        }
    } catch (Throwable $e) {
        if (!galat_db($e)) {
            throw $e;
        }
        $galat['umum'] = 'Layanan login sedang tidak tersedia. Coba beberapa saat lagi.';
    }
}

if ($galat) {
    simpan_form('', ['username' => $username], $galat);
    arahkan('index.php');
}

// Login berhasil: ganti ID session, simpan user_id saja (tanpa password/hash/peran).
session_regenerate_id(true);
unset($_SESSION['csrf'], $_SESSION['_form'], $_SESSION['_sandi_sementara']);
$_SESSION['user_id'] = $pengguna['id'];
arahkan(beranda($pengguna['peran']));
