<?php
require __DIR__ . '/includes/bootstrap.php';

unset($_SESSION['user_id']);
session_regenerate_id(true);
flash('info', 'Anda telah keluar.');
arahkan('index.php');
