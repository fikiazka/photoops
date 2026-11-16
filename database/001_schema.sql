-- PhotoOps Nicio Studio — skema PostgreSQL 16/17.
-- Dijalankan pada database kosong: psql -d <db> -v ON_ERROR_STOP=1 -f database/001_schema.sql

SET client_encoding = 'UTF8';

BEGIN;

-- Kode dokumen ------------------------------------------------------------------

-- 'PRJ', 26 -> 'PRJ-026'; di atas 999 digit bertambah, tidak terpotong seperti lpad.
CREATE FUNCTION format_kode(p_awalan text, p_nomor bigint)
RETURNS varchar
LANGUAGE sql
IMMUTABLE
STRICT
AS $$
    SELECT p_awalan || '-' || CASE WHEN p_nomor < 1000 THEN lpad(p_nomor::text, 3, '0') ELSE p_nomor::text END
$$;

CREATE SEQUENCE seq_kode_proyek    AS integer START 1 MINVALUE 1;
CREATE SEQUENCE seq_kode_transaksi AS integer START 1 MINVALUE 1;
CREATE SEQUENCE seq_kode_insiden   AS integer START 1 MINVALUE 1;

-- Pengguna dan inventaris ------------------------------------------------------

CREATE TABLE pengguna (
    id             integer GENERATED ALWAYS AS IDENTITY,
    nama           varchar(60)  NOT NULL,
    username       varchar(30)  NOT NULL,
    email          varchar(80)  NOT NULL,
    password_hash  varchar(255) NOT NULL,
    peran          varchar(2)   NOT NULL,
    jenis_anggota  varchar(10)  NOT NULL DEFAULT 'Internal',
    telepon        varchar(20)  NOT NULL DEFAULT '',
    status         varchar(8)   NOT NULL DEFAULT 'Aktif',
    CONSTRAINT pk_pengguna PRIMARY KEY (id),
    CONSTRAINT uq_pengguna_username UNIQUE (username),
    CONSTRAINT uq_pengguna_email UNIQUE (email),
    CONSTRAINT ck_pengguna_nama CHECK (char_length(btrim(nama)) >= 3),
    CONSTRAINT ck_pengguna_username CHECK (username ~ '^[a-z0-9._]{3,30}$'),
    CONSTRAINT ck_pengguna_email CHECK (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
    CONSTRAINT ck_pengguna_password_hash CHECK (password_hash <> ''),
    CONSTRAINT ck_pengguna_peran CHECK (peran IN ('sa', 'pi', 'us')),
    CONSTRAINT ck_pengguna_jenis_anggota CHECK (jenis_anggota IN ('Internal', 'Freelancer')),
    CONSTRAINT ck_pengguna_status CHECK (status IN ('Aktif', 'Nonaktif'))
);

CREATE TABLE kategori_perlengkapan (
    id              integer GENERATED ALWAYS AS IDENTITY,
    nama            varchar(50)  NOT NULL,
    kode_awalan     varchar(5)   NOT NULL,
    deskripsi       varchar(150) NOT NULL DEFAULT '',
    nomor_terakhir  integer      NOT NULL DEFAULT 0,  -- penghitung kode_unit, lihat ambil_kode_unit()
    CONSTRAINT pk_kategori_perlengkapan PRIMARY KEY (id),
    CONSTRAINT uq_kategori_nama UNIQUE (nama),
    CONSTRAINT uq_kategori_kode_awalan UNIQUE (kode_awalan),
    CONSTRAINT ck_kategori_kode_awalan CHECK (kode_awalan ~ '^[A-Z]{2,5}$'),
    CONSTRAINT ck_kategori_nomor_terakhir CHECK (nomor_terakhir >= 0)
);

-- Satu baris = satu unit fisik Studio. Rental dan Milik Kru hanya ada di alokasi_barang.
CREATE TABLE perlengkapan (
    id            integer GENERATED ALWAYS AS IDENTITY,
    kode_unit     varchar(12)  NOT NULL,
    nama          varchar(60)  NOT NULL,
    merek         varchar(30)  NOT NULL,
    kategori_id   integer      NOT NULL,
    kondisi       varchar(11)  NOT NULL DEFAULT 'Baik',
    status_aktif  varchar(8)   NOT NULL DEFAULT 'Aktif',
    catatan       varchar(120) NOT NULL DEFAULT '',
    CONSTRAINT pk_perlengkapan PRIMARY KEY (id),
    CONSTRAINT uq_perlengkapan_kode_unit UNIQUE (kode_unit),
    CONSTRAINT fk_perlengkapan_kategori FOREIGN KEY (kategori_id)
        REFERENCES kategori_perlengkapan (id) ON DELETE RESTRICT,
    CONSTRAINT ck_perlengkapan_kode_unit CHECK (kode_unit ~ '^[A-Z]{2,5}-[0-9]{3,}$'),
    CONSTRAINT ck_perlengkapan_nama CHECK (char_length(btrim(nama)) >= 2),
    CONSTRAINT ck_perlengkapan_merek CHECK (btrim(merek) <> ''),
    CONSTRAINT ck_perlengkapan_kondisi CHECK (kondisi IN ('Baik', 'Maintenance', 'Rusak')),
    CONSTRAINT ck_perlengkapan_status_aktif CHECK (status_aktif IN ('Aktif', 'Nonaktif'))
);

-- Proyek, kebutuhan, dan alokasi -----------------------------------------------

-- PIC tidak disimpan di sini; pic_id tersedia lewat v_proyek.
-- selesai = jadwal event, bukan batas pengembalian barang.
CREATE TABLE proyek (
    id                  integer GENERATED ALWAYS AS IDENTITY,
    kode                varchar(12)    NOT NULL DEFAULT format_kode('PRJ', nextval('seq_kode_proyek')),
    nama                varchar(80)    NOT NULL,
    klien               varchar(80)    NOT NULL,
    lokasi              varchar(80)    NOT NULL,
    mulai               timestamptz(0) NOT NULL,
    selesai             timestamptz(0) NOT NULL,
    deadline_persiapan  timestamptz(0) NOT NULL,
    status              varchar(11)    NOT NULL DEFAULT 'Draf',
    catatan             varchar(300)   NOT NULL DEFAULT '',
    dibuat_oleh         integer        NOT NULL,
    CONSTRAINT pk_proyek PRIMARY KEY (id),
    CONSTRAINT uq_proyek_kode UNIQUE (kode),
    CONSTRAINT fk_proyek_dibuat_oleh FOREIGN KEY (dibuat_oleh)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT ck_proyek_nama CHECK (btrim(nama) <> ''),
    CONSTRAINT ck_proyek_klien CHECK (btrim(klien) <> ''),
    CONSTRAINT ck_proyek_lokasi CHECK (btrim(lokasi) <> ''),
    CONSTRAINT ck_proyek_jadwal CHECK (selesai >= mulai),
    CONSTRAINT ck_proyek_deadline_persiapan CHECK (deadline_persiapan <= mulai),
    CONSTRAINT ck_proyek_status CHECK (status IN ('Draf', 'Dijadwalkan', 'Berlangsung', 'Selesai', 'Dibatalkan'))
);

-- peran = peran di proyek (PIC/Kru), bukan role login.
CREATE TABLE anggota_proyek (
    id           integer GENERATED ALWAYS AS IDENTITY,
    proyek_id    integer    NOT NULL,
    pengguna_id  integer    NOT NULL,
    peran        varchar(3) NOT NULL DEFAULT 'Kru',
    CONSTRAINT pk_anggota_proyek PRIMARY KEY (id),
    CONSTRAINT uq_anggota_proyek UNIQUE (proyek_id, pengguna_id),
    CONSTRAINT fk_anggota_proyek_proyek FOREIGN KEY (proyek_id)
        REFERENCES proyek (id) ON DELETE CASCADE,
    CONSTRAINT fk_anggota_proyek_pengguna FOREIGN KEY (pengguna_id)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT ck_anggota_proyek_peran CHECK (peran IN ('PIC', 'Kru'))
);

CREATE TABLE preset_perlengkapan (
    id           integer GENERATED ALWAYS AS IDENTITY,
    nama         varchar(50)    NOT NULL,
    deskripsi    varchar(160)   NOT NULL DEFAULT '',
    dibuat_oleh  integer        NOT NULL,
    diubah_pada  timestamptz(0) NOT NULL,
    CONSTRAINT pk_preset_perlengkapan PRIMARY KEY (id),
    CONSTRAINT fk_preset_dibuat_oleh FOREIGN KEY (dibuat_oleh)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT ck_preset_nama CHECK (char_length(btrim(nama)) >= 3)
);

-- nama_perlengkapan adalah nama model (teks), bukan FK ke unit.
CREATE TABLE detail_preset (
    id                 integer GENERATED ALWAYS AS IDENTITY,
    preset_id          integer     NOT NULL,
    nama_perlengkapan  varchar(60) NOT NULL,
    kategori_id        integer     NOT NULL,
    jumlah             integer     NOT NULL,
    CONSTRAINT pk_detail_preset PRIMARY KEY (id),
    CONSTRAINT fk_detail_preset_preset FOREIGN KEY (preset_id)
        REFERENCES preset_perlengkapan (id) ON DELETE CASCADE,
    CONSTRAINT fk_detail_preset_kategori FOREIGN KEY (kategori_id)
        REFERENCES kategori_perlengkapan (id) ON DELETE RESTRICT,
    CONSTRAINT ck_detail_preset_nama CHECK (char_length(btrim(nama_perlengkapan)) >= 2),
    CONSTRAINT ck_detail_preset_jumlah CHECK (jumlah BETWEEN 1 AND 20)
);

CREATE TABLE kebutuhan_proyek (
    id           integer GENERATED ALWAYS AS IDENTITY,
    proyek_id    integer     NOT NULL,
    nama         varchar(60) NOT NULL,
    acuan        varchar(60) NOT NULL DEFAULT '',
    kategori_id  integer     NOT NULL,
    jumlah       integer     NOT NULL,
    CONSTRAINT pk_kebutuhan_proyek PRIMARY KEY (id),
    CONSTRAINT uq_kebutuhan_id_proyek UNIQUE (id, proyek_id),  -- target FK komposit alokasi_barang
    CONSTRAINT fk_kebutuhan_proyek FOREIGN KEY (proyek_id)
        REFERENCES proyek (id) ON DELETE CASCADE,
    CONSTRAINT fk_kebutuhan_kategori FOREIGN KEY (kategori_id)
        REFERENCES kategori_perlengkapan (id) ON DELETE RESTRICT,
    CONSTRAINT ck_kebutuhan_nama CHECK (char_length(btrim(nama)) >= 2),
    CONSTRAINT ck_kebutuhan_jumlah CHECK (jumlah BETWEEN 1 AND 50)
);

-- Sumber barang: Studio (unit perlengkapan), Rental (vendor), Milik Kru (pemilik anggota).
-- tgl_mulai/tgl_selesai = salinan jadwal proyek saat alokasi dibuat.
-- Kolom diterima_*, sewa_mulai, jatuh_tempo_vendor, kontak_vendor, dikembalikan_vendor_* khusus Rental.
CREATE TABLE alokasi_barang (
    id                        integer GENERATED ALWAYS AS IDENTITY,
    proyek_id                 integer        NOT NULL,
    kebutuhan_id              integer        NOT NULL,
    sumber                    varchar(9)     NOT NULL,
    perlengkapan_id           integer,
    nama_barang               varchar(60)    NOT NULL DEFAULT '',
    vendor                    varchar(60)    NOT NULL DEFAULT '',
    pemilik_id                integer,
    jumlah                    integer        NOT NULL DEFAULT 1,
    tgl_mulai                 date           NOT NULL,
    tgl_selesai               date           NOT NULL,
    status                    varchar(12)    NOT NULL DEFAULT 'Dikonfirmasi',
    catatan                   varchar(120)   NOT NULL DEFAULT '',
    diperiksa                 boolean        NOT NULL DEFAULT false,
    diterima_pada             timestamptz(0),
    diterima_oleh             integer,
    kondisi_terima            varchar(11),
    catatan_terima            varchar(120)   NOT NULL DEFAULT '',
    sewa_mulai                date,
    jatuh_tempo_vendor        date,
    kontak_vendor             varchar(60)    NOT NULL DEFAULT '',
    dikembalikan_vendor_pada  timestamptz(0),
    dikembalikan_vendor_oleh  integer,
    kondisi_kembali_vendor    varchar(11),
    catatan_kembali_vendor    varchar(120)   NOT NULL DEFAULT '',
    CONSTRAINT pk_alokasi_barang PRIMARY KEY (id),
    CONSTRAINT fk_alokasi_kebutuhan FOREIGN KEY (kebutuhan_id, proyek_id)
        REFERENCES kebutuhan_proyek (id, proyek_id) ON DELETE CASCADE,
    CONSTRAINT fk_alokasi_perlengkapan FOREIGN KEY (perlengkapan_id)
        REFERENCES perlengkapan (id) ON DELETE RESTRICT,
    CONSTRAINT fk_alokasi_pemilik FOREIGN KEY (pemilik_id)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT fk_alokasi_diterima_oleh FOREIGN KEY (diterima_oleh)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT fk_alokasi_dikembalikan_vendor_oleh FOREIGN KEY (dikembalikan_vendor_oleh)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT ck_alokasi_sumber CHECK (sumber IN ('Studio', 'Rental', 'Milik Kru')),
    CONSTRAINT ck_alokasi_status CHECK (status IN ('Direncanakan', 'Dikonfirmasi', 'Dibatalkan')),
    CONSTRAINT ck_alokasi_jumlah CHECK (jumlah >= 1),
    CONSTRAINT ck_alokasi_tanggal CHECK (tgl_selesai >= tgl_mulai),
    CONSTRAINT ck_alokasi_isi_per_sumber CHECK (
        (sumber = 'Studio'
            AND perlengkapan_id IS NOT NULL AND jumlah = 1
            AND nama_barang = '' AND vendor = '' AND pemilik_id IS NULL)
     OR (sumber = 'Rental'
            AND perlengkapan_id IS NULL AND btrim(nama_barang) <> '' AND btrim(vendor) <> ''
            AND pemilik_id IS NULL)
     OR (sumber = 'Milik Kru'
            AND perlengkapan_id IS NULL AND btrim(nama_barang) <> '' AND vendor = ''
            AND pemilik_id IS NOT NULL)
    ),
    CONSTRAINT ck_alokasi_diperiksa CHECK (NOT diperiksa OR status = 'Dikonfirmasi'),
    CONSTRAINT ck_alokasi_kolom_vendor_hanya_rental CHECK (
        sumber = 'Rental' OR (
            diterima_pada IS NULL AND diterima_oleh IS NULL AND kondisi_terima IS NULL AND catatan_terima = ''
            AND sewa_mulai IS NULL AND jatuh_tempo_vendor IS NULL AND kontak_vendor = ''
            AND dikembalikan_vendor_pada IS NULL AND dikembalikan_vendor_oleh IS NULL
            AND kondisi_kembali_vendor IS NULL AND catatan_kembali_vendor = ''
        )
    ),
    CONSTRAINT ck_alokasi_kondisi_terima CHECK (kondisi_terima IN ('Baik', 'Maintenance', 'Rusak')),
    CONSTRAINT ck_alokasi_kondisi_kembali_vendor CHECK (kondisi_kembali_vendor IN ('Baik', 'Maintenance', 'Rusak')),
    CONSTRAINT ck_alokasi_trio_terima CHECK (
        (diterima_pada IS NULL) = (diterima_oleh IS NULL)
        AND (diterima_pada IS NULL) = (kondisi_terima IS NULL)
    ),
    CONSTRAINT ck_alokasi_trio_kembali_vendor CHECK (
        (dikembalikan_vendor_pada IS NULL) = (dikembalikan_vendor_oleh IS NULL)
        AND (dikembalikan_vendor_pada IS NULL) = (kondisi_kembali_vendor IS NULL)
    ),
    CONSTRAINT ck_alokasi_urutan_vendor CHECK (
        dikembalikan_vendor_pada IS NULL
        OR (diterima_pada IS NOT NULL AND dikembalikan_vendor_pada >= diterima_pada)
    ),
    CONSTRAINT ck_alokasi_masa_sewa CHECK (
        sewa_mulai IS NULL OR jatuh_tempo_vendor IS NULL OR jatuh_tempo_vendor >= sewa_mulai
    )
);

-- Persiapan dan serah terima ---------------------------------------------------

-- 1:1 dengan proyek. Ketepatan waktu = dikonfirmasi_pada dibanding proyek.deadline_persiapan (dihitung).
-- batal_* hanya menyimpan pembatalan konfirmasi terakhir; riwayat lengkap di riwayat_aktivitas.
CREATE TABLE persiapan_proyek (
    id                     integer GENERATED ALWAYS AS IDENTITY,
    proyek_id              integer        NOT NULL,
    diperbarui_pada        timestamptz(0),
    diperbarui_oleh        integer,
    dikonfirmasi_pada      timestamptz(0),
    dikonfirmasi_oleh      integer,
    batal_pada             timestamptz(0),
    batal_oleh             integer,
    batal_otomatis         boolean,
    batal_alasan           text           NOT NULL DEFAULT '',
    batal_konfirmasi_pada  timestamptz(0),
    batal_konfirmasi_oleh  integer,
    CONSTRAINT pk_persiapan_proyek PRIMARY KEY (id),
    CONSTRAINT uq_persiapan_proyek UNIQUE (proyek_id),
    CONSTRAINT fk_persiapan_proyek FOREIGN KEY (proyek_id)
        REFERENCES proyek (id) ON DELETE CASCADE,
    CONSTRAINT fk_persiapan_diperbarui_oleh FOREIGN KEY (diperbarui_oleh)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT fk_persiapan_dikonfirmasi_oleh FOREIGN KEY (dikonfirmasi_oleh)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT fk_persiapan_batal_oleh FOREIGN KEY (batal_oleh)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT fk_persiapan_batal_konfirmasi_oleh FOREIGN KEY (batal_konfirmasi_oleh)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT ck_persiapan_pasangan_diperbarui CHECK ((diperbarui_pada IS NULL) = (diperbarui_oleh IS NULL)),
    CONSTRAINT ck_persiapan_pasangan_konfirmasi CHECK ((dikonfirmasi_pada IS NULL) = (dikonfirmasi_oleh IS NULL)),
    -- batal_oleh boleh NULL: pencabutan otomatis bisa terjadi tanpa pelaku.
    CONSTRAINT ck_persiapan_data_batal CHECK (
        (batal_pada IS NOT NULL AND batal_otomatis IS NOT NULL
            AND batal_konfirmasi_pada IS NOT NULL AND batal_konfirmasi_oleh IS NOT NULL)
     OR (batal_pada IS NULL AND batal_oleh IS NULL AND batal_otomatis IS NULL AND batal_alasan = ''
            AND batal_konfirmasi_pada IS NULL AND batal_konfirmasi_oleh IS NULL)
    )
);

-- penerima/penyerah = snapshot nama saat kejadian. Pada Kembali, penerima berisi nama petugas.
CREATE TABLE transaksi_barang (
    id            integer GENERATED ALWAYS AS IDENTITY,
    kode          varchar(12)    NOT NULL DEFAULT format_kode('TRX', nextval('seq_kode_transaksi')),
    proyek_id     integer        NOT NULL,
    jenis         varchar(7)     NOT NULL,
    waktu         timestamptz(0) NOT NULL,
    petugas_id    integer        NOT NULL,
    penerima      varchar(60)    NOT NULL DEFAULT '',
    penerima_id   integer,
    penyerah      varchar(60)    NOT NULL DEFAULT '',
    penyerah_id   integer,
    catatan       varchar(300)   NOT NULL DEFAULT '',
    dicatat_pada  timestamptz(0),
    CONSTRAINT pk_transaksi_barang PRIMARY KEY (id),
    CONSTRAINT uq_transaksi_kode UNIQUE (kode),
    CONSTRAINT uq_transaksi_id_jenis UNIQUE (id, jenis),  -- target FK komposit detail_transaksi
    CONSTRAINT fk_transaksi_proyek FOREIGN KEY (proyek_id)
        REFERENCES proyek (id) ON DELETE RESTRICT,
    CONSTRAINT fk_transaksi_petugas FOREIGN KEY (petugas_id)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT fk_transaksi_penerima FOREIGN KEY (penerima_id)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT fk_transaksi_penyerah FOREIGN KEY (penyerah_id)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT ck_transaksi_jenis CHECK (jenis IN ('Keluar', 'Kembali')),
    CONSTRAINT ck_transaksi_keluar CHECK (
        jenis <> 'Keluar'
        OR (btrim(penerima) <> '' AND penerima_id IS NOT NULL AND penyerah = '' AND penyerah_id IS NULL)
    ),
    CONSTRAINT ck_transaksi_kembali CHECK (jenis <> 'Kembali' OR penerima_id IS NULL),
    CONSTRAINT ck_transaksi_penyerah CHECK (penyerah_id IS NULL OR btrim(penyerah) <> '')
);

-- Saldo per alokasi = jumlah Keluar - total jumlah Kembali (v_saldo_alokasi); tidak disimpan.
-- jenis disalin dari kepala transaksi dan dijaga FK komposit.
-- trx_asal: transaksi Keluar asal untuk baris Kembali.
CREATE TABLE detail_transaksi (
    id            integer GENERATED ALWAYS AS IDENTITY,
    transaksi_id  integer     NOT NULL,
    jenis         varchar(7)  NOT NULL,
    alokasi_id    integer     NOT NULL,
    kondisi       varchar(11) NOT NULL DEFAULT 'Baik',
    jumlah        integer     NOT NULL DEFAULT 1,
    trx_asal      integer,
    CONSTRAINT pk_detail_transaksi PRIMARY KEY (id),
    CONSTRAINT fk_detail_transaksi FOREIGN KEY (transaksi_id, jenis)
        REFERENCES transaksi_barang (id, jenis) ON DELETE RESTRICT,
    CONSTRAINT fk_detail_alokasi FOREIGN KEY (alokasi_id)
        REFERENCES alokasi_barang (id) ON DELETE RESTRICT,
    CONSTRAINT fk_detail_trx_asal FOREIGN KEY (trx_asal)
        REFERENCES transaksi_barang (id) ON DELETE RESTRICT,
    CONSTRAINT uq_detail_transaksi_alokasi_kondisi UNIQUE (transaksi_id, alokasi_id, kondisi),
    CONSTRAINT ck_detail_jenis CHECK (jenis IN ('Keluar', 'Kembali')),
    CONSTRAINT ck_detail_kondisi CHECK (kondisi IN ('Baik', 'Maintenance', 'Rusak')),
    CONSTRAINT ck_detail_jumlah CHECK (jumlah >= 1),
    CONSTRAINT ck_detail_keluar_kembali CHECK (
        (jenis = 'Keluar' AND trx_asal IS NULL AND kondisi = 'Baik')
     OR (jenis = 'Kembali' AND trx_asal IS NOT NULL)
    )
);

-- Insiden dan riwayat ----------------------------------------------------------

-- Insiden formal hanya untuk unit Studio (perlengkapan_id wajib).
-- detail_id terisi bila insiden dibuat otomatis dari pengembalian.
CREATE TABLE laporan_insiden (
    id               integer GENERATED ALWAYS AS IDENTITY,
    kode             varchar(12)    NOT NULL DEFAULT format_kode('INS', nextval('seq_kode_insiden')),
    perlengkapan_id  integer        NOT NULL,
    proyek_id        integer,
    jenis            varchar(14)    NOT NULL,
    waktu            timestamptz(0) NOT NULL,
    deskripsi        text           NOT NULL,
    status           varchar(10)    NOT NULL DEFAULT 'Dilaporkan',
    pelapor_id       integer        NOT NULL,
    detail_id        integer,
    CONSTRAINT pk_laporan_insiden PRIMARY KEY (id),
    CONSTRAINT uq_insiden_kode UNIQUE (kode),
    CONSTRAINT uq_insiden_detail UNIQUE (detail_id),
    CONSTRAINT fk_insiden_perlengkapan FOREIGN KEY (perlengkapan_id)
        REFERENCES perlengkapan (id) ON DELETE RESTRICT,
    CONSTRAINT fk_insiden_proyek FOREIGN KEY (proyek_id)
        REFERENCES proyek (id) ON DELETE SET NULL,
    CONSTRAINT fk_insiden_pelapor FOREIGN KEY (pelapor_id)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT fk_insiden_detail FOREIGN KEY (detail_id)
        REFERENCES detail_transaksi (id) ON DELETE RESTRICT,
    CONSTRAINT ck_insiden_jenis CHECK (jenis IN ('Rusak', 'Masalah Teknis')),
    CONSTRAINT ck_insiden_deskripsi CHECK (char_length(btrim(deskripsi)) >= 5),
    CONSTRAINT ck_insiden_status CHECK (status IN ('Dilaporkan', 'Ditangani', 'Selesai'))
);

-- Log audit, hanya INSERT. pengguna_id NULL = Sistem. objek berupa teks agar log tetap utuh.
-- lama/baru disimpan apa adanya (perubahan deadline persiapan: format Y-m-d H:i).
CREATE TABLE riwayat_aktivitas (
    id           bigint GENERATED ALWAYS AS IDENTITY,
    waktu        timestamptz(0) NOT NULL,
    pengguna_id  integer,
    aksi         varchar(20)    NOT NULL,
    objek        varchar(200)   NOT NULL DEFAULT '',
    keterangan   text           NOT NULL DEFAULT '',
    lama         varchar(200)   NOT NULL DEFAULT '',
    baru         varchar(200)   NOT NULL DEFAULT '',
    CONSTRAINT pk_riwayat_aktivitas PRIMARY KEY (id),
    CONSTRAINT fk_riwayat_pengguna FOREIGN KEY (pengguna_id)
        REFERENCES pengguna (id) ON DELETE RESTRICT,
    CONSTRAINT ck_riwayat_aksi CHECK (aksi IN (
        'Barang Keluar', 'Pengembalian', 'Insiden', 'Alokasi', 'Rental', 'Persiapan',
        'Proyek', 'Kebutuhan', 'Tim', 'Preset', 'Perlengkapan', 'Pengguna'
    ))
);

-- Indeks -----------------------------------------------------------------------

CREATE UNIQUE INDEX uq_anggota_proyek_satu_pic ON anggota_proyek (proyek_id) WHERE peran = 'PIC';
CREATE UNIQUE INDEX uq_preset_nama_lower ON preset_perlengkapan (lower(nama));
CREATE UNIQUE INDEX uq_detail_preset_nama_lower ON detail_preset (preset_id, lower(nama_perlengkapan));
CREATE UNIQUE INDEX uq_detail_satu_keluar_per_alokasi ON detail_transaksi (alokasi_id) WHERE jenis = 'Keluar';

CREATE INDEX ix_perlengkapan_kategori        ON perlengkapan (kategori_id);
CREATE INDEX ix_proyek_dibuat_oleh           ON proyek (dibuat_oleh);
CREATE INDEX ix_anggota_proyek_pengguna      ON anggota_proyek (pengguna_id);
CREATE INDEX ix_preset_dibuat_oleh           ON preset_perlengkapan (dibuat_oleh);
CREATE INDEX ix_detail_preset_kategori       ON detail_preset (kategori_id);
CREATE INDEX ix_kebutuhan_proyek             ON kebutuhan_proyek (proyek_id);
CREATE INDEX ix_kebutuhan_kategori           ON kebutuhan_proyek (kategori_id);
CREATE INDEX ix_alokasi_proyek               ON alokasi_barang (proyek_id);
CREATE INDEX ix_alokasi_kebutuhan            ON alokasi_barang (kebutuhan_id, proyek_id);
CREATE INDEX ix_alokasi_perlengkapan_studio  ON alokasi_barang (perlengkapan_id) WHERE sumber = 'Studio';
CREATE INDEX ix_alokasi_pemilik              ON alokasi_barang (pemilik_id) WHERE pemilik_id IS NOT NULL;
CREATE INDEX ix_alokasi_rental_jatuh_tempo   ON alokasi_barang (jatuh_tempo_vendor) WHERE sumber = 'Rental';
CREATE INDEX ix_transaksi_proyek             ON transaksi_barang (proyek_id);
CREATE INDEX ix_transaksi_waktu              ON transaksi_barang (waktu);
CREATE INDEX ix_detail_alokasi               ON detail_transaksi (alokasi_id);
CREATE INDEX ix_detail_trx_asal              ON detail_transaksi (trx_asal) WHERE trx_asal IS NOT NULL;
CREATE INDEX ix_insiden_perlengkapan_terbuka ON laporan_insiden (perlengkapan_id) WHERE status <> 'Selesai';
CREATE INDEX ix_insiden_proyek               ON laporan_insiden (proyek_id);
CREATE INDEX ix_riwayat_waktu                ON riwayat_aktivitas (waktu DESC, id DESC);
CREATE INDEX ix_riwayat_pengguna             ON riwayat_aktivitas (pengguna_id);

-- Fungsi dan trigger -----------------------------------------------------------

-- Kode unit berikutnya per kategori. Baris kategori terkunci sampai transaksi selesai,
-- sehingga aman dipakai bersamaan; nomor yang pernah dipakai tidak diulang.
-- Pemakaian: INSERT INTO perlengkapan (kode_unit, ...) VALUES (ambil_kode_unit(:kategori_id), ...)
CREATE FUNCTION ambil_kode_unit(p_kategori_id integer)
RETURNS varchar
LANGUAGE plpgsql
AS $$
DECLARE
    v_kode varchar;
BEGIN
    UPDATE kategori_perlengkapan
       SET nomor_terakhir = nomor_terakhir + 1
     WHERE id = p_kategori_id
    RETURNING format_kode(kode_awalan, nomor_terakhir) INTO v_kode;

    IF v_kode IS NULL THEN
        RAISE EXCEPTION 'Kategori % tidak ditemukan', p_kategori_id
            USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN v_kode;
END;
$$;

-- Validasi detail_transaksi yang melibatkan baris lain (tidak bisa sebagai CHECK):
--   alokasi harus satu proyek dengan transaksi;
--   baris Kembali: trx_asal adalah Keluar alokasi yang sama, waktunya tidak sebelum Keluar,
--   dan total Kembali tidak melebihi jumlah Keluar.
-- Baris alokasi dikunci agar pengembalian bersamaan untuk alokasi yang sama berjalan berurutan.
CREATE FUNCTION cek_detail_transaksi()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_proyek_transaksi  integer;
    v_jenis_transaksi   varchar;
    v_waktu_transaksi   timestamptz;
    v_proyek_alokasi    integer;
    v_jumlah_keluar     integer;
    v_waktu_keluar      timestamptz;
    v_jumlah_kembali    integer;
BEGIN
    SELECT proyek_id, jenis, waktu INTO v_proyek_transaksi, v_jenis_transaksi, v_waktu_transaksi
      FROM transaksi_barang WHERE id = NEW.transaksi_id;

    -- Baris yang pasti ditolak FK/CHECK diserahkan ke constraint tersebut agar nama errornya tepat.
    IF v_jenis_transaksi IS DISTINCT FROM NEW.jenis
       OR (NEW.jenis = 'Kembali' AND NEW.trx_asal IS NULL) THEN
        RETURN NEW;
    END IF;

    SELECT proyek_id INTO v_proyek_alokasi
      FROM alokasi_barang WHERE id = NEW.alokasi_id
       FOR UPDATE;

    IF v_proyek_transaksi IS DISTINCT FROM v_proyek_alokasi THEN
        RAISE EXCEPTION 'Alokasi % bukan milik proyek transaksi %', NEW.alokasi_id, NEW.transaksi_id
            USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.jenis = 'Kembali' THEN
        SELECT d.jumlah, t.waktu INTO v_jumlah_keluar, v_waktu_keluar
          FROM detail_transaksi d
          JOIN transaksi_barang t ON t.id = d.transaksi_id
         WHERE d.alokasi_id = NEW.alokasi_id
           AND d.jenis = 'Keluar'
           AND d.transaksi_id = NEW.trx_asal;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'trx_asal % bukan transaksi Keluar untuk alokasi %', NEW.trx_asal, NEW.alokasi_id
                USING ERRCODE = 'check_violation';
        END IF;

        IF v_waktu_transaksi < v_waktu_keluar THEN
            RAISE EXCEPTION 'Waktu pengembalian alokasi % lebih awal dari waktu keluarnya', NEW.alokasi_id
                USING ERRCODE = 'check_violation';
        END IF;

        SELECT COALESCE(SUM(jumlah), 0) INTO v_jumlah_kembali
          FROM detail_transaksi
         WHERE alokasi_id = NEW.alokasi_id
           AND jenis = 'Kembali'
           AND id <> NEW.id;

        IF v_jumlah_kembali + NEW.jumlah > v_jumlah_keluar THEN
            RAISE EXCEPTION 'Jumlah kembali alokasi % (% + %) melebihi jumlah keluar (%)',
                NEW.alokasi_id, v_jumlah_kembali, NEW.jumlah, v_jumlah_keluar
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_cek_detail_transaksi
    BEFORE INSERT OR UPDATE ON detail_transaksi
    FOR EACH ROW EXECUTE FUNCTION cek_detail_transaksi();

-- View -------------------------------------------------------------------------

-- proyek + pic_id dari anggota_proyek, untuk pembaca $proyek['pic_id'] di PHP.
CREATE VIEW v_proyek AS
SELECT p.id, p.kode, p.nama, p.klien, p.lokasi, p.mulai, p.selesai, p.deadline_persiapan,
       p.status, pic.pengguna_id AS pic_id, p.catatan, p.dibuat_oleh
  FROM proyek p
  LEFT JOIN anggota_proyek pic ON pic.proyek_id = p.id AND pic.peran = 'PIC';

-- Saldo per alokasi yang pernah keluar. Label status_kembali sama dengan indeks_serah() di PHP.
CREATE VIEW v_saldo_alokasi AS
SELECT a.id                                     AS alokasi_id,
       a.proyek_id,
       a.sumber,
       k.transaksi_id                           AS trx_keluar,
       tk.waktu                                 AS keluar,
       k.jumlah                                 AS jumlah_keluar,
       COALESCE(r.jumlah_kembali, 0)            AS jumlah_kembali,
       k.jumlah - COALESCE(r.jumlah_kembali, 0) AS sisa,
       COALESCE(r.kembali_baik, 0)              AS kembali_baik,
       COALESCE(r.kembali_maintenance, 0)       AS kembali_maintenance,
       COALESCE(r.kembali_rusak, 0)             AS kembali_rusak,
       r.kembali_terakhir,
       CASE
           WHEN COALESCE(r.jumlah_kembali, 0) = 0 THEN 'Belum Kembali'
           WHEN r.jumlah_kembali < k.jumlah       THEN 'Kembali Sebagian'
           ELSE 'Sudah Kembali'
       END                                      AS status_kembali
  FROM alokasi_barang a
  JOIN detail_transaksi k  ON k.alokasi_id = a.id AND k.jenis = 'Keluar'
  JOIN transaksi_barang tk ON tk.id = k.transaksi_id
  LEFT JOIN (
        SELECT d.alokasi_id,
               SUM(d.jumlah)                                          AS jumlah_kembali,
               SUM(d.jumlah) FILTER (WHERE d.kondisi = 'Baik')        AS kembali_baik,
               SUM(d.jumlah) FILTER (WHERE d.kondisi = 'Maintenance') AS kembali_maintenance,
               SUM(d.jumlah) FILTER (WHERE d.kondisi = 'Rusak')       AS kembali_rusak,
               MAX(t.waktu)                                           AS kembali_terakhir
          FROM detail_transaksi d
          JOIN transaksi_barang t ON t.id = d.transaksi_id
         WHERE d.jenis = 'Kembali'
         GROUP BY d.alokasi_id
  ) r ON r.alokasi_id = a.id;

COMMIT;
