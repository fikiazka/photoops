<?php
defined('ROOT') || exit;
?>
<dialog class="dialog" id="dialog-reset" aria-labelledby="dialog-reset-judul">
<form method="post" action="<?= e(url('reset.php')) ?>" class="dialog-isi">
<?= csrf_field() ?>
<div class="dialog-kepala">
<div>
<h2 id="dialog-reset-judul">Reset data demo</h2>
<p class="sub">Data kembali ke skenario awal Sabtu, 7 November 2026.</p>
</div>
<button type="button" class="tombol-ikon" data-tutup aria-label="Tutup"><?= ikon('x', 20) ?></button>
</div>
<div class="dialog-badan">
<p>Semua proyek, alokasi, serah terima, dan riwayat yang Anda ubah di sesi ini akan hilang. Anda tetap masuk sebagai <strong><?= e($pengguna['nama']) ?></strong>.</p>
</div>
<div class="dialog-kaki">
<button type="button" class="btn btn-sekunder" data-tutup>Batal</button>
<button type="submit" class="btn btn-bahaya">Reset data</button>
</div>
</form>
</dialog>
