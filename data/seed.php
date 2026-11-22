<?php
defined('ROOT') || exit;

// Data awal session untuk modul yang BELUM dimigrasikan ke PostgreSQL. Sejak Sprint Backend 4B hanya Insiden (laporan
// insiden dan log aktivitas Insiden). Data bisnis lain (pengguna, inventaris, proyek, preset, kebutuhan, alokasi,
// persiapan, serah terima) hanya ada di PostgreSQL. Id/kode di bawah sama dengan seed PostgreSQL (002_seed_demo.sql).

function seed_tabel(array $kolom, array $baris): array
{
    $hasil = [];
    foreach ($baris as $isi) {
        $data = array_combine($kolom, $isi);
        $hasil[$data['id']] = $data;
    }
    return $hasil;
}

function seed_data(): array
{
    return [
        'insiden' => seed_tabel(
            ['id', 'kode', 'perlengkapan_id', 'proyek_id', 'jenis', 'waktu', 'deskripsi', 'status', 'pelapor_id'],
            [
                [1, 'INS-010', 10, 8, 'Masalah Teknis', '2026-10-05 18:05', 'Autofokus sempat macet, sudah dibersihkan kontaknya.', 'Selesai', 2],
                [2, 'INS-011', 14, 1, 'Rusak', '2026-10-24 19:15', 'Kaki stand patah saat dibongkar di lokasi.', 'Ditangani', 2],
                [3, 'INS-012', 11, 2, 'Masalah Teknis', '2026-11-02 23:35', 'Kipas pendingin berisik, lampu mati sendiri setelah 20 menit.', 'Ditangani', 2],
            ]
        ),

        // Log aktivitas session hanya untuk aksi Insiden; aksi lain dibaca dari riwayat_aktivitas PostgreSQL.
        'riwayat' => seed_tabel(
            ['id', 'waktu', 'pengguna_id', 'aksi', 'objek', 'keterangan', 'lama', 'baru'],
            [
                [8, '2026-10-24 19:15', 2, 'Insiden', 'STD-001 · Light Stand 2.8m', 'Kaki stand patah saat dibongkar', 'Baik', 'Rusak'],
                [11, '2026-11-02 23:35', 2, 'Insiden', 'LGT-001 · Godox SL60W', 'Kipas pendingin berisik', 'Baik', 'Maintenance'],
            ]
        ),
    ];
}
