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
<?php
$prs = persiapan_proyek($p);
$gerak = ringkasan_barang_proyek((int) $p['id']);
$np_k = $gerak['unit'] || $gerak['pernah_keluar'] || $prs['lewat'] ? null : penilaian_persiapan($p);
$label_np = ['Persiapan Dikonfirmasi Selesai' => 'Dikonfirmasi', 'Siap untuk Dikonfirmasi' => 'Siap dikonfirmasi', 'Belum Siap' => 'Belum siap', 'Tidak Dikonfirmasi' => 'Tidak dikonfirmasi'];
?>
<li>
<a class="daftar-baris daftar-tautan" href="<?= e(url('pages/proyek-detail.php?id=' . $p['id'] . '&tab=persiapan')) ?>">
<span class="tanggal-blok"><span><?= e(date('j', strtotime($p['mulai']))) ?></span><span><?= e(nama_bulan((int) date('n', strtotime($p['mulai'])), true)) ?></span></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($p['kode']) ?> · <?= e($p['nama']) ?></span>
<span class="progres">
<?= bilah($prs['persen'], $prs['lewat'] ? 'merah' : ($prs['status'] === 'Siap' ? 'hijau' : 'tinta')) ?>
<span class="kecil"><?= $prs['selesai'] ?>/<?= $prs['total'] ?> diperiksa<?= $p['status'] !== 'Dijadwalkan' ? ' · ' . e($p['status']) : '' ?></span>
</span>
</span>
<?php if ($gerak['unit']): ?>
<?= badge('Sedang Keluar', 'Di proyek · ' . $gerak['unit'] . ' unit') ?>
<?php elseif ($gerak['pernah_keluar']): ?>
<?= badge('Sudah Kembali', 'Sudah kembali') ?>
<?php elseif ($prs['lewat']): ?>
<?= badge('Lewat deadline') ?>
<?php else: ?>
<?= badge($np_k['status'], $label_np[$np_k['status']] ?? $np_k['status']) ?>
<?php endif; ?>
</a>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
