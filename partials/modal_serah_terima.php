<?php
defined('ROOT') || exit;

$kp = $kelompok_serah['proyek'];
$penerima_opsi = penerima_proyek($kp['id']);
?>
<?php if ($jenis_serah === 'keluar'): ?>
<?php $id_dialog = 'dialog-keluar-' . $kp['id']; $pilih_lama = lama_daftar('alokasi'); $np_keluar = $kelompok_serah['gerbang']['nilai']; ?>
<?= dialog_buka($id_dialog, 'Barang keluar · ' . $kp['kode'], $kp['nama'] . ' · ' . rentang_tgl($kp['mulai'], $kp['selesai']), 'dialog dialog-lebar') ?>
<input type="hidden" name="aksi" value="checkout">
<input type="hidden" name="proyek_id" value="<?= (int) $kp['id'] ?>">
<p class="petunjuk">Persiapan dikonfirmasi selesai oleh <?= e(nama_pengguna($np_keluar['dikonfirmasi_oleh'])) ?> · <?= e(tgl($np_keluar['dikonfirmasi_pada'], 'lengkap')) ?>. Barang baru tercatat keluar setelah formulir ini disimpan.</p>
<fieldset class="bidang">
<legend>Barang yang diserahkan</legend>
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
$mati = $baris['blokir'] !== '';
$tercentang = $pilih_lama ? in_array((string) $a['id'], $pilih_lama, true) : !$mati;
if ($a['sumber'] === 'Studio') {
    $judul_b = label_unit($baris['unit']);
    $ket_b = 'Unit studio · sudah diperiksa di persiapan';
} elseif ($a['sumber'] === 'Rental') {
    $judul_b = $a['nama_barang'];
    $ket_b = 'Vendor: ' . $a['vendor'] . (!empty($a['diterima_pada']) ? ' · diterima dari vendor ' . tgl($a['diterima_pada'], 'singkat_jam') : '');
} else {
    $judul_b = $a['nama_barang'];
    $ket_b = 'Milik ' . nama_pengguna($a['pemilik_id']) . ' · dicatat dipakai untuk proyek, bukan barang gudang studio';
}
?>
<li>
<label class="ceklist-item<?= $mati ? ' ceklist-mati' : '' ?>" data-keluar-alokasi="<?= (int) $a['id'] ?>">
<input type="checkbox" name="alokasi[]" value="<?= (int) $a['id'] ?>"<?= $mati ? ' disabled' : ($tercentang ? ' checked' : '') ?>>
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($judul_b) ?> <span class="redup">× <?= (int) $baris['jumlah'] ?> unit</span></span>
<span class="daftar-sub"><?= $mati ? '<span class="teks-merah">' . e($baris['blokir']) . '</span>' : e($ket_b) ?> · kembali <?= e(tgl($a['tgl_selesai'], 'singkat')) ?></span>
</span>
<span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= e($a['sumber']) ?></span>
</label>
<?php if (!$mati): ?><input type="hidden" name="jumlah[<?= (int) $a['id'] ?>]" value="<?= (int) $baris['jumlah'] ?>"><?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<p class="petunjuk">Jumlah mengikuti alokasi; penyerahan sebagian belum didukung. Untuk Rental dan Milik Kru, catatan ini terpisah dari penerimaan vendor dan pemeriksaan persiapan.</p>
</fieldset>
<div class="baris-bidang">
<div class="bidang">
<label for="<?= e($id_dialog) ?>-penerima">Diterima oleh</label>
<select id="<?= e($id_dialog) ?>-penerima" name="penerima_id"<?= attr_galat('penerima_id') ?>>
<?= opsi($penerima_opsi, lama('penerima_id', isset($penerima_opsi[(string) $kp['pic_id']]) ? (string) $kp['pic_id'] : ''), 'Pilih anggota tim') ?>
</select>
<?= pesan_galat('penerima_id') ?>
</div>
<div class="bidang">
<label for="<?= e($id_dialog) ?>-waktu">Waktu serah terima</label>
<input id="<?= e($id_dialog) ?>-waktu" name="waktu" type="datetime-local" value="<?= e(lama('waktu', waktu_input(sekarang()))) ?>"<?= attr_galat('waktu') ?>>
<?= pesan_galat('waktu') ?>
</div>
</div>
<div class="bidang">
<label for="<?= e($id_dialog) ?>-catatan">Catatan <span class="redup">(opsional)</span></label>
<input id="<?= e($id_dialog) ?>-catatan" name="catatan" type="text" maxlength="160" value="<?= e(lama('catatan')) ?>"<?= attr_galat('catatan') ?>>
<?= pesan_galat('catatan') ?>
</div>
<ul class="ceklist">
<li>
<label class="ceklist-item">
<input type="checkbox" name="konfirmasi" value="1">
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama"><span class="daftar-judul">Barang yang dicentang sudah diserahkan kepada penerima pada waktu di atas.</span><span class="daftar-sub">Setelah dicatat, barang berstatus keluar dan tidak bisa dikeluarkan lagi.</span></span>
</label>
</li>
</ul>
<?= pesan_galat('konfirmasi') ?>
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
