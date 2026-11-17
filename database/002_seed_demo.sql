-- PhotoOps Nicio Studio — data contoh untuk development/testing (bukan untuk produksi).
-- Jalankan setelah 001_schema.sql pada database yang tabelnya masih kosong:
--   psql -d photoops_si3_dev -v ON_ERROR_STOP=1 -f database/002_seed_demo.sql
-- Sumber: data prototipe PhotoOps (data/seed.php) + sedikit data dummy tambahan di bagian akhir.

SET client_encoding = 'UTF8';

BEGIN;

-- Akun demo; kata sandi semuanya nicio123 (hash bcrypt). Hanya untuk development/testing.
INSERT INTO pengguna (id, nama, username, email, password_hash, peran, jenis_anggota, telepon, status) VALUES
    (1, 'Fiki Azka', 'fiki.cantik', 'fiki@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'sa', 'Internal', '0812-3456-7801', 'Aktif'),
    (2, 'Budi Santoso', 'budi.santoso', 'budi@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'pi', 'Internal', '0812-3456-7802', 'Aktif'),
    (3, 'Rizky Pratama', 'rizky.p', 'rizky.pratama@gmail.com', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Freelancer', '0813-2200-1144', 'Aktif'),
    (4, 'Sari Dewi', 'sari.dewi', 'sari@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Internal', '0813-2200-1145', 'Aktif'),
    (5, 'Bagas Saputra', 'bagas.s', 'bagas@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Internal', '0813-2200-1146', 'Aktif'),
    (6, 'Dimas Arya', 'dimas.a', 'dimas.arya@gmail.com', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Freelancer', '0857-1100-9921', 'Aktif'),
    (7, 'Nina Kartika', 'nina.k', 'nina@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Internal', '0813-2200-1147', 'Aktif');

-- nomor_terakhir = nomor kode unit tertinggi per kategori.
INSERT INTO kategori_perlengkapan (id, nama, kode_awalan, deskripsi, nomor_terakhir) VALUES
    (1, 'Kamera', 'CAM', 'Body kamera foto dan video', 5),
    (2, 'Lensa', 'LNS', 'Lensa semua mount', 5),
    (3, 'Pencahayaan', 'LGT', 'Lampu LED, flash, softbox', 3),
    (4, 'Aksesori', 'STD', 'Stand, tripod, gimbal', 6),
    (5, 'Penyimpanan', 'MEM', 'SD card, SSD, card reader', 4);

INSERT INTO perlengkapan (id, kode_unit, nama, merek, kategori_id, kondisi, status_aktif, catatan) VALUES
    (1, 'CAM-001', 'Sony A7 III', 'Sony', 1, 'Baik', 'Aktif', ''),
    (2, 'CAM-002', 'Sony A7 III', 'Sony', 1, 'Baik', 'Aktif', ''),
    (3, 'CAM-003', 'Canon EOS R6', 'Canon', 1, 'Baik', 'Aktif', ''),
    (4, 'CAM-004', 'Sony A7 III', 'Sony', 1, 'Baik', 'Aktif', ''),
    (5, 'CAM-005', 'Fujifilm X-T4', 'Fujifilm', 1, 'Baik', 'Aktif', ''),
    (6, 'LNS-001', 'Sony FE 24–70mm', 'Sony', 2, 'Baik', 'Aktif', ''),
    (7, 'LNS-002', 'Sony FE 24–70mm', 'Sony', 2, 'Baik', 'Aktif', ''),
    (8, 'LNS-003', 'Sony FE 85mm f/1.8', 'Sony', 2, 'Baik', 'Aktif', ''),
    (9, 'LNS-004', 'Canon RF 24–105mm', 'Canon', 2, 'Baik', 'Aktif', ''),
    (10, 'LNS-005', 'Sigma 35mm f/1.4 DG DN', 'Sigma', 2, 'Baik', 'Aktif', ''),
    (11, 'LGT-001', 'Godox SL60W', 'Godox', 3, 'Maintenance', 'Aktif', 'Kipas pendingin diservis'),
    (12, 'LGT-002', 'Godox SL60W', 'Godox', 3, 'Baik', 'Aktif', ''),
    (13, 'LGT-003', 'Aputure Amaran 100d', 'Aputure', 3, 'Baik', 'Aktif', ''),
    (14, 'STD-001', 'Light Stand 2.8m', 'Takara', 4, 'Rusak', 'Aktif', 'Kaki patah'),
    (15, 'STD-002', 'Light Stand 2.8m', 'Takara', 4, 'Baik', 'Aktif', ''),
    (16, 'STD-003', 'Light Stand 2.8m', 'Takara', 4, 'Baik', 'Aktif', ''),
    (17, 'STD-004', 'Light Stand 2.8m', 'Takara', 4, 'Baik', 'Aktif', ''),
    (18, 'STD-005', 'Tripod Manfrotto 190X', 'Manfrotto', 4, 'Baik', 'Aktif', ''),
    (19, 'STD-006', 'Gimbal DJI RS3 Mini', 'DJI', 4, 'Baik', 'Aktif', ''),
    (20, 'MEM-001', 'SD Card 128GB', 'SanDisk', 5, 'Baik', 'Aktif', ''),
    (21, 'MEM-002', 'SD Card 128GB', 'SanDisk', 5, 'Baik', 'Aktif', ''),
    (22, 'MEM-003', 'SD Card 128GB', 'SanDisk', 5, 'Baik', 'Aktif', ''),
    (23, 'MEM-004', 'SD Card 128GB', 'SanDisk', 5, 'Baik', 'Aktif', '');

INSERT INTO proyek (id, kode, nama, klien, lokasi, mulai, selesai, deadline_persiapan, status, pic_id, catatan, dibuat_oleh) VALUES
    (1, 'PRJ-019', 'Wisuda Kampus Brawijaya', 'Universitas Brawijaya', 'Malang', '2026-10-24 07:00', '2026-10-24 17:00', '2026-10-23 20:00', 'Selesai', 4, 'Dokumentasi prosesi dan foto keluarga.', 1),
    (2, 'PRJ-020', 'Dokumentasi Konser Senandika', 'Senandika Live', 'Malang', '2026-11-01 15:00', '2026-11-02 23:00', '2026-10-31 20:00', 'Berlangsung', 5, 'Dua hari konser, butuh video multicam.', 1),
    (3, 'PRJ-021', 'Wedding Arunika & Dimas', 'Arunika Putri', 'Batu, Malang', '2026-11-07 08:00', '2026-11-08 22:00', '2026-11-06 20:00', 'Dijadwalkan', 3, 'Akad pagi di rumah mempelai, resepsi malam di hotel. Butuh 2 tim kamera.', 1),
    (4, 'PRJ-022', 'Prewedding Laras', 'Laras Wulandari', 'Coban Rondo', '2026-11-12 05:30', '2026-11-12 13:00', '2026-11-11 20:00', 'Dijadwalkan', 4, 'Sesi sunrise, satu tim kecil.', 1),
    (5, 'PRJ-023', 'Company Profile PT Sinar', 'PT Sinar Abadi', 'Surabaya', '2026-11-15 08:00', '2026-11-16 17:00', '2026-11-14 20:00', 'Draf', 5, 'Video korporat dan foto tim manajemen.', 1),
    (6, 'PRJ-024', 'Ulang Tahun Kirana', 'Keluarga Wijaya', 'Kota Batu', '2026-11-14 16:00', '2026-11-14 21:00', '2026-11-13 20:00', 'Dibatalkan', 7, 'Dibatalkan klien karena acara diundur.', 1),
    (7, 'PRJ-025', 'Lamaran Nadia & Fajar', 'Nadia Salsabila', 'Malang', '2026-11-06 09:00', '2026-11-07 14:00', '2026-11-05 20:00', 'Berlangsung', 6, 'Lamaran dua sesi, butuh lighting indoor.', 1),
    (8, 'PRJ-016', 'Foto Produk Kedai Senja', 'Kedai Senja', 'Malang', '2026-10-05 09:00', '2026-10-05 16:00', '2026-10-04 20:00', 'Selesai', 7, 'Katalog menu musim hujan.', 1),
    (9, 'PRJ-017', 'Seminar Nasional Teknologi', 'Himpunan Mahasiswa TI', 'Malang', '2026-10-10 07:00', '2026-10-11 16:00', '2026-10-09 20:00', 'Selesai', 5, 'Dua hari seminar, dokumentasi panggung dan peserta.', 1),
    (10, 'PRJ-018', 'Engagement Rara & Bima', 'Rara Anindita', 'Kota Batu', '2026-10-17 15:00', '2026-10-17 21:00', '2026-10-16 20:00', 'Selesai', 4, 'Sesi sore sampai malam di villa.', 1);

-- Hanya Kru; PIC ada di proyek.pic_id.
INSERT INTO anggota_proyek (id, proyek_id, pengguna_id) VALUES
    (2, 1, 3),
    (4, 2, 7),
    (6, 3, 4),
    (7, 3, 6),
    (9, 4, 3),
    (11, 5, 3),
    (14, 7, 7),
    (16, 8, 3),
    (18, 9, 6),
    (20, 10, 3);

INSERT INTO preset_perlengkapan (id, nama, deskripsi, dibuat_oleh, diubah_pada) VALUES
    (1, 'Wedding Basic', 'Akad pagi + resepsi malam, 2 tim kamera.', 1, '2026-10-28 14:10'),
    (2, 'Prewedding Outdoor', 'Sesi luar ruang, 1 tim.', 2, '2026-10-12 10:00'),
    (3, 'Event / Konser', 'Dokumentasi panggung multicam.', 1, '2026-10-20 09:30'),
    (4, 'Company Profile', 'Video korporat dan foto tim.', 2, '2026-10-05 13:45');

INSERT INTO detail_preset (id, preset_id, nama_perlengkapan, kategori_id, jumlah) VALUES
    (1, 1, 'Sony A7 III', 1, 2),
    (2, 1, 'Sony FE 24–70mm', 2, 2),
    (3, 1, 'Godox SL60W', 3, 2),
    (4, 1, 'Light Stand 2.8m', 4, 2),
    (5, 1, 'SD Card 128GB', 5, 1),
    (6, 2, 'Sony A7 III', 1, 1),
    (7, 2, 'Sony FE 85mm f/1.8', 2, 1),
    (8, 2, 'Gimbal DJI RS3 Mini', 4, 1),
    (9, 2, 'SD Card 128GB', 5, 2),
    (10, 3, 'Sony A7 III', 1, 2),
    (11, 3, 'Canon EOS R6', 1, 1),
    (12, 3, 'Sony FE 24–70mm', 2, 2),
    (13, 3, 'Sigma 35mm f/1.4 DG DN', 2, 1),
    (14, 3, 'Godox SL60W', 3, 2),
    (15, 3, 'SD Card 128GB', 5, 3),
    (16, 4, 'Sony A7 III', 1, 2),
    (17, 4, 'Sony FE 24–70mm', 2, 1),
    (18, 4, 'Aputure Amaran 100d', 3, 1),
    (19, 4, 'Tripod Manfrotto 190X', 4, 1),
    (20, 4, 'SD Card 128GB', 5, 2),
    (21, 4, 'Sony FX3', 1, 1);

INSERT INTO kebutuhan_proyek (id, proyek_id, nama, acuan, kategori_id, jumlah) VALUES
    (1, 1, 'Kamera Mirrorless', 'Sony A7 III', 1, 2),
    (2, 1, 'Lensa 24–70mm', 'Sony FE 24–70mm', 2, 1),
    (3, 1, 'Light Stand', 'Light Stand 2.8m', 4, 2),
    (4, 1, 'Tripod', '', 4, 1),
    (5, 2, 'Kamera Video', '', 1, 3),
    (6, 2, 'Lighting LED', 'Godox SL60W', 3, 2),
    (7, 2, 'Gimbal', '', 4, 1),
    (8, 3, 'Kamera Mirrorless', 'Sony A7 III', 1, 3),
    (9, 3, 'Lensa 24–70mm', 'Sony FE 24–70mm', 2, 2),
    (10, 3, 'Lighting LED', 'Godox SL60W', 3, 1),
    (11, 3, 'SD Card 128GB', '', 5, 1),
    (12, 4, 'Kamera Mirrorless', '', 1, 1),
    (13, 4, 'Lensa Portrait', 'Sony FE 85mm f/1.8', 2, 1),
    (14, 4, 'Gimbal', 'Gimbal DJI RS3 Mini', 4, 1),
    (15, 4, 'SD Card 128GB', 'SD Card 128GB', 5, 2),
    (16, 7, 'Lighting LED', '', 3, 2),
    (17, 7, 'Kamera', '', 1, 1),
    (18, 8, 'Kamera Mirrorless', 'Sony A7 III', 1, 1),
    (19, 8, 'Lensa Fix', 'Sigma 35mm f/1.4 DG DN', 2, 1),
    (20, 8, 'Lighting LED', 'Aputure Amaran 100d', 3, 1),
    (21, 9, 'Kamera', '', 1, 2),
    (22, 9, 'Tripod', 'Tripod Manfrotto 190X', 4, 1),
    (23, 9, 'SD Card 128GB', 'SD Card 128GB', 5, 2),
    (24, 10, 'Kamera Mirrorless', 'Sony A7 III', 1, 1),
    (25, 10, 'Lensa Portrait', 'Sony FE 85mm f/1.8', 2, 1),
    (26, 10, 'Gimbal', 'Gimbal DJI RS3 Mini', 4, 1),
    (27, 5, 'Kamera Sinema', 'Sony FX3', 1, 1),
    (28, 5, 'Lighting LED', 'Aputure Amaran 100d', 3, 1);

-- Proyek setiap alokasi diketahui lewat kebutuhan_id. diperiksa = centang checklist persiapan.
INSERT INTO alokasi_barang (id, kebutuhan_id, sumber, perlengkapan_id, nama_barang, vendor, pemilik_id, jumlah, tgl_mulai, tgl_selesai, status, catatan, diperiksa) VALUES
    (1, 1, 'Studio', 1, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', TRUE),
    (2, 1, 'Studio', 2, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', TRUE),
    (3, 2, 'Studio', 6, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', TRUE),
    (4, 3, 'Studio', 14, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', TRUE),
    (5, 3, 'Studio', 15, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', TRUE),
    (6, 4, 'Milik Kru', NULL, 'Tripod Manfrotto', '', 4, 1, '2026-10-24', '2026-10-24', 'Dikonfirmasi', '', TRUE),
    (7, 5, 'Studio', 1, '', '', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', '', TRUE),
    (8, 5, 'Studio', 3, '', '', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', '', TRUE),
    (9, 5, 'Rental', NULL, 'Sony FX3', 'Rental Kamera Malang', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', 'Masa sewa diperpanjang sampai 9 Nov', TRUE),
    (10, 6, 'Studio', 11, '', '', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', '', TRUE),
    (11, 6, 'Studio', 12, '', '', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', '', TRUE),
    (12, 7, 'Milik Kru', NULL, 'Gimbal DJI RS3', '', 5, 1, '2026-11-01', '2026-11-02', 'Dikonfirmasi', '', TRUE),
    (13, 8, 'Studio', 1, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', '', TRUE),
    (14, 8, 'Studio', 2, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', '', TRUE),
    (15, 8, 'Rental', NULL, 'Sony FX3', 'Rental Kamera Malang', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', 'Diantar vendor pagi hari', FALSE),
    (16, 9, 'Studio', 6, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', '', TRUE),
    (17, 9, 'Studio', 7, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', '', TRUE),
    (18, 10, 'Studio', 11, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dibatalkan', 'Unit masuk maintenance', FALSE),
    (19, 11, 'Milik Kru', NULL, 'SD Card 128GB ×2', '', 3, 1, '2026-11-07', '2026-11-08', 'Dikonfirmasi', 'Dibawa langsung oleh Rizky', FALSE),
    (20, 12, 'Studio', 3, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', FALSE),
    (21, 13, 'Studio', 8, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', TRUE),
    (22, 14, 'Studio', 19, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', TRUE),
    (23, 15, 'Studio', 20, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', FALSE),
    (24, 15, 'Studio', 21, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', FALSE),
    (25, 16, 'Studio', 12, '', '', NULL, 1, '2026-11-06', '2026-11-07', 'Dikonfirmasi', '', TRUE),
    (26, 16, 'Studio', 13, '', '', NULL, 1, '2026-11-06', '2026-11-07', 'Dikonfirmasi', '', TRUE),
    (27, 17, 'Studio', 5, '', '', NULL, 1, '2026-11-06', '2026-11-07', 'Dikonfirmasi', '', TRUE),
    (28, 18, 'Studio', 4, '', '', NULL, 1, '2026-10-04', '2026-10-05', 'Dikonfirmasi', '', TRUE),
    (29, 19, 'Studio', 10, '', '', NULL, 1, '2026-10-04', '2026-10-05', 'Dikonfirmasi', '', TRUE),
    (30, 20, 'Studio', 13, '', '', NULL, 1, '2026-10-04', '2026-10-05', 'Dikonfirmasi', '', TRUE),
    (31, 21, 'Studio', 1, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', TRUE),
    (32, 21, 'Studio', 2, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', TRUE),
    (33, 22, 'Studio', 18, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', TRUE),
    (34, 23, 'Studio', 20, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', TRUE),
    (35, 23, 'Studio', 21, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', TRUE),
    (36, 24, 'Studio', 4, '', '', NULL, 1, '2026-10-16', '2026-10-17', 'Dikonfirmasi', '', TRUE),
    (37, 25, 'Studio', 8, '', '', NULL, 1, '2026-10-16', '2026-10-17', 'Dikonfirmasi', '', TRUE),
    (38, 26, 'Studio', 19, '', '', NULL, 1, '2026-10-16', '2026-10-17', 'Dikonfirmasi', '', TRUE),
    (39, 27, 'Rental', NULL, 'Sony FX3', 'Rental Kamera Malang', NULL, 1, '2026-11-14', '2026-11-16', 'Direncanakan', 'Menunggu konfirmasi ketersediaan dari vendor', FALSE),
    (40, 28, 'Studio', 13, '', '', NULL, 1, '2026-11-14', '2026-11-16', 'Direncanakan', '', FALSE);

-- Satu baris per proyek (PRJ-024 baris kosong).
INSERT INTO persiapan_proyek (id, proyek_id, diperbarui_pada, diperbarui_oleh) VALUES
    (1, 1, '2026-10-23 18:30', 2),
    (2, 2, '2026-10-31 19:10', 2),
    (3, 3, '2026-11-06 19:40', 2),
    (4, 4, '2026-11-06 10:15', 2),
    (5, 5, NULL, NULL),
    (6, 7, '2026-11-05 19:20', 2),
    (7, 8, '2026-10-04 17:00', 2),
    (8, 9, '2026-10-09 19:00', 2),
    (9, 10, '2026-10-16 18:45', 2),
    (10, 6, NULL, NULL);

-- Keluar: penerima_id dari nama penerima. Kembali (data lama): penerima berisi nama kru apa adanya.
INSERT INTO transaksi_barang (id, kode, proyek_id, jenis, waktu, petugas_id, penerima, penerima_id, catatan) VALUES
    (1, 'TRX-038', 1, 'Keluar', '2026-10-24 05:30', 2, 'Sari Dewi', 4, ''),
    (2, 'TRX-039', 1, 'Kembali', '2026-10-24 19:10', 2, 'Sari Dewi', NULL, 'STD-001 kembali dalam keadaan patah'),
    (3, 'TRX-041', 2, 'Keluar', '2026-11-01 07:00', 2, 'Bagas Saputra', 5, ''),
    (4, 'TRX-042', 2, 'Kembali', '2026-11-02 23:30', 2, 'Bagas Saputra', NULL, 'CAM-003 belum kembali, dicari panitia'),
    (5, 'TRX-043', 7, 'Keluar', '2026-11-06 06:45', 2, 'Dimas Arya', 6, ''),
    (6, 'TRX-030', 8, 'Keluar', '2026-10-05 07:30', 2, 'Nina Kartika', 7, ''),
    (7, 'TRX-031', 8, 'Kembali', '2026-10-05 18:00', 2, 'Nina Kartika', NULL, ''),
    (8, 'TRX-033', 9, 'Keluar', '2026-10-10 06:00', 2, 'Bagas Saputra', 5, ''),
    (9, 'TRX-034', 9, 'Kembali', '2026-10-13 10:00', 2, 'Bagas Saputra', NULL, 'Terlambat 2 hari, barang sempat tertahan di panitia'),
    (10, 'TRX-036', 10, 'Keluar', '2026-10-17 13:00', 2, 'Sari Dewi', 4, ''),
    (11, 'TRX-037', 10, 'Kembali', '2026-10-17 22:30', 2, 'Sari Dewi', NULL, '');

INSERT INTO detail_transaksi (id, transaksi_id, alokasi_id, kondisi, jumlah) VALUES
    (1, 1, 1, 'Baik', 1),
    (2, 1, 2, 'Baik', 1),
    (3, 1, 3, 'Baik', 1),
    (4, 1, 4, 'Baik', 1),
    (5, 1, 5, 'Baik', 1),
    (6, 2, 1, 'Baik', 1),
    (7, 2, 2, 'Baik', 1),
    (8, 2, 3, 'Baik', 1),
    (9, 2, 4, 'Rusak', 1),
    (10, 2, 5, 'Baik', 1),
    (11, 3, 7, 'Baik', 1),
    (12, 3, 8, 'Baik', 1),
    (13, 3, 10, 'Baik', 1),
    (14, 3, 11, 'Baik', 1),
    (15, 4, 7, 'Baik', 1),
    (16, 4, 10, 'Maintenance', 1),
    (17, 4, 11, 'Baik', 1),
    (18, 5, 25, 'Baik', 1),
    (19, 5, 26, 'Baik', 1),
    (20, 5, 27, 'Baik', 1),
    (21, 6, 28, 'Baik', 1),
    (22, 6, 29, 'Baik', 1),
    (23, 6, 30, 'Baik', 1),
    (24, 7, 28, 'Baik', 1),
    (25, 7, 29, 'Baik', 1),
    (26, 7, 30, 'Baik', 1),
    (27, 8, 31, 'Baik', 1),
    (28, 8, 32, 'Baik', 1),
    (29, 8, 33, 'Baik', 1),
    (30, 8, 34, 'Baik', 1),
    (31, 8, 35, 'Baik', 1),
    (32, 9, 31, 'Baik', 1),
    (33, 9, 32, 'Baik', 1),
    (34, 9, 33, 'Baik', 1),
    (35, 9, 34, 'Baik', 1),
    (36, 9, 35, 'Baik', 1),
    (37, 10, 36, 'Baik', 1),
    (38, 10, 37, 'Baik', 1),
    (39, 10, 38, 'Baik', 1),
    (40, 11, 36, 'Baik', 1),
    (41, 11, 37, 'Baik', 1),
    (42, 11, 38, 'Baik', 1);

INSERT INTO laporan_insiden (id, kode, perlengkapan_id, proyek_id, jenis, waktu, deskripsi, status, pelapor_id) VALUES
    (1, 'INS-010', 10, 8, 'Masalah Teknis', '2026-10-05 18:05', 'Autofokus sempat macet, sudah dibersihkan kontaknya.', 'Selesai', 2),
    (2, 'INS-011', 14, 1, 'Rusak', '2026-10-24 19:15', 'Kaki stand patah saat dibongkar di lokasi.', 'Ditangani', 2),
    (3, 'INS-012', 11, 2, 'Masalah Teknis', '2026-11-02 23:35', 'Kipas pendingin berisik, lampu mati sendiri setelah 20 menit.', 'Ditangani', 2);

INSERT INTO riwayat_aktivitas (id, waktu, pengguna_id, aksi, objek, keterangan, lama, baru) VALUES
    (1, '2026-10-05 07:30', 2, 'Barang Keluar', 'PRJ-016 · TRX-030', '3 unit diserahkan ke Nina Kartika', '', ''),
    (2, '2026-10-05 18:00', 2, 'Pengembalian', 'PRJ-016 · TRX-031', '3 unit kembali tepat waktu', '', ''),
    (3, '2026-10-10 06:00', 2, 'Barang Keluar', 'PRJ-017 · TRX-033', '5 unit diserahkan ke Bagas Saputra', '', ''),
    (4, '2026-10-13 10:00', 2, 'Pengembalian', 'PRJ-017 · TRX-034', '5 unit kembali, terlambat 2 hari', '', ''),
    (5, '2026-10-17 13:00', 2, 'Barang Keluar', 'PRJ-018 · TRX-036', '3 unit diserahkan ke Sari Dewi', '', ''),
    (6, '2026-10-17 22:30', 2, 'Pengembalian', 'PRJ-018 · TRX-037', '3 unit kembali tepat waktu', '', ''),
    (7, '2026-10-24 19:10', 2, 'Pengembalian', 'PRJ-019 · TRX-039', '5 unit kembali, STD-001 rusak', '', ''),
    (8, '2026-10-24 19:15', 2, 'Insiden', 'STD-001 · Light Stand 2.8m', 'Kaki stand patah saat dibongkar', 'Baik', 'Rusak'),
    (9, '2026-11-01 07:00', 2, 'Barang Keluar', 'PRJ-020 · TRX-041', '4 unit diserahkan ke Bagas Saputra', '', ''),
    (10, '2026-11-02 23:30', 2, 'Pengembalian', 'PRJ-020 · TRX-042', '3 dari 4 unit kembali, CAM-003 belum kembali', '', ''),
    (11, '2026-11-02 23:35', 2, 'Insiden', 'LGT-001 · Godox SL60W', 'Kipas pendingin berisik', 'Baik', 'Maintenance'),
    (12, '2026-11-03 10:42', 2, 'Alokasi', 'Sony FX3 · Rental Kamera Malang', 'Masa sewa PRJ-020 diperpanjang', '2 Nov 2026', '9 Nov 2026'),
    (13, '2026-11-05 09:15', 2, 'Alokasi', 'CAM-001 · PRJ-021', 'Dialokasikan ke Wedding Arunika & Dimas', '', ''),
    (14, '2026-11-05 09:20', 2, 'Alokasi', 'LGT-001 · PRJ-021', 'Alokasi dibatalkan, unit masuk maintenance', 'Dikonfirmasi', 'Dibatalkan'),
    (15, '2026-11-05 11:00', 2, 'Alokasi', 'SD Card 128GB ×2 · PRJ-021', 'Dicatat sebagai barang milik Rizky Pratama', '', ''),
    (16, '2026-11-06 06:45', 2, 'Barang Keluar', 'PRJ-025 · TRX-043', '3 unit diserahkan ke Dimas Arya', '', ''),
    (17, '2026-11-06 10:15', 2, 'Persiapan', 'PRJ-022', '2 dari 5 barang diperiksa', '', ''),
    (18, '2026-11-06 16:20', 1, 'Proyek', 'PRJ-023 · Company Profile PT Sinar', 'Proyek baru dibuat', '', ''),
    (19, '2026-11-06 19:40', 2, 'Persiapan', 'PRJ-021', '4 dari 6 barang diperiksa', '', '');

-- ---------------------------------------------------------------------------
-- Data dummy tambahan untuk pengujian (tidak ada di prototipe).
-- ---------------------------------------------------------------------------

-- a. Siklus rental: Sony FX3 PRJ-020 sudah diterima dari vendor dan masa sewanya diperpanjang
--    sampai 9 Nov (sesuai catatan alokasi #9 dan riwayat #12).
UPDATE alokasi_barang
SET diterima_pada      = '2026-10-31 18:00',
    diterima_oleh      = 2,
    kondisi_terima     = 'Baik',
    sewa_mulai         = '2026-10-31',
    jatuh_tempo_vendor = '2026-11-09',
    kontak_vendor      = '0812-0000-1111'
WHERE id = 9;

-- b. Konfirmasi persiapan: PRJ-019 tepat waktu (deadline 23 Okt 20:00), PRJ-025 terlambat (deadline 5 Nov 20:00).
UPDATE persiapan_proyek SET dikonfirmasi_pada = '2026-10-23 18:30', dikonfirmasi_oleh = 2 WHERE proyek_id = 1;
UPDATE persiapan_proyek SET dikonfirmasi_pada = '2026-11-05 21:15', dikonfirmasi_oleh = 2 WHERE proyek_id = 7;

-- c. Pengembalian sebagian: SD Card milik Dimas (2 keping) keluar untuk PRJ-025, baru 1 yang kembali.
INSERT INTO kebutuhan_proyek (id, proyek_id, nama, acuan, kategori_id, jumlah) VALUES
    (29, 7, 'SD Card cadangan', '', 5, 2);
INSERT INTO alokasi_barang (id, kebutuhan_id, sumber, nama_barang, pemilik_id, jumlah, tgl_mulai, tgl_selesai, status, catatan, diperiksa) VALUES
    (41, 29, 'Milik Kru', 'SD Card 64GB ×2', 6, 2, '2026-11-06', '2026-11-07', 'Dikonfirmasi', 'Dibawa Dimas', TRUE);
INSERT INTO transaksi_barang (id, kode, proyek_id, jenis, waktu, petugas_id, penerima, penerima_id, penyerah, penyerah_id, catatan, dicatat_pada) VALUES
    (12, 'TRX-044', 7, 'Keluar',  '2026-11-06 07:00', 2, 'Dimas Arya',   6,    '',           NULL, '', '2026-11-06 07:00'),
    (13, 'TRX-045', 7, 'Kembali', '2026-11-06 21:00', 2, 'Budi Santoso', NULL, 'Dimas Arya', 6,    'Satu SD Card masih dipakai di sesi kedua', '2026-11-06 21:00');
INSERT INTO detail_transaksi (id, transaksi_id, alokasi_id, kondisi, jumlah) VALUES
    (43, 12, 41, 'Baik', 2),
    (44, 13, 41, 'Baik', 1);
INSERT INTO riwayat_aktivitas (id, waktu, pengguna_id, aksi, objek, keterangan) VALUES
    (20, '2026-11-06 07:00', 2, 'Barang Keluar', 'PRJ-025 · TRX-044', '2 unit diserahkan ke Dimas Arya (Milik Kru 2)'),
    (21, '2026-11-06 21:00', 2, 'Pengembalian',  'PRJ-025 · TRX-045', '1 dari 2 unit kembali dari PRJ-025 (Milik Kru 1)');

-- ---------------------------------------------------------------------------
-- Selaraskan nomor otomatis dengan ID dan kode yang sudah diisi manual.
-- ---------------------------------------------------------------------------
SELECT setval(pg_get_serial_sequence('pengguna', 'id'),              (SELECT MAX(id) FROM pengguna));
SELECT setval(pg_get_serial_sequence('kategori_perlengkapan', 'id'), (SELECT MAX(id) FROM kategori_perlengkapan));
SELECT setval(pg_get_serial_sequence('perlengkapan', 'id'),          (SELECT MAX(id) FROM perlengkapan));
SELECT setval(pg_get_serial_sequence('proyek', 'id'),                (SELECT MAX(id) FROM proyek));
SELECT setval(pg_get_serial_sequence('anggota_proyek', 'id'),        (SELECT MAX(id) FROM anggota_proyek));
SELECT setval(pg_get_serial_sequence('preset_perlengkapan', 'id'),   (SELECT MAX(id) FROM preset_perlengkapan));
SELECT setval(pg_get_serial_sequence('detail_preset', 'id'),         (SELECT MAX(id) FROM detail_preset));
SELECT setval(pg_get_serial_sequence('kebutuhan_proyek', 'id'),      (SELECT MAX(id) FROM kebutuhan_proyek));
SELECT setval(pg_get_serial_sequence('alokasi_barang', 'id'),        (SELECT MAX(id) FROM alokasi_barang));
SELECT setval(pg_get_serial_sequence('persiapan_proyek', 'id'),      (SELECT MAX(id) FROM persiapan_proyek));
SELECT setval(pg_get_serial_sequence('transaksi_barang', 'id'),      (SELECT MAX(id) FROM transaksi_barang));
SELECT setval(pg_get_serial_sequence('detail_transaksi', 'id'),      (SELECT MAX(id) FROM detail_transaksi));
SELECT setval(pg_get_serial_sequence('laporan_insiden', 'id'),       (SELECT MAX(id) FROM laporan_insiden));
SELECT setval(pg_get_serial_sequence('riwayat_aktivitas', 'id'),     (SELECT MAX(id) FROM riwayat_aktivitas));

-- Kode berikutnya: PRJ-026, TRX-046, INS-013.
SELECT setval('seq_kode_proyek',    (SELECT MAX(CAST(SUBSTRING(kode FROM 5) AS INTEGER)) FROM proyek));
SELECT setval('seq_kode_transaksi', (SELECT MAX(CAST(SUBSTRING(kode FROM 5) AS INTEGER)) FROM transaksi_barang));
SELECT setval('seq_kode_insiden',   (SELECT MAX(CAST(SUBSTRING(kode FROM 5) AS INTEGER)) FROM laporan_insiden));

COMMIT;
