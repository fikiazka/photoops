<?php
defined('ROOT') || exit;

$opsi_preset = [];
foreach (db('preset') as $preset_opsi) {
    $jumlah_item = count(array_filter(db('detail_preset'), fn ($d) => (int) $d['preset_id'] === (int) $preset_opsi['id']));
    $opsi_preset[$preset_opsi['id']] = $preset_opsi['nama'] . ' · ' . $jumlah_item . ' item';
}
?>
<?= dialog_buka('dialog-kebutuhan', lama('id') !== '' ? 'Ubah kebutuhan' : 'Tambah kebutuhan', 'Barang yang dibutuhkan proyek, sebelum dialokasikan.') ?>
<input type="hidden" name="aksi" value="simpan_kebutuhan">
<input type="hidden" name="id" value="<?= e(lama('id')) ?>">
<div class="bidang">
<label for="kebutuhan-nama">Nama kebutuhan</label>
<input id="kebutuhan-nama" name="nama" type="text" maxlength="60" value="<?= e(lama('nama')) ?>" placeholder="Contoh: Kamera Mirrorless"<?= attr_galat('nama') ?>>
<?= pesan_galat('nama') ?>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="kebutuhan-kategori">Kategori</label>
<select id="kebutuhan-kategori" name="kategori_id"<?= attr_galat('kategori_id') ?>>
<?= opsi(opsi_kategori(), lama('kategori_id'), 'Pilih kategori') ?>
</select>
<?= pesan_galat('kategori_id') ?>
</div>
<div class="bidang">
<label for="kebutuhan-jumlah">Jumlah</label>
<input id="kebutuhan-jumlah" name="jumlah" type="number" min="1" max="50" value="<?= e(lama('jumlah', '1')) ?>" data-bawaan="1"<?= attr_galat('jumlah') ?>>
<?= pesan_galat('jumlah') ?>
</div>
</div>
<div class="bidang">
<label for="kebutuhan-acuan">Model acuan <span class="redup">(opsional)</span></label>
<input id="kebutuhan-acuan" name="acuan" type="text" maxlength="60" list="daftar-model" value="<?= e(lama('acuan')) ?>" placeholder="Contoh: Sony A7 III">
<p class="petunjuk">Membantu petugas memilih unit yang sesuai. Kosongkan jika model bebas.</p>
</div>
<datalist id="daftar-model">
<?php foreach (array_keys(stok_model()) as $model): ?>
<option value="<?= e($model) ?>">
<?php endforeach; ?>
</datalist>
<?= dialog_tutup('Simpan kebutuhan') ?>

<?= dialog_buka('dialog-isi-preset', 'Isi dari preset', 'Kebutuhan dari preset ditambahkan ke proyek ini. Item yang modelnya sudah ada dilewati.') ?>
<input type="hidden" name="aksi" value="isi_preset">
<div class="bidang">
<label for="isi-preset">Preset</label>
<select id="isi-preset" name="preset_id"<?= attr_galat('preset_id') ?>>
<?= opsi($opsi_preset, lama('preset_id'), 'Pilih preset') ?>
</select>
<?= pesan_galat('preset_id') ?>
</div>
<?= dialog_tutup('Tambahkan ke kebutuhan') ?>
