<?php
defined('ROOT') || exit;

// Data akun dibaca dari tabel PostgreSQL `pengguna`. password_hash hanya dibaca saat login.

const KOLOM_PENGGUNA = 'id, nama, username, email, peran, jenis_anggota, telepon, status';

function daftar_peran(): array
{
    return ['sa' => 'Super Admin', 'pi' => 'Petugas Inventaris', 'us' => 'User'];
}

function nama_peran(string $peran): string
{
    return daftar_peran()[$peran] ?? $peran;
}

function label_peran(array $pengguna): string
{
    if ($pengguna['peran'] === 'us') {
        return 'User · ' . $pengguna['jenis_anggota'];
    }
    return nama_peran($pengguna['peran']);
}

// Semua akun, ber-key id. Dibaca sekali per request lalu disimpan sementara.
function semua_pengguna(): array
{
    static $daftar = null;
    if ($daftar === null) {
        $daftar = [];
        foreach (sql_semua('SELECT ' . KOLOM_PENGGUNA . ' FROM pengguna ORDER BY id') as $baris) {
            $daftar[(int) $baris['id']] = ['id' => (int) $baris['id']] + $baris;
        }
    }
    return $daftar;
}

function cari_pengguna($id): ?array
{
    return $id ? (semua_pengguna()[(int) $id] ?? null) : null;
}

function nama_pengguna($id): string
{
    return cari_pengguna($id)['nama'] ?? 'Sistem';
}

// Login: satu-satunya fungsi yang membaca password_hash.
function cari_akun_login(string $username): ?array
{
    $akun = sql_satu('SELECT ' . KOLOM_PENGGUNA . ', password_hash FROM pengguna WHERE username = :username',
        ['username' => mb_strtolower($username)]);
    if ($akun) {
        $akun['id'] = (int) $akun['id'];
    }
    return $akun;
}

function cari_pengguna_username(string $username, ?int $kecuali = null): ?array
{
    return sql_satu('SELECT ' . KOLOM_PENGGUNA . ' FROM pengguna WHERE username = :username AND id <> :kecuali',
        ['username' => mb_strtolower($username), 'kecuali' => $kecuali ?? 0]);
}

function email_dipakai(string $email, ?int $kecuali = null): bool
{
    return sql_satu('SELECT id FROM pengguna WHERE LOWER(email) = LOWER(:email) AND id <> :kecuali',
        ['email' => $email, 'kecuali' => $kecuali ?? 0]) !== null;
}

function opsi_kru(): array
{
    $hasil = [];
    foreach (semua_pengguna() as $pengguna) {
        if ($pengguna['peran'] === 'us' && $pengguna['status'] === 'Aktif') {
            $hasil[$pengguna['id']] = $pengguna['nama'] . ' · ' . $pengguna['jenis_anggota'];
        }
    }
    asort($hasil);
    return $hasil;
}

function validasi_pengguna(array $data, ?int $id): array
{
    $galat = [];
    if (mb_strlen($data['nama']) < 3) {
        $galat['nama'] = 'Nama minimal 3 karakter.';
    } elseif (mb_strlen($data['nama']) > 60) {
        $galat['nama'] = 'Nama maksimal 60 karakter.';
    }
    if (!preg_match('/^[a-z0-9._]{3,30}$/', $data['username'])) {
        $galat['username'] = 'Gunakan 3–30 huruf kecil, angka, titik, atau garis bawah.';
    } elseif (cari_pengguna_username($data['username'], $id)) {
        $galat['username'] = 'Nama pengguna sudah dipakai.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 80) {
        $galat['email'] = 'Format email tidak valid.';
    } elseif (email_dipakai($data['email'], $id)) {
        $galat['email'] = 'Email sudah dipakai akun lain.';
    }
    if (mb_strlen($data['telepon']) > 20) {
        $galat['telepon'] = 'Telepon maksimal 20 karakter.';
    }
    if (!isset(daftar_peran()[$data['peran']])) {
        $galat['peran'] = 'Pilih peran.';
    }
    if (!in_array($data['jenis_anggota'], ['Internal', 'Freelancer'], true)) {
        $galat['jenis_anggota'] = 'Pilih jenis anggota.';
    }
    return $galat;
}

// Bila dua admin menyimpan username/email yang sama bersamaan, UNIQUE di database yang menolak.
function galat_unik_pengguna(PDOException $e): array
{
    if (str_contains($e->getMessage(), 'uq_pengguna_username')) {
        return ['username' => 'Nama pengguna sudah dipakai.'];
    }
    if (str_contains($e->getMessage(), 'uq_pengguna_email')) {
        return ['email' => 'Email sudah dipakai akun lain.'];
    }
    return [];
}

// Masih adakah Super Admin aktif selain $id? Dipanggil di dalam transaksi; FOR UPDATE mengunci baris
// Super Admin agar dua admin yang saling menurunkan peran tidak bisa membuat sistem tanpa Super Admin.
function ada_super_admin_lain(int $id): bool
{
    $baris = sql_semua("SELECT id FROM pengguna WHERE peran = 'sa' AND status = 'Aktif' FOR UPDATE");
    foreach ($baris as $b) {
        if ((int) $b['id'] !== $id) {
            return true;
        }
    }
    return false;
}

// Data yang merujuk akun di PostgreSQL. Akun yang masih dirujuk tidak boleh dihapus, cukup dinonaktifkan.
// Semua FOREIGN KEY ke pengguna memakai NO ACTION (tanpa CASCADE), jadi database tetap menolak bila daftar ini terlewat.
function relasi_pengguna(int $id): array
{
    $baris = sql_satu('WITH t AS (SELECT CAST(:id AS INTEGER) AS id)
        SELECT
            EXISTS (SELECT 1 FROM proyek p, t WHERE t.id IN (p.pic_id, p.dibuat_oleh)) AS proyek,
            EXISTS (SELECT 1 FROM anggota_proyek a, t WHERE a.pengguna_id = t.id) AS anggota_proyek,
            EXISTS (SELECT 1 FROM preset_perlengkapan s, t WHERE s.dibuat_oleh = t.id) AS preset,
            EXISTS (SELECT 1 FROM alokasi_barang b, t WHERE t.id IN (b.pemilik_id, b.diterima_oleh, b.dikembalikan_vendor_oleh)) AS alokasi,
            EXISTS (SELECT 1 FROM persiapan_proyek s, t WHERE t.id IN (s.diperbarui_oleh, s.dikonfirmasi_oleh, s.batal_oleh, s.batal_konfirmasi_oleh)) AS persiapan,
            EXISTS (SELECT 1 FROM transaksi_barang x, t WHERE t.id IN (x.petugas_id, x.penerima_id, x.penyerah_id)) AS transaksi,
            EXISTS (SELECT 1 FROM laporan_insiden i, t WHERE i.pelapor_id = t.id) AS insiden,
            EXISTS (SELECT 1 FROM riwayat_aktivitas r, t WHERE r.pengguna_id = t.id) AS riwayat
        FROM t', ['id' => $id]);
    return array_values(array_intersect_key(LABEL_RELASI_PENGGUNA, array_filter($baris, fn ($ada) => $ada === true || $ada === 't')));
}

const LABEL_RELASI_PENGGUNA = [
    'proyek' => 'Proyek', 'anggota_proyek' => 'Tim proyek', 'preset' => 'Preset perlengkapan',
    'alokasi' => 'Alokasi barang', 'persiapan' => 'Persiapan proyek', 'transaksi' => 'Serah terima',
    'insiden' => 'Laporan insiden', 'riwayat' => 'Riwayat aktivitas',
];

// KEBIJAKAN SEMENTARA (sistem hybrid): hapus permanen DITOLAK untuk semua akun.
// Proyek, Anggota Tim, dan modul operasional lain masih disimpan di PHP Session per browser. Penugasan akun
// yang dibuat di browser lain tidak terlihat dari server, dan tabel pengguna tidak mencatat kapan akun dibuat
// atau pernah masuk. Jadi "akun belum pernah dipakai" tidak bisa dibuktikan; gunakan Nonaktifkan Akun.
// Ubah ke true hanya setelah modul operasional dimigrasikan ke PostgreSQL, ketika relasi_pengguna()
// dan FOREIGN KEY sudah melihat seluruh pemakaian akun.
const HAPUS_PERMANEN_TERSEDIA = false;

// Hasil: [status, daftar relasi]. Status: ok | belum_terverifikasi | dipakai | super_admin_terakhir | sudah_berubah.
function hapus_pengguna(array $target): array
{
    if (!HAPUS_PERMANEN_TERSEDIA) {
        return ['belum_terverifikasi', []];
    }
    return hapus_pengguna_db($target);
}

// Hapus permanen di PostgreSQL: hanya bila tidak ada rujukan sama sekali. DELETE dan catatan riwayatnya
// satu transaksi; riwayat mencatat Super Admin pelaku (bukan akun yang dihapus) sehingga tidak bentrok FOREIGN KEY.
function hapus_pengguna_db(array $target): array
{
    return sql_transaksi(function () use ($target) {
        if ($target['peran'] === 'sa' && $target['status'] === 'Aktif' && !ada_super_admin_lain($target['id'])) {
            return ['super_admin_terakhir', []];
        }
        $relasi = relasi_pengguna($target['id']);
        if ($relasi) {
            return ['dipakai', $relasi];
        }
        if (sql_jalankan('DELETE FROM pengguna WHERE id = :id', ['id' => $target['id']])->rowCount() === 0) {
            return ['sudah_berubah', []];
        }
        catat_riwayat_pengguna($target['nama'], 'Akun dihapus permanen', '@' . $target['username'] . ' · ' . nama_peran($target['peran']));
        return ['ok', []];
    });
}

// Kata sandi sementara 12 karakter acak (random_int), tanpa huruf/angka yang mudah tertukar (0/O, 1/l/I).
function buat_sandi_sementara(): string
{
    $karakter = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    do {
        $sandi = '';
        for ($i = 0; $i < 12; $i++) {
            $sandi .= $karakter[random_int(0, strlen($karakter) - 1)];
        }
    } while (!preg_match('/[A-Za-z]/', $sandi) || !preg_match('/[2-9]/', $sandi));
    return $sandi;
}

// Sandi sementara dititipkan di session hanya sampai halaman Pengguna ditampilkan sekali, lalu dihapus.
function titip_sandi_sementara(string $nama, string $username, string $sandi, string $jenis): void
{
    $_SESSION['_sandi_sementara'] = ['nama' => $nama, 'username' => $username, 'sandi' => $sandi, 'jenis' => $jenis, 'waktu' => time()];
}

function ambil_sandi_sementara(): ?array
{
    $isi = $_SESSION['_sandi_sementara'] ?? null;
    unset($_SESSION['_sandi_sementara']);
    return is_array($isi) && time() - $isi['waktu'] <= 120 ? $isi : null;
}
