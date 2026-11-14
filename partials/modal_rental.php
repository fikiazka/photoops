<?php
defined('ROOT') || exit;

$opsi_kondisi = array_combine(daftar_kondisi(), daftar_kondisi());
$batas_waktu = waktu_input(sekarang());
$info_barang = function (): string {
    return '<div class="baris-bidang">'
        . '<div class="bidang"><span class="label-kecil">Barang</span><p data-isi-teks="_barang">' . e(lama('_barang')) . '</p></div>'
        . '<div class="bidang"><span class="label-kecil">Vendor</span><p data-isi-teks="_vendor">' . e(lama('_vendor')) . '</p></div>'
        . '</div>'
        . '<input type="hidden" name="id" value="' . e(lama('id')) . '">'
        . '<input type="hidden" name="proyek_id" value="' . e(lama('proyek_id')) . '">';
};
?>
<?= dialog_buka('dialog-terima-rental', 'Catat penerimaan rental', 'Catat hanya setelah barang dari vendor benar-benar diterima secara fisik.') ?>
<input type="hidden" name="aksi" value="terima_rental">
<?= $info_barang() ?>
<div class="baris-bidang">
<div class="bidang">
<span class="label-kecil">Jumlah diterima</span>
<p><span data-isi-teks="_jumlah"><?= e(lama('_jumlah')) ?></span></p>
<input type="hidden" name="jumlah" value="<?= e(lama('jumlah')) ?>">
<?= pesan_galat('jumlah') ?>
<p class="petunjuk">Seluruh unit alokasi dicatat sekaligus. Penerimaan sebagian belum didukung.</p>
</div>
<div class="bidang">
<label for="terima-waktu">Waktu diterima</label>
<input id="terima-waktu" name="waktu" type="datetime-local" max="<?= e($batas_waktu) ?>" value="<?= e(lama('waktu')) ?>"<?= attr_galat('waktu') ?>>
<?= pesan_galat('waktu') ?>
</div>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="terima-kondisi">Kondisi saat diterima</label>
<select id="terima-kondisi" name="kondisi" data-bawaan="Baik"<?= attr_galat('kondisi') ?>>
<?= opsi($opsi_kondisi, lama('kondisi', 'Baik')) ?>
</select>
<?= pesan_galat('kondisi') ?>
</div>
<div class="bidang">
<label for="terima-catatan">Catatan <span class="redup">(wajib jika tidak Baik)</span></label>
<input id="terima-catatan" name="catatan" type="text" maxlength="120" value="<?= e(lama('catatan')) ?>"<?= attr_galat('catatan') ?>>
<?= pesan_galat('catatan') ?>
</div>
</div>
<ul class="ceklist">
<li>
<label class="ceklist-item">
<input type="checkbox" name="konfirmasi" value="1">
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama"><span class="daftar-judul">Barang sudah saya terima secara fisik dari vendor.</span><span class="daftar-sub">Ini belum mencentang checklist Persiapan proyek.</span></span>
</label>
</li>
</ul>
<?= pesan_galat('konfirmasi') ?>
<?= dialog_tutup('Simpan penerimaan') ?>

<?= dialog_buka('dialog-atur-tempo', 'Atur jatuh tempo vendor', 'Tanggal barang harus dikembalikan ke vendor. Berbeda dari tanggal selesai proyek.') ?>
<input type="hidden" name="aksi" value="atur_jatuh_tempo">
<?= $info_barang() ?>
<div class="baris-bidang">
<div class="bidang">
<label for="tempo-mulai">Mulai sewa <span class="redup">(opsional)</span></label>
<input id="tempo-mulai" name="sewa_mulai" type="date" value="<?= e(lama('sewa_mulai')) ?>"<?= attr_galat('sewa_mulai') ?>>
<?= pesan_galat('sewa_mulai') ?>
</div>
<div class="bidang">
<label for="tempo-baru">Jatuh tempo ke vendor</label>
<input id="tempo-baru" name="jatuh_tempo" type="date" value="<?= e(lama('jatuh_tempo')) ?>"<?= attr_galat('jatuh_tempo') ?>>
<?= pesan_galat('jatuh_tempo') ?>
</div>
</div>
<div class="bidang">
<label for="tempo-kontak">Kontak vendor <span class="redup">(opsional)</span></label>
<input id="tempo-kontak" name="kontak_vendor" type="text" maxlength="60" placeholder="Contoh: 0812-xxxx atau nama admin" value="<?= e(lama('kontak_vendor')) ?>"<?= attr_galat('kontak_vendor') ?>>
<?= pesan_galat('kontak_vendor') ?>
</div>
<?= dialog_tutup('Simpan jatuh tempo') ?>

<?= dialog_buka('dialog-perpanjang-rental', 'Perpanjang rental', 'Jadwal proyek tidak ikut berubah.') ?>
<input type="hidden" name="aksi" value="perpanjang_rental">
<?= $info_barang() ?>
<div class="baris-bidang">
<div class="bidang">
<span class="label-kecil">Jatuh tempo saat ini</span>
<p data-isi-teks="_tempo_lama"><?= e(lama('_tempo_lama')) ?></p>
</div>
<div class="bidang">
<label for="perpanjang-baru">Jatuh tempo baru</label>
<input id="perpanjang-baru" name="jatuh_tempo" type="date" value="<?= e(lama('jatuh_tempo')) ?>"<?= attr_galat('jatuh_tempo') ?>>
<?= pesan_galat('jatuh_tempo') ?>
</div>
</div>
<div class="bidang">
<label for="perpanjang-alasan">Alasan <span class="redup">(opsional)</span></label>
<input id="perpanjang-alasan" name="alasan" type="text" maxlength="120" placeholder="Contoh: acara diundur satu hari" value="<?= e(lama('alasan')) ?>"<?= attr_galat('alasan') ?>>
<?= pesan_galat('alasan') ?>
<p class="petunjuk">Tanggal lama dan baru tercatat di Riwayat Aktivitas.</p>
</div>
<?= dialog_tutup('Simpan perpanjangan') ?>

<?= dialog_buka('dialog-kembali-vendor', 'Kembalikan ke vendor', 'Catat saat barang sudah diserahkan kembali kepada vendor.') ?>
<input type="hidden" name="aksi" value="kembalikan_vendor">
<?= $info_barang() ?>
<div class="pemberitahuan pemberitahuan-info" role="note"><?= ikon('info') ?><span>Pencatatan ini belum terhubung dengan serah terima proyek: sistem belum mencatat barang Rental keluar dan kembali dari lokasi proyek. Pastikan barang sudah kembali dari proyek sebelum diserahkan ke vendor.</span></div>
<div class="baris-bidang">
<div class="bidang">
<span class="label-kecil">Jumlah dikembalikan</span>
<p><span data-isi-teks="_jumlah"><?= e(lama('_jumlah')) ?></span></p>
<input type="hidden" name="jumlah" value="<?= e(lama('jumlah')) ?>">
<?= pesan_galat('jumlah') ?>
<p class="petunjuk">Seluruh unit alokasi dikembalikan sekaligus. Pengembalian sebagian belum didukung.</p>
</div>
<div class="bidang">
<label for="kembali-waktu">Waktu diserahkan ke vendor</label>
<input id="kembali-waktu" name="waktu" type="datetime-local" max="<?= e($batas_waktu) ?>" value="<?= e(lama('waktu')) ?>"<?= attr_galat('waktu') ?>>
<?= pesan_galat('waktu') ?>
</div>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="kembali-kondisi">Kondisi saat dikembalikan</label>
<select id="kembali-kondisi" name="kondisi" data-bawaan="Baik"<?= attr_galat('kondisi') ?>>
<?= opsi($opsi_kondisi, lama('kondisi', 'Baik')) ?>
</select>
<?= pesan_galat('kondisi') ?>
</div>
<div class="bidang">
<label for="kembali-catatan">Catatan <span class="redup">(wajib jika tidak Baik)</span></label>
<input id="kembali-catatan" name="catatan" type="text" maxlength="120" value="<?= e(lama('catatan')) ?>"<?= attr_galat('catatan') ?>>
<?= pesan_galat('catatan') ?>
</div>
</div>
<ul class="ceklist">
<li>
<label class="ceklist-item">
<input type="checkbox" name="konfirmasi" value="1">
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama"><span class="daftar-judul">Barang sudah saya serahkan kepada vendor.</span><span class="daftar-sub">Setelah ini rental tidak bisa diperpanjang lagi.</span></span>
</label>
</li>
</ul>
<?= pesan_galat('konfirmasi') ?>
<?= dialog_tutup('Simpan pengembalian') ?>
