<?php
// PhotoOps × Supabase — langkah 4: kata sandi baru yang acak untuk 7 akun demo (pengganti kata sandi seed yang diketahui umum).
//   php database/supabase/buat_sandi_demo.php | psql "<koneksi admin>" -v ON_ERROR_STOP=1
// STDOUT: SQL berisi HASH bcrypt saja (dialirkan ke psql). STDERR (layar): daftar username dan kata sandi, ditampilkan SEKALI.
// Simpan daftar itu di password manager tim. Jangan mengalihkan STDERR ke file di repository, dan jangan menempelkannya ke chat
// atau laporan. Bila daftar hilang, jalankan ulang skrip ini (hash lama ditimpa).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Akun di database/002_seed_demo.sql beserta perannya.
const AKUN_DEMO = [
    'fiki.cantik' => 'Super Admin',
    'budi.santoso' => 'Petugas Inventaris',
    'rizky.p' => 'User/Kru',
    'sari.dewi' => 'User/Kru',
    'bagas.s' => 'User/Kru',
    'dimas.a' => 'User/Kru',
    'nina.k' => 'User/Kru',
];
// Hash kata sandi demo lama di 002_seed_demo.sql; setelah langkah ini tidak boleh ada akun yang masih memakainya.
const HASH_SEED_LAMA = '$2y$10$WtzkXqUtHElDQBjEbMZC.u1Hk1Y8XZi40sGANzN/8R83h9YMFnN9S';

// 16 karakter acak (random_int) tanpa huruf/angka yang mudah tertukar; minimal satu huruf besar, huruf kecil, dan angka.
function sandi_acak(): string
{
    $karakter = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    do {
        $sandi = '';
        for ($i = 0; $i < 16; $i++) {
            $sandi .= $karakter[random_int(0, strlen($karakter) - 1)];
        }
    } while (!preg_match('/[A-Z]/', $sandi) || !preg_match('/[a-z]/', $sandi) || !preg_match('/[2-9]/', $sandi));
    return $sandi;
}

$sql = ["\\set ON_ERROR_STOP on", 'BEGIN;'];
$daftar = [];
foreach (AKUN_DEMO as $username => $peran) {
    $sandi = sandi_acak();
    $hash = password_hash($sandi, PASSWORD_DEFAULT);
    // Username dan hash bcrypt hanya berisi [a-z0-9._$/] dan [A-Za-z0-9./$], jadi aman di dalam literal SQL berpetik tunggal.
    $sql[] = "UPDATE photoops.pengguna SET password_hash = '$hash' WHERE username = '$username';";
    $daftar[] = sprintf('  %-14s %-19s %s', $username, $peran, $sandi);
}
$jumlah = count(AKUN_DEMO);
$username_sql = "'" . implode("', '", array_keys(AKUN_DEMO)) . "'";
$hash_lama = HASH_SEED_LAMA;
$sql[] = <<<SQL
DO \$\$
BEGIN
    IF (SELECT COUNT(*) FROM photoops.pengguna WHERE username IN ($username_sql)) <> $jumlah THEN
        RAISE EXCEPTION 'BERHENTI: akun demo di database tidak lengkap; tidak ada kata sandi yang diubah.';
    END IF;
    IF EXISTS (SELECT 1 FROM photoops.pengguna WHERE password_hash = '$hash_lama') THEN
        RAISE EXCEPTION 'BERHENTI: masih ada akun dengan kata sandi seed lama; tidak ada kata sandi yang diubah.';
    END IF;
END \$\$;
SQL;
$sql[] = 'COMMIT;';
$sql[] = "\\echo 'Langkah 4 selesai: kata sandi $jumlah akun demo diganti.'";

echo implode(PHP_EOL, $sql) . PHP_EOL;
fwrite(STDERR, PHP_EOL . 'Kata sandi demo baru (tampil sekali; simpan di password manager tim, jangan di repository/chat):' . PHP_EOL
    . implode(PHP_EOL, $daftar) . PHP_EOL . PHP_EOL);
