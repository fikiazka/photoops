<?php
defined('ROOT') || exit;

// Akun yang sedang login dibaca ulang dari PostgreSQL setiap request,
// sehingga perubahan peran atau penonaktifan langsung berlaku. Session hanya menyimpan user_id.
function pengguna_masuk(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }
    try {
        return cari_pengguna($id);
    } catch (Throwable $e) {
        if (!galat_db($e)) {
            throw $e;
        }
        // Database tidak bisa diakses: keluarkan sesi, tanpa memakai data akun lain.
        unset($_SESSION['user_id']);
        flash('error', 'Layanan database sedang tidak tersedia. Silakan masuk kembali beberapa saat lagi.');
        return null;
    }
}

function beranda(string $peran): string
{
    $peta = [
        'sa' => 'pages/dashboard-admin.php',
        'pi' => 'pages/dashboard-petugas.php',
        'us' => 'pages/dashboard-user.php',
    ];
    return $peta[$peran] ?? 'index.php';
}

function hak_akses(): array
{
    return [
        'proyek.lihat' => ['sa', 'pi', 'us'],
        'proyek.semua' => ['sa', 'pi'],
        'proyek.kelola' => ['sa'],
        'kebutuhan.kelola' => ['sa', 'pi'],
        'anggota.kelola' => ['sa'],
        'preset.lihat' => ['sa', 'pi'],
        'preset.kelola' => ['sa', 'pi'],
        'perlengkapan.lihat' => ['sa', 'pi'],
        'perlengkapan.kelola' => ['sa', 'pi'],
        'rental.kelola' => ['sa', 'pi'],
        'alokasi.kelola' => ['sa', 'pi'],
        'persiapan.kelola' => ['sa', 'pi'],
        'serah_terima.lihat' => ['sa', 'pi'],
        'serah_terima.kelola' => ['sa', 'pi'],
        'insiden.kelola' => ['sa', 'pi'],
        'pengguna.kelola' => ['sa'],
        'dashboard.admin' => ['sa'],
        'riwayat.lihat' => ['sa', 'pi'],
    ];
}

function boleh(string $kunci): bool
{
    $pengguna = pengguna_masuk();
    if (!$pengguna || $pengguna['status'] !== 'Aktif') {
        return false;
    }
    return in_array($pengguna['peran'], hak_akses()[$kunci] ?? [], true);
}

function wajib_login(): array
{
    $pengguna = pengguna_masuk();
    if (!$pengguna || $pengguna['status'] !== 'Aktif') {
        unset($_SESSION['user_id']);
        if (empty($_SESSION['flash'])) {
            flash('info', 'Silakan masuk terlebih dahulu.');
        }
        arahkan('index.php');
    }
    return $pengguna;
}

function wajib_akses(string $kunci): array
{
    $pengguna = wajib_login();
    if (!boleh($kunci)) {
        flash('error', 'Halaman itu tidak termasuk hak akses ' . nama_peran($pengguna['peran']) . '.');
        arahkan(beranda($pengguna['peran']));
    }
    return $pengguna;
}

function wajib_boleh(string $kunci): void
{
    if (!boleh($kunci)) {
        flash('error', 'Anda tidak memiliki izin untuk tindakan itu.');
        muat_ulang();
    }
}

function menu_peran(string $peran): array
{
    $dashboard = ['kunci' => 'dashboard', 'label' => 'Dashboard', 'ikon' => 'layout-dashboard', 'href' => 'dashboard.php'];
    $menu = [
        'sa' => [
            $dashboard,
            ['kunci' => 'proyek', 'label' => 'Proyek & Acara', 'ikon' => 'calendar-days', 'href' => 'pages/proyek.php'],
            ['kunci' => 'preset', 'label' => 'Preset Perlengkapan', 'ikon' => 'layers', 'href' => 'pages/preset.php'],
            ['kunci' => 'perlengkapan', 'label' => 'Perlengkapan', 'ikon' => 'camera', 'href' => 'pages/perlengkapan.php'],
            ['kunci' => 'serah_terima', 'label' => 'Serah Terima Barang', 'ikon' => 'arrow-left-right', 'href' => 'pages/serah-terima.php'],
            ['kunci' => 'riwayat', 'label' => 'Riwayat Aktivitas', 'ikon' => 'file-clock', 'href' => 'pages/riwayat.php'],
            ['kunci' => 'pengguna', 'label' => 'Manajemen Pengguna', 'ikon' => 'users', 'href' => 'pages/pengguna.php'],
        ],
        'pi' => [
            $dashboard,
            ['kunci' => 'proyek', 'label' => 'Proyek & Acara', 'ikon' => 'calendar-days', 'href' => 'pages/proyek.php'],
            ['kunci' => 'preset', 'label' => 'Preset Perlengkapan', 'ikon' => 'layers', 'href' => 'pages/preset.php'],
            ['kunci' => 'perlengkapan', 'label' => 'Perlengkapan', 'ikon' => 'camera', 'href' => 'pages/perlengkapan.php'],
            ['kunci' => 'serah_terima', 'label' => 'Serah Terima Barang', 'ikon' => 'arrow-left-right', 'href' => 'pages/serah-terima.php'],
            ['kunci' => 'riwayat', 'label' => 'Riwayat Aktivitas', 'ikon' => 'file-clock', 'href' => 'pages/riwayat.php'],
        ],
        'us' => [
            $dashboard,
            ['kunci' => 'proyek', 'label' => 'Proyek Saya', 'ikon' => 'folder-kanban', 'href' => 'pages/proyek.php'],
        ],
    ];
    return $menu[$peran] ?? [];
}
