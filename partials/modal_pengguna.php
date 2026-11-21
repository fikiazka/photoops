<?php
defined('ROOT') || exit;
?>
<?= dialog_buka('dialog-pengguna', lama('id') !== '' ? 'Ubah pengguna' : 'Tambah pengguna', 'Kata sandi awal dibuat acak dan ditampilkan sekali setelah akun disimpan.', 'dialog dialog-lebar') ?>
<input type="hidden" name="aksi" value="simpan_pengguna">
<input type="hidden" name="id" value="<?= e(lama('id')) ?>">
<div class="baris-bidang">
<div class="bidang">
<label for="pengguna-nama">Nama lengkap</label>
<input id="pengguna-nama" name="nama" type="text" maxlength="60" value="<?= e(lama('nama')) ?>"<?= attr_galat('nama') ?>>
<?= pesan_galat('nama') ?>
</div>
<div class="bidang">
<label for="pengguna-username">Nama pengguna</label>
<input id="pengguna-username" name="username" type="text" maxlength="30" autocapitalize="none" spellcheck="false" value="<?= e(lama('username')) ?>" placeholder="contoh: sari.dewi"<?= attr_galat('username') ?>>
<?= pesan_galat('username') ?>
</div>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="pengguna-email">Email</label>
<input id="pengguna-email" name="email" type="email" maxlength="80" value="<?= e(lama('email')) ?>"<?= attr_galat('email') ?>>
<?= pesan_galat('email') ?>
</div>
<div class="bidang">
<label for="pengguna-telepon">Telepon <span class="redup">(opsional)</span></label>
<input id="pengguna-telepon" name="telepon" type="tel" maxlength="20" value="<?= e(lama('telepon')) ?>"<?= attr_galat('telepon') ?>>
<?= pesan_galat('telepon') ?>
</div>
</div>
<div class="baris-bidang">
<div class="bidang">
<label for="pengguna-peran">Peran</label>
<select id="pengguna-peran" name="peran" data-bawaan="us"<?= attr_galat('peran') ?>>
<?= opsi(daftar_peran(), lama('peran', 'us')) ?>
</select>
<?= pesan_galat('peran') ?>
</div>
<div class="bidang">
<label for="pengguna-jenis">Jenis anggota</label>
<select id="pengguna-jenis" name="jenis_anggota" data-bawaan="Internal"<?= attr_galat('jenis_anggota') ?>>
<?= opsi(['Internal' => 'Internal', 'Freelancer' => 'Freelancer'], lama('jenis_anggota', 'Internal')) ?>
</select>
<?= pesan_galat('jenis_anggota') ?>
</div>
</div>
<p class="kecil redup">User hanya bisa melihat proyek yang menugaskannya. Petugas Inventaris mengelola barang. Super Admin punya semua akses.</p>
<?= dialog_tutup('Simpan pengguna') ?>
