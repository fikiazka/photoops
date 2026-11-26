-- PhotoOps × Supabase — langkah 3: data demo PhotoOps (7 akun untuk tiga peran, inventaris, proyek, transaksi contoh).
-- Jalankan sebagai admin Supabase setelah 02_skema.sql, dari folder proyek:
--   psql "<koneksi admin>" -v ON_ERROR_STOP=1 -f database/supabase/03_seed_demo.sql
-- Sumber datanya database/002_seed_demo.sql (data contoh tetap), BUKAN salinan database development.
-- Akun demo di file sumber memakai kata sandi lama yang diketahui umum. Langkah 4 (buat_sandi_demo.php) WAJIB
-- dijalankan sesudahnya; role aplikasi belum bisa login (belum punya password) sampai langkah itu selesai.

\set ON_ERROR_STOP on
\if :{?target_db}
\else
    \set target_db postgres
\endif

SET photoops.target_db = :'target_db';
DO $$
BEGIN
    IF current_database() <> current_setting('photoops.target_db') THEN
        RAISE EXCEPTION 'BERHENTI: terhubung ke database %, bukan %.', current_database(), current_setting('photoops.target_db');
    END IF;
    IF to_regclass('photoops.pengguna') IS NULL THEN
        RAISE EXCEPTION 'BERHENTI: tabel photoops.pengguna belum ada. Jalankan 02_skema.sql dulu.';
    END IF;
    IF EXISTS (SELECT 1 FROM photoops.pengguna) THEN
        RAISE EXCEPTION 'BERHENTI: photoops.pengguna sudah berisi data. Seed hanya untuk database kosong.';
    END IF;
END $$;

SET search_path TO photoops;

\ir ../002_seed_demo.sql

\echo 'Langkah 3 selesai. Lanjutkan langkah 4: php database/supabase/buat_sandi_demo.php | psql ...'
