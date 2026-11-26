<?php
defined('ROOT') || exit;

// Konfigurasi lingkungan PhotoOps dari environment variable.
//   PHOTOOPS_ENV=production   mode produksi (Vercel/container): error tidak ditampilkan, database & session wajib dari
//                             environment, cookie Secure, tanggal nyata. Tanpa variabel ini = mode development lokal.
//   PHOTOOPS_TANGGAL_DEMO     tanggal simulasi YYYY-MM-DD (opsional, untuk demo dengan data contoh).
// Kredensial tidak pernah ditulis di kode; lihat includes/koneksi.php dan config/database.example.php.

// Tanggal data contoh (database/002_seed_demo.sql). Dipakai development lokal dan suite uji agar hasilnya deterministik.
const TANGGAL_DEMO_LOKAL = '2026-11-07';

function env_photoops(string $nama): ?string
{
    $nilai = getenv($nama);
    if ($nilai === false) {
        return null;
    }
    $nilai = trim($nilai);
    return $nilai === '' ? null : $nilai;
}

function mode_produksi(): bool
{
    return env_photoops('PHOTOOPS_ENV') === 'production';
}

// Galat konfigurasi diperlakukan seperti database tidak tersedia (kode 503): request berhenti dengan pesan umum,
// alasannya hanya dicatat di log server (tanpa nilai rahasia).
function galat_konfigurasi(string $pesan): RuntimeException
{
    error_log('PhotoOps: konfigurasi tidak valid: ' . $pesan);
    return new RuntimeException('Konfigurasi aplikasi tidak lengkap.', 503);
}

// Produksi: error tidak pernah ditampilkan ke pengguna (bisa memuat query/path), hanya dicatat ke log server.
function terapkan_mode_error(): void
{
    if (!mode_produksi()) {
        return;
    }
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

// Tanggal "hari ini" aplikasi (Asia/Jakarta):
//   1. PHOTOOPS_TANGGAL_DEMO bila diisi (mode demo, termasuk di produksi);
//   2. produksi: tanggal nyata;
//   3. development lokal: tanggal data contoh, sehingga tampilan dan suite uji tetap sama seperti sebelumnya.
function tanggal_aplikasi(): string
{
    $demo = env_photoops('PHOTOOPS_TANGGAL_DEMO');
    if ($demo !== null) {
        $t = DateTimeImmutable::createFromFormat('!Y-m-d', $demo);
        if (!$t || $t->format('Y-m-d') !== $demo) {
            throw galat_konfigurasi('PHOTOOPS_TANGGAL_DEMO harus berformat YYYY-MM-DD.');
        }
        return $demo;
    }
    return mode_produksi() ? date('Y-m-d') : TANGGAL_DEMO_LOKAL;
}
