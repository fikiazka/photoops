<?php
defined('ROOT') || exit;

function daftar_status_proyek(): array
{
    return ['Draf', 'Dijadwalkan', 'Berlangsung', 'Selesai', 'Dibatalkan'];
}

function daftar_sumber(): array
{
    return ['Studio', 'Rental', 'Milik Kru'];
}

function proyek_aktif(array $proyek): bool
{
    return !in_array($proyek['status'], ['Selesai', 'Dibatalkan'], true);
}

function id_proyek_pengguna(int $pengguna_id): array
{
    $hasil = [];
    foreach (db('anggota_proyek') as $anggota) {
        if ((int) $anggota['pengguna_id'] === $pengguna_id) {
            $hasil[] = (int) $anggota['proyek_id'];
        }
    }
    return $hasil;
}

function boleh_lihat_proyek(array $proyek, array $pengguna): bool
{
    return boleh('proyek.semua') || in_array((int) $proyek['id'], id_proyek_pengguna($pengguna['id']), true);
}

function urutkan_proyek(array $daftar): array
{
    usort($daftar, function ($a, $b) {
        $aktif_a = proyek_aktif($a);
        $aktif_b = proyek_aktif($b);
        if ($aktif_a !== $aktif_b) {
            return $aktif_a ? -1 : 1;
        }
        return $aktif_a ? strcmp($a['mulai'], $b['mulai']) : strcmp($b['mulai'], $a['mulai']);
    });
    return $daftar;
}

function proyek_terlihat(array $pengguna): array
{
    $daftar = array_values(db('proyek'));
    if (!boleh('proyek.semua')) {
        $milik = id_proyek_pengguna($pengguna['id']);
        $daftar = array_values(array_filter($daftar, fn ($p) => in_array((int) $p['id'], $milik, true)));
    }
    return urutkan_proyek($daftar);
}

function peran_di_proyek(int $proyek_id, int $pengguna_id): string
{
    foreach (db('anggota_proyek') as $anggota) {
        if ((int) $anggota['proyek_id'] === $proyek_id && (int) $anggota['pengguna_id'] === $pengguna_id) {
            return $anggota['peran'];
        }
    }
    return '';
}

function anggota_proyek(int $proyek_id): array
{
    $hasil = [];
    foreach (db('anggota_proyek') as $anggota) {
        if ((int) $anggota['proyek_id'] === $proyek_id) {
            $anggota['pengguna'] = db_cari('pengguna', $anggota['pengguna_id']);
            if ($anggota['pengguna']) {
                $hasil[] = $anggota;
            }
        }
    }
    usort($hasil, fn ($a, $b) => ($a['peran'] === 'PIC' ? 0 : 1) <=> ($b['peran'] === 'PIC' ? 0 : 1) ?: strcmp($a['pengguna']['nama'], $b['pengguna']['nama']));
    return $hasil;
}

function atur_pic(int $proyek_id, int $pic_id): void
{
    foreach (db('anggota_proyek') as $anggota) {
        if ((int) $anggota['proyek_id'] === $proyek_id && $anggota['peran'] === 'PIC' && (int) $anggota['pengguna_id'] !== $pic_id) {
            db_ubah('anggota_proyek', $anggota['id'], ['peran' => 'Kru']);
        }
    }
    foreach (db('anggota_proyek') as $anggota) {
        if ((int) $anggota['proyek_id'] === $proyek_id && (int) $anggota['pengguna_id'] === $pic_id) {
            db_ubah('anggota_proyek', $anggota['id'], ['peran' => 'PIC']);
            return;
        }
    }
    db_tambah('anggota_proyek', ['proyek_id' => $proyek_id, 'pengguna_id' => $pic_id, 'peran' => 'PIC']);
}

function kebutuhan_proyek(int $proyek_id): array
{
    $hasil = array_values(array_filter(db('kebutuhan'), fn ($k) => (int) $k['proyek_id'] === $proyek_id));
    usort($hasil, fn ($a, $b) => $a['kategori_id'] <=> $b['kategori_id'] ?: $a['id'] <=> $b['id']);
    return $hasil;
}

function alokasi_proyek(int $proyek_id, bool $aktif_saja = false): array
{
    $hasil = [];
    foreach (db('alokasi') as $alokasi) {
        if ((int) $alokasi['proyek_id'] === $proyek_id && (!$aktif_saja || $alokasi['status'] !== 'Dibatalkan')) {
            $hasil[] = $alokasi;
        }
    }
    return $hasil;
}

function alokasi_kebutuhan(int $kebutuhan_id): array
{
    return array_values(array_filter(db('alokasi'), fn ($a) => (int) $a['kebutuhan_id'] === $kebutuhan_id));
}

function pemenuhan_kebutuhan(array $kebutuhan): array
{
    $terisi = count(array_filter(alokasi_kebutuhan($kebutuhan['id']), fn ($a) => $a['status'] !== 'Dibatalkan'));
    $status = 'Belum Terpenuhi';
    if ($terisi >= $kebutuhan['jumlah']) {
        $status = 'Terpenuhi';
    } elseif ($terisi > 0) {
        $status = 'Sebagian';
    }
    return ['terisi' => $terisi, 'jumlah' => (int) $kebutuhan['jumlah'], 'status' => $status];
}

function nama_barang_alokasi(array $alokasi): string
{
    if ($alokasi['sumber'] === 'Studio') {
        return db_cari('perlengkapan', (int) $alokasi['perlengkapan_id'])['nama'] ?? 'Unit dihapus';
    }
    return $alokasi['nama_barang'];
}

function keterangan_sumber(array $alokasi): string
{
    if ($alokasi['sumber'] === 'Studio') {
        return db_cari('perlengkapan', (int) $alokasi['perlengkapan_id'])['kode_unit'] ?? '—';
    }
    if ($alokasi['sumber'] === 'Rental') {
        return $alokasi['vendor'];
    }
    return 'Milik ' . nama_pengguna($alokasi['pemilik_id']);
}

function label_status_alokasi(array $alokasi): string
{
    if ($alokasi['sumber'] === 'Rental') {
        $peta = ['Direncanakan' => 'Menunggu vendor', 'Dikonfirmasi' => 'Disetujui vendor'];
        return $peta[$alokasi['status']] ?? $alokasi['status'];
    }
    return $alokasi['status'];
}

function ambil_persiapan(int $proyek_id): array
{
    foreach (db('persiapan') as $baris) {
        if ((int) $baris['proyek_id'] === $proyek_id) {
            return $baris;
        }
    }
    $id = db_tambah('persiapan', ['proyek_id' => $proyek_id, 'diperiksa' => [], 'diperbarui_pada' => null, 'diperbarui_oleh' => null]);
    return db_cari('persiapan', $id);
}

function persiapan_proyek(array $proyek): array
{
    $item = alokasi_proyek($proyek['id'], true);
    $rekam = null;
    foreach (db('persiapan') as $baris) {
        if ((int) $baris['proyek_id'] === (int) $proyek['id']) {
            $rekam = $baris;
            break;
        }
    }
    $id_item = array_map(fn ($a) => (int) $a['id'], $item);
    $diperiksa = array_values(array_intersect(array_map('intval', $rekam['diperiksa'] ?? []), $id_item));
    $total = count($item);
    $selesai = count($diperiksa);
    if ($total === 0) {
        $status = 'Belum Dimulai';
    } elseif ($selesai === 0) {
        $status = 'Belum Dimulai';
    } elseif ($selesai < $total) {
        $status = 'Dalam Persiapan';
    } else {
        $status = 'Siap';
    }
    $lewat = proyek_aktif($proyek) && $total > 0 && $selesai < $total && sekarang() > $proyek['deadline_persiapan'];
    return [
        'item' => $item,
        'diperiksa' => $diperiksa,
        'total' => $total,
        'selesai' => $selesai,
        'persen' => persen($selesai, $total),
        'status' => $status,
        'lewat' => $lewat,
        'diperbarui_pada' => $rekam['diperbarui_pada'] ?? null,
        'diperbarui_oleh' => $rekam['diperbarui_oleh'] ?? null,
    ];
}

function kode_proyek_baru(): string
{
    $maks = 0;
    foreach (db('proyek') as $proyek) {
        $maks = max($maks, (int) substr($proyek['kode'], 4));
    }
    return 'PRJ-' . str_pad((string) ($maks + 1), 3, '0', STR_PAD_LEFT);
}

function validasi_proyek(array $data): array
{
    $galat = [];
    foreach (['nama' => 'Nama proyek', 'klien' => 'Klien', 'lokasi' => 'Lokasi'] as $kunci => $label) {
        if ($data[$kunci] === '') {
            $galat[$kunci] = $label . ' wajib diisi.';
        }
    }
    $mulai = waktu_valid($data['mulai']);
    $selesai = waktu_valid($data['selesai']);
    $deadline = waktu_valid($data['deadline_persiapan']);
    if (!$mulai) {
        $galat['mulai'] = 'Isi waktu mulai.';
    }
    if (!$selesai) {
        $galat['selesai'] = 'Isi waktu selesai.';
    } elseif ($mulai && $selesai < $mulai) {
        $galat['selesai'] = 'Waktu selesai harus setelah waktu mulai.';
    }
    if (!$deadline) {
        $galat['deadline_persiapan'] = 'Isi batas persiapan.';
    } elseif ($mulai && $deadline > $mulai) {
        $galat['deadline_persiapan'] = 'Batas persiapan harus sebelum acara dimulai.';
    }
    $pic = db_cari('pengguna', (int) $data['pic_id']);
    if (!$pic || $pic['peran'] !== 'us') {
        $galat['pic_id'] = 'Pilih PIC dari daftar kru.';
    }
    if (!in_array($data['status'], daftar_status_proyek(), true)) {
        $galat['status'] = 'Pilih status.';
    }
    return $galat;
}

function data_form_proyek(array $proyek): array
{
    return [
        'id' => $proyek['id'],
        'nama' => $proyek['nama'],
        'klien' => $proyek['klien'],
        'lokasi' => $proyek['lokasi'],
        'mulai' => waktu_input($proyek['mulai']),
        'selesai' => waktu_input($proyek['selesai']),
        'deadline_persiapan' => waktu_input($proyek['deadline_persiapan']),
        'pic_id' => $proyek['pic_id'],
        'status' => $proyek['status'],
        'catatan' => $proyek['catatan'],
        '_judul' => 'Ubah proyek ' . $proyek['kode'],
    ];
}

function simpan_proyek_dari_form(array $pengguna): void
{
    wajib_boleh('proyek.kelola');
    $id = (int) input('id');
    $lama = $id ? db_cari('proyek', $id) : null;
    if ($id && !$lama) {
        flash('error', 'Proyek tidak ditemukan.');
        arahkan('pages/proyek.php');
    }
    $data = [
        'nama' => input('nama'),
        'klien' => input('klien'),
        'lokasi' => input('lokasi'),
        'mulai' => input('mulai'),
        'selesai' => input('selesai'),
        'deadline_persiapan' => input('deadline_persiapan'),
        'pic_id' => input('pic_id'),
        'status' => input('status'),
        'catatan' => input('catatan'),
    ];
    $galat = validasi_proyek($data);
    if ($galat) {
        simpan_form('dialog-proyek', $data + ['id' => $id ?: ''], $galat);
        muat_ulang();
    }
    $isi = [
        'nama' => $data['nama'],
        'klien' => $data['klien'],
        'lokasi' => $data['lokasi'],
        'mulai' => waktu_valid($data['mulai']),
        'selesai' => waktu_valid($data['selesai']),
        'deadline_persiapan' => waktu_valid($data['deadline_persiapan']),
        'status' => $data['status'],
        'pic_id' => (int) $data['pic_id'],
        'catatan' => $data['catatan'],
    ];
    if ($lama) {
        db_ubah('proyek', $id, $isi);
        atur_pic($id, $isi['pic_id']);
        $ganti = $lama['status'] !== $isi['status'];
        catat_riwayat('Proyek', $lama['kode'] . ' · ' . $isi['nama'], $ganti ? 'Status proyek diubah' : 'Detail proyek diperbarui', $ganti ? $lama['status'] : '', $ganti ? $isi['status'] : '');
        flash('sukses', 'Proyek ' . $lama['kode'] . ' diperbarui.');
        muat_ulang();
    }
    $kode = kode_proyek_baru();
    $id = db_tambah('proyek', ['kode' => $kode] + $isi + ['dibuat_oleh' => $pengguna['id']]);
    atur_pic($id, $isi['pic_id']);
    ambil_persiapan($id);
    catat_riwayat('Proyek', $kode . ' · ' . $isi['nama'], 'Proyek baru dibuat');
    flash('sukses', 'Proyek ' . $kode . ' dibuat. Lanjutkan dengan menambah tim dan kebutuhan.');
    arahkan('pages/proyek-detail.php?id=' . $id);
}

function hapus_proyek(int $proyek_id): void
{
    foreach (['kebutuhan', 'alokasi', 'anggota_proyek', 'persiapan'] as $tabel) {
        foreach (db($tabel) as $baris) {
            if ((int) $baris['proyek_id'] === $proyek_id) {
                db_hapus($tabel, $baris['id']);
            }
        }
    }
    db_hapus('proyek', $proyek_id);
}

function proyek_punya_transaksi(int $proyek_id): bool
{
    foreach (db('transaksi') as $trx) {
        if ((int) $trx['proyek_id'] === $proyek_id) {
            return true;
        }
    }
    return false;
}

function proyek_mendatang(int $hari = 14): array
{
    $batas = date('Y-m-d', strtotime(HARI_INI . ' +' . $hari . ' days'));
    $hasil = array_filter(db('proyek'), fn ($p) => proyek_aktif($p) && substr($p['selesai'], 0, 10) >= HARI_INI && substr($p['mulai'], 0, 10) <= $batas);
    return urutkan_proyek(array_values($hasil));
}

function masalah_operasional(): array
{
    $hasil = [];
    foreach (db('proyek') as $proyek) {
        if (!proyek_aktif($proyek)) {
            continue;
        }
        $kurang = 0;
        foreach (kebutuhan_proyek($proyek['id']) as $kebutuhan) {
            $p = pemenuhan_kebutuhan($kebutuhan);
            $kurang += max(0, $p['jumlah'] - $p['terisi']);
        }
        if ($kurang > 0) {
            $hasil[] = ['tingkat' => 'merah', 'ikon' => 'package', 'judul' => $kurang . ' kebutuhan belum teralokasi', 'keterangan' => $proyek['kode'] . ' · ' . $proyek['nama'] . ' · mulai ' . tgl($proyek['mulai'], 'singkat'), 'tautan' => 'pages/proyek-detail.php?id=' . $proyek['id'] . '&tab=kebutuhan'];
        }
        $persiapan = persiapan_proyek($proyek);
        if ($persiapan['lewat']) {
            $hasil[] = ['tingkat' => 'merah', 'ikon' => 'list-checks', 'judul' => 'Persiapan lewat deadline', 'keterangan' => $proyek['kode'] . ' · ' . $persiapan['selesai'] . ' dari ' . $persiapan['total'] . ' barang diperiksa, batas ' . tgl($proyek['deadline_persiapan'], 'singkat_jam'), 'tautan' => 'pages/proyek-detail.php?id=' . $proyek['id'] . '&tab=persiapan'];
        }
        foreach (alokasi_proyek($proyek['id'], true) as $alokasi) {
            if ($alokasi['sumber'] === 'Rental' && $alokasi['status'] === 'Direncanakan') {
                $hasil[] = ['tingkat' => 'kuning', 'ikon' => 'truck', 'judul' => 'Rental menunggu konfirmasi vendor', 'keterangan' => $alokasi['nama_barang'] . ' · ' . $alokasi['vendor'] . ' · ' . $proyek['kode'], 'tautan' => 'pages/proyek-detail.php?id=' . $proyek['id'] . '&tab=kebutuhan'];
            }
            if ($alokasi['sumber'] !== 'Studio' || sedang_keluar($alokasi) || sudah_kembali($alokasi)) {
                continue;
            }
            $unit = db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']);
            if (!$unit) {
                continue;
            }
            if ($unit['kondisi'] !== 'Baik') {
                $hasil[] = ['tingkat' => 'merah', 'ikon' => 'wrench', 'judul' => $unit['kode_unit'] . ' dialokasikan tapi kondisi ' . $unit['kondisi'], 'keterangan' => 'Dibutuhkan ' . $proyek['kode'] . ' mulai ' . tgl($alokasi['tgl_mulai'], 'singkat'), 'tautan' => 'pages/proyek-detail.php?id=' . $proyek['id'] . '&tab=kebutuhan'];
            }
            $keluar = alokasi_keluar_unit($unit['id']);
            if ($keluar && (int) $keluar['proyek_id'] !== (int) $proyek['id'] && (HARI_INI > $keluar['tgl_selesai'] || $keluar['tgl_selesai'] >= $alokasi['tgl_mulai'])) {
                $asal = db_cari('proyek', $keluar['proyek_id']);
                $hasil[] = ['tingkat' => 'merah', 'ikon' => 'clock', 'judul' => 'Bentrok: ' . $unit['kode_unit'] . ' belum kembali', 'keterangan' => 'Masih di ' . ($asal['kode'] ?? '?') . ', dibutuhkan ' . $proyek['kode'] . ' mulai ' . tgl($alokasi['tgl_mulai'], 'singkat'), 'tautan' => 'pages/serah-terima.php'];
            }
        }
    }
    $urutan = ['merah' => 0, 'kuning' => 1];
    usort($hasil, fn ($a, $b) => $urutan[$a['tingkat']] <=> $urutan[$b['tingkat']]);
    return $hasil;
}
