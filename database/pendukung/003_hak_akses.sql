-- PhotoOps — hak akses minimum untuk role aplikasi photoops_si3_app.
-- Jalankan sebagai superuser setelah 001_schema.sql dan 002_seed_demo.sql:
--   psql -U postgres -d photoops_si3_dev -v ON_ERROR_STOP=1 -f database/pendukung/003_hak_akses.sql

BEGIN;

GRANT USAGE ON SCHEMA public TO photoops_si3_app;

-- Data yang dikelola penuh lewat aplikasi, termasuk dihapus
-- (hapus proyek, kebutuhan, alokasi, anggota tim, preset, dan unit yang belum pernah dipakai).
GRANT SELECT, INSERT, UPDATE, DELETE ON
    proyek, anggota_proyek, preset_perlengkapan, detail_preset,
    kebutuhan_proyek, alokasi_barang, perlengkapan
TO photoops_si3_app;

-- Data yang diubah tetapi tidak pernah dihapus lewat aplikasi.
-- Pengguna cukup dinonaktifkan; kategori hanya diperbarui nomor_terakhir-nya.
GRANT SELECT, INSERT, UPDATE ON pengguna, persiapan_proyek, laporan_insiden TO photoops_si3_app;
GRANT SELECT, UPDATE ON kategori_perlengkapan TO photoops_si3_app;

-- Histori serah terima dan log aktivitas: hanya boleh ditambah, tidak boleh diubah atau dihapus.
GRANT SELECT, INSERT ON transaksi_barang, detail_transaksi, riwayat_aktivitas TO photoops_si3_app;

GRANT SELECT ON v_saldo_alokasi TO photoops_si3_app;

-- Sequence kode dokumen (nextval).
GRANT USAGE ON SEQUENCE seq_kode_proyek, seq_kode_transaksi, seq_kode_insiden TO photoops_si3_app;

COMMIT;
