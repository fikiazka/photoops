<?php
defined('ROOT') || exit;

function kode_transaksi_baru(): string
{
    $maks = 0;
    foreach (db('transaksi') as $trx) {
        $maks = max($maks, (int) substr($trx['kode'], 4));
    }
    return 'TRX-' . str_pad((string) ($maks + 1), 3, '0', STR_PAD_LEFT);
}

function status_keluar(array $alokasi): string
{
    if (HARI_INI > $alokasi['tgl_selesai']) {
        return 'Terlambat';
    }
    if (HARI_INI === $alokasi['tgl_selesai']) {
        return 'Hari ini';
    }
    return 'Dipinjam';
}

function hari_terlambat(array $alokasi): int
{
    return max(0, (int) ((strtotime(HARI_INI) - strtotime($alokasi['tgl_selesai'])) / 86400));
}

function unit_siap_keluar(int $proyek_id): array
{
    $hasil = [];
    foreach (alokasi_proyek($proyek_id, true) as $alokasi) {
        if ($alokasi['sumber'] !== 'Studio' || isset(serah_alokasi($alokasi['id'])['keluar'])) {
            continue;
        }
        $unit = db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']);
        $blokir = '';
        if ($alokasi['status'] !== 'Dikonfirmasi') {
            $blokir = 'Alokasi belum dikonfirmasi';
        } elseif (!$unit) {
            $blokir = 'Unit sudah dihapus';
        } elseif ($unit['kondisi'] !== 'Baik') {
            $blokir = 'Kondisi ' . $unit['kondisi'];
        } else {
            $keluar = alokasi_keluar_unit($unit['id']);
            if ($keluar) {
                $blokir = 'Masih keluar di ' . (db_cari('proyek', $keluar['proyek_id'])['kode'] ?? '?');
            }
        }
        $hasil[] = ['alokasi' => $alokasi, 'unit' => $unit, 'blokir' => $blokir];
    }
    return $hasil;
}

function unit_belum_kembali(?int $proyek_id = null): array
{
    $hasil = [];
    foreach (db('alokasi') as $alokasi) {
        if ($alokasi['sumber'] !== 'Studio' || !sedang_keluar($alokasi)) {
            continue;
        }
        if ($proyek_id !== null && (int) $alokasi['proyek_id'] !== $proyek_id) {
            continue;
        }
        $hasil[] = ['alokasi' => $alokasi, 'unit' => db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']), 'status' => status_keluar($alokasi), 'keluar' => serah_alokasi($alokasi['id'])['keluar']];
    }
    usort($hasil, fn ($a, $b) => strcmp($a['alokasi']['tgl_selesai'], $b['alokasi']['tgl_selesai']));
    return $hasil;
}

function kelompok_per_proyek(array $daftar): array
{
    $hasil = [];
    foreach ($daftar as $baris) {
        $id = (int) $baris['alokasi']['proyek_id'];
        $hasil[$id] = $hasil[$id] ?? ['proyek' => db_cari('proyek', $id), 'unit' => []];
        $hasil[$id]['unit'][] = $baris;
    }
    return $hasil;
}

function proyek_siap_keluar(): array
{
    $hasil = [];
    foreach (urutkan_proyek(array_values(db('proyek'))) as $proyek) {
        if (!proyek_aktif($proyek)) {
            continue;
        }
        $unit = unit_siap_keluar($proyek['id']);
        if ($unit) {
            $hasil[$proyek['id']] = ['proyek' => $proyek, 'unit' => $unit];
        }
    }
    return $hasil;
}

function penerima_proyek(int $proyek_id): array
{
    $hasil = [];
    foreach (anggota_proyek($proyek_id) as $anggota) {
        $hasil[$anggota['pengguna']['nama']] = $anggota['pengguna']['nama'] . ' · ' . $anggota['peran'];
    }
    return $hasil;
}

function proses_checkout(array $proyek, array $alokasi_ids, string $penerima, string $catatan): string
{
    $kode = kode_transaksi_baru();
    $trx_id = db_tambah('transaksi', [
        'kode' => $kode,
        'proyek_id' => $proyek['id'],
        'jenis' => 'Keluar',
        'waktu' => date('Y-m-d H:i', strtotime(sekarang())),
        'petugas_id' => $_SESSION['user_id'] ?? null,
        'penerima' => $penerima,
        'catatan' => $catatan,
    ]);
    foreach ($alokasi_ids as $id) {
        db_tambah('detail_transaksi', ['transaksi_id' => $trx_id, 'alokasi_id' => $id, 'kondisi' => 'Baik']);
    }
    catat_riwayat('Barang Keluar', $proyek['kode'] . ' · ' . $kode, count($alokasi_ids) . ' unit diserahkan ke ' . $penerima);
    return $kode;
}

function proses_checkin(array $proyek, array $kondisi_per_alokasi, string $catatan): array
{
    $kode = kode_transaksi_baru();
    $trx_id = db_tambah('transaksi', [
        'kode' => $kode,
        'proyek_id' => $proyek['id'],
        'jenis' => 'Kembali',
        'waktu' => date('Y-m-d H:i', strtotime(sekarang())),
        'petugas_id' => $_SESSION['user_id'] ?? null,
        'penerima' => nama_pengguna($_SESSION['user_id'] ?? null),
        'catatan' => $catatan,
    ]);
    $terlambat = 0;
    $bermasalah = [];
    foreach ($kondisi_per_alokasi as $alokasi_id => $kondisi) {
        $alokasi = db_cari('alokasi', (int) $alokasi_id);
        db_tambah('detail_transaksi', ['transaksi_id' => $trx_id, 'alokasi_id' => (int) $alokasi_id, 'kondisi' => $kondisi]);
        if (HARI_INI > $alokasi['tgl_selesai']) {
            $terlambat++;
        }
        if ($kondisi !== 'Baik') {
            $unit = db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']);
            $jenis = $kondisi === 'Rusak' ? 'Rusak' : 'Masalah Teknis';
            $deskripsi = $catatan !== '' ? $catatan : 'Dilaporkan saat pengembalian ' . $kode . '.';
            lapor_insiden((int) $alokasi['perlengkapan_id'], (int) $proyek['id'], $jenis, $deskripsi, $kondisi);
            $bermasalah[] = $unit['kode_unit'] ?? '?';
        }
    }
    $jumlah = count($kondisi_per_alokasi);
    $ket = $jumlah . ' unit kembali' . ($terlambat ? ', ' . $terlambat . ' terlambat' : ' tepat waktu') . ($bermasalah ? ', bermasalah: ' . implode(', ', $bermasalah) : '');
    catat_riwayat('Pengembalian', $proyek['kode'] . ' · ' . $kode, $ket);
    return ['kode' => $kode, 'terlambat' => $terlambat, 'bermasalah' => $bermasalah];
}

function daftar_transaksi(?int $proyek_id = null): array
{
    $detail = [];
    foreach (db('detail_transaksi') as $baris) {
        $detail[$baris['transaksi_id']][] = $baris;
    }
    $hasil = [];
    foreach (db('transaksi') as $trx) {
        if ($proyek_id !== null && (int) $trx['proyek_id'] !== $proyek_id) {
            continue;
        }
        $trx['detail'] = $detail[$trx['id']] ?? [];
        $trx['proyek'] = db_cari('proyek', $trx['proyek_id']);
        $hasil[] = $trx;
    }
    usort($hasil, fn ($a, $b) => strcmp($b['waktu'], $a['waktu']));
    return $hasil;
}

function pengembalian_terlambat_historis(): int
{
    $jumlah = 0;
    foreach (db('alokasi') as $alokasi) {
        $kembali = serah_alokasi($alokasi['id'])['kembali'] ?? null;
        if ($kembali && substr($kembali, 0, 10) > $alokasi['tgl_selesai']) {
            $jumlah++;
        }
    }
    return $jumlah;
}

function tren_mingguan(int $minggu = 6): array
{
    $senin = date('Y-m-d', strtotime('monday this week', strtotime(HARI_INI)));
    $hasil = [];
    for ($i = $minggu - 1; $i >= 0; $i--) {
        $awal = date('Y-m-d', strtotime($senin . ' -' . ($i * 7) . ' days'));
        $akhir = date('Y-m-d', strtotime($awal . ' +6 days'));
        $hasil[$awal] = ['label' => tgl($awal, 'singkat'), 'akhir' => $akhir, 'Keluar' => 0, 'Kembali' => 0];
    }
    $transaksi = db('transaksi');
    foreach (db('detail_transaksi') as $detail) {
        $trx = $transaksi[$detail['transaksi_id']] ?? null;
        if (!$trx) {
            continue;
        }
        $tanggal = substr($trx['waktu'], 0, 10);
        foreach ($hasil as $awal => $baris) {
            if ($tanggal >= $awal && $tanggal <= $baris['akhir']) {
                $hasil[$awal][$trx['jenis']]++;
                break;
            }
        }
    }
    return $hasil;
}
