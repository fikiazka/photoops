<?php
// PhotoOps x Supabase - langkah 7: uji koneksi PDO aplikasi lewat Session Pooler, sebagai role aplikasi (bukan admin).
// Membuktikan: login role kustom lewat Supavisor, SSL, search_path/zona waktu role, advisory lock tidak tertinggal antar-koneksi, prepared statement native
// (ATTR_EMULATE_PREPARES=false, sama dengan includes/koneksi.php), view security_invoker, FOR UPDATE, LOCK TABLE, dan
// apakah prepared statement dari koneksi sebelumnya tertinggal di koneksi berikutnya (pooler tidak mereset sesi).
// Tidak mengubah data: semua transaksi diakhiri ROLLBACK.
//
// Konfigurasi dari environment (password TIDAK lewat argumen/berkas):
//   PHOTOOPS_DB_HOST, PHOTOOPS_DB_PORT (5432), PHOTOOPS_DB_NAME (postgres), PHOTOOPS_DB_USER (photoops_app.<project-ref>),
//   PHOTOOPS_DB_PASSWORD, PHOTOOPS_DB_SSLMODE (require; disarankan verify-full), PHOTOOPS_DB_SSLROOTCERT (docker/supabase-ca.crt)
//   php database/supabase/uji_pdo_pooler.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$k = [
    'host' => getenv('PHOTOOPS_DB_HOST') ?: '',
    'port' => getenv('PHOTOOPS_DB_PORT') ?: '5432',
    'dbname' => getenv('PHOTOOPS_DB_NAME') ?: 'postgres',
    'user' => getenv('PHOTOOPS_DB_USER') ?: '',
    'password' => getenv('PHOTOOPS_DB_PASSWORD') ?: '',
    'sslmode' => getenv('PHOTOOPS_DB_SSLMODE') ?: 'require',
    'sslrootcert' => getenv('PHOTOOPS_DB_SSLROOTCERT') ?: '',
];
if ($k['host'] === '' || $k['user'] === '' || $k['password'] === '') {
    fwrite(STDERR, "[BERHENTI] Set PHOTOOPS_DB_HOST, PHOTOOPS_DB_USER, dan PHOTOOPS_DB_PASSWORD dulu.\n");
    exit(2);
}
if ($k['port'] === '6543') {
    fwrite(STDERR, "[BERHENTI] Port 6543 adalah Transaction Pooler (tanpa prepared statement). Pakai Session Pooler port 5432.\n");
    exit(2);
}

$gagal = 0;
$cek = function (string $nama, bool $ok, string $info = '') use (&$gagal): void {
    echo ($ok ? '[LULUS] ' : '[GAGAL] ') . $nama . ($info !== '' ? " - $info" : '') . PHP_EOL;
    $gagal += $ok ? 0 : 1;
};
$opsi = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 10,
];
$dsn = "pgsql:host={$k['host']};port={$k['port']};dbname={$k['dbname']};sslmode={$k['sslmode']}";
if ($k['sslrootcert'] !== '') {
    if (!is_file($k['sslrootcert'])) {
        fwrite(STDERR, "[BERHENTI] Berkas root CA {$k['sslrootcert']} tidak ditemukan.\n");
        exit(2);
    }
    // libpq membaca "\" sebagai karakter escape di string koneksi (docker\ca.crt menjadi dockerca.crt). Windows juga
    // menerima "/", jadi path dinormalkan; spasi/tanda kutip ditolak karena memotong nilai di string koneksi.
    $ca = PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $k['sslrootcert']) : $k['sslrootcert'];
    if (preg_match('/[\s\'"\\\\;]/', $ca)) {
        fwrite(STDERR, "[BERHENTI] Path root CA tidak boleh memuat spasi, tanda kutip, titik koma, atau backslash: $ca\n");
        exit(2);
    }
    $dsn .= ";sslrootcert=$ca";
}
echo "Target {$k['host']}:{$k['port']}/{$k['dbname']} sebagai {$k['user']} (sslmode={$k['sslmode']})" . PHP_EOL;

// Tiga koneksi berturut-turut, meniru tiga request PHP yang masing-masing membuka koneksi baru.
for ($putaran = 1; $putaran <= 3; $putaran++) {
    echo "-- koneksi $putaran" . PHP_EOL;
    $mulai = microtime(true);
    try {
        $pdo = new PDO($dsn, $k['user'], $k['password'], $opsi);
    } catch (PDOException $e) {
        // Pesan PDO tidak memuat password.
        $cek('koneksi', false, $e->getMessage());
        exit(1);
    }
    $cek('koneksi', true, round((microtime(true) - $mulai) * 1000) . ' ms');

    // Prepared statement yang tertinggal dari klien sebelumnya berarti pooler tidak mereset sesi; nama statement PDO
    // (pdo_stmt_00000001, ...) bisa bentrok ("already exists", SQLSTATE 42P05).
    // Query ini sendiri juga disiapkan sebagai prepared statement, jadi dikecualikan dari hitungan.
    $sisa = (int) $pdo->query("SELECT COUNT(*) FROM pg_prepared_statements WHERE statement NOT LIKE '%pg_prepared_statements%'")->fetchColumn();
    $cek('tidak ada prepared statement sisa koneksi sebelumnya', $sisa === 0, "$sisa statement");

    // Advisory lock (dipakai penguncian session PhotoOps): koneksi 1 sengaja menutup koneksi tanpa melepas kunci uji
    // (meniru request yang mati). Kunci itu tidak boleh terbawa ke koneksi berikutnya, di backend mana pun.
    $kunci_sendiri = (int) $pdo->query("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()")->fetchColumn();
    $cek('tidak ada advisory lock sisa di koneksi backend ini', $kunci_sendiri === 0, "$kunci_sendiri kunci");
    $kunci_uji = (int) $pdo->query("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 470117 AND objid = 999 AND objsubid = 2")->fetchColumn();
    $cek('kunci uji dari koneksi sebelumnya tidak tertinggal di backend mana pun', $kunci_uji === 0, $kunci_uji === 0 ? '0 kunci'
        : "$kunci_uji kunci: pooler tidak mereset sesi; kunci yatim baru lepas saat backend itu dipakai ulang (pg_advisory_unlock_all di aplikasi)");

    $info = $pdo->query("SELECT current_user AS u, current_setting('is_superuser') AS su, current_setting('search_path') AS sp,
        current_setting('TimeZone') AS tz, current_setting('statement_timeout') AS st")->fetch();
    if ($putaran === 1) {
        $cek('bukan superuser', $info['su'] === 'off', $info['u']);
        $cek('search_path = photoops', $info['sp'] === 'photoops', $info['sp']);
        $cek('zona waktu Asia/Jakarta', $info['tz'] === 'Asia/Jakarta', $info['tz']);
        $cek('statement_timeout 15s', $info['st'] === '15s', $info['st']);
    }

    // Prepared statement native dengan parameter, dieksekusi ulang (re-use) dan beberapa statement sekaligus.
    $q = $pdo->prepare('SELECT COUNT(*) FROM pengguna WHERE peran = ?');
    $jumlah = [];
    foreach (['sa', 'pi', 'us'] as $peran) {
        $q->execute([$peran]);
        $jumlah[] = (int) $q->fetchColumn();
    }
    $cek('prepared statement berparameter (3x eksekusi)', array_sum($jumlah) > 0, implode('/', $jumlah) . ' akun per peran');
    $v = $pdo->prepare('SELECT COUNT(*) FROM v_saldo_alokasi WHERE alokasi_id > ?');
    $v->execute([0]);
    $cek('view v_saldo_alokasi (security_invoker)', true, $v->fetchColumn() . ' baris');

    // Pola transaksi aplikasi: FOR UPDATE dan LOCK TABLE, lalu ROLLBACK (tidak ada data berubah).
    $pdo->beginTransaction();
    $kunci = $pdo->prepare('SELECT id FROM perlengkapan WHERE id = ? FOR UPDATE');
    $kunci->execute([1]);
    $pdo->exec('LOCK TABLE preset_perlengkapan IN SHARE ROW EXCLUSIVE MODE');
    $pdo->rollBack();
    $cek('transaksi FOR UPDATE + LOCK TABLE (di-ROLLBACK)', true);

    if ($putaran === 1) {
        $pdo->query('SELECT pg_advisory_lock(470117, 999)')->fetchColumn(); // sengaja TIDAK dilepas
    }
    $q = $v = $kunci = null;
    $pdo = null; // tutup koneksi, seperti akhir request
}

echo $gagal === 0 ? 'OK: koneksi PDO lewat Session Pooler kompatibel.' . PHP_EOL : "GAGAL: $gagal pemeriksaan." . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
