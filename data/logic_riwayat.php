<?php
defined('ROOT') || exit;

// Log session untuk modul yang belum dimigrasikan. $ekstra = kolom tambahan, mis. snapshot 'deadline' pada konfirmasi persiapan.
function catat_riwayat(string $aksi, string $objek, string $keterangan, string $lama = '', string $baru = '', array $ekstra = []): void
{
    db_tambah('riwayat', [
        'waktu' => date('Y-m-d H:i', strtotime(sekarang())),
        'pengguna_id' => $_SESSION['user_id'] ?? null,
        'aksi' => $aksi,
        'objek' => $objek,
        'keterangan' => $keterangan,
        'lama' => $lama,
        'baru' => $baru,
    ] + $ekstra);
}

// Audit Proyek dan Tim (Sprint Backend 3A) di PostgreSQL, dipanggil di dalam transaksi yang sama dengan perubahannya.
// Waktunya memakai sekarang() (jam aplikasi), sama dengan log Persiapan/Serah Terima di session, karena log ini
// ditampilkan berdampingan dan dibaca bersama di halaman Riwayat.
function catat_riwayat_proyek(string $aksi, string $objek, string $keterangan, string $lama = '', string $baru = ''): void
{
    sql_jalankan('INSERT INTO riwayat_aktivitas (waktu, pengguna_id, aksi, objek, keterangan, lama, baru)
                  VALUES (:waktu, :pengguna_id, :aksi, :objek, :keterangan, :lama, :baru)', [
        'waktu' => sekarang(),
        'pengguna_id' => $_SESSION['user_id'] ?? null,
        'aksi' => $aksi,
        'objek' => $objek,
        'keterangan' => $keterangan,
        'lama' => $lama,
        'baru' => $baru,
    ]);
}

// Audit Manajemen Pengguna disimpan di PostgreSQL (aksi 'Pengguna'), di dalam transaksi yang sama
// dengan perubahan akunnya. Log modul operasional lain masih memakai catat_riwayat() (session).
function catat_riwayat_pengguna(string $objek, string $keterangan, string $lama = '', string $baru = ''): void
{
    sql_jalankan("INSERT INTO riwayat_aktivitas (waktu, pengguna_id, aksi, objek, keterangan, lama, baru)
                  VALUES (:waktu, :pengguna_id, 'Pengguna', :objek, :keterangan, :lama, :baru)", [
        'waktu' => date('Y-m-d H:i:s'),
        'pengguna_id' => $_SESSION['user_id'] ?? null,
        'objek' => $objek,
        'keterangan' => $keterangan,
        'lama' => $lama,
        'baru' => $baru,
    ]);
}

// Audit Inventaris Studio (aksi 'Perlengkapan') juga di PostgreSQL, dipanggil di dalam transaksi
// yang sama dengan INSERT/UPDATE unitnya. Bila pencatatan gagal, perubahan unit ikut dibatalkan (ROLLBACK).
function catat_riwayat_perlengkapan(string $objek, string $keterangan, string $lama = '', string $baru = ''): void
{
    sql_jalankan("INSERT INTO riwayat_aktivitas (waktu, pengguna_id, aksi, objek, keterangan, lama, baru)
                  VALUES (:waktu, :pengguna_id, 'Perlengkapan', :objek, :keterangan, :lama, :baru)", [
        'waktu' => date('Y-m-d H:i:s'),
        'pengguna_id' => $_SESSION['user_id'] ?? null,
        'objek' => $objek,
        'keterangan' => $keterangan,
        'lama' => $lama,
        'baru' => $baru,
    ]);
}

// Aksi riwayat yang sumbernya PostgreSQL. Aksi lain masih dari log session.
const AKSI_RIWAYAT_DB = ['Pengguna', 'Perlengkapan', 'Proyek', 'Tim'];

// Halaman Riwayat: log session (selain AKSI_RIWAYAT_DB) digabung dengan audit dari PostgreSQL.
// Tabel riwayat_aktivitas juga berisi data contoh aksi lain (Alokasi, Insiden, ...) yang sama dengan log session;
// karena itu yang diambil dari database hanya AKSI_RIWAYAT_DB agar tidak tampil ganda.
// ID baris PostgreSQL dibuat negatif agar tidak bertabrakan dengan ID log session.
function daftar_riwayat(): array
{
    $baris = array_values(array_filter(db('riwayat'), fn ($r) => !in_array($r['aksi'], AKSI_RIWAYAT_DB, true)));
    $audit = sql_semua("SELECT id, TO_CHAR(waktu, 'YYYY-MM-DD HH24:MI') AS waktu, pengguna_id, aksi, objek, keterangan, lama, baru
                        FROM riwayat_aktivitas
                        WHERE aksi IN (" . implode(', ', array_fill(0, count(AKSI_RIWAYAT_DB), '?')) . ')', AKSI_RIWAYAT_DB);
    foreach ($audit as $r) {
        $r['id'] = -(int) $r['id'];
        $baris[] = $r;
    }
    usort($baris, fn ($a, $b) => strcmp($b['waktu'], $a['waktu']) ?: $b['id'] <=> $a['id']);
    return $baris;
}

function ikon_aksi(string $aksi): string
{
    $peta = [
        'Barang Keluar' => 'arrow-up-right',
        'Pengembalian' => 'arrow-down-left',
        'Insiden' => 'triangle-alert',
        'Alokasi' => 'package',
        'Rental' => 'truck',
        'Persiapan' => 'list-checks',
        'Proyek' => 'calendar-days',
        'Kebutuhan' => 'list-checks',
        'Tim' => 'users',
        'Preset' => 'layers',
        'Perlengkapan' => 'camera',
        'Pengguna' => 'user',
    ];
    return $peta[$aksi] ?? 'info';
}

/*
 * Sprint 3E: ketepatan waktu persiapan di Riwayat Aktivitas.
 * Log audit ($_SESSION['db']['riwayat']) tetap disimpan dan ditampilkan apa adanya; fungsi di bawah hanya MEMBACA log
 * untuk memberi keterangan pada baris aksi "Persiapan" (konfirmasi selesai, progres checklist, pembatalan/buka ulang).
 * Hanya fakta yang tercatat di log yang dipakai: tidak ada baris turunan, tidak ada timestamp selesai yang dikarang.
 */

function teks_durasi(int $menit): string
{
    $menit = max(0, $menit);
    $bagian = [];
    if (intdiv($menit, 1440) > 0) {
        $bagian[] = intdiv($menit, 1440) . ' hari';
    }
    if (intdiv($menit % 1440, 60) > 0) {
        $bagian[] = intdiv($menit % 1440, 60) . ' jam';
    }
    if ($menit % 60 > 0 || !$bagian) {
        $bagian[] = ($menit % 60) . ' menit';
    }
    return implode(' ', $bagian);
}

function ketepatan_persiapan(string $dikonfirmasi_pada, string $deadline): array
{
    // Konfirmasi <= deadline: Tepat Waktu; konfirmasi > deadline: Terlambat. Dibandingkan per menit (format simpan Y-m-d H:i).
    $k = strtotime($dikonfirmasi_pada);
    $d = strtotime($deadline);
    if ($dikonfirmasi_pada === '' || $deadline === '' || $k === false || $d === false) {
        return ['status' => '', 'selisih_menit' => null, 'selisih' => ''];
    }
    $k -= $k % 60;
    $d -= $d % 60;
    if ($k <= $d) {
        return ['status' => 'Tepat Waktu', 'selisih_menit' => 0, 'selisih' => ''];
    }
    $menit = intdiv($k - $d, 60);
    return ['status' => 'Terlambat', 'selisih_menit' => $menit, 'selisih' => teks_durasi($menit)];
}

function kode_proyek_objek(string $objek): string
{
    return preg_match('/^(PRJ-\d{3,})(?: · |$)/u', $objek, $m) ? $m[1] : '';
}

function konteks_log_proyek(array $log): array
{
    // Peta pendukung dari log: penghapusan proyek (kode bisa dipakai ulang proyek baru) dan perubahan deadline (lama → baru).
    $hapus = [];
    $deadline = [];
    foreach ($log as $r) {
        if ($r['aksi'] !== 'Proyek') {
            continue;
        }
        $kode = kode_proyek_objek($r['objek']);
        if ($kode === '') {
            continue;
        }
        if ($r['keterangan'] === 'Proyek dihapus') {
            $hapus[$kode] = max($hapus[$kode] ?? 0, (int) $r['id']);
        } elseif ($r['keterangan'] === 'Deadline persiapan diubah') {
            $deadline[$kode][] = $r;
        }
    }
    // Sejak Sprint Backend 3A perubahan deadline dicatat di PostgreSQL (urut id = urutan kejadian).
    $deadline_db = [];
    foreach (sql_semua("SELECT objek, lama FROM riwayat_aktivitas
                        WHERE aksi = 'Proyek' AND keterangan = 'Deadline persiapan diubah' ORDER BY id") as $r) {
        $deadline_db[kode_proyek_objek($r['objek'])][] = $r;
    }
    $per_kode = [];
    foreach (semua_proyek() as $p) {
        $per_kode[$p['kode']] = $p;
    }
    return ['hapus' => $hapus, 'deadline' => $deadline, 'deadline_db' => $deadline_db, 'per_kode' => $per_kode];
}

function proyek_entri_log(array $konteks, string $kode, int $id_entri): ?array
{
    // Entri log merujuk proyek dengan kode itu, kecuali proyek tersebut dihapus SETELAH entri dibuat (kodenya lalu dipakai ulang).
    if ($kode === '' || ($konteks['hapus'][$kode] ?? 0) > $id_entri) {
        return null;
    }
    return $konteks['per_kode'][$kode] ?? null;
}

// Deadline yang berlaku saat konfirmasi persiapan ($entri = baris log konfirmasi di session).
//  1. Konfirmasi baru menyimpan snapshot 'deadline' saat dicatat. Snapshot dipakai langsung, sehingga log session dan
//     log PostgreSQL tidak perlu dibandingkan menurut waktu (jam aplikasi memakai tanggal demo HARI_INI).
//  2. Konfirmasi lama (tanpa snapshot): nilai 'lama' dari perubahan deadline pertama SETELAH konfirmasi. Log session
//     dibandingkan per id; log PostgreSQL seluruhnya dibuat setelah migrasi 3A, jadi pasti sesudah konfirmasi lama.
//  3. Tidak ada perubahan sesudahnya: deadline proyek saat ini.
function deadline_saat_konfirmasi(array $konteks, array $proyek, array $entri): array
{
    $sekarang = (string) $proyek['deadline_persiapan'];
    $snapshot = waktu_valid((string) ($entri['deadline'] ?? ''));
    if ($snapshot) {
        return ['deadline' => $snapshot, 'berubah' => $snapshot !== waktu_valid($sekarang)];
    }
    foreach ($konteks['deadline'][$proyek['kode']] ?? [] as $r) {
        if ((int) $r['id'] > (int) $entri['id'] && waktu_valid((string) $r['lama'])) {
            return ['deadline' => waktu_valid((string) $r['lama']), 'berubah' => true];
        }
    }
    foreach ($konteks['deadline_db'][$proyek['kode']] ?? [] as $r) {
        if (waktu_valid((string) $r['lama'])) {
            return ['deadline' => waktu_valid((string) $r['lama']), 'berubah' => true];
        }
    }
    return ['deadline' => $sekarang, 'berubah' => false];
}

function info_persiapan_riwayat(): array
{
    // Keterangan tambahan per id entri log Persiapan:
    //  - 'konfirmasi': deadline saat itu, waktu konfirmasi, Tepat Waktu/Terlambat (+selisih), dan pembatalan sesudahnya bila ada;
    //  - 'batal'     : konfirmasi tidak berlaku lagi (otomatis) atau dibuka ulang (manual);
    //  - 'progres'   : simpan checklist "x dari y barang diperiksa", BUKAN persiapan selesai (tanpa status akhir).
    $log = array_values(db('riwayat'));
    usort($log, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
    $konteks = konteks_log_proyek($log);
    $info = [];
    $konfirmasi_terbuka = [];
    foreach ($log as $r) {
        if ($r['aksi'] !== 'Persiapan') {
            continue;
        }
        $id = (int) $r['id'];
        $kode = kode_proyek_objek($r['objek']);
        $proyek = proyek_entri_log($konteks, $kode, $id);
        $kunci = $proyek ? 'p' . $proyek['id'] : 'k' . $kode;
        $dasar = ['proyek' => $proyek, 'kode' => $kode];
        if ($r['baru'] === 'Dikonfirmasi Selesai') {
            $hasil = ['status' => '', 'selisih' => ''];
            $deadline = '';
            $berubah = false;
            if ($proyek) {
                $dl = deadline_saat_konfirmasi($konteks, $proyek, $r);
                $deadline = $dl['deadline'];
                $berubah = $dl['berubah'];
                $hasil = ketepatan_persiapan($r['waktu'], $deadline);
            }
            $info[$id] = $dasar + ['jenis' => 'konfirmasi', 'deadline' => $deadline, 'deadline_berubah' => $berubah,
                'status' => $hasil['status'], 'selisih' => $hasil['selisih'], 'dibatalkan' => null];
            $konfirmasi_terbuka[$kunci] = $id;
        } elseif ($r['lama'] === 'Dikonfirmasi Selesai' && in_array($r['baru'], ['Perlu konfirmasi ulang', 'Dibuka ulang'], true)) {
            $otomatis = $r['baru'] === 'Perlu konfirmasi ulang';
            $info[$id] = $dasar + ['jenis' => 'batal', 'otomatis' => $otomatis,
                'alasan' => preg_replace('/^(Konfirmasi persiapan selesai tidak berlaku lagi|Persiapan dibuka ulang): /u', '', $r['keterangan'])];
            if (isset($konfirmasi_terbuka[$kunci])) {
                $info[$konfirmasi_terbuka[$kunci]]['dibatalkan'] = ['waktu' => $r['waktu'], 'otomatis' => $otomatis];
                unset($konfirmasi_terbuka[$kunci]);
            }
        } elseif (preg_match('/^(\d+) dari (\d+) barang diperiksa$/u', $r['keterangan'], $m)) {
            $info[$id] = $dasar + ['jenis' => 'progres', 'diperiksa' => (int) $m[1], 'total' => (int) $m[2]];
        }
    }
    return $info;
}
