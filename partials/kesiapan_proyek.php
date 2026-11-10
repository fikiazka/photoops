<?php
defined('ROOT') || exit;

$mendatang = proyek_mendatang(14);
?>
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Kesiapan proyek mendatang</h2><p>Acara yang berjalan atau mulai dalam 14 hari.</p></div>
<a href="<?= e(url('pages/proyek.php?status=aktif')) ?>" class="tautan-kecil">Semua proyek</a>
</div>
<?php if (!$mendatang): ?>
<?= kosong('Tidak ada acara dalam 14 hari', 'Proyek baru akan muncul di sini mendekati tanggalnya.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($mendatang as $p): ?>
<?php $prs = persiapan_proyek($p); ?>
<li>
<a class="daftar-baris daftar-tautan" href="<?= e(url('pages/proyek-detail.php?id=' . $p['id'] . '&tab=persiapan')) ?>">
<span class="tanggal-blok"><span><?= e(date('j', strtotime($p['mulai']))) ?></span><span><?= e(nama_bulan((int) date('n', strtotime($p['mulai'])), true)) ?></span></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($p['kode']) ?> · <?= e($p['nama']) ?></span>
<span class="progres">
<?= bilah($prs['persen'], $prs['lewat'] ? 'merah' : ($prs['persen'] === 100 ? 'hijau' : 'tinta')) ?>
<span class="kecil"><?= $prs['selesai'] ?>/<?= $prs['total'] ?></span>
</span>
</span>
<?= $prs['lewat'] ? badge('Lewat deadline') : badge($prs['total'] ? $prs['status'] : 'Belum Dimulai') ?>
</a>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
