<?php
defined('ROOT') || exit;

// ---------------------------------------------------------------------------------------------
// Laporan insiden (Sprint Backend 4C) di PostgreSQL: tabel laporan_insiden, kode dari seq_kode_insiden.
//   - Insiden hanya untuk unit Studio (perlengkapan_id wajib). Insiden otomatis dari pengembalian Rusak/Maintenance
//     menunjuk rincian pengembaliannya (detail_id, UNIQUE: satu insiden per rincian transaksi).
//   - Status: Dilaporkan -> Ditangani -> Selesai; "Selesai" juga boleh langsung dari Dilaporkan (alur yang sudah ada).
//   - Kondisi unit (perlengkapan.kondisi) diubah di transaksi yang sama dengan laporannya. Saat insiden diselesaikan dan
//     tidak ada insiden lain yang masih terbuka untuk unit itu, kondisi unit kembali Baik (aturan yang sudah berlaku di
//     aplikasi dan tertulis di dialog "Tandai selesai"). Bila masih ada insiden terbuka, kondisi unit tidak diubah.
//   - Urutan kunci: unit (perlengkapan) -> laporan_insiden. Pengembalian (4A) mengunci proyek -> alokasi -> unit lebih dulu
//     dan hanya menambah baris insiden baru, jadi tidak ada siklus kunci.
// ---------------------------------------------------------------------------------------------

const KOLOM_INSIDEN = "i.id, i.kode, i.perlengkapan_id, i.proyek_id, i.detail_id, d.transaksi_id, d.alokasi_id, i.jenis,
    TO_CHAR(i.waktu, 'YYYY-MM-DD HH24:MI') AS waktu, i.deskripsi, i.status, i.pelapor_id";
const DARI_INSIDEN = ' FROM laporan_insiden i LEFT JOIN detail_transaksi d ON d.id = i.detail_id';

function insiden_dari_baris(array $baris): array
{
    foreach (['id', 'perlengkapan_id', 'pelapor_id'] as $kolom) {
        $baris[$kolom] = (int) $baris[$kolom];
    }
    foreach (['proyek_id', 'detail_id', 'transaksi_id', 'alokasi_id'] as $kolom) {
        $baris[$kolom] = $baris[$kolom] === null ? null : (int) $baris[$kolom];
    }
    return $baris;
}

// Semua laporan insiden, ber-key id. Dibaca sekali per request; $muat_ulang = true sesudah ada perubahan.
function semua_insiden(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT ' . KOLOM_INSIDEN . DARI_INSIDEN . ' ORDER BY i.id') as $baris) {
            $baris = insiden_dari_baris($baris);
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

function daftar_insiden(): array
{
    $baris = array_values(semua_insiden());
    usort($baris, fn ($a, $b) => strcmp($b['waktu'], $a['waktu']) ?: $b['id'] <=> $a['id']);
    return $baris;
}

function insiden_terbuka(): array
{
    return array_values(array_filter(daftar_insiden(), fn ($i) => $i['status'] !== 'Selesai'));
}

function muat_ulang_insiden(): void
{
    semua_insiden(true);
    semua_perlengkapan(true);
}

// Kode unik dari sequence (aman untuk request bersamaan; bukan MAX + 1).
function kode_insiden_baru(): string
{
    $nomor = (int) sql_satu("SELECT nextval('seq_kode_insiden') AS nomor")['nomor'];
    return 'INS-' . str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);
}

// Catat insiden, ubah kondisi unit, dan catat riwayat. HARUS dipanggil di dalam transaksi (catat_insiden_manual() atau
// proses_pengembalian()), sehingga ketiganya tersimpan atau batal bersama. Hasil: ['id' => ..., 'kode' => ...] atau null
// bila unit tidak ada.
function lapor_insiden(int $unit_id, ?int $proyek_id, string $jenis, string $deskripsi, string $kondisi_baru, ?int $detail_id = null): ?array
{
    $unit = sql_satu('SELECT id, kode_unit, nama, kondisi FROM perlengkapan WHERE id = :id FOR UPDATE', ['id' => $unit_id]);
    if (!$unit) {
        return null;
    }
    $kode = kode_insiden_baru();
    $id = (int) sql_satu("INSERT INTO laporan_insiden (kode, perlengkapan_id, proyek_id, detail_id, jenis, waktu, deskripsi, status, pelapor_id)
                          VALUES (:kode, :unit, :proyek, :detail, :jenis, :waktu, :deskripsi, 'Dilaporkan', :pelapor) RETURNING id", [
        'kode' => $kode, 'unit' => $unit_id, 'proyek' => $proyek_id, 'detail' => $detail_id, 'jenis' => $jenis,
        'waktu' => sekarang(), 'deskripsi' => $deskripsi, 'pelapor' => (int) ($_SESSION['user_id'] ?? 0),
    ])['id'];
    if ($unit['kondisi'] !== $kondisi_baru) {
        ubah_kondisi_unit($unit_id, $kondisi_baru);
    }
    catat_riwayat_proyek('Insiden', label_unit($unit), $kode . ' · ' . $deskripsi, $unit['kondisi'], $kondisi_baru);
    return ['id' => $id, 'kode' => $kode];
}

// Laporan insiden manual dari halaman Inventaris: laporan, kondisi unit, dan riwayat dalam SATU transaksi.
function catat_insiden_manual(int $unit_id, ?int $proyek_id, string $jenis, string $deskripsi, string $kondisi): ?array
{
    $hasil = transaksi_inventaris(fn () => lapor_insiden($unit_id, $proyek_id, $jenis, $deskripsi, $kondisi));
    if ($hasil) {
        muat_ulang_insiden();
    }
    return $hasil;
}

// Ubah status insiden ($aksi = tangani | selesaikan) beserta kondisi unit dan riwayatnya dalam SATU transaksi.
// Baris unit lalu baris insiden dikunci, sehingga dua perubahan bersamaan berjalan bergantian dan yang kedua melihat
// status terbaru (misalnya "tangani" kedua ditolak karena insiden sudah Ditangani).
// "Selesai" menutup laporan, bukan bukti barang sudah diperbaiki: petugas memilih $kondisi_akhir (Baik/Maintenance/Rusak).
// Kondisi itu dipakai bila ini insiden terbuka terakhir unit tersebut; bila masih ada insiden lain, kondisi unit tidak diubah.
// Hasil: ['status' => ok | tidak_ada | transisi_tidak_sah | kondisi_tidak_sah, 'insiden' => ..., 'unit' => ..., 'masih_terbuka' => n, 'kondisi_baru' => ...].
function ubah_status_insiden(int $id, string $aksi, string $kondisi_akhir = ''): array
{
    if ($aksi === 'selesaikan' && !in_array($kondisi_akhir, daftar_kondisi(), true)) {
        return ['status' => 'kondisi_tidak_sah'];
    }
    $hasil = transaksi_inventaris(function () use ($id, $aksi, $kondisi_akhir) {
        $awal = sql_satu('SELECT perlengkapan_id FROM laporan_insiden WHERE id = :id', ['id' => $id]);
        if (!$awal) {
            return ['status' => 'tidak_ada'];
        }
        $unit = sql_satu('SELECT id, kode_unit, nama, kondisi FROM perlengkapan WHERE id = :id FOR UPDATE', ['id' => $awal['perlengkapan_id']]);
        $ins = sql_satu('SELECT id, kode, status FROM laporan_insiden WHERE id = :id FOR UPDATE', ['id' => $id]);
        $boleh = $aksi === 'tangani' ? $ins['status'] === 'Dilaporkan' : ($aksi === 'selesaikan' && $ins['status'] !== 'Selesai');
        if (!$boleh) {
            return ['status' => 'transisi_tidak_sah', 'insiden' => $ins];
        }
        if ($aksi === 'tangani') {
            sql_jalankan("UPDATE laporan_insiden SET status = 'Ditangani' WHERE id = :id", ['id' => $id]);
            catat_riwayat_proyek('Insiden', label_unit($unit), $ins['kode'] . ' mulai ditangani', $ins['status'], 'Ditangani');
            return ['status' => 'ok', 'insiden' => $ins, 'unit' => $unit];
        }
        $masih = (int) sql_satu("SELECT COUNT(*) AS n FROM laporan_insiden WHERE perlengkapan_id = :u AND id <> :id AND status <> 'Selesai'",
            ['u' => $unit['id'], 'id' => $id])['n'];
        sql_jalankan("UPDATE laporan_insiden SET status = 'Selesai' WHERE id = :id", ['id' => $id]);
        $kondisi_baru = $masih ? $unit['kondisi'] : $kondisi_akhir;
        if ($kondisi_baru !== $unit['kondisi']) {
            ubah_kondisi_unit((int) $unit['id'], $kondisi_baru);
        }
        if ($masih) {
            $ket = ', unit masih punya ' . $masih . ' insiden terbuka';
        } else {
            $ket = $kondisi_baru === 'Baik' ? ', unit kembali layak pakai' : ', kondisi akhir unit ' . $kondisi_baru . ' (belum layak pakai)';
        }
        catat_riwayat_proyek('Insiden', label_unit($unit), $ins['kode'] . ' selesai' . $ket, $unit['kondisi'], $kondisi_baru);
        return ['status' => 'ok', 'insiden' => $ins, 'unit' => $unit, 'masih_terbuka' => $masih, 'kondisi_baru' => $kondisi_baru];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_insiden();
    }
    return $hasil;
}
