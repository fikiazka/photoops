<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_login();
if ($pengguna['peran'] !== 'us') {
    arahkan(beranda($pengguna['peran']));
}

$proyek_saya = proyek_terlihat($pengguna);
$akan_datang = array_values(array_filter($proyek_saya, fn ($p) => proyek_aktif($p) && substr($p['selesai'], 0, 10) >= HARI_INI));
$berikut = $akan_datang[0] ?? null;
$lalu = array_values(array_filter($proyek_saya, fn ($p) => !in_array($p, $akan_datang, true)));

$bawaan = [];
foreach (db('alokasi') as $a) {
    if ($a['sumber'] === 'Milik Kru' && (int) $a['pemilik_id'] === (int) $pengguna['id'] && $a['status'] !== 'Dibatalkan') {
        $p = db_cari('proyek', $a['proyek_id']);
        if ($p && proyek_aktif($p)) {
            $bawaan[] = ['alokasi' => $a, 'proyek' => $p];
        }
    }
}

$halaman = ['menu' => 'dashboard', 'bagian' => 'User', 'judul' => 'Dashboard'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Halo, ' . nama_depan($pengguna['nama']), tgl(HARI_INI, 'panjang') . ' · jadwal dan kesiapan proyek Anda.');
?>
<?php if ($berikut): ?>
<?php $prs = persiapan_proyek($berikut); $peran = peran_di_proyek($berikut['id'], $pengguna['id']); ?>
<section class="kartu sorot">
<div class="sorot-kepala">
<div>
<p class="label-kecil"><?= substr($berikut['mulai'], 0, 10) <= HARI_INI ? 'Sedang berjalan' : 'Proyek berikutnya' ?></p>
<h2><?= e($berikut['nama']) ?></h2>
<div class="meta">
<span><?= ikon('calendar-days', 16) ?><?= e(tgl($berikut['mulai'], 'lengkap')) ?></span>
<span><?= ikon('map-pin', 16) ?><?= e($berikut['lokasi']) ?></span>
<span><?= ikon('user', 16) ?>PIC <?= e(nama_pengguna($berikut['pic_id'])) ?></span>
</div>
</div>
<div class="aksi-baris">
<?= badge($peran, 'Anda: ' . $peran) ?>
<?= badge($berikut['status']) ?>
</div>
</div>
<div class="sorot-isi">
<div>
<p class="label-kecil">Kesiapan barang</p>
<div class="progres progres-besar">
<?= bilah($prs['persen'], $prs['lewat'] ? 'merah' : ($prs['persen'] === 100 ? 'hijau' : 'tinta')) ?>
<span><strong><?= $prs['selesai'] ?></strong> dari <?= $prs['total'] ?> diperiksa</span>
</div>
<p class="kecil redup"><?= $prs['persen'] === 100 ? 'Semua barang sudah dicek petugas.' : 'Petugas Inventaris masih menyiapkan barang. Batas ' . e(tgl($berikut['deadline_persiapan'], 'singkat_jam')) . '.' ?></p>
</div>
<a href="<?= e(url('pages/proyek-detail.php?id=' . $berikut['id'])) ?>" class="btn btn-sekunder"><span>Lihat detail proyek</span><?= ikon('chevron-right', 16) ?></a>
</div>
</section>
<?php endif; ?>

<div class="grid-2">
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Jadwal saya</h2><p><?= count($akan_datang) ?> proyek aktif</p></div>
<a href="<?= e(url('pages/proyek.php')) ?>" class="tautan-kecil">Semua proyek saya</a>
</div>
<?php if (!$akan_datang): ?>
<?= kosong('Belum ada jadwal', 'Proyek akan muncul di sini setelah Super Admin menugaskan Anda.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($akan_datang as $p): ?>
<li>
<a class="daftar-baris daftar-tautan" href="<?= e(url('pages/proyek-detail.php?id=' . $p['id'])) ?>">
<span class="tanggal-blok"><span><?= e(date('j', strtotime($p['mulai']))) ?></span><span><?= e(nama_bulan((int) date('n', strtotime($p['mulai'])), true)) ?></span></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($p['nama']) ?></span>
<span class="daftar-sub"><?= e($p['kode']) ?> · <?= e($p['lokasi']) ?> · <?= e(tgl($p['mulai'], 'jam')) ?> WIB</span>
</span>
<?= badge(peran_di_proyek($p['id'], $pengguna['id'])) ?>
</a>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Barang yang Anda bawa</h2><p>Dicatat petugas sebagai barang milik Anda.</p></div>
</div>
<?php if (!$bawaan): ?>
<?= kosong('Tidak ada barang pribadi', 'Jika Anda meminjamkan barang sendiri, petugas akan mencatatnya di proyek.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($bawaan as $b): ?>
<li class="daftar-baris">
<span class="ikon-bulat ikon-biru"><?= ikon('user', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e($b['alokasi']['nama_barang']) ?></p>
<p class="daftar-sub"><?= e($b['proyek']['kode']) ?> · <?= e(rentang_tgl($b['alokasi']['tgl_mulai'], $b['alokasi']['tgl_selesai'])) ?></p>
</div>
<?= badge($b['alokasi']['status']) ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
</div>

<?php if ($lalu): ?>
<section class="kartu">
<div class="kartu-kepala"><div><h2>Proyek sebelumnya</h2><p><?= count($lalu) ?> proyek</p></div></div>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Proyek</th><th>Tanggal</th><th>Peran</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($lalu as $p): ?>
<tr>
<td data-label="Proyek"><a class="tautan-utama" href="<?= e(url('pages/proyek-detail.php?id=' . $p['id'])) ?>"><?= e($p['nama']) ?></a><div class="kecil redup"><?= e($p['kode']) ?> · <?= e($p['klien']) ?></div></td>
<td data-label="Tanggal"><?= e(rentang_tgl($p['mulai'], $p['selesai'])) ?></td>
<td data-label="Peran"><?= badge(peran_di_proyek($p['id'], $pengguna['id'])) ?></td>
<td data-label="Status"><?= badge($p['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
<?php endif; ?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
