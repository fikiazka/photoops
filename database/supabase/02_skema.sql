-- PhotoOps × Supabase — langkah 2: 14 tabel bisnis + tabel sesi_php di schema `photoops`, lalu hak akses role aplikasi.
-- Jalankan sebagai admin Supabase setelah 01_persiapan.sql, dari folder proyek (memakai \ir):
--   psql "<koneksi admin>" -v ON_ERROR_STOP=1 -f database/supabase/02_skema.sql
-- DDL tidak disalin: database/001_schema.sql dipakai apa adanya dengan search_path = photoops, sehingga skema lokal dan
-- Supabase tetap satu sumber.

\set ON_ERROR_STOP on
\if :{?target_db}
\else
    \set target_db postgres
\endif
\if :{?app_role}
\else
    \set app_role photoops_app
\endif

SET photoops.target_db = :'target_db';
SET photoops.app_role = :'app_role';
DO $$
BEGIN
    IF current_database() <> current_setting('photoops.target_db') THEN
        RAISE EXCEPTION 'BERHENTI: terhubung ke database %, bukan %.', current_database(), current_setting('photoops.target_db');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'photoops') THEN
        RAISE EXCEPTION 'BERHENTI: schema photoops belum ada. Jalankan 01_persiapan.sql dulu.';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'photoops') THEN
        RAISE EXCEPTION 'BERHENTI: schema photoops sudah berisi objek. Langkah 2 hanya untuk schema kosong.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_setting('photoops.app_role')) THEN
        RAISE EXCEPTION 'BERHENTI: role % belum ada. Jalankan 01_persiapan.sql dulu.', current_setting('photoops.app_role');
    END IF;
END $$;

-- Semua nama tabel/sequence/view tanpa schema di file di bawah dibuat di photoops.
SET search_path TO photoops;

\ir ../001_schema.sql
\ir ../sesi_php.sql
\ir hak_akses_supabase.sql

\echo 'Langkah 2 selesai: 15 tabel, 3 sequence kode dokumen, view v_saldo_alokasi, dan hak akses' :app_role 'dibuat di schema photoops.'
