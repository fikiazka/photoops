-- PhotoOps — tabel PHP Session di PostgreSQL (tabel ke-15, di luar 14 tabel bisnis).
-- Dipakai handler session PostgreSQL untuk deployment stateless (Vercel): beberapa instance dan restart tetap memakai
-- session yang sama. Bukan data bisnis: isinya hanya state sesi (user_id, csrf, flash, isian form, sandi sementara).
-- Dijalankan dengan search_path ke schema tujuan, misalnya lewat database/supabase/02_skema.sql.

SET client_encoding = 'UTF8';

BEGIN;

CREATE TABLE sesi_php (
    -- ID session PHP (karakter a-z, A-Z, 0-9, koma, dan tanda minus).
    id               VARCHAR(128) PRIMARY KEY CHECK (id ~ '^[A-Za-z0-9,-]{22,128}$'),
    -- Data session hasil serialize PHP, disimpan base64 karena bisa berisi byte yang bukan UTF-8.
    data             TEXT         NOT NULL DEFAULT '',
    -- Waktu nyata server database (bukan tanggal demo aplikasi), dipakai untuk membuang session kedaluwarsa.
    diperbarui_pada  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX idx_sesi_php_diperbarui ON sesi_php (diperbarui_pada);

COMMIT;
