-- PhotoOps — pembuatan role aplikasi dan database development.
-- Jalankan SEKALI sebagai superuser, terhubung ke database postgres:
--   psql -U postgres -d postgres -v ON_ERROR_STOP=1 -f database/pendukung/000_setup.sql
-- Password role tidak ditulis di file ini. Setelah file ini selesai, setel password secara interaktif:
--   psql -U postgres -d postgres -c "\password photoops_si3_app"
-- lalu simpan password itu di config/database.local.php (diabaikan Git).

-- Role aplikasi: boleh login, bukan superuser, tidak bisa membuat database atau role lain.
CREATE ROLE photoops_si3_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;

-- Database development dengan encoding UTF-8 dan zona waktu WIB.
CREATE DATABASE photoops_si3_dev WITH ENCODING 'UTF8' TEMPLATE template0;
ALTER DATABASE photoops_si3_dev SET timezone TO 'Asia/Jakarta';

-- Hanya role aplikasi (dan superuser) yang boleh terhubung ke database ini.
REVOKE CONNECT, TEMPORARY ON DATABASE photoops_si3_dev FROM PUBLIC;
GRANT CONNECT ON DATABASE photoops_si3_dev TO photoops_si3_app;
