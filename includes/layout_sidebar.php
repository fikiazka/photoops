<?php
defined('ROOT') || exit;

$menu_aktif = $halaman['menu'] ?? '';
?>
<aside class="sidebar" id="sidebar">
<div class="sidebar-merek">
<a href="<?= e(url(beranda($pengguna['peran']))) ?>" class="sidebar-logo">
<img src="<?= e(aset('img/nicio-hitam.png')) ?>" alt="Nicio Studio" width="104" height="44">
</a>
<span class="sidebar-produk">PhotoOps</span>
</div>
<nav class="sidebar-nav" aria-label="Menu utama">
<p class="label-kecil sidebar-label">Ruang kerja</p>
<ul>
<?php foreach (menu_peran($pengguna['peran']) as $item): ?>
<?php $aktif = $item['kunci'] === $menu_aktif; ?>
<li>
<a href="<?= e(url($item['href'])) ?>" class="menu-item<?= $aktif ? ' aktif' : '' ?>"<?= $aktif ? ' aria-current="page"' : '' ?>>
<?= ikon($item['ikon']) ?>
<span><?= e($item['label']) ?></span>
</a>
</li>
<?php endforeach; ?>
</ul>
</nav>
<div class="sidebar-kaki">
<div class="sidebar-akun">
<span class="kecil redup">Masuk sebagai</span>
<strong><?= e($pengguna['nama']) ?></strong>
<span class="kecil redup"><?= e(label_peran($pengguna)) ?></span>
</div>
<button type="button" class="menu-item menu-item-redup" data-buka="dialog-reset">
<?= ikon('rotate-ccw') ?>
<span>Reset data demo</span>
</button>
<a href="<?= e(url('logout.php')) ?>" class="menu-item menu-item-redup">
<?= ikon('log-out') ?>
<span>Keluar</span>
</a>
</div>
</aside>
