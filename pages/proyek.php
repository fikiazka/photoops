<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('proyek.lihat');

if (is_post()) {
    cek_csrf();
    if (input('aksi') === 'simpan_proyek') {
        simpan_proyek_dari_form($pengguna);
    }
    muat_ulang();
}

$semua = proyek_terlihat($pengguna);
$saring = query('status', 'semua');
$kata = query('q');

$kelompok = [
    'semua' => ['label' => 'Semua', 'cek' => fn ($p) => true],
    'aktif' => ['label' => 'Aktif', 'cek' => fn ($p) => in_array($p['status'], ['Dijadwalkan', 'Berlangsung'], true)],
    'draf' => ['label' => 'Draf', 'cek' => fn ($p) => $p['status'] === 'Draf'],
    'selesai' => ['label' => 'Selesai', 'cek' => fn ($p) => $p['status'] === 'Selesai'],
    'batal' => ['label' => 'Dibatalkan', 'cek' => fn ($p) => $p['status'] === 'Dibatalkan'],
];
if (!isset($kelompok[$saring])) {
    $saring = 'semua';
}

$tampil = array_filter($semua, fn ($p) => $kelompok[$saring]['cek']($p) && cocok($kata, $p['nama'], $p['klien'], $p['kode'], $p['lokasi']));
$milik_user = $pengguna['peran'] === 'us';

$halaman = ['menu' => 'proyek', 'bagian' => 'Operasional', 'judul' => $milik_user ? 'Proyek Saya' : 'Proyek & Acara'];
require ROOT . '/includes/layout_head.php';

$aksi = boleh('proyek.kelola') ? tombol_modal('dialog-proyek', 'Proyek baru', ['_judul' => 'Proyek baru'], 'plus') : '';
judul_halaman(
    $milik_user ? 'Proyek Saya' : 'Proyek & Acara',
    $milik_user ? 'Proyek tempat Anda ditugaskan sebagai PIC atau kru.' : 'Jadwal acara, klien, PIC, dan kesiapan barang tiap proyek.',
    $aksi
);
?>
<section class="kartu">
<div class="kartu-kepala kartu-kepala-saring">
<nav class="chip-bar" aria-label="Saring status">
<?php foreach ($kelompok as $kunci => $info): ?>
<?php $jumlah = count(array_filter($semua, $info['cek'])); ?>
<a href="<?= e(url('pages/proyek.php?status=' . $kunci . ($kata !== '' ? '&q=' . urlencode($kata) : ''))) ?>" class="chip<?= $kunci === $saring ? ' aktif' : '' ?>"<?= $kunci === $saring ? ' aria-current="page"' : '' ?>>
<?= e($info['label']) ?> <span class="chip-angka"><?= $jumlah ?></span>
</a>
<?php endforeach; ?>
</nav>
<form method="get" class="cari" role="search">
<input type="hidden" name="status" value="<?= e($saring) ?>">
<?= ikon('search', 16) ?>
<label class="sr" for="cari-proyek">Cari proyek</label>
<input id="cari-proyek" type="search" name="q" value="<?= e($kata) ?>" placeholder="Cari nama, klien, kode">
</form>
</div>
<?php if (!$tampil): ?>
<?= kosong(
    $kata !== '' ? 'Tidak ada proyek yang cocok' : 'Belum ada proyek di kelompok ini',
    $kata !== '' ? 'Coba kata kunci lain atau pilih status Semua.' : ($milik_user ? 'Proyek akan muncul setelah Super Admin menugaskan Anda.' : 'Pilih status lain untuk melihat proyek lainnya.')
) ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead>
<tr>
<th>Proyek</th>
<th>Jadwal</th>
<th><?= $milik_user ? 'Peran Anda' : 'PIC' ?></th>
<th>Kesiapan barang</th>
<th>Status</th>
<th><span class="sr">Buka</span></th>
</tr>
</thead>
<tbody>
<?php foreach ($tampil as $p): ?>
<?php $persiapan = persiapan_proyek($p); ?>
<tr class="baris-tautan">
<td data-label="Proyek">
<a class="tautan-utama" href="<?= e(url('pages/proyek-detail.php?id=' . $p['id'])) ?>"><?= e($p['nama']) ?></a>
<div class="kecil redup"><?= e($p['kode']) ?> · <?= e($p['klien']) ?> · <?= e($p['lokasi']) ?></div>
</td>
<td data-label="Jadwal"><?= e(rentang_tgl($p['mulai'], $p['selesai'])) ?><div class="kecil redup"><?= e(tgl($p['mulai'], 'jam')) ?> WIB</div></td>
<td data-label="<?= $milik_user ? 'Peran Anda' : 'PIC' ?>">
<?php if ($milik_user): ?>
<?= badge(peran_di_proyek($p['id'], $pengguna['id'])) ?>
<?php else: ?>
<?= e(nama_pengguna($p['pic_id'])) ?>
<?php endif; ?>
</td>
<td data-label="Kesiapan">
<?php if ($persiapan['total'] === 0): ?>
<span class="kecil redup">Belum ada barang</span>
<?php else: ?>
<div class="progres">
<?= bilah($persiapan['persen'], $persiapan['lewat'] ? 'merah' : ($persiapan['persen'] === 100 ? 'hijau' : 'tinta')) ?>
<span class="kecil"><?= $persiapan['selesai'] ?>/<?= $persiapan['total'] ?></span>
</div>
<?php if ($persiapan['lewat']): ?><span class="kecil teks-merah">Lewat deadline</span><?php endif; ?>
<?php endif; ?>
</td>
<td data-label="Status"><?= badge($p['status']) ?></td>
<td class="kolom-aksi"><a href="<?= e(url('pages/proyek-detail.php?id=' . $p['id'])) ?>" class="tombol-ikon tombol-ikon-kecil" aria-label="Buka <?= e($p['nama']) ?>"><?= ikon('chevron-right', 16) ?></a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<?php if (boleh('proyek.kelola')) {
    require ROOT . '/partials/modal_proyek.php';
} ?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
