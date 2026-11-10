<?php
defined('ROOT') || exit;

$opsi_unit = [];
foreach (db('perlengkapan') as $u) {
    $opsi_unit[$u['id']] = label_unit($u) . ' · ' . $u['kondisi'];
}
$opsi_proyek_insiden = [];
foreach (urutkan_proyek(array_values(db('proyek'))) as $p) {
    $opsi_proyek_insiden[$p['id']] = $p['kode'] . ' · ' . $p['nama'];
}
?>
<?= dialog_buka('dialog-insiden', 'Laporkan insiden', 'Kerusakan atau masalah teknis pada unit studio.') ?>
<input type="hidden" name="aksi" value="lapor_insiden">
<div class="bidang">
<label for="insiden-unit">Unit</label>
<select id="insiden-unit" name="perlengkapan_id"<?= attr_galat('perlengkapan_id') ?>>
<?= opsi($opsi_unit, lama('perlengkapan_id'), 'Pilih unit') ?>
</select>
<?= pesan_galat('perlengkapan_id') ?>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="insiden-jenis">Jenis</label>
<select id="insiden-jenis" name="jenis" data-bawaan="Rusak"<?= attr_galat('jenis') ?>>
<?= opsi(['Rusak' => 'Rusak', 'Masalah Teknis' => 'Masalah teknis'], lama('jenis', 'Rusak')) ?>
</select>
</div>
<div class="bidang">
<label for="insiden-kondisi">Ubah kondisi unit jadi</label>
<select id="insiden-kondisi" name="kondisi" data-bawaan="Maintenance"<?= attr_galat('kondisi') ?>>
<?= opsi(['Maintenance' => 'Maintenance', 'Rusak' => 'Rusak'], lama('kondisi', 'Maintenance')) ?>
</select>
<?= pesan_galat('kondisi') ?>
</div>
</div>
<div class="bidang">
<label for="insiden-proyek">Terjadi di proyek <span class="redup">(opsional)</span></label>
<select id="insiden-proyek" name="proyek_id">
<?= opsi($opsi_proyek_insiden, lama('proyek_id'), 'Tidak terkait proyek') ?>
</select>
</div>
<div class="bidang">
<label for="insiden-deskripsi">Deskripsi</label>
<textarea id="insiden-deskripsi" name="deskripsi" maxlength="300" placeholder="Apa yang terjadi dan bagian mana yang bermasalah"<?= attr_galat('deskripsi') ?>><?= e(lama('deskripsi')) ?></textarea>
<?= pesan_galat('deskripsi') ?>
</div>
<?= dialog_tutup('Kirim laporan', 'btn btn-bahaya') ?>
