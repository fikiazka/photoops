<?php
defined('ROOT') || exit;

$masalah = masalah_operasional();
?>
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Perlu tindakan</h2><p><?= $masalah ? count($masalah) . ' hal butuh perhatian sebelum acara berjalan.' : 'Tidak ada masalah operasional.' ?></p></div>
</div>
<?php if (!$masalah): ?>
<div class="kosong kosong-baik"><?= ikon('circle-check', 22) ?><div><p class="kosong-judul">Semua aman</p><p class="kosong-pesan">Kebutuhan teralokasi, persiapan tepat waktu, dan tidak ada unit bentrok.</p></div></div>
<?php else: ?>
<ul class="daftar">
<?php foreach ($masalah as $m): ?>
<li>
<a class="daftar-baris daftar-tautan" href="<?= e(url($m['tautan'])) ?>">
<span class="ikon-bulat ikon-<?= e($m['tingkat']) ?>"><?= ikon($m['ikon'], 16) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($m['judul']) ?></span>
<span class="daftar-sub"><?= e($m['keterangan']) ?></span>
</span>
<?= ikon('chevron-right', 16) ?>
</a>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
