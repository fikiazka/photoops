<?php
// Uji PHP Session PostgreSQL (PHOTOOPS_SESSION=db) secara end-to-end.
//   PHOTOOPS_DB_NAME=<database_uji>_test php tests/uji_sesi_db.php
// Database uji harus berakhiran _test dan sudah memiliki tabel sesi_php (dibuat dengan database/supabase/0*.sql dalam
// mode simulasi lokal). Akun demo memakai kata sandi lokal nicio123. Uji ini hanya menulis tabel sesi_php.
//
// Cara kerja: dua server PHP terpisah (php -S, port berbeda) menjalankan kode asli dengan session di PostgreSQL, meniru
// dua instance container Vercel tanpa disk bersama. Request HTTP nyata (cookie, CSRF, redirect) dikirim ke keduanya.
// Penguncian session diuji dengan dua proses PHP CLI independen yang memakai session ID yang sama.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('AKAR_UJI', dirname(__DIR__));
const NAMA_COOKIE = 'photoops';
const SANDI_DEMO = 'nicio123';

// ---------------------------------------------------------------------------------------------
// Mode anak --konfig: hasil konfigurasi_db() dari environment (tanpa membuka koneksi).
// ---------------------------------------------------------------------------------------------
if (($argv[1] ?? '') === '--konfig') {
    define('ROOT', AKAR_UJI);
    // Pesan log aplikasi (galat_konfigurasi) dipisah dari keluaran JSON.
    $log = tempnam(sys_get_temp_dir(), 'photoops_konfig');
    ini_set('error_log', $log);
    require AKAR_UJI . '/includes/konfigurasi.php';
    require AKAR_UJI . '/includes/koneksi.php';
    try {
        $kf = konfigurasi_db();
        $hasil = ['diterima' => true, 'sslrootcert' => $kf['sslrootcert'], 'sslmode' => $kf['sslmode']];
    } catch (RuntimeException $e) {
        $hasil = ['diterima' => false, 'kode' => $e->getCode(), 'log' => trim((string) file_get_contents($log))];
    }
    @unlink($log);
    echo json_encode($hasil);
    exit;
}

// ---------------------------------------------------------------------------------------------
// Mode anak --sisa-kunci: koneksi aplikasi (pdo()) yang sudah memegang beberapa jenis kunci, lalu menjalankan
// SQL_CEK_KONEKSI di koneksi yang SAMA (meniru backend yang dipakai ulang pooler tanpa reset).
// ---------------------------------------------------------------------------------------------
if (($argv[1] ?? '') === '--sisa-kunci') {
    define('ROOT', AKAR_UJI);
    require AKAR_UJI . '/includes/konfigurasi.php';
    require AKAR_UJI . '/includes/koneksi.php';
    $p = pdo();
    $hitung = fn (string $syarat) => (int) $p->query("SELECT COUNT(*) FROM pg_locks WHERE pid = pg_backend_pid() AND $syarat")->fetchColumn();
    $p->beginTransaction();
    $p->query('SELECT pg_advisory_lock(470117, 77)')->fetchColumn();        // advisory tingkat sesi (sisa pemakai lama)
    $p->query('SELECT pg_advisory_xact_lock(470117, 78)')->fetchColumn();   // advisory tingkat transaksi
    $p->query('SELECT id FROM perlengkapan ORDER BY id LIMIT 1 FOR UPDATE')->fetchAll(); // kunci baris bisnis
    $sebelum = ['sesi' => $hitung("locktype = 'advisory' AND objid = 77"), 'xact' => $hitung("locktype = 'advisory' AND objid = 78"),
        'baris' => $hitung("locktype = 'relation' AND relation = 'perlengkapan'::regclass")];
    $istimewa = $p->query(SQL_CEK_KONEKSI)->fetchColumn();
    $sesudah = ['sesi' => $hitung("locktype = 'advisory' AND objid = 77"), 'xact' => $hitung("locktype = 'advisory' AND objid = 78"),
        'baris' => $hitung("locktype = 'relation' AND relation = 'perlengkapan'::regclass")];
    $p->rollBack();
    echo json_encode(['sebelum' => $sebelum, 'sesudah' => $sesudah, 'istimewa' => $istimewa]);
    exit;
}

// ---------------------------------------------------------------------------------------------
// Mode anak: proses PHP independen yang memakai session tertentu (session ID dari argumen).
// ---------------------------------------------------------------------------------------------
if (in_array($argv[1] ?? '', ['--anak', '--baca', '--gc', '--cookie', '--galat'], true)) {
    $mode = $argv[1];
    if ($mode === '--cookie') {
        $_SERVER['HTTPS'] = 'on';
    } else {
        session_id($argv[2]);
    }
    require AKAR_UJI . '/includes/bootstrap.php';
    if (in_array($mode, ['--anak', '--galat'], true)) {
        // Dilaporkan PALING AKHIR (sesudah session ditutup di shutdown), di koneksi PDO yang sama: berapa advisory lock
        // yang masih dipegang koneksi ini. Harus 0, karena koneksi bisa dipakai ulang pooler.
        register_shutdown_function(function () {
            register_shutdown_function(function () {
                register_shutdown_function(function () {
                    $n = pdo()->query("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()")->fetchColumn();
                    echo "\n@@KUNCI@@$n\n";
                });
            });
        });
    }
    if ($mode === '--galat') {
        // Request yang gagal di tengah halaman sesudah session dibuka.
        $_SESSION['uji_galat_' . $argv[3]] = 1;
        if ($argv[3] === 'db') {
            throw new RuntimeException('Simulasi galat database', 503);
        }
        throw new LogicException('Simulasi galat program');
    }
    if ($mode === '--anak') {
        // Tulis satu kunci, tahan session selama $argv[4] detik (seperti request yang lama), lalu selesai.
        $mulai = microtime(true);
        $kunci_awal = array_keys($_SESSION);
        $_SESSION['uji_' . $argv[3]] = $mulai;
        usleep((int) ((float) $argv[4] * 1e6));
        echo json_encode(['mulai' => $mulai, 'selesai' => microtime(true), 'kunci_awal' => $kunci_awal]);
    } elseif ($mode === '--baca') {
        echo json_encode(['id' => session_id(), 'isi' => $_SESSION]);
        session_abort();
    } elseif ($mode === '--gc') {
        echo json_encode(['terhapus' => session_gc()]);
        session_abort();
    } else {
        echo json_encode(['nama' => session_name(), 'param' => session_get_cookie_params(), 'strict' => ini_get('session.use_strict_mode')]);
        session_abort();
    }
    exit;
}

// ---------------------------------------------------------------------------------------------
// Persiapan dan pengaman
// ---------------------------------------------------------------------------------------------
$k = require AKAR_UJI . '/config/database.local.php';
if (getenv('PHOTOOPS_DB_NAME')) {
    $k['dbname'] = getenv('PHOTOOPS_DB_NAME');
}
if (!preg_match('/_test$/', $k['dbname'])) {
    echo "[BERHENTI] Jalankan dengan PHOTOOPS_DB_NAME=<database uji berakhiran _test> (sekarang: {$k['dbname']}).\n";
    exit(1);
}
$pdo = new PDO("pgsql:host={$k['host']};port={$k['port']};dbname={$k['dbname']}", $k['user'], $k['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
if ($pdo->query('SELECT current_database()')->fetchColumn() !== $k['dbname']
    || $pdo->query("SELECT to_regclass('sesi_php') IS NULL")->fetchColumn()
    || $pdo->query("SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'supabase_admin')")->fetchColumn()) {
    echo "[BERHENTI] Database uji harus lokal, bernama {$k['dbname']}, dan memiliki tabel sesi_php.\n";
    exit(1);
}

$lulus = 0;
$gagal = 0;
function cek(string $nama, bool $ok, string $info = ''): void
{
    global $lulus, $gagal;
    echo ($ok ? '[LULUS] ' : '[GAGAL] ') . $nama . ($ok || $info === '' ? '' : " -- $info") . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
}

function satu(string $sql, array $p = [])
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

// Lingkungan server/anak: session PostgreSQL, database uji, mode development (bukan produksi).
function env_uji(array $tambah = []): array
{
    global $k;
    $env = getenv();
    foreach (['PHOTOOPS_ENV', 'PHOTOOPS_DB_HOST', 'PHOTOOPS_DB_PORT', 'PHOTOOPS_DB_USER', 'PHOTOOPS_DB_PASSWORD',
        'PHOTOOPS_DB_SSLMODE', 'PHOTOOPS_DB_SSLROOTCERT', 'PHOTOOPS_TANGGAL_DEMO'] as $v) {
        unset($env[$v]);
    }
    return array_filter($tambah + ['PHOTOOPS_SESSION' => 'db', 'PHOTOOPS_DB_NAME' => $k['dbname']] + $env, fn ($v) => $v !== null);
}

$server = [];
function nyalakan_server(int $port, array $env): void
{
    global $server;
    $log = tempnam(sys_get_temp_dir(), 'photoops_srv');
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', AKAR_UJI], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipa, AKAR_UJI, $env);
    $server[] = [$p, $log];
    for ($i = 0; $i < 100; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $no, $teks, 0.2);
        if ($s) {
            fclose($s);
            return;
        }
        usleep(100000);
    }
    echo "[BERHENTI] Server uji di port $port tidak menyala.\n";
    exit(1);
}

register_shutdown_function(function () {
    global $server;
    foreach ($server as [$p, $log]) {
        proc_terminate($p);
        proc_close($p);
        @unlink($log);
    }
});

function kepala_http(int $port, string $metode, string $jalur, ?string $sid, ?array $post): string
{
    $isi = $post === null ? '' : http_build_query($post);
    $h = "$metode $jalur HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n";
    if ($sid !== null) {
        $h .= 'Cookie: ' . NAMA_COOKIE . "=$sid\r\n";
    }
    if ($post !== null) {
        $h .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($isi) . "\r\n";
    }
    return $h . "\r\n" . $isi;
}

function urai_http(string $mentah): array
{
    [$kepala, $isi] = array_pad(explode("\r\n\r\n", $mentah, 2), 2, '');
    $baris = explode("\r\n", $kepala);
    preg_match('/^HTTP\/\S+ (\d+)/', $baris[0], $m);
    $hasil = ['status' => (int) ($m[1] ?? 0), 'lokasi' => '', 'sid' => null, 'cookie' => '', 'html' => $isi];
    foreach ($baris as $b) {
        if (stripos($b, 'Location:') === 0) {
            $hasil['lokasi'] = trim(substr($b, 9));
        }
        if (stripos($b, 'Set-Cookie:') === 0 && preg_match('/' . NAMA_COOKIE . '=([^;]+)/', $b, $c)) {
            $hasil['sid'] = $c[1];
            $hasil['cookie'] = trim(substr($b, 11));
        }
    }
    return $hasil;
}

// Satu request HTTP (bisa juga beberapa sekaligus lewat http_paralel).
function http(int $port, string $metode, string $jalur, ?string $sid, ?array $post = null): array
{
    return http_paralel([[$port, $metode, $jalur, $sid, $post]])[0];
}

function http_paralel(array $daftar): array
{
    $soket = [];
    foreach ($daftar as $i => [$port, $metode, $jalur, $sid, $post]) {
        $soket[$i] = stream_socket_client("tcp://127.0.0.1:$port", $no, $teks, 10);
        fwrite($soket[$i], kepala_http($port, $metode, $jalur, $sid, $post));
    }
    $hasil = [];
    foreach ($soket as $i => $s) {
        stream_set_timeout($s, 60);
        $hasil[$i] = urai_http(stream_get_contents($s));
        fclose($s);
    }
    return $hasil;
}

function token(array $h): string
{
    return preg_match('/name="csrf" value="([0-9a-f]+)"/', $h['html'], $m) ? $m[1] : '';
}

// Keluaran proses anak: JSON hasil + (opsional) laporan "@@KUNCI@@n" dari shutdown + teks lain (pesan galat).
function urai_anak(string $keluar): array
{
    $kunci = null;
    if (preg_match('/@@KUNCI@@(\d+)/', $keluar, $m)) {
        $kunci = (int) $m[1];
        $keluar = str_replace($m[0], '', $keluar);
    }
    $hasil = json_decode(trim($keluar), true);
    $hasil = is_array($hasil) ? $hasil : [];
    return $hasil + ['mentah' => $keluar, 'kunci_tersisa' => $kunci];
}

function anak(array $arg, array $env = []): array
{
    $p = proc_open(array_merge([PHP_BINARY, __FILE__], $arg), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa, AKAR_UJI, env_uji($env));
    fclose($pipa[0]);
    $keluar = stream_get_contents($pipa[1]) . stream_get_contents($pipa[2]);
    proc_close($p);
    return urai_anak($keluar);
}

// Apakah advisory lock session untuk $sid sedang bebas (dicoba dari koneksi uji, lalu langsung dilepas lagi).
function kunci_bebas(string $sid): bool
{
    global $pdo;
    $s = $pdo->prepare('SELECT pg_try_advisory_lock(470117, hashtext(:s))');
    $s->execute(['s' => $sid]);
    $bebas = (bool) $s->fetchColumn();
    if ($bebas) {
        $pdo->prepare('SELECT pg_advisory_unlock(470117, hashtext(:s))')->execute(['s' => $sid]);
    }
    return $bebas;
}

function isi_baris(string $sid): ?array
{
    global $pdo;
    $s = $pdo->prepare('SELECT data, diperbarui_pada FROM sesi_php WHERE id = :i');
    $s->execute(['i' => $sid]);
    return $s->fetch() ?: null;
}

function anak_latar(array $arg)
{
    $p = proc_open(array_merge([PHP_BINARY, __FILE__], $arg), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa, AKAR_UJI, env_uji());
    fclose($pipa[0]);
    return [$p, $pipa];
}

function selesai_latar(array $latar): array
{
    [$p, $pipa] = $latar;
    $keluar = stream_get_contents($pipa[1]) . stream_get_contents($pipa[2]);
    proc_close($p);
    return urai_anak($keluar);
}

function baris_ada(?string $sid): bool
{
    return $sid !== null && (bool) satu('SELECT COUNT(*) FROM sesi_php WHERE id = :i', ['i' => $sid]);
}

// Login lewat form asli: GET halaman masuk (session + token baru), lalu POST auth.php.
function masuk(int $port, string $username, string $sandi = SANDI_DEMO): array
{
    $awal = http($port, 'GET', '/index.php', null);
    $kirim = http($port, 'POST', '/auth.php', $awal['sid'], ['csrf' => token($awal), 'username' => $username, 'password' => $sandi]);
    return ['awal' => $awal, 'kirim' => $kirim, 'sid' => $kirim['sid'] ?? $awal['sid']];
}

function bersih_html(array $h): bool
{
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|Parse error)\b/', $h['html']);
}

$PA = 18000 + random_int(100, 899);
$PB = $PA + 1;
$jumlah_awal = (int) satu('SELECT COUNT(*) FROM sesi_php');
nyalakan_server($PA, env_uji());
nyalakan_server($PB, env_uji());
echo "Server uji: A=127.0.0.1:$PA, B=127.0.0.1:$PB, database {$k['dbname']}\n";

// ---------------------------------------------------------------------------------------------
echo "-- S1 session baru, cookie, penyimpanan di PostgreSQL\n";
$h = http($PA, 'GET', '/index.php', null);
cek('S1a halaman masuk 200 tanpa galat PHP', $h['status'] === 200 && bersih_html($h), (string) $h['status']);
cek('S1b cookie session HttpOnly + SameSite=Lax + Path=/', $h['sid'] !== null && stripos($h['cookie'], 'httponly') !== false
    && stripos($h['cookie'], 'samesite=lax') !== false && stripos($h['cookie'], 'path=/') !== false, $h['cookie']);
cek('S1c baris sesi_php dibuat (bukan file)', baris_ada($h['sid']));
$data = (string) satu('SELECT data FROM sesi_php WHERE id = :i', ['i' => $h['sid']]);
cek('S1d data tersimpan base64 dan memuat token CSRF', base64_decode($data, true) !== false && str_contains(base64_decode($data), 'csrf|'));
$sid_anon = $h['sid'];

// ---------------------------------------------------------------------------------------------
echo "-- S2 login gagal dan CSRF\n";
$g = http($PA, 'POST', '/auth.php', $sid_anon, ['csrf' => token($h), 'username' => 'fiki.cantik', 'password' => 'salah-sekali']);
$hal = http($PB, 'GET', '/index.php', $sid_anon);
cek('S2a sandi salah: kembali ke halaman masuk dengan pesan, session sama', $g['status'] === 302 && $g['sid'] === null
    && str_contains($hal['html'], 'Nama pengguna atau kata sandi salah'), $g['status'] . ' ' . $g['lokasi']);
$c = http($PA, 'POST', '/auth.php', $sid_anon, ['csrf' => 'token-palsu', 'username' => 'fiki.cantik', 'password' => SANDI_DEMO]);
$hal = http($PB, 'GET', '/index.php', $sid_anon);
cek('S2b login dengan token CSRF salah ditolak (flash, tetap belum login)', $c['status'] === 302 && $c['sid'] === null
    && str_contains($hal['html'], 'Sesi formulir sudah kedaluwarsa'), $c['status'] . ' ' . $c['lokasi']);
$d = http($PB, 'GET', '/pages/dashboard-admin.php', $sid_anon);
cek('S2c belum login: halaman dashboard dialihkan ke halaman masuk', $d['status'] === 302 && str_ends_with($d['lokasi'], '/index.php'), $d['lokasi']);

// ---------------------------------------------------------------------------------------------
echo "-- S3 login tiga peran, regenerasi ID, lintas instance\n";
$akun = ['fiki.cantik' => ['sa', 'dashboard-admin.php'], 'budi.santoso' => ['pi', 'dashboard-petugas.php'], 'rizky.p' => ['us', 'dashboard-user.php']];
$sid_peran = [];
foreach ($akun as $username => [$peran, $beranda]) {
    $m = masuk($PA, $username);
    $id_lama = $m['awal']['sid'];
    cek("S3a [$peran] login berhasil, dialihkan ke $beranda", $m['kirim']['status'] === 302 && str_ends_with($m['kirim']['lokasi'], "/pages/$beranda"),
        $m['kirim']['status'] . ' ' . $m['kirim']['lokasi']);
    cek("S3b [$peran] ID session diganti saat login (anti session fixation)", $m['kirim']['sid'] !== null && $m['kirim']['sid'] !== $id_lama);
    cek("S3c [$peran] baris session lama dihapus, baris baru ada", !baris_ada($id_lama) && baris_ada($m['kirim']['sid']));
    $sid = $m['kirim']['sid'];
    $sid_peran[$peran] = $sid;
    $b = http($PB, 'GET', "/pages/$beranda", $sid);
    cek("S3d [$peran] session terbaca di instance lain (server B)", $b['status'] === 200 && bersih_html($b) && str_contains($b['html'], 'Keluar'),
        (string) $b['status']);
    $r = http($PA, 'GET', "/pages/$beranda", $sid);
    cek("S3e [$peran] refresh di instance A tetap login, ID tidak berubah", $r['status'] === 200 && $r['sid'] === null);
    $lama = http($PB, 'GET', "/pages/$beranda", $id_lama);
    cek("S3f [$peran] ID sebelum login tidak bisa dipakai lagi", $lama['status'] === 302 && str_ends_with($lama['lokasi'], '/index.php'));
}
$t = http($PB, 'GET', '/pages/dashboard-admin.php', $sid_peran['us']);
cek('S3g RBAC tetap: User/Kru dialihkan dari dashboard admin', $t['status'] === 302 && str_ends_with($t['lokasi'], '/pages/dashboard-user.php'), $t['lokasi']);
$f = http($PA, 'GET', '/pages/dashboard-user.php', $sid_peran['us']);
cek('S3h flash pesan hak akses tampil sekali di request berikutnya (instance lain)', str_contains($f['html'], 'tidak termasuk hak akses'));
$f2 = http($PB, 'GET', '/pages/dashboard-user.php', $sid_peran['us']);
cek('S3i flash tidak muncul lagi sesudah ditampilkan', !str_contains($f2['html'], 'tidak termasuk hak akses'));

// ---------------------------------------------------------------------------------------------
echo "-- S4 session fixation\n";
$palsu = bin2hex(random_bytes(16));
$x = http($PA, 'GET', '/index.php', $palsu);
cek('S4a ID buatan penyerang ditolak (strict mode): server memberi ID baru', $x['sid'] !== null && $x['sid'] !== $palsu && !baris_ada($palsu));

// ---------------------------------------------------------------------------------------------
echo "-- S5 dua tab bersamaan (request paralel ke dua instance, session sama)\n";
$sid = $sid_peran['pi'];
$hasil = http_paralel([
    [$PA, 'POST', '/pages/perlengkapan.php', $sid, ['csrf' => 'salah-1', 'aksi' => 'tambah']],
    [$PB, 'POST', '/pages/perlengkapan.php', $sid, ['csrf' => 'salah-2', 'aksi' => 'tambah']],
]);
cek('S5a kedua request ditolak CSRF dan dialihkan', $hasil[0]['status'] === 302 && $hasil[1]['status'] === 302);
$isi = anak(['--baca', $sid]);
$flash = array_column($isi['isi']['flash'] ?? [], 'pesan');
cek('S5b kedua flash tersimpan (tidak ada update yang hilang)', count(array_filter($flash, fn ($p) => str_contains($p, 'kedaluwarsa'))) === 2,
    json_encode($flash));
cek('S5c user_id dan token CSRF tetap utuh', ($isi['isi']['user_id'] ?? null) === 2 && !empty($isi['isi']['csrf']));
http($PA, 'GET', '/pages/dashboard-petugas.php', $sid); // tampilkan & kosongkan flash

// ---------------------------------------------------------------------------------------------
echo "-- S6 dua proses PHP independen, session ID sama (penguncian)\n";
$p1 = anak_latar(['--anak', $sid, 'p1', '2']);
usleep(400000);
$p2 = anak_latar(['--anak', $sid, 'p2', '0']);
$h1 = selesai_latar($p1);
$h2 = selesai_latar($p2);
cek('S6a kedua proses berjalan normal', isset($h1['mulai'], $h2['mulai']), json_encode([$h1, $h2]));
cek('S6b proses kedua menunggu sampai proses pertama selesai', isset($h1['selesai'], $h2['mulai']) && $h2['mulai'] >= $h1['selesai'] - 0.05,
    sprintf('p1 selesai %.3f, p2 mulai %.3f', $h1['selesai'] ?? 0, $h2['mulai'] ?? 0));
cek('S6c proses kedua melihat tulisan proses pertama', in_array('uji_p1', $h2['kunci_awal'] ?? [], true));
$isi = anak(['--baca', $sid]);
cek('S6d hasil akhir memuat tulisan kedua proses', isset($isi['isi']['uji_p1'], $isi['isi']['uji_p2']));
cek('S6e session tersedia kembali sesudah proses pertama berakhir (login tetap)', ($isi['isi']['user_id'] ?? null) === 2);
$r = http($PB, 'GET', '/pages/dashboard-petugas.php', $sid);
cek('S6f request HTTP sesudahnya tidak tertahan kunci', $r['status'] === 200);
cek('S6g kedua proses melepas kunci di koneksinya sendiri setelah selesai normal', ($h1['kunci_tersisa'] ?? null) === 0
    && ($h2['kunci_tersisa'] ?? null) === 0, json_encode([$h1['kunci_tersisa'] ?? null, $h2['kunci_tersisa'] ?? null]));

// ---------------------------------------------------------------------------------------------
echo "-- S10 fail-closed: request kedua kehabisan waktu menunggu kunci session\n";
$sid = $sid_peran['pi'];
http($PA, 'GET', '/pages/dashboard-petugas.php', $sid); // kosongkan flash sisa
$tahan = anak_latar(['--anak', $sid, 'tahan', '11']);   // memegang kunci ~11 detik (> batas tunggu 8 detik)
usleep(700000);
$sebelum = isi_baris($sid);
$t0 = microtime(true);
$z = http($PB, 'POST', '/pages/perlengkapan.php', $sid, ['csrf' => 'salah-3', 'aksi' => 'tambah']);
$lama = microtime(true) - $t0;
$sesudah = isi_baris($sid);
cek('S10a request kedua dengan session sama berhenti 503 setelah batas tunggu kunci', $z['status'] === 503 && $lama >= 7.5 && $lama < 10.5,
    sprintf('status %d dalam %.1f detik', $z['status'], $lama));
cek('S10b respons timeout: pesan umum, tanpa cookie baru, tanpa detail teknis', $z['sid'] === null && str_contains($z['html'], 'sedang tidak tersedia')
    && !preg_match('/SQLSTATE|advisory|sesi\.php|Warning|Fatal/i', $z['html']), $z['cookie'] . ' ' . substr(strip_tags($z['html']), 0, 200));
cek('S10c request yang timeout tidak menulis session tanpa kunci (data & waktu sama, baris tidak dihapus)', $sebelum !== null && $sesudah === $sebelum);
$h = selesai_latar($tahan);
cek('S10d pemegang kunci selesai normal dan melepas kunci di koneksinya', isset($h['mulai']) && ($h['kunci_tersisa'] ?? null) === 0, json_encode($h));
cek('S10e kunci session bebas sesudahnya', kunci_bebas($sid));
$isi = anak(['--baca', $sid]);
$flash = array_column($isi['isi']['flash'] ?? [], 'pesan');
cek('S10f session konsisten: tulisan pemegang kunci tersimpan, request yang timeout tidak meninggalkan jejak',
    isset($isi['isi']['uji_tahan']) && ($isi['isi']['user_id'] ?? null) === 2 && !array_filter($flash, fn ($p) => str_contains($p, 'kedaluwarsa')),
    json_encode($isi['isi'] ?? $isi));
$r = http($PA, 'GET', '/pages/dashboard-petugas.php', $sid);
cek('S10g request berikutnya normal (200, tetap login, ID sama)', $r['status'] === 200 && $r['sid'] === null && str_contains($r['html'], 'Keluar'));

// ---------------------------------------------------------------------------------------------
echo "-- S11 kunci dilepas setelah exception\n";
foreach (['logika' => ['galat program tak tertangani', 'Simulasi galat program'], 'db' => ['galat database (halaman 503)', 'sedang tidak tersedia']] as $jenis => [$label, $jejak]) {
    $g = anak(['--galat', $sid, $jenis]);
    cek("S11a [$jenis] $label benar-benar terjadi", str_contains($g['mentah'], $jejak), substr($g['mentah'], 0, 200));
    cek("S11b [$jenis] kunci dilepas di koneksi yang sama sesudah exception", ($g['kunci_tersisa'] ?? null) === 0, var_export($g['kunci_tersisa'] ?? null, true));
    cek("S11c [$jenis] kunci session bebas untuk request lain", kunci_bebas($sid));
    $r = http($PB, 'GET', '/pages/dashboard-petugas.php', $sid);
    cek("S11d [$jenis] session tetap utuh: request berikutnya 200 dan tetap login", $r['status'] === 200 && $r['sid'] === null && str_contains($r['html'], 'Keluar'));
}

// ---------------------------------------------------------------------------------------------
echo "-- S12 pg_advisory_unlock_all() saat koneksi dibuka (backend dipakai ulang)\n";
$s12 = anak(['--sisa-kunci']);
cek('S12a kondisi awal: koneksi memegang advisory lock sesi, advisory lock transaksi, dan kunci baris',
    ($s12['sebelum']['sesi'] ?? 0) === 1 && ($s12['sebelum']['xact'] ?? 0) === 1 && ($s12['sebelum']['baris'] ?? 0) >= 1, json_encode($s12));
cek('S12b advisory lock tingkat sesi sisa pemakai lama dilepas', ($s12['sesudah']['sesi'] ?? -1) === 0, json_encode($s12['sesudah'] ?? $s12));
cek('S12c advisory lock transaksi dan kunci baris (FOR UPDATE) tidak terganggu',
    ($s12['sesudah']['xact'] ?? 0) === 1 && ($s12['sesudah']['baris'] ?? 0) === ($s12['sebelum']['baris'] ?? -1));
cek('S12d role aplikasi tetap dinilai tidak istimewa oleh pemeriksaan yang sama', ($s12['istimewa'] ?? null) === false, var_export($s12['istimewa'] ?? null, true));

// ---------------------------------------------------------------------------------------------
echo "-- S7 logout\n";
$sid = $sid_peran['sa'];
$hal = http($PA, 'GET', '/pages/dashboard-admin.php', $sid);
$tanpa = http($PB, 'POST', '/logout.php', $sid, []);
$masih = http($PA, 'GET', '/pages/dashboard-admin.php', $sid);
cek('S7a logout tanpa token CSRF ditolak (tetap login)', $tanpa['sid'] === null && $masih['status'] === 200);
$keluar = http($PB, 'POST', '/logout.php', $sid, ['csrf' => token($hal)]);
cek('S7b logout dialihkan ke halaman masuk dengan ID baru', $keluar['status'] === 302 && str_ends_with($keluar['lokasi'], '/index.php')
    && $keluar['sid'] !== null && $keluar['sid'] !== $sid);
cek('S7c baris session lama dihapus', !baris_ada($sid));
$idx = http($PA, 'GET', '/index.php', $keluar['sid']);
cek('S7d pesan "Anda telah keluar." tampil di instance lain', str_contains($idx['html'], 'Anda telah keluar.'));
$lagi = http($PA, 'GET', '/pages/dashboard-admin.php', $keluar['sid']);
$lama = http($PB, 'GET', '/pages/dashboard-admin.php', $sid);
cek('S7e sesudah logout dashboard tidak bisa dibuka (ID baru maupun lama)', $lagi['status'] === 302 && $lama['status'] === 302);

// ---------------------------------------------------------------------------------------------
echo "-- S8 kedaluwarsa dan pembersihan (GC)\n";
$sid = $sid_peran['us'];
satu("UPDATE sesi_php SET diperbarui_pada = now() - INTERVAL '3 hours' WHERE id = :i RETURNING 1", ['i' => $sid]);
$e = http($PA, 'GET', '/pages/dashboard-user.php', $sid);
cek('S8a session yang lewat 2 jam tidak dipakai: dialihkan ke halaman masuk', $e['status'] === 302 && str_ends_with($e['lokasi'], '/index.php'));
cek('S8b ID kedaluwarsa diganti ID baru', $e['sid'] !== null && $e['sid'] !== $sid);
$basi = 'ujigc' . bin2hex(random_bytes(12));
satu("INSERT INTO sesi_php (id, data, diperbarui_pada) VALUES (:i, '', now() - INTERVAL '5 hours') RETURNING 1", ['i' => $basi]);
$gc = anak(['--gc', $e['sid']]);
cek('S8c gc menghapus session basi', ($gc['terhapus'] ?? 0) >= 1 && !baris_ada($basi) && !baris_ada($sid), json_encode($gc));
cek('S8d gc tidak menghapus session aktif', baris_ada($e['sid']));

// ---------------------------------------------------------------------------------------------
echo "-- S9 fail-closed dan konfigurasi produksi\n";
$PC = $PB + 1;
nyalakan_server($PC, env_uji(['PHOTOOPS_DB_NAME' => 'photoops_tidak_ada_test']));
$z = http($PC, 'GET', '/index.php', null);
cek('S9a database session tidak tersedia: 503, tanpa cookie (tidak pindah ke session file)', $z['status'] === 503 && $z['sid'] === null
    && str_contains($z['html'], 'sedang tidak tersedia'), $z['status'] . ' ' . $z['cookie']);
cek('S9b halaman 503 tidak memuat detail teknis', !preg_match('/PDO|SQLSTATE|photoops_tidak_ada|password|\.php/i', $z['html']));
$PD = $PC + 1;
nyalakan_server($PD, env_uji(['PHOTOOPS_ENV' => 'production', 'PHOTOOPS_SESSION' => null, 'PHOTOOPS_DB_NAME' => null]));
$z = http($PD, 'GET', '/index.php', null);
cek('S9c produksi tanpa variabel database: 503, tidak memakai config/database.local.php', $z['status'] === 503 && $z['sid'] === null,
    (string) $z['status']);
cek('S9d produksi: pesan umum tanpa detail', !preg_match('/PHOTOOPS_|Warning|Fatal|database\.local|\.php/i', $z['html']));
$PE = $PD + 1;
nyalakan_server($PE, env_uji(['PHOTOOPS_ENV' => 'production', 'PHOTOOPS_SESSION' => 'file', 'PHOTOOPS_DB_HOST' => '127.0.0.1',
    'PHOTOOPS_DB_NAME' => 'x_test', 'PHOTOOPS_DB_USER' => 'x', 'PHOTOOPS_DB_PASSWORD' => 'x', 'PHOTOOPS_DB_SSLMODE' => 'require']));
$z = http($PE, 'GET', '/index.php', null);
cek('S9e produksi dengan session file ditolak (503, tanpa cookie)', $z['status'] === 503 && $z['sid'] === null, (string) $z['status']);
$PF = $PE + 1;
nyalakan_server($PF, env_uji(['PHOTOOPS_ENV' => 'production', 'PHOTOOPS_DB_HOST' => '127.0.0.1', 'PHOTOOPS_DB_NAME' => 'x_test',
    'PHOTOOPS_DB_USER' => 'x', 'PHOTOOPS_DB_PASSWORD' => 'x', 'PHOTOOPS_DB_SSLMODE' => 'disable']));
$z = http($PF, 'GET', '/index.php', null);
cek('S9f produksi menolak koneksi database tanpa SSL (sslmode=disable)', $z['status'] === 503 && $z['sid'] === null, (string) $z['status']);
$PG = $PF + 1;
nyalakan_server($PG, env_uji(['PHOTOOPS_ENV' => 'production', 'PHOTOOPS_DB_HOST' => '127.0.0.1', 'PHOTOOPS_DB_PORT' => '6543',
    'PHOTOOPS_DB_NAME' => 'x_test', 'PHOTOOPS_DB_USER' => 'x', 'PHOTOOPS_DB_PASSWORD' => 'x', 'PHOTOOPS_DB_SSLMODE' => 'require']));
$z = http($PG, 'GET', '/index.php', null);
cek('S9g port Transaction Pooler 6543 ditolak', $z['status'] === 503 && $z['sid'] === null, (string) $z['status']);
// Path root CA: libpq membaca "\" di string koneksi sebagai escape (masalah nyata di Windows).
$folder_ca = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops uji ca';
@mkdir($folder_ca);
$ca_biasa = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photoops_uji_ca.crt';
$ca_spasi = $folder_ca . DIRECTORY_SEPARATOR . 'ca.crt';
file_put_contents($ca_biasa, "uji\n");
file_put_contents($ca_spasi, "uji\n");
$env_ca = ['PHOTOOPS_ENV' => 'production', 'PHOTOOPS_DB_HOST' => 'h', 'PHOTOOPS_DB_NAME' => 'n', 'PHOTOOPS_DB_USER' => 'u',
    'PHOTOOPS_DB_PASSWORD' => 'x', 'PHOTOOPS_DB_SSLMODE' => 'verify-full'];
$kf = anak(['--konfig'], $env_ca + ['PHOTOOPS_DB_SSLROOTCERT' => $ca_biasa]);
cek('S9i path root CA asli sistem operasi diterima dan bebas backslash di string koneksi', ($kf['diterima'] ?? false)
    && !str_contains((string) ($kf['sslrootcert'] ?? '\\'), '\\') && is_file((string) $kf['sslrootcert']), json_encode($kf));
$kf = anak(['--konfig'], $env_ca + ['PHOTOOPS_DB_SSLROOTCERT' => $ca_spasi]);
cek('S9j path root CA berspasi ditolak dengan galat konfigurasi (503) dan alasannya tercatat di log', ($kf['diterima'] ?? true) === false
    && ($kf['kode'] ?? 0) === 503 && str_contains($kf['log'] ?? '', 'PHOTOOPS_DB_SSLROOTCERT'), json_encode($kf));
@unlink($ca_biasa);
@unlink($ca_spasi);
@rmdir($folder_ca);

$ck = anak(['--cookie']);
cek('S9h HTTPS: cookie __Host-photoops, Secure, HttpOnly, SameSite=Lax, path /, strict mode', ($ck['nama'] ?? '') === '__Host-photoops'
    && ($ck['param']['secure'] ?? false) && ($ck['param']['httponly'] ?? false) && ($ck['param']['samesite'] ?? '') === 'Lax'
    && ($ck['param']['path'] ?? '') === '/' && ($ck['strict'] ?? '') === '1', json_encode($ck));

// ---------------------------------------------------------------------------------------------
// Bersihkan baris session yang dibuat uji ini.
$pdo->exec('DELETE FROM sesi_php');
echo "-- baris sesi_php sebelum uji: $jumlah_awal, sesudah dibersihkan: " . satu('SELECT COUNT(*) FROM sesi_php') . "\n";
echo $gagal === 0 ? "OK ($lulus lulus, 0 gagal)\n" : "GAGAL ($lulus lulus, $gagal gagal)\n";
exit($gagal === 0 ? 0 : 1);
