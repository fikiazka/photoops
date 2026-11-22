<?php
defined('ROOT') || exit;

// Log session untuk modul yang belum dimigrasikan (sejak Sprint 4B hanya Insiden).
function catat_riwayat(string $aksi, string $objek, string $keterangan, string $lama = '', string $baru = ''): void
{
    db_tambah('riwayat', [
        'waktu' => date('Y-m-d H:i', strtotime(sekarang())),
        'pengguna_id' => $_SESSION['user_id'] ?? null,
        'aksi' => $aksi,
        'objek' => $objek,
        'keterangan' => $keterangan,
        'lama' => $lama,
        'baru' => $baru,
    ]);
}

// Audit Proyek dan Tim (Sprint Backend 3A), Kebutuhan, Alokasi, Rental (3C), Barang Keluar dan Pengembalian (4A), serta
// Persiapan (4B) di PostgreSQL; dipanggil di dalam transaksi yang sama dengan perubahannya.
// Waktunya memakai sekarang() (jam aplikasi), sama dengan log Insiden di session, karena log ini
// ditampilkan berdampingan di halaman Riwayat.
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

// Audit Preset (Sprint Backend 3B), di dalam transaksi yang sama dengan perubahan preset/itemnya.
function catat_riwayat_preset(string $objek, string $keterangan, string $lama = '', string $baru = ''): void
{
    catat_riwayat_proyek('Preset', $objek, $keterangan, $lama, $baru);
}

// Aksi riwayat yang sumbernya PostgreSQL. Sisanya (Insiden) masih dari log session sampai Insiden dimigrasikan.
const AKSI_RIWAYAT_DB = ['Pengguna', 'Perlengkapan', 'Proyek', 'Tim', 'Preset', 'Kebutuhan', 'Alokasi', 'Rental', 'Barang Keluar', 'Pengembalian', 'Persiapan'];

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
 * Sejak Sprint Backend 4B log Persiapan ada di PostgreSQL (riwayat_aktivitas); fungsi di bawah hanya MEMBACA log untuk
 * memberi keterangan pada baris aksi "Persiapan" (konfirmasi selesai, progres checklist, pembatalan/buka ulang).
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

// Peta pendukung: perubahan deadline per kode proyek (PostgreSQL, urut id = urutan kejadian) dan proyek per kode.
// Proyek tidak bisa dihapus (HAPUS_PROYEK_TERSEDIA = false), jadi kode proyek tidak pernah dipakai ulang.
function konteks_log_proyek(): array
{
    $deadline = [];
    foreach (sql_semua("SELECT id, objek, lama FROM riwayat_aktivitas
                        WHERE aksi = 'Proyek' AND keterangan = 'Deadline persiapan diubah' ORDER BY id") as $r) {
        $deadline[kode_proyek_objek($r['objek'])][] = ['id' => (int) $r['id'], 'lama' => $r['lama']];
    }
    $per_kode = [];
    foreach (semua_proyek() as $p) {
        $per_kode[$p['kode']] = $p;
    }
    return ['deadline' => $deadline, 'per_kode' => $per_kode];
}

// Deadline yang berlaku saat konfirmasi persiapan ($id_log = id log konfirmasi di riwayat_aktivitas).
// Sejak Sprint Backend 4B log konfirmasi dan log perubahan deadline sama-sama di PostgreSQL. id identity selalu naik, jadi
// urutan kejadian = urutan id, tidak bergantung pada jam aplikasi (tanggal demo HARI_INI):
//  - ada perubahan deadline SETELAH konfirmasi: deadline saat konfirmasi = nilai 'lama' perubahan pertama itu;
//  - tidak ada: deadline proyek saat ini.
function deadline_saat_konfirmasi(array $konteks, array $proyek, int $id_log): array
{
    foreach ($konteks['deadline'][$proyek['kode']] ?? [] as $r) {
        if ($r['id'] > $id_log && waktu_valid((string) $r['lama'])) {
            return ['deadline' => waktu_valid((string) $r['lama']), 'berubah' => true];
        }
    }
    return ['deadline' => (string) $proyek['deadline_persiapan'], 'berubah' => false];
}

function info_persiapan_riwayat(): array
{
    // Keterangan tambahan per id entri log Persiapan (id negatif, sama dengan daftar_riwayat()):
    //  - 'konfirmasi': deadline saat itu, waktu konfirmasi, Tepat Waktu/Terlambat (+selisih), dan pembatalan sesudahnya bila ada;
    //  - 'batal'     : konfirmasi tidak berlaku lagi (otomatis) atau dibuka ulang (manual);
    //  - 'progres'   : simpan checklist "x dari y barang diperiksa", BUKAN persiapan selesai (tanpa status akhir).
    $log = sql_semua("SELECT id, TO_CHAR(waktu, 'YYYY-MM-DD HH24:MI') AS waktu, objek, keterangan, lama, baru
                      FROM riwayat_aktivitas WHERE aksi = 'Persiapan' ORDER BY id");
    $konteks = konteks_log_proyek();
    $info = [];
    $konfirmasi_terbuka = [];
    foreach ($log as $r) {
        $id_db = (int) $r['id'];
        $id = -$id_db;
        $kode = kode_proyek_objek($r['objek']);
        $proyek = $konteks['per_kode'][$kode] ?? null;
        $kunci = $proyek ? 'p' . $proyek['id'] : 'k' . $kode;
        $dasar = ['proyek' => $proyek, 'kode' => $kode];
        if ($r['baru'] === 'Dikonfirmasi Selesai') {
            $hasil = ['status' => '', 'selisih' => ''];
            $deadline = '';
            $berubah = false;
            if ($proyek) {
                $dl = deadline_saat_konfirmasi($konteks, $proyek, $id_db);
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
