<?php
defined('ROOT') || exit;

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

function nama_pengguna($id): string
{
    $pengguna = $id ? db_cari('pengguna', (int) $id) : null;
    return $pengguna['nama'] ?? 'Sistem';
}

function cari_pengguna_username(string $username, ?int $kecuali = null): ?array
{
    $kunci = mb_strtolower($username);
    foreach (db('pengguna') as $pengguna) {
        if ($pengguna['id'] !== $kecuali && mb_strtolower($pengguna['username']) === $kunci) {
            return $pengguna;
        }
    }
    return null;
}

function opsi_kru(): array
{
    $hasil = [];
    foreach (db('pengguna') as $pengguna) {
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
    }
    if (!preg_match('/^[a-z0-9._]{3,30}$/', $data['username'])) {
        $galat['username'] = 'Gunakan 3–30 huruf kecil, angka, titik, atau garis bawah.';
    } elseif (cari_pengguna_username($data['username'], $id)) {
        $galat['username'] = 'Nama pengguna sudah dipakai.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $galat['email'] = 'Format email tidak valid.';
    }
    if (!isset(daftar_peran()[$data['peran']])) {
        $galat['peran'] = 'Pilih peran.';
    }
    if (!in_array($data['jenis_anggota'], ['Internal', 'Freelancer'], true)) {
        $galat['jenis_anggota'] = 'Pilih jenis anggota.';
    }
    return $galat;
}
