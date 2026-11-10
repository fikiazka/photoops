<?php
defined('ROOT') || exit;

$halaman = $halaman ?? [];
$polos = !empty($halaman['polos']);
$pengguna = $polos ? null : wajib_login();
$modal_dibuka = modal_awal();
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($halaman['judul'] ?? 'Masuk') . ' · PhotoOps Nicio Studio') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= e(aset('css/app.css')) ?>">
</head>
<body class="<?= $polos ? 'halaman-polos' : 'halaman-app' ?>"<?= $modal_dibuka !== '' ? ' data-modal-awal="' . e($modal_dibuka) . '"' : '' ?>>
<?php if (!$polos): ?>
<a class="lewati" href="#konten">Lewati ke konten</a>
<div class="shell">
<?php require ROOT . '/includes/layout_sidebar.php'; ?>
<div class="shell-utama">
<?php require ROOT . '/includes/layout_topbar.php'; ?>
<main id="konten" class="konten" tabindex="-1">
<?php require ROOT . '/includes/flash.php'; ?>
<?php endif; ?>
