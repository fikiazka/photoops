<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_login();
if ($pengguna['peran'] !== 'pi') {
    arahkan(beranda($pengguna['peran']));
}

// Antrean kerja Petugas Inventaris; definisi angka di data/logic_dashboard.php.
$tugas = tugas_petugas();
$di_proyek = ringkasan_barang_proyek();
$belum_kembali = $di_proyek['daftar'];
$insiden = kpi_insiden();

$halaman = ['menu' => 'dashboard', 'bagian' => 'Petugas Inventaris', 'judul' => 'Dashboard'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Halo, ' . nama_depan($pengguna['nama']), tgl(HARI_INI, 'panjang') . ' · antrean pekerjaan barang.');
?>
<div class="grid-kpi">
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>"><p class="kpi-label">Siap diserahkan</p><p class="kpi-angka" data-kpi-siap-keluar><?= $tugas['siap_unit'] ?></p><p class="kpi-ket"><?= $tugas['siap_unit'] ? 'unit dari ' . $tugas['siap_proyek'] . ' proyek terkonfirmasi, catat di Serah Terima' : 'belum ada barang yang siap keluar' ?></p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/proyek.php?status=aktif')) ?>"><p class="kpi-label">Persiapan belum dikonfirmasi</p><p class="kpi-angka<?= $tugas['persiapan_terbuka'] ? ' teks-kuning' : '' ?>" data-kpi-persiapan-terbuka><?= count($tugas['persiapan_terbuka']) ?></p><p class="kpi-ket">proyek berjalan atau mulai dalam 14 hari</p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>"><p class="kpi-label">Barang belum kembali</p><p class="kpi-angka<?= $di_proyek['unit'] ? ' teks-biru' : '' ?>" data-kpi-di-proyek><?= $di_proyek['unit'] ?></p><p class="kpi-ket"><?= $di_proyek['unit'] ? e(teks_per_sumber($di_proyek['per_sumber'])) . ' · saldo serah terima' : 'semua barang sudah kembali' ?></p></a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/perlengkapan.php?tab=insiden')) ?>"><p class="kpi-label">Insiden terbuka</p><p class="kpi-angka<?= $insiden['terbuka'] ? ' teks-kuning' : '' ?>"><?= $insiden['terbuka'] ?></p><p class="kpi-ket"><?= count($tugas['unit']) ?> unit Studio berkondisi Maintenance/Rusak</p></a>
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
<?= kosong('Tidak ada barang di proyek', 'Semua barang yang keluar sudah dicatat kembali.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($belum_kembali as $b): ?>
<?php $p = cari_proyek($b['alokasi']['proyek_id']); ?>
<li class="daftar-baris">
<span class="ikon-bulat ikon-abu"><?= ikon('package', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e($b['alokasi']['sumber'] === 'Studio' ? label_unit($b['unit']) : nama_barang_alokasi($b['alokasi']) . ' × ' . $b['jumlah']) ?></p>
<p class="daftar-sub"><?= e($b['alokasi']['sumber']) ?> · <?= e($p['kode'] ?? '?') ?><?= $p && !proyek_aktif($p) ? ' (' . e($p['status']) . ')' : '' ?> · jadwal selesai <?= e(tgl($b['alokasi']['tgl_selesai'], 'singkat')) ?><?= $b['jumlah_kembali'] > 0 ? ' · sisa ' . (int) $b['jumlah'] . ' dari ' . (int) $b['jumlah_keluar'] : '' ?></p>
</div>
<?= $b['jumlah_kembali'] > 0 ? badge('Kembali Sebagian', 'Kembali sebagian') : badge('Sedang Keluar', 'Masih di proyek') ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section class="kartu" data-tugas-rental-unit>
<div class="kartu-kepala">
<div><h2>Rental &amp; unit perlu ditindak</h2><p>Penerimaan dan pengembalian ke vendor, serta unit Studio yang belum layak pakai.</p></div>
</div>
<?php if (!$tugas['rental'] && !$tugas['unit']): ?>
<?= kosong('Tidak ada tindakan', 'Tidak ada rental yang menunggu dan semua unit aktif berkondisi Baik.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($tugas['rental'] as $r): ?>
<li>
<a class="daftar-baris daftar-tautan" href="<?= e(url('pages/perlengkapan.php?tab=eksternal')) ?>">
<span class="ikon-bulat ikon-kuning"><?= ikon('truck', 16) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($r['tindakan']) ?>: <?= e($r['alokasi']['nama_barang']) ?><?= jumlah_alokasi($r['alokasi']) > 1 ? ' × ' . jumlah_alokasi($r['alokasi']) : '' ?></span>
<span class="daftar-sub"><?= e($r['alokasi']['vendor']) ?> · <?= e($r['proyek']['kode'] ?? '?') ?><?= $r['info']['jatuh_tempo'] ? ' · tempo vendor ' . e(tgl($r['info']['jatuh_tempo'], 'singkat')) : '' ?></span>
</span>
<?= $r['info']['status_sewa'] !== '' && $r['info']['status_sewa'] !== 'Belum Dicatat' ? badge($r['info']['status_sewa']) : '' ?>
</a>
</li>
<?php endforeach; ?>
<?php foreach ($tugas['unit'] as $u): ?>
<li>
<a class="daftar-baris daftar-tautan" href="<?= e(url('pages/perlengkapan.php?tab=insiden')) ?>">
<span class="ikon-bulat ikon-merah"><?= ikon('wrench', 16) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e(label_unit($u['unit'])) ?></span>
<span class="daftar-sub"><?= $u['insiden'] ? 'Insiden ' . e(implode(', ', $u['insiden'])) : 'Tidak ada insiden terbuka; periksa lalu perbarui kondisinya' ?></span>
</span>
<?= badge($u['unit']['kondisi']) ?>
</a>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
</div>
<?php require ROOT . '/includes/layout_foot.php'; ?>
