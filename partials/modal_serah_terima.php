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
<?= dialog_buka($id_dialog, 'Pengembalian · ' . $kp['kode'], $kp['nama'] . ' · ' . $kp['status'], 'dialog dialog-lebar') ?>
<input type="hidden" name="aksi" value="checkin">
<input type="hidden" name="proyek_id" value="<?= (int) $kp['id'] ?>">
<p class="petunjuk">Centang barang yang diterima kembali dari proyek dan periksa kondisinya. Rental dan Milik Kru boleh dikembalikan sebagian; isi jumlah per kondisi. Pencatatan ini hanya menandai barang sudah kembali dari proyek; Rental tetap dikembalikan ke vendor lewat Barang eksternal.</p>
<?php if (galat('alokasi') !== ''): ?><?= pesan_galat('alokasi') ?><?php endif; ?>
<ul class="ceklist">
<?php foreach ($kelompok_serah['unit'] as $baris): ?>
<?php
$a = $baris['alokasi'];
$aid = (int) $a['id'];
$tercentang = $pilih_lama ? in_array((string) $aid, $pilih_lama, true) : true;
$trx_asal = $baris['trx_keluar'];
$judul_k = $a['sumber'] === 'Studio' ? label_unit($baris['unit']) : $a['nama_barang'];
?>
<li class="kembali-baris" data-kembali-alokasi="<?= $aid ?>">
<label class="ceklist-item">
<input type="checkbox" name="alokasi[]" value="<?= $aid ?>"<?= $tercentang ? ' checked' : '' ?>>
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($judul_k) ?> <span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= e($a['sumber']) ?></span></span>
<span class="daftar-sub">Keluar <?= (int) $baris['jumlah_keluar'] ?> · sudah kembali <?= (int) $baris['jumlah_kembali'] ?> · masih di proyek <?= (int) $baris['jumlah'] ?> unit</span>
<span class="daftar-sub"><?= e(($trx_asal['kode'] ?? '?') . ' · keluar ' . tgl($baris['keluar'], 'singkat_jam') . ' · tempo ' . tgl($a['tgl_selesai'], 'singkat')) ?><?= $a['sumber'] === 'Milik Kru' ? ' · milik ' . e(nama_pengguna($a['pemilik_id'])) : ($a['sumber'] === 'Rental' ? ' · vendor ' . e($a['vendor']) : '') ?></span>
</span>
<?= badge($baris['status']) ?>
</label>
<input type="hidden" name="saldo[<?= $aid ?>]" value="<?= (int) $baris['jumlah'] ?>">
<?php if ($a['sumber'] === 'Studio'): ?>
<div class="bidang bidang-sejajar">
<label for="<?= e($id_dialog) ?>-kondisi-<?= $aid ?>" class="sr">Kondisi <?= e($baris['unit']['kode_unit'] ?? '') ?></label>
<select id="<?= e($id_dialog) ?>-kondisi-<?= $aid ?>" name="kondisi[<?= $aid ?>]">
<?= opsi(['Baik' => 'Kondisi baik', 'Maintenance' => 'Perlu maintenance', 'Rusak' => 'Rusak'], 'Baik') ?>
</select>
</div>
<?php else: ?>
<div class="kembali-jumlah" role="group" aria-label="Jumlah dikembalikan per kondisi untuk <?= e($a['nama_barang']) ?>">
<?php foreach (['Baik' => 'Baik', 'Maintenance' => 'Maintenance', 'Rusak' => 'Rusak'] as $kk => $label_k): ?>
<div class="bidang">
<label for="<?= e($id_dialog) ?>-j-<?= $aid ?>-<?= e($kk) ?>"><?= e($label_k) ?></label>
<input id="<?= e($id_dialog) ?>-j-<?= $aid ?>-<?= e($kk) ?>" name="jumlah[<?= $aid ?>][<?= e($kk) ?>]" type="number" min="0" max="<?= (int) $baris['jumlah'] ?>" step="1" inputmode="numeric" value="<?= $kk === 'Baik' ? (int) $baris['jumlah'] : '' ?>">
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<div class="baris-bidang">
<div class="bidang">
<label for="<?= e($id_dialog) ?>-waktu">Waktu diterima kembali</label>
<input id="<?= e($id_dialog) ?>-waktu" name="waktu" type="datetime-local" value="<?= e(lama('waktu', waktu_input(sekarang()))) ?>"<?= attr_galat('waktu') ?>>
<?= pesan_galat('waktu') ?>
</div>
<div class="bidang">
<label for="<?= e($id_dialog) ?>-penyerah">Diserahkan oleh <span class="redup">(opsional)</span></label>
<select id="<?= e($id_dialog) ?>-penyerah" name="penyerah_id"<?= attr_galat('penyerah_id') ?>>
<?= opsi($penerima_opsi, lama('penyerah_id'), 'Tidak dicatat') ?>
</select>
<?= pesan_galat('penyerah_id') ?>
</div>
</div>
<div class="bidang">
<label for="<?= e($id_dialog) ?>-catatan">Catatan <span class="redup">(wajib jika ada barang Maintenance/Rusak)</span></label>
<textarea id="<?= e($id_dialog) ?>-catatan" name="catatan" maxlength="300" placeholder="Contoh: lensa tergores di bagian depan"<?= attr_galat('catatan') ?>><?= e(lama('catatan')) ?></textarea>
<?= pesan_galat('catatan') ?>
<p class="petunjuk">Unit studio Maintenance/Rusak otomatis tercatat sebagai insiden dan tidak bisa dikeluarkan sampai insidennya selesai. Kondisi Rental/Milik Kru dicatat di transaksi ini tanpa mengubah inventaris studio. Petugas penerima: <?= e(nama_pengguna($_SESSION['user_id'] ?? null)) ?>.</p>
</div>
<ul class="ceklist">
<li>
<label class="ceklist-item">
<input type="checkbox" name="konfirmasi" value="1">
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama"><span class="daftar-judul">Barang yang dicentang sudah diterima kembali dan kondisinya sudah diperiksa.</span><span class="daftar-sub">Jumlah dan kondisi tersimpan sebagai transaksi pengembalian yang merujuk barang keluar asal.</span></span>
</label>
</li>
</ul>
<?= pesan_galat('konfirmasi') ?>
<?= dialog_tutup('Catat pengembalian') ?>
<?php endif; ?>
