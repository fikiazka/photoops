-- PhotoOps × Supabase — langkah 1: schema privat `photoops` dan role aplikasi least privilege.
-- Jalankan SEKALI sebagai role admin Supabase (postgres) lewat Session Pooler dengan SSL:
--   psql "<koneksi admin>" -v ON_ERROR_STOP=1 -f database/supabase/01_persiapan.sql
-- Variabel opsional: -v target_db=postgres (bawaan) -v app_role=photoops_app (bawaan).
-- File ini tidak berisi password. Setelah semua langkah selesai, password role diset interaktif dari psql:
--   \password photoops_app
-- Schema `photoops` TIDAK dimasukkan ke "Exposed schemas" Data API, sehingga tidak bisa diakses lewat REST/GraphQL.

\set ON_ERROR_STOP on
\if :{?target_db}
\else
    \set target_db postgres
\endif
\if :{?app_role}
\else
    \set app_role photoops_app
\endif
\if :{?simulasi_lokal}
\else
    \set simulasi_lokal tidak
\endif

-- Pengaman: hanya untuk database tujuan yang disebut, hanya di server Supabase (kecuali simulasi lokal yang disengaja),
-- dan hanya bila schema/role belum ada (tidak menimpa apa pun).
SET photoops.target_db = :'target_db';
SET photoops.app_role = :'app_role';
SET photoops.simulasi_lokal = :'simulasi_lokal';
DO $$
BEGIN
    IF current_database() <> current_setting('photoops.target_db') THEN
        RAISE EXCEPTION 'BERHENTI: terhubung ke database %, bukan %.', current_database(), current_setting('photoops.target_db');
    END IF;
    IF current_database() IN ('photoops_si3_dev', 'photoops_si3_test') THEN
        RAISE EXCEPTION 'BERHENTI: skrip Supabase tidak boleh dijalankan di database lokal %.', current_database();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'supabase_admin') AND current_setting('photoops.simulasi_lokal') <> 'ya' THEN
        RAISE EXCEPTION 'BERHENTI: server ini bukan Supabase. Periksa host koneksi (untuk simulasi lokal: -v simulasi_lokal=ya).';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'supabase_admin') AND current_user <> 'postgres' THEN
        RAISE EXCEPTION 'BERHENTI: login sebagai %, bukan postgres.', current_user;
    END IF;
    IF current_setting('photoops.app_role') !~ '^photoops_[a-z0-9_]+$' THEN
        RAISE EXCEPTION 'BERHENTI: nama role aplikasi % harus berawalan photoops_ (huruf kecil, angka, garis bawah).', current_setting('photoops.app_role');
    END IF;
    IF EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'photoops') THEN
        RAISE EXCEPTION 'BERHENTI: schema photoops sudah ada. Pakai database/supabase/99_rollback.sql dulu bila ingin mengulang.';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_setting('photoops.app_role')) THEN
        RAISE EXCEPTION 'BERHENTI: role % sudah ada.', current_setting('photoops.app_role');
    END IF;
END $$;

BEGIN;

-- Schema privat milik admin (postgres). PUBLIC tidak mendapat hak apa pun; role aplikasi hanya USAGE (tanpa CREATE/DDL).
CREATE SCHEMA photoops;
COMMENT ON SCHEMA photoops IS 'PhotoOps (PHP Native). Privat: tidak diekspos lewat Supabase Data API.';
REVOKE ALL ON SCHEMA photoops FROM PUBLIC;
-- Role Data API Supabase tidak boleh memakai schema ini sejak detik pertama, termasuk bila ada default privileges
-- untuk schema baru. Dengan begitu tabel yang sudah dibuat saat langkah 2 terputus di tengah tetap tidak terjangkau.
DO $$
DECLARE
    r text;
BEGIN
    FOREACH r IN ARRAY ARRAY['anon', 'authenticated', 'service_role'] LOOP
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
            EXECUTE format('REVOKE ALL ON SCHEMA photoops FROM %I', r);
        END IF;
    END LOOP;
END $$;

-- Role aplikasi: boleh login, tanpa hak superuser/membuat role/database/replikasi/melewati RLS.
CREATE ROLE :"app_role" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 20;
GRANT CONNECT ON DATABASE :"target_db" TO :"app_role";
GRANT USAGE ON SCHEMA photoops TO :"app_role";

-- Query aplikasi tidak memakai nama schema, jadi search_path role diarahkan ke photoops (tanpa perubahan kode PHP).
-- Zona waktu WIB sama dengan database lokal; batas waktu melindungi pooler dari query/transaksi yang menggantung.
ALTER ROLE :"app_role" SET search_path = photoops;
ALTER ROLE :"app_role" SET timezone = 'Asia/Jakarta';
ALTER ROLE :"app_role" SET statement_timeout = '15s';
ALTER ROLE :"app_role" SET idle_in_transaction_session_timeout = '60s';

COMMIT;

\echo 'Langkah 1 selesai: schema photoops dan role' :app_role 'dibuat (tanpa password).'
