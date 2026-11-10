<?php
require __DIR__ . '/includes/bootstrap.php';

$sudah_masuk = pengguna_masuk();
if ($sudah_masuk && $sudah_masuk['status'] === 'Aktif') {
    arahkan(beranda($sudah_masuk['peran']));
}

$halaman = ['polos' => true, 'judul' => 'Masuk'];
require ROOT . '/includes/layout_head.php';
?>
<div class="login">
<section class="login-panel">
<img src="<?= e(aset('img/nicio-putih.png')) ?>" alt="Nicio Studio" width="176" height="74" class="login-logo">
<div class="login-pesan">
<h1>Kelola proyek dan perlengkapan studio dalam satu tempat.</h1>
<p>Dari kebutuhan proyek, alokasi perlengkapan, persiapan, barang keluar, sampai pengembalian.</p>
</div>
<p class="login-kaki">PhotoOps · sistem internal Nicio Studio, Malang</p>
</section>
<section class="login-isi">
<form method="post" action="<?= e(url('auth.php')) ?>" class="login-form" novalidate>
<?= csrf_field() ?>
<div>
<h2>Masuk</h2>
<p class="sub">Gunakan akun yang diberikan Super Admin.</p>
</div>
<?php require ROOT . '/includes/flash.php'; ?>
<?php if (galat('umum') !== ''): ?>
<div class="pemberitahuan pemberitahuan-error" role="alert">
<?= ikon('triangle-alert') ?>
<span><?= e(galat('umum')) ?></span>
</div>
<?php endif; ?>
<div class="bidang">
<label for="username">Nama pengguna</label>
<input id="username" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e(lama('username')) ?>"<?= attr_galat('username') ?>>
<?= pesan_galat('username') ?>
</div>
<div class="bidang">
<label for="password">Kata sandi</label>
<div class="sandi">
<input id="password" name="password" type="password" autocomplete="current-password"<?= attr_galat('password') ?>>
<button type="button" class="tombol-ikon sandi-tombol" data-lihat-sandi="password" aria-label="Tampilkan kata sandi" aria-pressed="false">
<span data-ikon-tampil><?= ikon('eye') ?></span>
<span data-ikon-sembunyi hidden><?= ikon('eye-off') ?></span>
</button>
</div>
<?= pesan_galat('password') ?>
</div>
<button type="submit" class="btn btn-blok">Masuk</button>
<p class="kecil redup login-bantuan">Lupa kata sandi? Hubungi Super Admin.</p>
</form>
</section>
</div>
<?php require ROOT . '/includes/layout_foot.php'; ?>
