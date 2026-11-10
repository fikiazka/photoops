<?php
defined('ROOT') || exit;
?>
<dialog class="dialog dialog-kecil" id="dialog-konfirmasi" aria-labelledby="dialog-konfirmasi-judul">
<form method="post" class="dialog-isi">
<?= csrf_field() ?>
<input type="hidden" name="aksi" value="">
<input type="hidden" name="id" value="">
<div class="dialog-kepala">
<h2 id="dialog-konfirmasi-judul" data-isi-teks="_judul">Konfirmasi</h2>
<button type="button" class="tombol-ikon" data-tutup aria-label="Tutup"><?= ikon('x', 20) ?></button>
</div>
<div class="dialog-badan">
<p data-isi-teks="_pesan">Lanjutkan tindakan ini?</p>
</div>
<div class="dialog-kaki">
<button type="button" class="btn btn-sekunder" data-tutup>Batal</button>
<button type="submit" class="btn btn-bahaya" data-isi-teks="_tombol">Lanjutkan</button>
</div>
</form>
</dialog>
