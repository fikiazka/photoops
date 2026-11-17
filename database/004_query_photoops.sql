-- PhotoOps — kueri operasional utama (hanya membaca data).
-- Disiapkan untuk modul PHP; saat ini aplikasi masih memakai data session, jadi belum ada yang dipanggil aplikasi.
-- Angka seperti DATE '2026-11-07' adalah contoh parameter (di PHP: :parameter); 2026-11-07 = "hari ini" data contoh.
--   psql -d photoops_si3_dev -f database/004_query_photoops.sql

-- Q1 Daftar proyek beserta PIC (halaman Proyek).
SELECT p.kode,
       p.nama,
       p.status,
       p.mulai,
       pic.nama AS pic
FROM proyek p
JOIN pengguna pic ON pic.id = p.pic_id
ORDER BY p.mulai DESC;

-- Q2 Perlengkapan Studio beserta kategorinya (halaman Perlengkapan).
SELECT kp.nama AS kategori,
       pl.kode_unit,
       pl.nama,
       pl.merek,
       pl.kondisi,
       pl.status_aktif
FROM perlengkapan pl
JOIN kategori_perlengkapan kp ON kp.id = pl.kategori_id
ORDER BY kp.nama, pl.kode_unit;

-- Q3 Kebutuhan proyek aktif yang belum terpenuhi (alokasi yang dibatalkan tidak dihitung).
SELECT p.kode,
       k.nama                     AS kebutuhan,
       k.jumlah                   AS dibutuhkan,
       COALESCE(SUM(a.jumlah), 0) AS dialokasikan
FROM kebutuhan_proyek k
JOIN proyek p              ON p.id = k.proyek_id
LEFT JOIN alokasi_barang a ON a.kebutuhan_id = k.id
                          AND a.status <> 'Dibatalkan'
WHERE p.status NOT IN ('Selesai', 'Dibatalkan')
GROUP BY p.kode, k.id, k.nama, k.jumlah
HAVING COALESCE(SUM(a.jumlah), 0) < k.jumlah
ORDER BY p.kode;

-- Q4 Barang yang belum kembali dari proyek (sisa = keluar - kembali, dihitung oleh view v_saldo_alokasi).
SELECT p.kode                                AS proyek,
       COALESCE(pl.kode_unit, a.nama_barang) AS barang,
       s.jumlah_keluar,
       s.jumlah_kembali,
       s.sisa,
       s.status_kembali
FROM v_saldo_alokasi s
JOIN alokasi_barang a     ON a.id = s.alokasi_id
JOIN proyek p             ON p.id = s.proyek_id
LEFT JOIN perlengkapan pl ON pl.id = a.perlengkapan_id
WHERE s.sisa > 0
ORDER BY p.kode;

-- Q5 Rental yang melewati jatuh tempo vendor dan belum dikembalikan ke vendor.
-- Kosong pada 2026-11-07; dengan tanggal 2026-11-10 muncul Sony FX3 PRJ-020 (terlambat 1 hari).
SELECT p.kode,
       a.nama_barang,
       a.vendor,
       a.jatuh_tempo_vendor,
       DATE '2026-11-07' - a.jatuh_tempo_vendor AS hari_terlambat
FROM alokasi_barang a
JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
JOIN proyek p           ON p.id = k.proyek_id
WHERE a.sumber = 'Rental'
  AND a.status <> 'Dibatalkan'
  AND a.dikembalikan_vendor_pada IS NULL
  AND a.jatuh_tempo_vendor < DATE '2026-11-07';

-- Q6 Riwayat aktivitas terbaru (pengguna kosong = Sistem).
SELECT r.waktu,
       COALESCE(u.nama, 'Sistem') AS oleh,
       r.aksi,
       r.objek,
       r.keterangan
FROM riwayat_aktivitas r
LEFT JOIN pengguna u ON u.id = r.pengguna_id
ORDER BY r.waktu DESC
LIMIT 20;

-- Q7 Cek bentrok jadwal sebelum unit Studio dialokasikan.
-- Contoh: CAM-001 (id 1) untuk PRJ-022 (id 4) pada 11–12 Nov. Hasil kosong = boleh dialokasikan.
-- Aturan ini melibatkan banyak baris, jadi tidak bisa dijaga CHECK. PHP menjalankannya di dalam transaksi
-- setelah mengunci baris unit (SELECT ... FROM perlengkapan WHERE id = ... FOR UPDATE), lalu baru INSERT.
SELECT p.kode,
       a.tgl_mulai,
       a.tgl_selesai
FROM alokasi_barang a
JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
JOIN proyek p           ON p.id = k.proyek_id
WHERE a.perlengkapan_id = 1
  AND a.status <> 'Dibatalkan'
  AND k.proyek_id <> 4
  AND a.tgl_mulai   <= DATE '2026-11-12'
  AND a.tgl_selesai >= DATE '2026-11-11'
  AND NOT EXISTS (SELECT 1
                  FROM v_saldo_alokasi s
                  WHERE s.alokasi_id = a.id
                    AND s.sisa = 0);
