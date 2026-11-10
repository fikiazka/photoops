<?php
defined('ROOT') || exit;
?>
<header class="topbar">
<button type="button" class="tombol-ikon topbar-menu" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Buka menu">
<?= ikon('menu', 20) ?>
</button>
<nav class="remah" aria-label="Lokasi halaman">
<span class="redup"><?= e($halaman['bagian'] ?? '') ?></span>
<span class="redup remah-pemisah" aria-hidden="true"><?= ikon('chevron-right', 14) ?></span>
<span class="tebal"><?= e($halaman['judul'] ?? '') ?></span>
</nav>
<div class="topbar-akun">
<span class="avatar" aria-hidden="true"><?= e(inisial($pengguna['nama'])) ?></span>
<span class="topbar-nama">
<strong><?= e($pengguna['nama']) ?></strong>
<span class="kecil redup"><?= e(label_peran($pengguna)) ?></span>
</span>
</div>
</header>
