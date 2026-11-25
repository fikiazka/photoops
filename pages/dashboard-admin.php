<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('dashboard.admin');

if (is_post()) {
    cek_csrf();
    if (input('aksi') === 'simpan_proyek') {
        simpan_proyek_dari_form($pengguna);
    }
    muat_ulang();
}

// Definisi KPI: lihat data/logic_dashboard.php.
$kpi = kpi_proyek();
$di_proyek = ringkasan_barang_proyek();
$eksternal = $di_proyek['per_sumber']['Rental'] + $di_proyek['per_sumber']['Milik Kru'];
$insiden = kpi_insiden();
$kondisi = kondisi_aset();
$aktivitas = array_slice(daftar_riwayat(), 0, 6);

$halaman = ['menu' => 'dashboard', 'bagian' => 'Super Admin', 'judul' => 'Dashboard'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Selamat datang, ' . nama_depan($pengguna['nama']), tgl(HARI_INI, 'panjang') . ' · apa yang perlu perhatian di operasional studio.');
?>
<div class="grid-kpi">
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/proyek.php?status=aktif')) ?>">
<p class="kpi-label">Proyek aktif</p>
<p class="kpi-angka" data-kpi-proyek-aktif><?= $kpi['aktif'] ?></p>
<p class="kpi-ket"><?= $kpi['berlangsung'] ?> berlangsung · <?= $kpi['dijadwalkan'] ?> dijadwalkan<?= $kpi['draf'] ? ' · ' . $kpi['draf'] . ' draf belum dihitung' : '' ?></p>
</a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/proyek.php?status=aktif')) ?>">
<p class="kpi-label">Persiapan lewat batas</p>
<p class="kpi-angka<?= $kpi['lewat'] ? ' teks-merah' : '' ?>" data-kpi-persiapan-lewat><?= $kpi['lewat'] ?></p>
<p class="kpi-ket"><?= $kpi['lewat'] ? 'proyek melewati batas persiapan dan belum siap' : 'tidak ada persiapan yang terlambat' ?></p>
</a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>">
<p class="kpi-label">Unit Studio belum kembali</p>
<p class="kpi-angka<?= $di_proyek['unit_studio'] ? ' teks-biru' : '' ?>" data-kpi-studio-di-proyek><?= $di_proyek['unit_studio'] ?></p>
<p class="kpi-ket">unit fisik masih di proyek<?= $eksternal ? ' · ' . e(teks_per_sumber(['Rental' => $di_proyek['per_sumber']['Rental'], 'Milik Kru' => $di_proyek['per_sumber']['Milik Kru']])) . ' juga belum kembali' : '' ?></p>
</a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/perlengkapan.php?tab=insiden')) ?>">
<p class="kpi-label">Insiden terbuka</p>
<p class="kpi-angka<?= $insiden['terbuka'] ? ' teks-kuning' : '' ?>"><?= $insiden['terbuka'] ?></p>
<p class="kpi-ket"><?= $insiden['terbuka'] ? $insiden['Dilaporkan'] . ' belum ditangani · ' . $insiden['Ditangani'] . ' sedang ditangani' : 'tidak ada unit bermasalah' ?></p>
</a>
</div>

<div class="grid-2">
<?php require ROOT . '/partials/daftar_masalah.php'; ?>
<?php require ROOT . '/partials/kesiapan_proyek.php'; ?>
</div>

<div class="grid-2">
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Aktivitas terbaru</h2><p>Perubahan data terakhir dari seluruh modul.</p></div>
<a href="<?= e(url('pages/riwayat.php')) ?>" class="tautan-kecil">Semua riwayat</a>
</div>
<?php if (!$aktivitas): ?>
<?= kosong('Belum ada aktivitas', 'Aktivitas akan tercatat setelah ada perubahan data.') ?>
<?php else: ?>
<ul class="linimasa linimasa-ringkas">
<?php foreach ($aktivitas as $r): ?>
<li class="linimasa-item">
<span class="ikon-bulat ikon-abu"><?= ikon(ikon_aksi($r['aksi']), 14) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e($r['aksi']) ?> · <?= e($r['objek']) ?></p>
<p class="daftar-sub"><?= e($r['keterangan']) ?></p>
</div>
<span class="linimasa-waktu kecil redup"><?= e(tgl($r['waktu'], 'singkat_jam')) ?></span>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Kondisi aset</h2><p><?= $kondisi['Baik'] + $kondisi['Maintenance'] + $kondisi['Rusak'] ?> unit Studio aktif<?= $kondisi['nonaktif'] ? ' · ' . $kondisi['nonaktif'] . ' nonaktif' : '' ?>.</p></div>
<a href="<?= e(url('pages/perlengkapan.php')) ?>" class="tautan-kecil">Lihat inventaris</a>
</div>
<ul class="daftar">
<?php foreach (['Baik' => 'layak dialokasikan', 'Maintenance' => 'sedang/perlu diservis', 'Rusak' => 'tidak layak pakai'] as $k => $ket): ?>
<li class="daftar-baris">
<div class="daftar-utama"><p class="daftar-judul"><?= e($k) ?></p><p class="daftar-sub"><?= e($ket) ?></p></div>
<?= badge($k, (string) $kondisi[$k]) ?>
</li>
<?php endforeach; ?>
</ul>
</section>
</div>
<?php require ROOT . '/partials/modal_proyek.php'; ?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
