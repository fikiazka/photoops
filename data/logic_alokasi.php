<?php
defined('ROOT') || exit;

// ---------------------------------------------------------------------------------------------
// Kebutuhan proyek dan alokasi barang (Sprint Backend 3C) di PostgreSQL: tabel kebutuhan_proyek dan alokasi_barang.
//   - Baris alokasi dibaca bersama proyek_id kebutuhannya (JOIN), sehingga kode lama yang memakai $alokasi['proyek_id'] tetap jalan.
//   - Kolom Rental (diterima_*, sewa_mulai, jatuh_tempo_vendor, kontak_vendor, dikembalikan_vendor_*) ikut di baris alokasi.
//   - Checklist persiapan ada di kolom alokasi_barang.diperiksa (Sprint 4B); serah terima (Sprint 4A) merujuk id alokasi ini.
//   - Penguncian: setiap perubahan mengunci baris proyek (FOR UPDATE) lebih dulu, sehingga perubahan kebutuhan/alokasi/tim/jadwal
//     pada proyek yang sama berjalan bergantian. Alokasi Studio juga mengunci baris unit (perlengkapan) sehingga dua proyek
//     tidak bisa memesan unit yang sama pada jadwal yang bertabrakan, walaupun dikirim bersamaan (termasuk saat jadwal digeser).
// ---------------------------------------------------------------------------------------------

const KOLOM_ALOKASI = "a.id, k.proyek_id, a.kebutuhan_id, a.sumber, a.perlengkapan_id, a.nama_barang, a.vendor, a.pemilik_id, a.jumlah,
    TO_CHAR(a.tgl_mulai, 'YYYY-MM-DD') AS tgl_mulai, TO_CHAR(a.tgl_selesai, 'YYYY-MM-DD') AS tgl_selesai, a.status, a.catatan,
    TO_CHAR(a.diterima_pada, 'YYYY-MM-DD HH24:MI') AS diterima_pada, a.diterima_oleh, a.kondisi_terima, a.catatan_terima,
    TO_CHAR(a.sewa_mulai, 'YYYY-MM-DD') AS sewa_mulai, TO_CHAR(a.jatuh_tempo_vendor, 'YYYY-MM-DD') AS jatuh_tempo_vendor, a.kontak_vendor,
    TO_CHAR(a.dikembalikan_vendor_pada, 'YYYY-MM-DD HH24:MI') AS dikembalikan_vendor_pada, a.dikembalikan_vendor_oleh,
    a.kondisi_kembali_vendor, a.catatan_kembali_vendor, a.diperiksa::int AS diperiksa";

const DARI_ALOKASI = ' FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id';

function alokasi_dari_baris(array $baris): array
{
    foreach (['id', 'proyek_id', 'kebutuhan_id', 'jumlah'] as $kolom) {
        $baris[$kolom] = (int) $baris[$kolom];
    }
    foreach (['perlengkapan_id', 'pemilik_id', 'diterima_oleh', 'dikembalikan_vendor_oleh'] as $kolom) {
        $baris[$kolom] = $baris[$kolom] === null ? null : (int) $baris[$kolom];
    }
    // Centang checklist persiapan (Sprint 4B). Hanya alokasi Dikonfirmasi yang boleh TRUE (CHECK ck_alokasi_diperiksa).
    $baris['diperiksa'] = (bool) (int) $baris['diperiksa'];
    return $baris;
}

// Persiapan proyek sudah dikonfirmasi (PostgreSQL). Dipanggil di dalam transaksi SETELAH kunci_proyek(): konfirmasi dan
// pembukaan ulang persiapan juga mengunci baris proyek lebih dulu, jadi hasil ini tidak bisa berubah sampai COMMIT.
function persiapan_dikonfirmasi_db(int $proyek_id): bool
{
    return (bool) sql_satu('SELECT 1 FROM persiapan_proyek WHERE proyek_id = :p AND dikonfirmasi_pada IS NOT NULL', ['p' => $proyek_id]);
}

// Semua kebutuhan, ber-key id. Dibaca sekali per request; $muat_ulang = true sesudah ada perubahan.
function semua_kebutuhan(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT id, proyek_id, nama, acuan, kategori_id, jumlah FROM kebutuhan_proyek ORDER BY id') as $baris) {
            foreach (['id', 'proyek_id', 'kategori_id', 'jumlah'] as $kolom) {
                $baris[$kolom] = (int) $baris[$kolom];
            }
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

function cari_kebutuhan($id): ?array
{
    return semua_kebutuhan()[(int) $id] ?? null;
}

// Semua alokasi, ber-key id (satu query, tanpa N+1).
function semua_alokasi(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT ' . KOLOM_ALOKASI . DARI_ALOKASI . ' ORDER BY a.id') as $baris) {
            $baris = alokasi_dari_baris($baris);
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

function cari_alokasi($id): ?array
{
    return semua_alokasi()[(int) $id] ?? null;
}

// Sesudah kebutuhan/alokasi berubah: baca ulang, dan tandai agar bootstrap menyinkronkan konfirmasi persiapan
// (syarat persiapan bergantung pada alokasi).
function muat_ulang_alokasi(): void
{
    semua_kebutuhan(true);
    semua_alokasi(true);
    proyek_berubah(true);
}

// Kunci baris proyek. Hasil: data proyek (format sama dengan semua_proyek()), atau null bila tidak ada.
function kunci_proyek(int $proyek_id): ?array
{
    $baris = sql_satu('SELECT ' . KOLOM_PROYEK . ' FROM proyek WHERE id = :id FOR UPDATE', ['id' => $proyek_id]);
    return $baris ? proyek_dari_baris($baris) : null;
}

// Kunci baris alokasi milik proyek (urut id agar urutan kunci selalu sama). Dipanggil SETELAH kunci_proyek().
// Hasil: alokasi ber-key id; id yang bukan milik proyek tidak ikut.
function kunci_alokasi_proyek(int $proyek_id, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    if (!$ids) {
        return [];
    }
    $param = ['proyek_id' => $proyek_id];
    foreach ($ids as $i => $id) {
        $param['a' . $i] = $id;
    }
    $tanda = ':' . implode(', :', array_slice(array_keys($param), 1));
    $hasil = [];
    foreach (sql_semua('SELECT ' . KOLOM_ALOKASI . DARI_ALOKASI . " WHERE k.proyek_id = :proyek_id AND a.id IN ($tanda) ORDER BY a.id FOR UPDATE OF a", $param) as $baris) {
        $baris = alokasi_dari_baris($baris);
        $hasil[$baris['id']] = $baris;
    }
    return $hasil;
}

// Jumlah unit yang sudah terisi (alokasi selain Dibatalkan) untuk satu kebutuhan, dibaca langsung dari tabel.
function terisi_kebutuhan_db(int $kebutuhan_id): int
{
    return (int) sql_satu("SELECT COALESCE(SUM(CASE WHEN sumber = 'Studio' THEN 1 ELSE jumlah END), 0) AS n
                           FROM alokasi_barang WHERE kebutuhan_id = :id AND status <> 'Dibatalkan'", ['id' => $kebutuhan_id])['n'];
}

// Serah terima yang merujuk alokasi kebutuhan ini. Sejak Sprint 4A semua serah terima ada di PostgreSQL, jadi pemeriksaan
// ini (di dalam kunci proyek) berlaku untuk semua browser.
function kebutuhan_punya_serah_db(int $kebutuhan_id): bool
{
    return (bool) sql_satu('SELECT 1 FROM detail_transaksi d JOIN alokasi_barang a ON a.id = d.alokasi_id WHERE a.kebutuhan_id = :id LIMIT 1',
        ['id' => $kebutuhan_id]);
}

function alokasi_punya_serah_db(int $alokasi_id): bool
{
    return (bool) sql_satu('SELECT 1 FROM detail_transaksi WHERE alokasi_id = :id LIMIT 1', ['id' => $alokasi_id]);
}

// ---------------------------------------------------------------------------------------------
// Kebutuhan
// ---------------------------------------------------------------------------------------------

// Tambah ($id = 0) atau ubah kebutuhan. Aturan yang sudah divalidasi di form dicek ulang di dalam kunci proyek.
// Hasil: ['status' => ok | proyek_tutup | tidak_ada | kategori_terkunci | kurang_dari_terisi, 'terisi' => ..., 'lama' => ...].
function simpan_kebutuhan(int $proyek_id, int $id, array $isi): array
{
    $hasil = sql_transaksi(function () use ($proyek_id, $id, $isi) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
        }
        if (persiapan_dikonfirmasi_db($proyek_id)) {
            return ['status' => 'persiapan_terkunci'];
        }
        if (!$id) {
            sql_jalankan('INSERT INTO kebutuhan_proyek (proyek_id, nama, acuan, kategori_id, jumlah)
                          VALUES (:proyek_id, :nama, :acuan, :kategori_id, :jumlah)', $isi + ['proyek_id' => $proyek_id]);
            catat_riwayat_proyek('Kebutuhan', $proyek['kode'], $isi['nama'] . ' × ' . $isi['jumlah'] . ' ditambahkan');
            return ['status' => 'ok'];
        }
        $lama = sql_satu('SELECT nama, acuan, kategori_id, jumlah FROM kebutuhan_proyek WHERE id = :id AND proyek_id = :proyek_id FOR UPDATE',
            ['id' => $id, 'proyek_id' => $proyek_id]);
        if (!$lama) {
            return ['status' => 'tidak_ada'];
        }
        $terisi = terisi_kebutuhan_db($id);
        if ((int) $lama['kategori_id'] !== $isi['kategori_id'] && ($terisi > 0 || kebutuhan_punya_serah_db($id))) {
            return ['status' => 'kategori_terkunci', 'terisi' => $terisi];
        }
        if ($isi['jumlah'] < $terisi) {
            return ['status' => 'kurang_dari_terisi', 'terisi' => $terisi];
        }
        sql_jalankan('UPDATE kebutuhan_proyek SET nama = :nama, acuan = :acuan, kategori_id = :kategori_id, jumlah = :jumlah WHERE id = :id',
            $isi + ['id' => $id]);
        catat_riwayat_proyek('Kebutuhan', $proyek['kode'], $isi['nama'] . ' diperbarui', (string) $lama['jumlah'], (string) $isi['jumlah']);
        return ['status' => 'ok', 'lama' => $lama];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_alokasi();
    }
    return $hasil;
}

// Hapus kebutuhan yang tidak punya alokasi aktif dan tidak pernah diserahterimakan. Alokasi yang sudah Dibatalkan ikut
// terhapus (ON DELETE CASCADE). Hasil: ['status' => ok | proyek_tutup | tidak_ada | masih_alokasi | punya_serah, 'alokasi_ids' => [...]].
function hapus_kebutuhan(int $proyek_id, int $id): array
{
    $hasil = sql_transaksi(function () use ($proyek_id, $id) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
        }
        if (persiapan_dikonfirmasi_db($proyek_id)) {
            return ['status' => 'persiapan_terkunci'];
        }
        $k = sql_satu('SELECT nama FROM kebutuhan_proyek WHERE id = :id AND proyek_id = :proyek_id FOR UPDATE', ['id' => $id, 'proyek_id' => $proyek_id]);
        if (!$k) {
            return ['status' => 'tidak_ada'];
        }
        if (terisi_kebutuhan_db($id) > 0) {
            return ['status' => 'masih_alokasi'];
        }
        if (kebutuhan_punya_serah_db($id)) {
            return ['status' => 'punya_serah'];
        }
        $ids = array_map('intval', array_column(sql_semua('SELECT id FROM alokasi_barang WHERE kebutuhan_id = :id', ['id' => $id]), 'id'));
        sql_jalankan('DELETE FROM kebutuhan_proyek WHERE id = :id', ['id' => $id]);
        catat_riwayat_proyek('Kebutuhan', $proyek['kode'], $k['nama'] . ' dihapus');
        return ['status' => 'ok', 'alokasi_ids' => $ids];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_alokasi();
    }
    return $hasil;
}

// Isi kebutuhan dari preset: item preset DISALIN (nama, kategori, jumlah); model yang sudah ada (nama/acuan sama) dilewati.
// Baris preset dikunci FOR SHARE agar tidak terhapus di tengah proses.
// Hasil: ['status' => ok | proyek_tutup | preset_tidak_ada, 'tambah' => n, 'lewat' => n, 'preset' => nama].
function isi_kebutuhan_dari_preset(int $proyek_id, int $preset_id): array
{
    $hasil = sql_transaksi(function () use ($proyek_id, $preset_id) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
        }
        if (persiapan_dikonfirmasi_db($proyek_id)) {
            return ['status' => 'persiapan_terkunci'];
        }
        $preset = sql_satu('SELECT nama FROM preset_perlengkapan WHERE id = :id FOR SHARE', ['id' => $preset_id]);
        if (!$preset) {
            return ['status' => 'preset_tidak_ada'];
        }
        $sudah = [];
        foreach (sql_semua('SELECT nama, acuan FROM kebutuhan_proyek WHERE proyek_id = :id', ['id' => $proyek_id]) as $k) {
            $sudah[mb_strtolower($k['acuan'] !== '' ? $k['acuan'] : $k['nama'])] = true;
        }
        $tambah = 0;
        $lewat = 0;
        foreach (sql_semua('SELECT nama_perlengkapan, kategori_id, jumlah FROM detail_preset WHERE preset_id = :id ORDER BY id', ['id' => $preset_id]) as $d) {
            $kunci = mb_strtolower($d['nama_perlengkapan']);
            if (isset($sudah[$kunci])) {
                $lewat++;
                continue;
            }
            sql_jalankan('INSERT INTO kebutuhan_proyek (proyek_id, nama, acuan, kategori_id, jumlah) VALUES (:proyek_id, :nama, :acuan, :kategori_id, :jumlah)', [
                'proyek_id' => $proyek_id, 'nama' => $d['nama_perlengkapan'], 'acuan' => $d['nama_perlengkapan'],
                'kategori_id' => (int) $d['kategori_id'], 'jumlah' => (int) $d['jumlah'],
            ]);
            $sudah[$kunci] = true;
            $tambah++;
        }
        catat_riwayat_proyek('Kebutuhan', $proyek['kode'], $tambah . ' kebutuhan ditambahkan dari preset ' . $preset['nama']);
        return ['status' => 'ok', 'tambah' => $tambah, 'lewat' => $lewat, 'preset' => $preset['nama']];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_alokasi();
    }
    return $hasil;
}

// ---------------------------------------------------------------------------------------------
// Alokasi
// ---------------------------------------------------------------------------------------------

// Alokasi aktif (selain Dibatalkan) lain yang memakai unit ini dan bertabrakan jadwal, atau sudah ada di proyek yang sama.
// Reservasi milik proyek Selesai/Dibatalkan tidak memblokir (barangnya yang masih keluar tetap terlihat lewat serah terima);
// bila proyek itu dibuka kembali, bentroknya dicek ulang oleh sinkron_jadwal_alokasi().
// $termasuk_proyek_sama = false: hanya alokasi proyek lain (dipakai saat jadwal proyek sendiri digeser atau dibuka kembali).
// Dipanggil di dalam transaksi SESUDAH baris unit dikunci, jadi hasilnya tidak bisa didahului request lain.
function bentrok_unit_db(int $unit_id, int $proyek_id, string $mulai, string $selesai, bool $termasuk_proyek_sama = true): ?array
{
    $syarat = $termasuk_proyek_sama
        ? '(k.proyek_id = :proyek_a OR (a.tgl_mulai <= :selesai AND a.tgl_selesai >= :mulai))'
        : '(k.proyek_id <> :proyek_a AND a.tgl_mulai <= :selesai AND a.tgl_selesai >= :mulai)';
    $baris = sql_satu("SELECT a.id, k.proyek_id, TO_CHAR(a.tgl_mulai, 'YYYY-MM-DD') AS tgl_mulai, TO_CHAR(a.tgl_selesai, 'YYYY-MM-DD') AS tgl_selesai
                       FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id JOIN proyek pr ON pr.id = k.proyek_id
                       WHERE a.perlengkapan_id = :unit AND a.status <> 'Dibatalkan' AND pr.status NOT IN ('Selesai', 'Dibatalkan') AND $syarat
                       ORDER BY CASE WHEN k.proyek_id = :proyek_b THEN 0 ELSE 1 END, a.tgl_mulai
                       LIMIT 1", ['unit' => $unit_id, 'proyek_a' => $proyek_id, 'selesai' => $selesai, 'mulai' => $mulai, 'proyek_b' => $proyek_id]);
    if (!$baris) {
        return null;
    }
    $baris['proyek_id'] = (int) $baris['proyek_id'];
    $baris['proyek_sama'] = $baris['proyek_id'] === $proyek_id;
    return $baris;
}

// Jadwal atau status proyek diubah: rentang reservasi alokasi aktif (rentang_alokasi) ikut digeser, dan unit Studio-nya dicek
// bentrok bila proyek (tetap/kembali) aktif. Dipanggil di dalam transaksi ubah_proyek() SESUDAH baris proyek dikunci dan SEBELUM
// proyek di-UPDATE. Urutan kunci sama dengan tambah_alokasi(): proyek -> alokasi -> unit (urut id), jadi perubahan jadwal dan
// alokasi baru untuk unit yang sama berjalan bergantian dan yang kedua melihat hasil yang pertama.
//   - Alokasi yang sudah punya serah terima tidak digeser: perubahan rentang ditolak agar reservasi tetap sesuai transaksinya.
//   - Proyek yang dibuka kembali (Selesai/Dibatalkan -> aktif) dicek bentrok walau rentangnya tetap, karena reservasi proyek
//     tertutup tidak memblokir unit dan bisa sudah dipakai proyek lain.
// Hasil: ['status' => ok | jadwal_terkunci | bentrok, 'jumlah' => alokasi yang digeser, 'keluar' => n, 'bentrok' => [...],
// 'rentang_lama' => [mulai, selesai], 'rentang_baru' => [mulai, selesai]].
function sinkron_jadwal_alokasi(int $proyek_id, array $lama, array $baru): array
{
    $dibuka = !proyek_aktif($lama) && proyek_aktif($baru);
    $hasil = ['status' => 'ok', 'jumlah' => 0, 'dibuka' => $dibuka, 'rentang_lama' => rentang_alokasi($lama), 'rentang_baru' => rentang_alokasi($baru)];
    [$mulai, $selesai] = $hasil['rentang_baru'];
    $geser = $hasil['rentang_lama'] !== $hasil['rentang_baru'];
    if (!$geser && !$dibuka) {
        return $hasil;
    }
    $aktif = sql_semua("SELECT a.id FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
                        WHERE k.proyek_id = :p AND a.status <> 'Dibatalkan' ORDER BY a.id FOR UPDATE OF a", ['p' => $proyek_id]);
    if (!$aktif) {
        return $hasil;
    }
    $keluar = (int) sql_satu("SELECT COUNT(DISTINCT d.alokasi_id) AS n FROM detail_transaksi d
                              JOIN alokasi_barang a ON a.id = d.alokasi_id JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
                              WHERE k.proyek_id = :p AND a.status <> 'Dibatalkan'", ['p' => $proyek_id])['n'];
    if ($geser && $keluar > 0) {
        return ['status' => 'jadwal_terkunci', 'keluar' => $keluar] + $hasil;
    }
    if (!proyek_aktif($baru)) {
        $hasil['jumlah'] = geser_jadwal_alokasi($proyek_id, $mulai, $selesai);
        return $hasil;
    }
    $unit = [];
    foreach (sql_semua("SELECT p.id, p.kode_unit FROM perlengkapan p
                        WHERE p.id IN (SELECT a.perlengkapan_id FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
                                       WHERE k.proyek_id = :p AND a.status <> 'Dibatalkan' AND a.sumber = 'Studio')
                        ORDER BY p.id FOR UPDATE", ['p' => $proyek_id]) as $u) {
        $unit[(int) $u['id']] = $u['kode_unit'];
    }
    $bentrok = [];
    foreach ($unit as $unit_id => $kode_unit) {
        $b = bentrok_unit_db($unit_id, $proyek_id, $mulai, $selesai, false);
        if ($b) {
            $bentrok[] = ['kode_unit' => $kode_unit] + $b;
        }
    }
    if ($bentrok) {
        return ['status' => 'bentrok', 'bentrok' => $bentrok] + $hasil;
    }
    if ($geser) {
        $hasil['jumlah'] = geser_jadwal_alokasi($proyek_id, $mulai, $selesai);
    }
    return $hasil;
}

// UPDATE rentang alokasi aktif proyek (baris sudah dikunci oleh sinkron_jadwal_alokasi). Hasil: jumlah baris yang digeser.
function geser_jadwal_alokasi(int $proyek_id, string $mulai, string $selesai): int
{
    return sql_jalankan("UPDATE alokasi_barang a SET tgl_mulai = :mulai, tgl_selesai = :selesai FROM kebutuhan_proyek k
                         WHERE k.id = a.kebutuhan_id AND k.proyek_id = :p AND a.status <> 'Dibatalkan'",
        ['mulai' => $mulai, 'selesai' => $selesai, 'p' => $proyek_id])->rowCount();
}

// Alokasi baru. $isi: sumber, perlengkapan_id, nama_barang, vendor, pemilik_id, jumlah, status, catatan (sudah divalidasi form).
// Di dalam SATU transaksi: kunci proyek → kunci kebutuhan (sisa tidak bisa terlampaui) → [Studio] kunci unit, cek layak dan
// jadwal → INSERT → riwayat. Hasil: ['status' => ok | proyek_tutup | kebutuhan_tidak_ada | melebihi | unit_tidak_ada | unit_tidak_layak
// | kategori_beda | sudah_di_proyek | bentrok | pemilik_bukan_anggota, ...info].
function tambah_alokasi(int $proyek_id, int $kebutuhan_id, array $isi): array
{
    $hasil = sql_transaksi(function () use ($proyek_id, $kebutuhan_id, $isi) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
        }
        if (persiapan_dikonfirmasi_db($proyek_id)) {
            return ['status' => 'persiapan_terkunci'];
        }
        $k = sql_satu('SELECT nama, kategori_id, jumlah FROM kebutuhan_proyek WHERE id = :id AND proyek_id = :proyek_id FOR UPDATE',
            ['id' => $kebutuhan_id, 'proyek_id' => $proyek_id]);
        if (!$k) {
            return ['status' => 'kebutuhan_tidak_ada'];
        }
        $studio = $isi['sumber'] === 'Studio';
        $jumlah = $studio ? 1 : $isi['jumlah'];
        $sisa = (int) $k['jumlah'] - terisi_kebutuhan_db($kebutuhan_id);
        if ($jumlah > $sisa) {
            return ['status' => 'melebihi', 'sisa' => max(0, $sisa)];
        }
        [$mulai, $selesai] = rentang_alokasi($proyek);
        if ($studio) {
            $unit = sql_satu('SELECT id, kode_unit, kategori_id, kondisi, status_aktif FROM perlengkapan WHERE id = :id FOR UPDATE', ['id' => $isi['perlengkapan_id']]);
            if (!$unit) {
                return ['status' => 'unit_tidak_ada'];
            }
            if ($unit['status_aktif'] !== 'Aktif' || $unit['kondisi'] !== 'Baik') {
                return ['status' => 'unit_tidak_layak', 'kode_unit' => $unit['kode_unit'],
                    'alasan' => $unit['status_aktif'] !== 'Aktif' ? 'Unit nonaktif' : 'Kondisi ' . $unit['kondisi']];
            }
            if ((int) $unit['kategori_id'] !== (int) $k['kategori_id']) {
                return ['status' => 'kategori_beda', 'kode_unit' => $unit['kode_unit']];
            }
            $bentrok = bentrok_unit_db((int) $unit['id'], $proyek_id, $mulai, $selesai);
            if ($bentrok) {
                return ['status' => $bentrok['proyek_sama'] ? 'sudah_di_proyek' : 'bentrok', 'kode_unit' => $unit['kode_unit']] + $bentrok;
            }
        } elseif ($isi['sumber'] === 'Milik Kru') {
            // Pemilik harus PIC atau kru proyek ini. Baris proyek sudah dikunci, jadi tim tidak bisa berubah di tengah proses.
            $anggota = sql_satu('SELECT 1 FROM proyek WHERE id = :p1 AND pic_id = :u1
                                 UNION ALL SELECT 1 FROM anggota_proyek WHERE proyek_id = :p2 AND pengguna_id = :u2',
                ['p1' => $proyek_id, 'u1' => $isi['pemilik_id'], 'p2' => $proyek_id, 'u2' => $isi['pemilik_id']]);
            if (!$anggota) {
                return ['status' => 'pemilik_bukan_anggota'];
            }
        }
        $baris = [
            'kebutuhan_id' => $kebutuhan_id,
            'sumber' => $isi['sumber'],
            'perlengkapan_id' => $studio ? $isi['perlengkapan_id'] : null,
            'nama_barang' => $studio ? '' : $isi['nama_barang'],
            'vendor' => $isi['sumber'] === 'Rental' ? $isi['vendor'] : '',
            'pemilik_id' => $isi['sumber'] === 'Milik Kru' ? $isi['pemilik_id'] : null,
            'jumlah' => $jumlah,
            'tgl_mulai' => $mulai,
            'tgl_selesai' => $selesai,
            'status' => $isi['status'],
            'catatan' => $isi['catatan'],
        ];
        $id = (int) sql_satu('INSERT INTO alokasi_barang (kebutuhan_id, sumber, perlengkapan_id, nama_barang, vendor, pemilik_id, jumlah, tgl_mulai, tgl_selesai, status, catatan)
                              VALUES (:kebutuhan_id, :sumber, :perlengkapan_id, :nama_barang, :vendor, :pemilik_id, :jumlah, :tgl_mulai, :tgl_selesai, :status, :catatan)
                              RETURNING id', $baris)['id'];
        $a = ['id' => $id] + $baris;
        $kali = jumlah_alokasi($a) > 1 ? ' × ' . jumlah_alokasi($a) : '';
        catat_riwayat_proyek('Alokasi', ($studio ? keterangan_sumber($a) : nama_barang_alokasi($a)) . ' · ' . $proyek['kode'],
            $isi['sumber'] . ': ' . nama_barang_alokasi($a) . $kali . ' untuk ' . $k['nama']);
        return ['status' => 'ok', 'id' => $id, 'nama' => nama_barang_alokasi($a) . $kali, 'kebutuhan' => $k['nama']];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_alokasi();
    }
    return $hasil;
}

// Konfirmasi, batalkan, atau hapus alokasi ($aksi = konfirmasi | batalkan | hapus).
// Alokasi yang punya serah terima atau sudah diterima dari vendor dikunci. Pencatatan barang keluar (proses_checkout) juga
// mengunci proyek lalu alokasi lebih dulu, jadi pembatalan dan barang keluar yang bersamaan berjalan bergantian: yang kedua
// melihat hasil yang pertama. detail_transaksi.alokasi_id (FOREIGN KEY tanpa CASCADE) menjadi pengaman terakhir untuk hapus.
// Hasil: ['status' => ok | proyek_tutup | tidak_ada | punya_serah | bukan_rencana | unit_tidak_layak | sudah_batal | rental_diterima, 'lama' => alokasi].
function ubah_status_alokasi(int $proyek_id, int $alokasi_id, string $aksi): array
{
    $hasil = sql_transaksi(function () use ($proyek_id, $alokasi_id, $aksi) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
        }
        if (persiapan_dikonfirmasi_db($proyek_id)) {
            return ['status' => 'persiapan_terkunci'];
        }
        $baris = sql_satu('SELECT ' . KOLOM_ALOKASI . DARI_ALOKASI . ' WHERE a.id = :id AND k.proyek_id = :proyek_id FOR UPDATE OF a',
            ['id' => $alokasi_id, 'proyek_id' => $proyek_id]);
        if (!$baris) {
            return ['status' => 'tidak_ada'];
        }
        $a = alokasi_dari_baris($baris);
        $nama = nama_barang_alokasi($a);
        if (alokasi_punya_serah_db($alokasi_id)) {
            return ['status' => 'punya_serah', 'lama' => $a];
        }
        if ($aksi === 'konfirmasi') {
            if ($a['status'] !== 'Direncanakan') {
                return ['status' => 'bukan_rencana', 'lama' => $a];
            }
            if ($a['sumber'] === 'Studio') {
                $unit = sql_satu('SELECT kode_unit, kondisi, status_aktif FROM perlengkapan WHERE id = :id FOR SHARE', ['id' => $a['perlengkapan_id']]);
                if (!$unit || $unit['status_aktif'] !== 'Aktif' || $unit['kondisi'] !== 'Baik') {
                    return ['status' => 'unit_tidak_layak', 'lama' => $a, 'unit' => $unit];
                }
            }
            sql_jalankan("UPDATE alokasi_barang SET status = 'Dikonfirmasi' WHERE id = :id", ['id' => $alokasi_id]);
            catat_riwayat_proyek('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dikonfirmasi', $a['status'], 'Dikonfirmasi');
            return ['status' => 'ok', 'lama' => $a];
        }
        if ($aksi === 'batalkan' && $a['status'] === 'Dibatalkan') {
            return ['status' => 'sudah_batal', 'lama' => $a];
        }
        if ($a['sumber'] === 'Rental' && $a['diterima_pada'] !== null) {
            return ['status' => 'rental_diterima', 'lama' => $a];
        }
        if ($aksi === 'batalkan') {
            // Centang persiapan ikut dilepas (CHECK ck_alokasi_diperiksa: hanya alokasi Dikonfirmasi yang boleh dicentang).
            sql_jalankan("UPDATE alokasi_barang SET status = 'Dibatalkan', diperiksa = FALSE WHERE id = :id", ['id' => $alokasi_id]);
            catat_riwayat_proyek('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dibatalkan', $a['status'], 'Dibatalkan');
            return ['status' => 'ok', 'lama' => $a];
        }
        sql_jalankan('DELETE FROM alokasi_barang WHERE id = :id', ['id' => $alokasi_id]);
        catat_riwayat_proyek('Alokasi', $nama . ' · ' . $proyek['kode'], 'Alokasi dihapus');
        return ['status' => 'ok', 'lama' => $a];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_alokasi();
    }
    return $hasil;
}

// Catatan Rental (terima dari vendor, kembali ke vendor, jatuh tempo) pada baris alokasi.
// $syarat adalah kondisi SQL tetap dari kode (bukan input pengguna) yang harus masih berlaku saat UPDATE, misalnya
// "diterima_pada IS NULL"; bila request lain sudah lebih dulu mengubahnya, tidak ada baris yang berubah dan hasilnya false.
// $lepas_centang = true: centang persiapan alokasi ini ikut dilepas (penerimaan vendor: barang harus diperiksa ulang).
function simpan_catatan_rental(int $alokasi_id, array $kolom, string $syarat, array $syarat_param, array $riwayat, bool $lepas_centang = false): bool
{
    $set = implode(', ', array_map(fn ($k) => $k . ' = :' . $k, array_keys($kolom))) . ($lepas_centang ? ', diperiksa = FALSE' : '');
    $berhasil = sql_transaksi(function () use ($alokasi_id, $kolom, $set, $syarat, $syarat_param, $riwayat) {
        // Urutan kunci sama dengan modul lain: proyek -> alokasi. Lalu UPDATE sebagai perintah baru yang melihat data terbaru
        // (misalnya barang keluar atau konfirmasi persiapan yang baru saja dicatat browser lain).
        $proyek_id = (int) (sql_satu('SELECT k.proyek_id FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id WHERE a.id = :id', ['id' => $alokasi_id])['proyek_id'] ?? 0);
        if (!$proyek_id || !kunci_proyek($proyek_id)) {
            return false;
        }
        sql_jalankan('SELECT id FROM alokasi_barang WHERE id = :id FOR UPDATE', ['id' => $alokasi_id]);
        $ubah = sql_jalankan("UPDATE alokasi_barang SET $set WHERE id = :id AND sumber = 'Rental' AND ($syarat)", $kolom + $syarat_param + ['id' => $alokasi_id]);
        if ($ubah->rowCount() !== 1) {
            return false;
        }
        catat_riwayat_proyek('Rental', ...$riwayat);
        return true;
    });
    if ($berhasil) {
        muat_ulang_alokasi();
    }
    return $berhasil;
}
