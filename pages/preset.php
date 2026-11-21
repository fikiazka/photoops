<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('preset.lihat');

// Preset dan itemnya dibaca dari PostgreSQL (data/logic_preset.php).
$daftar = array_values(semua_preset());
usort($daftar, fn ($a, $b) => strcmp($a['nama'], $b['nama']));
$terpilih = cari_preset(query('id')) ?? ($daftar[0] ?? null);

if (is_post()) {
    cek_csrf();
    wajib_boleh('preset.kelola');
    $aksi = input('aksi');

    if ($aksi === 'simpan_preset') {
        $id = preg_match('/^\d{1,9}$/', input('id')) ? (int) input('id') : 0;
        $data = ['id' => $id ?: '', 'nama' => input('nama'), 'deskripsi' => input('deskripsi')];
        $galat = validasi_preset($data);
        $hasil = $galat ? null : simpan_preset($id, ['nama' => $data['nama'], 'deskripsi' => $data['deskripsi']], (int) $pengguna['id']);
        if ($hasil && $hasil['status'] === 'nama_dipakai') {
            $galat['nama'] = 'Nama preset sudah dipakai.';
        }
        if ($galat) {
            simpan_form('dialog-preset', $data, $galat);
            muat_ulang();
        }
        if ($hasil['status'] === 'tidak_ada') {
            flash('error', 'Preset tidak ditemukan. Mungkin sudah dihapus pengguna lain.');
            arahkan('pages/preset.php');
        }
        if ($hasil['baru']) {
            flash('sukses', 'Preset ' . $data['nama'] . ' dibuat. Tambahkan item di dalamnya.');
        } else {
            flash('sukses', 'Preset ' . $data['nama'] . ' diperbarui.');
        }
        arahkan('pages/preset.php?id=' . $hasil['id']);
    }

    if ($aksi === 'hapus_preset') {
        $nama = hapus_preset((int) input('id'));
        if ($nama !== null) {
            flash('sukses', 'Preset ' . $nama . ' dihapus.');
        }
        arahkan('pages/preset.php');
    }

    if ($aksi === 'simpan_item') {
        // Preset tujuan dikirim form (bukan preset yang kebetulan tampil pertama), agar item tidak masuk ke preset lain.
        $preset_id = (int) (input('preset_id') !== '' ? input('preset_id') : query('id'));
        $id = preg_match('/^\d{1,9}$/', input('id')) ? (int) input('id') : 0;
        $data = ['id' => $id ?: '', 'nama_perlengkapan' => input('nama_perlengkapan'), 'kategori_id' => input('kategori_id'), 'jumlah' => input('jumlah')];
        $stok = stok_model();
        if (isset($stok[$data['nama_perlengkapan']])) {
            $data['kategori_id'] = (string) $stok[$data['nama_perlengkapan']]['kategori_id'];
        }
        $galat = validasi_item_preset($data);
        $hasil = $galat ? null : simpan_item_preset($preset_id, $id,
            ['nama_perlengkapan' => $data['nama_perlengkapan'], 'kategori_id' => (int) $data['kategori_id'], 'jumlah' => (int) $data['jumlah']]);
        if ($hasil && $hasil['status'] === 'duplikat') {
            $galat['nama_perlengkapan'] = 'Model ini sudah ada di preset. Ubah jumlahnya saja.';
        } elseif ($hasil && $hasil['status'] === 'kategori_tidak_ada') {
            $galat['kategori_id'] = 'Pilih kategori.';
        }
        if ($galat) {
            simpan_form('dialog-item-preset', $data, $galat);
            muat_ulang();
        }
        if ($hasil['status'] === 'preset_tidak_ada') {
            flash('error', 'Preset tidak ditemukan. Mungkin sudah dihapus pengguna lain.');
            arahkan('pages/preset.php');
        }
        if ($hasil['status'] === 'item_tidak_ada') {
            flash('error', 'Item tidak ditemukan di preset ' . $hasil['preset'] . '. Mungkin sudah dihapus pengguna lain.');
        } else {
            flash('sukses', 'Item ' . $data['nama_perlengkapan'] . ' disimpan di ' . $hasil['preset'] . '.');
        }
        arahkan('pages/preset.php?id=' . $preset_id);
    }

    if ($aksi === 'hapus_item') {
        $hasil = hapus_item_preset((int) input('id'));
        if ($hasil) {
            flash('sukses', $hasil['item']['nama_perlengkapan'] . ' dihapus dari ' . $hasil['preset'] . '.');
            arahkan('pages/preset.php?id=' . $hasil['preset_id']);
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
    'Paket kebutuhan standar per jenis acara. Super Admin dan Petugas Inventaris memakainya untuk mengisi kebutuhan proyek dengan cepat.',
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
<p class="kecil redup">Total <?= array_sum(array_column($item, 'jumlah')) ?> unit dari <?= count($item) ?> model. Stok dihitung dari unit yang tersedia hari ini, belum memperhitungkan jadwal proyek lain.</p>
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
