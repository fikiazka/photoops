<?php
defined('ROOT') || exit;

$np_modal = $nilai_persiapan;
$sy_modal = $np_modal['syarat'];
?>
<?php if ($np_modal['boleh_konfirmasi']): ?>
<?= dialog_buka('dialog-konfirmasi-persiapan', 'Konfirmasi persiapan selesai?', $proyek['kode'] . ' · ' . $proyek['nama']) ?>
<input type="hidden" name="aksi" value="konfirmasi_persiapan">
<input type="hidden" name="proyek_id" value="<?= (int) $proyek['id'] ?>">
<dl class="info-ringkas">
<div><dt>Kebutuhan</dt><dd><?= $sy_modal['kebutuhan'] ?> kebutuhan · <?= $sy_modal['unit_butuh'] ?> unit, seluruhnya teralokasi</dd></div>
<div><dt>Unit Studio</dt><dd><?= $sy_modal['unit_sumber']['Studio'] ?> unit dikonfirmasi, layak pakai, dan diperiksa</dd></div>
<div><dt>Rental</dt><dd><?= $sy_modal['unit_sumber']['Rental'] ?> unit tercatat diterima dari vendor dan diperiksa</dd></div>
<div><dt>Milik Kru</dt><dd><?= $sy_modal['unit_sumber']['Milik Kru'] ?> unit diperiksa petugas</dd></div>
</dl>
<p class="petunjuk">Konfirmasi ini hanya menandai persiapan selesai. Status unit, transaksi serah terima, penerimaan atau pengembalian rental, dan status proyek tidak berubah. Barang tetap harus dicatat keluar di Serah Terima.</p>
<ul class="ceklist">
<li>
<label class="ceklist-item">
<input type="checkbox" name="konfirmasi" value="1">
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama"><span class="daftar-judul">Saya sudah memastikan seluruh perlengkapan diperiksa dan siap dibawa.</span><span class="daftar-sub">Setelah dikonfirmasi, kebutuhan, alokasi, dan checklist dikunci.</span></span>
</label>
</li>
</ul>
<?= pesan_galat('konfirmasi') ?>
<?= dialog_tutup('Konfirmasi Persiapan Selesai') ?>
<?php endif; ?>
<?php if ($np_modal['boleh_buka']): ?>
<?= dialog_buka('dialog-buka-persiapan', 'Buka ulang persiapan?', 'Konfirmasi akhir dicabut dan dicatat di riwayat. Progres checklist tetap tersimpan.') ?>
<input type="hidden" name="aksi" value="buka_ulang_persiapan">
<input type="hidden" name="proyek_id" value="<?= (int) $proyek['id'] ?>">
<div class="bidang">
<label for="buka-persiapan-alasan">Alasan dibuka ulang</label>
<textarea id="buka-persiapan-alasan" name="alasan" maxlength="120" placeholder="Contoh: klien menambah kebutuhan lighting"<?= attr_galat('alasan') ?>><?= e(lama('alasan')) ?></textarea>
<?= pesan_galat('alasan') ?>
</div>
<?= dialog_tutup('Buka ulang persiapan', 'btn btn-bahaya') ?>
<?php endif; ?>
