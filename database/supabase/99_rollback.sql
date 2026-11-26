-- PhotoOps x Supabase - ROLLBACK: menghapus SELURUH schema `photoops` (tabel, data, sequence, view, session) dan role aplikasi.
-- DESTRUKTIF. Hanya untuk mengulang migrasi yang gagal/salah sebelum dipakai, atau membatalkan deployment Supabase.
-- Tidak menyentuh schema bawaan Supabase (public, auth, storage, dst.): skrip menolak berjalan bila ada objek di luar
-- schema photoops yang bergantung padanya (yang akan ikut terhapus oleh CASCADE).
-- Wajib konfirmasi eksplisit:
--   psql "<koneksi admin>" -v ON_ERROR_STOP=1 -v konfirmasi=HAPUS-PHOTOOPS -f database/supabase/99_rollback.sql
-- Data yang sudah dipakai sungguhan harus dibackup dulu (misalnya pg_dump --schema=photoops) sebelum rollback.

\set ON_ERROR_STOP on
\if :{?target_db}
\else
    \set target_db postgres
\endif
\if :{?app_role}
\else
    \set app_role photoops_app
\endif
\if :{?konfirmasi}
\else
    \set konfirmasi ''
\endif
\if :{?simulasi_lokal}
\else
    \set simulasi_lokal tidak
\endif

SET photoops.target_db = :'target_db';
SET photoops.app_role = :'app_role';
SET photoops.konfirmasi = :'konfirmasi';
SET photoops.simulasi_lokal = :'simulasi_lokal';
DO $$
DECLARE
    app  text := current_setting('photoops.app_role');
    ns   oid  := to_regnamespace('photoops');
    luar text;
BEGIN
    IF current_setting('photoops.konfirmasi') <> 'HAPUS-PHOTOOPS' THEN
        RAISE EXCEPTION 'BERHENTI: rollback butuh -v konfirmasi=HAPUS-PHOTOOPS. Tidak ada yang dihapus.';
    END IF;
    IF current_database() <> current_setting('photoops.target_db') THEN
        RAISE EXCEPTION 'BERHENTI: terhubung ke database %, bukan %.', current_database(), current_setting('photoops.target_db');
    END IF;
    IF current_database() IN ('photoops_si3_dev', 'photoops_si3_test') THEN
        RAISE EXCEPTION 'BERHENTI: rollback Supabase tidak boleh dijalankan di database lokal %.', current_database();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'supabase_admin') AND current_setting('photoops.simulasi_lokal') <> 'ya' THEN
        RAISE EXCEPTION 'BERHENTI: server ini bukan Supabase. Periksa host koneksi (untuk simulasi lokal: -v simulasi_lokal=ya).';
    END IF;
    -- Hanya role aplikasi PhotoOps yang boleh dihapus: nama berawalan photoops_ dan tanpa hak istimewa apa pun.
    -- Ini mencegah salah ketik -v app_role=... menghapus role bawaan Supabase (postgres, authenticator, anon, dst.).
    IF app !~ '^photoops_[a-z0-9_]+$' THEN
        RAISE EXCEPTION 'BERHENTI: % bukan nama role aplikasi PhotoOps (harus berawalan photoops_).', app;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = app
               AND (rolsuper OR rolcreaterole OR rolcreatedb OR rolreplication OR rolbypassrls)) THEN
        RAISE EXCEPTION 'BERHENTI: role % punya hak istimewa; bukan role least privilege buatan 01_persiapan.sql.', app;
    END IF;
    -- Objek di luar schema photoops (view/FK/fungsi/ekstensi di public, dst.) yang bergantung pada objek photoops
    -- akan ikut terhapus oleh DROP SCHEMA ... CASCADE. Bila ada, rollback ditolak dan objeknya ditampilkan.
    IF ns IS NOT NULL THEN
        WITH dalam AS (
            SELECT 'pg_class'::regclass AS kelas, oid FROM pg_class WHERE relnamespace = ns
            UNION ALL SELECT 'pg_type'::regclass, oid FROM pg_type WHERE typnamespace = ns
            UNION ALL SELECT 'pg_proc'::regclass, oid FROM pg_proc WHERE pronamespace = ns
            UNION ALL SELECT 'pg_namespace'::regclass, ns
        ), tergantung AS (
            SELECT DISTINCT d.classid, d.objid, d.objsubid,
                CASE d.classid
                    WHEN 'pg_class'::regclass THEN (SELECT relnamespace FROM pg_class WHERE oid = d.objid)
                    WHEN 'pg_type'::regclass THEN (SELECT typnamespace FROM pg_type WHERE oid = d.objid)
                    WHEN 'pg_proc'::regclass THEN (SELECT pronamespace FROM pg_proc WHERE oid = d.objid)
                    WHEN 'pg_constraint'::regclass THEN (SELECT connamespace FROM pg_constraint WHERE oid = d.objid)
                    WHEN 'pg_rewrite'::regclass THEN (SELECT c.relnamespace FROM pg_rewrite r JOIN pg_class c ON c.oid = r.ev_class WHERE r.oid = d.objid)
                    WHEN 'pg_attrdef'::regclass THEN (SELECT c.relnamespace FROM pg_attrdef a JOIN pg_class c ON c.oid = a.adrelid WHERE a.oid = d.objid)
                    WHEN 'pg_trigger'::regclass THEN (SELECT c.relnamespace FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE t.oid = d.objid)
                    WHEN 'pg_policy'::regclass THEN (SELECT c.relnamespace FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid WHERE p.oid = d.objid)
                    WHEN 'pg_default_acl'::regclass THEN (SELECT defaclnamespace FROM pg_default_acl WHERE oid = d.objid)
                END AS ns_objek
            FROM pg_depend d JOIN dalam ON d.refclassid = dalam.kelas AND d.refobjid = dalam.oid
            -- Dependensi internal (deptype 'i', misalnya tabel TOAST di pg_toast) adalah bagian dari tabel itu sendiri.
            WHERE d.deptype IN ('n', 'a')
        )
        SELECT string_agg(pg_describe_object(classid, objid, objsubid), '; ') INTO luar
        FROM tergantung WHERE ns_objek IS DISTINCT FROM ns;
        IF luar IS NOT NULL THEN
            RAISE EXCEPTION 'BERHENTI: objek di luar schema photoops bergantung padanya dan akan ikut terhapus: %. Tidak ada yang dihapus.', luar;
        END IF;
    END IF;
END $$;

-- Sesi aplikasi yang masih terhubung (termasuk koneksi yang ditahan pooler) diputus. Bila admin tidak punya hak
-- memutus sesi, rollback tetap berlanjut: sesi lama berakhir sendiri karena role dan schema-nya sudah tidak ada.
DO $$
DECLARE
    n int;
BEGIN
    SELECT COUNT(pg_terminate_backend(pid)) INTO n FROM pg_stat_activity WHERE usename = current_setting('photoops.app_role');
    RAISE NOTICE '% sesi role aplikasi diputus.', n;
EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'Tidak berhak memutus sesi role aplikasi; dilanjutkan.';
END $$;

-- Satu transaksi: bila DROP ROLE gagal (misalnya role masih punya hak di objek lain), schema juga tidak jadi dihapus.
BEGIN;
DROP SCHEMA IF EXISTS photoops CASCADE;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_setting('photoops.app_role')) THEN
        EXECUTE format('REVOKE ALL ON DATABASE %I FROM %I', current_database(), current_setting('photoops.app_role'));
        EXECUTE format('DROP ROLE %I', current_setting('photoops.app_role'));
    END IF;
END $$;
COMMIT;

\echo 'Rollback selesai: schema photoops dan role' :app_role 'dihapus.'
