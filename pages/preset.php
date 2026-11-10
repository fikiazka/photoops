<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('preset.lihat');

$daftar = array_values(db('preset'));
usort($daftar, fn ($a, $b) => strcmp($a['nama'], $b['nama']));
$terpilih = db_cari('preset', (int) query('id')) ?? ($daftar[0] ?? null);

if (is_post()) {
    cek_csrf();
    wajib_boleh('preset.kelola');
    $aksi = input('aksi');
    $waktu = date('Y-m-d H:i', strtotime(sekarang()));

    if ($aksi === 'simpan_preset') {
        $id = (int) input('id');
        $data = ['id' => $id ?: '', 'nama' => input('nama'), 'deskripsi' => input('deskripsi')];
        $galat = [];
        if (mb_strlen($data['nama']) < 3) {
            $galat['nama'] = 'Nama preset minimal 3 karakter.';
        }
        foreach (db('preset') as $p) {
            if ((int) $p['id'] !== $id && mb_strtolower($p['nama']) === mb_strtolower($data['nama'])) {
                $galat['nama'] = 'Nama preset sudah dipakai.';
            }
        }
        if ($galat) {
            simpan_form('dialog-preset', $data, $galat);
            muat_ulang();
        }
        if ($id && db_cari('preset', $id)) {
            db_ubah('preset', $id, ['nama' => $data['nama'], 'deskripsi' => $data['deskripsi'], 'diubah_pada' => $waktu]);
            catat_riwayat('Preset', $data['nama'], 'Preset diperbarui');
            flash('sukses', 'Preset ' . $data['nama'] . ' diperbarui.');
            muat_ulang();
        }
        $baru = db_tambah('preset', ['nama' => $data['nama'], 'deskripsi' => $data['deskripsi'], 'dibuat_oleh' => $pengguna['id'], 'diubah_pada' => $waktu]);
        catat_riwayat('Preset', $data['nama'], 'Preset baru dibuat');
        flash('sukses', 'Preset ' . $data['nama'] . ' dibuat. Tambahkan item di dalamnya.');
        arahkan('pages/preset.php?id=' . $baru);
    }

    if ($aksi === 'hapus_preset') {
        $p = db_cari('preset', (int) input('id'));
        if ($p) {
            foreach (item_preset($p['id']) as $item) {
                db_hapus('detail_preset', $item['id']);
            }
            db_hapus('preset', $p['id']);
            catat_riwayat('Preset', $p['nama'], 'Preset dihapus');
            flash('sukses', 'Preset ' . $p['nama'] . ' dihapus.');
        }
        arahkan('pages/preset.php');
    }

    if ($aksi === 'simpan_item') {
        if (!$terpilih) {
            muat_ulang();
        }
        $id = (int) input('id');
        $data = ['id' => $id ?: '', 'nama_perlengkapan' => input('nama_perlengkapan'), 'kategori_id' => input('kategori_id'), 'jumlah' => input('jumlah')];
        $stok = stok_model();
        if (isset($stok[$data['nama_perlengkapan']])) {
            $data['kategori_id'] = (string) $stok[$data['nama_perlengkapan']]['kategori_id'];
        }
        $galat = [];
        if (mb_strlen($data['nama_perlengkapan']) < 2) {
            $galat['nama_perlengkapan'] = 'Isi model barang.';
        }
        if (!db_cari('kategori', (int) $data['kategori_id'])) {
            $galat['kategori_id'] = 'Pilih kategori.';
        }
        if ((int) $data['jumlah'] < 1 || (int) $data['jumlah'] > 20) {
            $galat['jumlah'] = 'Jumlah 1 sampai 20.';
        }
        foreach (item_preset($terpilih['id']) as $item) {
            if ((int) $item['id'] !== $id && mb_strtolower($item['nama_perlengkapan']) === mb_strtolower($data['nama_perlengkapan'])) {
                $galat['nama_perlengkapan'] = 'Model ini sudah ada di preset. Ubah jumlahnya saja.';
            }
        }
        if ($galat) {
            simpan_form('dialog-item-preset', $data, $galat);
            muat_ulang();
        }
        $isi = ['nama_perlengkapan' => $data['nama_perlengkapan'], 'kategori_id' => (int) $data['kategori_id'], 'jumlah' => (int) $data['jumlah']];
        $item_lama = $id ? db_cari('detail_preset', $id) : null;
        if ($item_lama && (int) $item_lama['preset_id'] === (int) $terpilih['id']) {
            db_ubah('detail_preset', $id, $isi);
            catat_riwayat('Preset', $terpilih['nama'], $isi['nama_perlengkapan'] . ' diperbarui', (string) $item_lama['jumlah'], (string) $isi['jumlah']);
        } else {
            db_tambah('detail_preset', ['preset_id' => $terpilih['id']] + $isi);
            catat_riwayat('Preset', $terpilih['nama'], $isi['nama_perlengkapan'] . ' × ' . $isi['jumlah'] . ' ditambahkan');
        }
        db_ubah('preset', $terpilih['id'], ['diubah_pada' => $waktu]);
        flash('sukses', 'Item ' . $isi['nama_perlengkapan'] . ' disimpan di ' . $terpilih['nama'] . '.');
        muat_ulang();
    }

    if ($aksi === 'hapus_item') {
        $item = db_cari('detail_preset', (int) input('id'));
        if ($item && $terpilih && (int) $item['preset_id'] === (int) $terpilih['id']) {
            db_hapus('detail_preset', $item['id']);
            db_ubah('preset', $terpilih['id'], ['diubah_pada' => $waktu]);
            catat_riwayat('Preset', $terpilih['nama'], $item['nama_perlengkapan'] . ' dihapus dari preset');
            flash('sukses', $item['nama_perlengkapan'] . ' dihapus dari ' . $terpilih['nama'] . '.');
        }
        muat_ulang();
    }

    muat_ulang();
}

$stok = stok_model();
$kelola = boleh('preset.kelola');

$halaman = ['menu' => 'preset', 'bagian' => 'Inventaris', 'judul' => 'Preset Perlengkapan'];
require ROOT . '/includes/layout_head.php';

judul_halaman(
    'Preset Perlengkapan',
    'Paket kebutuhan standar per jenis acara. Super Admin memakainya untuk mengisi kebutuhan proyek dengan cepat.',
    $kelola ? tombol_modal('dialog-preset', 'Preset baru', ['_judul' => 'Preset baru'], 'plus') : ''
);
?>
<?php if (!$daftar): ?>
<section class="kartu"><?= kosong('Belum ada preset', 'Buat preset pertama untuk jenis acara yang sering ditangani studio.') ?></section>
<?php else: ?>
<div class="grid-samping">
<nav class="kartu daftar-pilih" aria-label="Daftar preset">
<?php foreach ($daftar as $p): ?>
<?php $item = item_preset($p['id']); $aktif = $terpilih && (int) $terpilih['id'] === (int) $p['id']; ?>
<a href="<?= e(url('pages/preset.php?id=' . $p['id'])) ?>" class="pilih<?= $aktif ? ' aktif' : '' ?>"<?= $aktif ? ' aria-current="true"' : '' ?>>
<span class="daftar-judul"><?= e($p['nama']) ?></span>
<span class="daftar-sub"><?= count($item) ?> model · <?= array_sum(array_column($item, 'jumlah')) ?> unit</span>
</a>
<?php endforeach; ?>
</nav>

<?php if ($terpilih): ?>
<?php $item = item_preset($terpilih['id']); $perlu_rental = 0; $kurang = 0; ?>
<section class="kartu">
<div class="kartu-kepala">
<div>
<h2><?= e($terpilih['nama']) ?></h2>
<p><?= $terpilih['deskripsi'] !== '' ? e($terpilih['deskripsi']) . ' · ' : '' ?>Diubah <?= e(tgl($terpilih['diubah_pada'], 'singkat_jam')) ?> · dibuat <?= e(nama_pengguna($terpilih['dibuat_oleh'])) ?></p>
</div>
<?php if ($kelola): ?>
<div class="aksi-baris">
<?= tombol_modal('dialog-item-preset', 'Tambah item', ['_judul' => 'Tambah item'], 'plus', 'btn btn-kecil') ?>
<?= tombol_ikon_modal('dialog-preset', 'Ubah preset', ['id' => $terpilih['id'], 'nama' => $terpilih['nama'], 'deskripsi' => $terpilih['deskripsi'], '_judul' => 'Ubah preset'], 'pencil') ?>
<?= tombol_konfirmasi(['label' => 'Hapus preset', 'ikon' => 'trash-2', 'hanya_ikon' => true, 'aksi' => 'hapus_preset', 'id' => $terpilih['id'], 'judul' => 'Hapus preset ' . $terpilih['nama'] . '?', 'pesan' => 'Kebutuhan proyek yang dulu diisi dari preset ini tidak ikut terhapus.', 'tombol' => 'Hapus preset']) ?>
</div>
<?php endif; ?>
</div>
<?php if (!$item): ?>
<?= kosong('Preset masih kosong', $kelola ? 'Tambahkan model barang beserta jumlahnya.' : 'Belum ada item di preset ini.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Model</th><th>Kategori</th><th class="angka">Jumlah</th><th>Stok studio</th><?= $kelola ? '<th><span class="sr">Aksi</span></th>' : '' ?></tr></thead>
<tbody>
<?php foreach ($item as $d): ?>
<?php
$s = $stok[$d['nama_perlengkapan']] ?? null;
if (!$s) {
    $perlu_rental++;
} elseif ($s['tersedia'] < $d['jumlah']) {
    $kurang++;
}
?>
<tr>
<td data-label="Model"><strong><?= e($d['nama_perlengkapan']) ?></strong></td>
<td data-label="Kategori"><?= e(nama_kategori($d['kategori_id'])) ?></td>
<td data-label="Jumlah" class="angka"><?= (int) $d['jumlah'] ?></td>
<td data-label="Stok studio">
<?php if (!$s): ?>
<?= badge('Menunggu', 'Tidak ada · perlu rental') ?>
<?php else: ?>
<?= badge($s['tersedia'] >= $d['jumlah'] ? 'Tersedia' : 'Sebagian', $s['tersedia'] . ' dari ' . $s['total'] . ' unit tersedia') ?>
<?php endif; ?>
</td>
<?php if ($kelola): ?>
<td class="kolom-aksi">
<div class="aksi-baris">
<?= tombol_ikon_modal('dialog-item-preset', 'Ubah ' . $d['nama_perlengkapan'], ['id' => $d['id'], 'nama_perlengkapan' => $d['nama_perlengkapan'], 'kategori_id' => $d['kategori_id'], 'jumlah' => $d['jumlah'], '_judul' => 'Ubah item'], 'pencil') ?>
<?= tombol_konfirmasi(['label' => 'Hapus ' . $d['nama_perlengkapan'], 'ikon' => 'trash-2', 'hanya_ikon' => true, 'aksi' => 'hapus_item', 'id' => $d['id'], 'judul' => 'Hapus item?', 'pesan' => $d['nama_perlengkapan'] . ' × ' . $d['jumlah'] . ' dihapus dari ' . $terpilih['nama'] . '.', 'tombol' => 'Hapus']) ?>
</div>
</td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="kartu-kaki">
<p class="kecil redup">Total <?= array_sum(array_column($item, 'jumlah')) ?> unit dari <?= count($item) ?> model. Stok dihitung dari unit yang tersedia hari ini.</p>
<?php if ($perlu_rental || $kurang): ?>
<p class="kecil teks-kuning"><?= $perlu_rental ? $perlu_rental . ' model perlu rental' : '' ?><?= $perlu_rental && $kurang ? ' · ' : '' ?><?= $kurang ? $kurang . ' model stoknya kurang' : '' ?></p>
<?php endif; ?>
</div>
<?php endif; ?>
</section>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($kelola) {
    require ROOT . '/partials/modal_preset.php';
} ?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
