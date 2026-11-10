<?php
defined('ROOT') || exit;

$id_dialog = 'dialog-alokasi-' . $kd['id'];
$milik_dialog = modal_awal() === $id_dialog;
$nilai = fn (string $kunci, string $bawaan = '') => $milik_dialog ? lama($kunci, $bawaan) : $bawaan;
$galat_di = fn (string $kunci) => $milik_dialog ? pesan_galat($kunci) : '';
$attr_di = fn (string $kunci) => $milik_dialog ? attr_galat($kunci) : '';

$unit_kategori = array_filter(db('perlengkapan'), fn ($u) => (int) $u['kategori_id'] === (int) $kd['kategori_id']);
usort($unit_kategori, function ($a, $b) use ($kd) {
    $cocok_a = $kd['acuan'] !== '' && $a['nama'] === $kd['acuan'] ? 0 : 1;
    $cocok_b = $kd['acuan'] !== '' && $b['nama'] === $kd['acuan'] ? 0 : 1;
    return $cocok_a <=> $cocok_b ?: strcmp($a['kode_unit'], $b['kode_unit']);
});
$opsi_pemilik = [];
foreach ($anggota as $a) {
    $opsi_pemilik[$a['pengguna']['id']] = $a['pengguna']['nama'];
}
$sumber_pilih = $nilai('sumber', 'Studio');
$sisa = max(0, $kd['jumlah'] - pemenuhan_kebutuhan($kd)['terisi']);
?>
<?= dialog_buka($id_dialog, 'Alokasi: ' . $kd['nama'], 'Butuh ' . $kd['jumlah'] . ' · tersisa ' . $sisa . ($kd['acuan'] !== '' ? ' · acuan ' . $kd['acuan'] : ''), 'dialog dialog-lebar') ?>
<input type="hidden" name="aksi" value="tambah_alokasi">
<input type="hidden" name="kebutuhan_id" value="<?= (int) $kd['id'] ?>">
<fieldset class="bidang">
<legend>Sumber barang</legend>
<div class="pilihan-sumber">
<?php foreach (['Studio' => ['camera', 'Unit inventaris studio'], 'Rental' => ['truck', 'Sewa dari vendor luar'], 'Milik Kru' => ['user', 'Dibawa anggota tim']] as $sumber => [$ikon_sumber, $ket_sumber]): ?>
<label class="pil">
<input type="radio" name="sumber" value="<?= e($sumber) ?>" data-bawaan="Studio"<?= $sumber_pilih === $sumber ? ' checked' : '' ?>>
<span class="pil-isi"><?= ikon($ikon_sumber, 18) ?><span><strong><?= e($sumber) ?></strong><span class="kecil redup"><?= e($ket_sumber) ?></span></span></span>
</label>
<?php endforeach; ?>
</div>
</fieldset>
<div class="bidang" data-saat="sumber=Studio">
<label for="<?= e($id_dialog) ?>-unit">Unit inventaris</label>
<select id="<?= e($id_dialog) ?>-unit" name="perlengkapan_id"<?= $attr_di('perlengkapan_id') ?>>
<option value="">Pilih unit</option>
<?php foreach ($unit_kategori as $unit): ?>
<?php $cek = cek_unit_untuk_proyek($unit, $proyek); ?>
<option value="<?= (int) $unit['id'] ?>"<?= $cek['blokir'] !== '' ? ' disabled' : '' ?><?= $nilai('perlengkapan_id') === (string) $unit['id'] ? ' selected' : '' ?>>
<?= e(label_unit($unit) . ' — ' . ($cek['blokir'] !== '' ? $cek['blokir'] : ($cek['catatan'] !== '' ? $cek['catatan'] : 'Tersedia'))) ?>
</option>
<?php endforeach; ?>
</select>
<?= $galat_di('perlengkapan_id') ?>
<p class="petunjuk">Hanya kategori <?= e(nama_kategori($kd['kategori_id'])) ?>. Unit yang bentrok jadwal atau tidak layak tidak bisa dipilih.</p>
</div>
<div class="baris-bidang" data-saat="sumber=Rental|Milik Kru">
<div class="bidang">
<label for="<?= e($id_dialog) ?>-barang">Nama barang</label>
<input id="<?= e($id_dialog) ?>-barang" name="nama_barang" type="text" maxlength="60" value="<?= e($nilai('nama_barang', $kd['acuan'])) ?>" data-bawaan="<?= e($kd['acuan']) ?>"<?= $attr_di('nama_barang') ?>>
<?= $galat_di('nama_barang') ?>
</div>
<div class="bidang" data-saat="sumber=Rental">
<label for="<?= e($id_dialog) ?>-vendor">Vendor rental</label>
<input id="<?= e($id_dialog) ?>-vendor" name="vendor" type="text" maxlength="60" value="<?= e($nilai('vendor')) ?>" placeholder="Contoh: Rental Kamera Malang"<?= $attr_di('vendor') ?>>
<?= $galat_di('vendor') ?>
</div>
<div class="bidang" data-saat="sumber=Milik Kru">
<label for="<?= e($id_dialog) ?>-pemilik">Pemilik barang</label>
<select id="<?= e($id_dialog) ?>-pemilik" name="pemilik_id"<?= $attr_di('pemilik_id') ?>>
<?= opsi($opsi_pemilik, $nilai('pemilik_id'), $opsi_pemilik ? 'Pilih anggota tim' : 'Belum ada anggota tim') ?>
</select>
<?= $galat_di('pemilik_id') ?>
</div>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="<?= e($id_dialog) ?>-status">Status</label>
<select id="<?= e($id_dialog) ?>-status" name="status" data-bawaan="Dikonfirmasi">
<?= opsi(['Dikonfirmasi' => 'Dikonfirmasi', 'Direncanakan' => 'Direncanakan (menunggu)'], $nilai('status', 'Dikonfirmasi')) ?>
</select>
<p class="petunjuk" data-saat="sumber=Rental">Pilih Direncanakan jika vendor belum mengonfirmasi.</p>
</div>
<div class="bidang">
<label for="<?= e($id_dialog) ?>-catatan">Catatan <span class="redup">(opsional)</span></label>
<input id="<?= e($id_dialog) ?>-catatan" name="catatan" type="text" maxlength="120" value="<?= e($nilai('catatan')) ?>">
</div>
</div>
<p class="kecil redup">Periode pemakaian otomatis <?= e(rentang_tgl(...rentang_alokasi($proyek))) ?>, dari hari persiapan sampai acara selesai.</p>
<?= dialog_tutup('Simpan alokasi') ?>
