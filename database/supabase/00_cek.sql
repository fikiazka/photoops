-- PhotoOps x Supabase - langkah 0 (preflight) dan cek status sesudah setiap langkah. READ-ONLY: tidak mengubah apa pun.
--   Preflight sebelum langkah 1 (berhenti dengan error bila target tidak aman):
--     psql "<koneksi admin>" -v ON_ERROR_STOP=1 -v gerbang=awal -f database/supabase/00_cek.sql
--   Status sesudah langkah mana pun (hanya menampilkan keadaan):
--     psql "<koneksi admin>" -v ON_ERROR_STOP=1 -f database/supabase/00_cek.sql
-- Simulasi di PostgreSQL lokal: tambahkan -v simulasi_lokal=ya -v target_db=<db sementara> -v app_role=<role sementara>.

\set ON_ERROR_STOP on
\if :{?target_db}
\else
    \set target_db postgres
\endif
\if :{?app_role}
\else
    \set app_role photoops_app
\endif
\if :{?gerbang}
\else
    \set gerbang status
\endif
\if :{?simulasi_lokal}
\else
    \set simulasi_lokal tidak
\endif

-- Koneksi klien ke server/pooler (harus "SSL connection" untuk Supabase).
\conninfo

BEGIN READ ONLY;
SET LOCAL client_min_messages = notice;
SET LOCAL photoops.target_db = :'target_db';
SET LOCAL photoops.app_role = :'app_role';
SET LOCAL photoops.gerbang = :'gerbang';
SET LOCAL photoops.simulasi_lokal = :'simulasi_lokal';

DO $$
DECLARE
    app       text    := current_setting('photoops.app_role');
    awal      boolean := current_setting('photoops.gerbang') = 'awal';
    simulasi  boolean := current_setting('photoops.simulasi_lokal') = 'ya';
    supabase  boolean := EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'supabase_admin');
    ns        oid     := to_regnamespace('photoops');
    peran     record;
    n         bigint;
    teks      text;
    masalah   text[]  := '{}';
BEGIN
    RAISE NOTICE 'Database        : % | user % | PostgreSQL %', current_database(), current_user, current_setting('server_version');
    RAISE NOTICE 'Platform        : %', CASE WHEN supabase THEN 'Supabase (role supabase_admin ada)' ELSE 'BUKAN Supabase (PostgreSQL lokal)' END;
    RAISE NOTICE 'client_encoding : %', current_setting('client_encoding');

    -- Target dan identitas admin
    IF current_database() <> current_setting('photoops.target_db') THEN
        masalah := array_append(masalah, format('database %s, bukan target %s', current_database(), current_setting('photoops.target_db')));
    END IF;
    IF current_database() IN ('photoops_si3_dev', 'photoops_si3_test') THEN
        masalah := array_append(masalah, format('database lokal %s tidak boleh dipakai', current_database()));
    END IF;
    IF NOT supabase AND NOT simulasi THEN
        masalah := array_append(masalah, 'bukan server Supabase (untuk simulasi lokal pakai -v simulasi_lokal=ya)');
    END IF;
    IF supabase AND current_user <> 'postgres' THEN
        masalah := array_append(masalah, format('login sebagai %s; langkah migrasi dijalankan sebagai postgres', current_user));
    END IF;
    IF app !~ '^photoops_[a-z0-9_]+$' THEN
        masalah := array_append(masalah, format('nama role aplikasi %s harus berawalan photoops_', app));
    END IF;
    SELECT rolcreaterole, rolsuper INTO peran FROM pg_roles WHERE rolname = current_user;
    RAISE NOTICE 'Admin           : CREATEROLE %, superuser %', peran.rolcreaterole, peran.rolsuper;
    IF NOT (peran.rolcreaterole OR peran.rolsuper) THEN
        masalah := array_append(masalah, 'admin tidak punya hak CREATEROLE');
    END IF;
    IF NOT has_database_privilege(current_database(), 'CREATE') THEN
        masalah := array_append(masalah, 'admin tidak punya hak CREATE SCHEMA di database ini');
    END IF;

    -- Schema photoops
    IF ns IS NULL THEN
        RAISE NOTICE 'Schema photoops : belum ada';
    ELSE
        SELECT COUNT(*) INTO n FROM pg_class WHERE relnamespace = ns;
        RAISE NOTICE 'Schema photoops : ada, % objek (% tabel)', n, (SELECT COUNT(*) FROM pg_class WHERE relnamespace = ns AND relkind = 'r');
        IF awal THEN
            masalah := array_append(masalah, 'schema photoops sudah ada (cek isinya; bila sisa migrasi gagal, pakai 99_rollback.sql)');
        END IF;
        IF to_regclass('photoops.pengguna') IS NOT NULL THEN
            EXECUTE 'SELECT COUNT(*) FROM photoops.pengguna' INTO n;
            RAISE NOTICE 'Data pengguna   : % akun', n;
            EXECUTE 'SELECT COUNT(*) FROM photoops.pengguna WHERE password_hash = $1'
                INTO n USING '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S';
            RAISE NOTICE 'Sandi seed lama : % akun (harus 0 sesudah langkah 4)', n;
        END IF;
        SELECT COUNT(*) INTO n FROM pg_class WHERE relnamespace = ns AND relrowsecurity;
        RAISE NOTICE 'Tabel dgn RLS   : % (harus 0; role aplikasi tidak memakai policy)', n;
        SELECT COUNT(*) INTO n FROM (
            SELECT (aclexplode(nspacl)).grantee FROM pg_namespace WHERE oid = ns
            UNION ALL
            SELECT (aclexplode(relacl)).grantee FROM pg_class WHERE relnamespace = ns
        ) h WHERE grantee = 0 OR grantee IN (SELECT oid FROM pg_roles WHERE rolname IN ('anon', 'authenticated', 'service_role'));
        RAISE NOTICE 'Hak role API    : % (harus 0)', n;
    END IF;

    -- Role aplikasi
    SELECT * INTO peran FROM pg_roles WHERE rolname = app;
    IF NOT FOUND THEN
        RAISE NOTICE 'Role %   : belum ada', app;
    ELSE
        RAISE NOTICE 'Role %   : login %, superuser %, createrole %, bypassrls %, batas koneksi %', app,
            peran.rolcanlogin, peran.rolsuper, peran.rolcreaterole, peran.rolbypassrls, peran.rolconnlimit;
        RAISE NOTICE 'Konfigurasi role: %', COALESCE(array_to_string(peran.rolconfig, '; '), '-');
        IF awal THEN
            masalah := array_append(masalah, format('role %s sudah ada', app));
        END IF;
    END IF;

    -- Informasi khusus Supabase (tidak menggagalkan)
    SELECT string_agg(evtname || ' (' || evtevent || ')', ', ' ORDER BY evtname) INTO teks FROM pg_event_trigger WHERE evtenabled <> 'D';
    RAISE NOTICE 'Event trigger   : %', COALESCE(teks, '-');
    SELECT array_to_string(rolconfig, '; ') INTO teks FROM pg_roles WHERE rolname = 'authenticator';
    RAISE NOTICE 'authenticator   : %', COALESCE(teks, '-');
    IF teks ILIKE '%photoops%' THEN
        masalah := array_append(masalah, 'konfigurasi authenticator (Data API) menyebut schema photoops');
    END IF;

    IF cardinality(masalah) > 0 THEN
        FOREACH teks IN ARRAY masalah LOOP
            RAISE NOTICE 'MASALAH         : %', teks;
        END LOOP;
        IF awal THEN
            RAISE EXCEPTION 'Preflight GAGAL: % masalah. Jangan lanjut ke langkah 1.', cardinality(masalah);
        END IF;
    ELSIF awal THEN
        RAISE NOTICE 'Preflight OK: aman melanjutkan ke langkah 1.';
    END IF;
END $$;

ROLLBACK;
