<?php
defined('ROOT') || exit;

$judul_proyek = lama('id') !== '' ? 'Ubah proyek' : 'Proyek baru';
?>
<?= dialog_buka('dialog-proyek', $judul_proyek, 'Jadwal, klien, dan PIC yang bertanggung jawab.', 'dialog dialog-lebar') ?>
<input type="hidden" name="aksi" value="simpan_proyek">
<input type="hidden" name="id" value="<?= e(lama('id')) ?>">
<div class="bidang">
<label for="proyek-nama">Nama proyek</label>
<input id="proyek-nama" name="nama" type="text" maxlength="80" value="<?= e(lama('nama')) ?>" placeholder="Contoh: Wedding Arunika & Dimas"<?= attr_galat('nama') ?>>
<?= pesan_galat('nama') ?>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="proyek-klien">Klien</label>
<input id="proyek-klien" name="klien" type="text" maxlength="80" value="<?= e(lama('klien')) ?>"<?= attr_galat('klien') ?>>
<?= pesan_galat('klien') ?>
</div>
<div class="bidang">
<label for="proyek-lokasi">Lokasi</label>
<input id="proyek-lokasi" name="lokasi" type="text" maxlength="80" value="<?= e(lama('lokasi')) ?>"<?= attr_galat('lokasi') ?>>
<?= pesan_galat('lokasi') ?>
</div>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="proyek-mulai">Mulai acara</label>
<input id="proyek-mulai" name="mulai" type="datetime-local" value="<?= e(lama('mulai')) ?>"<?= attr_galat('mulai') ?>>
<?= pesan_galat('mulai') ?>
</div>
<div class="bidang">
<label for="proyek-selesai">Selesai acara</label>
<input id="proyek-selesai" name="selesai" type="datetime-local" value="<?= e(lama('selesai')) ?>"<?= attr_galat('selesai') ?>>
<?= pesan_galat('selesai') ?>
</div>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="proyek-deadline">Batas persiapan barang</label>
<input id="proyek-deadline" name="deadline_persiapan" type="datetime-local" value="<?= e(lama('deadline_persiapan')) ?>"<?= attr_galat('deadline_persiapan') ?>>
<?= pesan_galat('deadline_persiapan') ?>
</div>
<div class="bidang">
<label for="proyek-pic">PIC proyek</label>
<select id="proyek-pic" name="pic_id"<?= attr_galat('pic_id') ?>>
<?= opsi(opsi_kru(), lama('pic_id'), 'Pilih PIC') ?>
</select>
<?= pesan_galat('pic_id') ?>
</div>
</div>
<div class="bidang">
<label for="proyek-status">Status</label>
<select id="proyek-status" name="status" data-bawaan="Dijadwalkan"<?= attr_galat('status') ?>>
<?= opsi(array_combine(daftar_status_proyek(), daftar_status_proyek()), lama('status', 'Dijadwalkan')) ?>
</select>
<?= pesan_galat('status') ?>
</div>
<div class="bidang">
<label for="proyek-catatan">Catatan <span class="redup">(opsional)</span></label>
<textarea id="proyek-catatan" name="catatan" maxlength="300"><?= e(lama('catatan')) ?></textarea>
</div>
<?= dialog_tutup('Simpan proyek') ?>
