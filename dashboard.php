<?php
require __DIR__ . '/includes/bootstrap.php';

$pengguna = wajib_login();
arahkan(beranda($pengguna['peran']));
