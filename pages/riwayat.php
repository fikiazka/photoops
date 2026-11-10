<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('riwayat.lihat');

$semua = daftar_riwayat();
$jenis = array_values(array_unique(array_column($semua, 'aksi')));
sort($jenis);
$f_aksi = query('aksi');
$f_oleh = query('oleh');
$kata = query('q');

$tampil = array_values(array_filter($semua, fn ($r) => ($f_aksi === '' || $r['aksi'] === $f_aksi)
    && ($f_oleh === '' || (string) $r['pengguna_id'] === $f_oleh)
    && cocok($kata, $r['objek'], $r['keterangan'], $r['aksi'])));

$pelaku = [];
foreach ($semua as $r) {
    if ($r['pengguna_id']) {
        $pelaku[$r['pengguna_id']] = nama_pengguna($r['pengguna_id']);
    }
}
asort($pelaku);

$per_hari = [];
foreach ($tampil as $r) {
    $per_hari[substr($r['waktu'], 0, 10)][] = $r;
}

$halaman = ['menu' => 'riwayat', 'bagian' => 'Laporan', 'judul' => 'Riwayat Aktivitas'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Riwayat Aktivitas', 'Jejak semua perubahan: proyek, alokasi, persiapan, serah terima, insiden, dan data master. Tidak bisa diubah.');
?>
<form method="get" class="kartu saring" role="search">
<div class="bidang">
<label for="saring-aksi">Jenis aktivitas</label>
<select id="saring-aksi" name="aksi" data-kirim-otomatis>
<?= opsi(array_combine($jenis, $jenis), $f_aksi, 'Semua aktivitas') ?>
</select>
</div>
<div class="bidang">
<label for="saring-oleh">Dilakukan oleh</label>
<select id="saring-oleh" name="oleh" data-kirim-otomatis>
<?= opsi($pelaku, $f_oleh, 'Semua orang') ?>
</select>
</div>
<div class="bidang bidang-lebar">
<label for="saring-cari">Cari</label>
<div class="cari cari-isi">
<?= ikon('search', 16) ?>
<input id="saring-cari" type="search" name="q" value="<?= e($kata) ?>" placeholder="Kode proyek, unit, atau keterangan">
</div>
</div>
<?php if ($f_aksi !== '' || $f_oleh !== '' || $kata !== ''): ?>
<a href="<?= e(url('pages/riwayat.php')) ?>" class="btn btn-sekunder btn-kecil saring-reset"><?= ikon('x', 16) ?><span>Reset</span></a>
<?php endif; ?>
</form>

<section class="kartu">
<div class="kartu-kepala">
<div><h2><?= count($tampil) ?> aktivitas</h2><p>Terbaru di atas<?= count($tampil) !== count($semua) ? ' · disaring dari ' . count($semua) : '' ?></p></div>
</div>
<?php if (!$tampil): ?>
<?= kosong('Tidak ada aktivitas yang cocok', 'Ubah saringan atau kata kunci.') ?>
<?php else: ?>
<?php foreach ($per_hari as $hari => $baris_hari): ?>
<h3 class="pemisah-hari"><?= e(tgl($hari, 'panjang')) ?></h3>
<ul class="linimasa">
<?php foreach ($baris_hari as $r): ?>
<li class="linimasa-item">
<span class="ikon-bulat ikon-abu"><?= ikon(ikon_aksi($r['aksi']), 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e($r['aksi']) ?> · <?= e($r['objek']) ?></p>
<p class="daftar-sub"><?= e($r['keterangan']) ?><?php if ($r['lama'] !== '' || $r['baru'] !== ''): ?> · <span class="perubahan"><?= e($r['lama'] !== '' ? $r['lama'] : '—') ?> → <?= e($r['baru'] !== '' ? $r['baru'] : '—') ?></span><?php endif; ?></p>
</div>
<div class="linimasa-waktu kecil redup"><?= e(tgl($r['waktu'], 'jam')) ?><br><?= e(nama_pengguna($r['pengguna_id'])) ?></div>
</li>
<?php endforeach; ?>
</ul>
<?php endforeach; ?>
<?php endif; ?>
</section>
<?php require ROOT . '/includes/layout_foot.php'; ?>
