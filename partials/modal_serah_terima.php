<?php
defined('ROOT') || exit;

$kp = $kelompok_serah['proyek'];
$penerima_opsi = penerima_proyek($kp['id']);
?>
<?php if ($jenis_serah === 'keluar'): ?>
<?php $id_dialog = 'dialog-keluar-' . $kp['id']; $pilih_lama = lama_daftar('alokasi'); ?>
<?= dialog_buka($id_dialog, 'Barang keluar · ' . $kp['kode'], $kp['nama'] . ' · ' . rentang_tgl($kp['mulai'], $kp['selesai']), 'dialog dialog-lebar') ?>
<input type="hidden" name="aksi" value="checkout">
<input type="hidden" name="proyek_id" value="<?= (int) $kp['id'] ?>">
<fieldset class="bidang">
<legend>Unit yang diserahkan</legend>
<?php if (galat('alokasi') !== ''): ?><?= pesan_galat('alokasi') ?><?php endif; ?>
<label class="ceklist-item ceklist-semua">
<input type="checkbox" data-pilih-semua="alokasi[]">
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-judul">Pilih semua yang bisa diserahkan</span>
</label>
<ul class="ceklist">
<?php foreach ($kelompok_serah['unit'] as $baris): ?>
<?php
$a = $baris['alokasi'];
$sudah_diperiksa = in_array((int) $a['id'], persiapan_proyek($kp)['diperiksa'], true);
$tercentang = $pilih_lama ? in_array((string) $a['id'], $pilih_lama, true) : ($baris['blokir'] === '');
?>
<li>
<label class="ceklist-item<?= $baris['blokir'] !== '' ? ' ceklist-mati' : '' ?>">
<input type="checkbox" name="alokasi[]" value="<?= (int) $a['id'] ?>"<?= $baris['blokir'] !== '' ? ' disabled' : ($tercentang ? ' checked' : '') ?>>
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e(label_unit($baris['unit'])) ?></span>
<span class="daftar-sub"><?= $baris['blokir'] !== '' ? '<span class="teks-merah">' . e($baris['blokir']) . '</span>' : ($sudah_diperiksa ? 'Sudah diperiksa di persiapan' : '<span class="teks-kuning">Belum dicentang di persiapan</span>') ?> · kembali <?= e(tgl($a['tgl_selesai'], 'singkat')) ?></span>
</span>
</label>
</li>
<?php endforeach; ?>
</ul>
</fieldset>
<div class="baris-bidang">
<div class="bidang">
<label for="<?= e($id_dialog) ?>-penerima">Diterima oleh</label>
<select id="<?= e($id_dialog) ?>-penerima" name="penerima"<?= attr_galat('penerima') ?>>
<?= opsi($penerima_opsi, lama('penerima', nama_pengguna($kp['pic_id'])), 'Pilih anggota tim') ?>
</select>
<?= pesan_galat('penerima') ?>
</div>
<div class="bidang">
<label for="<?= e($id_dialog) ?>-catatan">Catatan <span class="redup">(opsional)</span></label>
<input id="<?= e($id_dialog) ?>-catatan" name="catatan" type="text" maxlength="160" value="<?= e(lama('catatan')) ?>">
</div>
</div>
<?= dialog_tutup('Catat barang keluar') ?>
<?php else: ?>
<?php $id_dialog = 'dialog-kembali-' . $kp['id']; $pilih_lama = lama_daftar('alokasi'); ?>
<?= dialog_buka($id_dialog, 'Pengembalian · ' . $kp['kode'], 'Centang unit yang dikembalikan dan cek kondisinya satu per satu.', 'dialog dialog-lebar') ?>
<input type="hidden" name="aksi" value="checkin">
<input type="hidden" name="proyek_id" value="<?= (int) $kp['id'] ?>">
<?php if (galat('alokasi') !== ''): ?><?= pesan_galat('alokasi') ?><?php endif; ?>
<ul class="ceklist">
<?php foreach ($kelompok_serah['unit'] as $baris): ?>
<?php $a = $baris['alokasi']; $tercentang = $pilih_lama ? in_array((string) $a['id'], $pilih_lama, true) : true; ?>
<li class="kembali-baris">
<label class="ceklist-item">
<input type="checkbox" name="alokasi[]" value="<?= (int) $a['id'] ?>"<?= $tercentang ? ' checked' : '' ?>>
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e(label_unit($baris['unit'])) ?></span>
<span class="daftar-sub">Keluar <?= e(tgl($baris['keluar'], 'singkat_jam')) ?> · tempo <?= e(tgl($a['tgl_selesai'], 'singkat')) ?></span>
</span>
<?= badge($baris['status']) ?>
</label>
<div class="bidang bidang-sejajar">
<label for="<?= e($id_dialog) ?>-kondisi-<?= (int) $a['id'] ?>" class="sr">Kondisi <?= e($baris['unit']['kode_unit'] ?? '') ?></label>
<select id="<?= e($id_dialog) ?>-kondisi-<?= (int) $a['id'] ?>" name="kondisi[<?= (int) $a['id'] ?>]">
<?= opsi(['Baik' => 'Kondisi baik', 'Maintenance' => 'Perlu maintenance', 'Rusak' => 'Rusak'], 'Baik') ?>
</select>
</div>
</li>
<?php endforeach; ?>
</ul>
<div class="bidang">
<label for="<?= e($id_dialog) ?>-catatan">Catatan <span class="redup">(wajib jika ada unit bermasalah)</span></label>
<textarea id="<?= e($id_dialog) ?>-catatan" name="catatan" maxlength="300" placeholder="Contoh: lensa tergores di bagian depan"<?= attr_galat('catatan') ?>><?= e(lama('catatan')) ?></textarea>
<?= pesan_galat('catatan') ?>
<p class="petunjuk">Unit dengan kondisi Maintenance atau Rusak otomatis tercatat sebagai insiden.</p>
</div>
<?= dialog_tutup('Catat pengembalian') ?>
<?php endif; ?>
