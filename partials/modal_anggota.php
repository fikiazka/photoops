<?php
defined('ROOT') || exit;

$sudah_anggota = array_map(fn ($a) => (int) $a['pengguna_id'], $anggota);
$calon = array_filter(opsi_kru(), fn ($label, $id) => !in_array((int) $id, $sudah_anggota, true), ARRAY_FILTER_USE_BOTH);
?>
<?= dialog_buka('dialog-anggota', 'Tambah anggota tim', 'Anggota baru masuk sebagai kru. PIC diganti lewat Ubah proyek.') ?>
<input type="hidden" name="aksi" value="tambah_anggota">
<?php if (!$calon): ?>
<p class="redup">Semua user aktif sudah tergabung di proyek ini.</p>
<?php else: ?>
<div class="bidang">
<label for="anggota-pengguna">Pilih kru</label>
<select id="anggota-pengguna" name="pengguna_id"<?= attr_galat('pengguna_id') ?>>
<?= opsi($calon, lama('pengguna_id'), 'Pilih user') ?>
</select>
<?= pesan_galat('pengguna_id') ?>
</div>
<?php endif; ?>
<?= dialog_tutup('Tambahkan ke tim') ?>
