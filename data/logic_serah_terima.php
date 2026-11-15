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

function jumlah_detail(array $detail): int
{
    return max(1, (int) ($detail['jumlah'] ?? 1));
}

function gerbang_keluar(array $proyek): array
{
    $np = penilaian_persiapan($proyek);
    if (!proyek_aktif($proyek)) {
        return ['boleh' => false, 'alasan' => 'Proyek ' . $proyek['kode'] . ' berstatus ' . $proyek['status'] . ', jadi barang tidak bisa dikeluarkan lagi. Pengembalian barang yang masih di luar tetap bisa dicatat.', 'nilai' => $np];
    }
    if (!$np['dikonfirmasi_pada']) {
        if ($np['syarat']['sudah_keluar'] > 0) {
            $alasan = 'Barang proyek ini sudah tercatat keluar sebelum ada konfirmasi persiapan (data lama). Barang keluar baru tidak bisa dicatat karena persiapannya tidak pernah dikonfirmasi; pengembalian unit studio tetap bisa dicatat.';
        } elseif ($np['konfirmasi_batal']) {
            $alasan = 'Konfirmasi persiapan sebelumnya ' . ($np['konfirmasi_batal']['otomatis'] ? 'tidak berlaku lagi' : 'sudah dibuka ulang') . '. Konfirmasi ulang di tab Persiapan sebelum barang dikeluarkan.';
        } elseif ($np['status'] === 'Siap untuk Dikonfirmasi') {
            $alasan = 'Checklist dan syarat sudah terpenuhi, tetapi persiapan belum dikonfirmasi selesai. Konfirmasi di tab Persiapan terlebih dahulu.';
        } else {
            $alasan = 'Persiapan belum dikonfirmasi selesai (' . $np['status'] . '). Selesaikan persiapan lalu konfirmasi di tab Persiapan.';
        }
        return ['boleh' => false, 'alasan' => $alasan, 'nilai' => $np];
    }
    if ($np['perlu_cabut']) {
        return ['boleh' => false, 'alasan' => 'Konfirmasi persiapan tidak berlaku lagi karena data berubah: ' . implode(' ', $np['syarat']['masalah']), 'nilai' => $np];
    }
    return ['boleh' => true, 'alasan' => '', 'nilai' => $np];
}

function alasan_tidak_bisa_keluar(array $alokasi, array $diperiksa): string
{
    if ($alokasi['status'] === 'Dibatalkan') {
        return 'Alokasi dibatalkan';
    }
    $serah = serah_alokasi((int) $alokasi['id']);
    if (isset($serah['keluar'])) {
        $trx = db_cari('transaksi', $serah['trx_keluar'] ?? 0);
        return isset($serah['kembali']) ? 'Sudah keluar dan sudah dikembalikan' : 'Sudah tercatat keluar' . ($trx ? ' (' . $trx['kode'] . ')' : '');
    }
    if ($alokasi['status'] !== 'Dikonfirmasi') {
        return 'Alokasi belum dikonfirmasi';
    }
    $pid = (int) $alokasi['proyek_id'];
    if (!alokasi_sesuai_kebutuhan($alokasi, db_cari('kebutuhan', (int) $alokasi['kebutuhan_id']), $pid)) {
        return 'Tidak sesuai dengan kebutuhan proyek';
    }
    if ($alokasi['sumber'] === 'Studio') {
        $unit = db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']);
        if (!$unit) {
            return 'Unit sudah dihapus';
        }
        if ($unit['status_aktif'] !== 'Aktif') {
            return 'Unit nonaktif';
        }
        if ($unit['kondisi'] !== 'Baik') {
            return 'Kondisi ' . $unit['kondisi'];
        }
        $lain = alokasi_keluar_unit((int) $unit['id']);
        if ($lain) {
            return 'Masih keluar di ' . (db_cari('proyek', $lain['proyek_id'])['kode'] ?? '?');
        }
    }
    if ($alokasi['sumber'] === 'Rental') {
        if (empty($alokasi['diterima_pada'])) {
            return 'Belum tercatat diterima dari vendor';
        }
        if (!empty($alokasi['dikembalikan_vendor_pada'])) {
            return 'Sudah dikembalikan ke vendor';
        }
    }
    if (!in_array((int) $alokasi['id'], $diperiksa, true)) {
        return 'Belum diperiksa di persiapan';
    }
    return '';
}

function alokasi_siap_keluar(int $proyek_id): array
{
    $diperiksa = id_diperiksa($proyek_id);
    $hasil = [];
    foreach (alokasi_proyek($proyek_id, true) as $alokasi) {
        if (isset(serah_alokasi((int) $alokasi['id'])['keluar'])) {
            continue;
        }
        $hasil[] = [
            'alokasi' => $alokasi,
            'unit' => $alokasi['sumber'] === 'Studio' ? db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']) : null,
            'jumlah' => jumlah_alokasi($alokasi),
            'blokir' => alasan_tidak_bisa_keluar($alokasi, $diperiksa),
        ];
    }
    return $hasil;
}

function hitung_per_sumber(array $baris, bool $hanya_bisa = false): array
{
    $hasil = ['Studio' => 0, 'Rental' => 0, 'Milik Kru' => 0];
    foreach ($baris as $b) {
        if (!$hanya_bisa || ($b['blokir'] ?? '') === '') {
            $hasil[$b['alokasi']['sumber']] += $b['jumlah'] ?? jumlah_alokasi($b['alokasi']);
        }
    }
    return $hasil;
}

function teks_per_sumber(array $per_sumber): string
{
    $bagian = [];
    foreach ($per_sumber as $sumber => $jumlah) {
        if ($jumlah > 0) {
            $bagian[] = $sumber . ' ' . $jumlah;
        }
    }
    return implode(', ', $bagian);
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

function barang_di_luar(?int $proyek_id = null): array
{
    $hasil = [];
    foreach (db('alokasi') as $alokasi) {
        if (!sedang_keluar($alokasi) || ($proyek_id !== null && (int) $alokasi['proyek_id'] !== $proyek_id)) {
            continue;
        }
        $serah = serah_alokasi((int) $alokasi['id']);
        $hasil[] = [
            'alokasi' => $alokasi,
            'unit' => $alokasi['sumber'] === 'Studio' ? db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']) : null,
            'jumlah' => $serah['jumlah_keluar'] ?? jumlah_alokasi($alokasi),
            'status' => status_keluar($alokasi),
            'keluar' => $serah['keluar'],
        ];
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
        $unit = alokasi_siap_keluar((int) $proyek['id']);
        if (!$unit) {
            continue;
        }
        $gerbang = gerbang_keluar($proyek);
        $bisa = $gerbang['boleh'] ? array_values(array_filter($unit, fn ($u) => $u['blokir'] === '')) : [];
        $hasil[$proyek['id']] = [
            'proyek' => $proyek,
            'unit' => $unit,
            'gerbang' => $gerbang,
            'bisa_alokasi' => count($bisa),
            'bisa_unit' => array_sum(array_column($bisa, 'jumlah')),
        ];
    }
    return $hasil;
}

function penerima_proyek(int $proyek_id): array
{
    $hasil = [];
    foreach (anggota_proyek($proyek_id) as $anggota) {
        if ($anggota['pengguna']['status'] === 'Aktif') {
            $hasil[(string) $anggota['pengguna']['id']] = $anggota['pengguna']['nama'] . ' · ' . $anggota['peran'];
        }
    }
    return $hasil;
}

function proses_checkout(array $proyek, array $item, array $penerima, string $waktu, string $catatan): array
{
    $kode = kode_transaksi_baru();
    $trx_id = db_tambah('transaksi', [
        'kode' => $kode,
        'proyek_id' => $proyek['id'],
        'jenis' => 'Keluar',
        'waktu' => $waktu,
        'petugas_id' => $_SESSION['user_id'] ?? null,
        'penerima' => $penerima['nama'],
        'penerima_id' => (int) $penerima['id'],
        'catatan' => $catatan,
        'dicatat_pada' => date('Y-m-d H:i', strtotime(sekarang())),
    ]);
    $per_sumber = ['Studio' => 0, 'Rental' => 0, 'Milik Kru' => 0];
    foreach ($item as $baris) {
        $alokasi = $baris['alokasi'];
        db_tambah('detail_transaksi', ['transaksi_id' => $trx_id, 'alokasi_id' => (int) $alokasi['id'], 'kondisi' => 'Baik', 'jumlah' => $baris['jumlah'], 'sumber' => $alokasi['sumber']]);
        $per_sumber[$alokasi['sumber']] += $baris['jumlah'];
    }
    $total = array_sum($per_sumber);
    catat_riwayat('Barang Keluar', $proyek['kode'] . ' · ' . $kode, $total . ' unit diserahkan ke ' . $penerima['nama'] . ' (' . teks_per_sumber($per_sumber) . ')');
    return ['kode' => $kode, 'total' => $total, 'per_sumber' => $per_sumber];
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
        db_tambah('detail_transaksi', ['transaksi_id' => $trx_id, 'alokasi_id' => (int) $alokasi_id, 'kondisi' => $kondisi, 'jumlah' => 1, 'sumber' => 'Studio']);
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
                $hasil[$awal][$trx['jenis']] += jumlah_detail($detail);
                break;
            }
        }
    }
    return $hasil;
}
