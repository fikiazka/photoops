<?php
defined('ROOT') || exit;

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

function daftar_riwayat(): array
{
    $baris = array_values(db('riwayat'));
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
    $per_kode = [];
    foreach (db('proyek') as $p) {
        $per_kode[$p['kode']] = $p;
    }
    return ['hapus' => $hapus, 'deadline' => $deadline, 'per_kode' => $per_kode];
}

function proyek_entri_log(array $konteks, string $kode, int $id_entri): ?array
{
    // Entri log merujuk proyek dengan kode itu, kecuali proyek tersebut dihapus SETELAH entri dibuat (kodenya lalu dipakai ulang).
    if ($kode === '' || ($konteks['hapus'][$kode] ?? 0) > $id_entri) {
        return null;
    }
    return $konteks['per_kode'][$kode] ?? null;
}

function deadline_saat_konfirmasi(array $konteks, array $proyek, int $id_entri): array
{
    // Deadline yang berlaku saat konfirmasi: nilai 'lama' dari perubahan deadline pertama yang tercatat SETELAH konfirmasi;
    // bila tidak ada perubahan sesudahnya, deadline proyek saat ini.
    foreach ($konteks['deadline'][$proyek['kode']] ?? [] as $r) {
        if ((int) $r['id'] > $id_entri && waktu_valid((string) $r['lama'])) {
            return ['deadline' => waktu_valid((string) $r['lama']), 'berubah' => true];
        }
    }
    return ['deadline' => (string) $proyek['deadline_persiapan'], 'berubah' => false];
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
                $dl = deadline_saat_konfirmasi($konteks, $proyek, $id);
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
