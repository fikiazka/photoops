<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_login();
if ($pengguna['peran'] !== 'pi') {
    arahkan(beranda($pengguna['peran']));
}

$siap = proyek_siap_keluar();
$jumlah_siap = array_sum(array_column($siap, 'bisa_unit'));
$di_proyek = ringkasan_barang_proyek();
$belum_kembali = $di_proyek['daftar'];
$terbuka = insiden_terbuka();

$halaman = ['menu' => 'dashboard', 'bagian' => 'Petugas Inventaris', 'judul' => 'Dashboard'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Halo, ' . nama_depan($pengguna['nama']), tgl(HARI_INI, 'panjang') . ' · pekerjaan barang hari ini.');
?>
<div class="grid-kpi">
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>"><p class="kpi-label">Siap keluar</p><p class="kpi-angka"><?= $jumlah_siap ?></p><p class="kpi-ket">unit menunggu diserahkan</p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>"><p class="kpi-label">Barang di proyek</p><p class="kpi-angka teks-biru" data-kpi-di-proyek><?= $di_proyek['unit'] ?></p><p class="kpi-ket"><?= $di_proyek['unit'] ? e(teks_per_sumber($di_proyek['per_sumber'])) . ' unit belum kembali' : 'semua barang sudah kembali' ?></p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>"><p class="kpi-label">Unit Studio di proyek</p><p class="kpi-angka" data-kpi-studio-di-proyek><?= $di_proyek['unit_studio'] ?></p><p class="kpi-ket"><?= $di_proyek['unit_studio'] ? 'unit fisik belum kembali, tidak tersedia untuk proyek lain' : 'semua unit Studio sudah kembali' ?></p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/perlengkapan.php?tab=insiden')) ?>"><p class="kpi-label">Insiden terbuka</p><p class="kpi-angka<?= $terbuka ? ' teks-kuning' : '' ?>"><?= count($terbuka) ?></p><p class="kpi-ket">unit sedang bermasalah</p></a>
</div>

<div class="grid-2">
<?php require ROOT . '/partials/daftar_masalah.php'; ?>
<?php require ROOT . '/partials/kesiapan_proyek.php'; ?>
</div>

<div class="grid-2">
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Barang masih di proyek</h2><p>Barang Studio, Rental, dan Milik Kru yang saldonya belum kembali. Tanggal adalah jadwal selesai proyek, bukan batas pengembalian.</p></div>
<a href="<?= e(url('pages/serah-terima.php')) ?>" class="tautan-kecil">Buka serah terima</a>
</div>
<?php if (!$belum_kembali): ?>
<?= kosong('Tidak ada barang di proyek', 'Tidak ada yang perlu ditagih.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($belum_kembali as $b): ?>
<?php $p = db_cari('proyek', $b['alokasi']['proyek_id']); ?>
<li class="daftar-baris">
<span class="ikon-bulat ikon-abu"><?= ikon('package', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e($b['alokasi']['sumber'] === 'Studio' ? label_unit($b['unit']) : nama_barang_alokasi($b['alokasi']) . ' × ' . $b['jumlah']) ?></p>
<p class="daftar-sub"><?= e($b['alokasi']['sumber']) ?> · <?= e($p['kode'] ?? '?') ?> · jadwal selesai <?= e(tgl($b['alokasi']['tgl_selesai'], 'singkat')) ?><?= $b['jumlah_kembali'] > 0 ? ' · sisa ' . (int) $b['jumlah'] . ' dari ' . (int) $b['jumlah_keluar'] : '' ?></p>
</div>
<?= $b['jumlah_kembali'] > 0 ? badge('Kembali Sebagian', 'Kembali sebagian') : badge('Sedang Keluar', 'Masih di proyek') ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section class="kartu">
<div class="kartu-kepala"><div><h2>Pintasan</h2><p>Pekerjaan yang paling sering dilakukan.</p></div></div>
<div class="pintasan">
<a href="<?= e(url('pages/serah-terima.php')) ?>" class="pintasan-item"><?= ikon('arrow-left-right', 20) ?><span><strong>Serah terima</strong><span class="kecil redup">Barang keluar dan kembali</span></span></a>
<a href="<?= e(url('pages/proyek.php?status=aktif')) ?>" class="pintasan-item"><?= ikon('package', 20) ?><span><strong>Alokasi proyek</strong><span class="kecil redup">Penuhi kebutuhan proyek aktif</span></span></a>
<a href="<?= e(url('pages/perlengkapan.php')) ?>" class="pintasan-item"><?= ikon('camera', 20) ?><span><strong>Perlengkapan</strong><span class="kecil redup">Unit, kondisi, dan insiden</span></span></a>
<a href="<?= e(url('pages/preset.php')) ?>" class="pintasan-item"><?= ikon('layers', 20) ?><span><strong>Preset</strong><span class="kecil redup">Paket kebutuhan per acara</span></span></a>
</div>
</section>
</div>
<?php require ROOT . '/includes/layout_foot.php'; ?>
