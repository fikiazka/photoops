-- PhotoOps x Supabase - langkah 6: verifikasi READ-ONLY setelah langkah 1-5 (termasuk \password).
--   psql "<koneksi admin>" -v ON_ERROR_STOP=1 -f database/supabase/05_verifikasi.sql
-- Setiap pemeriksaan dicetak OK/GAGAL; bila ada yang GAGAL, skrip berakhir dengan error (exit code bukan 0).
-- Angka acuan sama dengan database lokal (31 FK, 14 UNIQUE, 27 CHECK, 17 sequence, 34 index) ditambah tabel sesi_php.

\set ON_ERROR_STOP on
\if :{?app_role}
\else
    \set app_role photoops_app
\endif

BEGIN READ ONLY;
SET LOCAL photoops.app_role = :'app_role';
SET LOCAL client_min_messages = notice;

DO $$
DECLARE
    app text := current_setting('photoops.app_role');
    r record;
    gagal int := 0;
    seed_ada boolean;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = app) OR to_regclass('photoops.pengguna') IS NULL THEN
        RAISE EXCEPTION 'GAGAL: role % atau schema/tabel photoops belum ada.', app;
    END IF;
    seed_ada := EXISTS (SELECT 1 FROM photoops.pengguna);
    FOR r IN
        WITH objek AS (
            SELECT c.relname, c.relkind, c.relacl, c.reloptions FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'photoops'
        ), kendala AS (
            SELECT c.contype FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = 'photoops'
        ), peran AS (
            SELECT * FROM pg_roles WHERE rolname = app
        ), hak_luar AS (
            -- Hak untuk PUBLIC (grantee 0) dan role Data API Supabase pada schema dan objek photoops.
            SELECT a.grantee FROM pg_namespace n, aclexplode(n.nspacl) a WHERE n.nspname = 'photoops'
            UNION ALL
            SELECT a.grantee FROM objek o, aclexplode(o.relacl) a
        )
        SELECT * FROM (VALUES
            ('15 tabel (14 bisnis + sesi_php)', (SELECT COUNT(*) FROM objek WHERE relkind = 'r') = 15,
                (SELECT string_agg(relname, ', ' ORDER BY relname) FROM objek WHERE relkind = 'r')),
            ('view v_saldo_alokasi, security_invoker', EXISTS (SELECT 1 FROM objek WHERE relname = 'v_saldo_alokasi' AND relkind = 'v'
                AND 'security_invoker=true' = ANY (reloptions)), ''),
            ('17 sequence (3 kode dokumen + 14 identity)', (SELECT COUNT(*) FROM objek WHERE relkind = 'S') = 17
                AND (SELECT COUNT(*) FROM objek WHERE relname IN ('seq_kode_proyek', 'seq_kode_transaksi', 'seq_kode_insiden')) = 3,
                (SELECT COUNT(*) FROM objek WHERE relkind = 'S')::text),
            ('31 FOREIGN KEY, 14 UNIQUE, 28 CHECK', (SELECT COUNT(*) FROM kendala WHERE contype = 'f') = 31
                AND (SELECT COUNT(*) FROM kendala WHERE contype = 'u') = 14 AND (SELECT COUNT(*) FROM kendala WHERE contype = 'c') = 28,
                format('FK %s, UNIQUE %s, CHECK %s', (SELECT COUNT(*) FROM kendala WHERE contype = 'f'),
                    (SELECT COUNT(*) FROM kendala WHERE contype = 'u'), (SELECT COUNT(*) FROM kendala WHERE contype = 'c'))),
            ('36 index', (SELECT COUNT(*) FROM objek WHERE relkind = 'i') = 36, (SELECT COUNT(*) FROM objek WHERE relkind = 'i')::text),
            -- RLS tanpa policy membuat role aplikasi (bukan pemilik tabel) melihat 0 baris; event trigger auto-RLS di
            -- Supabase hanya untuk schema public, tetapi tetap dipastikan di sini.
            ('RLS tidak aktif di tabel photoops', NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'photoops' AND c.relrowsecurity), ''),
            ('role aplikasi: login, bukan superuser/createrole/createdb/replication/bypassrls',
                (SELECT rolcanlogin AND NOT rolsuper AND NOT rolcreaterole AND NOT rolcreatedb AND NOT rolreplication AND NOT rolbypassrls FROM peran), app),
            ('role aplikasi: search_path photoops, zona waktu Asia/Jakarta',
                (SELECT rolconfig @> ARRAY['search_path=photoops', 'TimeZone=Asia/Jakarta'] FROM peran),
                (SELECT array_to_string(rolconfig, '; ') FROM peran)),
            ('role aplikasi tanpa hak CREATE (DDL) di schema photoops', NOT has_schema_privilege(app, 'photoops', 'CREATE'), ''),
            ('riwayat & transaksi hanya ditambah (tanpa UPDATE/DELETE)',
                has_table_privilege(app, 'photoops.riwayat_aktivitas', 'INSERT') AND NOT has_table_privilege(app, 'photoops.riwayat_aktivitas', 'UPDATE')
                AND NOT has_table_privilege(app, 'photoops.riwayat_aktivitas', 'DELETE') AND NOT has_table_privilege(app, 'photoops.transaksi_barang', 'DELETE')
                AND NOT has_table_privilege(app, 'photoops.detail_transaksi', 'UPDATE'), ''),
            ('hak tabel inti dan sesi_php sesuai', has_table_privilege(app, 'photoops.proyek', 'DELETE')
                AND has_table_privilege(app, 'photoops.pengguna', 'DELETE') AND NOT has_table_privilege(app, 'photoops.kategori_perlengkapan', 'INSERT')
                AND has_table_privilege(app, 'photoops.sesi_php', 'DELETE') AND has_table_privilege(app, 'photoops.v_saldo_alokasi', 'SELECT')
                AND has_sequence_privilege(app, 'photoops.seq_kode_insiden', 'USAGE'), ''),
            ('tidak ada hak untuk PUBLIC/anon/authenticated/service_role di photoops',
                NOT EXISTS (SELECT 1 FROM hak_luar WHERE grantee = 0
                    OR grantee IN (SELECT oid FROM pg_roles WHERE rolname IN ('anon', 'authenticated', 'service_role'))),
                (SELECT COUNT(*) FROM hak_luar WHERE grantee = 0 OR grantee IN (SELECT oid FROM pg_roles WHERE rolname IN ('anon', 'authenticated', 'service_role')))::text),
            ('seed demo: 7 pengguna (3 peran), 5 kategori, 23 unit, 10 proyek', NOT seed_ada OR (
                (SELECT COUNT(*) FROM photoops.pengguna) = 7 AND (SELECT COUNT(DISTINCT peran) FROM photoops.pengguna) = 3
                AND (SELECT COUNT(*) FROM photoops.kategori_perlengkapan) = 5 AND (SELECT COUNT(*) FROM photoops.perlengkapan) = 23
                AND (SELECT COUNT(*) FROM photoops.proyek) = 10), CASE WHEN seed_ada THEN '' ELSE 'dilewati (belum ada seed)' END),
            ('tidak ada akun dengan kata sandi seed lama', NOT EXISTS (SELECT 1 FROM photoops.pengguna
                WHERE password_hash = '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S'), 'langkah 4 (buat_sandi_demo.php)'),
            ('sequence kode dokumen sejajar dengan data', NOT seed_ada OR (
                (SELECT last_value FROM photoops.seq_kode_proyek) >= (SELECT MAX(CAST(SUBSTRING(kode FROM 5) AS INTEGER)) FROM photoops.proyek)
                AND (SELECT last_value FROM photoops.seq_kode_transaksi) >= (SELECT MAX(CAST(SUBSTRING(kode FROM 5) AS INTEGER)) FROM photoops.transaksi_barang)
                AND (SELECT last_value FROM photoops.seq_kode_insiden) >= (SELECT MAX(CAST(SUBSTRING(kode FROM 5) AS INTEGER)) FROM photoops.laporan_insiden)), '')
        ) AS t(cek, ok, info)
    LOOP
        RAISE NOTICE '%  %', CASE WHEN r.ok THEN 'OK   ' ELSE 'GAGAL' END,
            r.cek || CASE WHEN COALESCE(r.info, '') = '' THEN '' ELSE ': ' || r.info END;
        IF NOT COALESCE(r.ok, false) THEN
            gagal := gagal + 1;
        END IF;
    END LOOP;
    IF gagal > 0 THEN
        RAISE EXCEPTION 'Verifikasi GAGAL: % pemeriksaan tidak terpenuhi.', gagal;
    END IF;
    RAISE NOTICE 'Verifikasi selesai: semua pemeriksaan OK.';
END $$;

ROLLBACK;
