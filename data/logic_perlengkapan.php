<?php
defined('ROOT') || exit;

// Inventaris Studio (kategori dan unit) dibaca dan ditulis di PostgreSQL: tabel kategori_perlengkapan dan perlengkapan.
// Modul yang masih memakai PHP Session (proyek, alokasi, persiapan, serah terima, insiden) membaca unit lewat
// semua_perlengkapan() / cari_perlengkapan(), sehingga hanya ada satu sumber data inventaris.

// Semua kategori, ber-key id. Dibaca sekali per request lalu disimpan sementara.
function semua_kategori(): array
{
    static $daftar = null;
    if ($daftar === null) {
        $daftar = [];
        foreach (sql_semua('SELECT id, nama, kode_awalan, deskripsi, nomor_terakhir FROM kategori_perlengkapan ORDER BY id') as $baris) {
            $baris['id'] = (int) $baris['id'];
            $baris['nomor_terakhir'] = (int) $baris['nomor_terakhir'];
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

function cari_kategori($id): ?array
{
    return semua_kategori()[(int) $id] ?? null;
}

// Semua unit Studio, ber-key id. $muat_ulang = true dipakai sesudah unit diubah agar request ini membaca data terbaru.
function semua_perlengkapan(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT id, kode_unit, nama, merek, kategori_id, kondisi, status_aktif, catatan FROM perlengkapan ORDER BY id') as $baris) {
            $baris['id'] = (int) $baris['id'];
            $baris['kategori_id'] = (int) $baris['kategori_id'];
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

function cari_perlengkapan($id): ?array
{
    return $id ? (semua_perlengkapan()[(int) $id] ?? null) : null;
}

// Penanda bahwa kondisi/status unit berubah pada request ini. Bootstrap memakainya untuk menjalankan
// sinkron_konfirmasi_persiapan(), karena perubahan di PostgreSQL tidak mengubah $_SESSION['db'].
// inventaris_berubah(true) = tandai, inventaris_berubah(false) = hapus tanda (dipakai bila perubahan dibatalkan).
function inventaris_berubah(?bool $atur = null): bool
{
    static $berubah = false;
    if ($atur !== null) {
        $berubah = $atur;
    }
    return $berubah;
}

// Proses modul Session (insiden, pengembalian) yang juga mengubah kondisi unit di PostgreSQL.
// Session dan PostgreSQL tidak bisa digabung dalam satu transaksi database, jadi dibuat "semua atau tidak sama sekali":
//   1. salin $_SESSION['db'] sebagai cadangan;
//   2. jalankan $kerja di dalam sql_transaksi(): UPDATE kondisi unit baru permanen saat COMMIT di akhir;
//   3. bila ada error (database gagal di tengah proses), PostgreSQL di-ROLLBACK dan session dikembalikan ke cadangan.
// Dengan begitu tidak ada keadaan setengah jadi, misalnya transaksi pengembalian tercatat tetapi kondisi unit belum berubah.
function proses_session_dan_inventaris(callable $kerja)
{
    $cadangan = $_SESSION['db'];
    try {
        return sql_transaksi($kerja);
    } catch (Throwable $e) {
        $_SESSION['db'] = $cadangan;
        inventaris_berubah(false);
        throw $e;
    }
}

// Tambah unit baru. Nomor kode diambil dari kategori_perlengkapan.nomor_terakhir di dalam satu transaksi:
// UPDATE mengunci baris kategori sampai COMMIT, jadi dua petugas yang menambah unit bersamaan mendapat nomor berbeda.
// Nomor tidak pernah turun, sehingga kode unit lama tidak dipakai ulang. Hasil: kode unit baru, atau null bila kategori tidak ada.
function tambah_unit(int $kategori_id, array $isi): ?string
{
    $kode = sql_transaksi(function () use ($kategori_id, $isi) {
        $nomor = sql_satu('UPDATE kategori_perlengkapan SET nomor_terakhir = nomor_terakhir + 1
                           WHERE id = :id
                           RETURNING kode_awalan, nomor_terakhir', ['id' => $kategori_id]);
        if (!$nomor) {
            return null;
        }
        $kode = $nomor['kode_awalan'] . '-' . str_pad((string) $nomor['nomor_terakhir'], 3, '0', STR_PAD_LEFT);
        sql_jalankan('INSERT INTO perlengkapan (kode_unit, nama, merek, kategori_id, kondisi, status_aktif, catatan)
                      VALUES (:kode_unit, :nama, :merek, :kategori_id, :kondisi, :status_aktif, :catatan)',
            ['kode_unit' => $kode, 'kategori_id' => $kategori_id] + $isi);
        catat_riwayat_perlengkapan($kode . ' · ' . $isi['nama'], 'Unit baru ditambahkan ke inventaris');
        return $kode;
    });
    semua_perlengkapan(true);
    return $kode;
}

// Ubah data unit. Kategori dan kode unit tidak ikut diubah (aturan aplikasi).
// SELECT ... FOR UPDATE membaca kondisi/status terbaru sebagai nilai "lama" di riwayat. Hasil: false bila unit tidak ada.
function ubah_unit(int $id, array $isi): bool
{
    $berhasil = sql_transaksi(function () use ($id, $isi) {
        $lama = sql_satu('SELECT kode_unit, kondisi, status_aktif FROM perlengkapan WHERE id = :id FOR UPDATE', ['id' => $id]);
        if (!$lama) {
            return false;
        }
        sql_jalankan('UPDATE perlengkapan
                      SET nama = :nama, merek = :merek, kondisi = :kondisi, status_aktif = :status_aktif, catatan = :catatan
                      WHERE id = :id', $isi + ['id' => $id]);
        $label = $lama['kode_unit'] . ' · ' . $isi['nama'];
        if ($lama['kondisi'] !== $isi['kondisi']) {
            catat_riwayat_perlengkapan($label, 'Kondisi unit diubah', $lama['kondisi'], $isi['kondisi']);
        } elseif ($lama['status_aktif'] !== $isi['status_aktif']) {
            catat_riwayat_perlengkapan($label, 'Status unit diubah', $lama['status_aktif'], $isi['status_aktif']);
        } else {
            catat_riwayat_perlengkapan($label, 'Data unit diperbarui');
        }
        return true;
    });
    if ($berhasil) {
        semua_perlengkapan(true);
        inventaris_berubah(true);
    }
    return $berhasil;
}

// Ubah kondisi unit dari alur Insiden/Pengembalian (modul Session). Riwayatnya dicatat modul pemanggil
// sebagai aksi 'Insiden', jadi di sini tidak dicatat lagi agar tidak tampil ganda.
function ubah_kondisi_unit(int $id, string $kondisi): void
{
    sql_jalankan('UPDATE perlengkapan SET kondisi = :kondisi WHERE id = :id', ['kondisi' => $kondisi, 'id' => $id]);
    semua_perlengkapan(true);
    inventaris_berubah(true);
}

function opsi_kategori(): array
{
    $hasil = [];
    foreach (semua_kategori() as $kategori) {
        $hasil[$kategori['id']] = $kategori['nama'];
    }
    return $hasil;
}

function nama_kategori($id): string
{
    return cari_kategori($id)['nama'] ?? '—';
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
    // Sprint 3C: satu detail Keluar per alokasi; pengembalian boleh beberapa baris (sebagian / per kondisi).
    // Baris lama tanpa field jumlah dihitung 1 unit. 'kembali' hanya diisi bila seluruh jumlah keluar sudah kembali.
    $cache = [];
    $transaksi = db('transaksi');
    foreach (db('detail_transaksi') as $detail) {
        $trx = $transaksi[$detail['transaksi_id']] ?? null;
        if (!$trx) {
            continue;
        }
        $aid = $detail['alokasi_id'];
        $jumlah = max(1, (int) ($detail['jumlah'] ?? 1));
        if ($trx['jenis'] === 'Keluar') {
            $cache[$aid]['keluar'] = $trx['waktu'];
            $cache[$aid]['trx_keluar'] = $trx['id'];
            $cache[$aid]['jumlah_keluar'] = $jumlah;
            continue;
        }
        $kondisi = (string) $detail['kondisi'];
        $cache[$aid]['jumlah_kembali'] = ($cache[$aid]['jumlah_kembali'] ?? 0) + $jumlah;
        $cache[$aid]['kondisi_rincian'][$kondisi] = ($cache[$aid]['kondisi_rincian'][$kondisi] ?? 0) + $jumlah;
        if (!isset($cache[$aid]['kembali_terakhir']) || $trx['waktu'] >= $cache[$aid]['kembali_terakhir']) {
            $cache[$aid]['kembali_terakhir'] = $trx['waktu'];
            $cache[$aid]['trx_kembali_terakhir'] = $trx['id'];
        }
        if (!in_array($trx['id'], $cache[$aid]['trx_kembali_daftar'] ?? [], true)) {
            $cache[$aid]['trx_kembali_daftar'][] = $trx['id'];
        }
    }
    foreach ($cache as $aid => $s) {
        $keluar = $s['jumlah_keluar'] ?? 0;
        $kembali = $s['jumlah_kembali'] ?? 0;
        $cache[$aid]['jumlah_kembali'] = $kembali;
        $cache[$aid]['sisa'] = max(0, $keluar - $kembali);
        $rincian = $s['kondisi_rincian'] ?? [];
        $cache[$aid]['kondisi_ringkas'] = !empty($rincian['Rusak']) ? 'Rusak' : (!empty($rincian['Maintenance']) ? 'Maintenance' : ($kembali ? 'Baik' : ''));
        if (isset($s['keluar'])) {
            $cache[$aid]['status_kembali'] = $kembali === 0 ? 'Belum Kembali' : ($kembali < $keluar ? 'Kembali Sebagian' : 'Sudah Kembali');
            if ($kembali > 0 && $kembali >= $keluar) {
                $cache[$aid]['kembali'] = $s['kembali_terakhir'];
                $cache[$aid]['trx_kembali'] = $s['trx_kembali_terakhir'];
                $cache[$aid]['kondisi_kembali'] = $cache[$aid]['kondisi_ringkas'];
            }
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

function item_preset(int $preset_id): array
{
    $hasil = array_values(array_filter(db('detail_preset'), fn ($d) => (int) $d['preset_id'] === $preset_id));
    usort($hasil, fn ($a, $b) => $a['kategori_id'] <=> $b['kategori_id'] ?: strcmp($a['nama_perlengkapan'], $b['nama_perlengkapan']));
    return $hasil;
}

function stok_model(): array
{
    $hasil = [];
    foreach (semua_perlengkapan() as $unit) {
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
    $hasil = array_fill_keys(array_keys(semua_perlengkapan()), 0);
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
    foreach (semua_perlengkapan() as $unit) {
        $hasil[status_pemakaian($unit)]++;
    }
    return $hasil;
}

function pemakaian_per_kategori(): array
{
    $hasil = [];
    foreach (semua_kategori() as $kategori) {
        $hasil[$kategori['id']] = ['nama' => $kategori['nama'], 'Tersedia' => 0, 'Dipinjam' => 0, 'Tidak Tersedia' => 0, 'total' => 0];
    }
    foreach (semua_perlengkapan() as $unit) {
        $hasil[$unit['kategori_id']][status_pemakaian($unit)]++;
        $hasil[$unit['kategori_id']]['total']++;
    }
    return $hasil;
}

function validasi_unit(array $data): array
{
    // Batas panjang mengikuti kolom di database/001_schema.sql (nama 60, merek 30, catatan 120).
    $galat = [];
    if (!preg_match('/^\d+$/', (string) $data['kategori_id']) || !cari_kategori($data['kategori_id'])) {
        $galat['kategori_id'] = 'Pilih kategori.';
    }
    if (mb_strlen($data['nama']) < 2) {
        $galat['nama'] = 'Nama model wajib diisi.';
    } elseif (mb_strlen($data['nama']) > 60) {
        $galat['nama'] = 'Nama model maksimal 60 karakter.';
    }
    if ($data['merek'] === '') {
        $galat['merek'] = 'Merek wajib diisi.';
    } elseif (mb_strlen($data['merek']) > 30) {
        $galat['merek'] = 'Merek maksimal 30 karakter.';
    }
    if (!in_array($data['kondisi'], daftar_kondisi(), true)) {
        $galat['kondisi'] = 'Pilih kondisi.';
    }
    if (!in_array($data['status_aktif'], ['Aktif', 'Nonaktif'], true)) {
        $galat['status_aktif'] = 'Pilih status unit.';
    }
    if (mb_strlen($data['catatan']) > 120) {
        $galat['catatan'] = 'Catatan maksimal 120 karakter.';
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
    $unit = cari_perlengkapan($unit_id);
    $kode = kode_insiden_baru();
    // Kondisi unit ada di PostgreSQL dan diubah lebih dulu: bila database gagal, catatan insiden di session belum dibuat.
    if ($unit && $unit['kondisi'] !== $kondisi_baru) {
        ubah_kondisi_unit($unit_id, $kondisi_baru);
    }
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
    catat_riwayat('Insiden', label_unit($unit), $kode . ' · ' . $deskripsi, $unit['kondisi'] ?? '', $kondisi_baru);
    return $kode;
}

function daftar_barang_eksternal(): array
{
    $diperiksa = [];
    $hasil = [];
    foreach (db('alokasi') as $alokasi) {
        if ($alokasi['sumber'] === 'Studio') {
            continue;
        }
        $pid = (int) $alokasi['proyek_id'];
        $diperiksa[$pid] = $diperiksa[$pid] ?? id_diperiksa($pid);
        $proyek = db_cari('proyek', $pid);
        $hasil[] = [
            'alokasi' => $alokasi,
            'proyek' => $proyek,
            'kebutuhan' => db_cari('kebutuhan', (int) $alokasi['kebutuhan_id']),
            'jumlah' => jumlah_alokasi($alokasi),
            'asal' => $alokasi['sumber'] === 'Rental' ? $alokasi['vendor'] : nama_pengguna($alokasi['pemilik_id']),
            'diperiksa' => alokasi_diperiksa($alokasi, $diperiksa[$pid]),
            'centang_tersimpan' => in_array((int) $alokasi['id'], $diperiksa[$pid], true),
            'diperiksa_aktif' => alokasi_siap($alokasi, $diperiksa[$pid]),
            'proyek_aktif' => $proyek && proyek_aktif($proyek),
            'serah' => serah_alokasi((int) $alokasi['id']),
        ];
    }
    usort($hasil, fn ($a, $b) => [$a['alokasi']['status'] === 'Dibatalkan', !$a['proyek_aktif'], $a['alokasi']['tgl_mulai'], $a['alokasi']['id']]
        <=> [$b['alokasi']['status'] === 'Dibatalkan', !$b['proyek_aktif'], $b['alokasi']['tgl_mulai'], $b['alokasi']['id']]);
    return $hasil;
}

function ringkasan_eksternal(array $daftar): array
{
    $hasil = ['alokasi_aktif' => 0, 'unit_rental' => 0, 'unit_kru' => 0, 'direncanakan' => 0, 'unit_direncanakan' => 0];
    foreach ($daftar as $baris) {
        $status = $baris['alokasi']['status'];
        if ($status === 'Dibatalkan') {
            continue;
        }
        $hasil['alokasi_aktif']++;
        $hasil[$baris['alokasi']['sumber'] === 'Rental' ? 'unit_rental' : 'unit_kru'] += $baris['jumlah'];
        if ($status === 'Direncanakan') {
            $hasil['direncanakan']++;
            $hasil['unit_direncanakan'] += $baris['jumlah'];
        }
    }
    return $hasil;
}

function tanggal_tegas(string $nilai): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $nilai, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return $nilai;
}

function waktu_tegas(string $nilai): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $nilai, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
        return null;
    }
    return $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5];
}

function info_rental(array $alokasi): array
{
    $jatuh_tempo = $alokasi['jatuh_tempo_vendor'] ?? null;
    $diterima = $alokasi['diterima_pada'] ?? null;
    $dikembalikan = $alokasi['dikembalikan_vendor_pada'] ?? null;
    $sisa = $jatuh_tempo ? (int) round((strtotime($jatuh_tempo) - strtotime(HARI_INI)) / 86400) : null;
    $batal = $alokasi['status'] === 'Dibatalkan';
    $dipakai = isset($alokasi['id']) && sedang_keluar($alokasi);
    if ($batal) {
        $status = '';
        $ket = 'Alokasi dibatalkan';
    } elseif ($dikembalikan) {
        $status = 'Sudah Dikembalikan';
        $ket = 'Diserahkan ke vendor ' . tgl($dikembalikan, 'singkat_jam');
    } elseif (!$jatuh_tempo) {
        $status = 'Belum Dicatat';
        $ket = 'Jatuh tempo vendor belum diatur';
    } elseif ($sisa < 0 && $diterima === null) {
        $status = 'Tempo Vendor Lewat';
        $ket = 'Belum diterima · lewat ' . abs($sisa) . ' hari';
    } elseif ($sisa < 0) {
        $status = 'Terlambat';
        $ket = 'Terlambat ' . abs($sisa) . ' hari';
    } elseif ($sisa <= 2) {
        $status = 'Mendekati Jatuh Tempo';
        $ket = $sisa === 0 ? 'Jatuh tempo hari ini' : $sisa . ' hari lagi';
    } else {
        $status = 'Dalam Masa Sewa';
        $ket = $sisa . ' hari lagi';
    }
    return [
        'jatuh_tempo' => $jatuh_tempo,
        'sisa_hari' => $sisa,
        'status_sewa' => $status,
        'keterangan' => $ket,
        'diterima' => $diterima !== null,
        'dikembalikan' => $dikembalikan !== null,
        'bisa_terima' => $alokasi['status'] === 'Dikonfirmasi' && $diterima === null,
        'bisa_tempo' => !$batal && $dikembalikan === null,
        'bisa_kembali' => !$batal && $diterima !== null && $dikembalikan === null && !$dipakai,
        'dipakai_proyek' => $dipakai,
    ];
}

function rental_punya_catatan_vendor(array $alokasi): bool
{
    return $alokasi['sumber'] === 'Rental' && !empty($alokasi['diterima_pada']);
}

function ringkasan_rental_teks(array $alokasi): string
{
    if ($alokasi['status'] === 'Dibatalkan') {
        return '';
    }
    $info = info_rental($alokasi);
    $teks = $info['diterima'] ? 'Diterima dari vendor ' . tgl($alokasi['diterima_pada'], 'singkat_jam') : 'Penerimaan vendor belum dicatat';
    $teks .= ' · jatuh tempo ' . ($info['jatuh_tempo'] ? tgl($info['jatuh_tempo']) : 'belum dicatat');
    return $teks . ' · ' . $info['status_sewa'] . ($info['status_sewa'] !== 'Belum Dicatat' ? ' (' . $info['keterangan'] . ')' : '');
}

function proses_aksi_rental(string $aksi, array $alokasi, array $pengguna): array
{
    $info = info_rental($alokasi);
    $galat = [];
    $nama = $alokasi['nama_barang'] . ' (' . $alokasi['vendor'] . ')';
    $kini = date('Y-m-d H:i', strtotime(sekarang()));
    $jumlah = jumlah_alokasi($alokasi);
    $label = $alokasi['nama_barang'] . ' · ' . (db_cari('proyek', (int) $alokasi['proyek_id'])['kode'] ?? '?');

    if ($aksi === 'terima_rental' || $aksi === 'kembalikan_vendor') {
        $terima = $aksi === 'terima_rental';
        if ($terima && !$info['bisa_terima']) {
            return ['umum' => $info['diterima'] ? $nama . ' sudah tercatat diterima pada ' . tgl($alokasi['diterima_pada'], 'lengkap') . '.' : 'Penerimaan hanya untuk alokasi Rental yang sudah Dikonfirmasi.'];
        }
        if (!$terima && $info['dipakai_proyek']) {
            $serah = serah_alokasi((int) $alokasi['id']);
            $trx = db_cari('transaksi', $serah['trx_keluar'] ?? 0);
            return ['umum' => $nama . ' masih tercatat digunakan proyek ' . (db_cari('proyek', (int) $alokasi['proyek_id'])['kode'] ?? '?') . ' (barang keluar ' . ($trx['kode'] ?? '?') . ', ' . tgl($serah['keluar'], 'lengkap') . '): ' . (int) ($serah['sisa'] ?? 0) . ' dari ' . (int) ($serah['jumlah_keluar'] ?? 0) . ' unit belum kembali dari proyek. Catat pengembalian dari proyek terlebih dahulu di Serah Terima; pengembalian ke vendor baru bisa setelah seluruh unit kembali.'];
        }
        if (!$terima && !$info['bisa_kembali']) {
            return ['umum' => $info['dikembalikan'] ? $nama . ' sudah tercatat dikembalikan ke vendor pada ' . tgl($alokasi['dikembalikan_vendor_pada'], 'lengkap') . '.' : $nama . ' belum tercatat diterima dari vendor, jadi belum bisa dikembalikan.'];
        }
        if (input('jumlah') !== (string) $jumlah) {
            $galat['jumlah'] = 'Pencatatan sebagian belum didukung. Catat seluruh ' . $jumlah . ' unit sekaligus.';
        }
        $waktu = waktu_tegas(input('waktu'));
        if (!$waktu) {
            $galat['waktu'] = 'Isi waktu dengan tanggal dan jam yang valid.';
        } elseif ($waktu > $kini) {
            $galat['waktu'] = 'Waktu tidak boleh melewati saat ini (' . tgl($kini, 'lengkap') . ').';
        } elseif (!$terima && $waktu < $alokasi['diterima_pada']) {
            $galat['waktu'] = 'Waktu pengembalian tidak boleh sebelum penerimaan (' . tgl($alokasi['diterima_pada'], 'lengkap') . ').';
        }
        $kondisi = input('kondisi');
        if (!in_array($kondisi, daftar_kondisi(), true)) {
            $galat['kondisi'] = 'Pilih kondisi barang.';
        }
        $catatan = input('catatan');
        if ($kondisi !== 'Baik' && in_array($kondisi, daftar_kondisi(), true) && mb_strlen($catatan) < 5) {
            $galat['catatan'] = 'Jelaskan kondisi barang yang tidak baik.';
        } elseif (mb_strlen($catatan) > 120) {
            $galat['catatan'] = 'Catatan maksimal 120 karakter.';
        }
        if (input('konfirmasi') !== '1') {
            $galat['konfirmasi'] = $terima ? 'Centang konfirmasi bahwa barang sudah diterima secara fisik.' : 'Centang konfirmasi bahwa barang sudah diserahkan kepada vendor.';
        }
        if ($galat) {
            return ['galat' => $galat];
        }
        if ($terima) {
            $centang_lama = in_array((int) $alokasi['id'], id_diperiksa((int) $alokasi['proyek_id']), true);
            db_ubah('alokasi', (int) $alokasi['id'], ['diterima_pada' => $waktu, 'diterima_oleh' => $pengguna['id'], 'kondisi_terima' => $kondisi, 'catatan_terima' => $catatan]);
            lepas_dari_checklist((int) $alokasi['proyek_id'], [(int) $alokasi['id']]);
            catat_riwayat('Rental', $label, $jumlah . ' unit diterima dari ' . $alokasi['vendor'] . ', kondisi ' . $kondisi . ($catatan !== '' ? ' · ' . $catatan : '') . ($centang_lama ? ' · centang persiapan lama (sebelum penerimaan) direset' : ''));
            return ['sukses' => $nama . ': penerimaan ' . $jumlah . ' unit dicatat. ' . ($centang_lama ? 'Centang persiapan lama dari sebelum penerimaan direset; periksa barang lalu centang ulang di Persiapan proyek.' : 'Centang di Persiapan proyek setelah barang diperiksa.')];
        }
        db_ubah('alokasi', (int) $alokasi['id'], ['dikembalikan_vendor_pada' => $waktu, 'dikembalikan_vendor_oleh' => $pengguna['id'], 'kondisi_kembali_vendor' => $kondisi, 'catatan_kembali_vendor' => $catatan]);
        catat_riwayat('Rental', $label, $jumlah . ' unit dikembalikan ke ' . $alokasi['vendor'] . ', kondisi ' . $kondisi . ($catatan !== '' ? ' · ' . $catatan : ''));
        return ['sukses' => $nama . ': pengembalian ' . $jumlah . ' unit ke vendor dicatat.'];
    }

    if ($aksi === 'atur_jatuh_tempo' || $aksi === 'perpanjang_rental') {
        $atur = $aksi === 'atur_jatuh_tempo';
        if (!$info['bisa_tempo']) {
            return ['umum' => $info['dikembalikan'] ? $nama . ' sudah dikembalikan ke vendor, jadi jatuh temponya tidak bisa diubah lagi.' : 'Jatuh tempo hanya untuk alokasi Rental yang aktif.'];
        }
        if ($atur && $info['jatuh_tempo']) {
            return ['umum' => $nama . ' sudah punya jatuh tempo ' . tgl($info['jatuh_tempo']) . '. Gunakan Perpanjang rental.'];
        }
        if (!$atur && !$info['jatuh_tempo']) {
            return ['umum' => $nama . ' belum punya jatuh tempo. Gunakan Atur jatuh tempo.'];
        }
        $baru = tanggal_tegas(input('jatuh_tempo'));
        $mulai = null;
        if ($atur && input('sewa_mulai') !== '') {
            $mulai = tanggal_tegas(input('sewa_mulai'));
            if (!$mulai) {
                $galat['sewa_mulai'] = 'Tanggal mulai sewa tidak valid.';
            }
        }
        if (!$baru) {
            $galat['jatuh_tempo'] = 'Isi tanggal jatuh tempo yang valid.';
        } elseif (!$atur && $baru <= $info['jatuh_tempo']) {
            $galat['jatuh_tempo'] = 'Jatuh tempo baru harus setelah ' . tgl($info['jatuh_tempo']) . '.';
        } elseif ($mulai && $baru < $mulai) {
            $galat['jatuh_tempo'] = 'Jatuh tempo tidak boleh sebelum tanggal mulai sewa.';
        }
        $kontak = input('kontak_vendor');
        $alasan = input('alasan');
        if (mb_strlen($kontak) > 60) {
            $galat['kontak_vendor'] = 'Kontak vendor maksimal 60 karakter.';
        }
        if (mb_strlen($alasan) > 120) {
            $galat['alasan'] = 'Alasan maksimal 120 karakter.';
        }
        if ($galat) {
            return ['galat' => $galat];
        }
        if ($atur) {
            db_ubah('alokasi', (int) $alokasi['id'], ['jatuh_tempo_vendor' => $baru, 'sewa_mulai' => $mulai, 'kontak_vendor' => $kontak]);
            catat_riwayat('Rental', $label, 'Jatuh tempo vendor diatur', '', tgl($baru));
            return ['sukses' => $nama . ': jatuh tempo vendor diatur ke ' . tgl($baru, 'panjang') . '.'];
        }
        db_ubah('alokasi', (int) $alokasi['id'], ['jatuh_tempo_vendor' => $baru]);
        catat_riwayat('Rental', $label, 'Masa sewa diperpanjang' . ($alasan !== '' ? ' · ' . $alasan : ''), tgl($info['jatuh_tempo']), tgl($baru));
        return ['sukses' => $nama . ': masa sewa diperpanjang sampai ' . tgl($baru, 'panjang') . '.'];
    }
    return ['umum' => 'Aksi rental tidak dikenal.'];
}
