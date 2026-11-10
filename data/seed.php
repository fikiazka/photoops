<?php
defined('ROOT') || exit;

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
    $sandi = '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S';

    return [
        'pengguna' => seed_tabel(
            ['id', 'nama', 'username', 'email', 'password', 'peran', 'jenis_anggota', 'telepon', 'status'],
            [
                [1, 'Fiki Azka', 'fiki.cantik', 'fiki@niciostudio.id', $sandi, 'sa', 'Internal', '0812-3456-7801', 'Aktif'],
                [2, 'Budi Santoso', 'budi.santoso', 'budi@niciostudio.id', $sandi, 'pi', 'Internal', '0812-3456-7802', 'Aktif'],
                [3, 'Rizky Pratama', 'rizky.p', 'rizky.pratama@gmail.com', $sandi, 'us', 'Freelancer', '0813-2200-1144', 'Aktif'],
                [4, 'Sari Dewi', 'sari.dewi', 'sari@niciostudio.id', $sandi, 'us', 'Internal', '0813-2200-1145', 'Aktif'],
                [5, 'Bagas Saputra', 'bagas.s', 'bagas@niciostudio.id', $sandi, 'us', 'Internal', '0813-2200-1146', 'Aktif'],
                [6, 'Dimas Arya', 'dimas.a', 'dimas.arya@gmail.com', $sandi, 'us', 'Freelancer', '0857-1100-9921', 'Aktif'],
                [7, 'Nina Kartika', 'nina.k', 'nina@niciostudio.id', $sandi, 'us', 'Internal', '0813-2200-1147', 'Aktif'],
            ]
        ),

        'kategori' => seed_tabel(
            ['id', 'nama', 'kode_awalan', 'deskripsi'],
            [
                [1, 'Kamera', 'CAM', 'Body kamera foto dan video'],
                [2, 'Lensa', 'LNS', 'Lensa semua mount'],
                [3, 'Pencahayaan', 'LGT', 'Lampu LED, flash, softbox'],
                [4, 'Aksesori', 'STD', 'Stand, tripod, gimbal'],
                [5, 'Penyimpanan', 'MEM', 'SD card, SSD, card reader'],
            ]
        ),

        'perlengkapan' => seed_tabel(
            ['id', 'kode_unit', 'nama', 'merek', 'kategori_id', 'kondisi', 'status_aktif', 'catatan'],
            [
                [1, 'CAM-001', 'Sony A7 III', 'Sony', 1, 'Baik', 'Aktif', ''],
                [2, 'CAM-002', 'Sony A7 III', 'Sony', 1, 'Baik', 'Aktif', ''],
                [3, 'CAM-003', 'Canon EOS R6', 'Canon', 1, 'Baik', 'Aktif', ''],
                [4, 'CAM-004', 'Sony A7 III', 'Sony', 1, 'Baik', 'Aktif', ''],
                [5, 'CAM-005', 'Fujifilm X-T4', 'Fujifilm', 1, 'Baik', 'Aktif', ''],
                [6, 'LNS-001', 'Sony FE 24–70mm', 'Sony', 2, 'Baik', 'Aktif', ''],
                [7, 'LNS-002', 'Sony FE 24–70mm', 'Sony', 2, 'Baik', 'Aktif', ''],
                [8, 'LNS-003', 'Sony FE 85mm f/1.8', 'Sony', 2, 'Baik', 'Aktif', ''],
                [9, 'LNS-004', 'Canon RF 24–105mm', 'Canon', 2, 'Baik', 'Aktif', ''],
                [10, 'LNS-005', 'Sigma 35mm f/1.4 DG DN', 'Sigma', 2, 'Baik', 'Aktif', ''],
                [11, 'LGT-001', 'Godox SL60W', 'Godox', 3, 'Maintenance', 'Aktif', 'Kipas pendingin diservis'],
                [12, 'LGT-002', 'Godox SL60W', 'Godox', 3, 'Baik', 'Aktif', ''],
                [13, 'LGT-003', 'Aputure Amaran 100d', 'Aputure', 3, 'Baik', 'Aktif', ''],
                [14, 'STD-001', 'Light Stand 2.8m', 'Takara', 4, 'Rusak', 'Aktif', 'Kaki patah'],
                [15, 'STD-002', 'Light Stand 2.8m', 'Takara', 4, 'Baik', 'Aktif', ''],
                [16, 'STD-003', 'Light Stand 2.8m', 'Takara', 4, 'Baik', 'Aktif', ''],
                [17, 'STD-004', 'Light Stand 2.8m', 'Takara', 4, 'Baik', 'Aktif', ''],
                [18, 'STD-005', 'Tripod Manfrotto 190X', 'Manfrotto', 4, 'Baik', 'Aktif', ''],
                [19, 'STD-006', 'Gimbal DJI RS3 Mini', 'DJI', 4, 'Baik', 'Aktif', ''],
                [20, 'MEM-001', 'SD Card 128GB', 'SanDisk', 5, 'Baik', 'Aktif', ''],
                [21, 'MEM-002', 'SD Card 128GB', 'SanDisk', 5, 'Baik', 'Aktif', ''],
                [22, 'MEM-003', 'SD Card 128GB', 'SanDisk', 5, 'Baik', 'Aktif', ''],
                [23, 'MEM-004', 'SD Card 128GB', 'SanDisk', 5, 'Baik', 'Aktif', ''],
            ]
        ),

        'proyek' => seed_tabel(
            ['id', 'kode', 'nama', 'klien', 'lokasi', 'mulai', 'selesai', 'deadline_persiapan', 'status', 'pic_id', 'catatan', 'dibuat_oleh'],
            [
                [1, 'PRJ-019', 'Wisuda Kampus Brawijaya', 'Universitas Brawijaya', 'Malang', '2026-10-24 07:00', '2026-10-24 17:00', '2026-10-23 20:00', 'Selesai', 4, 'Dokumentasi prosesi dan foto keluarga.', 1],
                [2, 'PRJ-020', 'Dokumentasi Konser Senandika', 'Senandika Live', 'Malang', '2026-11-01 15:00', '2026-11-02 23:00', '2026-10-31 20:00', 'Berlangsung', 5, 'Dua hari konser, butuh video multicam.', 1],
                [3, 'PRJ-021', 'Wedding Arunika & Dimas', 'Arunika Putri', 'Batu, Malang', '2026-11-07 08:00', '2026-11-08 22:00', '2026-11-06 20:00', 'Dijadwalkan', 3, 'Akad pagi di rumah mempelai, resepsi malam di hotel. Butuh 2 tim kamera.', 1],
                [4, 'PRJ-022', 'Prewedding Laras', 'Laras Wulandari', 'Coban Rondo', '2026-11-12 05:30', '2026-11-12 13:00', '2026-11-11 20:00', 'Dijadwalkan', 4, 'Sesi sunrise, satu tim kecil.', 1],
                [5, 'PRJ-023', 'Company Profile PT Sinar', 'PT Sinar Abadi', 'Surabaya', '2026-11-15 08:00', '2026-11-16 17:00', '2026-11-14 20:00', 'Draf', 5, 'Video korporat dan foto tim manajemen.', 1],
                [6, 'PRJ-024', 'Ulang Tahun Kirana', 'Keluarga Wijaya', 'Kota Batu', '2026-11-14 16:00', '2026-11-14 21:00', '2026-11-13 20:00', 'Dibatalkan', 7, 'Dibatalkan klien karena acara diundur.', 1],
                [7, 'PRJ-025', 'Lamaran Nadia & Fajar', 'Nadia Salsabila', 'Malang', '2026-11-06 09:00', '2026-11-07 14:00', '2026-11-05 20:00', 'Berlangsung', 6, 'Lamaran dua sesi, butuh lighting indoor.', 1],
                [8, 'PRJ-016', 'Foto Produk Kedai Senja', 'Kedai Senja', 'Malang', '2026-10-05 09:00', '2026-10-05 16:00', '2026-10-04 20:00', 'Selesai', 7, 'Katalog menu musim hujan.', 1],
                [9, 'PRJ-017', 'Seminar Nasional Teknologi', 'Himpunan Mahasiswa TI', 'Malang', '2026-10-10 07:00', '2026-10-11 16:00', '2026-10-09 20:00', 'Selesai', 5, 'Dua hari seminar, dokumentasi panggung dan peserta.', 1],
                [10, 'PRJ-018', 'Engagement Rara & Bima', 'Rara Anindita', 'Kota Batu', '2026-10-17 15:00', '2026-10-17 21:00', '2026-10-16 20:00', 'Selesai', 4, 'Sesi sore sampai malam di villa.', 1],
            ]
        ),

        'anggota_proyek' => seed_tabel(
            ['id', 'proyek_id', 'pengguna_id', 'peran'],
            [
                [1, 1, 4, 'PIC'], [2, 1, 3, 'Kru'],
                [3, 2, 5, 'PIC'], [4, 2, 7, 'Kru'],
                [5, 3, 3, 'PIC'], [6, 3, 4, 'Kru'], [7, 3, 6, 'Kru'],
                [8, 4, 4, 'PIC'], [9, 4, 3, 'Kru'],
                [10, 5, 5, 'PIC'], [11, 5, 3, 'Kru'],
                [12, 6, 7, 'PIC'],
                [13, 7, 6, 'PIC'], [14, 7, 7, 'Kru'],
                [15, 8, 7, 'PIC'], [16, 8, 3, 'Kru'],
                [17, 9, 5, 'PIC'], [18, 9, 6, 'Kru'],
                [19, 10, 4, 'PIC'], [20, 10, 3, 'Kru'],
            ]
        ),

        'preset' => seed_tabel(
            ['id', 'nama', 'deskripsi', 'dibuat_oleh', 'diubah_pada'],
            [
                [1, 'Wedding Basic', 'Akad pagi + resepsi malam, 2 tim kamera.', 1, '2026-10-28 14:10'],
                [2, 'Prewedding Outdoor', 'Sesi luar ruang, 1 tim.', 2, '2026-10-12 10:00'],
                [3, 'Event / Konser', 'Dokumentasi panggung multicam.', 1, '2026-10-20 09:30'],
                [4, 'Company Profile', 'Video korporat dan foto tim.', 2, '2026-10-05 13:45'],
            ]
        ),

        'detail_preset' => seed_tabel(
            ['id', 'preset_id', 'nama_perlengkapan', 'kategori_id', 'jumlah'],
            [
                [1, 1, 'Sony A7 III', 1, 2], [2, 1, 'Sony FE 24–70mm', 2, 2], [3, 1, 'Godox SL60W', 3, 2], [4, 1, 'Light Stand 2.8m', 4, 2], [5, 1, 'SD Card 128GB', 5, 1],
                [6, 2, 'Sony A7 III', 1, 1], [7, 2, 'Sony FE 85mm f/1.8', 2, 1], [8, 2, 'Gimbal DJI RS3 Mini', 4, 1], [9, 2, 'SD Card 128GB', 5, 2],
                [10, 3, 'Sony A7 III', 1, 2], [11, 3, 'Canon EOS R6', 1, 1], [12, 3, 'Sony FE 24–70mm', 2, 2], [13, 3, 'Sigma 35mm f/1.4 DG DN', 2, 1], [14, 3, 'Godox SL60W', 3, 2], [15, 3, 'SD Card 128GB', 5, 3],
                [16, 4, 'Sony A7 III', 1, 2], [17, 4, 'Sony FE 24–70mm', 2, 1], [18, 4, 'Aputure Amaran 100d', 3, 1], [19, 4, 'Tripod Manfrotto 190X', 4, 1], [20, 4, 'SD Card 128GB', 5, 2], [21, 4, 'Sony FX3', 1, 1],
            ]
        ),

        'kebutuhan' => seed_tabel(
            ['id', 'proyek_id', 'nama', 'acuan', 'kategori_id', 'jumlah'],
            [
                [1, 1, 'Kamera Mirrorless', 'Sony A7 III', 1, 2],
                [2, 1, 'Lensa 24–70mm', 'Sony FE 24–70mm', 2, 1],
                [3, 1, 'Light Stand', 'Light Stand 2.8m', 4, 2],
                [4, 1, 'Tripod', '', 4, 1],
                [5, 2, 'Kamera Video', '', 1, 3],
                [6, 2, 'Lighting LED', 'Godox SL60W', 3, 2],
                [7, 2, 'Gimbal', '', 4, 1],
                [8, 3, 'Kamera Mirrorless', 'Sony A7 III', 1, 3],
                [9, 3, 'Lensa 24–70mm', 'Sony FE 24–70mm', 2, 2],
                [10, 3, 'Lighting LED', 'Godox SL60W', 3, 1],
                [11, 3, 'SD Card 128GB', '', 5, 1],
                [12, 4, 'Kamera Mirrorless', '', 1, 1],
                [13, 4, 'Lensa Portrait', 'Sony FE 85mm f/1.8', 2, 1],
                [14, 4, 'Gimbal', 'Gimbal DJI RS3 Mini', 4, 1],
                [15, 4, 'SD Card 128GB', 'SD Card 128GB', 5, 2],
                [16, 7, 'Lighting LED', '', 3, 2],
                [17, 7, 'Kamera', '', 1, 1],
                [18, 8, 'Kamera Mirrorless', 'Sony A7 III', 1, 1],
                [19, 8, 'Lensa Fix', 'Sigma 35mm f/1.4 DG DN', 2, 1],
                [20, 8, 'Lighting LED', 'Aputure Amaran 100d', 3, 1],
                [21, 9, 'Kamera', '', 1, 2],
                [22, 9, 'Tripod', 'Tripod Manfrotto 190X', 4, 1],
                [23, 9, 'SD Card 128GB', 'SD Card 128GB', 5, 2],
                [24, 10, 'Kamera Mirrorless', 'Sony A7 III', 1, 1],
                [25, 10, 'Lensa Portrait', 'Sony FE 85mm f/1.8', 2, 1],
                [26, 10, 'Gimbal', 'Gimbal DJI RS3 Mini', 4, 1],
                [27, 5, 'Kamera Sinema', 'Sony FX3', 1, 1],
                [28, 5, 'Lighting LED', 'Aputure Amaran 100d', 3, 1],
            ]
        ),

        'alokasi' => seed_tabel(
            ['id', 'proyek_id', 'kebutuhan_id', 'sumber', 'perlengkapan_id', 'nama_barang', 'vendor', 'pemilik_id', 'tgl_mulai', 'tgl_selesai', 'status', 'catatan'],
            [
                [1, 1, 1, 'Studio', 1, '', '', null, '2026-10-23', '2026-10-24', 'Dikonfirmasi', ''],
                [2, 1, 1, 'Studio', 2, '', '', null, '2026-10-23', '2026-10-24', 'Dikonfirmasi', ''],
                [3, 1, 2, 'Studio', 6, '', '', null, '2026-10-23', '2026-10-24', 'Dikonfirmasi', ''],
                [4, 1, 3, 'Studio', 14, '', '', null, '2026-10-23', '2026-10-24', 'Dikonfirmasi', ''],
                [5, 1, 3, 'Studio', 15, '', '', null, '2026-10-23', '2026-10-24', 'Dikonfirmasi', ''],
                [6, 1, 4, 'Milik Kru', null, 'Tripod Manfrotto', '', 4, '2026-10-24', '2026-10-24', 'Dikonfirmasi', ''],
                [7, 2, 5, 'Studio', 1, '', '', null, '2026-10-31', '2026-11-02', 'Dikonfirmasi', ''],
                [8, 2, 5, 'Studio', 3, '', '', null, '2026-10-31', '2026-11-02', 'Dikonfirmasi', ''],
                [9, 2, 5, 'Rental', null, 'Sony FX3', 'Rental Kamera Malang', null, '2026-10-31', '2026-11-02', 'Dikonfirmasi', 'Masa sewa diperpanjang sampai 9 Nov'],
                [10, 2, 6, 'Studio', 11, '', '', null, '2026-10-31', '2026-11-02', 'Dikonfirmasi', ''],
                [11, 2, 6, 'Studio', 12, '', '', null, '2026-10-31', '2026-11-02', 'Dikonfirmasi', ''],
                [12, 2, 7, 'Milik Kru', null, 'Gimbal DJI RS3', '', 5, '2026-11-01', '2026-11-02', 'Dikonfirmasi', ''],
                [13, 3, 8, 'Studio', 1, '', '', null, '2026-11-06', '2026-11-08', 'Dikonfirmasi', ''],
                [14, 3, 8, 'Studio', 2, '', '', null, '2026-11-06', '2026-11-08', 'Dikonfirmasi', ''],
                [15, 3, 8, 'Rental', null, 'Sony FX3', 'Rental Kamera Malang', null, '2026-11-06', '2026-11-08', 'Dikonfirmasi', 'Diantar vendor pagi hari'],
                [16, 3, 9, 'Studio', 6, '', '', null, '2026-11-06', '2026-11-08', 'Dikonfirmasi', ''],
                [17, 3, 9, 'Studio', 7, '', '', null, '2026-11-06', '2026-11-08', 'Dikonfirmasi', ''],
                [18, 3, 10, 'Studio', 11, '', '', null, '2026-11-06', '2026-11-08', 'Dibatalkan', 'Unit masuk maintenance'],
                [19, 3, 11, 'Milik Kru', null, 'SD Card 128GB ×2', '', 3, '2026-11-07', '2026-11-08', 'Dikonfirmasi', 'Dibawa langsung oleh Rizky'],
                [20, 4, 12, 'Studio', 3, '', '', null, '2026-11-11', '2026-11-12', 'Dikonfirmasi', ''],
                [21, 4, 13, 'Studio', 8, '', '', null, '2026-11-11', '2026-11-12', 'Dikonfirmasi', ''],
                [22, 4, 14, 'Studio', 19, '', '', null, '2026-11-11', '2026-11-12', 'Dikonfirmasi', ''],
                [23, 4, 15, 'Studio', 20, '', '', null, '2026-11-11', '2026-11-12', 'Dikonfirmasi', ''],
                [24, 4, 15, 'Studio', 21, '', '', null, '2026-11-11', '2026-11-12', 'Dikonfirmasi', ''],
                [25, 7, 16, 'Studio', 12, '', '', null, '2026-11-06', '2026-11-07', 'Dikonfirmasi', ''],
                [26, 7, 16, 'Studio', 13, '', '', null, '2026-11-06', '2026-11-07', 'Dikonfirmasi', ''],
                [27, 7, 17, 'Studio', 5, '', '', null, '2026-11-06', '2026-11-07', 'Dikonfirmasi', ''],
                [28, 8, 18, 'Studio', 4, '', '', null, '2026-10-04', '2026-10-05', 'Dikonfirmasi', ''],
                [29, 8, 19, 'Studio', 10, '', '', null, '2026-10-04', '2026-10-05', 'Dikonfirmasi', ''],
                [30, 8, 20, 'Studio', 13, '', '', null, '2026-10-04', '2026-10-05', 'Dikonfirmasi', ''],
                [31, 9, 21, 'Studio', 1, '', '', null, '2026-10-09', '2026-10-11', 'Dikonfirmasi', ''],
                [32, 9, 21, 'Studio', 2, '', '', null, '2026-10-09', '2026-10-11', 'Dikonfirmasi', ''],
                [33, 9, 22, 'Studio', 18, '', '', null, '2026-10-09', '2026-10-11', 'Dikonfirmasi', ''],
                [34, 9, 23, 'Studio', 20, '', '', null, '2026-10-09', '2026-10-11', 'Dikonfirmasi', ''],
                [35, 9, 23, 'Studio', 21, '', '', null, '2026-10-09', '2026-10-11', 'Dikonfirmasi', ''],
                [36, 10, 24, 'Studio', 4, '', '', null, '2026-10-16', '2026-10-17', 'Dikonfirmasi', ''],
                [37, 10, 25, 'Studio', 8, '', '', null, '2026-10-16', '2026-10-17', 'Dikonfirmasi', ''],
                [38, 10, 26, 'Studio', 19, '', '', null, '2026-10-16', '2026-10-17', 'Dikonfirmasi', ''],
                [39, 5, 27, 'Rental', null, 'Sony FX3', 'Rental Kamera Malang', null, '2026-11-14', '2026-11-16', 'Direncanakan', 'Menunggu konfirmasi ketersediaan dari vendor'],
                [40, 5, 28, 'Studio', 13, '', '', null, '2026-11-14', '2026-11-16', 'Direncanakan', ''],
            ]
        ),

        'persiapan' => seed_tabel(
            ['id', 'proyek_id', 'diperiksa', 'diperbarui_pada', 'diperbarui_oleh'],
            [
                [1, 1, [1, 2, 3, 4, 5, 6], '2026-10-23 18:30', 2],
                [2, 2, [7, 8, 9, 10, 11, 12], '2026-10-31 19:10', 2],
                [3, 3, [13, 14, 16, 17], '2026-11-06 19:40', 2],
                [4, 4, [21, 22], '2026-11-06 10:15', 2],
                [5, 5, [], null, null],
                [6, 7, [25, 26, 27], '2026-11-05 19:20', 2],
                [7, 8, [28, 29, 30], '2026-10-04 17:00', 2],
                [8, 9, [31, 32, 33, 34, 35], '2026-10-09 19:00', 2],
                [9, 10, [36, 37, 38], '2026-10-16 18:45', 2],
            ]
        ),

        'transaksi' => seed_tabel(
            ['id', 'kode', 'proyek_id', 'jenis', 'waktu', 'petugas_id', 'penerima', 'catatan'],
            [
                [1, 'TRX-038', 1, 'Keluar', '2026-10-24 05:30', 2, 'Sari Dewi', ''],
                [2, 'TRX-039', 1, 'Kembali', '2026-10-24 19:10', 2, 'Sari Dewi', 'STD-001 kembali dalam keadaan patah'],
                [3, 'TRX-041', 2, 'Keluar', '2026-11-01 07:00', 2, 'Bagas Saputra', ''],
                [4, 'TRX-042', 2, 'Kembali', '2026-11-02 23:30', 2, 'Bagas Saputra', 'CAM-003 belum kembali, dicari panitia'],
                [5, 'TRX-043', 7, 'Keluar', '2026-11-06 06:45', 2, 'Dimas Arya', ''],
                [6, 'TRX-030', 8, 'Keluar', '2026-10-05 07:30', 2, 'Nina Kartika', ''],
                [7, 'TRX-031', 8, 'Kembali', '2026-10-05 18:00', 2, 'Nina Kartika', ''],
                [8, 'TRX-033', 9, 'Keluar', '2026-10-10 06:00', 2, 'Bagas Saputra', ''],
                [9, 'TRX-034', 9, 'Kembali', '2026-10-13 10:00', 2, 'Bagas Saputra', 'Terlambat 2 hari, barang sempat tertahan di panitia'],
                [10, 'TRX-036', 10, 'Keluar', '2026-10-17 13:00', 2, 'Sari Dewi', ''],
                [11, 'TRX-037', 10, 'Kembali', '2026-10-17 22:30', 2, 'Sari Dewi', ''],
            ]
        ),

        'detail_transaksi' => seed_tabel(
            ['id', 'transaksi_id', 'alokasi_id', 'kondisi'],
            [
                [1, 1, 1, 'Baik'], [2, 1, 2, 'Baik'], [3, 1, 3, 'Baik'], [4, 1, 4, 'Baik'], [5, 1, 5, 'Baik'],
                [6, 2, 1, 'Baik'], [7, 2, 2, 'Baik'], [8, 2, 3, 'Baik'], [9, 2, 4, 'Rusak'], [10, 2, 5, 'Baik'],
                [11, 3, 7, 'Baik'], [12, 3, 8, 'Baik'], [13, 3, 10, 'Baik'], [14, 3, 11, 'Baik'],
                [15, 4, 7, 'Baik'], [16, 4, 10, 'Maintenance'], [17, 4, 11, 'Baik'],
                [18, 5, 25, 'Baik'], [19, 5, 26, 'Baik'], [20, 5, 27, 'Baik'],
                [21, 6, 28, 'Baik'], [22, 6, 29, 'Baik'], [23, 6, 30, 'Baik'],
                [24, 7, 28, 'Baik'], [25, 7, 29, 'Baik'], [26, 7, 30, 'Baik'],
                [27, 8, 31, 'Baik'], [28, 8, 32, 'Baik'], [29, 8, 33, 'Baik'], [30, 8, 34, 'Baik'], [31, 8, 35, 'Baik'],
                [32, 9, 31, 'Baik'], [33, 9, 32, 'Baik'], [34, 9, 33, 'Baik'], [35, 9, 34, 'Baik'], [36, 9, 35, 'Baik'],
                [37, 10, 36, 'Baik'], [38, 10, 37, 'Baik'], [39, 10, 38, 'Baik'],
                [40, 11, 36, 'Baik'], [41, 11, 37, 'Baik'], [42, 11, 38, 'Baik'],
            ]
        ),

        'insiden' => seed_tabel(
            ['id', 'kode', 'perlengkapan_id', 'proyek_id', 'jenis', 'waktu', 'deskripsi', 'status', 'pelapor_id'],
            [
                [1, 'INS-010', 10, 8, 'Masalah Teknis', '2026-10-05 18:05', 'Autofokus sempat macet, sudah dibersihkan kontaknya.', 'Selesai', 2],
                [2, 'INS-011', 14, 1, 'Rusak', '2026-10-24 19:15', 'Kaki stand patah saat dibongkar di lokasi.', 'Ditangani', 2],
                [3, 'INS-012', 11, 2, 'Masalah Teknis', '2026-11-02 23:35', 'Kipas pendingin berisik, lampu mati sendiri setelah 20 menit.', 'Ditangani', 2],
            ]
        ),

        'riwayat' => seed_tabel(
            ['id', 'waktu', 'pengguna_id', 'aksi', 'objek', 'keterangan', 'lama', 'baru'],
            [
                [1, '2026-10-05 07:30', 2, 'Barang Keluar', 'PRJ-016 · TRX-030', '3 unit diserahkan ke Nina Kartika', '', ''],
                [2, '2026-10-05 18:00', 2, 'Pengembalian', 'PRJ-016 · TRX-031', '3 unit kembali tepat waktu', '', ''],
                [3, '2026-10-10 06:00', 2, 'Barang Keluar', 'PRJ-017 · TRX-033', '5 unit diserahkan ke Bagas Saputra', '', ''],
                [4, '2026-10-13 10:00', 2, 'Pengembalian', 'PRJ-017 · TRX-034', '5 unit kembali, terlambat 2 hari', '', ''],
                [5, '2026-10-17 13:00', 2, 'Barang Keluar', 'PRJ-018 · TRX-036', '3 unit diserahkan ke Sari Dewi', '', ''],
                [6, '2026-10-17 22:30', 2, 'Pengembalian', 'PRJ-018 · TRX-037', '3 unit kembali tepat waktu', '', ''],
                [7, '2026-10-24 19:10', 2, 'Pengembalian', 'PRJ-019 · TRX-039', '5 unit kembali, STD-001 rusak', '', ''],
                [8, '2026-10-24 19:15', 2, 'Insiden', 'STD-001 · Light Stand 2.8m', 'Kaki stand patah saat dibongkar', 'Baik', 'Rusak'],
                [9, '2026-11-01 07:00', 2, 'Barang Keluar', 'PRJ-020 · TRX-041', '4 unit diserahkan ke Bagas Saputra', '', ''],
                [10, '2026-11-02 23:30', 2, 'Pengembalian', 'PRJ-020 · TRX-042', '3 dari 4 unit kembali, CAM-003 belum kembali', '', ''],
                [11, '2026-11-02 23:35', 2, 'Insiden', 'LGT-001 · Godox SL60W', 'Kipas pendingin berisik', 'Baik', 'Maintenance'],
                [12, '2026-11-03 10:42', 2, 'Alokasi', 'Sony FX3 · Rental Kamera Malang', 'Masa sewa PRJ-020 diperpanjang', '2 Nov 2026', '9 Nov 2026'],
                [13, '2026-11-05 09:15', 2, 'Alokasi', 'CAM-001 · PRJ-021', 'Dialokasikan ke Wedding Arunika & Dimas', '', ''],
                [14, '2026-11-05 09:20', 2, 'Alokasi', 'LGT-001 · PRJ-021', 'Alokasi dibatalkan, unit masuk maintenance', 'Dikonfirmasi', 'Dibatalkan'],
                [15, '2026-11-05 11:00', 2, 'Alokasi', 'SD Card 128GB ×2 · PRJ-021', 'Dicatat sebagai barang milik Rizky Pratama', '', ''],
                [16, '2026-11-06 06:45', 2, 'Barang Keluar', 'PRJ-025 · TRX-043', '3 unit diserahkan ke Dimas Arya', '', ''],
                [17, '2026-11-06 10:15', 2, 'Persiapan', 'PRJ-022', '2 dari 5 barang diperiksa', '', ''],
                [18, '2026-11-06 16:20', 1, 'Proyek', 'PRJ-023 · Company Profile PT Sinar', 'Proyek baru dibuat', '', ''],
                [19, '2026-11-06 19:40', 2, 'Persiapan', 'PRJ-021', '4 dari 6 barang diperiksa', '', ''],
            ]
        ),
    ];
}
