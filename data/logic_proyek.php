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

function wajib_proyek_aktif(array $proyek): void
{
    if (!proyek_aktif($proyek)) {
        flash('error', 'Proyek ' . $proyek['kode'] . ' berstatus ' . $proyek['status'] . '. Kebutuhan dan alokasinya tidak bisa diubah lagi.');
        muat_ulang();
    }
}

function validasi_kebutuhan(array $data, ?array $lama): array
{
    $galat = [];
    $panjang_nama = mb_strlen($data['nama']);
    if ($panjang_nama < 2) {
        $galat['nama'] = 'Nama kebutuhan wajib diisi.';
    } elseif ($panjang_nama > 60) {
        $galat['nama'] = 'Nama kebutuhan maksimal 60 karakter.';
    }
    if (!db_cari('kategori', (int) $data['kategori_id'])) {
        $galat['kategori_id'] = 'Pilih kategori.';
    } elseif ($lama && (int) $data['kategori_id'] !== (int) $lama['kategori_id']) {
        $aktif = count(array_filter(alokasi_kebutuhan($lama['id']), fn ($a) => $a['status'] !== 'Dibatalkan'));
        if (kebutuhan_punya_serah($lama['id'])) {
            $galat['kategori_id'] = 'Kategori tidak bisa diubah karena barang untuk kebutuhan ini sudah tercatat keluar di serah terima. Kategori dikunci agar alokasi dan riwayat transaksinya tetap sah.';
        } elseif ($aktif > 0) {
            $galat['kategori_id'] = 'Kategori tidak bisa diubah karena masih ada ' . $aktif . ' alokasi aktif. Batalkan atau hapus alokasi terkait terlebih dahulu.';
        }
    }
    if (!preg_match('/^\d{1,3}$/', $data['jumlah']) || (int) $data['jumlah'] < 1 || (int) $data['jumlah'] > 50) {
        $galat['jumlah'] = 'Jumlah harus bilangan bulat 1 sampai 50.';
    } elseif ($lama && (int) $data['jumlah'] < pemenuhan_kebutuhan($lama)['terisi']) {
        $galat['jumlah'] = 'Tidak bisa kurang dari ' . pemenuhan_kebutuhan($lama)['terisi'] . ' unit yang sudah ada di alokasi aktif. Batalkan atau hapus alokasinya dulu.';
    }
    if (mb_strlen($data['acuan']) > 60) {
        $galat['acuan'] = 'Model acuan maksimal 60 karakter.';
    }
    return $galat;
}

function kebutuhan_punya_serah(int $kebutuhan_id): bool
{
    foreach (alokasi_kebutuhan($kebutuhan_id) as $alokasi) {
        if (serah_alokasi($alokasi['id'])) {
            return true;
        }
    }
    return false;
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

function jumlah_alokasi(array $alokasi): int
{
    if ($alokasi['sumber'] === 'Studio') {
        return 1;
    }
    return max(1, (int) ($alokasi['jumlah'] ?? 1));
}

function rekam_persiapan(int $proyek_id): ?array
{
    foreach (db('persiapan') as $baris) {
        if ((int) $baris['proyek_id'] === $proyek_id) {
            return $baris;
        }
    }
    return null;
}

function id_diperiksa(int $proyek_id): array
{
    return array_map('intval', rekam_persiapan($proyek_id)['diperiksa'] ?? []);
}

function lepas_dari_checklist(int $proyek_id, array $alokasi_ids): void
{
    $rekam = rekam_persiapan($proyek_id);
    if (!$rekam) {
        return;
    }
    $lama = array_map('intval', $rekam['diperiksa'] ?? []);
    $baru = array_values(array_diff($lama, array_map('intval', $alokasi_ids)));
    if (count($baru) !== count($lama)) {
        db_ubah('persiapan', (int) $rekam['id'], ['diperiksa' => $baru]);
    }
}

function alokasi_bisa_diperiksa(array $alokasi): bool
{
    return $alokasi['status'] === 'Dikonfirmasi' && ($alokasi['sumber'] !== 'Rental' || !empty($alokasi['diterima_pada']));
}

function alokasi_diperiksa(array $alokasi, array $diperiksa): bool
{
    return alokasi_bisa_diperiksa($alokasi) && in_array((int) $alokasi['id'], $diperiksa, true);
}

function alokasi_siap(array $alokasi, array $diperiksa): bool
{
    if (!alokasi_diperiksa($alokasi, $diperiksa) || serah_alokasi($alokasi['id'])) {
        return false;
    }
    $proyek = db_cari('proyek', (int) $alokasi['proyek_id']);
    if (!$proyek || !proyek_aktif($proyek)) {
        return false;
    }
    if ($alokasi['sumber'] !== 'Studio') {
        return empty($alokasi['dikembalikan_vendor_pada']);
    }
    $unit = db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']);
    return $unit && $unit['status_aktif'] === 'Aktif' && $unit['kondisi'] === 'Baik' && !alokasi_keluar_unit($unit['id']);
}

function pemenuhan_kebutuhan(array $kebutuhan): array
{
    $diperiksa = id_diperiksa((int) $kebutuhan['proyek_id']);
    $proyek = db_cari('proyek', (int) $kebutuhan['proyek_id']);
    $aktif = $proyek && proyek_aktif($proyek);
    $hasil = ['dialokasikan' => 0, 'direncanakan' => 0, 'dikonfirmasi' => 0, 'diperiksa' => 0, 'siap' => 0, 'siap_eksternal' => 0, 'eksternal' => 0,
        'keluar' => 0, 'di_luar' => 0, 'terlambat' => 0, 'kembali' => 0, 'kembali_bermasalah' => 0];
    foreach (alokasi_kebutuhan($kebutuhan['id']) as $alokasi) {
        if ($alokasi['status'] === 'Dibatalkan') {
            continue;
        }
        $qty = jumlah_alokasi($alokasi);
        $hasil['dialokasikan'] += $qty;
        if ($alokasi['sumber'] !== 'Studio') {
            $hasil['eksternal'] += $qty;
        }
        if ($alokasi['status'] !== 'Dikonfirmasi') {
            $hasil['direncanakan'] += $qty;
            continue;
        }
        $hasil['dikonfirmasi'] += $qty;
        if (alokasi_diperiksa($alokasi, $diperiksa)) {
            $hasil['diperiksa'] += $qty;
        }
        if (alokasi_siap($alokasi, $diperiksa)) {
            $hasil['siap'] += $qty;
            if ($alokasi['sumber'] !== 'Studio') {
                $hasil['siap_eksternal'] += $qty;
            }
        }
        $serah = serah_alokasi($alokasi['id']);
        if (isset($serah['keluar'])) {
            $hasil['keluar'] += $qty;
            if (isset($serah['kembali'])) {
                $hasil['kembali'] += $qty;
                if (($serah['kondisi_kembali'] ?? 'Baik') !== 'Baik') {
                    $hasil['kembali_bermasalah'] += $qty;
                }
            } else {
                $hasil['di_luar'] += $qty;
                if (status_keluar($alokasi) === 'Terlambat') {
                    $hasil['terlambat'] += $qty;
                }
            }
        }
    }
    $jumlah = (int) $kebutuhan['jumlah'];
    if ($hasil['dialokasikan'] === 0) {
        $status = 'Belum Terpenuhi';
    } elseif ($hasil['dialokasikan'] < $jumlah) {
        $status = 'Sebagian';
    } elseif ($hasil['dikonfirmasi'] < $jumlah) {
        $status = 'Menunggu Konfirmasi';
    } elseif ($hasil['keluar'] > 0 || !$aktif) {
        $status = $hasil['diperiksa'] >= $jumlah ? 'Diperiksa' : 'Belum Diperiksa';
    } elseif ($hasil['siap'] >= $jumlah) {
        $status = $hasil['siap_eksternal'] > 0 ? 'Diperiksa Petugas' : 'Siap';
    } else {
        $status = 'Belum Diperiksa';
    }
    return $hasil + [
        'terisi' => $hasil['dialokasikan'],
        'jumlah' => $jumlah,
        'sisa' => max(0, $jumlah - $hasil['dialokasikan']),
        'status' => $status,
        'pergerakan' => status_pergerakan($hasil),
        'proyek_aktif' => $aktif,
    ];
}

function rincian_pemenuhan(array $p): string
{
    $teks = 'Dialokasikan ' . $p['dialokasikan'] . '/' . $p['jumlah'] . ' · direncanakan ' . $p['direncanakan'] . ' · dikonfirmasi ' . $p['dikonfirmasi'] . ' · diperiksa ' . $p['diperiksa'];
    if (!$p['proyek_aktif']) {
        $teks .= ' · proyek ditutup, kesiapan saat ini tidak dihitung';
    } else {
        $teks .= ' · siap ' . $p['siap'];
        if ($p['siap_eksternal'] > 0) {
            $teks .= ' (' . $p['siap_eksternal'] . ' Rental/Kru berdasarkan pemeriksaan petugas)';
        }
    }
    if ($p['keluar'] > 0) {
        $teks .= ' · unit Studio keluar ' . $p['keluar'] . ': di luar ' . $p['di_luar'] . ', kembali ' . $p['kembali'];
        if ($p['kembali_bermasalah'] > 0) {
            $teks .= ' (' . $p['kembali_bermasalah'] . ' Rusak/Maintenance)';
        }
        if ($p['eksternal'] > 0) {
            $teks .= ' · Rental/Kru belum dicatat di serah terima';
        }
    }
    return $teks;
}

function status_pergerakan(array $hitung): string
{
    if ($hitung['keluar'] === 0) {
        return '';
    }
    if ($hitung['di_luar'] > 0) {
        if ($hitung['kembali'] > 0) {
            return 'Pengembalian Sebagian';
        }
        return $hitung['terlambat'] > 0 ? 'Belum Kembali' : 'Sedang Keluar';
    }
    return $hitung['eksternal'] > 0 ? 'Studio Sudah Kembali' : 'Sudah Kembali';
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
    $rekam = rekam_persiapan((int) $proyek['id']);
    $tersimpan = array_map('intval', $rekam['diperiksa'] ?? []);
    $id_bisa = array_map(fn ($a) => (int) $a['id'], array_filter($item, 'alokasi_bisa_diperiksa'));
    $id_tunggu_vendor = array_map(fn ($a) => (int) $a['id'], array_filter($item, fn ($a) => $a['status'] === 'Dikonfirmasi' && !alokasi_bisa_diperiksa($a)));
    $diperiksa = array_values(array_intersect($tersimpan, $id_bisa));
    $total = count($item);
    $selesai = count($diperiksa);
    $menunggu = count(array_filter($item, fn ($a) => $a['status'] !== 'Dikonfirmasi'));
    $kurang = 0;
    foreach (kebutuhan_proyek((int) $proyek['id']) as $kebutuhan) {
        $kurang += pemenuhan_kebutuhan($kebutuhan)['sisa'];
    }
    if ($total === 0 || $selesai === 0) {
        $status = 'Belum Dimulai';
    } elseif ($selesai < $total || $kurang > 0) {
        $status = 'Dalam Persiapan';
    } else {
        $status = 'Siap';
    }
    $lewat = proyek_aktif($proyek) && ($total > 0 || $kurang > 0) && $status !== 'Siap' && sekarang() > $proyek['deadline_persiapan'];
    return [
        'item' => $item,
        'diperiksa' => $diperiksa,
        'total' => $total,
        'selesai' => $selesai,
        'persen' => persen($selesai, $total),
        'status' => $status,
        'menunggu' => $menunggu,
        'menunggu_vendor' => count($id_tunggu_vendor),
        'centang_tertahan' => array_values(array_intersect($tersimpan, $id_tunggu_vendor)),
        'kurang' => $kurang,
        'lewat' => $lewat,
        'diperbarui_pada' => $rekam['diperbarui_pada'] ?? null,
        'diperbarui_oleh' => $rekam['diperbarui_oleh'] ?? null,
    ];
}

function alokasi_sesuai_kebutuhan(array $alokasi, ?array $kebutuhan, int $proyek_id): bool
{
    if (!$kebutuhan || (int) $kebutuhan['proyek_id'] !== $proyek_id || !in_array($alokasi['sumber'], daftar_sumber(), true)) {
        return false;
    }
    if ($alokasi['sumber'] === 'Studio') {
        $unit = db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']);
        return !$unit || (int) $unit['kategori_id'] === (int) $kebutuhan['kategori_id'];
    }
    if (trim((string) $alokasi['nama_barang']) === '') {
        return false;
    }
    if ($alokasi['sumber'] === 'Rental') {
        return trim((string) $alokasi['vendor']) !== '';
    }
    return peran_di_proyek($proyek_id, (int) $alokasi['pemilik_id']) !== '';
}

function syarat_persiapan(array $proyek): array
{
    $pid = (int) $proyek['id'];
    $diperiksa = id_diperiksa($pid);
    $h = ['kebutuhan' => 0, 'unit_butuh' => 0, 'kurang' => 0, 'lebih' => 0, 'alokasi' => 0, 'memenuhi' => 0, 'sudah_keluar' => 0,
        'tidak_sesuai' => 0, 'belum_konfirmasi' => 0, 'rental_belum_terima' => 0, 'rental_dikembalikan' => 0,
        'unit_tidak_layak' => 0, 'unit_bentrok' => 0, 'belum_diperiksa' => 0,
        'unit_sumber' => ['Studio' => 0, 'Rental' => 0, 'Milik Kru' => 0]];
    $kebutuhan = [];
    $kebutuhan_masalah = [];
    foreach (kebutuhan_proyek($pid) as $k) {
        $kebutuhan[(int) $k['id']] = $k;
        $p = pemenuhan_kebutuhan($k);
        $h['kebutuhan']++;
        $h['unit_butuh'] += $p['jumlah'];
        $h['kurang'] += $p['sisa'];
        $h['lebih'] += max(0, $p['dialokasikan'] - $p['jumlah']);
        if ($p['sisa'] > 0 || $p['dialokasikan'] > $p['jumlah']) {
            $kebutuhan_masalah[(int) $k['id']] = true;
        }
    }
    $alokasi_masalah = 0;
    $id_masalah = [];
    foreach (alokasi_proyek($pid, true) as $a) {
        $h['alokasi']++;
        $k = $kebutuhan[(int) $a['kebutuhan_id']] ?? null;
        $keluar = isset(serah_alokasi($a['id'])['keluar']);
        $unit = $a['sumber'] === 'Studio' ? db_cari('perlengkapan', (int) $a['perlengkapan_id']) : null;
        $lain = $unit && !$keluar ? alokasi_keluar_unit((int) $unit['id']) : null;
        $masalah = '';
        if (!alokasi_sesuai_kebutuhan($a, $k, $pid)) {
            $masalah = 'tidak_sesuai';
        } elseif ($a['status'] !== 'Dikonfirmasi') {
            $masalah = 'belum_konfirmasi';
        } elseif ($a['sumber'] === 'Rental' && empty($a['diterima_pada'])) {
            $masalah = 'rental_belum_terima';
        } elseif ($a['sumber'] === 'Rental' && !empty($a['dikembalikan_vendor_pada'])) {
            $masalah = 'rental_dikembalikan';
        } elseif ($a['sumber'] === 'Studio' && !$keluar && (!$unit || $unit['status_aktif'] !== 'Aktif' || $unit['kondisi'] !== 'Baik')) {
            $masalah = 'unit_tidak_layak';
        } elseif ($lain && (int) $lain['id'] !== (int) $a['id']) {
            $masalah = 'unit_bentrok';
        } elseif (!in_array((int) $a['id'], $diperiksa, true)) {
            $masalah = 'belum_diperiksa';
        }
        if ($masalah !== '') {
            $h[$masalah]++;
            $alokasi_masalah++;
            $id_masalah[$masalah][] = (int) $a['id'];
            if ($k) {
                $kebutuhan_masalah[(int) $k['id']] = true;
            }
            continue;
        }
        $h['memenuhi']++;
        $h['unit_sumber'][$a['sumber']] += jumlah_alokasi($a);
        if ($keluar) {
            $h['sudah_keluar']++;
        }
    }
    $pesan = [];
    if (!proyek_aktif($proyek)) {
        $pesan['proyek_tutup'] = 'Proyek berstatus ' . $proyek['status'] . ', jadi persiapannya tidak bisa dikonfirmasi lagi.';
    }
    if ($h['kebutuhan'] === 0) {
        $pesan['tanpa_kebutuhan'] = 'Proyek belum punya kebutuhan perlengkapan.';
    }
    $teks = [
        'kurang' => 'Masih ada %d unit perlengkapan yang belum dialokasikan.',
        'lebih' => 'Ada %d unit alokasi yang melebihi jumlah kebutuhan.',
        'tidak_sesuai' => 'Ada %d alokasi yang sumber atau kategorinya tidak sesuai dengan kebutuhan.',
        'belum_konfirmasi' => 'Masih ada %d alokasi yang belum dikonfirmasi.',
        'rental_belum_terima' => 'Ada %d barang rental yang belum tercatat diterima dari vendor.',
        'rental_dikembalikan' => 'Ada %d barang rental yang sudah dikembalikan ke vendor, jadi tidak lagi tersedia untuk proyek ini.',
        'unit_tidak_layak' => 'Ada %d unit studio yang tidak layak pakai (rusak, maintenance, nonaktif, atau dihapus).',
        'unit_bentrok' => 'Ada %d unit studio yang masih keluar di proyek lain.',
        'belum_diperiksa' => 'Masih ada %d perlengkapan yang belum diperiksa.',
    ];
    foreach ($teks as $kunci => $format) {
        if ($h[$kunci] > 0) {
            $pesan[$kunci] = sprintf($format, $h[$kunci]);
        }
    }
    return $h + [
        'kebutuhan_bermasalah' => count($kebutuhan_masalah),
        'alokasi_bermasalah' => $alokasi_masalah,
        'masalah' => array_values($pesan),
        'masalah_kunci' => $pesan,
        'id_masalah' => $id_masalah,
        'siap' => !$pesan,
    ];
}

function penilaian_persiapan(array $proyek): array
{
    $syarat = syarat_persiapan($proyek);
    $rekam = rekam_persiapan((int) $proyek['id']);
    $pada = $rekam['dikonfirmasi_pada'] ?? null;
    $aktif = proyek_aktif($proyek);
    $masalah_kunci = $syarat['masalah_kunci'];
    if (!$pada && $aktif && $syarat['sudah_keluar'] > 0) {
        $masalah_kunci['data_lama'] = $syarat['sudah_keluar'] . ' unit studio sudah tercatat keluar sebelum ada konfirmasi persiapan (data lama). Konfirmasi baru tidak dibuat agar urutan transaksi tetap jujur.';
    }
    $masalah = array_values($masalah_kunci);
    if ($pada) {
        $status = 'Persiapan Dikonfirmasi Selesai';
    } elseif (!$aktif) {
        $status = 'Tidak Dikonfirmasi';
    } else {
        $status = $masalah ? 'Belum Siap' : 'Siap untuk Dikonfirmasi';
    }
    $beku = $pada && $aktif && $syarat['sudah_keluar'] > 0;
    return [
        'syarat' => $syarat,
        'masalah' => $masalah,
        'masalah_kunci' => $masalah_kunci,
        'status' => $status,
        'dikonfirmasi_pada' => $pada,
        'dikonfirmasi_oleh' => $rekam['dikonfirmasi_oleh'] ?? null,
        'konfirmasi_batal' => $rekam['konfirmasi_batal'] ?? null,
        'terkunci' => $pada && $aktif,
        'beku' => $beku,
        'boleh_konfirmasi' => !$pada && $aktif && !$masalah,
        'boleh_buka' => $pada && $aktif && !$beku,
        'perlu_cabut' => $pada && $aktif && !$beku && !$syarat['siap'],
    ];
}

function wajib_persiapan_terbuka(array $proyek): void
{
    $rekam = rekam_persiapan((int) $proyek['id']);
    if (!proyek_aktif($proyek) || empty($rekam['dikonfirmasi_pada'])) {
        return;
    }
    flash('error', 'Persiapan ' . $proyek['kode'] . ' sudah dikonfirmasi selesai oleh ' . nama_pengguna($rekam['dikonfirmasi_oleh']) . ' pada ' . tgl($rekam['dikonfirmasi_pada'], 'lengkap') . '. Kebutuhan, alokasi, dan checklist dikunci selama konfirmasi berlaku. Buka ulang persiapan di tab Persiapan jika memang perlu diubah.');
    muat_ulang();
}

function cabut_konfirmasi_persiapan(array $proyek, array $rekam, string $alasan, bool $otomatis): void
{
    db_ubah('persiapan', (int) $rekam['id'], [
        'dikonfirmasi_pada' => null,
        'dikonfirmasi_oleh' => null,
        'konfirmasi_batal' => [
            'pada' => date('Y-m-d H:i', strtotime(sekarang())),
            'oleh' => $_SESSION['user_id'] ?? null,
            'otomatis' => $otomatis,
            'alasan' => $alasan,
            'dikonfirmasi_pada' => $rekam['dikonfirmasi_pada'],
            'dikonfirmasi_oleh' => $rekam['dikonfirmasi_oleh'],
        ],
    ]);
    catat_riwayat('Persiapan', $proyek['kode'], ($otomatis ? 'Konfirmasi persiapan selesai tidak berlaku lagi: ' : 'Persiapan dibuka ulang: ') . $alasan, 'Dikonfirmasi Selesai', $otomatis ? 'Perlu konfirmasi ulang' : 'Dibuka ulang');
}

function sinkron_konfirmasi_persiapan(): void
{
    foreach (db('persiapan') as $rekam) {
        if (empty($rekam['dikonfirmasi_pada'])) {
            continue;
        }
        $proyek = db_cari('proyek', (int) $rekam['proyek_id']);
        if (!$proyek) {
            continue;
        }
        $nilai = penilaian_persiapan($proyek);
        if (!$nilai['perlu_cabut']) {
            continue;
        }
        $alasan = implode(' ', $nilai['syarat']['masalah']);
        cabut_konfirmasi_persiapan($proyek, $rekam, $alasan, true);
        flash('info', 'Konfirmasi persiapan ' . $proyek['kode'] . ' tidak berlaku lagi karena data berubah: ' . $alasan . ' Periksa ulang lalu konfirmasi kembali.');
    }
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

function rental_penghalang_hapus(int $proyek_id): array
{
    $hasil = ['catatan_vendor' => [], 'komitmen' => []];
    foreach (db('alokasi') as $alokasi) {
        if ((int) $alokasi['proyek_id'] !== $proyek_id || $alokasi['sumber'] !== 'Rental') {
            continue;
        }
        if (!empty($alokasi['diterima_pada']) || !empty($alokasi['dikembalikan_vendor_pada'])) {
            $hasil['catatan_vendor'][] = $alokasi['nama_barang'];
        } elseif ($alokasi['status'] === 'Dikonfirmasi') {
            $hasil['komitmen'][] = $alokasi['nama_barang'];
        }
    }
    return $hasil;
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
