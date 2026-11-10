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

$proyek_semua = db('proyek');
$proyek_jalan = array_filter($proyek_semua, fn ($p) => in_array($p['status'], ['Dijadwalkan', 'Berlangsung'], true));
$proyek_draf = array_filter($proyek_semua, fn ($p) => $p['status'] === 'Draf');
$belum_kembali = unit_belum_kembali();
$terlambat = array_filter($belum_kembali, fn ($b) => $b['status'] === 'Terlambat');
$terbuka = insiden_terbuka();
$lewat = array_filter($proyek_semua, fn ($p) => persiapan_proyek($p)['lewat']);

$per_kategori = pemakaian_per_kategori();
$ringkas = ringkasan_pemakaian();
$total_unit = array_sum($ringkas);

$sumber = array_fill_keys(daftar_sumber(), 0);
foreach (db('alokasi') as $a) {
    if ($a['status'] !== 'Dibatalkan') {
        $sumber[$a['sumber']]++;
    }
}
$total_sumber = max(1, array_sum($sumber));
$batas_a = persen($sumber['Studio'], $total_sumber);
$batas_b = $batas_a + persen($sumber['Rental'], $total_sumber);

$tren = tren_mingguan(6);
$tren_maks = max(1, ...array_values(array_map(fn ($t) => max($t['Keluar'], $t['Kembali']), $tren)));

$kondisi = array_fill_keys(daftar_kondisi(), 0);
foreach (db('perlengkapan') as $u) {
    if ($u['status_aktif'] === 'Aktif') {
        $kondisi[$u['kondisi']]++;
    }
}
$total_kondisi = max(1, array_sum($kondisi));

$frekuensi = frekuensi_pakai();
arsort($frekuensi);
$sering = array_slice($frekuensi, 0, 5, true);
$jarang = $frekuensi;
asort($jarang);
$jarang = array_slice($jarang, 0, 5, true);
$frek_maks = max(1, ...array_values($frekuensi ?: [1]));

$aktivitas = array_slice(daftar_riwayat(), 0, 7);
$terlambat_historis = pengembalian_terlambat_historis();

$halaman = ['menu' => 'dashboard', 'bagian' => 'Super Admin', 'judul' => 'Dashboard'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Selamat datang, ' . nama_depan($pengguna['nama']), tgl(HARI_INI, 'panjang') . ' · ringkasan operasional studio dan perlengkapan.', tombol_modal('dialog-proyek', 'Proyek baru', ['_judul' => 'Proyek baru'], 'plus'));
?>
<div class="grid-kpi grid-kpi-5">
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/proyek.php?status=aktif')) ?>">
<p class="kpi-label">Proyek aktif</p>
<p class="kpi-angka"><?= count($proyek_jalan) ?></p>
<p class="kpi-ket"><?= count($proyek_draf) ?> masih draf</p>
</a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>">
<p class="kpi-label">Unit sedang keluar</p>
<p class="kpi-angka teks-biru"><?= count($belum_kembali) ?></p>
<p class="kpi-ket">dari <?= $total_unit ?> unit studio</p>
</a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/serah-terima.php')) ?>">
<p class="kpi-label">Terlambat kembali</p>
<p class="kpi-angka<?= $terlambat ? ' teks-merah' : '' ?>"><?= count($terlambat) ?></p>
<p class="kpi-ket"><?= $terlambat_historis ?> unit pernah telat kembali</p>
</a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/perlengkapan.php?tab=insiden')) ?>">
<p class="kpi-label">Insiden terbuka</p>
<p class="kpi-angka<?= $terbuka ? ' teks-kuning' : '' ?>"><?= count($terbuka) ?></p>
<p class="kpi-ket">rusak atau maintenance</p>
</a>
<a class="kartu kpi kpi-tautan" href="<?= e(url('pages/proyek.php?status=aktif')) ?>">
<p class="kpi-label">Persiapan lewat deadline</p>
<p class="kpi-angka<?= $lewat ? ' teks-merah' : '' ?>"><?= count($lewat) ?></p>
<p class="kpi-ket">proyek belum siap</p>
</a>
</div>

<div class="grid-2">
<?php require ROOT . '/partials/daftar_masalah.php'; ?>
<?php require ROOT . '/partials/kesiapan_proyek.php'; ?>
</div>

<div class="grid-2">
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Utilisasi perlengkapan</h2><p>Status <?= $total_unit ?> unit per kategori, hari ini.</p></div>
<div class="legenda">
<span><i class="titik titik-hijau"></i>Tersedia</span>
<span><i class="titik titik-biru"></i>Dipinjam</span>
<span><i class="titik titik-merah"></i>Tidak tersedia</span>
</div>
</div>
<div class="kartu-isi">
<ul class="batang-tumpuk-daftar">
<?php foreach ($per_kategori as $k): ?>
<li>
<div class="batang-tumpuk-label"><span><?= e($k['nama']) ?></span><span class="redup"><?= $k['Dipinjam'] ?>/<?= $k['total'] ?> dipakai</span></div>
<div class="batang-tumpuk" role="img" aria-label="<?= e($k['nama'] . ': ' . $k['Tersedia'] . ' tersedia, ' . $k['Dipinjam'] . ' dipinjam, ' . $k['Tidak Tersedia'] . ' tidak tersedia') ?>">
<?php foreach (['Tersedia' => 'hijau', 'Dipinjam' => 'biru', 'Tidak Tersedia' => 'merah'] as $s => $w): ?>
<?php if ($k[$s] > 0): ?><span class="seg seg-<?= $w ?>" style="width:<?= persen($k[$s], $k['total']) ?>%"><?= $k[$s] ?></span><?php endif; ?>
<?php endforeach; ?>
</div>
</li>
<?php endforeach; ?>
</ul>
</div>
</section>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Sumber alokasi</h2><p>Seberapa bergantung proyek pada rental.</p></div>
</div>
<div class="kartu-isi donat-wrap">
<div class="donat" style="--a:<?= $batas_a ?>%;--b:<?= $batas_b ?>%" role="img" aria-label="<?= e('Studio ' . $sumber['Studio'] . ', Rental ' . $sumber['Rental'] . ', Milik Kru ' . $sumber['Milik Kru']) ?>">
<div class="donat-lubang"><strong><?= persen($sumber['Studio'], $total_sumber) ?>%</strong><span>dari studio</span></div>
</div>
<ul class="donat-legenda">
<?php foreach (['Studio' => 'tinta', 'Rental' => 'kuning', 'Milik Kru' => 'biru'] as $s => $w): ?>
<li><i class="titik titik-<?= $w ?>"></i><span><?= e($s) ?></span><strong><?= $sumber[$s] ?></strong><span class="redup"><?= persen($sumber[$s], $total_sumber) ?>%</span></li>
<?php endforeach; ?>
</ul>
</div>
</section>
</div>

<div class="grid-2">
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Barang keluar vs kembali</h2><p>Jumlah unit per minggu, 6 minggu terakhir.</p></div>
<div class="legenda">
<span><i class="titik titik-tinta"></i>Keluar</span>
<span><i class="titik titik-hijau"></i>Kembali</span>
</div>
</div>
<div class="kartu-isi">
<div class="grafik" role="img" aria-label="Grafik unit keluar dan kembali per minggu">
<?php foreach ($tren as $t): ?>
<div class="grafik-kolom">
<div class="grafik-batang">
<span class="batang batang-tinta" style="height:<?= persen($t['Keluar'], $tren_maks) ?>%" title="Keluar <?= $t['Keluar'] ?>"><em><?= $t['Keluar'] ?: '' ?></em></span>
<span class="batang batang-hijau" style="height:<?= persen($t['Kembali'], $tren_maks) ?>%" title="Kembali <?= $t['Kembali'] ?>"><em><?= $t['Kembali'] ?: '' ?></em></span>
</div>
<span class="grafik-label"><?= e($t['label']) ?></span>
</div>
<?php endforeach; ?>
</div>
</div>
</section>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Kondisi aset</h2><p><?= $total_kondisi ?> unit aktif.</p></div>
<a href="<?= e(url('pages/perlengkapan.php?tab=insiden')) ?>" class="tautan-kecil">Lihat insiden</a>
</div>
<div class="kartu-isi">
<ul class="metrik-daftar">
<?php foreach (['Baik' => 'hijau', 'Maintenance' => 'kuning', 'Rusak' => 'merah'] as $s => $w): ?>
<li>
<div class="batang-tumpuk-label"><span><?= e($s) ?></span><strong><?= $kondisi[$s] ?></strong></div>
<?= bilah(persen($kondisi[$s], $total_kondisi), $w) ?>
</li>
<?php endforeach; ?>
</ul>
</div>
</section>
</div>

<div class="grid-2">
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Frekuensi pemakaian unit</h2><p>Jumlah alokasi per unit sejak data dicatat.</p></div>
</div>
<div class="kartu-isi grid-2-rata grid-dalam">
<div>
<p class="label-kecil">Paling sering</p>
<ul class="metrik-daftar">
<?php foreach ($sering as $id => $n): ?>
<?php $u = db_cari('perlengkapan', $id); ?>
<li>
<div class="batang-tumpuk-label"><span><?= e($u['kode_unit'] ?? '?') ?> <span class="redup"><?= e($u['nama'] ?? '') ?></span></span><strong><?= $n ?>×</strong></div>
<?= bilah(persen($n, $frek_maks), 'tinta') ?>
</li>
<?php endforeach; ?>
</ul>
</div>
<div>
<p class="label-kecil">Paling jarang</p>
<ul class="metrik-daftar">
<?php foreach ($jarang as $id => $n): ?>
<?php $u = db_cari('perlengkapan', $id); ?>
<li>
<div class="batang-tumpuk-label"><span><?= e($u['kode_unit'] ?? '?') ?> <span class="redup"><?= e($u['nama'] ?? '') ?></span></span><strong><?= $n ?>×</strong></div>
<?= bilah(persen($n, $frek_maks), 'abu') ?>
</li>
<?php endforeach; ?>
</ul>
</div>
</div>
</section>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Aktivitas terbaru</h2><p>Persiapan, serah terima, insiden, dan perubahan data.</p></div>
<a href="<?= e(url('pages/riwayat.php')) ?>" class="tautan-kecil">Semua riwayat</a>
</div>
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
</section>
</div>
<?php require ROOT . '/partials/modal_proyek.php'; ?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
