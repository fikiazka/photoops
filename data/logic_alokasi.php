<?php
defined('ROOT') || exit;

// ---------------------------------------------------------------------------------------------
// Kebutuhan proyek dan alokasi barang (Sprint Backend 3C) di PostgreSQL: tabel kebutuhan_proyek dan alokasi_barang.
//   - Baris alokasi dibaca bersama proyek_id kebutuhannya (JOIN), sehingga kode lama yang memakai $alokasi['proyek_id'] tetap jalan.
//   - Kolom Rental (diterima_*, sewa_mulai, jatuh_tempo_vendor, kontak_vendor, dikembalikan_vendor_*) ikut di baris alokasi.
//   - Checklist persiapan (id alokasi yang dicentang) dan serah terima masih di PHP Session dan merujuk id alokasi ini.
//   - Penguncian: setiap perubahan mengunci baris proyek (FOR UPDATE) lebih dulu, sehingga perubahan kebutuhan/alokasi/tim
//     pada proyek yang sama berjalan bergantian. Alokasi Studio juga mengunci baris unit (perlengkapan) sehingga dua proyek
//     tidak bisa memesan unit yang sama pada jadwal yang bertabrakan, walaupun dikirim bersamaan.
// ---------------------------------------------------------------------------------------------

const KOLOM_ALOKASI = "a.id, k.proyek_id, a.kebutuhan_id, a.sumber, a.perlengkapan_id, a.nama_barang, a.vendor, a.pemilik_id, a.jumlah,
    TO_CHAR(a.tgl_mulai, 'YYYY-MM-DD') AS tgl_mulai, TO_CHAR(a.tgl_selesai, 'YYYY-MM-DD') AS tgl_selesai, a.status, a.catatan,
    TO_CHAR(a.diterima_pada, 'YYYY-MM-DD HH24:MI') AS diterima_pada, a.diterima_oleh, a.kondisi_terima, a.catatan_terima,
    TO_CHAR(a.sewa_mulai, 'YYYY-MM-DD') AS sewa_mulai, TO_CHAR(a.jatuh_tempo_vendor, 'YYYY-MM-DD') AS jatuh_tempo_vendor, a.kontak_vendor,
    TO_CHAR(a.dikembalikan_vendor_pada, 'YYYY-MM-DD HH24:MI') AS dikembalikan_vendor_pada, a.dikembalikan_vendor_oleh,
    a.kondisi_kembali_vendor, a.catatan_kembali_vendor";

const DARI_ALOKASI = ' FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id';

function alokasi_dari_baris(array $baris): array
{
    foreach (['id', 'proyek_id', 'kebutuhan_id', 'jumlah'] as $kolom) {
        $baris[$kolom] = (int) $baris[$kolom];
    }
    foreach (['perlengkapan_id', 'pemilik_id', 'diterima_oleh', 'dikembalikan_vendor_oleh'] as $kolom) {
        $baris[$kolom] = $baris[$kolom] === null ? null : (int) $baris[$kolom];
    }
    return $baris;
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
// (checklist persiapan masih di session dan bergantung pada alokasi).
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

// Jumlah unit yang sudah terisi (alokasi selain Dibatalkan) untuk satu kebutuhan, dibaca langsung dari tabel.
function terisi_kebutuhan_db(int $kebutuhan_id): int
{
    return (int) sql_satu("SELECT COALESCE(SUM(CASE WHEN sumber = 'Studio' THEN 1 ELSE jumlah END), 0) AS n
                           FROM alokasi_barang WHERE kebutuhan_id = :id AND status <> 'Dibatalkan'", ['id' => $kebutuhan_id])['n'];
}

// Serah terima di PostgreSQL (data contoh) yang merujuk alokasi kebutuhan ini. Serah terima baru masih di session
// dan diperiksa terpisah oleh pemanggil (kebutuhan_punya_serah / serah_alokasi).
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
// Dipanggil di dalam transaksi SESUDAH baris unit dikunci, jadi hasilnya tidak bisa didahului request lain.
function bentrok_unit_db(int $unit_id, int $proyek_id, string $mulai, string $selesai): ?array
{
    $baris = sql_satu("SELECT a.id, k.proyek_id, TO_CHAR(a.tgl_mulai, 'YYYY-MM-DD') AS tgl_mulai, TO_CHAR(a.tgl_selesai, 'YYYY-MM-DD') AS tgl_selesai
                       FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
                       WHERE a.perlengkapan_id = :unit AND a.status <> 'Dibatalkan'
                         AND (k.proyek_id = :proyek_a OR (a.tgl_mulai <= :selesai AND a.tgl_selesai >= :mulai))
                       ORDER BY CASE WHEN k.proyek_id = :proyek_b THEN 0 ELSE 1 END, a.tgl_mulai
                       LIMIT 1", ['unit' => $unit_id, 'proyek_a' => $proyek_id, 'selesai' => $selesai, 'mulai' => $mulai, 'proyek_b' => $proyek_id]);
    if (!$baris) {
        return null;
    }
    $baris['proyek_id'] = (int) $baris['proyek_id'];
    $baris['proyek_sama'] = $baris['proyek_id'] === $proyek_id;
    return $baris;
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
// Alokasi yang punya serah terima di PostgreSQL atau sudah diterima dari vendor dikunci. Serah terima yang masih di session
// diperiksa pemanggil (serah_alokasi) sebelum fungsi ini dipanggil.
// Hasil: ['status' => ok | proyek_tutup | tidak_ada | punya_serah | bukan_rencana | unit_tidak_layak | sudah_batal | rental_diterima, 'lama' => alokasi].
function ubah_status_alokasi(int $proyek_id, int $alokasi_id, string $aksi): array
{
    $hasil = sql_transaksi(function () use ($proyek_id, $alokasi_id, $aksi) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
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
            sql_jalankan("UPDATE alokasi_barang SET status = 'Dibatalkan' WHERE id = :id", ['id' => $alokasi_id]);
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
function simpan_catatan_rental(int $alokasi_id, array $kolom, string $syarat, array $syarat_param, array $riwayat): bool
{
    $set = implode(', ', array_map(fn ($k) => $k . ' = :' . $k, array_keys($kolom)));
    $berhasil = sql_transaksi(function () use ($alokasi_id, $kolom, $set, $syarat, $syarat_param, $riwayat) {
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
