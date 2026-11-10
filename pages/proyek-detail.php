<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('proyek.lihat');

$proyek = db_cari('proyek', (int) query('id'));
if (!$proyek || !boleh_lihat_proyek($proyek, $pengguna)) {
    flash('error', 'Proyek tidak ditemukan atau Anda tidak ditugaskan di proyek itu.');
    arahkan('pages/proyek.php');
}
$pid = (int) $proyek['id'];
$bisa_ubah = proyek_aktif($proyek);

if (is_post()) {
    cek_csrf();
    $aksi = input('aksi');

    if ($aksi === 'simpan_proyek') {
        simpan_proyek_dari_form($pengguna);
    }

    if ($aksi === 'hapus_proyek') {
        wajib_boleh('proyek.kelola');
        if (proyek_punya_transaksi($pid)) {
            flash('error', 'Proyek ini sudah punya riwayat serah terima. Ubah statusnya menjadi Dibatalkan atau Selesai.');
            muat_ulang();
        }
        hapus_proyek($pid);
        catat_riwayat('Proyek', $proyek['kode'] . ' · ' . $proyek['nama'], 'Proyek dihapus');
        flash('sukses', 'Proyek ' . $proyek['kode'] . ' dihapus.');
        arahkan('pages/proyek.php');
    }

    if ($aksi === 'tambah_anggota') {
        wajib_boleh('anggota.kelola');
        $calon = db_cari('pengguna', (int) input('pengguna_id'));
        if (!$calon || $calon['peran'] !== 'us' || $calon['status'] !== 'Aktif') {
            simpan_form('dialog-anggota', [], ['pengguna_id' => 'Pilih user yang aktif.']);
            muat_ulang();
        }
        if (peran_di_proyek($pid, $calon['id']) !== '') {
            simpan_form('dialog-anggota', [], ['pengguna_id' => $calon['nama'] . ' sudah ada di tim.']);
            muat_ulang();
        }
        db_tambah('anggota_proyek', ['proyek_id' => $pid, 'pengguna_id' => $calon['id'], 'peran' => 'Kru']);
        catat_riwayat('Tim', $proyek['kode'], $calon['nama'] . ' ditambahkan sebagai kru');
        flash('sukses', $calon['nama'] . ' ditambahkan ke tim.');
        muat_ulang();
    }

    if ($aksi === 'lepas_anggota') {
        wajib_boleh('anggota.kelola');
        $baris = db_cari('anggota_proyek', (int) input('id'));
        if (!$baris || (int) $baris['proyek_id'] !== $pid) {
            muat_ulang();
        }
        if ($baris['peran'] === 'PIC') {
            flash('error', 'PIC tidak bisa dilepas. Ganti PIC lewat Ubah proyek.');
            muat_ulang();
        }
        foreach (alokasi_proyek($pid, true) as $a) {
            if ($a['sumber'] === 'Milik Kru' && (int) $a['pemilik_id'] === (int) $baris['pengguna_id']) {
                flash('error', nama_pengguna($baris['pengguna_id']) . ' masih tercatat membawa ' . $a['nama_barang'] . '. Hapus atau batalkan alokasinya dulu.');
                muat_ulang();
            }
        }
        db_hapus('anggota_proyek', $baris['id']);
        catat_riwayat('Tim', $proyek['kode'], nama_pengguna($baris['pengguna_id']) . ' dilepas dari tim');
        flash('sukses', nama_pengguna($baris['pengguna_id']) . ' dilepas dari tim.');
        muat_ulang();
    }

    if ($aksi === 'simpan_kebutuhan') {
        wajib_boleh('kebutuhan.kelola');
        $id = (int) input('id');
        $lama_k = $id ? db_cari('kebutuhan', $id) : null;
        $data = ['id' => $id ?: '', 'nama' => input('nama'), 'kategori_id' => input('kategori_id'), 'jumlah' => input('jumlah'), 'acuan' => input('acuan')];
        $galat = [];
        if (mb_strlen($data['nama']) < 2) {
            $galat['nama'] = 'Nama kebutuhan wajib diisi.';
        }
        if (!db_cari('kategori', (int) $data['kategori_id'])) {
            $galat['kategori_id'] = 'Pilih kategori.';
        }
        $jumlah = (int) $data['jumlah'];
        if ($jumlah < 1 || $jumlah > 50) {
            $galat['jumlah'] = 'Jumlah 1 sampai 50.';
        } elseif ($lama_k && $jumlah < pemenuhan_kebutuhan($lama_k)['terisi']) {
            $galat['jumlah'] = 'Sudah ada ' . pemenuhan_kebutuhan($lama_k)['terisi'] . ' alokasi. Kurangi alokasinya dulu.';
        }
        if ($galat) {
            simpan_form('dialog-kebutuhan', $data, $galat);
            muat_ulang();
        }
        $isi = ['nama' => $data['nama'], 'kategori_id' => (int) $data['kategori_id'], 'jumlah' => $jumlah, 'acuan' => $data['acuan']];
        if ($lama_k && (int) $lama_k['proyek_id'] === $pid) {
            db_ubah('kebutuhan', $id, $isi);
            catat_riwayat('Kebutuhan', $proyek['kode'], $isi['nama'] . ' diperbarui', (string) $lama_k['jumlah'], (string) $jumlah);
            flash('sukses', 'Kebutuhan ' . $isi['nama'] . ' diperbarui.');
        } else {
            db_tambah('kebutuhan', ['proyek_id' => $pid] + $isi);
            catat_riwayat('Kebutuhan', $proyek['kode'], $isi['nama'] . ' × ' . $jumlah . ' ditambahkan');
            flash('sukses', 'Kebutuhan ' . $isi['nama'] . ' ditambahkan.');
        }
        muat_ulang();
    }

    if ($aksi === 'hapus_kebutuhan') {
        wajib_boleh('kebutuhan.kelola');
        $k = db_cari('kebutuhan', (int) input('id'));
        if ($k && (int) $k['proyek_id'] === $pid) {
            if (pemenuhan_kebutuhan($k)['terisi'] > 0) {
                flash('error', 'Kebutuhan ' . $k['nama'] . ' masih punya alokasi aktif. Batalkan atau hapus alokasinya dulu.');
                muat_ulang();
            }
            foreach (alokasi_kebutuhan($k['id']) as $a) {
                db_hapus('alokasi', $a['id']);
            }
            db_hapus('kebutuhan', $k['id']);
            catat_riwayat('Kebutuhan', $proyek['kode'], $k['nama'] . ' dihapus');
            flash('sukses', 'Kebutuhan ' . $k['nama'] . ' dihapus.');
        }
        muat_ulang();
    }

    if ($aksi === 'isi_preset') {
        wajib_boleh('kebutuhan.kelola');
        $preset = db_cari('preset', (int) input('preset_id'));
        if (!$preset) {
            simpan_form('dialog-isi-preset', [], ['preset_id' => 'Pilih preset.']);
            muat_ulang();
        }
        $sudah = array_map(fn ($k) => mb_strtolower($k['acuan'] !== '' ? $k['acuan'] : $k['nama']), kebutuhan_proyek($pid));
        $tambah = 0;
        $lewat = 0;
        foreach (db('detail_preset') as $d) {
            if ((int) $d['preset_id'] !== (int) $preset['id']) {
                continue;
            }
            if (in_array(mb_strtolower($d['nama_perlengkapan']), $sudah, true)) {
                $lewat++;
                continue;
            }
            db_tambah('kebutuhan', ['proyek_id' => $pid, 'nama' => $d['nama_perlengkapan'], 'acuan' => $d['nama_perlengkapan'], 'kategori_id' => $d['kategori_id'], 'jumlah' => $d['jumlah']]);
            $tambah++;
        }
        catat_riwayat('Kebutuhan', $proyek['kode'], $tambah . ' kebutuhan ditambahkan dari preset ' . $preset['nama']);
        flash('sukses', $tambah . ' kebutuhan dari preset ' . $preset['nama'] . ' ditambahkan' . ($lewat ? ', ' . $lewat . ' dilewati karena sudah ada.' : '.'));
        muat_ulang();
    }

    if ($aksi === 'tambah_alokasi') {
        wajib_boleh('alokasi.kelola');
        $k = db_cari('kebutuhan', (int) input('kebutuhan_id'));
        if (!$k || (int) $k['proyek_id'] !== $pid || !$bisa_ubah) {
            flash('error', 'Kebutuhan tidak ditemukan atau proyek sudah ditutup.');
            muat_ulang();
        }
        $modal = 'dialog-alokasi-' . $k['id'];
        $data = [
            'sumber' => input('sumber'),
            'perlengkapan_id' => input('perlengkapan_id'),
            'nama_barang' => input('nama_barang'),
            'vendor' => input('vendor'),
            'pemilik_id' => input('pemilik_id'),
            'status' => input('status') === 'Direncanakan' ? 'Direncanakan' : 'Dikonfirmasi',
            'catatan' => input('catatan'),
        ];
        $galat = [];
        if (pemenuhan_kebutuhan($k)['terisi'] >= $k['jumlah']) {
            flash('error', 'Kebutuhan ' . $k['nama'] . ' sudah terpenuhi.');
            muat_ulang();
        }
        if (!in_array($data['sumber'], daftar_sumber(), true)) {
            $galat['sumber'] = 'Pilih sumber barang.';
        } elseif ($data['sumber'] === 'Studio') {
            $unit = db_cari('perlengkapan', (int) $data['perlengkapan_id']);
            if (!$unit) {
                $galat['perlengkapan_id'] = 'Pilih unit.';
            } else {
                $cek = cek_unit_untuk_proyek($unit, $proyek);
                if ($cek['blokir'] !== '') {
                    $galat['perlengkapan_id'] = $unit['kode_unit'] . ': ' . $cek['blokir'] . '.';
                }
            }
        } else {
            if ($data['nama_barang'] === '') {
                $galat['nama_barang'] = 'Nama barang wajib diisi.';
            }
            if ($data['sumber'] === 'Rental' && $data['vendor'] === '') {
                $galat['vendor'] = 'Nama vendor wajib diisi.';
            }
            if ($data['sumber'] === 'Milik Kru' && peran_di_proyek($pid, (int) $data['pemilik_id']) === '') {
                $galat['pemilik_id'] = 'Pilih pemilik dari anggota tim proyek.';
            }
        }
        if ($galat) {
            simpan_form($modal, $data, $galat);
            muat_ulang();
        }
        [$mulai, $selesai] = rentang_alokasi($proyek);
        $studio = $data['sumber'] === 'Studio';
        $baru = db_tambah('alokasi', [
            'proyek_id' => $pid,
            'kebutuhan_id' => $k['id'],
            'sumber' => $data['sumber'],
            'perlengkapan_id' => $studio ? (int) $data['perlengkapan_id'] : null,
            'nama_barang' => $studio ? '' : $data['nama_barang'],
            'vendor' => $data['sumber'] === 'Rental' ? $data['vendor'] : '',
            'pemilik_id' => $data['sumber'] === 'Milik Kru' ? (int) $data['pemilik_id'] : null,
            'tgl_mulai' => $mulai,
            'tgl_selesai' => $selesai,
            'status' => $data['status'],
            'catatan' => $data['catatan'],
        ]);
        $a = db_cari('alokasi', $baru);
        catat_riwayat('Alokasi', ($studio ? keterangan_sumber($a) . ' · ' : nama_barang_alokasi($a) . ' · ') . $proyek['kode'], $data['sumber'] . ': ' . nama_barang_alokasi($a) . ' untuk ' . $k['nama']);
        flash('sukses', nama_barang_alokasi($a) . ' dialokasikan untuk ' . $k['nama'] . '.');
        muat_ulang();
    }

    if (in_array($aksi, ['konfirmasi_alokasi', 'batalkan_alokasi', 'hapus_alokasi'], true)) {
        wajib_boleh('alokasi.kelola');
        $a = db_cari('alokasi', (int) input('id'));
        if (!$a || (int) $a['proyek_id'] !== $pid) {
            muat_ulang();
        }
        $serah = serah_alokasi($a['id']);
        $nama = nama_barang_alokasi($a);
        if ($aksi === 'konfirmasi_alokasi') {
            db_ubah('alokasi', $a['id'], ['status' => 'Dikonfirmasi']);
            catat_riwayat('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dikonfirmasi', $a['status'], 'Dikonfirmasi');
            flash('sukses', $nama . ' dikonfirmasi.');
        } elseif (isset($serah['keluar'])) {
            flash('error', $nama . ' sudah pernah diserahkan, jadi tidak bisa dibatalkan atau dihapus.');
        } elseif ($aksi === 'batalkan_alokasi') {
            db_ubah('alokasi', $a['id'], ['status' => 'Dibatalkan']);
            catat_riwayat('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dibatalkan', $a['status'], 'Dibatalkan');
            flash('sukses', 'Alokasi ' . $nama . ' dibatalkan.');
        } else {
            db_hapus('alokasi', $a['id']);
            catat_riwayat('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dihapus');
            flash('sukses', 'Alokasi ' . $nama . ' dihapus.');
        }
        muat_ulang();
    }

    if ($aksi === 'simpan_persiapan') {
        wajib_boleh('persiapan.kelola');
        $aktif = array_map(fn ($a) => (int) $a['id'], alokasi_proyek($pid, true));
        $pilih = array_values(array_intersect(array_map('intval', input_daftar('diperiksa')), $aktif));
        $rekam = ambil_persiapan($pid);
        db_ubah('persiapan', $rekam['id'], ['diperiksa' => $pilih, 'diperbarui_pada' => date('Y-m-d H:i', strtotime(sekarang())), 'diperbarui_oleh' => $pengguna['id']]);
        $total = count($aktif);
        catat_riwayat('Persiapan', $proyek['kode'], count($pilih) . ' dari ' . $total . ' barang diperiksa');
        flash('sukses', count($pilih) === $total && $total > 0 ? 'Persiapan lengkap. Barang studio siap diserahkan di menu Serah Terima.' : 'Persiapan disimpan: ' . count($pilih) . ' dari ' . $total . ' barang diperiksa.');
        muat_ulang();
    }

    muat_ulang();
}

$tab_daftar = [
    'ringkasan' => 'Ringkasan &amp; Tim',
    'kebutuhan' => 'Kebutuhan &amp; Alokasi',
    'persiapan' => 'Persiapan',
    'serah' => 'Serah Terima',
];
$tab = query('tab', 'ringkasan');
if (!isset($tab_daftar[$tab])) {
    $tab = 'ringkasan';
}

$anggota = anggota_proyek($pid);
$kebutuhan = kebutuhan_proyek($pid);
$persiapan = persiapan_proyek($proyek);
$pemenuhan_total = ['terisi' => 0, 'jumlah' => 0];
foreach ($kebutuhan as $k) {
    $p = pemenuhan_kebutuhan($k);
    $pemenuhan_total['terisi'] += min($p['terisi'], $p['jumlah']);
    $pemenuhan_total['jumlah'] += $p['jumlah'];
}
$di_luar = unit_belum_kembali($pid);
$dasar = 'pages/proyek-detail.php?id=' . $pid;

$halaman = ['menu' => 'proyek', 'bagian' => $pengguna['peran'] === 'us' ? 'Proyek Saya' : 'Proyek & Acara', 'judul' => $proyek['kode']];
require ROOT . '/includes/layout_head.php';
?>
<div class="kepala-detail">
<a href="<?= e(url('pages/proyek.php')) ?>" class="tautan-kembali"><?= ikon('chevron-left', 16) ?><span>Semua proyek</span></a>
<div class="kepala-halaman">
<div>
<p class="label-kecil"><?= e($proyek['kode']) ?></p>
<h1><?= e($proyek['nama']) ?></h1>
<div class="meta">
<span><?= ikon('user', 16) ?><?= e($proyek['klien']) ?></span>
<span><?= ikon('map-pin', 16) ?><?= e($proyek['lokasi']) ?></span>
<span><?= ikon('calendar-days', 16) ?><?= e(rentang_tgl($proyek['mulai'], $proyek['selesai'])) ?></span>
<?= badge($proyek['status']) ?>
</div>
</div>
<?php if (boleh('proyek.kelola')): ?>
<div class="kepala-aksi">
<?= tombol_modal('dialog-proyek', 'Ubah proyek', data_form_proyek($proyek), 'pencil', 'btn btn-sekunder') ?>
<?= tombol_konfirmasi(['label' => 'Hapus', 'ikon' => 'trash-2', 'aksi' => 'hapus_proyek', 'id' => $pid, 'judul' => 'Hapus proyek ' . $proyek['kode'] . '?', 'pesan' => 'Kebutuhan, tim, alokasi, dan persiapan proyek ini ikut terhapus. Proyek yang sudah punya serah terima tidak bisa dihapus.', 'tombol' => 'Hapus proyek', 'kelas' => 'btn btn-sekunder']) ?>
</div>
<?php endif; ?>
</div>
</div>

<?php if (!$bisa_ubah): ?>
<div class="pemberitahuan pemberitahuan-info" role="status"><?= ikon('info') ?><span>Proyek berstatus <?= e($proyek['status']) ?>. Kebutuhan dan alokasi hanya bisa dilihat.</span></div>
<?php endif; ?>

<?= tab_bar($tab_daftar, $tab, $dasar) ?>

<?php if ($tab === 'ringkasan'): ?>
<div class="grid-kpi">
<div class="kartu kpi">
<p class="kpi-label">Kebutuhan teralokasi</p>
<p class="kpi-angka"><?= $pemenuhan_total['terisi'] ?><span class="kpi-dari">/<?= $pemenuhan_total['jumlah'] ?></span></p>
<?= bilah(persen($pemenuhan_total['terisi'], $pemenuhan_total['jumlah']), $pemenuhan_total['terisi'] < $pemenuhan_total['jumlah'] ? 'kuning' : 'hijau') ?>
</div>
<div class="kartu kpi">
<p class="kpi-label">Barang diperiksa</p>
<p class="kpi-angka"><?= $persiapan['selesai'] ?><span class="kpi-dari">/<?= $persiapan['total'] ?></span></p>
<?= bilah($persiapan['persen'], $persiapan['lewat'] ? 'merah' : ($persiapan['persen'] === 100 ? 'hijau' : 'tinta')) ?>
</div>
<div class="kartu kpi">
<p class="kpi-label">Unit studio di luar</p>
<p class="kpi-angka"><?= count($di_luar) ?></p>
<p class="kpi-ket"><?= count(array_filter($di_luar, fn ($u) => $u['status'] === 'Terlambat')) ?> terlambat kembali</p>
</div>
</div>
<div class="grid-2">
<section class="kartu">
<div class="kartu-kepala"><h2>Detail proyek</h2></div>
<dl class="info-grid">
<div><dt>Klien</dt><dd><?= e($proyek['klien']) ?></dd></div>
<div><dt>Lokasi</dt><dd><?= e($proyek['lokasi']) ?></dd></div>
<div><dt>Mulai</dt><dd><?= e(tgl($proyek['mulai'], 'lengkap')) ?></dd></div>
<div><dt>Selesai</dt><dd><?= e(tgl($proyek['selesai'], 'lengkap')) ?></dd></div>
<div><dt>Batas persiapan</dt><dd><?= e(tgl($proyek['deadline_persiapan'], 'lengkap')) ?><?= $persiapan['lewat'] ? ' ' . badge('Lewat deadline') : '' ?></dd></div>
<div><dt>PIC</dt><dd><?= e(nama_pengguna($proyek['pic_id'])) ?></dd></div>
<div class="info-penuh"><dt>Catatan</dt><dd><?= $proyek['catatan'] !== '' ? e($proyek['catatan']) : '<span class="redup">Tidak ada catatan.</span>' ?></dd></div>
</dl>
</section>
<section class="kartu">
<div class="kartu-kepala">
<div><h2>Tim proyek</h2><p><?= count($anggota) ?> orang</p></div>
<?php if (boleh('anggota.kelola') && $bisa_ubah): ?>
<?= tombol_modal('dialog-anggota', 'Tambah', [], 'user-plus', 'btn btn-sekunder btn-kecil') ?>
<?php endif; ?>
</div>
<?php if (!$anggota): ?>
<?= kosong('Belum ada tim', 'Super Admin menambahkan PIC dan kru ke proyek ini.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($anggota as $a): ?>
<li class="daftar-baris">
<span class="avatar avatar-kecil" aria-hidden="true"><?= e(inisial($a['pengguna']['nama'])) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e($a['pengguna']['nama']) ?><?= (int) $a['pengguna']['id'] === (int) $pengguna['id'] ? ' <span class="redup">(Anda)</span>' : '' ?></p>
<p class="daftar-sub"><?= e($a['pengguna']['jenis_anggota']) ?> · <?= e($a['pengguna']['telepon']) ?></p>
</div>
<?= badge($a['peran']) ?>
<?php if (boleh('anggota.kelola') && $bisa_ubah && $a['peran'] !== 'PIC'): ?>
<?= tombol_konfirmasi(['label' => 'Lepas ' . $a['pengguna']['nama'], 'ikon' => 'user-minus', 'hanya_ikon' => true, 'aksi' => 'lepas_anggota', 'id' => $a['id'], 'judul' => 'Lepas dari tim?', 'pesan' => $a['pengguna']['nama'] . ' tidak lagi melihat proyek ini.', 'tombol' => 'Lepas']) ?>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
</div>
<?php endif; ?>

<?php if ($tab === 'kebutuhan'): ?>
<section class="kartu">
<div class="kartu-kepala">
<div>
<h2>Kebutuhan &amp; alokasi</h2>
<p>Super Admin menentukan kebutuhan, Petugas Inventaris memenuhinya dari studio, rental, atau barang milik kru.</p>
</div>
<?php if (boleh('kebutuhan.kelola') && $bisa_ubah): ?>
<div class="kepala-aksi">
<?= tombol_modal('dialog-isi-preset', 'Isi dari preset', [], 'layers', 'btn btn-sekunder btn-kecil') ?>
<?= tombol_modal('dialog-kebutuhan', 'Tambah kebutuhan', ['_judul' => 'Tambah kebutuhan'], 'plus', 'btn btn-kecil') ?>
</div>
<?php endif; ?>
</div>
<?php if (!$kebutuhan): ?>
<?= kosong('Belum ada kebutuhan', boleh('kebutuhan.kelola') ? 'Tambahkan kebutuhan satu per satu atau isi cepat dari preset.' : 'Kebutuhan barang akan diisi oleh Super Admin.') ?>
<?php else: ?>
<div class="kebutuhan-daftar">
<?php foreach ($kebutuhan as $k): ?>
<?php $p = pemenuhan_kebutuhan($k); $alokasi_k = alokasi_kebutuhan($k['id']); ?>
<article class="kebutuhan">
<div class="kebutuhan-kepala">
<div class="daftar-utama">
<h3><?= e($k['nama']) ?> <span class="redup">× <?= (int) $k['jumlah'] ?></span></h3>
<p class="daftar-sub"><?= e(nama_kategori($k['kategori_id'])) ?><?= $k['acuan'] !== '' ? ' · acuan ' . e($k['acuan']) : '' ?></p>
</div>
<?= badge($p['status'], $p['terisi'] . '/' . $p['jumlah'] . ' · ' . $p['status']) ?>
<div class="aksi-baris">
<?php if (boleh('alokasi.kelola') && $bisa_ubah && $p['terisi'] < $p['jumlah']): ?>
<?= tombol_modal('dialog-alokasi-' . $k['id'], 'Alokasikan', [], 'plus', 'btn btn-kecil') ?>
<?php endif; ?>
<?php if (boleh('kebutuhan.kelola') && $bisa_ubah): ?>
<?= tombol_ikon_modal('dialog-kebutuhan', 'Ubah ' . $k['nama'], ['id' => $k['id'], 'nama' => $k['nama'], 'kategori_id' => $k['kategori_id'], 'jumlah' => $k['jumlah'], 'acuan' => $k['acuan'], '_judul' => 'Ubah kebutuhan'], 'pencil') ?>
<?= tombol_konfirmasi(['label' => 'Hapus ' . $k['nama'], 'ikon' => 'trash-2', 'hanya_ikon' => true, 'aksi' => 'hapus_kebutuhan', 'id' => $k['id'], 'judul' => 'Hapus kebutuhan?', 'pesan' => $k['nama'] . ' × ' . $k['jumlah'] . ' akan dihapus dari proyek ini.', 'tombol' => 'Hapus']) ?>
<?php endif; ?>
</div>
</div>
<?php if (!$alokasi_k): ?>
<p class="kebutuhan-kosong">Belum ada barang dialokasikan.</p>
<?php else: ?>
<ul class="alokasi-daftar">
<?php foreach ($alokasi_k as $a): ?>
<?php $serah = serah_alokasi($a['id']); $batal = $a['status'] === 'Dibatalkan'; ?>
<li class="alokasi<?= $batal ? ' alokasi-batal' : '' ?>">
<span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= ikon($a['sumber'] === 'Studio' ? 'camera' : ($a['sumber'] === 'Rental' ? 'truck' : 'user'), 14) ?><?= e($a['sumber']) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e(nama_barang_alokasi($a)) ?></p>
<p class="daftar-sub"><?= e(keterangan_sumber($a)) ?><?= $a['catatan'] !== '' ? ' · ' . e($a['catatan']) : '' ?></p>
</div>
<div class="alokasi-status">
<?= badge($a['status'], label_status_alokasi($a)) ?>
<?php if ($a['sumber'] === 'Studio' && isset($serah['kembali'])): ?>
<?= badge('Kembali') ?>
<?php elseif ($a['sumber'] === 'Studio' && isset($serah['keluar'])): ?>
<?= badge(status_keluar($a), 'Keluar · ' . status_keluar($a)) ?>
<?php endif; ?>
</div>
<?php if (boleh('alokasi.kelola') && $bisa_ubah && !$batal && !isset($serah['keluar'])): ?>
<div class="aksi-baris">
<?php if ($a['status'] === 'Direncanakan'): ?>
<?= tombol_konfirmasi(['label' => 'Konfirmasi', 'ikon' => 'check', 'aksi' => 'konfirmasi_alokasi', 'id' => $a['id'], 'judul' => 'Konfirmasi alokasi?', 'pesan' => nama_barang_alokasi($a) . ' (' . keterangan_sumber($a) . ') dipastikan tersedia untuk proyek ini.', 'tombol' => 'Konfirmasi', 'aman' => true]) ?>
<?php endif; ?>
<?= tombol_konfirmasi(['label' => 'Batalkan alokasi', 'ikon' => 'ban', 'hanya_ikon' => true, 'aksi' => 'batalkan_alokasi', 'id' => $a['id'], 'judul' => 'Batalkan alokasi?', 'pesan' => nama_barang_alokasi($a) . ' tetap tercatat tapi tidak dihitung lagi untuk kebutuhan ini.', 'tombol' => 'Batalkan alokasi']) ?>
<?= tombol_konfirmasi(['label' => 'Hapus alokasi', 'ikon' => 'trash-2', 'hanya_ikon' => true, 'aksi' => 'hapus_alokasi', 'id' => $a['id'], 'judul' => 'Hapus alokasi?', 'pesan' => nama_barang_alokasi($a) . ' dihapus dari proyek ini.', 'tombol' => 'Hapus']) ?>
</div>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'persiapan'): ?>
<section class="kartu">
<div class="kartu-kepala">
<div>
<h2>Persiapan barang</h2>
<p>Batas <?= e(tgl($proyek['deadline_persiapan'], 'lengkap')) ?><?= $persiapan['diperbarui_pada'] ? ' · diperbarui ' . e(tgl($persiapan['diperbarui_pada'], 'singkat_jam')) . ' oleh ' . e(nama_pengguna($persiapan['diperbarui_oleh'])) : '' ?></p>
</div>
<div class="aksi-baris">
<?= badge($persiapan['status']) ?>
<?= $persiapan['lewat'] ? badge('Lewat deadline') : '' ?>
</div>
</div>
<?php if (!$persiapan['item']): ?>
<?= kosong('Belum ada barang untuk disiapkan', 'Daftar periksa muncul setelah kebutuhan dialokasikan.') ?>
<?php else: ?>
<?php $bisa_centang = boleh('persiapan.kelola') && $bisa_ubah; ?>
<form method="post" class="persiapan" data-persiapan>
<?= csrf_field() ?>
<input type="hidden" name="aksi" value="simpan_persiapan">
<div class="persiapan-progres">
<div class="progres progres-besar">
<?= bilah($persiapan['persen'], $persiapan['lewat'] ? 'merah' : 'tinta') ?>
<span><strong data-progres-angka><?= $persiapan['selesai'] ?></strong> dari <?= $persiapan['total'] ?> diperiksa</span>
</div>
<?php if ($bisa_centang): ?>
<button type="button" class="btn btn-sekunder btn-kecil" data-centang-semua>Centang semua</button>
<?php endif; ?>
</div>
<ul class="ceklist">
<?php foreach ($persiapan['item'] as $a): ?>
<?php $k = db_cari('kebutuhan', $a['kebutuhan_id']); $tercentang = in_array((int) $a['id'], $persiapan['diperiksa'], true); ?>
<li>
<label class="ceklist-item">
<input type="checkbox" name="diperiksa[]" value="<?= (int) $a['id'] ?>"<?= $tercentang ? ' checked' : '' ?><?= $bisa_centang ? '' : ' disabled' ?>>
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e(nama_barang_alokasi($a)) ?></span>
<span class="daftar-sub"><?= e(keterangan_sumber($a)) ?> · untuk <?= e($k['nama'] ?? '—') ?></span>
</span>
<span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= e($a['sumber']) ?></span>
</label>
</li>
<?php endforeach; ?>
</ul>
<?php if ($bisa_centang): ?>
<div class="kartu-kaki">
<p class="kecil redup">Centang barang yang sudah dicek fisik dan siap dibawa, termasuk barang rental dan milik kru.</p>
<button type="submit" class="btn">Simpan persiapan</button>
</div>
<?php endif; ?>
</form>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'serah'): ?>
<?php $unit_studio = array_filter(alokasi_proyek($pid, true), fn ($a) => $a['sumber'] === 'Studio'); ?>
<section class="kartu">
<div class="kartu-kepala">
<div>
<h2>Unit studio di proyek ini</h2>
<p>Hanya unit inventaris studio yang dicatat keluar-masuk. Barang rental dan milik kru tidak ikut.</p>
</div>
<?php if (boleh('serah_terima.kelola')): ?>
<a href="<?= e(url('pages/serah-terima.php')) ?>" class="btn btn-sekunder btn-kecil"><?= ikon('arrow-left-right', 16) ?><span>Buka serah terima</span></a>
<?php endif; ?>
</div>
<?php if (!$unit_studio): ?>
<?= kosong('Belum ada unit studio', 'Unit akan muncul setelah dialokasikan dari inventaris.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Unit</th><th>Keluar</th><th>Jatuh tempo</th><th>Kembali</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($unit_studio as $a): ?>
<?php $serah = serah_alokasi($a['id']); ?>
<tr>
<td data-label="Unit"><strong><?= e(keterangan_sumber($a)) ?></strong><div class="kecil redup"><?= e(nama_barang_alokasi($a)) ?></div></td>
<td data-label="Keluar"><?= isset($serah['keluar']) ? e(tgl($serah['keluar'], 'singkat_jam')) : '<span class="redup">—</span>' ?></td>
<td data-label="Jatuh tempo"><?= e(tgl($a['tgl_selesai'])) ?></td>
<td data-label="Kembali"><?= isset($serah['kembali']) ? e(tgl($serah['kembali'], 'singkat_jam')) . ' · ' . e($serah['kondisi_kembali']) : '<span class="redup">—</span>' ?></td>
<td data-label="Status">
<?php if (isset($serah['kembali'])): ?>
<?= badge(substr($serah['kembali'], 0, 10) > $a['tgl_selesai'] ? 'Terlambat' : 'Kembali', substr($serah['kembali'], 0, 10) > $a['tgl_selesai'] ? 'Kembali terlambat' : 'Kembali') ?>
<?php elseif (isset($serah['keluar'])): ?>
<?= badge(status_keluar($a)) ?>
<?php else: ?>
<?= badge($a['status'] === 'Dikonfirmasi' ? 'Menunggu' : $a['status'], $a['status'] === 'Dikonfirmasi' ? 'Belum keluar' : $a['status']) ?>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<?php $trx_proyek = daftar_transaksi($pid); ?>
<?php if ($trx_proyek): ?>
<section class="kartu">
<div class="kartu-kepala"><h2>Transaksi</h2></div>
<ul class="daftar">
<?php foreach ($trx_proyek as $trx): ?>
<li class="daftar-baris">
<span class="ikon-bulat ikon-<?= $trx['jenis'] === 'Keluar' ? 'biru' : 'hijau' ?>"><?= ikon($trx['jenis'] === 'Keluar' ? 'arrow-up-right' : 'arrow-down-left', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e($trx['kode']) ?> · <?= e($trx['jenis'] === 'Keluar' ? 'Barang keluar' : 'Pengembalian') ?></p>
<p class="daftar-sub"><?= e(tgl($trx['waktu'], 'lengkap')) ?> · <?= count($trx['detail']) ?> unit · <?= e($trx['jenis'] === 'Keluar' ? 'diterima ' . $trx['penerima'] : 'dicek ' . nama_pengguna($trx['petugas_id'])) ?></p>
</div>
</li>
<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>
<?php endif; ?>

<?php
if (boleh('proyek.kelola')) {
    require ROOT . '/partials/modal_proyek.php';
}
if (boleh('anggota.kelola') && $bisa_ubah) {
    require ROOT . '/partials/modal_anggota.php';
}
if (boleh('kebutuhan.kelola') && $bisa_ubah) {
    require ROOT . '/partials/modal_kebutuhan.php';
}
if (boleh('alokasi.kelola') && $bisa_ubah && $tab === 'kebutuhan') {
    foreach ($kebutuhan as $kd) {
        if (pemenuhan_kebutuhan($kd)['terisi'] < $kd['jumlah']) {
            require ROOT . '/partials/modal_alokasi.php';
        }
    }
}
?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
