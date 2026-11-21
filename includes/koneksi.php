<?php
defined('ROOT') || exit;

// Satu koneksi PostgreSQL (PDO) untuk modul yang sudah memakai database: Login, Hak Akses, Pengguna.
// Kredensial dibaca dari config/database.local.php (diabaikan Git).
// PHOTOOPS_DB_NAME boleh menimpa nama database, misalnya untuk database uji.

function konfigurasi_db(): array
{
    $berkas = ROOT . '/config/database.local.php';
    $k = is_file($berkas) ? require $berkas : [];
    if (getenv('PHOTOOPS_DB_NAME')) {
        $k['dbname'] = getenv('PHOTOOPS_DB_NAME');
    }
    return $k + ['host' => 'localhost', 'port' => '5432', 'dbname' => 'photoops_si3_dev', 'user' => 'photoops_si3_app', 'password' => ''];
}

function pdo(): PDO
{
    static $pdo = null;
    static $gagal = false;
    if ($pdo) {
        return $pdo;
    }
    if ($gagal) {
        throw new RuntimeException('Database tidak tersedia.', 503);
    }
    $gagal = true;
    $k = konfigurasi_db();
    try {
        $koneksi = new PDO("pgsql:host={$k['host']};port={$k['port']};dbname={$k['dbname']}", $k['user'], $k['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $superuser = $koneksi->query("SELECT current_setting('is_superuser')")->fetchColumn();
    } catch (PDOException $e) {
        // Pesan PDO tidak memuat password; detailnya hanya ke log server, bukan ke layar.
        error_log('PhotoOps: koneksi database gagal: ' . $e->getMessage());
        throw new RuntimeException('Database tidak tersedia.', 503);
    }
    if ($superuser === 'on') {
        error_log('PhotoOps: koneksi ditolak karena memakai role superuser.');
        throw new RuntimeException('Database tidak tersedia.', 503);
    }
    $gagal = false;
    return $pdo = $koneksi;
}

// Menjalankan kueri dengan prepared statement. Input pengguna selalu lewat $param, tidak digabung ke teks SQL.
function sql_jalankan(string $sql, array $param = []): PDOStatement
{
    $perintah = pdo()->prepare($sql);
    $perintah->execute($param);
    return $perintah;
}

function sql_semua(string $sql, array $param = []): array
{
    return sql_jalankan($sql, $param)->fetchAll();
}

function sql_satu(string $sql, array $param = []): ?array
{
    $baris = sql_jalankan($sql, $param)->fetch();
    return $baris === false ? null : $baris;
}

// BEGIN ... COMMIT; bila ada error di tengah, semua perubahan dibatalkan (ROLLBACK).
function sql_transaksi(callable $kerja)
{
    $koneksi = pdo();
    $koneksi->beginTransaction();
    try {
        $hasil = $kerja();
        $koneksi->commit();
        return $hasil;
    } catch (Throwable $e) {
        $koneksi->rollBack();
        throw $e;
    }
}

function galat_db(Throwable $e): bool
{
    return $e instanceof PDOException || ($e instanceof RuntimeException && $e->getCode() === 503);
}

// Galat database yang tidak ditangani halaman: dicatat di log server, layar hanya menampilkan pesan umum.
function tangani_galat_db(Throwable $e): void
{
    if (!galat_db($e)) {
        throw $e;
    }
    error_log('PhotoOps: galat database: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Layanan tidak tersedia</title>'
        . '<p>Layanan database PhotoOps sedang tidak tersedia. Coba beberapa saat lagi.</p></html>';
}
