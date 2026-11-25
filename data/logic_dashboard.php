<?php
defined('ROOT') || exit;

// ---------------------------------------------------------------------------------------------
// Data dashboard tiga peran. Semua angka dibaca dari PostgreSQL lewat fungsi modul (semua_*(), cache per request).
// Definisi yang dipakai di seluruh dashboard:
//   - Proyek aktif       = status Dijadwalkan atau Berlangsung (Draf belum dihitung aktif; Selesai/Dibatalkan tidak).
//   - Persiapan lewat    = persiapan_proyek()['lewat']: proyek belum ditutup, belum Siap, dan sekarang() > batas persiapan.
//   - Belum kembali      = saldo serah terima (keluar - kembali). Unit Studio dihitung per unit fisik; Rental/Milik Kru per kuantitas.
//                          Alokasi yang belum keluar TIDAK dihitung, dan tanggal selesai acara bukan tenggat pengembalian.
//   - Insiden terbuka    = laporan_insiden berstatus selain Selesai (Dilaporkan atau Ditangani).
// ---------------------------------------------------------------------------------------------

// Barang proyek ini sudah pernah tercatat keluar (persiapan/rental sudah lewat tahap pra-acara).
function proyek_pernah_keluar(int $proyek_id): bool
{
    foreach (alokasi_proyek($proyek_id, true) as $a) {
        if (isset(serah_alokasi((int) $a['id'])['keluar'])) {
            return true;
        }
    }
    return false;
}

function kpi_proyek(): array
{
    $hasil = ['aktif' => 0, 'berlangsung' => 0, 'dijadwalkan' => 0, 'draf' => 0, 'lewat' => 0];
    foreach (semua_proyek() as $p) {
        if ($p['status'] === 'Draf') {
            $hasil['draf']++;
        } elseif ($p['status'] === 'Berlangsung' || $p['status'] === 'Dijadwalkan') {
            $hasil['aktif']++;
            $hasil[$p['status'] === 'Berlangsung' ? 'berlangsung' : 'dijadwalkan']++;
        }
        if (persiapan_proyek($p)['lewat']) {
            $hasil['lewat']++;
        }
    }
    return $hasil;
}

function kpi_insiden(): array
{
    $hasil = ['terbuka' => 0, 'Dilaporkan' => 0, 'Ditangani' => 0];
    foreach (insiden_terbuka() as $i) {
        $hasil['terbuka']++;
        $hasil[$i['status']]++;
    }
    return $hasil;
}

// Kondisi fisik unit Studio aktif; unit Nonaktif dihitung terpisah (tidak dipakai lagi).
function kondisi_aset(): array
{
    $hasil = ['Baik' => 0, 'Maintenance' => 0, 'Rusak' => 0, 'nonaktif' => 0];
    foreach (semua_perlengkapan() as $u) {
        if ($u['status_aktif'] === 'Aktif') {
            $hasil[$u['kondisi']]++;
        } else {
            $hasil['nonaktif']++;
        }
    }
    return $hasil;
}

// Daftar "Perlu tindakan" (Super Admin dan Petugas Inventaris). Hanya masalah yang didukung data, urut paling mendesak:
// merah = sudah lewat batas atau menghalangi acara yang mulai <= 2 hari lagi; kuning = perlu ditindak sebelum batasnya.
// Tidak ada peringatan "terlambat dikembalikan" dari tanggal selesai acara (bukan tenggat pengembalian barang).
function masalah_operasional(): array
{
    $segera = date('Y-m-d', strtotime(HARI_INI . ' +2 days'));
    $dekat = date('Y-m-d', strtotime(HARI_INI . ' +14 days'));
    $batas_48jam = date('Y-m-d H:i', strtotime(sekarang() . ' +48 hours'));
    $hasil = [];
    $catat = function (bool $merah, string $ikon, string $judul, string $ket, string $tautan, string $urut) use (&$hasil) {
        $hasil[] = ['tingkat' => $merah ? 'merah' : 'kuning', 'ikon' => $ikon, 'judul' => $judul, 'keterangan' => $ket, 'tautan' => $tautan, 'urut' => $urut];
    };
    foreach (semua_proyek() as $proyek) {
        if (!proyek_aktif($proyek)) {
            continue;
        }
        $pid = (int) $proyek['id'];
        $mulai = substr($proyek['mulai'], 0, 10);
        $acara_segera = $mulai <= $segera;
        $label = $proyek['kode'] . ' · ' . $proyek['nama'];
        $detail = 'pages/proyek-detail.php?id=' . $pid;
        $persiapan = persiapan_proyek($proyek);
        $sudah_keluar = proyek_pernah_keluar($pid);
        $dikonfirmasi = !empty(rekam_persiapan($pid)['dikonfirmasi_pada']);

        if ($persiapan['lewat']) {
            $catat(true, 'list-checks', 'Persiapan lewat batas', $label . ' · ' . $persiapan['selesai'] . ' dari ' . $persiapan['total'] . ' barang diperiksa, batas '
                . tgl($proyek['deadline_persiapan'], 'singkat_jam'), $detail . '&tab=persiapan', $proyek['deadline_persiapan']);
        } elseif (!$dikonfirmasi && !$sudah_keluar && $persiapan['status'] !== 'Siap' && $proyek['deadline_persiapan'] <= $batas_48jam) {
            $catat(false, 'list-checks', 'Batas persiapan kurang dari 48 jam', $label . ' · ' . $persiapan['selesai'] . ' dari ' . $persiapan['total']
                . ' barang diperiksa, batas ' . tgl($proyek['deadline_persiapan'], 'singkat_jam'), $detail . '&tab=persiapan', $proyek['deadline_persiapan']);
        }
        $kurang = 0;
        foreach (kebutuhan_proyek($pid) as $kebutuhan) {
            $kurang += pemenuhan_kebutuhan($kebutuhan)['sisa'];
        }
        if ($kurang > 0) {
            $catat($acara_segera || $persiapan['lewat'], 'package', $kurang . ' unit kebutuhan belum dialokasikan', $label . ' · mulai ' . tgl($proyek['mulai'], 'singkat'),
                $detail . '&tab=kebutuhan', $mulai);
        }
        foreach (alokasi_proyek($pid, true) as $a) {
            if (isset(serah_alokasi((int) $a['id'])['keluar'])) {
                continue;
            }
            if ($a['sumber'] === 'Rental') {
                if ($a['status'] === 'Direncanakan') {
                    $catat($acara_segera, 'truck', 'Rental menunggu konfirmasi vendor', $a['nama_barang'] . ' · ' . $a['vendor'] . ' · ' . $proyek['kode'] . ' mulai ' . tgl($proyek['mulai'], 'singkat'),
                        $detail . '&tab=kebutuhan', $mulai);
                } elseif (empty($a['diterima_pada']) && $mulai <= $dekat) {
                    $catat($acara_segera || $persiapan['lewat'], 'truck', 'Rental belum diterima dari vendor', $a['nama_barang'] . ' · ' . $a['vendor'] . ' · ' . $proyek['kode']
                        . ' mulai ' . tgl($proyek['mulai'], 'singkat'), 'pages/perlengkapan.php?tab=eksternal', $mulai);
                }
                continue;
            }
            if ($a['sumber'] !== 'Studio') {
                continue;
            }
            $unit = cari_perlengkapan((int) $a['perlengkapan_id']);
            if (!$unit) {
                continue;
            }
            if ($unit['kondisi'] !== 'Baik' || $unit['status_aktif'] !== 'Aktif') {
                $catat($acara_segera, 'wrench', $unit['kode_unit'] . ' dialokasikan tetapi ' . ($unit['status_aktif'] !== 'Aktif' ? 'nonaktif' : 'berkondisi ' . $unit['kondisi']),
                    'Dibutuhkan ' . $proyek['kode'] . ' mulai ' . tgl($a['tgl_mulai'], 'singkat') . '. Ganti unit atau selesaikan perbaikannya.', $detail . '&tab=kebutuhan', $a['tgl_mulai']);
            }
            // Unit masih keluar di proyek lain yang jadwal acaranya sudah selesai, atau jadwalnya beririsan: fisiknya belum ada di studio.
            $keluar = alokasi_keluar_unit((int) $unit['id']);
            if ($keluar && (int) $keluar['proyek_id'] !== $pid && (HARI_INI > $keluar['tgl_selesai'] || $keluar['tgl_selesai'] >= $a['tgl_mulai'])) {
                $asal = cari_proyek($keluar['proyek_id']);
                $catat($a['tgl_mulai'] <= $segera, 'clock', $unit['kode_unit'] . ' belum kembali ke studio', 'Masih di ' . ($asal['kode'] ?? '?') . ', dibutuhkan '
                    . $proyek['kode'] . ' mulai ' . tgl($a['tgl_mulai'], 'singkat'), 'pages/serah-terima.php', $a['tgl_mulai']);
            }
        }
    }
    $belum_ditangani = kpi_insiden()['Dilaporkan'];
    if ($belum_ditangani > 0) {
        $catat(false, 'triangle-alert', $belum_ditangani . ' laporan insiden belum ditangani', 'Unit berstatus Dilaporkan; tandai Ditangani saat mulai diperbaiki.',
            'pages/perlengkapan.php?tab=insiden', '9999');
    }
    usort($hasil, fn ($a, $b) => [$a['tingkat'] !== 'merah', $a['urut'], $a['judul']] <=> [$b['tingkat'] !== 'merah', $b['urut'], $b['judul']]);
    return $hasil;
}

// Dashboard User/Kru: keterangan kesiapan barang proyek, sesuai status persiapan yang sebenarnya.
function teks_kesiapan_user(array $proyek, array $prs): string
{
    if (!empty(rekam_persiapan((int) $proyek['id'])['dikonfirmasi_pada'])) {
        return 'Persiapan barang sudah dikonfirmasi petugas.';
    }
    if ($prs['status'] === 'Siap') {
        return 'Semua barang sudah dicek petugas; menunggu konfirmasi persiapan.';
    }
    if ($prs['lewat']) {
        return 'Batas persiapan (' . tgl($proyek['deadline_persiapan'], 'singkat_jam') . ') sudah lewat dan barang belum siap semua. Hubungi Petugas Inventaris.';
    }
    return 'Petugas Inventaris masih menyiapkan barang. Batas ' . tgl($proyek['deadline_persiapan'], 'singkat_jam') . '.';
}

// Antrean kerja Petugas Inventaris.
function tugas_petugas(): array
{
    $siap = proyek_siap_keluar();
    $persiapan_terbuka = [];
    foreach (proyek_mendatang(14) as $p) {
        if (!proyek_pernah_keluar((int) $p['id']) && empty(rekam_persiapan((int) $p['id'])['dikonfirmasi_pada'])) {
            $persiapan_terbuka[] = $p;
        }
    }
    $rental = [];
    foreach (semua_alokasi() as $a) {
        if ($a['sumber'] !== 'Rental' || $a['status'] === 'Dibatalkan' || !empty($a['dikembalikan_vendor_pada'])) {
            continue;
        }
        $proyek = cari_proyek((int) $a['proyek_id']);
        $info = info_rental($a);
        $serah = serah_alokasi((int) $a['id']);
        if ($a['status'] === 'Direncanakan' && $proyek && proyek_aktif($proyek)) {
            $tindakan = 'Menunggu konfirmasi vendor';
        } elseif (!$info['diterima'] && $proyek && proyek_aktif($proyek)) {
            $tindakan = 'Catat penerimaan dari vendor';
        } elseif ($info['diterima'] && !sedang_keluar($a) && (isset($serah['kembali']) || !$proyek || !proyek_aktif($proyek))) {
            $tindakan = 'Kembalikan ke vendor';
        } else {
            continue;
        }
        $rental[] = ['alokasi' => $a, 'proyek' => $proyek, 'tindakan' => $tindakan, 'info' => $info];
    }
    usort($rental, fn ($a, $b) => strcmp($a['alokasi']['tgl_mulai'], $b['alokasi']['tgl_mulai']));
    $unit = [];
    $insiden_unit = [];
    foreach (insiden_terbuka() as $i) {
        $insiden_unit[(int) $i['perlengkapan_id']][] = $i['kode'] . ' ' . $i['status'];
    }
    foreach (semua_perlengkapan() as $u) {
        if ($u['status_aktif'] === 'Aktif' && $u['kondisi'] !== 'Baik') {
            $unit[] = ['unit' => $u, 'insiden' => $insiden_unit[(int) $u['id']] ?? []];
        }
    }
    return [
        'siap' => $siap,
        'siap_unit' => array_sum(array_column($siap, 'bisa_unit')),
        'siap_proyek' => count(array_filter($siap, fn ($s) => $s['bisa_unit'] > 0)),
        'persiapan_terbuka' => $persiapan_terbuka,
        'rental' => $rental,
        'unit' => $unit,
    ];
}
