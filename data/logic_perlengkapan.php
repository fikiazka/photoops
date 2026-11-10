<?php
defined('ROOT') || exit;

function opsi_kategori(): array
{
    $hasil = [];
    foreach (db('kategori') as $kategori) {
        $hasil[$kategori['id']] = $kategori['nama'];
    }
    return $hasil;
}

function nama_kategori($id): string
{
    return db_cari('kategori', (int) $id)['nama'] ?? '—';
}

function label_unit(?array $unit): string
{
    return $unit ? $unit['kode_unit'] . ' · ' . $unit['nama'] : 'Unit tidak ditemukan';
}

function daftar_kondisi(): array
{
    return ['Baik', 'Maintenance', 'Rusak'];
}

function indeks_serah(): array
{
    static $cache = null;
    static $tanda = '';
    $tanda_baru = count(db('transaksi')) . ':' . count(db('detail_transaksi'));
    if ($cache !== null && $tanda === $tanda_baru) {
        return $cache;
    }
    $cache = [];
    $transaksi = db('transaksi');
    foreach (db('detail_transaksi') as $detail) {
        $trx = $transaksi[$detail['transaksi_id']] ?? null;
        if (!$trx) {
            continue;
        }
        $kunci = $trx['jenis'] === 'Keluar' ? 'keluar' : 'kembali';
        $cache[$detail['alokasi_id']][$kunci] = $trx['waktu'];
        $cache[$detail['alokasi_id']]['trx_' . $kunci] = $trx['id'];
        if ($kunci === 'kembali') {
            $cache[$detail['alokasi_id']]['kondisi_kembali'] = $detail['kondisi'];
        }
    }
    $tanda = $tanda_baru;
    return $cache;
}

function serah_alokasi(int $alokasi_id): array
{
    return indeks_serah()[$alokasi_id] ?? [];
}

function sedang_keluar(array $alokasi): bool
{
    $serah = serah_alokasi($alokasi['id']);
    return isset($serah['keluar']) && !isset($serah['kembali']);
}

function sudah_kembali(array $alokasi): bool
{
    return isset(serah_alokasi($alokasi['id'])['kembali']);
}

function alokasi_keluar_unit(int $unit_id): ?array
{
    foreach (db('alokasi') as $alokasi) {
        if ($alokasi['sumber'] === 'Studio' && (int) $alokasi['perlengkapan_id'] === $unit_id && sedang_keluar($alokasi)) {
            return $alokasi;
        }
    }
    return null;
}

function status_pemakaian(array $unit): string
{
    if (alokasi_keluar_unit($unit['id'])) {
        return 'Dipinjam';
    }
    if ($unit['status_aktif'] !== 'Aktif' || $unit['kondisi'] !== 'Baik') {
        return 'Tidak Tersedia';
    }
    return 'Tersedia';
}

function alokasi_aktif_unit(int $unit_id): array
{
    $hasil = [];
    foreach (db('alokasi') as $alokasi) {
        if ($alokasi['sumber'] === 'Studio' && (int) $alokasi['perlengkapan_id'] === $unit_id && $alokasi['status'] !== 'Dibatalkan' && !sudah_kembali($alokasi)) {
            $hasil[] = $alokasi;
        }
    }
    usort($hasil, fn ($a, $b) => strcmp($a['tgl_mulai'], $b['tgl_mulai']));
    return $hasil;
}

function jadwal_berikutnya(int $unit_id): ?array
{
    foreach (alokasi_aktif_unit($unit_id) as $alokasi) {
        if (!sedang_keluar($alokasi) && $alokasi['tgl_selesai'] >= HARI_INI) {
            return $alokasi;
        }
    }
    return null;
}

function tumpang_tindih(string $a_mulai, string $a_selesai, string $b_mulai, string $b_selesai): bool
{
    return $a_mulai <= $b_selesai && $b_mulai <= $a_selesai;
}

function rentang_alokasi(array $proyek): array
{
    $mulai = min(substr($proyek['deadline_persiapan'], 0, 10), substr($proyek['mulai'], 0, 10));
    return [$mulai, substr($proyek['selesai'], 0, 10)];
}

function cek_unit_untuk_proyek(array $unit, array $proyek): array
{
    if ($unit['status_aktif'] !== 'Aktif') {
        return ['blokir' => 'Unit nonaktif', 'catatan' => ''];
    }
    if ($unit['kondisi'] !== 'Baik') {
        return ['blokir' => 'Kondisi ' . $unit['kondisi'], 'catatan' => ''];
    }
    [$mulai, $selesai] = rentang_alokasi($proyek);
    $catatan = '';
    foreach (alokasi_aktif_unit($unit['id']) as $alokasi) {
        $lain = db_cari('proyek', $alokasi['proyek_id']);
        if ((int) $alokasi['proyek_id'] === (int) $proyek['id']) {
            return ['blokir' => 'Sudah dipakai di proyek ini', 'catatan' => ''];
        }
        if (tumpang_tindih($mulai, $selesai, $alokasi['tgl_mulai'], $alokasi['tgl_selesai'])) {
            return ['blokir' => 'Bentrok dengan ' . ($lain['kode'] ?? '?') . ' (' . rentang_tgl($alokasi['tgl_mulai'], $alokasi['tgl_selesai']) . ')', 'catatan' => ''];
        }
        if (sedang_keluar($alokasi)) {
            $catatan = 'Masih keluar di ' . ($lain['kode'] ?? '?');
        }
    }
    return ['blokir' => '', 'catatan' => $catatan];
}

function kode_unit_baru(int $kategori_id): string
{
    $awalan = db_cari('kategori', $kategori_id)['kode_awalan'] ?? 'UNT';
    $maks = 0;
    foreach (db('perlengkapan') as $unit) {
        if (strpos($unit['kode_unit'], $awalan . '-') === 0) {
            $maks = max($maks, (int) substr($unit['kode_unit'], strlen($awalan) + 1));
        }
    }
    return $awalan . '-' . str_pad((string) ($maks + 1), 3, '0', STR_PAD_LEFT);
}

function item_preset(int $preset_id): array
{
    $hasil = array_values(array_filter(db('detail_preset'), fn ($d) => (int) $d['preset_id'] === $preset_id));
    usort($hasil, fn ($a, $b) => $a['kategori_id'] <=> $b['kategori_id'] ?: strcmp($a['nama_perlengkapan'], $b['nama_perlengkapan']));
    return $hasil;
}

function stok_model(): array
{
    $hasil = [];
    foreach (db('perlengkapan') as $unit) {
        if ($unit['status_aktif'] !== 'Aktif') {
            continue;
        }
        $nama = $unit['nama'];
        $hasil[$nama] = $hasil[$nama] ?? ['kategori_id' => $unit['kategori_id'], 'total' => 0, 'tersedia' => 0];
        $hasil[$nama]['total']++;
        if (status_pemakaian($unit) === 'Tersedia') {
            $hasil[$nama]['tersedia']++;
        }
    }
    ksort($hasil);
    return $hasil;
}

function frekuensi_pakai(): array
{
    $hasil = array_fill_keys(array_keys(db('perlengkapan')), 0);
    foreach (db('alokasi') as $alokasi) {
        if ($alokasi['sumber'] === 'Studio' && $alokasi['status'] !== 'Dibatalkan' && isset($hasil[$alokasi['perlengkapan_id']])) {
            $hasil[$alokasi['perlengkapan_id']]++;
        }
    }
    return $hasil;
}

function ringkasan_pemakaian(): array
{
    $hasil = ['Tersedia' => 0, 'Dipinjam' => 0, 'Tidak Tersedia' => 0];
    foreach (db('perlengkapan') as $unit) {
        $hasil[status_pemakaian($unit)]++;
    }
    return $hasil;
}

function pemakaian_per_kategori(): array
{
    $hasil = [];
    foreach (db('kategori') as $kategori) {
        $hasil[$kategori['id']] = ['nama' => $kategori['nama'], 'Tersedia' => 0, 'Dipinjam' => 0, 'Tidak Tersedia' => 0, 'total' => 0];
    }
    foreach (db('perlengkapan') as $unit) {
        $hasil[$unit['kategori_id']][status_pemakaian($unit)]++;
        $hasil[$unit['kategori_id']]['total']++;
    }
    return $hasil;
}

function validasi_unit(array $data): array
{
    $galat = [];
    if (!db_cari('kategori', (int) $data['kategori_id'])) {
        $galat['kategori_id'] = 'Pilih kategori.';
    }
    if (mb_strlen($data['nama']) < 2) {
        $galat['nama'] = 'Nama model wajib diisi.';
    }
    if ($data['merek'] === '') {
        $galat['merek'] = 'Merek wajib diisi.';
    }
    if (!in_array($data['kondisi'], daftar_kondisi(), true)) {
        $galat['kondisi'] = 'Pilih kondisi.';
    }
    return $galat;
}

function daftar_insiden(): array
{
    $baris = array_values(db('insiden'));
    usort($baris, fn ($a, $b) => strcmp($b['waktu'], $a['waktu']));
    return $baris;
}

function insiden_terbuka(): array
{
    return array_values(array_filter(daftar_insiden(), fn ($i) => $i['status'] !== 'Selesai'));
}

function kode_insiden_baru(): string
{
    $maks = 0;
    foreach (db('insiden') as $insiden) {
        $maks = max($maks, (int) substr($insiden['kode'], 4));
    }
    return 'INS-' . str_pad((string) ($maks + 1), 3, '0', STR_PAD_LEFT);
}

function lapor_insiden(int $unit_id, ?int $proyek_id, string $jenis, string $deskripsi, string $kondisi_baru): string
{
    $unit = db_cari('perlengkapan', $unit_id);
    $kode = kode_insiden_baru();
    db_tambah('insiden', [
        'kode' => $kode,
        'perlengkapan_id' => $unit_id,
        'proyek_id' => $proyek_id,
        'jenis' => $jenis,
        'waktu' => date('Y-m-d H:i', strtotime(sekarang())),
        'deskripsi' => $deskripsi,
        'status' => 'Dilaporkan',
        'pelapor_id' => $_SESSION['user_id'] ?? null,
    ]);
    if ($unit && $unit['kondisi'] !== $kondisi_baru) {
        db_ubah('perlengkapan', $unit_id, ['kondisi' => $kondisi_baru]);
    }
    catat_riwayat('Insiden', label_unit($unit), $kode . ' · ' . $deskripsi, $unit['kondisi'] ?? '', $kondisi_baru);
    return $kode;
}
