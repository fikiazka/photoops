<?php
defined('ROOT') || exit;
?>
<?= dialog_buka('dialog-unit', lama('id') !== '' ? 'Ubah unit' : 'Tambah unit', 'Kode unit dibuat otomatis dari kategori.') ?>
<input type="hidden" name="aksi" value="simpan_unit">
<input type="hidden" name="id" value="<?= e(lama('id')) ?>">
<div class="baris-bidang">
<div class="bidang">
<label for="unit-kategori">Kategori</label>
<select id="unit-kategori" name="kategori_id"<?= attr_galat('kategori_id') ?>>
<?= opsi(opsi_kategori(), lama('kategori_id'), 'Pilih kategori') ?>
</select>
<?= pesan_galat('kategori_id') ?>
<p class="petunjuk">Saat mengubah unit, kategori dan kode tetap.</p>
</div>
<div class="bidang">
<label for="unit-kondisi">Kondisi</label>
<select id="unit-kondisi" name="kondisi" data-bawaan="Baik"<?= attr_galat('kondisi') ?>>
<?= opsi(array_combine(daftar_kondisi(), daftar_kondisi()), lama('kondisi', 'Baik')) ?>
</select>
<?= pesan_galat('kondisi') ?>
</div>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="unit-nama">Nama model</label>
<input id="unit-nama" name="nama" type="text" maxlength="60" list="daftar-model-unit" value="<?= e(lama('nama')) ?>" placeholder="Contoh: Sony A7 III"<?= attr_galat('nama') ?>>
<?= pesan_galat('nama') ?>
</div>
<div class="bidang">
<label for="unit-merek">Merek</label>
<input id="unit-merek" name="merek" type="text" maxlength="30" value="<?= e(lama('merek')) ?>"<?= attr_galat('merek') ?>>
<?= pesan_galat('merek') ?>
</div>
</div>
<datalist id="daftar-model-unit">
<?php foreach (array_keys(stok_model()) as $model): ?>
<option value="<?= e($model) ?>">
<?php endforeach; ?>
</datalist>
<div class="bidang">
<label for="unit-aktif">Status unit</label>
<select id="unit-aktif" name="status_aktif" data-bawaan="Aktif">
<?= opsi(['Aktif' => 'Aktif, bisa dialokasikan', 'Nonaktif' => 'Nonaktif, disimpan saja'], lama('status_aktif', 'Aktif')) ?>
</select>
</div>
<div class="bidang">
<label for="unit-catatan">Catatan <span class="redup">(opsional)</span></label>
<input id="unit-catatan" name="catatan" type="text" maxlength="120" value="<?= e(lama('catatan')) ?>">
</div>
<?= dialog_tutup('Simpan unit') ?>
