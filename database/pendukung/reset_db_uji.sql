-- PhotoOps — membangun ulang DATABASE UJI photoops_si3_test dari 001 + 002 + 003 (untuk regression testing).
-- HANYA untuk database uji. Nama database ditulis langsung di file ini (bukan variabel atau parameter), sehingga tidak
-- mungkin mengenai database development (photoops_si3_dev) atau production.
--
-- Jalankan sebagai superuser dari folder proyek, terhubung ke database "postgres" (bukan ke database uji):
--   psql -U postgres -d postgres -v ON_ERROR_STOP=1 -f database/pendukung/reset_db_uji.sql
-- Password superuser diminta psql secara interaktif; jangan menuliskannya di file atau perintah.
--
-- Isi photoops_si3_test (termasuk proyek ARSIP-UJI dan transaksi uji) dihapus seluruhnya, lalu diisi seed contoh.

\set ON_ERROR_STOP on

SELECT current_user AS pengguna, current_database() AS terhubung_ke;

DROP DATABASE IF EXISTS photoops_si3_test WITH (FORCE);
CREATE DATABASE photoops_si3_test WITH ENCODING 'UTF8' TEMPLATE template0;
ALTER DATABASE photoops_si3_test SET timezone TO 'Asia/Jakarta';
REVOKE CONNECT, TEMPORARY ON DATABASE photoops_si3_test FROM PUBLIC;
GRANT CONNECT ON DATABASE photoops_si3_test TO photoops_si3_app;

\connect photoops_si3_test

-- Pengaman kedua: skema hanya dimuat bila koneksi benar-benar ke database uji.
SELECT current_database() = 'photoops_si3_test' AS target_uji \gset
\if :target_uji
\else
    \echo 'BERHENTI: koneksi bukan ke photoops_si3_test. Tidak ada skema yang dimuat.'
    \quit
\endif

\ir ../001_schema.sql
\ir ../002_seed_demo.sql
\ir 003_hak_akses.sql

SELECT current_database() AS database_uji,
       (SELECT COUNT(*) FROM perlengkapan) AS unit,
       (SELECT COUNT(*) FROM proyek) AS proyek,
       (SELECT COUNT(*) FROM laporan_insiden) AS insiden;
