<?php
defined('ROOT') || exit;

$ikon_flash = ['sukses' => 'circle-check', 'error' => 'triangle-alert', 'info' => 'info'];
?>
<?php foreach (ambil_flash() as $pesan_flash): ?>
<div class="pemberitahuan pemberitahuan-<?= e($pesan_flash['jenis']) ?>" role="<?= $pesan_flash['jenis'] === 'error' ? 'alert' : 'status' ?>">
<?= ikon($ikon_flash[$pesan_flash['jenis']] ?? 'info') ?>
<span><?= e($pesan_flash['pesan']) ?></span>
</div>
<?php endforeach; ?>
