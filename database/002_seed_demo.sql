-- PhotoOps Nicio Studio — data demo untuk development/testing, bukan untuk database produksi.
-- Konversi seed_data() data/seed.php (VERSI_DATA 2). Jalankan sekali setelah 001_schema.sql pada tabel kosong.
-- ID, kode, teks, dan waktu dipertahankan; waktu ditulis eksplisit WIB (+07).
-- Kolom yang tidak ada di seed lama dibiarkan NULL/''; turunan konversi dicatat per tabel.

SET client_encoding = 'UTF8';

BEGIN;

-- password -> password_hash (hash bcrypt yang sama).
INSERT INTO pengguna (id, nama, username, email, password_hash, peran, jenis_anggota, telepon, status)
OVERRIDING SYSTEM VALUE VALUES
    (1, 'Fiki Azka', 'fiki.cantik', 'fiki@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'sa', 'Internal', '0812-3456-7801', 'Aktif'),
    (2, 'Budi Santoso', 'budi.santoso', 'budi@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'pi', 'Internal', '0812-3456-7802', 'Aktif'),
    (3, 'Rizky Pratama', 'rizky.p', 'rizky.pratama@gmail.com', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Freelancer', '0813-2200-1144', 'Aktif'),
    (4, 'Sari Dewi', 'sari.dewi', 'sari@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Internal', '0813-2200-1145', 'Aktif'),
    (5, 'Bagas Saputra', 'bagas.s', 'bagas@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Internal', '0813-2200-1146', 'Aktif'),
    (6, 'Dimas Arya', 'dimas.a', 'dimas.arya@gmail.com', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Freelancer', '0857-1100-9921', 'Aktif'),
    (7, 'Nina Kartika', 'nina.k', 'nina@niciostudio.id', '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S', 'us', 'Internal', '0813-2200-1147', 'Aktif');

-- nomor_terakhir = nomor kode_unit tertinggi per kategori.
INSERT INTO kategori_perlengkapan (id, nama, kode_awalan, deskripsi, nomor_terakhir)
OVERRIDING SYSTEM VALUE VALUES
    (1, 'Kamera', 'CAM', 'Body kamera foto dan video', 5),
    (2, 'Lensa', 'LNS', 'Lensa semua mount', 5),
    (3, 'Pencahayaan', 'LGT', 'Lampu LED, flash, softbox', 3),
    (4, 'Aksesori', 'STD', 'Stand, tripod, gimbal', 6),
    (5, 'Penyimpanan', 'MEM', 'SD card, SSD, card reader', 4);

INSERT INTO perlengkapan (id, kode_unit, nama, merek, kategori_id, kondisi, status_aktif, catatan)
OVERRIDING SYSTEM VALUE VALUES
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

-- pic_id tidak disimpan; identik dengan anggota_proyek peran PIC (v_proyek).
INSERT INTO proyek (id, kode, nama, klien, lokasi, mulai, selesai, deadline_persiapan, status, catatan, dibuat_oleh)
OVERRIDING SYSTEM VALUE VALUES
    (1, 'PRJ-019', 'Wisuda Kampus Brawijaya', 'Universitas Brawijaya', 'Malang', '2026-10-24 07:00+07', '2026-10-24 17:00+07', '2026-10-23 20:00+07', 'Selesai', 'Dokumentasi prosesi dan foto keluarga.', 1),
    (2, 'PRJ-020', 'Dokumentasi Konser Senandika', 'Senandika Live', 'Malang', '2026-11-01 15:00+07', '2026-11-02 23:00+07', '2026-10-31 20:00+07', 'Berlangsung', 'Dua hari konser, butuh video multicam.', 1),
    (3, 'PRJ-021', 'Wedding Arunika & Dimas', 'Arunika Putri', 'Batu, Malang', '2026-11-07 08:00+07', '2026-11-08 22:00+07', '2026-11-06 20:00+07', 'Dijadwalkan', 'Akad pagi di rumah mempelai, resepsi malam di hotel. Butuh 2 tim kamera.', 1),
    (4, 'PRJ-022', 'Prewedding Laras', 'Laras Wulandari', 'Coban Rondo', '2026-11-12 05:30+07', '2026-11-12 13:00+07', '2026-11-11 20:00+07', 'Dijadwalkan', 'Sesi sunrise, satu tim kecil.', 1),
    (5, 'PRJ-023', 'Company Profile PT Sinar', 'PT Sinar Abadi', 'Surabaya', '2026-11-15 08:00+07', '2026-11-16 17:00+07', '2026-11-14 20:00+07', 'Draf', 'Video korporat dan foto tim manajemen.', 1),
    (6, 'PRJ-024', 'Ulang Tahun Kirana', 'Keluarga Wijaya', 'Kota Batu', '2026-11-14 16:00+07', '2026-11-14 21:00+07', '2026-11-13 20:00+07', 'Dibatalkan', 'Dibatalkan klien karena acara diundur.', 1),
    (7, 'PRJ-025', 'Lamaran Nadia & Fajar', 'Nadia Salsabila', 'Malang', '2026-11-06 09:00+07', '2026-11-07 14:00+07', '2026-11-05 20:00+07', 'Berlangsung', 'Lamaran dua sesi, butuh lighting indoor.', 1),
    (8, 'PRJ-016', 'Foto Produk Kedai Senja', 'Kedai Senja', 'Malang', '2026-10-05 09:00+07', '2026-10-05 16:00+07', '2026-10-04 20:00+07', 'Selesai', 'Katalog menu musim hujan.', 1),
    (9, 'PRJ-017', 'Seminar Nasional Teknologi', 'Himpunan Mahasiswa TI', 'Malang', '2026-10-10 07:00+07', '2026-10-11 16:00+07', '2026-10-09 20:00+07', 'Selesai', 'Dua hari seminar, dokumentasi panggung dan peserta.', 1),
    (10, 'PRJ-018', 'Engagement Rara & Bima', 'Rara Anindita', 'Kota Batu', '2026-10-17 15:00+07', '2026-10-17 21:00+07', '2026-10-16 20:00+07', 'Selesai', 'Sesi sore sampai malam di villa.', 1);

INSERT INTO anggota_proyek (id, proyek_id, pengguna_id, peran)
OVERRIDING SYSTEM VALUE VALUES
    (1, 1, 4, 'PIC'),
    (2, 1, 3, 'Kru'),
    (3, 2, 5, 'PIC'),
    (4, 2, 7, 'Kru'),
    (5, 3, 3, 'PIC'),
    (6, 3, 4, 'Kru'),
    (7, 3, 6, 'Kru'),
    (8, 4, 4, 'PIC'),
    (9, 4, 3, 'Kru'),
    (10, 5, 5, 'PIC'),
    (11, 5, 3, 'Kru'),
    (12, 6, 7, 'PIC'),
    (13, 7, 6, 'PIC'),
    (14, 7, 7, 'Kru'),
    (15, 8, 7, 'PIC'),
    (16, 8, 3, 'Kru'),
    (17, 9, 5, 'PIC'),
    (18, 9, 6, 'Kru'),
    (19, 10, 4, 'PIC'),
    (20, 10, 3, 'Kru');

INSERT INTO preset_perlengkapan (id, nama, deskripsi, dibuat_oleh, diubah_pada)
OVERRIDING SYSTEM VALUE VALUES
    (1, 'Wedding Basic', 'Akad pagi + resepsi malam, 2 tim kamera.', 1, '2026-10-28 14:10+07'),
    (2, 'Prewedding Outdoor', 'Sesi luar ruang, 1 tim.', 2, '2026-10-12 10:00+07'),
    (3, 'Event / Konser', 'Dokumentasi panggung multicam.', 1, '2026-10-20 09:30+07'),
    (4, 'Company Profile', 'Video korporat dan foto tim.', 2, '2026-10-05 13:45+07');

INSERT INTO detail_preset (id, preset_id, nama_perlengkapan, kategori_id, jumlah)
OVERRIDING SYSTEM VALUE VALUES
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

INSERT INTO kebutuhan_proyek (id, proyek_id, nama, acuan, kategori_id, jumlah)
OVERRIDING SYSTEM VALUE VALUES
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

-- jumlah kosong = 1; diperiksa dari array persiapan.diperiksa. Kolom siklus vendor tidak ada di seed lama.
INSERT INTO alokasi_barang (id, proyek_id, kebutuhan_id, sumber, perlengkapan_id, nama_barang, vendor, pemilik_id, jumlah, tgl_mulai, tgl_selesai, status, catatan, diperiksa)
OVERRIDING SYSTEM VALUE VALUES
    (1, 1, 1, 'Studio', 1, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', true),
    (2, 1, 1, 'Studio', 2, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', true),
    (3, 1, 2, 'Studio', 6, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', true),
    (4, 1, 3, 'Studio', 14, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', true),
    (5, 1, 3, 'Studio', 15, '', '', NULL, 1, '2026-10-23', '2026-10-24', 'Dikonfirmasi', '', true),
    (6, 1, 4, 'Milik Kru', NULL, 'Tripod Manfrotto', '', 4, 1, '2026-10-24', '2026-10-24', 'Dikonfirmasi', '', true),
    (7, 2, 5, 'Studio', 1, '', '', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', '', true),
    (8, 2, 5, 'Studio', 3, '', '', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', '', true),
    (9, 2, 5, 'Rental', NULL, 'Sony FX3', 'Rental Kamera Malang', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', 'Masa sewa diperpanjang sampai 9 Nov', true),
    (10, 2, 6, 'Studio', 11, '', '', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', '', true),
    (11, 2, 6, 'Studio', 12, '', '', NULL, 1, '2026-10-31', '2026-11-02', 'Dikonfirmasi', '', true),
    (12, 2, 7, 'Milik Kru', NULL, 'Gimbal DJI RS3', '', 5, 1, '2026-11-01', '2026-11-02', 'Dikonfirmasi', '', true),
    (13, 3, 8, 'Studio', 1, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', '', true),
    (14, 3, 8, 'Studio', 2, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', '', true),
    (15, 3, 8, 'Rental', NULL, 'Sony FX3', 'Rental Kamera Malang', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', 'Diantar vendor pagi hari', false),
    (16, 3, 9, 'Studio', 6, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', '', true),
    (17, 3, 9, 'Studio', 7, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dikonfirmasi', '', true),
    (18, 3, 10, 'Studio', 11, '', '', NULL, 1, '2026-11-06', '2026-11-08', 'Dibatalkan', 'Unit masuk maintenance', false),
    (19, 3, 11, 'Milik Kru', NULL, 'SD Card 128GB ×2', '', 3, 1, '2026-11-07', '2026-11-08', 'Dikonfirmasi', 'Dibawa langsung oleh Rizky', false),
    (20, 4, 12, 'Studio', 3, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', false),
    (21, 4, 13, 'Studio', 8, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', true),
    (22, 4, 14, 'Studio', 19, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', true),
    (23, 4, 15, 'Studio', 20, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', false),
    (24, 4, 15, 'Studio', 21, '', '', NULL, 1, '2026-11-11', '2026-11-12', 'Dikonfirmasi', '', false),
    (25, 7, 16, 'Studio', 12, '', '', NULL, 1, '2026-11-06', '2026-11-07', 'Dikonfirmasi', '', true),
    (26, 7, 16, 'Studio', 13, '', '', NULL, 1, '2026-11-06', '2026-11-07', 'Dikonfirmasi', '', true),
    (27, 7, 17, 'Studio', 5, '', '', NULL, 1, '2026-11-06', '2026-11-07', 'Dikonfirmasi', '', true),
    (28, 8, 18, 'Studio', 4, '', '', NULL, 1, '2026-10-04', '2026-10-05', 'Dikonfirmasi', '', true),
    (29, 8, 19, 'Studio', 10, '', '', NULL, 1, '2026-10-04', '2026-10-05', 'Dikonfirmasi', '', true),
    (30, 8, 20, 'Studio', 13, '', '', NULL, 1, '2026-10-04', '2026-10-05', 'Dikonfirmasi', '', true),
    (31, 9, 21, 'Studio', 1, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', true),
    (32, 9, 21, 'Studio', 2, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', true),
    (33, 9, 22, 'Studio', 18, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', true),
    (34, 9, 23, 'Studio', 20, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', true),
    (35, 9, 23, 'Studio', 21, '', '', NULL, 1, '2026-10-09', '2026-10-11', 'Dikonfirmasi', '', true),
    (36, 10, 24, 'Studio', 4, '', '', NULL, 1, '2026-10-16', '2026-10-17', 'Dikonfirmasi', '', true),
    (37, 10, 25, 'Studio', 8, '', '', NULL, 1, '2026-10-16', '2026-10-17', 'Dikonfirmasi', '', true),
    (38, 10, 26, 'Studio', 19, '', '', NULL, 1, '2026-10-16', '2026-10-17', 'Dikonfirmasi', '', true),
    (39, 5, 27, 'Rental', NULL, 'Sony FX3', 'Rental Kamera Malang', NULL, 1, '2026-11-14', '2026-11-16', 'Direncanakan', 'Menunggu konfirmasi ketersediaan dari vendor', false),
    (40, 5, 28, 'Studio', 13, '', '', NULL, 1, '2026-11-14', '2026-11-16', 'Direncanakan', '', false);

-- Baris id 10 ditambahkan untuk proyek 6 (PRJ-024) agar relasi 1:1 lengkap.
INSERT INTO persiapan_proyek (id, proyek_id, diperbarui_pada, diperbarui_oleh)
OVERRIDING SYSTEM VALUE VALUES
    (1, 1, '2026-10-23 18:30+07', 2),
    (2, 2, '2026-10-31 19:10+07', 2),
    (3, 3, '2026-11-06 19:40+07', 2),
    (4, 4, '2026-11-06 10:15+07', 2),
    (5, 5, NULL, NULL),
    (6, 7, '2026-11-05 19:20+07', 2),
    (7, 8, '2026-10-04 17:00+07', 2),
    (8, 9, '2026-10-09 19:00+07', 2),
    (9, 10, '2026-10-16 18:45+07', 2),
    (10, 6, NULL, NULL);

-- Keluar: penerima_id dari nama pengguna yang persis sama. Kembali lama: penerima disalin apa adanya.
INSERT INTO transaksi_barang (id, kode, proyek_id, jenis, waktu, petugas_id, penerima, penerima_id, catatan)
OVERRIDING SYSTEM VALUE VALUES
    (1, 'TRX-038', 1, 'Keluar', '2026-10-24 05:30+07', 2, 'Sari Dewi', 4, ''),
    (2, 'TRX-039', 1, 'Kembali', '2026-10-24 19:10+07', 2, 'Sari Dewi', NULL, 'STD-001 kembali dalam keadaan patah'),
    (3, 'TRX-041', 2, 'Keluar', '2026-11-01 07:00+07', 2, 'Bagas Saputra', 5, ''),
    (4, 'TRX-042', 2, 'Kembali', '2026-11-02 23:30+07', 2, 'Bagas Saputra', NULL, 'CAM-003 belum kembali, dicari panitia'),
    (5, 'TRX-043', 7, 'Keluar', '2026-11-06 06:45+07', 2, 'Dimas Arya', 6, ''),
    (6, 'TRX-030', 8, 'Keluar', '2026-10-05 07:30+07', 2, 'Nina Kartika', 7, ''),
    (7, 'TRX-031', 8, 'Kembali', '2026-10-05 18:00+07', 2, 'Nina Kartika', NULL, ''),
    (8, 'TRX-033', 9, 'Keluar', '2026-10-10 06:00+07', 2, 'Bagas Saputra', 5, ''),
    (9, 'TRX-034', 9, 'Kembali', '2026-10-13 10:00+07', 2, 'Bagas Saputra', NULL, 'Terlambat 2 hari, barang sempat tertahan di panitia'),
    (10, 'TRX-036', 10, 'Keluar', '2026-10-17 13:00+07', 2, 'Sari Dewi', 4, ''),
    (11, 'TRX-037', 10, 'Kembali', '2026-10-17 22:30+07', 2, 'Sari Dewi', NULL, '');

-- jenis dari kepala transaksi; jumlah kosong = 1; trx_asal Kembali = Keluar dari alokasi yang sama.
INSERT INTO detail_transaksi (id, transaksi_id, jenis, alokasi_id, kondisi, jumlah, trx_asal)
OVERRIDING SYSTEM VALUE VALUES
    (1, 1, 'Keluar', 1, 'Baik', 1, NULL),
    (2, 1, 'Keluar', 2, 'Baik', 1, NULL),
    (3, 1, 'Keluar', 3, 'Baik', 1, NULL),
    (4, 1, 'Keluar', 4, 'Baik', 1, NULL),
    (5, 1, 'Keluar', 5, 'Baik', 1, NULL),
    (6, 2, 'Kembali', 1, 'Baik', 1, 1),
    (7, 2, 'Kembali', 2, 'Baik', 1, 1),
    (8, 2, 'Kembali', 3, 'Baik', 1, 1),
    (9, 2, 'Kembali', 4, 'Rusak', 1, 1),
    (10, 2, 'Kembali', 5, 'Baik', 1, 1),
    (11, 3, 'Keluar', 7, 'Baik', 1, NULL),
    (12, 3, 'Keluar', 8, 'Baik', 1, NULL),
    (13, 3, 'Keluar', 10, 'Baik', 1, NULL),
    (14, 3, 'Keluar', 11, 'Baik', 1, NULL),
    (15, 4, 'Kembali', 7, 'Baik', 1, 3),
    (16, 4, 'Kembali', 10, 'Maintenance', 1, 3),
    (17, 4, 'Kembali', 11, 'Baik', 1, 3),
    (18, 5, 'Keluar', 25, 'Baik', 1, NULL),
    (19, 5, 'Keluar', 26, 'Baik', 1, NULL),
    (20, 5, 'Keluar', 27, 'Baik', 1, NULL),
    (21, 6, 'Keluar', 28, 'Baik', 1, NULL),
    (22, 6, 'Keluar', 29, 'Baik', 1, NULL),
    (23, 6, 'Keluar', 30, 'Baik', 1, NULL),
    (24, 7, 'Kembali', 28, 'Baik', 1, 6),
    (25, 7, 'Kembali', 29, 'Baik', 1, 6),
    (26, 7, 'Kembali', 30, 'Baik', 1, 6),
    (27, 8, 'Keluar', 31, 'Baik', 1, NULL),
    (28, 8, 'Keluar', 32, 'Baik', 1, NULL),
    (29, 8, 'Keluar', 33, 'Baik', 1, NULL),
    (30, 8, 'Keluar', 34, 'Baik', 1, NULL),
    (31, 8, 'Keluar', 35, 'Baik', 1, NULL),
    (32, 9, 'Kembali', 31, 'Baik', 1, 8),
    (33, 9, 'Kembali', 32, 'Baik', 1, 8),
    (34, 9, 'Kembali', 33, 'Baik', 1, 8),
    (35, 9, 'Kembali', 34, 'Baik', 1, 8),
    (36, 9, 'Kembali', 35, 'Baik', 1, 8),
    (37, 10, 'Keluar', 36, 'Baik', 1, NULL),
    (38, 10, 'Keluar', 37, 'Baik', 1, NULL),
    (39, 10, 'Keluar', 38, 'Baik', 1, NULL),
    (40, 11, 'Kembali', 36, 'Baik', 1, 10),
    (41, 11, 'Kembali', 37, 'Baik', 1, 10),
    (42, 11, 'Kembali', 38, 'Baik', 1, 10);

-- detail_id NULL: seed lama tidak menautkan insiden ke detail pengembalian.
INSERT INTO laporan_insiden (id, kode, perlengkapan_id, proyek_id, jenis, waktu, deskripsi, status, pelapor_id)
OVERRIDING SYSTEM VALUE VALUES
    (1, 'INS-010', 10, 8, 'Masalah Teknis', '2026-10-05 18:05+07', 'Autofokus sempat macet, sudah dibersihkan kontaknya.', 'Selesai', 2),
    (2, 'INS-011', 14, 1, 'Rusak', '2026-10-24 19:15+07', 'Kaki stand patah saat dibongkar di lokasi.', 'Ditangani', 2),
    (3, 'INS-012', 11, 2, 'Masalah Teknis', '2026-11-02 23:35+07', 'Kipas pendingin berisik, lampu mati sendiri setelah 20 menit.', 'Ditangani', 2);

INSERT INTO riwayat_aktivitas (id, waktu, pengguna_id, aksi, objek, keterangan, lama, baru)
OVERRIDING SYSTEM VALUE VALUES
    (1, '2026-10-05 07:30+07', 2, 'Barang Keluar', 'PRJ-016 · TRX-030', '3 unit diserahkan ke Nina Kartika', '', ''),
    (2, '2026-10-05 18:00+07', 2, 'Pengembalian', 'PRJ-016 · TRX-031', '3 unit kembali tepat waktu', '', ''),
    (3, '2026-10-10 06:00+07', 2, 'Barang Keluar', 'PRJ-017 · TRX-033', '5 unit diserahkan ke Bagas Saputra', '', ''),
    (4, '2026-10-13 10:00+07', 2, 'Pengembalian', 'PRJ-017 · TRX-034', '5 unit kembali, terlambat 2 hari', '', ''),
    (5, '2026-10-17 13:00+07', 2, 'Barang Keluar', 'PRJ-018 · TRX-036', '3 unit diserahkan ke Sari Dewi', '', ''),
    (6, '2026-10-17 22:30+07', 2, 'Pengembalian', 'PRJ-018 · TRX-037', '3 unit kembali tepat waktu', '', ''),
    (7, '2026-10-24 19:10+07', 2, 'Pengembalian', 'PRJ-019 · TRX-039', '5 unit kembali, STD-001 rusak', '', ''),
    (8, '2026-10-24 19:15+07', 2, 'Insiden', 'STD-001 · Light Stand 2.8m', 'Kaki stand patah saat dibongkar', 'Baik', 'Rusak'),
    (9, '2026-11-01 07:00+07', 2, 'Barang Keluar', 'PRJ-020 · TRX-041', '4 unit diserahkan ke Bagas Saputra', '', ''),
    (10, '2026-11-02 23:30+07', 2, 'Pengembalian', 'PRJ-020 · TRX-042', '3 dari 4 unit kembali, CAM-003 belum kembali', '', ''),
    (11, '2026-11-02 23:35+07', 2, 'Insiden', 'LGT-001 · Godox SL60W', 'Kipas pendingin berisik', 'Baik', 'Maintenance'),
    (12, '2026-11-03 10:42+07', 2, 'Alokasi', 'Sony FX3 · Rental Kamera Malang', 'Masa sewa PRJ-020 diperpanjang', '2 Nov 2026', '9 Nov 2026'),
    (13, '2026-11-05 09:15+07', 2, 'Alokasi', 'CAM-001 · PRJ-021', 'Dialokasikan ke Wedding Arunika & Dimas', '', ''),
    (14, '2026-11-05 09:20+07', 2, 'Alokasi', 'LGT-001 · PRJ-021', 'Alokasi dibatalkan, unit masuk maintenance', 'Dikonfirmasi', 'Dibatalkan'),
    (15, '2026-11-05 11:00+07', 2, 'Alokasi', 'SD Card 128GB ×2 · PRJ-021', 'Dicatat sebagai barang milik Rizky Pratama', '', ''),
    (16, '2026-11-06 06:45+07', 2, 'Barang Keluar', 'PRJ-025 · TRX-043', '3 unit diserahkan ke Dimas Arya', '', ''),
    (17, '2026-11-06 10:15+07', 2, 'Persiapan', 'PRJ-022', '2 dari 5 barang diperiksa', '', ''),
    (18, '2026-11-06 16:20+07', 1, 'Proyek', 'PRJ-023 · Company Profile PT Sinar', 'Proyek baru dibuat', '', ''),
    (19, '2026-11-06 19:40+07', 2, 'Persiapan', 'PRJ-021', '4 dari 6 barang diperiksa', '', '');

-- Selaraskan identity dan sequence kode dengan data eksplisit.

SELECT setval(pg_get_serial_sequence('pengguna', 'id'),              (SELECT max(id) FROM pengguna));
SELECT setval(pg_get_serial_sequence('kategori_perlengkapan', 'id'), (SELECT max(id) FROM kategori_perlengkapan));
SELECT setval(pg_get_serial_sequence('perlengkapan', 'id'),          (SELECT max(id) FROM perlengkapan));
SELECT setval(pg_get_serial_sequence('proyek', 'id'),                (SELECT max(id) FROM proyek));
SELECT setval(pg_get_serial_sequence('anggota_proyek', 'id'),        (SELECT max(id) FROM anggota_proyek));
SELECT setval(pg_get_serial_sequence('preset_perlengkapan', 'id'),   (SELECT max(id) FROM preset_perlengkapan));
SELECT setval(pg_get_serial_sequence('detail_preset', 'id'),         (SELECT max(id) FROM detail_preset));
SELECT setval(pg_get_serial_sequence('kebutuhan_proyek', 'id'),      (SELECT max(id) FROM kebutuhan_proyek));
SELECT setval(pg_get_serial_sequence('alokasi_barang', 'id'),        (SELECT max(id) FROM alokasi_barang));
SELECT setval(pg_get_serial_sequence('persiapan_proyek', 'id'),      (SELECT max(id) FROM persiapan_proyek));
SELECT setval(pg_get_serial_sequence('transaksi_barang', 'id'),      (SELECT max(id) FROM transaksi_barang));
SELECT setval(pg_get_serial_sequence('detail_transaksi', 'id'),      (SELECT max(id) FROM detail_transaksi));
SELECT setval(pg_get_serial_sequence('laporan_insiden', 'id'),       (SELECT max(id) FROM laporan_insiden));
SELECT setval(pg_get_serial_sequence('riwayat_aktivitas', 'id'),     (SELECT max(id) FROM riwayat_aktivitas));

SELECT setval('seq_kode_proyek',    (SELECT max(substring(kode FROM 5)::integer) FROM proyek));
SELECT setval('seq_kode_transaksi', (SELECT max(substring(kode FROM 5)::integer) FROM transaksi_barang));
SELECT setval('seq_kode_insiden',   (SELECT max(substring(kode FROM 5)::integer) FROM laporan_insiden));

-- Rekonsiliasi dengan seed session; selisih membatalkan seluruh seed.
DO $$
DECLARE
    v_jumlah  integer;
    v_teks    text;
BEGIN
    PERFORM 1 WHERE (SELECT count(*) FROM pengguna) = 7
                AND (SELECT count(*) FROM kategori_perlengkapan) = 5
                AND (SELECT count(*) FROM perlengkapan) = 23
                AND (SELECT count(*) FROM proyek) = 10
                AND (SELECT count(*) FROM anggota_proyek) = 20
                AND (SELECT count(*) FROM preset_perlengkapan) = 4
                AND (SELECT count(*) FROM detail_preset) = 21
                AND (SELECT count(*) FROM kebutuhan_proyek) = 28
                AND (SELECT count(*) FROM alokasi_barang) = 40
                AND (SELECT count(*) FROM persiapan_proyek) = 10
                AND (SELECT count(*) FROM transaksi_barang) = 11
                AND (SELECT count(*) FROM detail_transaksi) = 42
                AND (SELECT count(*) FROM laporan_insiden) = 3
                AND (SELECT count(*) FROM riwayat_aktivitas) = 19;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Rekonsiliasi gagal: jumlah baris tidak sesuai seed session';
    END IF;

    SELECT sum(jumlah_keluar) INTO v_jumlah FROM v_saldo_alokasi;
    IF v_jumlah <> 23 THEN RAISE EXCEPTION 'Rekonsiliasi gagal: total keluar % (harus 23)', v_jumlah; END IF;
    SELECT sum(jumlah_kembali) INTO v_jumlah FROM v_saldo_alokasi;
    IF v_jumlah <> 19 THEN RAISE EXCEPTION 'Rekonsiliasi gagal: total kembali % (harus 19)', v_jumlah; END IF;
    SELECT string_agg(alokasi_id::text, ',' ORDER BY alokasi_id) INTO v_teks FROM v_saldo_alokasi WHERE sisa > 0;
    IF v_teks IS DISTINCT FROM '8,25,26,27' THEN
        RAISE EXCEPTION 'Rekonsiliasi gagal: alokasi bersaldo % (harus 8,25,26,27)', v_teks;
    END IF;

    SELECT count(*) INTO v_jumlah FROM alokasi_barang WHERE diperiksa;
    IF v_jumlah <> 32 THEN RAISE EXCEPTION 'Rekonsiliasi gagal: % centang diperiksa (harus 32)', v_jumlah; END IF;

    SELECT count(*) INTO v_jumlah FROM v_proyek WHERE pic_id IS NULL;
    IF v_jumlah <> 0 THEN RAISE EXCEPTION 'Rekonsiliasi gagal: % proyek tanpa PIC', v_jumlah; END IF;

    IF format_kode('PRJ', (SELECT last_value + 1 FROM seq_kode_proyek)) <> 'PRJ-026'
       OR format_kode('TRX', (SELECT last_value + 1 FROM seq_kode_transaksi)) <> 'TRX-044'
       OR format_kode('INS', (SELECT last_value + 1 FROM seq_kode_insiden)) <> 'INS-013' THEN
        RAISE EXCEPTION 'Rekonsiliasi gagal: sequence kode tidak sesuai';
    END IF;

    RAISE NOTICE 'Rekonsiliasi seed OK: 14 tabel terisi, saldo 23 keluar / 19 kembali / sisa 4, 32 centang, PIC lengkap.';
END;
$$;

COMMIT;
