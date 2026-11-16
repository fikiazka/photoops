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
        $penghalang = rental_penghalang_hapus($pid);
        if ($penghalang['catatan_vendor']) {
            flash('error', 'Proyek ini punya catatan penerimaan/pengembalian rental dari vendor (' . implode(', ', $penghalang['catatan_vendor']) . ') yang harus dipertahankan. Ubah statusnya menjadi Dibatalkan atau Selesai, lalu selesaikan rental lewat Perlengkapan › Barang eksternal.');
            muat_ulang();
        }
        if ($penghalang['komitmen']) {
            flash('error', 'Proyek ini masih punya rental yang sudah dikonfirmasi vendor (' . implode(', ', $penghalang['komitmen']) . '). Batalkan alokasi rental tersebut terlebih dahulu atau ubah status proyek menjadi Dibatalkan.');
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
        wajib_proyek_aktif($proyek);
        wajib_persiapan_terbuka($proyek);
        $id = (int) input('id');
        $lama_k = null;
        if ($id) {
            $lama_k = db_cari('kebutuhan', $id);
            if (!$lama_k || (int) $lama_k['proyek_id'] !== $pid) {
                flash('error', 'Kebutuhan tidak ditemukan di proyek ini.');
                muat_ulang();
            }
        }
        $data = ['id' => $id ?: '', 'nama' => input('nama'), 'kategori_id' => input('kategori_id'), 'jumlah' => input('jumlah'), 'acuan' => input('acuan')];
        $galat = validasi_kebutuhan($data, $lama_k);
        if ($galat) {
            simpan_form('dialog-kebutuhan', $data, $galat);
            muat_ulang();
        }
        $jumlah = (int) $data['jumlah'];
        $isi = ['nama' => $data['nama'], 'kategori_id' => (int) $data['kategori_id'], 'jumlah' => $jumlah, 'acuan' => $data['acuan']];
        if ($lama_k) {
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
        wajib_proyek_aktif($proyek);
        wajib_persiapan_terbuka($proyek);
        $k = db_cari('kebutuhan', (int) input('id'));
        if (!$k || (int) $k['proyek_id'] !== $pid) {
            flash('error', 'Kebutuhan tidak ditemukan di proyek ini.');
            muat_ulang();
        }
        if (pemenuhan_kebutuhan($k)['terisi'] > 0) {
            flash('error', 'Kebutuhan ' . $k['nama'] . ' masih punya alokasi aktif. Batalkan atau hapus alokasinya dulu.');
            muat_ulang();
        }
        if (kebutuhan_punya_serah($k['id'])) {
            flash('error', 'Kebutuhan ' . $k['nama'] . ' punya riwayat serah terima, jadi tidak bisa dihapus.');
            muat_ulang();
        }
        $id_terhapus = [];
        foreach (alokasi_kebutuhan($k['id']) as $a) {
            db_hapus('alokasi', $a['id']);
            $id_terhapus[] = (int) $a['id'];
        }
        lepas_dari_checklist($pid, $id_terhapus);
        db_hapus('kebutuhan', $k['id']);
        catat_riwayat('Kebutuhan', $proyek['kode'], $k['nama'] . ' dihapus');
        flash('sukses', 'Kebutuhan ' . $k['nama'] . ' dihapus.');
        muat_ulang();
    }

    if ($aksi === 'isi_preset') {
        wajib_boleh('kebutuhan.kelola');
        wajib_proyek_aktif($proyek);
        wajib_persiapan_terbuka($proyek);
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
        wajib_proyek_aktif($proyek);
        wajib_persiapan_terbuka($proyek);
        $k = db_cari('kebutuhan', (int) input('kebutuhan_id'));
        if (!$k || (int) $k['proyek_id'] !== $pid) {
            flash('error', 'Kebutuhan tidak ditemukan di proyek ini.');
            muat_ulang();
        }
        $modal = 'dialog-alokasi-' . $k['id'];
        $data = [
            'sumber' => input('sumber'),
            'perlengkapan_id' => input('perlengkapan_id'),
            'nama_barang' => input('nama_barang'),
            'vendor' => input('vendor'),
            'pemilik_id' => input('pemilik_id'),
            'jumlah' => input('jumlah', '1'),
            'status' => input('status', 'Dikonfirmasi'),
            'catatan' => input('catatan'),
        ];
        $galat = [];
        $sisa = pemenuhan_kebutuhan($k)['sisa'];
        if ($sisa < 1) {
            flash('error', 'Kebutuhan ' . $k['nama'] . ' sudah teralokasi penuh. Batalkan alokasi lain dulu jika ingin mengganti barang.');
            muat_ulang();
        }
        if (!in_array($data['status'], ['Dikonfirmasi', 'Direncanakan'], true)) {
            $galat['status'] = 'Status alokasi baru hanya Dikonfirmasi atau Direncanakan.';
        }
        if ($data['sumber'] === 'Studio') {
            $data['jumlah'] = '1';
        } elseif (!preg_match('/^\d{1,3}$/', $data['jumlah']) || (int) $data['jumlah'] < 1) {
            $galat['jumlah'] = 'Jumlah harus bilangan bulat minimal 1.';
        } elseif ((int) $data['jumlah'] > $sisa) {
            $galat['jumlah'] = 'Melebihi sisa kebutuhan. Hanya ' . $sisa . ' unit lagi yang bisa dialokasikan.';
        }
        if (!in_array($data['sumber'], daftar_sumber(), true)) {
            $galat['sumber'] = 'Pilih sumber barang.';
        } elseif ($data['sumber'] === 'Studio') {
            $unit = db_cari('perlengkapan', (int) $data['perlengkapan_id']);
            if (!$unit) {
                $galat['perlengkapan_id'] = 'Pilih unit.';
            } elseif ((int) $unit['kategori_id'] !== (int) $k['kategori_id']) {
                $galat['perlengkapan_id'] = $unit['kode_unit'] . ' berkategori ' . nama_kategori($unit['kategori_id']) . ', sedangkan kebutuhan ini berkategori ' . nama_kategori($k['kategori_id']) . '. Pilih unit dengan kategori yang sama.';
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
            'jumlah' => (int) $data['jumlah'],
            'tgl_mulai' => $mulai,
            'tgl_selesai' => $selesai,
            'status' => $data['status'],
            'catatan' => $data['catatan'],
        ]);
        lepas_dari_checklist($pid, [$baru]);
        $a = db_cari('alokasi', $baru);
        $kali =jumlah_alokasi($a) > 1 ? ' × ' . jumlah_alokasi($a) : '';
        catat_riwayat('Alokasi', ($studio ? keterangan_sumber($a) . ' · ' : nama_barang_alokasi($a) . ' · ') . $proyek['kode'], $data['sumber'] . ': ' . nama_barang_alokasi($a) . $kali . ' untuk ' . $k['nama']);
        flash('sukses', nama_barang_alokasi($a) . $kali . ' dialokasikan untuk ' . $k['nama'] . '.');
        muat_ulang();
    }

    if (in_array($aksi, ['konfirmasi_alokasi', 'batalkan_alokasi', 'hapus_alokasi'], true)) {
        wajib_boleh('alokasi.kelola');
        wajib_proyek_aktif($proyek);
        wajib_persiapan_terbuka($proyek);
        $a = db_cari('alokasi', (int) input('id'));
        if (!$a || (int) $a['proyek_id'] !== $pid) {
            flash('error', 'Alokasi tidak ditemukan di proyek ini.');
            muat_ulang();
        }
        $serah = serah_alokasi($a['id']);
        $nama = nama_barang_alokasi($a);
        $unit = $a['sumber'] === 'Studio' ? db_cari('perlengkapan', (int) $a['perlengkapan_id']) : null;
        if ($serah) {
            flash('error', $nama . ' sudah tercatat di serah terima' . (isset($serah['kembali']) ? ' (keluar dan sudah dikembalikan)' : ' dan masih di luar') . '. Alokasinya dikunci agar riwayat transaksi tetap sah.');
        } elseif ($aksi === 'konfirmasi_alokasi' && $a['status'] !== 'Direncanakan') {
            flash('error', 'Hanya alokasi berstatus Direncanakan yang bisa dikonfirmasi. ' . $nama . ' berstatus ' . $a['status'] . '.');
        } elseif ($aksi === 'konfirmasi_alokasi' && $a['sumber'] === 'Studio' && (!$unit || $unit['status_aktif'] !== 'Aktif' || $unit['kondisi'] !== 'Baik')) {
            flash('error', ($unit ? $unit['kode_unit'] . ' sedang ' . ($unit['status_aktif'] !== 'Aktif' ? 'nonaktif' : 'berkondisi ' . $unit['kondisi']) : 'Unit sudah dihapus') . ', jadi alokasinya belum bisa dikonfirmasi.');
        } elseif ($aksi === 'konfirmasi_alokasi') {
            db_ubah('alokasi', $a['id'], ['status' => 'Dikonfirmasi']);
            lepas_dari_checklist($pid, [(int) $a['id']]);
            catat_riwayat('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dikonfirmasi', $a['status'], 'Dikonfirmasi');
            flash('sukses', $nama . ' dikonfirmasi. Centang di Persiapan setelah barang diperiksa.');
        } elseif ($aksi === 'batalkan_alokasi' && $a['status'] === 'Dibatalkan') {
            flash('error', 'Alokasi ' . $nama . ' sudah dibatalkan sebelumnya.');
        } elseif (rental_punya_catatan_vendor($a)) {
            flash('error', $nama . ' sudah tercatat diterima dari vendor. Alokasinya dikunci; selesaikan lewat Perlengkapan › Barang eksternal.');
        } elseif ($aksi === 'batalkan_alokasi') {
            db_ubah('alokasi', $a['id'], ['status' => 'Dibatalkan']);
            catat_riwayat('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dibatalkan', $a['status'], 'Dibatalkan');
            flash('sukses', 'Alokasi ' . $nama . ' dibatalkan.');
        } else {
            db_hapus('alokasi', $a['id']);
            lepas_dari_checklist($pid, [(int) $a['id']]);
            catat_riwayat('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dihapus');
            flash('sukses', 'Alokasi ' . $nama . ' dihapus.');
        }
        muat_ulang();
    }

    if ($aksi === 'simpan_persiapan') {
        wajib_boleh('persiapan.kelola');
        wajib_proyek_aktif($proyek);
        wajib_persiapan_terbuka($proyek);
        $mentah = $_POST['diperiksa'] ?? [];
        if (!is_array($mentah) || array_filter($mentah, fn ($v) => !is_string($v))) {
            flash('error', 'Data checklist tidak valid. Muat ulang halaman lalu centang kembali.');
            muat_ulang();
        }
        $kiriman = array_values(array_unique(array_map(fn ($v) => preg_match('/^\d{1,9}$/', $v) ? (int) $v : 0, input_daftar('diperiksa'))));
        $aktif_p = alokasi_proyek($pid, true);
        $boleh_periksa = array_map(fn ($a) => (int) $a['id'], array_filter($aktif_p, 'alokasi_bisa_diperiksa'));
        $belum_bisa = array_map(fn ($a) => (int) $a['id'], array_filter($aktif_p, fn ($a) => !alokasi_bisa_diperiksa($a)));
        $diabaikan = count(array_diff($kiriman, $boleh_periksa));
        $rekam = ambil_persiapan($pid);
        $pilih = array_values(array_unique(array_merge(
            array_intersect($kiriman, $boleh_periksa),
            array_intersect(array_map('intval', $rekam['diperiksa'] ?? []), $belum_bisa)
        )));
        db_ubah('persiapan', $rekam['id'], ['diperiksa' => $pilih, 'diperbarui_pada' => date('Y-m-d H:i', strtotime(sekarang())), 'diperbarui_oleh' => $pengguna['id']]);
        $hasil = persiapan_proyek($proyek);
        catat_riwayat('Persiapan', $proyek['kode'], $hasil['selesai'] . ' dari ' . $hasil['total'] . ' barang diperiksa');
        $abaikan_teks = $diabaikan ? ' ' . $diabaikan . ' centang diabaikan karena alokasinya tidak ada di proyek ini atau belum bisa diperiksa.' : '';
        $nilai_p = penilaian_persiapan($proyek);
        if ($nilai_p['boleh_konfirmasi']) {
            flash('sukses', 'Persiapan disimpan: semua ' . $hasil['total'] . ' barang diperiksa dan seluruh syarat terpenuhi. Persiapan belum dikonfirmasi; lanjutkan dengan Konfirmasi Persiapan Selesai.' . $abaikan_teks);
        } elseif ($hasil['status'] === 'Siap') {
            flash('sukses', 'Persiapan disimpan: checklist lengkap, tetapi belum bisa dikonfirmasi. ' . implode(' ', $nilai_p['masalah']) . $abaikan_teks);
        } else {
            $catatan = [];
            if ($hasil['menunggu'] > 0) {
                $catatan[] = $hasil['menunggu'] . ' alokasi masih Direncanakan dan belum bisa diperiksa';
            }
            if ($hasil['menunggu_vendor'] > 0) {
                $catatan[] = $hasil['menunggu_vendor'] . ' rental menunggu penerimaan vendor';
            }
            if ($hasil['kurang'] > 0) {
                $catatan[] = $hasil['kurang'] . ' unit kebutuhan belum dialokasikan';
            }
            flash('sukses', 'Persiapan disimpan: ' . $hasil['selesai'] . ' dari ' . $hasil['total'] . ' barang diperiksa' . ($catatan ? '. Belum siap: ' . implode(', ', $catatan) . '.' : '.') . $abaikan_teks);
        }
        muat_ulang();
    }

    if ($aksi === 'konfirmasi_persiapan' || $aksi === 'buka_ulang_persiapan') {
        wajib_boleh('persiapan.kelola');
        if (!proyek_aktif($proyek)) {
            flash('error', 'Proyek ' . $proyek['kode'] . ' berstatus ' . $proyek['status'] . '. Konfirmasi persiapan tidak bisa dibuat atau dibuka ulang.');
            muat_ulang();
        }
        if (input('proyek_id') !== (string) $pid) {
            flash('error', 'Formulir tidak cocok dengan proyek ini. Muat ulang halaman lalu coba lagi.');
            muat_ulang();
        }
        $rekam = rekam_persiapan($pid);
        $nilai_p = penilaian_persiapan($proyek);
        if ($aksi === 'konfirmasi_persiapan') {
            if ($nilai_p['dikonfirmasi_pada']) {
                flash('error', 'Persiapan ' . $proyek['kode'] . ' sudah dikonfirmasi selesai oleh ' . nama_pengguna($nilai_p['dikonfirmasi_oleh']) . ' pada ' . tgl($nilai_p['dikonfirmasi_pada'], 'lengkap') . '. Konfirmasi tidak dicatat dua kali.');
                muat_ulang();
            }
            if (!$nilai_p['boleh_konfirmasi']) {
                flash('error', 'Persiapan belum bisa dikonfirmasi. ' . implode(' ', $nilai_p['masalah']));
                muat_ulang();
            }
            if (input('konfirmasi') !== '1') {
                simpan_form('dialog-konfirmasi-persiapan', [], ['konfirmasi' => 'Centang pernyataan bahwa seluruh perlengkapan sudah diperiksa dan siap.']);
                muat_ulang();
            }
            $rekam = ambil_persiapan($pid);
            $s = $nilai_p['syarat'];
            db_ubah('persiapan', (int) $rekam['id'], ['dikonfirmasi_pada' => date('Y-m-d H:i', strtotime(sekarang())), 'dikonfirmasi_oleh' => $pengguna['id']]);
            catat_riwayat('Persiapan', $proyek['kode'], 'Persiapan dikonfirmasi selesai · ' . $s['kebutuhan'] . ' kebutuhan, ' . $s['memenuhi'] . ' alokasi (Studio ' . $s['unit_sumber']['Studio'] . ', Rental ' . $s['unit_sumber']['Rental'] . ', Milik Kru ' . $s['unit_sumber']['Milik Kru'] . ' unit)', 'Siap untuk Dikonfirmasi', 'Dikonfirmasi Selesai');
            flash('sukses', 'Persiapan ' . $proyek['kode'] . ' dikonfirmasi selesai. Barang belum tercatat keluar; catat penyerahan barang di Serah Terima saat barang dibawa.');
            muat_ulang();
        }
        if (!$nilai_p['dikonfirmasi_pada']) {
            flash('error', 'Persiapan ' . $proyek['kode'] . ' belum dikonfirmasi, jadi tidak ada yang perlu dibuka ulang.');
            muat_ulang();
        }
        if ($nilai_p['beku']) {
            flash('error', $nilai_p['syarat']['sudah_keluar'] . ' alokasi barang proyek ini sudah tercatat keluar di serah terima. Konfirmasi persiapan tidak bisa dibuka ulang agar riwayat transaksi tetap konsisten.');
            muat_ulang();
        }
        $alasan = input('alasan');
        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 120) {
            simpan_form('dialog-buka-persiapan', ['alasan' => $alasan], ['alasan' => 'Tulis alasan 5 sampai 120 karakter.']);
            muat_ulang();
        }
        cabut_konfirmasi_persiapan($proyek, $rekam, $alasan, false);
        flash('sukses', 'Persiapan ' . $proyek['kode'] . ' dibuka ulang. Progres checklist tetap tersimpan; konfirmasi ulang diperlukan setelah perubahan selesai.');
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
$nilai_persiapan = penilaian_persiapan($proyek);
$ubah_kebutuhan = $bisa_ubah && !$nilai_persiapan['terkunci'];
$pemenuhan_total = ['terisi' => 0, 'jumlah' => 0, 'dikonfirmasi' => 0, 'diperiksa' => 0];
foreach ($kebutuhan as $k) {
    $p = pemenuhan_kebutuhan($k);
    $pemenuhan_total['terisi'] += min($p['terisi'], $p['jumlah']);
    $pemenuhan_total['jumlah'] += $p['jumlah'];
    $pemenuhan_total['dikonfirmasi'] += min($p['dikonfirmasi'], $p['jumlah']);
    $pemenuhan_total['diperiksa'] += min($p['diperiksa'], $p['jumlah']);
}
$di_proyek_p = ringkasan_barang_proyek($pid);
$id_diperiksa = id_diperiksa($pid);
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
<?= tombol_konfirmasi(['label' => 'Hapus', 'ikon' => 'trash-2', 'aksi' => 'hapus_proyek', 'id' => $pid, 'judul' => 'Hapus proyek ' . $proyek['kode'] . '?', 'pesan' => 'Kebutuhan, tim, alokasi, dan persiapan proyek ini ikut terhapus. Proyek yang sudah punya serah terima, rental yang dikonfirmasi vendor, atau catatan rental vendor tidak bisa dihapus.', 'tombol' => 'Hapus proyek', 'kelas' => 'btn btn-sekunder']) ?>
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
<p class="kpi-ket"><?= $pemenuhan_total['dikonfirmasi'] ?> dikonfirmasi · <?= $pemenuhan_total['diperiksa'] ?> diperiksa di persiapan</p>
</div>
<div class="kartu kpi">
<p class="kpi-label">Barang diperiksa</p>
<p class="kpi-angka"><?= $persiapan['selesai'] ?><span class="kpi-dari">/<?= $persiapan['total'] ?></span></p>
<?= bilah($persiapan['persen'], $persiapan['lewat'] ? 'merah' : ($persiapan['status'] === 'Siap' ? 'hijau' : 'tinta')) ?>
</div>
<div class="kartu kpi">
<p class="kpi-label">Barang di proyek</p>
<p class="kpi-angka" data-kpi-di-proyek><?= $di_proyek_p['unit'] ?></p>
<p class="kpi-ket"><?= $di_proyek_p['unit'] ? e(teks_per_sumber($di_proyek_p['per_sumber'])) . ' unit · ' . $di_proyek_p['alokasi'] . ' alokasi belum kembali' : ($di_proyek_p['pernah_keluar'] ? 'semua sudah kembali' : 'belum ada yang keluar') ?></p>
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
<p>Super Admin atau Petugas Inventaris menentukan kebutuhan, lalu memenuhinya dari studio, rental, atau barang milik kru.</p>
</div>
<?php if (boleh('kebutuhan.kelola') && $ubah_kebutuhan): ?>
<div class="kepala-aksi">
<?= tombol_modal('dialog-isi-preset', 'Isi dari preset', [], 'layers', 'btn btn-sekunder btn-kecil') ?>
<?= tombol_modal('dialog-kebutuhan', 'Tambah kebutuhan', ['_judul' => 'Tambah kebutuhan'], 'plus', 'btn btn-kecil') ?>
</div>
<?php endif; ?>
</div>
<?php if ($nilai_persiapan['terkunci']): ?>
<div class="pemberitahuan pemberitahuan-info pemberitahuan-kartu" role="status" data-kunci-persiapan><?= ikon('info') ?><span>Persiapan sudah dikonfirmasi selesai oleh <?= e(nama_pengguna($nilai_persiapan['dikonfirmasi_oleh'])) ?>, jadi kebutuhan dan alokasi dikunci. <?= $nilai_persiapan['boleh_buka'] ? 'Buka ulang persiapan di tab Persiapan jika memang perlu diubah.' : 'Barang sudah mulai keluar, sehingga konfirmasi tidak bisa dibuka ulang.' ?></span></div>
<?php endif; ?>
<?php if (!$kebutuhan): ?>
<?= kosong('Belum ada kebutuhan', boleh('kebutuhan.kelola') ? 'Tambahkan kebutuhan satu per satu atau isi cepat dari preset.' : 'Kebutuhan barang akan diisi oleh Super Admin atau Petugas Inventaris.') ?>
<?php else: ?>
<div class="kebutuhan-daftar">
<?php foreach ($kebutuhan as $k): ?>
<?php $p = pemenuhan_kebutuhan($k); $alokasi_k = alokasi_kebutuhan($k['id']); ?>
<article class="kebutuhan" id="kebutuhan-<?= (int) $k['id'] ?>">
<div class="kebutuhan-kepala">
<div class="daftar-utama">
<h3><?= e($k['nama']) ?> <span class="redup">× <?= (int) $k['jumlah'] ?></span></h3>
<p class="daftar-sub"><?= e(nama_kategori($k['kategori_id'])) ?><?= $k['acuan'] !== '' ? ' · acuan ' . e($k['acuan']) : '' ?></p>
<p class="daftar-sub" data-pemenuhan><?= e(rincian_pemenuhan($p)) ?></p>
</div>
<?= badge($p['status'], $p['terisi'] . '/' . $p['jumlah'] . ' · ' . $p['status']) ?>
<?= $p['pergerakan'] !== '' ? badge($p['pergerakan']) : '' ?>
<?= $p['kembali_bermasalah'] > 0 ? badge('Rusak', $p['kembali_bermasalah'] . ' kembali bermasalah') : '' ?>
<div class="aksi-baris">
<?php if (boleh('alokasi.kelola') && $ubah_kebutuhan && $p['terisi'] < $p['jumlah']): ?>
<?= tombol_modal('dialog-alokasi-' . $k['id'], 'Alokasikan', [], 'plus', 'btn btn-kecil') ?>
<?php endif; ?>
<?php if (boleh('kebutuhan.kelola') && $ubah_kebutuhan): ?>
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
<li class="alokasi<?= $batal ? ' alokasi-batal' : '' ?>" id="alokasi-<?= (int) $a['id'] ?>">
<span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= ikon($a['sumber'] === 'Studio' ? 'camera' : ($a['sumber'] === 'Rental' ? 'truck' : 'user'), 14) ?><?= e($a['sumber']) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><?= e(nama_barang_alokasi($a)) ?><?= jumlah_alokasi($a) > 1 ? ' <span class="redup">× ' . jumlah_alokasi($a) . '</span>' : '' ?></p>
<p class="daftar-sub"><?= e(keterangan_sumber($a)) ?><?= $a['catatan'] !== '' ? ' · ' . ($a['sumber'] === 'Rental' ? 'catatan (teks bebas): ' : '') . e($a['catatan']) : '' ?></p>
<?php if ($a['sumber'] === 'Rental' && !$batal): ?>
<p class="daftar-sub" data-rental>Vendor: <?= e(ringkasan_rental_teks($a)) ?></p>
<?php endif; ?>
<?php if ($a['sumber'] === 'Rental' && !$batal && $bisa_ubah && !empty($a['dikembalikan_vendor_pada'])): ?>
<p class="daftar-sub teks-kuning" data-rental-tidak-tersedia>Sudah dikembalikan ke vendor, jadi alokasi ini tidak bisa dipakai untuk persiapan proyek.</p>
<?php endif; ?>
</div>
<div class="alokasi-status">
<?= badge($a['status'], label_status_alokasi($a)) ?>
<?php if (alokasi_siap($a, $id_diperiksa)): ?>
<?= $a['sumber'] === 'Studio' ? badge('Siap', 'Siap · diperiksa') : badge('Diperiksa Petugas') ?>
<?php endif; ?>
<?php if (isset($serah['kembali'])): ?>
<?= ($serah['kondisi_kembali'] ?? 'Baik') === 'Baik' ? badge('Kembali') : badge($serah['kondisi_kembali'], 'Kembali · ' . $serah['kondisi_kembali']) ?>
<?php elseif (($serah['status_kembali'] ?? '') === 'Kembali Sebagian'): ?>
<?= badge('Kembali Sebagian', 'Kembali sebagian · ' . (int) $serah['jumlah_kembali'] . '/' . (int) $serah['jumlah_keluar']) ?>
<?php elseif (isset($serah['keluar'])): ?>
<?= badge('Sedang Keluar', 'Masih di proyek') ?>
<?php endif; ?>
</div>
<?php if (boleh('alokasi.kelola') && $ubah_kebutuhan && !$batal && !$serah && !rental_punya_catatan_vendor($a)): ?>
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
<?php
$np = $nilai_persiapan;
$sy = $np['syarat'];
$kelola_p = boleh('persiapan.kelola') && $bisa_ubah;
$bisa_centang = $kelola_p && !$np['terkunci'];
$batal_p = $np['konfirmasi_batal'];
$tautan_syarat = [];
$id_belum_terima = $sy['id_masalah']['rental_belum_terima'][0] ?? 0;
$id_dikembalikan = $sy['id_masalah']['rental_dikembalikan'][0] ?? 0;
if ($bisa_ubah && $id_belum_terima && boleh('rental.kelola')) {
    $tautan_syarat['rental_belum_terima'] = ' <a class="tautan-kecil" data-tautan-rental href="' . e(url('pages/perlengkapan.php?tab=eksternal&sumber=Rental&status=Dikonfirmasi&q=' . rawurlencode($proyek['kode']) . '#alokasi-' . $id_belum_terima)) . '">Kelola penerimaan rental</a>';
}
if ($bisa_ubah && $id_dikembalikan) {
    $tautan_syarat['rental_dikembalikan'] = ' Alokasi tersebut tidak bisa dipakai untuk persiapan proyek dan tidak bisa diterima ulang. <a class="tautan-kecil" data-tautan-tinjau href="' . e(url('pages/proyek-detail.php?id=' . $pid . '&tab=kebutuhan#alokasi-' . $id_dikembalikan)) . '">Tinjau di Kebutuhan &amp; Alokasi</a>';
}
?>
<section class="kartu">
<div class="kartu-kepala">
<div>
<h2>Persiapan barang</h2>
<p>Batas <?= e(tgl($proyek['deadline_persiapan'], 'lengkap')) ?><?= $persiapan['diperbarui_pada'] ? ' · progres disimpan ' . e(tgl($persiapan['diperbarui_pada'], 'singkat_jam')) . ' oleh ' . e(nama_pengguna($persiapan['diperbarui_oleh'])) : '' ?></p>
</div>
<div class="aksi-baris">
<?= badge($np['status']) ?>
<?= $persiapan['lewat'] ? badge('Lewat deadline') : '' ?>
</div>
</div>
<div class="persiapan-status" data-status-persiapan>
<?php if ($np['dikonfirmasi_pada']): ?>
<div class="pemberitahuan pemberitahuan-sukses" role="status"><?= ikon('circle-check') ?><div>
<p><strong>Persiapan Dikonfirmasi Selesai</strong></p>
<p data-konfirmasi-oleh>Dikonfirmasi oleh <?= e(nama_pengguna($np['dikonfirmasi_oleh'])) ?> · <?= e(tgl($np['dikonfirmasi_pada'], 'lengkap')) ?><?= $np['dikonfirmasi_pada'] > $proyek['deadline_persiapan'] ? ' (setelah batas persiapan)' : '' ?></p>
<p class="kecil"><?= e('Konfirmasi ini bukan pencatatan barang keluar. ' . (!$bisa_ubah ? 'Proyek berstatus ' . $proyek['status'] . '; konfirmasi disimpan sebagai riwayat.' : ($np['beku'] ? $sy['sudah_keluar'] . ' alokasi barang sudah tercatat keluar, jadi konfirmasi tidak bisa dibuka ulang.' : 'Kebutuhan, alokasi, dan checklist dikunci selama konfirmasi berlaku.'))) ?></p>
</div></div>
<?php if ($np['beku'] && $sy['masalah']): ?>
<div class="pemberitahuan pemberitahuan-peringatan" role="status"><?= ikon('triangle-alert') ?><div><p><strong>Kondisi berubah setelah barang mulai keluar</strong></p><ul class="daftar-syarat"><?php foreach ($sy['masalah'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<?php $belum_keluar_p = $bisa_ubah ? count(alokasi_siap_keluar($pid)) : 0; ?>
<?php if (($kelola_p && $np['boleh_buka']) || ($belum_keluar_p && boleh('serah_terima.kelola'))): ?>
<div class="persiapan-tombol">
<?php if ($belum_keluar_p && boleh('serah_terima.kelola')): ?>
<a class="btn btn-kecil" data-tautan-keluar href="<?= e(url('pages/serah-terima.php#barang-keluar')) ?>"><?= ikon('arrow-up-right', 16) ?><span>Catat barang keluar (<?= $belum_keluar_p ?> alokasi)</span></a>
<?php endif; ?>
<?php if ($kelola_p && $np['boleh_buka']): ?>
<?= tombol_modal('dialog-buka-persiapan', 'Buka ulang persiapan', [], 'rotate-ccw', 'btn btn-sekunder btn-kecil') ?>
<?php endif; ?>
</div>
<?php endif; ?>
<?php elseif ($np['status'] === 'Siap untuk Dikonfirmasi'): ?>
<div class="pemberitahuan pemberitahuan-info" role="status"><?= ikon('info') ?><div>
<p><strong>Siap untuk Dikonfirmasi</strong></p>
<p>Semua syarat terpenuhi menurut perhitungan sistem: <?= $sy['kebutuhan'] ?> kebutuhan, <?= $sy['memenuhi'] ?> alokasi diperiksa. Konfirmasi akhir belum diberikan.</p>
</div></div>
<?php if ($kelola_p): ?>
<div class="persiapan-tombol"><?= tombol_modal('dialog-konfirmasi-persiapan', 'Konfirmasi Persiapan Selesai', [], 'circle-check', 'btn') ?></div>
<?php endif; ?>
<?php else: ?>
<div class="pemberitahuan pemberitahuan-peringatan" role="status"><?= ikon('triangle-alert') ?><div>
<p><strong><?= e($np['status']) ?></strong><?= $bisa_ubah ? ' · selesaikan dulu sebelum konfirmasi:' : '' ?></p>
<ul class="daftar-syarat" data-syarat-persiapan><?php foreach ($np['masalah_kunci'] as $kunci_m => $m): ?><li><?= e($m) ?><?= $tautan_syarat[$kunci_m] ?? '' ?></li><?php endforeach; ?></ul>
</div></div>
<?php endif; ?>
<?php if (!$np['dikonfirmasi_pada'] && $batal_p): ?>
<?php $teks_batal = 'Konfirmasi sebelumnya oleh ' . nama_pengguna($batal_p['dikonfirmasi_oleh']) . ' (' . tgl($batal_p['dikonfirmasi_pada'], 'singkat_jam') . ') ' . ($batal_p['otomatis'] ? 'tidak berlaku lagi' : 'dibuka ulang oleh ' . nama_pengguna($batal_p['oleh'])) . ' pada ' . tgl($batal_p['pada'], 'singkat_jam') . ': ' . $batal_p['alasan']; ?>
<?php if ($kelola_p): ?>
<div class="pemberitahuan pemberitahuan-info" role="status" data-tinjau-ulang><?= ikon('rotate-ccw') ?><div>
<p data-konfirmasi-batal><?= e($teks_batal) ?></p>
<p class="kecil">Checklist sebelumnya masih tersimpan (<?= $persiapan['selesai'] ?> dari <?= $persiapan['total'] ?> diperiksa), tetapi belum berarti persiapan selesai. Tinjau ulang setiap barang terhadap kondisi terbaru, simpan progres bila ada perubahan, lalu konfirmasi kembali. Sistem tidak mengonfirmasi ulang secara otomatis meskipun checklist 100%.</p>
</div></div>
<?php else: ?>
<p class="kecil redup" data-konfirmasi-batal><?= e($teks_batal) ?></p>
<?php endif; ?>
<?php endif; ?>
</div>
<?php if (!$persiapan['item']): ?>
<?= kosong('Belum ada barang untuk disiapkan', 'Daftar periksa muncul setelah kebutuhan dialokasikan.') ?>
<?php else: ?>
<form method="post" class="persiapan" data-persiapan>
<?= csrf_field() ?>
<input type="hidden" name="aksi" value="simpan_persiapan">
<div class="persiapan-progres">
<div class="progres progres-besar">
<?= bilah($persiapan['persen'], $persiapan['lewat'] ? 'merah' : 'tinta') ?>
<span><strong data-progres-angka><?= $persiapan['selesai'] ?></strong> dari <?= $persiapan['total'] ?> diperiksa · <span data-progres-persen><?= $persiapan['persen'] ?></span>%</span>
</div>
<dl class="info-ringkas info-persiapan" data-ringkas-syarat>
<div><dt>Pemeriksaan</dt><dd><span data-status-periksa><?= $persiapan['selesai'] === 0 ? 'Belum ada barang yang diperiksa' : ($persiapan['selesai'] < $persiapan['total'] ? 'Sebagian barang sudah diperiksa' : 'Semua barang sudah diperiksa') ?></span><?= $bisa_centang ? '<span class="teks-kuning" data-belum-disimpan hidden> · centang berubah dan belum disimpan; kesiapan dihitung ulang setelah Simpan progres</span>' : '' ?></dd></div>
<div><dt>Kesiapan</dt><dd><?= $sy['memenuhi'] ?> dari <?= $sy['alokasi'] ?> alokasi memenuhi syarat · belum memenuhi syarat: <?= $sy['kebutuhan_bermasalah'] ?> kebutuhan, <?= $sy['alokasi_bermasalah'] ?> alokasi</dd></div>
<div><dt>Konfirmasi</dt><dd><?= $np['dikonfirmasi_pada'] ? 'Dikonfirmasi selesai oleh ' . e(nama_pengguna($np['dikonfirmasi_oleh'])) . ' · ' . e(tgl($np['dikonfirmasi_pada'], 'singkat_jam')) : ($bisa_ubah ? 'Belum dikonfirmasi' : 'Tidak dikonfirmasi · proyek ' . e($proyek['status'])) ?></dd></div>
</dl>
<?php $catatan_p = array_filter([$persiapan['menunggu'] > 0 ? $persiapan['menunggu'] . ' alokasi masih Direncanakan' : '', $persiapan['menunggu_vendor'] > 0 ? $persiapan['menunggu_vendor'] . ' rental menunggu penerimaan vendor' : '', $persiapan['kurang'] > 0 ? $persiapan['kurang'] . ' unit kebutuhan belum dialokasikan' : '']); ?>
<?php if ($catatan_p): ?>
<p class="kecil teks-kuning"><?= e(implode(' · ', $catatan_p)) ?></p>
<?php endif; ?>
<?php if ($bisa_centang): ?>
<button type="button" class="btn btn-sekunder btn-kecil" data-centang-semua>Centang semua</button>
<?php endif; ?>
</div>
<ul class="ceklist">
<?php foreach ($persiapan['item'] as $a): ?>
<?php $k = db_cari('kebutuhan', $a['kebutuhan_id']); $tercentang = in_array((int) $a['id'], $persiapan['diperiksa'], true); $menunggu = !alokasi_bisa_diperiksa($a); $tunggu_vendor = $a['status'] === 'Dikonfirmasi' && $menunggu; ?>
<li>
<label class="ceklist-item<?= $menunggu ? ' ceklist-mati' : '' ?>">
<input type="checkbox" name="diperiksa[]" value="<?= (int) $a['id'] ?>"<?= $tercentang ? ' checked' : '' ?><?= $bisa_centang && !$menunggu ? '' : ' disabled' ?>>
<span class="ceklist-kotak" aria-hidden="true"><?= ikon('check', 14) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e(nama_barang_alokasi($a)) ?><?= jumlah_alokasi($a) > 1 ? ' × ' . jumlah_alokasi($a) : '' ?></span>
<span class="daftar-sub"><?= e(keterangan_sumber($a)) ?> · untuk <?= e($k['nama'] ?? '—') ?><?= $menunggu ? ' · <span class="teks-kuning">' . e($tunggu_vendor ? 'Menunggu penerimaan vendor' : label_status_alokasi($a)) . ', belum bisa diperiksa' . (in_array((int) $a['id'], $persiapan['centang_tertahan'], true) ? ' (centang lama tersimpan, belum berlaku)' : '') . '</span>' : '' ?><?= $a['sumber'] === 'Rental' && !empty($a['dikembalikan_vendor_pada']) ? ' · <span class="teks-kuning">sudah dikembalikan ke vendor' . ($bisa_ubah ? ', tidak bisa dipakai untuk persiapan proyek ini' : '') . '</span>' : '' ?></span>
</span>
<span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= e($a['sumber']) ?></span>
</label>
</li>
<?php endforeach; ?>
</ul>
<?php if ($bisa_centang): ?>
<div class="kartu-kaki">
<p class="kecil redup">Centang barang yang sudah dicek fisik, lalu simpan progres kapan saja. Untuk Rental dan Kru, centang ini dicatat sebagai pemeriksaan petugas, bukan bukti penerimaan vendor atau serah terima. Checklist lengkap belum berarti persiapan dikonfirmasi.</p>
<button type="submit" class="btn">Simpan progres</button>
</div>
<?php elseif ($kelola_p && $np['terkunci']): ?>
<div class="kartu-kaki"><p class="kecil redup">Checklist dikunci karena persiapan sudah dikonfirmasi selesai.</p></div>
<?php endif; ?>
</form>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'serah'): ?>
<?php
$barang_p = alokasi_proyek($pid, true);
$gerbang_p = gerbang_keluar($proyek);
$jml_keluar_p = 0;
$unit_keluar_p = 0;
$unit_sisa_p = 0;
foreach ($barang_p as $bp) {
    $sp = serah_alokasi($bp['id']);
    if (isset($sp['keluar'])) {
        $jml_keluar_p++;
        $unit_keluar_p += $sp['jumlah_keluar'] ?? jumlah_alokasi($bp);
        $unit_sisa_p += $sp['sisa'] ?? 0;
    }
}
?>
<section class="kartu">
<div class="kartu-kepala">
<div>
<h2>Barang di proyek ini</h2>
<p data-ringkas-keluar><?= $jml_keluar_p ?> dari <?= count($barang_p) ?> alokasi sudah tercatat keluar (<?= $unit_keluar_p ?> unit) · <?= $unit_sisa_p ?> unit masih di proyek. Barang keluar dicatat di Serah Terima setelah persiapan dikonfirmasi; pengembalian dari proyek dicatat per jumlah dan kondisi.</p>
</div>
<?php if (boleh('serah_terima.kelola')): ?>
<a href="<?= e(url('pages/serah-terima.php')) ?>" class="btn btn-sekunder btn-kecil"><?= ikon('arrow-left-right', 16) ?><span>Buka serah terima</span></a>
<?php endif; ?>
</div>
<?php if ($bisa_ubah && !$gerbang_p['boleh'] && $jml_keluar_p < count($barang_p)): ?>
<div class="pemberitahuan pemberitahuan-peringatan pemberitahuan-kartu" role="status" data-gerbang-proyek><?= ikon('triangle-alert') ?><span>Barang keluar belum bisa dicatat. <?= e($gerbang_p['alasan']) ?> <a class="tautan-kecil" href="<?= e(url('pages/proyek-detail.php?id=' . $pid . '&tab=persiapan')) ?>">Buka Persiapan</a></span></div>
<?php endif; ?>
<?php if (!$barang_p): ?>
<?= kosong('Belum ada barang', 'Barang akan muncul setelah kebutuhan dialokasikan.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
<thead><tr><th>Barang</th><th class="angka">Jumlah</th><th>Keluar</th><th>Jadwal selesai</th><th>Kembali dari proyek</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($barang_p as $a): ?>
<?php $serah = serah_alokasi($a['id']); ?>
<tr id="serah-<?= (int) $a['id'] ?>">
<td data-label="Barang"><strong class="teks-pecah"><?= e($a['sumber'] === 'Studio' ? keterangan_sumber($a) : nama_barang_alokasi($a)) ?></strong><div class="kecil redup teks-pecah"><span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= e($a['sumber']) ?></span> <?= e($a['sumber'] === 'Studio' ? nama_barang_alokasi($a) : keterangan_sumber($a)) ?></div></td>
<td data-label="Jumlah" class="angka"><?= (int) ($serah['jumlah_keluar'] ?? jumlah_alokasi($a)) ?> unit</td>
<td data-label="Keluar"><?php if (isset($serah['keluar'])): ?><?php $trx_s = db_cari('transaksi', $serah['trx_keluar'] ?? 0); ?><?= e(tgl($serah['keluar'], 'singkat_jam')) ?><div class="kecil redup"><?= e(($trx_s['kode'] ?? '?') . (!empty($trx_s['penerima']) ? ' · ' . $trx_s['penerima'] : '')) ?></div><?php else: ?><span class="redup">—</span><?php endif; ?></td>
<td data-label="Jadwal selesai"><?= e(tgl($a['tgl_selesai'])) ?></td>
<td data-label="Kembali dari proyek"><?php if (!empty($serah['jumlah_kembali'])): ?><?= (int) $serah['jumlah_kembali'] ?> dari <?= (int) $serah['jumlah_keluar'] ?> unit · sisa <?= (int) $serah['sisa'] ?><div class="kecil redup"><?= e(teks_kondisi($serah['kondisi_rincian'] ?? []) . ' · terakhir ' . tgl($serah['kembali_terakhir'], 'singkat_jam')) ?></div><?php elseif (isset($serah['keluar'])): ?>0 dari <?= (int) $serah['jumlah_keluar'] ?> unit · sisa <?= (int) $serah['sisa'] ?><?php else: ?><span class="redup">—</span><?php endif; ?></td>
<td data-label="Status">
<?php if (isset($serah['kembali'])): ?>
<?= badge('Sudah Kembali') ?>
<?php elseif (($serah['status_kembali'] ?? '') === 'Kembali Sebagian'): ?>
<?= badge('Kembali Sebagian') ?>
<?php elseif (isset($serah['keluar'])): ?>
<?= badge('Sedang Keluar', 'Masih di proyek') ?>
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
<p class="daftar-sub"><?= e(tgl($trx['waktu'], 'lengkap')) ?> · <?= array_sum(array_map('jumlah_detail', $trx['detail'])) ?> unit · <?= e($trx['jenis'] === 'Keluar' ? 'diterima ' . $trx['penerima'] : 'dicek ' . nama_pengguna($trx['petugas_id'])) ?></p>
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
if (boleh('kebutuhan.kelola') && $ubah_kebutuhan) {
    require ROOT . '/partials/modal_kebutuhan.php';
}
if (boleh('persiapan.kelola') && $bisa_ubah && $tab === 'persiapan') {
    require ROOT . '/partials/modal_persiapan.php';
}
if (boleh('alokasi.kelola') && $ubah_kebutuhan && $tab === 'kebutuhan') {
    foreach ($kebutuhan as $kd) {
        if (pemenuhan_kebutuhan($kd)['terisi'] < $kd['jumlah']) {
            require ROOT . '/partials/modal_alokasi.php';
        }
    }
}
?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
