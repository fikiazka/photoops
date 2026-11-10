<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('serah_terima.lihat');

if (is_post()) {
    cek_csrf();
    wajib_boleh('serah_terima.kelola');
    $aksi = input('aksi');
    $proyek = db_cari('proyek', (int) input('proyek_id'));
    if (!$proyek) {
        flash('error', 'Proyek tidak ditemukan.');
        muat_ulang();
    }
    $pilih = array_map('intval', input_daftar('alokasi'));

    if ($aksi === 'checkout') {
        $modal = 'dialog-keluar-' . $proyek['id'];
        $boleh_pilih = [];
        foreach (unit_siap_keluar($proyek['id']) as $baris) {
            if ($baris['blokir'] === '') {
                $boleh_pilih[] = (int) $baris['alokasi']['id'];
            }
        }
        $galat = [];
        $pilih = array_values(array_intersect($pilih, $boleh_pilih));
        if (!$pilih) {
            $galat['alokasi'] = 'Pilih minimal satu unit yang bisa diserahkan.';
        }
        if (!isset(penerima_proyek($proyek['id'])[input('penerima')])) {
            $galat['penerima'] = 'Pilih penerima dari anggota tim.';
        }
        if ($galat) {
            simpan_form($modal, ['alokasi' => array_map('strval', $pilih), 'penerima' => input('penerima'), 'catatan' => input('catatan')], $galat);
            muat_ulang();
        }
        $kode = proses_checkout($proyek, $pilih, input('penerima'), input('catatan'));
        flash('sukses', $kode . ': ' . count($pilih) . ' unit keluar untuk ' . $proyek['kode'] . ', diterima ' . input('penerima') . '.');
        muat_ulang();
    }

    if ($aksi === 'checkin') {
        $modal = 'dialog-kembali-' . $proyek['id'];
        $di_luar = array_map(fn ($b) => (int) $b['alokasi']['id'], unit_belum_kembali($proyek['id']));
        $pilih = array_values(array_intersect($pilih, $di_luar));
        $kondisi_masuk = is_array($_POST['kondisi'] ?? null) ? $_POST['kondisi'] : [];
        $kondisi = [];
        foreach ($pilih as $id) {
            $k = is_string($kondisi_masuk[$id] ?? null) ? $kondisi_masuk[$id] : 'Baik';
            $kondisi[$id] = in_array($k, daftar_kondisi(), true) ? $k : 'Baik';
        }
        $galat = [];
        if (!$pilih) {
            $galat['alokasi'] = 'Centang minimal satu unit yang dikembalikan.';
        }
        $catatan = input('catatan');
        if (array_filter($kondisi, fn ($k) => $k !== 'Baik') && mb_strlen($catatan) < 5) {
            $galat['catatan'] = 'Jelaskan masalah unit yang tidak dalam kondisi baik.';
        }
        if ($galat) {
            simpan_form($modal, ['alokasi' => array_map('strval', $pilih), 'catatan' => $catatan], $galat);
            muat_ulang();
        }
        $hasil = proses_checkin($proyek, $kondisi, $catatan);
        $pesan = $hasil['kode'] . ': ' . count($kondisi) . ' unit kembali dari ' . $proyek['kode'];
        $pesan .= $hasil['terlambat'] ? ', ' . $hasil['terlambat'] . ' terlambat' : '';
        $pesan .= $hasil['bermasalah'] ? '. Insiden dicatat untuk ' . implode(', ', $hasil['bermasalah']) . '.' : '.';
        flash($hasil['bermasalah'] ? 'info' : 'sukses', $pesan);
        muat_ulang();
    }

    muat_ulang();
}

$kelola = boleh('serah_terima.kelola');
$siap = proyek_siap_keluar();
$belum_kembali = unit_belum_kembali();
$luar = kelompok_per_proyek($belum_kembali);
$transaksi = daftar_transaksi();
$jumlah_siap = 0;
foreach ($siap as $s) {
    $jumlah_siap += count(array_filter($s['unit'], fn ($u) => $u['blokir'] === ''));
}

$halaman = ['menu' => 'serah_terima', 'bagian' => 'Operasional', 'judul' => 'Serah Terima Barang'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Serah Terima Barang', 'Catat unit studio yang keluar ke lokasi dan yang kembali. Barang rental dan milik kru tidak dicatat di sini.');
?>
<div class="grid-kpi">
<div class="kartu kpi"><p class="kpi-label">Siap keluar</p><p class="kpi-angka"><?= $jumlah_siap ?></p><p class="kpi-ket">unit dari <?= count($siap) ?> proyek</p></div>
<div class="kartu kpi"><p class="kpi-label">Sedang di luar</p><p class="kpi-angka teks-biru"><?= count($belum_kembali) ?></p><p class="kpi-ket">unit belum kembali</p></div>
<div class="kartu kpi"><p class="kpi-label">Jatuh tempo hari ini</p><p class="kpi-angka"><?= count(array_filter($belum_kembali, fn ($b) => $b['status'] === 'Hari ini')) ?></p><p class="kpi-ket"><?= e(tgl(HARI_INI, 'panjang')) ?></p></div>
<div class="kartu kpi"><p class="kpi-label">Terlambat</p><p class="kpi-angka teks-merah"><?= count(array_filter($belum_kembali, fn ($b) => $b['status'] === 'Terlambat')) ?></p><p class="kpi-ket">lewat jatuh tempo</p></div>
</div>

<div class="grid-2-rata">
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Siap diserahkan</h2><p>Unit studio yang sudah dikonfirmasi dan belum keluar.</p></div>
</div>
<?php if (!$siap): ?>
<?= kosong('Tidak ada yang menunggu', 'Semua unit yang dialokasikan sudah diserahkan.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($siap as $kelompok_serah): ?>
<?php
$kp = $kelompok_serah['proyek'];
$bisa = count(array_filter($kelompok_serah['unit'], fn ($u) => $u['blokir'] === ''));
$tertahan = count($kelompok_serah['unit']) - $bisa;
$prs = persiapan_proyek($kp);
?>
<li class="daftar-baris">
<span class="ikon-bulat ikon-biru"><?= ikon('arrow-up-right', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><a href="<?= e(url('pages/proyek-detail.php?id=' . $kp['id'] . '&tab=serah')) ?>"><?= e($kp['kode']) ?> · <?= e($kp['nama']) ?></a></p>
<p class="daftar-sub">Mulai <?= e(tgl($kp['mulai'], 'singkat_jam')) ?> · <?= $bisa ?> unit siap<?= $tertahan ? ' · <span class="teks-merah">' . $tertahan . ' tertahan</span>' : '' ?> · persiapan <?= $prs['selesai'] ?>/<?= $prs['total'] ?></p>
</div>
<?php if ($kelola && $bisa): ?>
<button type="button" class="btn btn-kecil" data-buka="dialog-keluar-<?= (int) $kp['id'] ?>"><?= ikon('arrow-up-right', 16) ?><span>Keluarkan</span></button>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Sedang di luar</h2><p>Diurutkan dari jatuh tempo paling dekat.</p></div>
</div>
<?php if (!$luar): ?>
<?= kosong('Semua unit ada di studio', 'Tidak ada unit yang sedang dipinjam proyek.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($luar as $kelompok_serah): ?>
<?php $kp = $kelompok_serah['proyek']; $terlambat = count(array_filter($kelompok_serah['unit'], fn ($u) => $u['status'] === 'Terlambat')); ?>
<li class="daftar-baris daftar-baris-atas">
<span class="ikon-bulat ikon-<?= $terlambat ? 'merah' : 'hijau' ?>"><?= ikon('arrow-down-left', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><a href="<?= e(url('pages/proyek-detail.php?id=' . $kp['id'] . '&tab=serah')) ?>"><?= e($kp['kode']) ?> · <?= e($kp['nama']) ?></a></p>
<ul class="unit-mini">
<?php foreach ($kelompok_serah['unit'] as $u): ?>
<li><span><?= e($u['unit']['kode_unit'] ?? '?') ?></span> <?= badge($u['status'], $u['status'] === 'Terlambat' ? 'Terlambat ' . hari_terlambat($u['alokasi']) . ' hari' : ($u['status'] === 'Hari ini' ? 'Tempo hari ini' : 'Tempo ' . tgl($u['alokasi']['tgl_selesai'], 'singkat'))) ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php if ($kelola): ?>
<button type="button" class="btn btn-sekunder btn-kecil" data-buka="dialog-kembali-<?= (int) $kp['id'] ?>"><?= ikon('arrow-down-left', 16) ?><span>Terima kembali</span></button>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
</div>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Riwayat transaksi</h2><p><?= count($transaksi) ?> transaksi. Buka baris untuk melihat unit dan kondisinya.</p></div>
</div>
<?php if (!$transaksi): ?>
<?= kosong('Belum ada transaksi', 'Transaksi barang keluar dan kembali akan tercatat di sini.') ?>
<?php else: ?>
<div class="trx-daftar">
<?php foreach ($transaksi as $trx): ?>
<details class="trx">
<summary>
<span class="ikon-bulat ikon-<?= $trx['jenis'] === 'Keluar' ? 'biru' : 'hijau' ?>"><?= ikon($trx['jenis'] === 'Keluar' ? 'arrow-up-right' : 'arrow-down-left', 16) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($trx['kode']) ?> · <?= e($trx['jenis'] === 'Keluar' ? 'Barang keluar' : 'Pengembalian') ?></span>
<span class="daftar-sub"><?= e($trx['proyek']['kode'] ?? '?') ?> · <?= e(tgl($trx['waktu'], 'lengkap')) ?> · <?= count($trx['detail']) ?> unit</span>
</span>
<span class="trx-orang kecil redup"><?= e($trx['jenis'] === 'Keluar' ? 'Diterima ' . $trx['penerima'] : 'Dicek ' . nama_pengguna($trx['petugas_id'])) ?></span>
<span class="trx-panah" aria-hidden="true"><?= ikon('chevron-right', 16) ?></span>
</summary>
<div class="trx-isi">
<ul class="unit-mini">
<?php foreach ($trx['detail'] as $d): ?>
<?php $a = db_cari('alokasi', $d['alokasi_id']); ?>
<li><span><?= e($a ? keterangan_sumber($a) . ' · ' . nama_barang_alokasi($a) : 'Alokasi dihapus') ?></span> <?= badge($d['kondisi']) ?></li>
<?php endforeach; ?>
</ul>
<?php if ($trx['catatan'] !== ''): ?><p class="kecil redup">Catatan: <?= e($trx['catatan']) ?></p><?php endif; ?>
<p class="kecil redup">Petugas: <?= e(nama_pengguna($trx['petugas_id'])) ?></p>
</div>
</details>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<?php
if ($kelola) {
    $jenis_serah = 'keluar';
    foreach ($siap as $kelompok_serah) {
        require ROOT . '/partials/modal_serah_terima.php';
    }
    $jenis_serah = 'kembali';
    foreach ($luar as $kelompok_serah) {
        require ROOT . '/partials/modal_serah_terima.php';
    }
}
?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
