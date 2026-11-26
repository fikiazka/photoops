-- PhotoOps × Supabase — hak akses minimum role aplikasi di schema `photoops`.
-- Setara database/pendukung/003_hak_akses.sql (lokal), ditambah tabel sesi_php. Dipanggil oleh 02_skema.sql
-- (variabel psql :app_role sudah diset di sana).

BEGIN;

-- Tidak ada hak bawaan untuk PUBLIC pada objek schema ini.
REVOKE ALL ON ALL TABLES IN SCHEMA photoops FROM PUBLIC;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA photoops FROM PUBLIC;

-- Data yang dikelola penuh lewat aplikasi, termasuk dihapus
-- (hapus proyek, kebutuhan, alokasi, anggota tim, preset, dan unit yang belum pernah dipakai).
GRANT SELECT, INSERT, UPDATE, DELETE ON
    photoops.proyek, photoops.anggota_proyek, photoops.preset_perlengkapan, photoops.detail_preset,
    photoops.kebutuhan_proyek, photoops.alokasi_barang, photoops.perlengkapan
TO :"app_role";

-- Data yang diubah tetapi tidak dihapus lewat aplikasi; kategori hanya diperbarui nomor_terakhir-nya.
GRANT SELECT, INSERT, UPDATE ON photoops.pengguna, photoops.persiapan_proyek, photoops.laporan_insiden TO :"app_role";
-- Akun pengguna boleh dihapus hanya bila belum dirujuk data lain; FOREIGN KEY (tanpa CASCADE) menolak sisanya.
GRANT DELETE ON photoops.pengguna TO :"app_role";
GRANT SELECT, UPDATE ON photoops.kategori_perlengkapan TO :"app_role";

-- Histori serah terima dan log aktivitas: hanya boleh ditambah, tidak boleh diubah atau dihapus.
GRANT SELECT, INSERT ON photoops.transaksi_barang, photoops.detail_transaksi, photoops.riwayat_aktivitas TO :"app_role";

GRANT SELECT ON photoops.v_saldo_alokasi TO :"app_role";
-- View dijalankan dengan hak pemanggil (bukan pemilik), jadi tidak bisa dipakai melewati hak tabel di bawahnya.
ALTER VIEW photoops.v_saldo_alokasi SET (security_invoker = true);

-- Sequence kode dokumen (nextval). Sequence identity tabel tidak perlu hak tersendiri.
GRANT USAGE ON SEQUENCE photoops.seq_kode_proyek, photoops.seq_kode_transaksi, photoops.seq_kode_insiden TO :"app_role";

-- PHP Session: dibaca, ditulis, diperbarui, dan dihapus (logout / pembersihan session kedaluwarsa).
GRANT SELECT, INSERT, UPDATE, DELETE ON photoops.sesi_php TO :"app_role";

-- Role Data API Supabase (anon, authenticated, service_role) tidak boleh menyentuh schema privat ini, termasuk untuk objek
-- yang dibuat kemudian. Role tersebut hanya ada di Supabase; di PostgreSQL lokal blok ini dilewati.
DO $$
DECLARE
    r text;
BEGIN
    FOREACH r IN ARRAY ARRAY['anon', 'authenticated', 'service_role'] LOOP
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
            EXECUTE format('REVOKE ALL ON SCHEMA photoops FROM %I', r);
            EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA photoops FROM %I', r);
            EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA photoops FROM %I', r);
            EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA photoops REVOKE ALL ON TABLES FROM %I', r);
            EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA photoops REVOKE ALL ON SEQUENCES FROM %I', r);
        END IF;
    END LOOP;
END $$;

COMMIT;
