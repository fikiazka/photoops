-- PhotoOps — uji struktur dan integritas data. Skrip psql (memakai \echo dan \if), bukan untuk pgAdmin.
-- Jalankan sebagai admin pada database uji yang dibuat dari 001 + 002 + 003:
--   psql -U postgres -d photoops_si3_test -f database/pendukung/005_uji_integritas.sql
-- Semua perubahan dibatalkan dengan ROLLBACK di akhir. Setiap baris hasil diawali LULUS atau GAGAL.
-- Bagian B sengaja memicu error; pesan ERROR dari PostgreSQL yang muncul adalah bukti penolakannya.

\set ON_ERROR_STOP off
\pset tuples_only on
\pset format unaligned

BEGIN;

\echo '== A. Struktur dan konsistensi data contoh'

SELECT CASE WHEN COUNT(*) = 14 THEN 'LULUS' ELSE 'GAGAL' END || '|A01 jumlah tabel = 14 (' || COUNT(*) || ')'
FROM information_schema.tables
WHERE table_schema = 'public' AND table_type = 'BASE TABLE';

SELECT CASE WHEN COUNT(*) = 1 THEN 'LULUS' ELSE 'GAGAL' END || '|A02 view v_saldo_alokasi ada'
FROM information_schema.views
WHERE table_schema = 'public' AND table_name = 'v_saldo_alokasi';

SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|A03 tidak ada function/trigger buatan sendiri (' || COUNT(*) || ')'
FROM (SELECT p.oid FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public'
      UNION ALL
      SELECT t.oid FROM pg_trigger t WHERE NOT t.tgisinternal) x;

SELECT CASE WHEN STRING_AGG(tabel || '=' || jumlah, ' ' ORDER BY tabel) =
            'alokasi_barang=41 anggota_proyek=10 detail_preset=21 detail_transaksi=44 kategori_perlengkapan=5 kebutuhan_proyek=29 laporan_insiden=3 pengguna=7 perlengkapan=23 persiapan_proyek=10 preset_perlengkapan=4 proyek=10 riwayat_aktivitas=21 transaksi_barang=13'
            THEN 'LULUS' ELSE 'GAGAL' END || '|A04 jumlah baris data contoh per tabel'
FROM (SELECT 'pengguna' AS tabel, COUNT(*) AS jumlah FROM pengguna
      UNION ALL SELECT 'kategori_perlengkapan', COUNT(*) FROM kategori_perlengkapan
      UNION ALL SELECT 'perlengkapan', COUNT(*) FROM perlengkapan
      UNION ALL SELECT 'proyek', COUNT(*) FROM proyek
      UNION ALL SELECT 'anggota_proyek', COUNT(*) FROM anggota_proyek
      UNION ALL SELECT 'preset_perlengkapan', COUNT(*) FROM preset_perlengkapan
      UNION ALL SELECT 'detail_preset', COUNT(*) FROM detail_preset
      UNION ALL SELECT 'kebutuhan_proyek', COUNT(*) FROM kebutuhan_proyek
      UNION ALL SELECT 'alokasi_barang', COUNT(*) FROM alokasi_barang
      UNION ALL SELECT 'persiapan_proyek', COUNT(*) FROM persiapan_proyek
      UNION ALL SELECT 'transaksi_barang', COUNT(*) FROM transaksi_barang
      UNION ALL SELECT 'detail_transaksi', COUNT(*) FROM detail_transaksi
      UNION ALL SELECT 'laporan_insiden', COUNT(*) FROM laporan_insiden
      UNION ALL SELECT 'riwayat_aktivitas', COUNT(*) FROM riwayat_aktivitas) x;

SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|A05 setiap proyek punya tepat satu baris persiapan (1:1)'
FROM proyek p
WHERE (SELECT COUNT(*) FROM persiapan_proyek ps WHERE ps.proyek_id = p.id) <> 1;

SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|A06 PIC setiap proyek berperan login us'
FROM proyek p JOIN pengguna u ON u.id = p.pic_id
WHERE u.peran <> 'us';

SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|A07 PIC tidak tercatat ganda sebagai Kru di proyek yang sama'
FROM anggota_proyek a JOIN proyek p ON p.id = a.proyek_id
WHERE a.pengguna_id = p.pic_id;

SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|A08 pemilik barang Milik Kru adalah PIC atau Kru proyeknya'
FROM alokasi_barang a
JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
JOIN proyek p           ON p.id = k.proyek_id
WHERE a.sumber = 'Milik Kru'
  AND a.pemilik_id <> p.pic_id
  AND NOT EXISTS (SELECT 1 FROM anggota_proyek ag WHERE ag.proyek_id = p.id AND ag.pengguna_id = a.pemilik_id);

SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|A09 setiap alokasi paling banyak satu kali keluar'
FROM (SELECT d.alokasi_id
      FROM detail_transaksi d JOIN transaksi_barang t ON t.id = d.transaksi_id
      WHERE t.jenis = 'Keluar'
      GROUP BY d.alokasi_id
      HAVING COUNT(*) > 1) x;

SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|A10 tidak ada alokasi yang kembali melebihi jumlah keluar'
FROM v_saldo_alokasi WHERE sisa < 0;

SELECT CASE WHEN SUM(jumlah_keluar) = 25 AND SUM(jumlah_kembali) = 20 THEN 'LULUS' ELSE 'GAGAL' END
       || '|A11 total keluar 25, kembali 20 (' || SUM(jumlah_keluar) || '/' || SUM(jumlah_kembali) || ')'
FROM v_saldo_alokasi;

SELECT CASE WHEN STRING_AGG(alokasi_id || ':' || sisa, ',' ORDER BY alokasi_id) = '8:1,25:1,26:1,27:1,41:1' THEN 'LULUS' ELSE 'GAGAL' END
       || '|A12 barang masih di luar: alokasi 8, 25, 26, 27, 41 (' || STRING_AGG(alokasi_id || ':' || sisa, ',' ORDER BY alokasi_id) || ')'
FROM v_saldo_alokasi WHERE sisa > 0;

SELECT CASE WHEN status_kembali = 'Kembali Sebagian' AND jumlah_keluar = 2 AND jumlah_kembali = 1 THEN 'LULUS' ELSE 'GAGAL' END
       || '|A13 alokasi 41: keluar 2, kembali 1 = Kembali Sebagian'
FROM v_saldo_alokasi WHERE alokasi_id = 41;

SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|A14 tidak ada unit Studio yang dialokasikan ganda pada jadwal yang beririsan'
FROM alokasi_barang a1
JOIN alokasi_barang a2 ON a2.perlengkapan_id = a1.perlengkapan_id AND a2.id > a1.id
WHERE a1.sumber = 'Studio' AND a1.status <> 'Dibatalkan' AND a2.status <> 'Dibatalkan'
  AND a1.tgl_mulai <= a2.tgl_selesai AND a1.tgl_selesai >= a2.tgl_mulai;

SELECT CASE WHEN STRING_AGG(nama || '=' || last_value, ' ' ORDER BY nama) = 'insiden=12 proyek=25 transaksi=45' THEN 'LULUS' ELSE 'GAGAL' END
       || '|A15 sequence kode siap: berikutnya PRJ-026, TRX-046, INS-013'
FROM (SELECT 'proyek' AS nama, last_value FROM seq_kode_proyek
      UNION ALL SELECT 'transaksi', last_value FROM seq_kode_transaksi
      UNION ALL SELECT 'insiden', last_value FROM seq_kode_insiden) x;

SELECT CASE WHEN NOT has_table_privilege('photoops_si3_app', 'transaksi_barang', 'UPDATE')
             AND NOT has_table_privilege('photoops_si3_app', 'transaksi_barang', 'DELETE')
             AND NOT has_table_privilege('photoops_si3_app', 'detail_transaksi', 'DELETE')
             AND NOT has_table_privilege('photoops_si3_app', 'riwayat_aktivitas', 'UPDATE')
             AND NOT has_table_privilege('photoops_si3_app', 'pengguna', 'DELETE')
             AND has_table_privilege('photoops_si3_app', 'proyek', 'DELETE')
             AND NOT (SELECT rolsuper FROM pg_roles WHERE rolname = 'photoops_si3_app')
            THEN 'LULUS' ELSE 'GAGAL' END || '|A16 hak akses role aplikasi minimum (histori tidak bisa diubah/dihapus, bukan superuser)';

\echo '== B. Perintah yang harus ditolak database'

SAVEPOINT uji;
UPDATE pengguna SET peran = 'xx' WHERE id = 3;
\if :ERROR
\echo 'LULUS|B01 peran login di luar sa/pi/us ditolak (CHECK)'
\else
\echo 'GAGAL|B01 peran login di luar sa/pi/us ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO pengguna (nama, username, email, password_hash, peran) VALUES ('Uji', 'budi.santoso', 'uji1@nicio.id', 'x', 'us');
\if :ERROR
\echo 'LULUS|B02 username ganda ditolak (UNIQUE)'
\else
\echo 'GAGAL|B02 username ganda ditolak (UNIQUE)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO pengguna (nama, username, email, password_hash, peran) VALUES ('Uji', 'uji.dua', 'sari@niciostudio.id', 'x', 'us');
\if :ERROR
\echo 'LULUS|B03 email ganda ditolak (UNIQUE)'
\else
\echo 'GAGAL|B03 email ganda ditolak (UNIQUE)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO perlengkapan (kode_unit, nama, merek, kategori_id) VALUES ('CAM-099', 'Sony A7 IV', 'Sony', 99);
\if :ERROR
\echo 'LULUS|B04 unit dengan kategori yang tidak ada ditolak (FOREIGN KEY)'
\else
\echo 'GAGAL|B04 unit dengan kategori yang tidak ada ditolak (FOREIGN KEY)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO perlengkapan (kode_unit, nama, merek, kategori_id) VALUES ('CAM-001', 'Sony A7 IV', 'Sony', 1);
\if :ERROR
\echo 'LULUS|B05 kode unit ganda ditolak (UNIQUE)'
\else
\echo 'GAGAL|B05 kode unit ganda ditolak (UNIQUE)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE perlengkapan SET kondisi = 'Hilang' WHERE id = 1;
\if :ERROR
\echo 'LULUS|B06 kondisi unit di luar Baik/Maintenance/Rusak ditolak (CHECK)'
\else
\echo 'GAGAL|B06 kondisi unit di luar Baik/Maintenance/Rusak ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE proyek SET selesai = mulai - INTERVAL '1 hour' WHERE id = 3;
\if :ERROR
\echo 'LULUS|B07 jadwal selesai sebelum mulai ditolak (CHECK)'
\else
\echo 'GAGAL|B07 jadwal selesai sebelum mulai ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE proyek SET deadline_persiapan = mulai + INTERVAL '1 hour' WHERE id = 3;
\if :ERROR
\echo 'LULUS|B08 deadline persiapan setelah acara mulai ditolak (CHECK)'
\else
\echo 'GAGAL|B08 deadline persiapan setelah acara mulai ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE proyek SET pic_id = 99 WHERE id = 3;
\if :ERROR
\echo 'LULUS|B09 PIC yang bukan pengguna terdaftar ditolak (FOREIGN KEY)'
\else
\echo 'GAGAL|B09 PIC yang bukan pengguna terdaftar ditolak (FOREIGN KEY)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO anggota_proyek (proyek_id, pengguna_id) VALUES (3, 6);
\if :ERROR
\echo 'LULUS|B10 kru ganda di proyek yang sama ditolak (UNIQUE)'
\else
\echo 'GAGAL|B10 kru ganda di proyek yang sama ditolak (UNIQUE)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO preset_perlengkapan (nama, dibuat_oleh, diubah_pada) VALUES ('Wedding Basic', 1, '2026-11-07 10:00');
\if :ERROR
\echo 'LULUS|B11 nama preset ganda ditolak (UNIQUE)'
\else
\echo 'GAGAL|B11 nama preset ganda ditolak (UNIQUE)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE detail_preset SET jumlah = 21 WHERE id = 1;
\if :ERROR
\echo 'LULUS|B12 jumlah item preset di atas 20 ditolak (CHECK)'
\else
\echo 'GAGAL|B12 jumlah item preset di atas 20 ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE kebutuhan_proyek SET jumlah = 0 WHERE id = 1;
\if :ERROR
\echo 'LULUS|B13 jumlah kebutuhan 0 ditolak (CHECK)'
\else
\echo 'GAGAL|B13 jumlah kebutuhan 0 ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO alokasi_barang (kebutuhan_id, sumber, tgl_mulai, tgl_selesai) VALUES (8, 'Studio', '2026-11-06', '2026-11-08');
\if :ERROR
\echo 'LULUS|B14 alokasi Studio tanpa unit ditolak (CHECK sumber)'
\else
\echo 'GAGAL|B14 alokasi Studio tanpa unit ditolak (CHECK sumber)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO alokasi_barang (kebutuhan_id, sumber, perlengkapan_id, jumlah, tgl_mulai, tgl_selesai) VALUES (8, 'Studio', 9, 2, '2026-11-06', '2026-11-08');
\if :ERROR
\echo 'LULUS|B15 alokasi Studio berjumlah 2 ditolak (satu baris = satu unit)'
\else
\echo 'GAGAL|B15 alokasi Studio berjumlah 2 ditolak (satu baris = satu unit)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO alokasi_barang (kebutuhan_id, sumber, nama_barang, tgl_mulai, tgl_selesai) VALUES (8, 'Rental', 'Sony FX6', '2026-11-06', '2026-11-08');
\if :ERROR
\echo 'LULUS|B16 alokasi Rental tanpa vendor ditolak (CHECK sumber)'
\else
\echo 'GAGAL|B16 alokasi Rental tanpa vendor ditolak (CHECK sumber)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO alokasi_barang (kebutuhan_id, sumber, nama_barang, tgl_mulai, tgl_selesai) VALUES (11, 'Milik Kru', 'SD Card', '2026-11-07', '2026-11-08');
\if :ERROR
\echo 'LULUS|B17 alokasi Milik Kru tanpa pemilik ditolak (CHECK sumber)'
\else
\echo 'GAGAL|B17 alokasi Milik Kru tanpa pemilik ditolak (CHECK sumber)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE alokasi_barang SET diperiksa = TRUE WHERE id = 18;
\if :ERROR
\echo 'LULUS|B18 centang checklist pada alokasi Dibatalkan ditolak (CHECK)'
\else
\echo 'GAGAL|B18 centang checklist pada alokasi Dibatalkan ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE alokasi_barang SET dikembalikan_vendor_pada = '2026-11-09 10:00', dikembalikan_vendor_oleh = 2, kondisi_kembali_vendor = 'Baik' WHERE id = 15;
\if :ERROR
\echo 'LULUS|B19 rental dikembalikan ke vendor sebelum diterima ditolak (CHECK)'
\else
\echo 'GAGAL|B19 rental dikembalikan ke vendor sebelum diterima ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE alokasi_barang SET sewa_mulai = '2026-11-06', jatuh_tempo_vendor = '2026-11-05' WHERE id = 15;
\if :ERROR
\echo 'LULUS|B20 jatuh tempo vendor sebelum sewa mulai ditolak (CHECK)'
\else
\echo 'GAGAL|B20 jatuh tempo vendor sebelum sewa mulai ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO persiapan_proyek (proyek_id) VALUES (3);
\if :ERROR
\echo 'LULUS|B21 baris persiapan kedua untuk proyek yang sama ditolak (UNIQUE, 1:1)'
\else
\echo 'GAGAL|B21 baris persiapan kedua untuk proyek yang sama ditolak (UNIQUE, 1:1)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO transaksi_barang (kode, proyek_id, jenis, waktu, petugas_id) VALUES ('TRX-901', 3, 'Pinjam', '2026-11-07 06:00', 2);
\if :ERROR
\echo 'LULUS|B22 jenis transaksi di luar Keluar/Kembali ditolak (CHECK)'
\else
\echo 'GAGAL|B22 jenis transaksi di luar Keluar/Kembali ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO transaksi_barang (kode, proyek_id, jenis, waktu, petugas_id) VALUES ('TRX-038', 3, 'Keluar', '2026-11-07 06:00', 2);
\if :ERROR
\echo 'LULUS|B23 kode transaksi ganda ditolak (UNIQUE)'
\else
\echo 'GAGAL|B23 kode transaksi ganda ditolak (UNIQUE)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO detail_transaksi (transaksi_id, alokasi_id, kondisi, jumlah) VALUES (13, 41, 'Rusak', 0);
\if :ERROR
\echo 'LULUS|B24 jumlah barang 0 pada detail transaksi ditolak (CHECK)'
\else
\echo 'GAGAL|B24 jumlah barang 0 pada detail transaksi ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO detail_transaksi (transaksi_id, alokasi_id, kondisi, jumlah) VALUES (13, 41, 'Baik', 1);
\if :ERROR
\echo 'LULUS|B25 baris detail ganda (transaksi, alokasi, kondisi sama) ditolak (UNIQUE)'
\else
\echo 'GAGAL|B25 baris detail ganda (transaksi, alokasi, kondisi sama) ditolak (UNIQUE)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO laporan_insiden (kode, perlengkapan_id, jenis, waktu, deskripsi, pelapor_id) VALUES ('INS-901', NULL, 'Rusak', '2026-11-07 10:00', 'FX3 rental jatuh', 2);
\if :ERROR
\echo 'LULUS|B26 insiden tanpa unit Studio ditolak (NOT NULL: insiden khusus Studio)'
\else
\echo 'GAGAL|B26 insiden tanpa unit Studio ditolak (NOT NULL: insiden khusus Studio)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
UPDATE laporan_insiden SET detail_id = 9 WHERE id IN (2, 3);
\if :ERROR
\echo 'LULUS|B27 satu detail pengembalian untuk dua insiden ditolak (UNIQUE)'
\else
\echo 'GAGAL|B27 satu detail pengembalian untuk dua insiden ditolak (UNIQUE)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
INSERT INTO riwayat_aktivitas (waktu, aksi) VALUES ('2026-11-07 10:00', 'Login');
\if :ERROR
\echo 'LULUS|B28 jenis aktivitas di luar daftar ditolak (CHECK)'
\else
\echo 'GAGAL|B28 jenis aktivitas di luar daftar ditolak (CHECK)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
DELETE FROM proyek WHERE id = 1;
\if :ERROR
\echo 'LULUS|B29 hapus proyek yang sudah punya transaksi ditolak (FOREIGN KEY)'
\else
\echo 'GAGAL|B29 hapus proyek yang sudah punya transaksi ditolak (FOREIGN KEY)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
DELETE FROM pengguna WHERE id = 2;
\if :ERROR
\echo 'LULUS|B30 hapus pengguna yang masih dirujuk ditolak (FOREIGN KEY)'
\else
\echo 'GAGAL|B30 hapus pengguna yang masih dirujuk ditolak (FOREIGN KEY)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
DELETE FROM perlengkapan WHERE id = 1;
\if :ERROR
\echo 'LULUS|B31 hapus unit yang pernah dialokasikan ditolak (FOREIGN KEY)'
\else
\echo 'GAGAL|B31 hapus unit yang pernah dialokasikan ditolak (FOREIGN KEY)'
\endif
ROLLBACK TO SAVEPOINT uji;

SAVEPOINT uji;
DELETE FROM alokasi_barang WHERE id = 1;
\if :ERROR
\echo 'LULUS|B32 hapus alokasi yang sudah bertransaksi ditolak (FOREIGN KEY)'
\else
\echo 'GAGAL|B32 hapus alokasi yang sudah bertransaksi ditolak (FOREIGN KEY)'
\endif
ROLLBACK TO SAVEPOINT uji;

\echo '== C. Aturan yang dijaga aplikasi dalam transaksi (dibuktikan dengan kueri yang dipakai aplikasi)'

-- C01: CAM-001 (id 1) diminta PRJ-022 (id 4) pada 7–8 Nov: bentrok dengan PRJ-021 (6–8 Nov).
SELECT CASE WHEN STRING_AGG(p.kode, ',') = 'PRJ-021' THEN 'LULUS' ELSE 'GAGAL' END
       || '|C01 cek bentrok jadwal menemukan PRJ-021 untuk CAM-001 pada 7–8 Nov'
FROM alokasi_barang a
JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
JOIN proyek p           ON p.id = k.proyek_id
WHERE a.perlengkapan_id = 1 AND a.status <> 'Dibatalkan' AND k.proyek_id <> 4
  AND a.tgl_mulai <= DATE '2026-11-08' AND a.tgl_selesai >= DATE '2026-11-07'
  AND NOT EXISTS (SELECT 1 FROM v_saldo_alokasi s WHERE s.alokasi_id = a.id AND s.sisa = 0);

-- C02: CAM-001 pada 11–12 Nov tidak bentrok dengan alokasi mana pun.
SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|C02 cek bentrok jadwal kosong untuk CAM-001 pada 11–12 Nov'
FROM alokasi_barang a
JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
WHERE a.perlengkapan_id = 1 AND a.status <> 'Dibatalkan' AND k.proyek_id <> 4
  AND a.tgl_mulai <= DATE '2026-11-12' AND a.tgl_selesai >= DATE '2026-11-11'
  AND NOT EXISTS (SELECT 1 FROM v_saldo_alokasi s WHERE s.alokasi_id = a.id AND s.sisa = 0);

-- C03: alokasi 25 (LGT-002) sudah pernah keluar, sehingga aplikasi menolak barang keluar kedua.
SELECT CASE WHEN EXISTS (SELECT 1 FROM v_saldo_alokasi WHERE alokasi_id = 25) THEN 'LULUS' ELSE 'GAGAL' END
       || '|C03 cek "sudah pernah keluar" bernilai benar untuk alokasi 25';

-- C04–C05: pengembalian alokasi 41 (sisa 1). Permintaan 2 unit melebihi sisa -> aplikasi menolak;
-- permintaan 1 unit diterima dan statusnya menjadi Sudah Kembali.
SELECT CASE WHEN 2 > sisa THEN 'LULUS' ELSE 'GAGAL' END || '|C04 permintaan kembali 2 unit melebihi sisa ' || sisa || ' (ditolak aplikasi)'
FROM v_saldo_alokasi WHERE alokasi_id = 41;
SELECT id FROM alokasi_barang WHERE id = 41 FOR UPDATE;
INSERT INTO transaksi_barang (id, kode, proyek_id, jenis, waktu, petugas_id, penerima, penyerah, penyerah_id)
VALUES (900, 'TRX-900', 7, 'Kembali', '2026-11-07 09:00', 2, 'Budi Santoso', 'Dimas Arya', 6);
INSERT INTO detail_transaksi (transaksi_id, alokasi_id, kondisi, jumlah) VALUES (900, 41, 'Baik', 1);
SELECT CASE WHEN sisa = 0 AND status_kembali = 'Sudah Kembali' THEN 'LULUS' ELSE 'GAGAL' END
       || '|C05 setelah 1 unit kembali: sisa 0, Sudah Kembali'
FROM v_saldo_alokasi WHERE alokasi_id = 41;

\echo '== D. Aturan hapus berantai'

DELETE FROM proyek WHERE id = 5;
SELECT CASE WHEN (SELECT COUNT(*) FROM kebutuhan_proyek WHERE proyek_id = 5) = 0
             AND (SELECT COUNT(*) FROM alokasi_barang WHERE id IN (39, 40)) = 0
             AND (SELECT COUNT(*) FROM anggota_proyek WHERE proyek_id = 5) = 0
             AND (SELECT COUNT(*) FROM persiapan_proyek WHERE proyek_id = 5) = 0
            THEN 'LULUS' ELSE 'GAGAL' END || '|D01 hapus proyek tanpa transaksi ikut menghapus kebutuhan, alokasi, kru, persiapan (CASCADE)';

INSERT INTO laporan_insiden (kode, perlengkapan_id, proyek_id, jenis, waktu, deskripsi, pelapor_id)
VALUES ('INS-900', 9, 4, 'Masalah Teknis', '2026-11-07 10:00', 'Ring zoom seret', 2);
DELETE FROM proyek WHERE id = 4;
SELECT CASE WHEN proyek_id IS NULL THEN 'LULUS' ELSE 'GAGAL' END || '|D02 insiden tetap ada, proyek_id menjadi kosong (SET NULL)'
FROM laporan_insiden WHERE kode = 'INS-900';

DELETE FROM preset_perlengkapan WHERE id = 1;
SELECT CASE WHEN COUNT(*) = 0 THEN 'LULUS' ELSE 'GAGAL' END || '|D03 hapus preset ikut menghapus isinya (CASCADE)'
FROM detail_preset WHERE preset_id = 1;

ROLLBACK;
