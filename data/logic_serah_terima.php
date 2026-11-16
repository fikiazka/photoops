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

// Sprint 3E final: tidak ada lagi status "Terlambat/Hari ini" untuk barang proyek. Tanggal selesai event (tgl_selesai)
// hanya informasi jadwal, bukan batas wajib pengembalian. Status barang = saldo transaksi (keluar - akumulasi kembali).

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
        $hasil[] = ['alokasi' => $alokasi, 'unit' => db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']), 'keluar' => serah_alokasi($alokasi['id'])['keluar']];
    }
    usort($hasil, fn ($a, $b) => strcmp($a['alokasi']['tgl_selesai'], $b['alokasi']['tgl_selesai']));
    return $hasil;
}

function barang_di_luar(?int $proyek_id = null): array
{
    // Sprint 3C: 'jumlah' = saldo yang masih di proyek (keluar - akumulasi kembali).
    $hasil = [];
    $transaksi = db('transaksi');
    foreach (db('alokasi') as $alokasi) {
        if (!sedang_keluar($alokasi) || ($proyek_id !== null && (int) $alokasi['proyek_id'] !== $proyek_id)) {
            continue;
        }
        $serah = serah_alokasi((int) $alokasi['id']);
        $keluar = $serah['jumlah_keluar'] ?? jumlah_alokasi($alokasi);
        $kembali = $serah['jumlah_kembali'] ?? 0;
        $hasil[] = [
            'alokasi' => $alokasi,
            'unit' => $alokasi['sumber'] === 'Studio' ? db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']) : null,
            'jumlah' => max(0, $keluar - $kembali),
            'jumlah_keluar' => $keluar,
            'jumlah_kembali' => $kembali,
            'status_kembali' => $serah['status_kembali'] ?? 'Belum Kembali',
            'kondisi_rincian' => $serah['kondisi_rincian'] ?? [],
            'kembali_terakhir' => $serah['kembali_terakhir'] ?? null,
            'trx_keluar' => $transaksi[$serah['trx_keluar']] ?? null,
            'keluar' => $serah['keluar'],
        ];
    }
    usort($hasil, fn ($a, $b) => strcmp($a['alokasi']['tgl_selesai'], $b['alokasi']['tgl_selesai']));
    return $hasil;
}

function ringkasan_barang_proyek(?int $proyek_id = null): array
{
    // Sprint 3D: KPI "barang di proyek" semua sumber, dari saldo transaksi (keluar - kembali), bukan banyaknya baris.
    // Sprint 3E final: tiga denominasi dipisah — 'unit' = kuantitas semua sumber, 'unit_studio' = unit fisik Studio
    // (alokasi Studio selalu 1 unit dan satu unit hanya bisa keluar di satu proyek), 'alokasi' = baris alokasi yang saldonya > 0.
    $luar = barang_di_luar($proyek_id);
    $pernah_keluar = false;
    if ($proyek_id !== null) {
        foreach (alokasi_proyek($proyek_id, true) as $a) {
            if (isset(serah_alokasi((int) $a['id'])['keluar'])) {
                $pernah_keluar = true;
                break;
            }
        }
    }
    $per_sumber = hitung_per_sumber($luar);
    return [
        'daftar' => $luar,
        'unit' => array_sum($per_sumber),
        'per_sumber' => $per_sumber,
        'unit_studio' => $per_sumber['Studio'],
        'alokasi' => count($luar),
        'pernah_keluar' => $pernah_keluar,
    ];
}

function teks_kondisi(array $rincian): string
{
    $bagian = [];
    foreach (daftar_kondisi() as $kondisi) {
        if (!empty($rincian[$kondisi])) {
            $bagian[] = $rincian[$kondisi] . ' ' . $kondisi;
        }
    }
    return implode(', ', $bagian);
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

function proses_pengembalian(array $proyek, array $item, ?array $penyerah, string $waktu, string $catatan): array
{
    // Sprint 3C: satu transaksi Kembali; satu detail per alokasi per kondisi, merujuk transaksi Keluar asal (trx_asal).
    $kode = kode_transaksi_baru();
    $trx_id = db_tambah('transaksi', [
        'kode' => $kode,
        'proyek_id' => $proyek['id'],
        'jenis' => 'Kembali',
        'waktu' => $waktu,
        'petugas_id' => $_SESSION['user_id'] ?? null,
        'penerima' => nama_pengguna($_SESSION['user_id'] ?? null),
        'penyerah' => $penyerah['nama'] ?? '',
        'penyerah_id' => $penyerah ? (int) $penyerah['id'] : null,
        'catatan' => $catatan,
        'dicatat_pada' => date('Y-m-d H:i', strtotime(sekarang())),
    ]);
    $per_sumber = ['Studio' => 0, 'Rental' => 0, 'Milik Kru' => 0];
    $per_kondisi = ['Baik' => 0, 'Maintenance' => 0, 'Rusak' => 0];
    $bermasalah = [];
    $eksternal_bermasalah = [];
    $insiden = [];
    foreach ($item as $baris) {
        $alokasi = $baris['alokasi'];
        foreach ($baris['per_kondisi'] as $kondisi => $jumlah) {
            if ($jumlah < 1) {
                continue;
            }
            $detail_id = db_tambah('detail_transaksi', [
                'transaksi_id' => $trx_id,
                'alokasi_id' => (int) $alokasi['id'],
                'kondisi' => $kondisi,
                'jumlah' => $jumlah,
                'sumber' => $alokasi['sumber'],
                'trx_asal' => (int) $baris['trx_asal'],
            ]);
            $per_sumber[$alokasi['sumber']] += $jumlah;
            $per_kondisi[$kondisi] += $jumlah;
            if ($kondisi === 'Baik') {
                continue;
            }
            if ($alokasi['sumber'] === 'Studio') {
                $unit = db_cari('perlengkapan', (int) $alokasi['perlengkapan_id']);
                $deskripsi = ($catatan !== '' ? $catatan . ' · ' : '') . 'Dilaporkan saat pengembalian ' . $kode . ' (barang keluar ' . (db_cari('transaksi', (int) $baris['trx_asal'])['kode'] ?? '?') . ').';
                $kode_ins = lapor_insiden((int) $alokasi['perlengkapan_id'], (int) $proyek['id'], $kondisi === 'Rusak' ? 'Rusak' : 'Masalah Teknis', $deskripsi, $kondisi);
                foreach (db('insiden') as $ins) {
                    if ($ins['kode'] === $kode_ins) {
                        db_ubah('insiden', (int) $ins['id'], ['transaksi_id' => $trx_id, 'alokasi_id' => (int) $alokasi['id'], 'detail_id' => $detail_id]);
                    }
                }
                $bermasalah[] = $unit['kode_unit'] ?? '?';
                $insiden[] = $kode_ins;
            } else {
                $eksternal_bermasalah[] = $alokasi['nama_barang'] . ' ' . $jumlah . ' ' . $kondisi;
            }
        }
    }
    $total = array_sum($per_sumber);
    // Sprint 3E final: tidak ada penilaian terlambat/tepat waktu berdasarkan jadwal event (jadwal bersifat dinamis).
    // Yang dicatat hanya fakta: waktu pengembalian dan saldo yang masih di proyek setelah transaksi ini.
    $sisa_proyek = array_sum(array_column(barang_di_luar((int) $proyek['id']), 'jumlah'));
    $ket = $total . ' unit kembali (' . teks_per_sumber($per_sumber) . ') · kondisi ' . teks_kondisi($per_kondisi);
    $ket .= ' · waktu kembali ' . tgl($waktu, 'lengkap');
    $ket .= $sisa_proyek > 0 ? ' · sisa di proyek ' . $sisa_proyek . ' unit' : ' · seluruh barang proyek sudah kembali';
    $ket .= $penyerah ? ' · diserahkan ' . $penyerah['nama'] : '';
    $ket .= $insiden ? ' · insiden ' . implode(', ', $insiden) : '';
    $ket .= $eksternal_bermasalah ? ' · eksternal bermasalah: ' . implode(', ', $eksternal_bermasalah) : '';
    catat_riwayat('Pengembalian', $proyek['kode'] . ' · ' . $kode, $ket);
    return ['kode' => $kode, 'total' => $total, 'per_sumber' => $per_sumber, 'per_kondisi' => $per_kondisi, 'sisa_proyek' => $sisa_proyek, 'bermasalah' => $bermasalah, 'eksternal_bermasalah' => $eksternal_bermasalah, 'insiden' => $insiden];
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
