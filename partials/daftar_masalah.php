<?php
defined('ROOT') || exit;

$masalah = masalah_operasional();
$mendesak = count(array_filter($masalah, fn ($m) => $m['tingkat'] === 'merah'));
?>
<section class="kartu" data-perlu-tindakan>
<div class="kartu-kepala">
<div><h2>Perlu tindakan</h2><p><?= $masalah ? count($masalah) . ' hal butuh perhatian' . ($mendesak ? ', ' . $mendesak . ' mendesak' : '') . '. Urut dari yang paling mendesak.' : 'Tidak ada masalah operasional.' ?></p></div>
</div>
<?php if (!$masalah): ?>
<div class="kosong kosong-baik"><?= ikon('circle-check', 22) ?><div><p class="kosong-judul">Semua aman</p><p class="kosong-pesan">Kebutuhan teralokasi, persiapan sesuai batas, rental sudah ditangani, dan tidak ada unit yang menghalangi acara.</p></div></div>
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
<?= $m['tingkat'] === 'merah' ? badge('Terlambat', 'Mendesak') : badge('Menunggu', 'Perlu ditindak') ?>
</a>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
