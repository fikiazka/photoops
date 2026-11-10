<?php
defined('ROOT') || exit;
?>
<?php if (!$polos): ?>
</main>
</div>
</div>
<div class="sidebar-latar" data-sidebar-tutup hidden></div>
<?php require ROOT . '/partials/modal_reset.php'; ?>
<?php require ROOT . '/partials/modal_konfirmasi.php'; ?>
<?php endif; ?>
<script src="<?= e(aset('js/app.js')) ?>" defer></script>
</body>
</html>
