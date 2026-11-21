<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('perlengkapan.lihat');

if (is_post()) {
    cek_csrf();
    $aksi = input('aksi');

    if ($aksi === 'simpan_unit') {
        wajib_boleh('perlengkapan.kelola');
        $id = (int) input('id');
        $lama_u = $id ? cari_perlengkapan($id) : null;
        if ($id && !$lama_u) {
            flash('error', 'Unit tidak ditemukan.');
            muat_ulang();
        }
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
        try {
            // Perubahan unit dan catatan riwayatnya disimpan dalam satu transaksi (lihat logic_perlengkapan.php).
            if ($lama_u) {
                if (ubah_unit($id, $isi)) {
                    flash('sukses', $lama_u['kode_unit'] . ' diperbarui.');
                } else {
                    flash('error', 'Unit tidak ditemukan.');
                }
            } else {
                $kode = tambah_unit((int) $data['kategori_id'], $isi);
                if ($kode === null) {
                    simpan_form('dialog-unit', $data, ['kategori_id' => 'Pilih kategori.']);
                } else {
                    flash('sukses', 'Unit ' . $kode . ' · ' . $isi['nama'] . ' ditambahkan.');
                }
            }
        } catch (PDOException $e) {
            error_log('PhotoOps: simpan unit gagal: ' . $e->getMessage());
            flash('error', 'Data unit gagal disimpan. Coba lagi.');
        }
        muat_ulang();
    }

    // KEBIJAKAN SEMENTARA (sistem hybrid): unit tidak dihapus permanen. Alokasi dan insiden masih disimpan di
    // PHP Session per browser, jadi pemakaian unit di browser lain tidak bisa diperiksa dari server.
    // Unit yang tidak dipakai lagi cukup dinonaktifkan; datanya tetap ada untuk riwayat.
    if ($aksi === 'hapus_unit') {
        wajib_boleh('perlengkapan.kelola');
        $unit = cari_perlengkapan((int) input('id'));
        if ($unit) {
            flash('error', $unit['kode_unit'] . ' tidak dihapus: pemakaian unit di proyek belum dapat diverifikasi menyeluruh. Ubah statusnya menjadi Nonaktif.');
        }
        muat_ulang();
    }

    if ($aksi === 'lapor_insiden') {
        wajib_boleh('insiden.kelola');
        $data = ['perlengkapan_id' => input('perlengkapan_id'), 'jenis' => input('jenis'), 'kondisi' => input('kondisi'), 'proyek_id' => input('proyek_id'), 'deskripsi' => input('deskripsi')];
        $galat = [];
        if (!cari_perlengkapan((int) $data['perlengkapan_id'])) {
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
        $proyek_insiden = cari_proyek((int) $data['proyek_id']) ? (int) $data['proyek_id'] : null;
        try {
            $kode = proses_session_dan_inventaris(fn () => lapor_insiden((int) $data['perlengkapan_id'], $proyek_insiden, $data['jenis'], $data['deskripsi'], $data['kondisi']));
        } catch (Throwable $e) {
            if (!galat_db($e)) {
                throw $e;
            }
            error_log('PhotoOps: lapor insiden gagal: ' . $e->getMessage());
            flash('error', 'Insiden belum dicatat karena database sedang bermasalah. Tidak ada data yang berubah; coba lagi.');
            muat_ulang();
        }
        flash('sukses', 'Insiden ' . $kode . ' dicatat. Unit tidak bisa dialokasikan sampai kondisinya kembali Baik.');
        arahkan('pages/perlengkapan.php?tab=insiden');
    }

    if (in_array($aksi, ['tangani_insiden', 'selesaikan_insiden'], true)) {
        wajib_boleh('insiden.kelola');
        $insiden = db_cari('insiden', (int) input('id'));
        if ($insiden) {
            $unit = cari_perlengkapan((int) $insiden['perlengkapan_id']);
            if ($aksi === 'tangani_insiden') {
                db_ubah('insiden', $insiden['id'], ['status' => 'Ditangani']);
                catat_riwayat('Insiden', label_unit($unit), $insiden['kode'] . ' mulai ditangani', $insiden['status'], 'Ditangani');
                flash('sukses', $insiden['kode'] . ' ditandai sedang ditangani.');
            } else {
                // Insiden terbuka lain untuk unit yang sama (selain insiden ini).
                $masih = array_filter(insiden_terbuka(), fn ($i) => (int) $i['perlengkapan_id'] === (int) $insiden['perlengkapan_id'] && (int) $i['id'] !== (int) $insiden['id']);
                // Kondisi unit (PostgreSQL) diubah lebih dulu: bila database gagal, status insiden di session belum berubah.
                if ($unit && !$masih && $unit['kondisi'] !== 'Baik') {
                    ubah_kondisi_unit($unit['id'], 'Baik');
                }
                db_ubah('insiden', $insiden['id'], ['status' => 'Selesai']);
                catat_riwayat('Insiden', label_unit($unit), $insiden['kode'] . ' selesai, unit kembali layak pakai', $unit['kondisi'] ?? '', $masih ? ($unit['kondisi'] ?? '') : 'Baik');
                flash('sukses', $insiden['kode'] . ' selesai.' . ($unit && !$masih ? ' ' . $unit['kode_unit'] . ' kembali berkondisi Baik.' : ''));
            }
        }
        muat_ulang();
    }

    $modal_rental = ['terima_rental' => 'dialog-terima-rental', 'atur_jatuh_tempo' => 'dialog-atur-tempo', 'perpanjang_rental' => 'dialog-perpanjang-rental', 'kembalikan_vendor' => 'dialog-kembali-vendor'];
    if (isset($modal_rental[$aksi])) {
        wajib_boleh('rental.kelola');
        $a = db_cari('alokasi', (int) input('id'));
        $pr = $a ? cari_proyek((int) $a['proyek_id']) : null;
        if (!$a || !$pr || input('proyek_id') !== (string) $a['proyek_id']) {
            flash('error', 'Alokasi rental tidak ditemukan pada proyek tersebut.');
            muat_ulang();
        }
        if ($a['sumber'] !== 'Rental') {
            flash('error', 'Aksi ini hanya untuk barang Rental. ' . nama_barang_alokasi($a) . ' bersumber ' . $a['sumber'] . '.');
            muat_ulang();
        }
        $hasil = proses_aksi_rental($aksi, $a, $pengguna);
        if (!empty($hasil['galat'])) {
            $lama = ['id' => $a['id'], 'proyek_id' => $a['proyek_id'], '_barang' => $a['nama_barang'] . ' · ' . jumlah_alokasi($a) . ' unit · ' . $pr['kode'], '_vendor' => $a['vendor'], '_jumlah' => jumlah_alokasi($a) . ' unit', '_tempo_lama' => !empty($a['jatuh_tempo_vendor']) ? tgl($a['jatuh_tempo_vendor'], 'panjang') : ''];
            foreach (['jumlah', 'waktu', 'kondisi', 'catatan', 'jatuh_tempo', 'sewa_mulai', 'kontak_vendor', 'alasan'] as $kunci) {
                $lama[$kunci] = input($kunci);
            }
            simpan_form($modal_rental[$aksi], $lama, $hasil['galat']);
        } elseif (!empty($hasil['umum'])) {
            flash('error', $hasil['umum']);
        } else {
            flash('sukses', $hasil['sukses']);
        }
        muat_ulang();
    }

    muat_ulang();
}

$tab = in_array(query('tab'), ['eksternal', 'insiden'], true) ? query('tab') : 'unit';
$kelola = boleh('perlengkapan.kelola');
$ringkas = ringkasan_pemakaian();
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
<div class="kartu kpi"><p class="kpi-label">Total unit</p><p class="kpi-angka"><?= count(semua_perlengkapan()) ?></p><p class="kpi-ket"><?= count(semua_kategori()) ?> kategori</p></div>
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
        && cocok($kata_eks, $a['nama_barang'], $b['kebutuhan']['nama'] ?? '', $b['proyek']['kode'] ?? '', $b['proyek']['nama'] ?? '', $b['asal'], $a['kontak_vendor'] ?? '');
}));
?>
<div class="grid-kpi">
<?php $eks_di_proyek = count(array_filter($eksternal, fn ($bx) => isset($bx['serah']['keluar']) && !isset($bx['serah']['kembali']))); ?>
<div class="kartu kpi"><p class="kpi-label">Alokasi eksternal</p><p class="kpi-angka"><?= $ringkas_eks['alokasi_aktif'] ?></p><p class="kpi-ket">baris tidak dibatalkan · <span data-kpi-eks-di-proyek><?= $eks_di_proyek ?></span> masih di proyek</p></div>
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
<p data-jumlah-eksternal><?= count($eks_tampil) ?> dari <?= count($eksternal) ?> alokasi ditampilkan. Dikonfirmasi berarti alokasi disepakati, belum berarti barang sudah diterima. Penerimaan, jatuh tempo, dan pengembalian Rental dicatat di kolom Sewa vendor.</p>
</div>
</div>
<?php
$sewa_hitung = ['Terlambat' => 0, 'Mendekati Jatuh Tempo' => 0, 'Tempo Vendor Lewat' => 0];
foreach ($eksternal as $bx) {
    if ($bx['alokasi']['sumber'] === 'Rental') {
        $sx = info_rental($bx['alokasi'])['status_sewa'];
        if (isset($sewa_hitung[$sx])) {
            $sewa_hitung[$sx]++;
        }
    }
}
$sewa_pesan = array_filter([
    $sewa_hitung['Terlambat'] ? $sewa_hitung['Terlambat'] . ' rental terlambat dikembalikan ke vendor' : '',
    $sewa_hitung['Mendekati Jatuh Tempo'] ? $sewa_hitung['Mendekati Jatuh Tempo'] . ' rental mendekati jatuh tempo (H-2)' : '',
    $sewa_hitung['Tempo Vendor Lewat'] ? $sewa_hitung['Tempo Vendor Lewat'] . ' rental lewat tempo vendor tetapi penerimaannya belum dicatat' : '',
]);
?>
<?php if ($sewa_pesan): ?>
<div class="pemberitahuan <?= $sewa_hitung['Terlambat'] ? 'pemberitahuan-error' : 'pemberitahuan-info' ?> pemberitahuan-kartu" role="status" data-peringatan-sewa><?= ikon('triangle-alert') ?><span><?= e(implode(' · ', $sewa_pesan)) ?>. Periksa penerimaan, perpanjang, atau kembalikan ke vendor.</span></div>
<?php endif; ?>
<?php if (!$eksternal): ?>
<?= kosong('Belum ada barang eksternal', 'Barang Rental dan Milik Kru muncul otomatis setelah dialokasikan di proyek.') ?>
<?php elseif (!$eks_tampil): ?>
<?= kosong('Tidak ada barang eksternal yang cocok', 'Ubah saringan atau kata kunci pencarian.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu tabel-eksternal">
<thead><tr><th>Barang</th><th class="angka">Jumlah</th><th>Proyek &amp; periode</th><th>Sumber</th><th>Status</th><th>Sewa vendor</th><th><span class="sr">Aksi</span></th></tr></thead>
<tbody>
<?php foreach ($eks_tampil as $b): ?>
<?php $a = $b['alokasi']; $pr = $b['proyek']; ?>
<tr id="alokasi-<?= (int) $a['id'] ?>">
<td data-label="Barang"><strong class="teks-pecah"><?= e($a['nama_barang'] !== '' ? $a['nama_barang'] : 'Belum dicatat') ?></strong><div class="kecil redup">Untuk <?= e($b['kebutuhan']['nama'] ?? 'kebutuhan dihapus') ?></div><?= $a['catatan'] !== '' ? '<div class="kecil redup teks-pecah" data-catatan>Catatan (teks bebas): ' . e($a['catatan']) . '</div>' : '' ?></td>
<td data-label="Jumlah" class="angka"><?= (int) $b['jumlah'] ?> unit</td>
<td data-label="Proyek &amp; periode"><strong><?= e($pr['kode'] ?? '?') ?></strong><div class="kecil redup"><?= e($pr['nama'] ?? 'Proyek tidak ditemukan') ?><?= $pr ? ' · ' . e($pr['status']) : '' ?></div><div class="kecil">Pakai <?= e(rentang_tgl($a['tgl_mulai'], $a['tgl_selesai'])) ?></div></td>
<td data-label="Sumber"><span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= ikon($a['sumber'] === 'Rental' ? 'truck' : 'user', 14) ?><?= e($a['sumber']) ?></span><div class="kecil redup teks-pecah"><?= $a['sumber'] === 'Rental' ? 'Vendor' : 'Pemilik' ?>: <?= e($b['asal'] !== '' ? $b['asal'] : 'Belum dicatat') ?></div><?= $a['sumber'] === 'Rental' && ($a['kontak_vendor'] ?? '') !== '' ? '<div class="kecil redup teks-pecah">Kontak: ' . e($a['kontak_vendor']) . '</div>' : '' ?></td>
<td data-label="Status"><?= badge($a['status']) ?><?= label_status_alokasi($a) !== $a['status'] ? '<div class="kecil redup">' . e(label_status_alokasi($a)) . '</div>' : '' ?>
<div class="kecil" data-pemeriksaan>
<?php if ($a['status'] === 'Dibatalkan'): ?>
<span class="redup">—</span>
<?php elseif (isset($b['serah']['keluar'])): ?>
<?php $sr = $b['serah']; $trx_k = db_cari('transaksi', $sr['trx_keluar'] ?? 0); $penuh = isset($sr['kembali']); $sebagian = !$penuh && ($sr['jumlah_kembali'] ?? 0) > 0; ?>
<div class="status-proyek" <?= $penuh ? 'data-kembali-proyek' : 'data-dipakai-proyek' ?>>
<?= $penuh ? badge('Sudah Kembali', 'Kembali dari proyek') : ($sebagian ? badge('Kembali Sebagian', 'Kembali sebagian') : badge('Sedang Keluar', 'Dipakai proyek')) ?>
<div class="saldo-proyek" data-saldo-proyek>Keluar <?= (int) ($sr['jumlah_keluar'] ?? $b['jumlah']) ?> · Kembali <?= (int) ($sr['jumlah_kembali'] ?? 0) ?> · Sisa <?= (int) ($sr['sisa'] ?? 0) ?></div>
<div class="redup">Keluar <?= e(tgl($sr['keluar'], 'singkat_jam')) ?> · <?= e($trx_k['kode'] ?? '?') ?><?= !empty($trx_k['penerima']) ? ' · diterima ' . e($trx_k['penerima']) : '' ?></div>
<?php if (($sr['jumlah_kembali'] ?? 0) > 0): ?>
<div class="redup">Kondisi kembali: <?= e(teks_kondisi($sr['kondisi_rincian'] ?? [])) ?><?= $penuh ? ' · ' . e(tgl($sr['kembali'], 'singkat_jam')) : '' ?></div>
<?php endif; ?>
<?php if ($penuh && $a['sumber'] === 'Milik Kru'): ?>
<div class="redup">Sudah kembali dari proyek.</div>
<?php endif; ?>
</div>
<?php elseif ($a['status'] !== 'Dikonfirmasi'): ?>
<span class="redup">Menunggu konfirmasi</span>
<?php elseif (!alokasi_bisa_diperiksa($a)): ?>
<span class="redup">Menunggu penerimaan vendor<?= $b['centang_tersimpan'] ? ' · centang lama belum berlaku' : '' ?></span>
<?php elseif ($b['diperiksa_aktif']): ?>
<?= badge('Diperiksa Petugas') ?>
<?php elseif ($b['diperiksa']): ?>
<span class="redup">Pernah diperiksa (<?= $b['proyek_aktif'] ? 'sudah dikembalikan ke vendor' : 'proyek ditutup' ?>)</span>
<?php else: ?>
<span class="redup">Belum diperiksa</span>
<?php endif; ?>
</div>
</td>
<td data-label="Sewa vendor" data-sewa>
<?php if ($a['sumber'] !== 'Rental'): ?>
<span class="kecil redup">Tidak berlaku (barang milik kru)</span>
<?php elseif ($a['status'] === 'Dibatalkan'): ?>
<span class="kecil redup">—</span>
<?php else: ?>
<?php $r = info_rental($a); $kirim = ['id' => $a['id'], 'proyek_id' => $a['proyek_id'], '_barang' => $a['nama_barang'] . ' · ' . $b['jumlah'] . ' unit · ' . ($pr['kode'] ?? '?'), '_vendor' => $a['vendor'], 'jumlah' => $b['jumlah'], '_jumlah' => $b['jumlah'] . ' unit']; ?>
<?= badge($r['status_sewa']) ?>
<div class="kecil">Jatuh tempo: <?= $r['jatuh_tempo'] ? e(tgl($r['jatuh_tempo'])) : 'Belum dicatat' ?><?= $r['jatuh_tempo'] && !$r['dikembalikan'] ? ' · ' . e($r['keterangan']) : '' ?></div>
<div class="kecil redup">Penerimaan: <?= $r['diterima'] ? 'diterima ' . e(tgl($a['diterima_pada'], 'singkat_jam')) . ' · ' . e($a['kondisi_terima'] ?? '') : 'belum diterima' ?></div>
<div class="kecil redup">Ke vendor: <?= $r['dikembalikan'] ? 'dikembalikan ' . e(tgl($a['dikembalikan_vendor_pada'], 'singkat_jam')) . ' · ' . e($a['kondisi_kembali_vendor'] ?? '') : 'belum dikembalikan' ?></div>
<?php if ($r['dipakai_proyek']): ?>
<div class="kecil teks-kuning" data-kunci-vendor>Masih dipakai proyek: <?= (int) ($b['serah']['sisa'] ?? 0) ?> dari <?= (int) ($b['serah']['jumlah_keluar'] ?? 0) ?> unit belum kembali. Kembalikan ke vendor baru bisa setelah seluruh unit kembali dari proyek.</div>
<?php elseif (isset($b['serah']['kembali']) && !$r['dikembalikan']): ?>
<div class="kecil" data-kewajiban-vendor>Sudah kembali dari proyek. Belum dikembalikan ke vendor; kewajiban vendor masih berjalan.</div>
<?php if (($b['serah']['kondisi_ringkas'] ?? 'Baik') !== 'Baik'): ?>
<div class="kecil teks-kuning" data-kondisi-proyek>Kembali dari proyek: <?= e(teks_kondisi($b['serah']['kondisi_rincian'] ?? [])) ?>. Periksa sebelum diserahkan ke vendor; status perbaikannya tidak tercatat di sini.</div>
<?php endif; ?>
<?php endif; ?>
<?php if (boleh('rental.kelola') && ($r['bisa_terima'] || $r['bisa_tempo'] || $r['bisa_kembali'])): ?>
<div class="aksi-rental">
<?php if ($r['bisa_terima']): ?>
<?= tombol_modal('dialog-terima-rental', 'Catat penerimaan', $kirim + ['waktu' => waktu_input(sekarang()), 'kondisi' => 'Baik'], 'package', 'btn btn-kecil') ?>
<?php endif; ?>
<?php if ($r['bisa_kembali']): ?>
<?php
$info_proyek = isset($b['serah']['kembali'])
    ? 'Sudah kembali seluruhnya dari proyek ' . ($pr['kode'] ?? '?') . ' pada ' . tgl($b['serah']['kembali'], 'singkat_jam') . ': ' . teks_kondisi($b['serah']['kondisi_rincian'] ?? []) . '.' . (($b['serah']['kondisi_ringkas'] ?? 'Baik') !== 'Baik' ? ' Kondisi itu belum tentu sudah diperbaiki; periksa sebelum diserahkan ke vendor.' : '')
    : 'Rental ini tidak tercatat keluar ke proyek melalui Serah Terima.';
?>
<?= tombol_modal('dialog-kembali-vendor', 'Kembalikan ke vendor', $kirim + ['waktu' => waktu_input(sekarang()), 'kondisi' => isset($b['serah']['kembali']) ? ($b['serah']['kondisi_ringkas'] ?: 'Baik') : 'Baik', '_proyek' => $info_proyek], 'truck', 'btn btn-kecil') ?>
<?php endif; ?>
<?php if ($r['bisa_tempo'] && $r['jatuh_tempo']): ?>
<?= tombol_modal('dialog-perpanjang-rental', 'Perpanjang', $kirim + ['_tempo_lama' => tgl($r['jatuh_tempo'], 'panjang'), 'jatuh_tempo' => date('Y-m-d', strtotime($r['jatuh_tempo'] . ' +1 day'))], 'calendar-days', 'btn btn-sekunder btn-kecil') ?>
<?php elseif ($r['bisa_tempo']): ?>
<?= tombol_modal('dialog-atur-tempo', 'Atur jatuh tempo', $kirim + ['sewa_mulai' => $a['tgl_mulai'], 'kontak_vendor' => $a['kontak_vendor'] ?? ''], 'calendar-days', 'btn btn-sekunder btn-kecil') ?>
<?php endif; ?>
</div>
<?php endif; ?>
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
$unit_tampil = array_filter(semua_perlengkapan(), function ($u) use ($f_kategori, $f_status, $kata) {
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

<?php foreach (semua_kategori() as $kat): ?>
<?php $unit_kat = array_filter($unit_tampil, fn ($u) => (int) $u['kategori_id'] === (int) $kat['id']); ?>
<?php if (!$unit_kat) { continue; } ?>
<?php usort($unit_kat, fn ($a, $b) => strcmp($a['kode_unit'], $b['kode_unit'])); ?>
<section class="kartu">
<div class="kartu-kepala">
<div><h2><?= e($kat['nama']) ?></h2><p><?= e($kat['kode_awalan']) ?> · <?= count($unit_kat) ?> unit · <?= e($kat['deskripsi']) ?></p></div>
</div>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Unit</th><th>Kondisi</th><th>Pemakaian</th><?= $kelola ? '<th><span class="sr">Aksi</span></th>' : '' ?></tr></thead>
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
<?php $pk = cari_proyek($keluar['proyek_id']); ?>
<div class="kecil redup" data-unit-di-proyek>Masih di <?= e($pk['kode'] ?? '?') ?> · jadwal selesai <?= e(tgl($keluar['tgl_selesai'], 'singkat')) ?></div>
<?php elseif ($berikut): ?>
<?php $pb = cari_proyek($berikut['proyek_id']); ?>
<div class="kecil redup">Berikutnya <?= e($pb['kode'] ?? '?') ?> · <?= e(rentang_tgl($berikut['tgl_mulai'], $berikut['tgl_selesai'])) ?></div>
<?php endif; ?>
</td>
<?php if ($kelola): ?>
<td class="kolom-aksi">
<div class="aksi-baris">
<?= tombol_ikon_modal('dialog-unit', 'Ubah ' . $u['kode_unit'], ['id' => $u['id'], 'kategori_id' => $u['kategori_id'], 'nama' => $u['nama'], 'merek' => $u['merek'], 'kondisi' => $u['kondisi'], 'status_aktif' => $u['status_aktif'], 'catatan' => $u['catatan'], '_judul' => 'Ubah ' . $u['kode_unit']], 'pencil') ?>
<?= tombol_konfirmasi(['label' => 'Hapus ' . $u['kode_unit'], 'ikon' => 'trash-2', 'hanya_ikon' => true, 'aksi' => 'hapus_unit', 'id' => $u['id'], 'judul' => 'Hapus ' . $u['kode_unit'] . '?', 'pesan' => 'Selama data proyek masih tersimpan per browser, unit tidak dihapus permanen. Unit yang tidak dipakai lagi sebaiknya dinonaktifkan.', 'tombol' => 'Hapus unit']) ?>
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
<div><h2>Laporan insiden</h2><p><?= count($terbuka) ?> terbuka dari <?= count($insiden) ?> laporan. Insiden untuk unit inventaris Studio, termasuk yang tercatat otomatis saat pengembalian. Kondisi barang Rental dan Milik Kru tidak dikelola sebagai insiden; lihat Barang eksternal atau Serah Terima.</p></div>
</div>
<?php if (!$insiden): ?>
<?= kosong('Belum ada insiden', 'Laporan kerusakan atau masalah teknis akan muncul di sini.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Insiden</th><th>Unit</th><th>Deskripsi</th><th>Status</th><?= boleh('insiden.kelola') ? '<th><span class="sr">Aksi</span></th>' : '' ?></tr></thead>
<tbody>
<?php foreach ($insiden as $i): ?>
<?php $u = cari_perlengkapan((int) $i['perlengkapan_id']); $p = $i['proyek_id'] ? cari_proyek((int) $i['proyek_id']) : null; ?>
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
if (boleh('rental.kelola') && $tab === 'eksternal') {
    require ROOT . '/partials/modal_rental.php';
}
?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
