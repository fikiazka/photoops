<?php
defined('ROOT') || exit;
?>
<?= dialog_buka('dialog-preset', lama('id') !== '' ? 'Ubah preset' : 'Preset baru', 'Paket perlengkapan standar untuk jenis acara tertentu.') ?>
<input type="hidden" name="aksi" value="simpan_preset">
<input type="hidden" name="id" value="<?= e(lama('id')) ?>">
<div class="bidang">
<label for="preset-nama">Nama preset</label>
<input id="preset-nama" name="nama" type="text" maxlength="50" value="<?= e(lama('nama')) ?>" placeholder="Contoh: Wedding Basic"<?= attr_galat('nama') ?>>
<?= pesan_galat('nama') ?>
</div>
<div class="bidang">
<label for="preset-deskripsi">Deskripsi <span class="redup">(opsional)</span></label>
<textarea id="preset-deskripsi" name="deskripsi" maxlength="160"><?= e(lama('deskripsi')) ?></textarea>
</div>
<?= dialog_tutup('Simpan preset') ?>

<?= dialog_buka('dialog-item-preset', lama('id') !== '' ? 'Ubah item' : 'Tambah item', 'Item preset merujuk ke model barang, bukan unit tertentu.') ?>
<input type="hidden" name="aksi" value="simpan_item">
<input type="hidden" name="id" value="<?= e(lama('id')) ?>">
<div class="bidang">
<label for="item-nama">Model barang</label>
<input id="item-nama" name="nama_perlengkapan" type="text" maxlength="60" list="daftar-model-preset" value="<?= e(lama('nama_perlengkapan')) ?>" placeholder="Ketik atau pilih model"<?= attr_galat('nama_perlengkapan') ?>>
<?= pesan_galat('nama_perlengkapan') ?>
<p class="petunjuk">Model dari inventaris otomatis memakai kategorinya. Model di luar inventaris akan perlu rental.</p>
</div>
<datalist id="daftar-model-preset">
<?php foreach (array_keys(stok_model()) as $model): ?>
<option value="<?= e($model) ?>">
<?php endforeach; ?>
</datalist>
<div class="baris-bidang">
<div class="bidang">
<label for="item-kategori">Kategori</label>
<select id="item-kategori" name="kategori_id"<?= attr_galat('kategori_id') ?>>
<?= opsi(opsi_kategori(), lama('kategori_id'), 'Pilih kategori') ?>
</select>
<?= pesan_galat('kategori_id') ?>
</div>
<div class="bidang">
<label for="item-jumlah">Jumlah</label>
<input id="item-jumlah" name="jumlah" type="number" min="1" max="20" value="<?= e(lama('jumlah', '1')) ?>" data-bawaan="1"<?= attr_galat('jumlah') ?>>
<?= pesan_galat('jumlah') ?>
</div>
</div>
<?= dialog_tutup('Simpan item') ?>
