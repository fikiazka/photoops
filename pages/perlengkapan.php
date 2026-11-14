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

$tab = in_array(query('tab'), ['eksternal', 'insiden'], true) ? query('tab') : 'unit';
$kelola = boleh('perlengkapan.kelola');
$ringkas = ringkasan_pemakaian();
$frekuensi = frekuensi_pakai();
$terbuka = insiden_terbuka();
$eksternal = daftar_barang_eksternal();
$ringkas_eks = ringkasan_eksternal($eksternal);

$halaman = ['menu' => 'perlengkapan', 'bagian' => 'Inventaris', 'judul' => 'Perlengkapan'];
require ROOT . '/includes/layout_head.php';

$aksi_kepala = '';
if (boleh('insiden.kelola')) {
    $aksi_kepala .= tombol_modal('dialog-insiden', 'Laporkan insiden', [], 'triangle-alert', 'btn btn-sekunder');
}
if ($kelola && $tab !== 'eksternal') {
    $aksi_kepala .= tombol_modal('dialog-unit', 'Tambah unit', ['_judul' => 'Tambah unit'], 'plus');
}
judul_halaman('Perlengkapan', $tab === 'eksternal' ? 'Barang Rental dan Milik Kru yang dialokasikan ke proyek. Barang ini sementara, bukan aset tetap studio.' : 'Inventaris unit studio. Status pemakaian dihitung otomatis dari alokasi, serah terima, dan kondisi unit.', $aksi_kepala);
?>
<?php if ($tab !== 'eksternal'): ?>
<div class="grid-kpi">
<div class="kartu kpi"><p class="kpi-label">Total unit</p><p class="kpi-angka"><?= count(db('perlengkapan')) ?></p><p class="kpi-ket"><?= count(db('kategori')) ?> kategori</p></div>
<div class="kartu kpi"><p class="kpi-label">Tersedia</p><p class="kpi-angka teks-hijau"><?= $ringkas['Tersedia'] ?></p><p class="kpi-ket">Siap dialokasikan</p></div>
<div class="kartu kpi"><p class="kpi-label">Dipinjam</p><p class="kpi-angka teks-biru"><?= $ringkas['Dipinjam'] ?></p><p class="kpi-ket">Sedang di luar studio</p></div>
<div class="kartu kpi"><p class="kpi-label">Tidak tersedia</p><p class="kpi-angka teks-merah"><?= $ringkas['Tidak Tersedia'] ?></p><p class="kpi-ket">Maintenance, rusak, atau nonaktif</p></div>
</div>
<?php endif; ?>

<?= tab_bar(['unit' => 'Unit inventaris', 'eksternal' => 'Barang eksternal <span class="chip-angka">' . $ringkas_eks['alokasi_aktif'] . '</span>', 'insiden' => 'Insiden <span class="chip-angka">' . count($terbuka) . '</span>'], $tab, 'pages/perlengkapan.php') ?>

<?php if ($tab === 'eksternal'): ?>
<?php
$f_sumber = in_array(query('sumber'), ['Rental', 'Milik Kru'], true) ? query('sumber') : '';
$f_status_eks = in_array(query('status'), ['Direncanakan', 'Dikonfirmasi', 'Dibatalkan'], true) ? query('status') : '';
$kata_eks = query('q');
$eks_tampil = array_values(array_filter($eksternal, function ($b) use ($f_sumber, $f_status_eks, $kata_eks) {
    $a = $b['alokasi'];
    return ($f_sumber === '' || $a['sumber'] === $f_sumber)
        && ($f_status_eks === '' || $a['status'] === $f_status_eks)
        && cocok($kata_eks, $a['nama_barang'], $b['kebutuhan']['nama'] ?? '', $b['proyek']['kode'] ?? '', $b['proyek']['nama'] ?? '', $b['asal']);
}));
?>
<div class="grid-kpi">
<div class="kartu kpi"><p class="kpi-label">Alokasi eksternal aktif</p><p class="kpi-angka"><?= $ringkas_eks['alokasi_aktif'] ?></p><p class="kpi-ket">baris alokasi, tanpa yang Dibatalkan</p></div>
<div class="kartu kpi"><p class="kpi-label">Unit Rental</p><p class="kpi-angka"><?= $ringkas_eks['unit_rental'] ?></p><p class="kpi-ket">kuantitas barang dari vendor</p></div>
<div class="kartu kpi"><p class="kpi-label">Unit Milik Kru</p><p class="kpi-angka"><?= $ringkas_eks['unit_kru'] ?></p><p class="kpi-ket">kuantitas barang dibawa anggota tim</p></div>
<div class="kartu kpi"><p class="kpi-label">Masih direncanakan</p><p class="kpi-angka<?= $ringkas_eks['direncanakan'] ? ' teks-kuning' : '' ?>"><?= $ringkas_eks['direncanakan'] ?></p><p class="kpi-ket">baris alokasi · <?= $ringkas_eks['unit_direncanakan'] ?> unit belum dikonfirmasi</p></div>
</div>

<form method="get" class="kartu saring" role="search">
<input type="hidden" name="tab" value="eksternal">
<div class="bidang">
<label for="saring-sumber">Sumber</label>
<select id="saring-sumber" name="sumber" data-kirim-otomatis>
<?= opsi(['Rental' => 'Rental', 'Milik Kru' => 'Milik Kru'], $f_sumber, 'Semua sumber') ?>
</select>
</div>
<div class="bidang">
<label for="saring-status-eks">Status alokasi</label>
<select id="saring-status-eks" name="status" data-kirim-otomatis>
<?= opsi(['Direncanakan' => 'Direncanakan', 'Dikonfirmasi' => 'Dikonfirmasi', 'Dibatalkan' => 'Dibatalkan'], $f_status_eks, 'Semua status') ?>
</select>
</div>
<div class="bidang bidang-lebar">
<label for="saring-cari-eks">Cari</label>
<div class="cari cari-isi">
<?= ikon('search', 16) ?>
<input id="saring-cari-eks" type="search" name="q" value="<?= e($kata_eks) ?>" placeholder="Barang, proyek, vendor, atau pemilik">
</div>
</div>
<?php if ($f_sumber !== '' || $f_status_eks !== '' || $kata_eks !== ''): ?>
<a href="<?= e(url('pages/perlengkapan.php?tab=eksternal')) ?>" class="btn btn-sekunder btn-kecil saring-reset"><?= ikon('x', 16) ?><span>Reset</span></a>
<?php endif; ?>
</form>

<section class="kartu">
<div class="kartu-kepala">
<div>
<h2>Barang eksternal</h2>
<p data-jumlah-eksternal><?= count($eks_tampil) ?> dari <?= count($eksternal) ?> alokasi ditampilkan. Dikonfirmasi berarti alokasi disepakati, belum berarti barang sudah diterima. Penerimaan, jatuh tempo, dan pengembalian ke vendor belum dicatat di sistem.</p>
</div>
</div>
<?php if (!$eksternal): ?>
<?= kosong('Belum ada barang eksternal', 'Barang Rental dan Milik Kru muncul otomatis setelah dialokasikan di proyek.') ?>
<?php elseif (!$eks_tampil): ?>
<?= kosong('Tidak ada barang eksternal yang cocok', 'Ubah saringan atau kata kunci pencarian.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Barang</th><th class="angka">Jumlah</th><th>Proyek &amp; periode</th><th>Sumber</th><th>Status</th><th>Pemeriksaan</th><th><span class="sr">Aksi</span></th></tr></thead>
<tbody>
<?php foreach ($eks_tampil as $b): ?>
<?php $a = $b['alokasi']; $pr = $b['proyek']; ?>
<tr>
<td data-label="Barang"><strong><?= e($a['nama_barang'] !== '' ? $a['nama_barang'] : 'Belum dicatat') ?></strong><div class="kecil redup">Untuk <?= e($b['kebutuhan']['nama'] ?? 'kebutuhan dihapus') ?><?= $a['catatan'] !== '' ? ' · ' . e($a['catatan']) : '' ?></div></td>
<td data-label="Jumlah" class="angka"><?= (int) $b['jumlah'] ?> unit</td>
<td data-label="Proyek &amp; periode"><strong><?= e($pr['kode'] ?? '?') ?></strong><div class="kecil redup"><?= e($pr['nama'] ?? 'Proyek tidak ditemukan') ?><?= $pr ? ' · ' . e($pr['status']) : '' ?></div><div class="kecil">Pakai <?= e(rentang_tgl($a['tgl_mulai'], $a['tgl_selesai'])) ?></div></td>
<td data-label="Sumber"><span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= ikon($a['sumber'] === 'Rental' ? 'truck' : 'user', 14) ?><?= e($a['sumber']) ?></span><div class="kecil redup"><?= $a['sumber'] === 'Rental' ? 'Vendor' : 'Pemilik' ?>: <?= e($b['asal'] !== '' ? $b['asal'] : 'Belum dicatat') ?></div><div class="kecil redup"><?= $a['sumber'] === 'Rental' ? 'Tempo vendor: Belum dicatat' : 'Tanpa tempo vendor' ?></div></td>
<td data-label="Status"><?= badge($a['status']) ?><?= label_status_alokasi($a) !== $a['status'] ? '<div class="kecil redup">' . e(label_status_alokasi($a)) . '</div>' : '' ?></td>
<td data-label="Pemeriksaan">
<?php if ($a['status'] === 'Dibatalkan'): ?>
<span class="kecil redup">—</span>
<?php elseif ($a['status'] !== 'Dikonfirmasi'): ?>
<span class="kecil redup">Menunggu konfirmasi</span>
<?php elseif ($b['diperiksa_aktif']): ?>
<?= badge('Diperiksa Petugas') ?>
<?php elseif ($b['diperiksa']): ?>
<span class="kecil redup">Pernah diperiksa (proyek ditutup)</span>
<?php else: ?>
<span class="kecil redup">Belum diperiksa</span>
<?php endif; ?>
</td>
<td class="kolom-aksi"><?php if ($pr): ?><a href="<?= e(url('pages/proyek-detail.php?id=' . (int) $pr['id'] . '&tab=kebutuhan')) ?>" class="tombol-ikon tombol-ikon-kecil" aria-label="Lihat proyek <?= e($pr['kode']) ?>" title="Lihat proyek <?= e($pr['kode']) ?>"><?= ikon('chevron-right', 16) ?></a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

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
