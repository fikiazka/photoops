<?php
defined('ROOT') || exit;

// Preset Perlengkapan dan isinya (Sprint Backend 3B) di PostgreSQL: tabel preset_perlengkapan dan detail_preset.
// Item preset merujuk ke NAMA model (teks) + kategori, bukan ke unit tertentu. Kebutuhan proyek yang diisi dari preset
// berupa salinan (tidak ada foreign key ke preset), sehingga mengubah atau menghapus preset tidak mengubah kebutuhan proyek.

const KOLOM_PRESET = "id, nama, deskripsi, dibuat_oleh, TO_CHAR(diubah_pada, 'YYYY-MM-DD HH24:MI') AS diubah_pada";

// Semua preset, ber-key id. Dibaca sekali per request; $muat_ulang = true sesudah preset diubah.
function semua_preset(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT ' . KOLOM_PRESET . ' FROM preset_perlengkapan ORDER BY id') as $baris) {
            $baris['id'] = (int) $baris['id'];
            $baris['dibuat_oleh'] = (int) $baris['dibuat_oleh'];
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

function cari_preset($id): ?array
{
    return semua_preset()[(int) $id] ?? null;
}

// Semua item preset (detail_preset), ber-key id.
function semua_item_preset(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT id, preset_id, nama_perlengkapan, kategori_id, jumlah FROM detail_preset ORDER BY id') as $baris) {
            foreach (['id', 'preset_id', 'kategori_id', 'jumlah'] as $kolom) {
                $baris[$kolom] = (int) $baris[$kolom];
            }
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

// Isi satu preset, urut per kategori lalu nama model (sama dengan urutan versi session).
function item_preset(int $preset_id): array
{
    $hasil = array_values(array_filter(semua_item_preset(), fn ($d) => $d['preset_id'] === $preset_id));
    usort($hasil, fn ($a, $b) => $a['kategori_id'] <=> $b['kategori_id'] ?: strcmp($a['nama_perlengkapan'], $b['nama_perlengkapan']));
    return $hasil;
}

function muat_ulang_preset(): void
{
    semua_preset(true);
    semua_item_preset(true);
}

// Validasi form preset (panjang mengikuti kolom VARCHAR). Nama unik dicek ulang di dalam transaksi simpan_preset().
function validasi_preset(array $data): array
{
    $galat = [];
    $panjang = mb_strlen($data['nama']);
    if ($panjang < 3) {
        $galat['nama'] = 'Nama preset minimal 3 karakter.';
    } elseif ($panjang > 50) {
        $galat['nama'] = 'Nama preset maksimal 50 karakter.';
    }
    if (mb_strlen($data['deskripsi']) > 160) {
        $galat['deskripsi'] = 'Deskripsi maksimal 160 karakter.';
    }
    return $galat;
}

// Validasi form item preset. Jumlah harus bilangan bulat 1–20 (CHECK di detail_preset).
function validasi_item_preset(array $data): array
{
    $galat = [];
    $panjang = mb_strlen($data['nama_perlengkapan']);
    if ($panjang < 2) {
        $galat['nama_perlengkapan'] = 'Isi model barang.';
    } elseif ($panjang > 60) {
        $galat['nama_perlengkapan'] = 'Nama model maksimal 60 karakter.';
    }
    if (!preg_match('/^\d{1,9}$/', $data['kategori_id']) || !cari_kategori($data['kategori_id'])) {
        $galat['kategori_id'] = 'Pilih kategori.';
    }
    if (!preg_match('/^\d{1,2}$/', $data['jumlah']) || (int) $data['jumlah'] < 1 || (int) $data['jumlah'] > 20) {
        $galat['jumlah'] = 'Jumlah 1 sampai 20.';
    }
    return $galat;
}

function catat_waktu_preset(int $preset_id): void
{
    sql_jalankan('UPDATE preset_perlengkapan SET diubah_pada = :waktu WHERE id = :id', ['waktu' => sekarang(), 'id' => $preset_id]);
}

// Tambah ($id = 0) atau ubah preset beserta riwayatnya dalam SATU transaksi.
// UNIQUE di kolom nama membedakan huruf besar/kecil, sedangkan aturan aplikasi tidak; tabel dikunci (mode SHARE ROW
// EXCLUSIVE, hanya bentrok dengan penulisan preset lain) agar dua browser tidak menyimpan nama yang sama bersamaan.
// Hasil: ['status' => ok | tidak_ada | nama_dipakai, 'id' => ..., 'baru' => bool].
function simpan_preset(int $id, array $isi, int $pembuat_id): array
{
    $hasil = sql_transaksi(function () use ($id, $isi, $pembuat_id) {
        sql_jalankan('LOCK TABLE preset_perlengkapan IN SHARE ROW EXCLUSIVE MODE');
        $lama = null;
        if ($id) {
            $lama = sql_satu('SELECT nama, deskripsi FROM preset_perlengkapan WHERE id = :id', ['id' => $id]);
            if (!$lama) {
                return ['status' => 'tidak_ada'];
            }
        }
        if (sql_satu('SELECT 1 FROM preset_perlengkapan WHERE LOWER(nama) = LOWER(:nama) AND id <> :id', ['nama' => $isi['nama'], 'id' => $id])) {
            return ['status' => 'nama_dipakai'];
        }
        if ($lama) {
            sql_jalankan('UPDATE preset_perlengkapan SET nama = :nama, deskripsi = :deskripsi, diubah_pada = :waktu WHERE id = :id',
                ['nama' => $isi['nama'], 'deskripsi' => $isi['deskripsi'], 'waktu' => sekarang(), 'id' => $id]);
            $ganti_nama = $lama['nama'] !== $isi['nama'];
            catat_riwayat_preset($isi['nama'], $ganti_nama ? 'Nama preset diubah' : 'Preset diperbarui',
                $ganti_nama ? $lama['nama'] : '', $ganti_nama ? $isi['nama'] : '');
            return ['status' => 'ok', 'id' => $id, 'baru' => false];
        }
        $baru = (int) sql_satu('INSERT INTO preset_perlengkapan (nama, deskripsi, dibuat_oleh, diubah_pada)
                                VALUES (:nama, :deskripsi, :dibuat_oleh, :waktu) RETURNING id',
            ['nama' => $isi['nama'], 'deskripsi' => $isi['deskripsi'], 'dibuat_oleh' => $pembuat_id, 'waktu' => sekarang()])['id'];
        catat_riwayat_preset($isi['nama'], 'Preset baru dibuat');
        return ['status' => 'ok', 'id' => $baru, 'baru' => true];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_preset();
    }
    return $hasil;
}

// Hapus preset. Itemnya ikut terhapus (ON DELETE CASCADE). Tidak ada tabel lain yang merujuk preset,
// jadi kebutuhan proyek yang dulu diisi dari preset ini tetap utuh. Hasil: nama preset, atau null bila sudah tidak ada.
function hapus_preset(int $id): ?string
{
    $nama = sql_transaksi(function () use ($id) {
        $baris = sql_satu('DELETE FROM preset_perlengkapan WHERE id = :id RETURNING nama', ['id' => $id]);
        if (!$baris) {
            return null;
        }
        catat_riwayat_preset($baris['nama'], 'Preset dihapus');
        return $baris['nama'];
    });
    if ($nama !== null) {
        muat_ulang_preset();
    }
    return $nama;
}

// Tambah ($item_id = 0) atau ubah item preset. Baris preset dikunci (FOR UPDATE) sehingga perubahan isi preset yang sama
// berjalan bergantian; cek duplikat model (tanpa membedakan huruf besar/kecil) di dalam kunci itu tidak bisa didahului
// browser lain. Item, waktu ubah preset, dan riwayat tersimpan dalam SATU transaksi.
// Hasil: ['status' => ok | preset_tidak_ada | item_tidak_ada | kategori_tidak_ada | duplikat, 'preset' => nama preset].
function simpan_item_preset(int $preset_id, int $item_id, array $isi): array
{
    $hasil = sql_transaksi(function () use ($preset_id, $item_id, $isi) {
        $preset = sql_satu('SELECT nama FROM preset_perlengkapan WHERE id = :id FOR UPDATE', ['id' => $preset_id]);
        if (!$preset) {
            return ['status' => 'preset_tidak_ada'];
        }
        $lama = null;
        if ($item_id) {
            $lama = sql_satu('SELECT nama_perlengkapan, jumlah FROM detail_preset WHERE id = :id AND preset_id = :preset_id',
                ['id' => $item_id, 'preset_id' => $preset_id]);
            if (!$lama) {
                return ['status' => 'item_tidak_ada', 'preset' => $preset['nama']];
            }
        }
        if (!sql_satu('SELECT 1 FROM kategori_perlengkapan WHERE id = :id', ['id' => $isi['kategori_id']])) {
            return ['status' => 'kategori_tidak_ada', 'preset' => $preset['nama']];
        }
        if (sql_satu('SELECT 1 FROM detail_preset WHERE preset_id = :preset_id AND LOWER(nama_perlengkapan) = LOWER(:nama) AND id <> :id',
            ['preset_id' => $preset_id, 'nama' => $isi['nama_perlengkapan'], 'id' => $item_id])) {
            return ['status' => 'duplikat', 'preset' => $preset['nama']];
        }
        if ($lama) {
            sql_jalankan('UPDATE detail_preset SET nama_perlengkapan = :nama_perlengkapan, kategori_id = :kategori_id, jumlah = :jumlah WHERE id = :id',
                $isi + ['id' => $item_id]);
            catat_riwayat_preset($preset['nama'], $isi['nama_perlengkapan'] . ' diperbarui',
                $lama['nama_perlengkapan'] . ' × ' . $lama['jumlah'], $isi['nama_perlengkapan'] . ' × ' . $isi['jumlah']);
        } else {
            sql_jalankan('INSERT INTO detail_preset (preset_id, nama_perlengkapan, kategori_id, jumlah)
                          VALUES (:preset_id, :nama_perlengkapan, :kategori_id, :jumlah)', $isi + ['preset_id' => $preset_id]);
            catat_riwayat_preset($preset['nama'], $isi['nama_perlengkapan'] . ' × ' . $isi['jumlah'] . ' ditambahkan');
        }
        catat_waktu_preset($preset_id);
        return ['status' => 'ok', 'preset' => $preset['nama']];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_preset();
    }
    return $hasil;
}

// Hapus satu item dari presetnya.
// Hasil: ['preset_id' => ..., 'preset' => nama preset, 'item' => baris item], atau null bila item sudah tidak ada.
function hapus_item_preset(int $item_id): ?array
{
    $hasil = sql_transaksi(function () use ($item_id) {
        $item = sql_satu('SELECT preset_id FROM detail_preset WHERE id = :id', ['id' => $item_id]);
        if (!$item) {
            return null;
        }
        $preset = sql_satu('SELECT nama FROM preset_perlengkapan WHERE id = :id FOR UPDATE', ['id' => $item['preset_id']]);
        $baris = sql_satu('DELETE FROM detail_preset WHERE id = :id AND preset_id = :preset_id RETURNING nama_perlengkapan, jumlah',
            ['id' => $item_id, 'preset_id' => $item['preset_id']]);
        if (!$preset || !$baris) {
            return null;
        }
        catat_waktu_preset((int) $item['preset_id']);
        catat_riwayat_preset($preset['nama'], $baris['nama_perlengkapan'] . ' dihapus dari preset');
        return ['preset_id' => (int) $item['preset_id'], 'preset' => $preset['nama'], 'item' => $baris];
    });
    if ($hasil !== null) {
        muat_ulang_preset();
    }
    return $hasil;
}
