<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_login();
if ($pengguna['peran'] !== 'pi') {
    arahkan(beranda($pengguna['peran']));
}

$siap = proyek_siap_keluar();
$jumlah_siap = 0;
foreach ($siap as $s) {
    $jumlah_siap += count(array_filter($s['unit'], fn ($u) => $u['blokir'] === ''));
}
$belum_kembali = unit_belum_kembali();
$tempo_dekat = array_filter($belum_kembali, fn ($b) => $b['status'] !== 'Dipinjam');
$terbuka = insiden_terbuka();

$halaman = ['menu' => 'dashboard', 'bagian' => 'Petugas Inventaris', 'judul' => 'Dashboard'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Halo, ' . nama_depan($pengguna['nama']), tgl(HARI_INI, 'panjang') . ' · pekerjaan barang hari ini.');
?>
<div class="grid-kpi">
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>"><p class="kpi-label">Siap keluar</p><p class="kpi-angka"><?= $jumlah_siap ?></p><p class="kpi-ket">unit menunggu diserahkan</p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>"><p class="kpi-label">Sedang di luar</p><p class="kpi-angka teks-biru"><?= count($belum_kembali) ?></p><p class="kpi-ket">unit belum kembali</p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>"><p class="kpi-label">Tempo hari ini / telat</p><p class="kpi-angka<?= $tempo_dekat ? ' teks-merah' : '' ?>"><?= count($tempo_dekat) ?></p><p class="kpi-ket">perlu ditagih kembali</p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/perlengkapan.php?tab=insiden')) ?>"><p class="kpi-label">Insiden terbuka</p><p class="kpi-angka<?= $terbuka ? ' teks-kuning' : '' ?>"><?= count($terbuka) ?></p><p class="kpi-ket">unit sedang bermasalah</p></a>
</div>

<div class="grid-2">
<?php require ROOT . '/partials/daftar_masalah.php'; ?>
<?php require ROOT . '/partials/kesiapan_proyek.php'; ?>
</div>

<div class="grid-2">
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Jatuh tempo pengembalian</h2><p>Unit studio yang masih di luar.</p></div>
<a href="<?= e(url('pages/serah-terima.php')) ?>" class="tautan-kecil">Buka serah terima</a>
</div>
<?php if (!$belum_kembali): ?>
<?= kosong('Semua unit ada di studio', 'Tidak ada yang perlu ditagih.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($belum_kembali as $b): ?>
<?php $p = db_cari('proyek', $b['alokasi']['proyek_id']); ?>
<li class="daftar-baris">
<span class="ikon-bulat ikon-<?= $b['status'] === 'Terlambat' ? 'merah' : ($b['status'] === 'Hari ini' ? 'kuning' : 'abu') ?>"><?= ikon('clock', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e(label_unit($b['unit'])) ?></p>
<p class="daftar-sub"><?= e($p['kode'] ?? '?') ?> · tempo <?= e(tgl($b['alokasi']['tgl_selesai'], 'singkat')) ?></p>
</div>
<?= badge($b['status'], $b['status'] === 'Terlambat' ? 'Terlambat ' . hari_terlambat($b['alokasi']) . ' hari' : $b['status']) ?>
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
