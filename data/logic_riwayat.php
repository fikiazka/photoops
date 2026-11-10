<?php
defined('ROOT') || exit;

function catat_riwayat(string $aksi, string $objek, string $keterangan, string $lama = '', string $baru = ''): void
{
    db_tambah('riwayat', [
        'waktu' => date('Y-m-d H:i', strtotime(sekarang())),
        'pengguna_id' => $_SESSION['user_id'] ?? null,
        'aksi' => $aksi,
        'objek' => $objek,
        'keterangan' => $keterangan,
        'lama' => $lama,
        'baru' => $baru,
    ]);
}

function daftar_riwayat(): array
{
    $baris = array_values(db('riwayat'));
    usort($baris, fn ($a, $b) => strcmp($b['waktu'], $a['waktu']) ?: $b['id'] <=> $a['id']);
    return $baris;
}

function ikon_aksi(string $aksi): string
{
    $peta = [
        'Barang Keluar' => 'arrow-up-right',
        'Pengembalian' => 'arrow-down-left',
        'Insiden' => 'triangle-alert',
        'Alokasi' => 'package',
        'Persiapan' => 'list-checks',
        'Proyek' => 'calendar-days',
        'Kebutuhan' => 'list-checks',
        'Tim' => 'users',
        'Preset' => 'layers',
        'Perlengkapan' => 'camera',
        'Pengguna' => 'user',
    ];
    return $peta[$aksi] ?? 'info';
}
