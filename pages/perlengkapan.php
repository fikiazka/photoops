<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('perlengkapan.lihat');

if (is_post()) {
    cek_csrf();
    $aksi = input('aksi');

    if ($aksi === 'simpan_unit') {
        wajib_boleh('perlengkapan.kelola');
        $id = (int) input('id');
        $lama_u = $id ? db_cari('perlengkapan', $id) : null;
        $data = [
            'id' => $id ?: '',
            'kategori_id' => $lama_u ? (string) $lama_u['kategori_id'] : input('kategori_id'),
            'nama' => input('nama'),
            'merek' => input('merek'),
            'kondisi' => input('kondisi'),
            'status_aktif' => input('status_aktif') === 'Nonaktif' ? 'Nonaktif' : 'Aktif',
            'catatan' => input('catatan'),
        ];
        $galat = validasi_unit($data);
        if ($galat) {
            simpan_form('dialog-unit', $data, $galat);
            muat_ulang();
        }
        $isi = ['nama' => $data['nama'], 'merek' => $data['merek'], 'kondisi' => $data['kondisi'], 'status_aktif' => $data['status_aktif'], 'catatan' => $data['catatan']];
        if ($lama_u) {
            db_ubah('perlengkapan', $id, $isi);
            $ganti = $lama_u['kondisi'] !== $isi['kondisi'];
            catat_riwayat('Perlengkapan', label_unit(db_cari('perlengkapan', $id)), $ganti ? 'Kondisi unit diubah' : 'Data unit diperbarui', $ganti ? $lama_u['kondisi'] : '', $ganti ? $isi['kondisi'] : '');
            flash('sukses', $lama_u['kode_unit'] . ' diperbarui.');
        } else {
            $kode = kode_unit_baru((int) $data['kategori_id']);
            db_tambah('perlengkapan', ['kode_unit' => $kode, 'kategori_id' => (int) $data['kategori_id']] + $isi);
            catat_riwayat('Perlengkapan', $kode . ' · ' . $isi['nama'], 'Unit baru ditambahkan ke inventaris');
            flash('sukses', 'Unit ' . $kode . ' · ' . $isi['nama'] . ' ditambahkan.');
        }
        muat_ulang();
    }

    if ($aksi === 'hapus_unit') {
        wajib_boleh('perlengkapan.kelola');
        $unit = db_cari('perlengkapan', (int) input('id'));
        if ($unit) {
            $dipakai = array_filter(db('alokasi'), fn ($a) => $a['sumber'] === 'Studio' && (int) $a['perlengkapan_id'] === (int) $unit['id']);
            if ($dipakai) {
                flash('error', $unit['kode_unit'] . ' punya riwayat alokasi, jadi tidak bisa dihapus. Ubah statusnya menjadi Nonaktif.');
                muat_ulang();
            }
            db_hapus('perlengkapan', $unit['id']);
            catat_riwayat('Perlengkapan', label_unit($unit), 'Unit dihapus dari inventaris');
            flash('sukses', $unit['kode_unit'] . ' dihapus.');
        }
        muat_ulang();
    }

    if ($aksi === 'lapor_insiden') {
        wajib_boleh('insiden.kelola');
        $data = ['perlengkapan_id' => input('perlengkapan_id'), 'jenis' => input('jenis'), 'kondisi' => input('kondisi'), 'proyek_id' => input('proyek_id'), 'deskripsi' => input('deskripsi')];
        $galat = [];
        if (!db_cari('perlengkapan', (int) $data['perlengkapan_id'])) {
            $galat['perlengkapan_id'] = 'Pilih unit.';
        }
        if (!in_array($data['jenis'], ['Rusak', 'Masalah Teknis'], true)) {
            $galat['jenis'] = 'Pilih jenis.';
        }
        if (!in_array($data['kondisi'], ['Maintenance', 'Rusak'], true)) {
            $galat['kondisi'] = 'Pilih kondisi.';
        }
        if (mb_strlen($data['deskripsi']) < 5) {
            $galat['deskripsi'] = 'Jelaskan masalahnya minimal 5 karakter.';
        }
        if ($galat) {
            simpan_form('dialog-insiden', $data, $galat);
            muat_ulang();
        }
        $kode = lapor_insiden((int) $data['perlengkapan_id'], db_cari('proyek', (int) $data['proyek_id']) ? (int) $data['proyek_id'] : null, $data['jenis'], $data['deskripsi'], $data['kondisi']);
        flash('sukses', 'Insiden ' . $kode . ' dicatat. Unit tidak bisa dialokasikan sampai kondisinya kembali Baik.');
        arahkan('pages/perlengkapan.php?tab=insiden');
    }

    if (in_array($aksi, ['tangani_insiden', 'selesaikan_insiden'], true)) {
        wajib_boleh('insiden.kelola');
        $insiden = db_cari('insiden', (int) input('id'));
        if ($insiden) {
            $unit = db_cari('perlengkapan', (int) $insiden['perlengkapan_id']);
            if ($aksi === 'tangani_insiden') {
                db_ubah('insiden', $insiden['id'], ['status' => 'Ditangani']);
                catat_riwayat('Insiden', label_unit($unit), $insiden['kode'] . ' mulai ditangani', $insiden['status'], 'Ditangani');
                flash('sukses', $insiden['kode'] . ' ditandai sedang ditangani.');
            } else {
                db_ubah('insiden', $insiden['id'], ['status' => 'Selesai']);
                $masih = array_filter(insiden_terbuka(), fn ($i) => (int) $i['perlengkapan_id'] === (int) $insiden['perlengkapan_id']);
                if ($unit && !$masih && $unit['kondisi'] !== 'Baik') {
                    db_ubah('perlengkapan', $unit['id'], ['kondisi' => 'Baik']);
                }
                catat_riwayat('Insiden', label_unit($unit), $insiden['kode'] . ' selesai, unit kembali layak pakai', $unit['kondisi'] ?? '', $masih ? ($unit['kondisi'] ?? '') : 'Baik');
                flash('sukses', $insiden['kode'] . ' selesai.' . ($unit && !$masih ? ' ' . $unit['kode_unit'] . ' kembali berkondisi Baik.' : ''));
            }
        }
        muat_ulang();
    }

    muat_ulang();
}

$tab = query('tab') === 'insiden' ? 'insiden' : 'unit';
$kelola = boleh('perlengkapan.kelola');
$ringkas = ringkasan_pemakaian();
$frekuensi = frekuensi_pakai();
$terbuka = insiden_terbuka();

$halaman = ['menu' => 'perlengkapan', 'bagian' => 'Inventaris', 'judul' => 'Perlengkapan'];
require ROOT . '/includes/layout_head.php';

$aksi_kepala = '';
if (boleh('insiden.kelola')) {
    $aksi_kepala .= tombol_modal('dialog-insiden', 'Laporkan insiden', [], 'triangle-alert', 'btn btn-sekunder');
}
if ($kelola) {
    $aksi_kepala .= tombol_modal('dialog-unit', 'Tambah unit', ['_judul' => 'Tambah unit'], 'plus');
}
judul_halaman('Perlengkapan', 'Inventaris unit studio. Status pemakaian dihitung otomatis dari alokasi, serah terima, dan kondisi unit.', $aksi_kepala);
?>
<div class="grid-kpi">
<div class="kartu kpi"><p class="kpi-label">Total unit</p><p class="kpi-angka"><?= count(db('perlengkapan')) ?></p><p class="kpi-ket"><?= count(db('kategori')) ?> kategori</p></div>
<div class="kartu kpi"><p class="kpi-label">Tersedia</p><p class="kpi-angka teks-hijau"><?= $ringkas['Tersedia'] ?></p><p class="kpi-ket">Siap dialokasikan</p></div>
<div class="kartu kpi"><p class="kpi-label">Dipinjam</p><p class="kpi-angka teks-biru"><?= $ringkas['Dipinjam'] ?></p><p class="kpi-ket">Sedang di luar studio</p></div>
<div class="kartu kpi"><p class="kpi-label">Tidak tersedia</p><p class="kpi-angka teks-merah"><?= $ringkas['Tidak Tersedia'] ?></p><p class="kpi-ket">Maintenance, rusak, atau nonaktif</p></div>
</div>

<?= tab_bar(['unit' => 'Unit inventaris', 'insiden' => 'Insiden <span class="chip-angka">' . count($terbuka) . '</span>'], $tab, 'pages/perlengkapan.php') ?>

<?php if ($tab === 'unit'): ?>
<?php
$f_kategori = query('kategori');
$f_status = query('status');
$kata = query('q');
$unit_tampil = array_filter(db('perlengkapan'), function ($u) use ($f_kategori, $f_status, $kata) {
    return ($f_kategori === '' || (string) $u['kategori_id'] === $f_kategori)
        && ($f_status === '' || status_pemakaian($u) === $f_status)
        && cocok($kata, $u['kode_unit'], $u['nama'], $u['merek']);
});
?>
<form method="get" class="kartu saring" role="search">
<input type="hidden" name="tab" value="unit">
<div class="bidang">
<label for="saring-kategori">Kategori</label>
<select id="saring-kategori" name="kategori" data-kirim-otomatis>
<?= opsi(opsi_kategori(), $f_kategori, 'Semua kategori') ?>
</select>
</div>
<div class="bidang">
<label for="saring-status">Pemakaian</label>
<select id="saring-status" name="status" data-kirim-otomatis>
<?= opsi(['Tersedia' => 'Tersedia', 'Dipinjam' => 'Dipinjam', 'Tidak Tersedia' => 'Tidak tersedia'], $f_status, 'Semua status') ?>
</select>
</div>
<div class="bidang bidang-lebar">
<label for="saring-cari">Cari</label>
<div class="cari cari-isi">
<?= ikon('search', 16) ?>
<input id="saring-cari" type="search" name="q" value="<?= e($kata) ?>" placeholder="Kode, model, atau merek">
</div>
</div>
<?php if ($f_kategori !== '' || $f_status !== '' || $kata !== ''): ?>
<a href="<?= e(url('pages/perlengkapan.php')) ?>" class="btn btn-sekunder btn-kecil saring-reset"><?= ikon('x', 16) ?><span>Reset</span></a>
<?php endif; ?>
</form>

<?php if (!$unit_tampil): ?>
<section class="kartu"><?= kosong('Tidak ada unit yang cocok', 'Ubah saringan atau kata kunci pencarian.') ?></section>
<?php endif; ?>

<?php foreach (db('kategori') as $kat): ?>
<?php $unit_kat = array_filter($unit_tampil, fn ($u) => (int) $u['kategori_id'] === (int) $kat['id']); ?>
<?php if (!$unit_kat) { continue; } ?>
<?php usort($unit_kat, fn ($a, $b) => strcmp($a['kode_unit'], $b['kode_unit'])); ?>
<section class="kartu">
<div class="kartu-kepala">
<div><h2><?= e($kat['nama']) ?></h2><p><?= e($kat['kode_awalan']) ?> · <?= count($unit_kat) ?> unit · <?= e($kat['deskripsi']) ?></p></div>
</div>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Unit</th><th>Kondisi</th><th>Pemakaian</th><th class="angka">Dipakai</th><?= $kelola ? '<th><span class="sr">Aksi</span></th>' : '' ?></tr></thead>
<tbody>
<?php foreach ($unit_kat as $u): ?>
<?php
$status = status_pemakaian($u);
$keluar = alokasi_keluar_unit($u['id']);
$berikut = jadwal_berikutnya($u['id']);
?>
<tr>
<td data-label="Unit"><strong><?= e($u['kode_unit']) ?></strong> <?= $u['status_aktif'] !== 'Aktif' ? badge('Nonaktif') : '' ?><div class="kecil redup"><?= e($u['nama']) ?> · <?= e($u['merek']) ?><?= $u['catatan'] !== '' ? ' · ' . e($u['catatan']) : '' ?></div></td>
<td data-label="Kondisi"><?= badge($u['kondisi']) ?></td>
<td data-label="Pemakaian">
<?= badge($status) ?>
<?php if ($keluar): ?>
<?php $pk = db_cari('proyek', $keluar['proyek_id']); ?>
<div class="kecil <?= status_keluar($keluar) === 'Terlambat' ? 'teks-merah' : 'redup' ?>">Di <?= e($pk['kode'] ?? '?') ?> · tempo <?= e(tgl($keluar['tgl_selesai'], 'singkat')) ?><?= status_keluar($keluar) === 'Terlambat' ? ' · terlambat ' . hari_terlambat($keluar) . ' hari' : '' ?></div>
<?php elseif ($berikut): ?>
<?php $pb = db_cari('proyek', $berikut['proyek_id']); ?>
<div class="kecil redup">Berikutnya <?= e($pb['kode'] ?? '?') ?> · <?= e(rentang_tgl($berikut['tgl_mulai'], $berikut['tgl_selesai'])) ?></div>
<?php endif; ?>
</td>
<td data-label="Dipakai" class="angka"><?= $frekuensi[$u['id']] ?? 0 ?>×</td>
<?php if ($kelola): ?>
<td class="kolom-aksi">
<div class="aksi-baris">
<?= tombol_ikon_modal('dialog-unit', 'Ubah ' . $u['kode_unit'], ['id' => $u['id'], 'kategori_id' => $u['kategori_id'], 'nama' => $u['nama'], 'merek' => $u['merek'], 'kondisi' => $u['kondisi'], 'status_aktif' => $u['status_aktif'], 'catatan' => $u['catatan'], '_judul' => 'Ubah ' . $u['kode_unit']], 'pencil') ?>
<?= tombol_konfirmasi(['label' => 'Hapus ' . $u['kode_unit'], 'ikon' => 'trash-2', 'hanya_ikon' => true, 'aksi' => 'hapus_unit', 'id' => $u['id'], 'judul' => 'Hapus ' . $u['kode_unit'] . '?', 'pesan' => 'Hanya unit yang belum pernah dialokasikan yang bisa dihapus. Unit lama sebaiknya dinonaktifkan.', 'tombol' => 'Hapus unit']) ?>
</div>
</td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($tab === 'insiden'): ?>
<?php $insiden = daftar_insiden(); ?>
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Laporan insiden</h2><p><?= count($terbuka) ?> terbuka dari <?= count($insiden) ?> laporan. Insiden dari pengembalian tercatat otomatis.</p></div>
</div>
<?php if (!$insiden): ?>
<?= kosong('Belum ada insiden', 'Laporan kerusakan atau masalah teknis akan muncul di sini.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Insiden</th><th>Unit</th><th>Deskripsi</th><th>Status</th><?= boleh('insiden.kelola') ? '<th><span class="sr">Aksi</span></th>' : '' ?></tr></thead>
<tbody>
<?php foreach ($insiden as $i): ?>
<?php $u = db_cari('perlengkapan', (int) $i['perlengkapan_id']); $p = $i['proyek_id'] ? db_cari('proyek', (int) $i['proyek_id']) : null; ?>
<tr>
<td data-label="Insiden"><strong><?= e($i['kode']) ?></strong><div class="kecil redup"><?= e($i['jenis']) ?> · <?= e(tgl($i['waktu'], 'singkat_jam')) ?></div></td>
<td data-label="Unit"><?= e(label_unit($u)) ?><div class="kecil redup"><?= $p ? e($p['kode']) . ' · ' : '' ?>dilapor <?= e(nama_pengguna($i['pelapor_id'])) ?></div></td>
<td data-label="Deskripsi" class="sel-teks"><?= e($i['deskripsi']) ?></td>
<td data-label="Status"><?= badge($i['status']) ?></td>
<?php if (boleh('insiden.kelola')): ?>
<td class="kolom-aksi">
<div class="aksi-baris">
<?php if ($i['status'] === 'Dilaporkan'): ?>
<?= tombol_konfirmasi(['label' => 'Tangani', 'ikon' => 'wrench', 'aksi' => 'tangani_insiden', 'id' => $i['id'], 'judul' => 'Tangani ' . $i['kode'] . '?', 'pesan' => label_unit($u) . ' sedang diperbaiki atau diservis.', 'tombol' => 'Tandai ditangani', 'aman' => true]) ?>
<?php endif; ?>
<?php if ($i['status'] !== 'Selesai'): ?>
<?= tombol_konfirmasi(['label' => 'Selesai', 'ikon' => 'check', 'aksi' => 'selesaikan_insiden', 'id' => $i['id'], 'judul' => 'Selesaikan ' . $i['kode'] . '?', 'pesan' => 'Jika tidak ada insiden lain yang terbuka, kondisi ' . ($u['kode_unit'] ?? 'unit') . ' kembali Baik dan bisa dialokasikan lagi.', 'tombol' => 'Tandai selesai', 'aman' => true]) ?>
<?php endif; ?>
</div>
</td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php
if ($kelola) {
    require ROOT . '/partials/modal_perlengkapan.php';
}
if (boleh('insiden.kelola')) {
    require ROOT . '/partials/modal_insiden.php';
}
?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
