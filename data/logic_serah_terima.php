<?php
defined('ROOT') || exit;

// ---------------------------------------------------------------------------------------------
// Serah terima (Sprint Backend 4A) di PostgreSQL: transaksi_barang (kepala) dan detail_transaksi (rincian per alokasi).
//   - Satu transaksi Keluar per alokasi (seluruh jumlah alokasi sekaligus); pengembalian boleh bertahap dan dipecah per kondisi.
//   - Saldo (keluar - kembali) dihitung dari view v_saldo_alokasi di dalam transaksi tulis, dan dari cache yang sama untuk tampilan.
//   - Tabel transaksi hanya ditambah (hak akses aplikasi: SELECT, INSERT), dan detail_transaksi.alokasi_id adalah FOREIGN KEY
//     tanpa CASCADE, sehingga alokasi yang pernah diserahterimakan tidak bisa terhapus di tingkat database.
//   - Urutan kunci sama dengan Sprint 3C: proyek -> alokasi (urut id) -> unit perlengkapan.
// ---------------------------------------------------------------------------------------------

const KOLOM_TRANSAKSI = "id, kode, proyek_id, jenis, TO_CHAR(waktu, 'YYYY-MM-DD HH24:MI') AS waktu, petugas_id, penerima, penerima_id,
    penyerah, penyerah_id, catatan, TO_CHAR(dicatat_pada, 'YYYY-MM-DD HH24:MI') AS dicatat_pada";

// Semua transaksi serah terima, ber-key id (format sama dengan data session lama).
function semua_transaksi(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT ' . KOLOM_TRANSAKSI . ' FROM transaksi_barang ORDER BY id') as $baris) {
            foreach (['id', 'proyek_id', 'petugas_id'] as $kolom) {
                $baris[$kolom] = (int) $baris[$kolom];
            }
            foreach (['penerima_id', 'penyerah_id'] as $kolom) {
                $baris[$kolom] = $baris[$kolom] === null ? null : (int) $baris[$kolom];
            }
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

function cari_transaksi($id): ?array
{
    return semua_transaksi()[(int) $id] ?? null;
}

// Semua rincian transaksi, ber-key id. 'trx_asal' (transaksi Keluar asal) untuk baris Kembali diturunkan dari aturan
// satu transaksi Keluar per alokasi, sehingga tidak perlu disimpan.
function semua_detail_transaksi(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        $keluar = [];
        $transaksi = semua_transaksi($muat_ulang);
        foreach (sql_semua('SELECT id, transaksi_id, alokasi_id, kondisi, jumlah FROM detail_transaksi ORDER BY id') as $baris) {
            foreach (['id', 'transaksi_id', 'alokasi_id', 'jumlah'] as $kolom) {
                $baris[$kolom] = (int) $baris[$kolom];
            }
            if (($transaksi[$baris['transaksi_id']]['jenis'] ?? '') === 'Keluar') {
                $keluar[$baris['alokasi_id']] = $keluar[$baris['alokasi_id']] ?? $baris['transaksi_id'];
            }
            $daftar[$baris['id']] = $baris;
        }
        foreach ($daftar as $id => $baris) {
            if (($transaksi[$baris['transaksi_id']]['jenis'] ?? '') === 'Kembali') {
                $daftar[$id]['trx_asal'] = $keluar[$baris['alokasi_id']] ?? null;
            }
        }
    }
    return $daftar;
}

// Sesudah serah terima berubah: baca ulang, dan tandai agar bootstrap menyinkronkan konfirmasi persiapan (session).
function muat_ulang_serah(): void
{
    semua_transaksi(true);
    semua_detail_transaksi(true);
    proyek_berubah(true);
}

// Saldo alokasi dari view v_saldo_alokasi (hanya alokasi yang pernah keluar), ber-key alokasi_id.
function saldo_alokasi_db(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $param = [];
    foreach ($ids as $i => $id) {
        $param['a' . $i] = $id;
    }
    $hasil = [];
    foreach (sql_semua("SELECT alokasi_id, trx_keluar, TO_CHAR(waktu_keluar, 'YYYY-MM-DD HH24:MI') AS waktu_keluar, jumlah_keluar, jumlah_kembali, sisa,
                               TO_CHAR(kembali_terakhir, 'YYYY-MM-DD HH24:MI') AS kembali_terakhir
                        FROM v_saldo_alokasi WHERE alokasi_id IN (:" . implode(', :', array_keys($param)) . ')', $param) as $baris) {
        foreach (['alokasi_id', 'trx_keluar', 'jumlah_keluar', 'jumlah_kembali', 'sisa'] as $kolom) {
            $baris[$kolom] = (int) $baris[$kolom];
        }
        $hasil[$baris['alokasi_id']] = $baris;
    }
    return $hasil;
}

// Penerima/penyerah harus PIC atau kru proyek (dibaca di dalam kunci proyek). $aktif = true: akun juga harus Aktif.
function anggota_tim_db(int $proyek_id, int $pic_id, int $pengguna_id, bool $aktif): bool
{
    return (bool) sql_satu('SELECT 1 FROM pengguna WHERE id = :u' . ($aktif ? " AND status = 'Aktif'" : '') . '
                            AND (id = :pic OR id IN (SELECT pengguna_id FROM anggota_proyek WHERE proyek_id = :p))',
        ['u' => $pengguna_id, 'pic' => $pic_id, 'p' => $proyek_id]);
}

function kode_transaksi_baru(): string
{
    $nomor = (int) sql_satu("SELECT nextval('seq_kode_transaksi') AS nomor")['nomor'];
    return 'TRX-' . str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);
}

function tambah_transaksi(array $isi): int
{
    return (int) sql_satu('INSERT INTO transaksi_barang (kode, proyek_id, jenis, waktu, petugas_id, penerima, penerima_id, penyerah, penyerah_id, catatan, dicatat_pada)
                           VALUES (:kode, :proyek_id, :jenis, :waktu, :petugas_id, :penerima, :penerima_id, :penyerah, :penyerah_id, :catatan, :dicatat_pada)
                           RETURNING id', $isi + ['penerima_id' => null, 'penyerah' => '', 'penyerah_id' => null, 'dicatat_pada' => sekarang()])['id'];
}

function tambah_detail_transaksi(int $trx_id, int $alokasi_id, string $kondisi, int $jumlah): int
{
    return (int) sql_satu('INSERT INTO detail_transaksi (transaksi_id, alokasi_id, kondisi, jumlah) VALUES (:t, :a, :k, :j) RETURNING id',
        ['t' => $trx_id, 'a' => $alokasi_id, 'k' => $kondisi, 'j' => $jumlah])['id'];
}

// Sprint 3E final: tidak ada lagi status "Terlambat/Hari ini" untuk barang proyek. Tanggal selesai event (tgl_selesai)
// hanya informasi jadwal, bukan batas wajib pengembalian. Status barang = saldo transaksi (keluar - akumulasi kembali).

function jumlah_detail(array $detail): int
{
    return max(1, (int) ($detail['jumlah'] ?? 1));
}

function gerbang_keluar(array $proyek): array
{
    $np = penilaian_persiapan($proyek);
    if (!proyek_aktif($proyek)) {
        return ['boleh' => false, 'alasan' => 'Proyek ' . $proyek['kode'] . ' berstatus ' . $proyek['status'] . ', jadi barang tidak bisa dikeluarkan lagi. Pengembalian barang yang masih di luar tetap bisa dicatat.', 'nilai' => $np];
    }
    if (!$np['dikonfirmasi_pada']) {
        if ($np['syarat']['sudah_keluar'] > 0) {
            $alasan = 'Barang proyek ini sudah tercatat keluar sebelum ada konfirmasi persiapan (data lama). Barang keluar baru tidak bisa dicatat karena persiapannya tidak pernah dikonfirmasi; pengembalian unit studio tetap bisa dicatat.';
        } elseif ($np['konfirmasi_batal']) {
            $alasan = 'Konfirmasi persiapan sebelumnya ' . ($np['konfirmasi_batal']['otomatis'] ? 'tidak berlaku lagi' : 'sudah dibuka ulang') . '. Konfirmasi ulang di tab Persiapan sebelum barang dikeluarkan.';
        } elseif ($np['status'] === 'Siap untuk Dikonfirmasi') {
            $alasan = 'Checklist dan syarat sudah terpenuhi, tetapi persiapan belum dikonfirmasi selesai. Konfirmasi di tab Persiapan terlebih dahulu.';
        } else {
            $alasan = 'Persiapan belum dikonfirmasi selesai (' . $np['status'] . '). Selesaikan persiapan lalu konfirmasi di tab Persiapan.';
        }
        return ['boleh' => false, 'alasan' => $alasan, 'nilai' => $np];
    }
    if ($np['perlu_cabut']) {
        return ['boleh' => false, 'alasan' => 'Konfirmasi persiapan tidak berlaku lagi karena data berubah: ' . implode(' ', $np['syarat']['masalah']), 'nilai' => $np];
    }
    return ['boleh' => true, 'alasan' => '', 'nilai' => $np];
}

function alasan_tidak_bisa_keluar(array $alokasi, array $diperiksa): string
{
    if ($alokasi['status'] === 'Dibatalkan') {
        return 'Alokasi dibatalkan';
    }
    $serah = serah_alokasi((int) $alokasi['id']);
    if (isset($serah['keluar'])) {
        $trx = cari_transaksi($serah['trx_keluar'] ?? 0);
        return isset($serah['kembali']) ? 'Sudah keluar dan sudah dikembalikan' : 'Sudah tercatat keluar' . ($trx ? ' (' . $trx['kode'] . ')' : '');
    }
    if ($alokasi['status'] !== 'Dikonfirmasi') {
        return 'Alokasi belum dikonfirmasi';
    }
    $pid = (int) $alokasi['proyek_id'];
    if (!alokasi_sesuai_kebutuhan($alokasi, cari_kebutuhan((int) $alokasi['kebutuhan_id']), $pid)) {
        return 'Tidak sesuai dengan kebutuhan proyek';
    }
    if ($alokasi['sumber'] === 'Studio') {
        $unit = cari_perlengkapan((int) $alokasi['perlengkapan_id']);
        if (!$unit) {
            return 'Unit sudah dihapus';
        }
        if ($unit['status_aktif'] !== 'Aktif') {
            return 'Unit nonaktif';
        }
        if ($unit['kondisi'] !== 'Baik') {
            return 'Kondisi ' . $unit['kondisi'];
        }
        $lain = alokasi_keluar_unit((int) $unit['id']);
        if ($lain) {
            return 'Masih keluar di ' . (cari_proyek($lain['proyek_id'])['kode'] ?? '?');
        }
    }
    if ($alokasi['sumber'] === 'Rental') {
        if (empty($alokasi['diterima_pada'])) {
            return 'Belum tercatat diterima dari vendor';
        }
        if (!empty($alokasi['dikembalikan_vendor_pada'])) {
            return 'Sudah dikembalikan ke vendor';
        }
    }
    if (!in_array((int) $alokasi['id'], $diperiksa, true)) {
        return 'Belum diperiksa di persiapan';
    }
    return '';
}

function alokasi_siap_keluar(int $proyek_id): array
{
    $diperiksa = id_diperiksa($proyek_id);
    $hasil = [];
    foreach (alokasi_proyek($proyek_id, true) as $alokasi) {
        if (isset(serah_alokasi((int) $alokasi['id'])['keluar'])) {
            continue;
        }
        $hasil[] = [
            'alokasi' => $alokasi,
            'unit' => $alokasi['sumber'] === 'Studio' ? cari_perlengkapan((int) $alokasi['perlengkapan_id']) : null,
            'jumlah' => jumlah_alokasi($alokasi),
            'blokir' => alasan_tidak_bisa_keluar($alokasi, $diperiksa),
        ];
    }
    return $hasil;
}

function hitung_per_sumber(array $baris, bool $hanya_bisa = false): array
{
    $hasil = ['Studio' => 0, 'Rental' => 0, 'Milik Kru' => 0];
    foreach ($baris as $b) {
        if (!$hanya_bisa || ($b['blokir'] ?? '') === '') {
            $hasil[$b['alokasi']['sumber']] += $b['jumlah'] ?? jumlah_alokasi($b['alokasi']);
        }
    }
    return $hasil;
}

function teks_per_sumber(array $per_sumber): string
{
    $bagian = [];
    foreach ($per_sumber as $sumber => $jumlah) {
        if ($jumlah > 0) {
            $bagian[] = $sumber . ' ' . $jumlah;
        }
    }
    return implode(', ', $bagian);
}

function unit_belum_kembali(?int $proyek_id = null): array
{
    $hasil = [];
    foreach (semua_alokasi() as $alokasi) {
        if ($alokasi['sumber'] !== 'Studio' || !sedang_keluar($alokasi)) {
            continue;
        }
        if ($proyek_id !== null && (int) $alokasi['proyek_id'] !== $proyek_id) {
            continue;
        }
        $hasil[] = ['alokasi' => $alokasi, 'unit' => cari_perlengkapan((int) $alokasi['perlengkapan_id']), 'keluar' => serah_alokasi($alokasi['id'])['keluar']];
    }
    usort($hasil, fn ($a, $b) => strcmp($a['alokasi']['tgl_selesai'], $b['alokasi']['tgl_selesai']));
    return $hasil;
}

function barang_di_luar(?int $proyek_id = null): array
{
    // Sprint 3C: 'jumlah' = saldo yang masih di proyek (keluar - akumulasi kembali).
    $hasil = [];
    $transaksi = semua_transaksi();
    foreach (semua_alokasi() as $alokasi) {
        if (!sedang_keluar($alokasi) || ($proyek_id !== null && (int) $alokasi['proyek_id'] !== $proyek_id)) {
            continue;
        }
        $serah = serah_alokasi((int) $alokasi['id']);
        $keluar = $serah['jumlah_keluar'] ?? jumlah_alokasi($alokasi);
        $kembali = $serah['jumlah_kembali'] ?? 0;
        $hasil[] = [
            'alokasi' => $alokasi,
            'unit' => $alokasi['sumber'] === 'Studio' ? cari_perlengkapan((int) $alokasi['perlengkapan_id']) : null,
            'jumlah' => max(0, $keluar - $kembali),
            'jumlah_keluar' => $keluar,
            'jumlah_kembali' => $kembali,
            'status_kembali' => $serah['status_kembali'] ?? 'Belum Kembali',
            'kondisi_rincian' => $serah['kondisi_rincian'] ?? [],
            'kembali_terakhir' => $serah['kembali_terakhir'] ?? null,
            'trx_keluar' => $transaksi[$serah['trx_keluar']] ?? null,
            'keluar' => $serah['keluar'],
        ];
    }
    usort($hasil, fn ($a, $b) => strcmp($a['alokasi']['tgl_selesai'], $b['alokasi']['tgl_selesai']));
    return $hasil;
}

function ringkasan_barang_proyek(?int $proyek_id = null): array
{
    // Sprint 3D: KPI "barang di proyek" semua sumber, dari saldo transaksi (keluar - kembali), bukan banyaknya baris.
    // Sprint 3E final: tiga denominasi dipisah — 'unit' = kuantitas semua sumber, 'unit_studio' = unit fisik Studio
    // (alokasi Studio selalu 1 unit dan satu unit hanya bisa keluar di satu proyek), 'alokasi' = baris alokasi yang saldonya > 0.
    $luar = barang_di_luar($proyek_id);
    $pernah_keluar = false;
    if ($proyek_id !== null) {
        foreach (alokasi_proyek($proyek_id, true) as $a) {
            if (isset(serah_alokasi((int) $a['id'])['keluar'])) {
                $pernah_keluar = true;
                break;
            }
        }
    }
    $per_sumber = hitung_per_sumber($luar);
    return [
        'daftar' => $luar,
        'unit' => array_sum($per_sumber),
        'per_sumber' => $per_sumber,
        'unit_studio' => $per_sumber['Studio'],
        'alokasi' => count($luar),
        'pernah_keluar' => $pernah_keluar,
    ];
}

function teks_kondisi(array $rincian): string
{
    $bagian = [];
    foreach (daftar_kondisi() as $kondisi) {
        if (!empty($rincian[$kondisi])) {
            $bagian[] = $rincian[$kondisi] . ' ' . $kondisi;
        }
    }
    return implode(', ', $bagian);
}

function kelompok_per_proyek(array $daftar): array
{
    $hasil = [];
    foreach ($daftar as $baris) {
        $id = (int) $baris['alokasi']['proyek_id'];
        $hasil[$id] = $hasil[$id] ?? ['proyek' => cari_proyek($id), 'unit' => []];
        $hasil[$id]['unit'][] = $baris;
    }
    return $hasil;
}

function proyek_siap_keluar(): array
{
    $hasil = [];
    foreach (urutkan_proyek(array_values(semua_proyek())) as $proyek) {
        if (!proyek_aktif($proyek)) {
            continue;
        }
        $unit = alokasi_siap_keluar((int) $proyek['id']);
        if (!$unit) {
            continue;
        }
        $gerbang = gerbang_keluar($proyek);
        $bisa = $gerbang['boleh'] ? array_values(array_filter($unit, fn ($u) => $u['blokir'] === '')) : [];
        $hasil[$proyek['id']] = [
            'proyek' => $proyek,
            'unit' => $unit,
            'gerbang' => $gerbang,
            'bisa_alokasi' => count($bisa),
            'bisa_unit' => array_sum(array_column($bisa, 'jumlah')),
        ];
    }
    return $hasil;
}

function penerima_proyek(int $proyek_id): array
{
    $hasil = [];
    foreach (anggota_proyek($proyek_id) as $anggota) {
        if ($anggota['pengguna']['status'] === 'Aktif') {
            $hasil[(string) $anggota['pengguna']['id']] = $anggota['pengguna']['nama'] . ' · ' . $anggota['peran'];
        }
    }
    return $hasil;
}

// Barang keluar. HARUS dipanggil di dalam transaksi (lihat catat_barang_keluar()). $item: [['alokasi' => ..., 'jumlah' => n], ...].
// Semua syarat integritas dicek ulang SETELAH proyek, alokasi, dan unit dikunci, karena data di halaman bisa sudah berubah
// oleh browser lain. Hasil: ['status' => ok, ...] atau ['status' => 'ditolak', 'masalah' => [...]] tanpa ada yang ditulis.
function proses_checkout(array $proyek, array $item, array $penerima, string $waktu, string $catatan): array
{
    $pid = (int) $proyek['id'];
    $kunci = kunci_proyek($pid);
    if (!$kunci || !proyek_aktif($kunci)) {
        return ['status' => 'ditolak', 'masalah' => ['Proyek ' . $proyek['kode'] . ' sudah ditutup, barang tidak bisa dikeluarkan lagi.']];
    }
    if (!anggota_tim_db($pid, $kunci['pic_id'], (int) $penerima['id'], true)) {
        return ['status' => 'ditolak', 'masalah' => [$penerima['nama'] . ' bukan anggota tim proyek yang aktif.']];
    }
    $minta = [];
    foreach ($item as $baris) {
        $minta[(int) $baris['alokasi']['id']] = (int) $baris['jumlah'];
    }
    $alokasi = kunci_alokasi_proyek($pid, array_keys($minta));
    // Gerbang persiapan (Sprint 4B) dinilai ulang dari PostgreSQL di dalam kunci proyek: konfirmasi persiapan harus ada dan
    // syaratnya masih terpenuhi. Konfirmasi dan pembukaan ulang persiapan juga mengunci proyek lebih dulu, jadi status ini
    // tidak bisa berubah sampai COMMIT. Tidak bergantung pada session browser yang mencatat.
    segarkan_data_persiapan();
    $gerbang = gerbang_keluar($kunci);
    if (!$gerbang['boleh']) {
        return ['status' => 'ditolak', 'masalah' => [$gerbang['alasan']]];
    }
    $saldo = saldo_alokasi_db(array_keys($minta));
    $masalah = [];
    foreach ($minta as $id => $jumlah) {
        $a = $alokasi[$id] ?? null;
        if (!$a) {
            $masalah[] = 'Alokasi #' . $id . ' tidak ada lagi di proyek ' . $proyek['kode'] . '.';
            continue;
        }
        if (!$a['diperiksa']) {
            $masalah[] = nama_barang_alokasi($a) . ' (' . $a['sumber'] . '): belum diperiksa di persiapan.';
            continue;
        }
        $nama = nama_barang_alokasi($a) . ' (' . $a['sumber'] . ')';
        if ($a['status'] !== 'Dikonfirmasi') {
            $masalah[] = $nama . ': alokasi ' . ($a['status'] === 'Dibatalkan' ? 'sudah dibatalkan' : 'belum dikonfirmasi') . '.';
        } elseif (isset($saldo[$id])) {
            $masalah[] = $nama . ': sudah tercatat keluar (' . (cari_transaksi($saldo[$id]['trx_keluar'])['kode'] ?? 'transaksi lain') . '), tidak dicatat dua kali.';
        } elseif ($jumlah !== jumlah_alokasi($a)) {
            $masalah[] = $nama . ': jumlah keluar harus ' . jumlah_alokasi($a) . ' unit sesuai alokasi.';
        } elseif ($a['sumber'] === 'Studio') {
            $unit = sql_satu('SELECT kode_unit, kondisi, status_aktif FROM perlengkapan WHERE id = :id FOR UPDATE', ['id' => $a['perlengkapan_id']]);
            $di_luar = $unit ? sql_satu('SELECT p.kode FROM v_saldo_alokasi v JOIN alokasi_barang x ON x.id = v.alokasi_id JOIN proyek p ON p.id = v.proyek_id
                                         WHERE x.perlengkapan_id = :u AND v.sisa > 0 LIMIT 1', ['u' => $a['perlengkapan_id']]) : null;
            if (!$unit) {
                $masalah[] = $nama . ': unit sudah tidak ada.';
            } elseif ($unit['status_aktif'] !== 'Aktif' || $unit['kondisi'] !== 'Baik') {
                $masalah[] = $nama . ': ' . $unit['kode_unit'] . ' sedang ' . ($unit['status_aktif'] !== 'Aktif' ? 'nonaktif' : 'berkondisi ' . $unit['kondisi']) . '.';
            } elseif ($di_luar) {
                $masalah[] = $nama . ': ' . $unit['kode_unit'] . ' masih keluar di ' . $di_luar['kode'] . '.';
            }
        } elseif ($a['sumber'] === 'Rental' && ($a['diterima_pada'] === null || $a['dikembalikan_vendor_pada'] !== null)) {
            $masalah[] = $nama . ': ' . ($a['diterima_pada'] === null ? 'belum tercatat diterima dari vendor' : 'sudah dikembalikan ke vendor') . '.';
        }
    }
    if ($masalah) {
        return ['status' => 'ditolak', 'masalah' => $masalah];
    }
    $kode = kode_transaksi_baru();
    $trx_id = tambah_transaksi([
        'kode' => $kode,
        'proyek_id' => $pid,
        'jenis' => 'Keluar',
        'waktu' => $waktu,
        'petugas_id' => (int) ($_SESSION['user_id'] ?? 0),
        'penerima' => $penerima['nama'],
        'penerima_id' => (int) $penerima['id'],
        'catatan' => $catatan,
    ]);
    $per_sumber = ['Studio' => 0, 'Rental' => 0, 'Milik Kru' => 0];
    foreach ($minta as $id => $jumlah) {
        tambah_detail_transaksi($trx_id, $id, 'Baik', $jumlah);
        $per_sumber[$alokasi[$id]['sumber']] += $jumlah;
    }
    $total = array_sum($per_sumber);
    catat_riwayat_proyek('Barang Keluar', $proyek['kode'] . ' · ' . $kode, $total . ' unit diserahkan ke ' . $penerima['nama'] . ' (' . teks_per_sumber($per_sumber) . ')');
    return ['status' => 'ok', 'kode' => $kode, 'total' => $total, 'per_sumber' => $per_sumber];
}

// Catat barang keluar dalam SATU transaksi PostgreSQL (kepala, rincian, riwayat).
function catat_barang_keluar(array $proyek, array $item, array $penerima, string $waktu, string $catatan): array
{
    $hasil = sql_transaksi(fn () => proses_checkout($proyek, $item, $penerima, $waktu, $catatan));
    if ($hasil['status'] === 'ok') {
        muat_ulang_serah();
    }
    return $hasil;
}

// Pengembalian. HARUS dipanggil di dalam transaksi (lihat catat_pengembalian()). $item: [['alokasi' => ..., 'per_kondisi' => [...]], ...].
// Saldo dihitung ulang dari v_saldo_alokasi setelah proyek dan alokasi dikunci, sehingga dua pengembalian bersamaan
// tidak bisa melebihi sisa. Unit Studio yang kembali Rusak/Maintenance diubah kondisinya di transaksi yang sama.
function proses_pengembalian(array $proyek, array $item, ?array $penyerah, string $waktu, string $catatan): array
{
    $pid = (int) $proyek['id'];
    $kunci = kunci_proyek($pid);
    if (!$kunci) {
        return ['status' => 'ditolak', 'masalah' => ['Proyek tidak ditemukan.']];
    }
    if ($penyerah && !anggota_tim_db($pid, $kunci['pic_id'], (int) $penyerah['id'], false)) {
        return ['status' => 'ditolak', 'masalah' => [$penyerah['nama'] . ' bukan anggota tim proyek ini.']];
    }
    $minta = [];
    foreach ($item as $baris) {
        $minta[(int) $baris['alokasi']['id']] = $baris['per_kondisi'];
    }
    $alokasi = kunci_alokasi_proyek($pid, array_keys($minta));
    $saldo = saldo_alokasi_db(array_keys($minta));
    $masalah = [];
    foreach ($minta as $id => $per) {
        $a = $alokasi[$id] ?? null;
        $s = $saldo[$id] ?? null;
        $total = array_sum($per);
        if (!$a) {
            $masalah[] = 'Alokasi #' . $id . ' tidak ada lagi di proyek ' . $proyek['kode'] . '.';
            continue;
        }
        $nama = nama_barang_alokasi($a) . ' (' . $a['sumber'] . ')';
        if (!$s) {
            $masalah[] = $nama . ': belum pernah tercatat keluar, jadi tidak bisa dikembalikan.';
        } elseif ($s['sisa'] < 1) {
            $masalah[] = $nama . ': seluruh unit sudah tercatat kembali.';
        } elseif ($total < 1 || $total > $s['sisa'] || ($a['sumber'] === 'Studio' && $total !== 1)) {
            $masalah[] = $nama . ': jumlah ' . $total . ' tidak sesuai saldo di proyek (' . $s['sisa'] . ' unit). Pengembalian tidak dicatat dua kali; muat ulang lalu periksa kembali.';
        } elseif ($waktu < max($s['waktu_keluar'], (string) $s['kembali_terakhir'])) {
            $masalah[] = $nama . ': waktu pengembalian tidak boleh sebelum ' . tgl(max($s['waktu_keluar'], (string) $s['kembali_terakhir']), 'lengkap') . '.';
        }
    }
    if ($masalah) {
        return ['status' => 'ditolak', 'masalah' => $masalah];
    }
    $kode = kode_transaksi_baru();
    $trx_id = tambah_transaksi([
        'kode' => $kode,
        'proyek_id' => $pid,
        'jenis' => 'Kembali',
        'waktu' => $waktu,
        'petugas_id' => (int) ($_SESSION['user_id'] ?? 0),
        'penerima' => nama_pengguna($_SESSION['user_id'] ?? null),
        'penyerah' => $penyerah['nama'] ?? '',
        'penyerah_id' => $penyerah ? (int) $penyerah['id'] : null,
        'catatan' => $catatan,
    ]);
    $per_sumber = ['Studio' => 0, 'Rental' => 0, 'Milik Kru' => 0];
    $per_kondisi = ['Baik' => 0, 'Maintenance' => 0, 'Rusak' => 0];
    $bermasalah = [];
    $eksternal_bermasalah = [];
    $insiden = [];
    foreach ($minta as $id => $per) {
        $alokasi_baris = $alokasi[$id];
        foreach ($per as $kondisi => $jumlah) {
            if ($jumlah < 1) {
                continue;
            }
            $detail_id = tambah_detail_transaksi($trx_id, $id, $kondisi, $jumlah);
            $per_sumber[$alokasi_baris['sumber']] += $jumlah;
            $per_kondisi[$kondisi] += $jumlah;
            if ($kondisi === 'Baik') {
                continue;
            }
            if ($alokasi_baris['sumber'] === 'Studio') {
                // Kondisi unit diubah di transaksi yang sama (kunci baris unit). Catatan insiden masih di session; bila
                // transaksi gagal, session dikembalikan oleh proses_session_dan_inventaris().
                $unit_db = sql_satu('SELECT kondisi FROM perlengkapan WHERE id = :id FOR UPDATE', ['id' => $alokasi_baris['perlengkapan_id']]);
                if ($unit_db && $unit_db['kondisi'] !== $kondisi) {
                    ubah_kondisi_unit((int) $alokasi_baris['perlengkapan_id'], $kondisi);
                }
                $unit = cari_perlengkapan((int) $alokasi_baris['perlengkapan_id']);
                $deskripsi = ($catatan !== '' ? $catatan . ' · ' : '') . 'Dilaporkan saat pengembalian ' . $kode . ' (barang keluar ' . (cari_transaksi($saldo[$id]['trx_keluar'])['kode'] ?? '?') . ').';
                $kode_ins = lapor_insiden((int) $alokasi_baris['perlengkapan_id'], $pid, $kondisi === 'Rusak' ? 'Rusak' : 'Masalah Teknis', $deskripsi, $kondisi);
                foreach (db('insiden') as $ins) {
                    if ($ins['kode'] === $kode_ins) {
                        db_ubah('insiden', (int) $ins['id'], ['transaksi_id' => $trx_id, 'alokasi_id' => $id, 'detail_id' => $detail_id]);
                    }
                }
                $bermasalah[] = $unit['kode_unit'] ?? '?';
                $insiden[] = $kode_ins;
            } else {
                $eksternal_bermasalah[] = $alokasi_baris['nama_barang'] . ' ' . $jumlah . ' ' . $kondisi;
            }
        }
    }
    $total = array_sum($per_sumber);
    // Sprint 3E final: tidak ada penilaian terlambat/tepat waktu berdasarkan jadwal event (jadwal bersifat dinamis).
    // Yang dicatat hanya fakta: waktu pengembalian dan saldo yang masih di proyek setelah transaksi ini (dibaca di transaksi
    // yang sama, jadi sudah termasuk pengembalian ini).
    $sisa_proyek = (int) sql_satu('SELECT COALESCE(SUM(sisa), 0) AS n FROM v_saldo_alokasi WHERE proyek_id = :p', ['p' => $pid])['n'];
    $ket = $total . ' unit kembali (' . teks_per_sumber($per_sumber) . ') · kondisi ' . teks_kondisi($per_kondisi);
    $ket .= ' · waktu kembali ' . tgl($waktu, 'lengkap');
    $ket .= $sisa_proyek > 0 ? ' · sisa di proyek ' . $sisa_proyek . ' unit' : ' · seluruh barang proyek sudah kembali';
    $ket .= $penyerah ? ' · diserahkan ' . $penyerah['nama'] : '';
    $ket .= $insiden ? ' · insiden ' . implode(', ', $insiden) : '';
    $ket .= $eksternal_bermasalah ? ' · eksternal bermasalah: ' . implode(', ', $eksternal_bermasalah) : '';
    catat_riwayat_proyek('Pengembalian', $proyek['kode'] . ' · ' . $kode, $ket);
    return ['status' => 'ok', 'kode' => $kode, 'total' => $total, 'per_sumber' => $per_sumber, 'per_kondisi' => $per_kondisi, 'sisa_proyek' => $sisa_proyek, 'bermasalah' => $bermasalah, 'eksternal_bermasalah' => $eksternal_bermasalah, 'insiden' => $insiden];
}

// Catat pengembalian dalam SATU transaksi PostgreSQL (kepala, rincian, kondisi unit, riwayat). Catatan insiden (masih
// di session) ikut dikembalikan bila transaksi gagal.
function catat_pengembalian(array $proyek, array $item, ?array $penyerah, string $waktu, string $catatan): array
{
    $hasil = proses_session_dan_inventaris(fn () => proses_pengembalian($proyek, $item, $penyerah, $waktu, $catatan));
    if ($hasil['status'] === 'ok') {
        muat_ulang_serah();
    }
    return $hasil;
}

function daftar_transaksi(?int $proyek_id = null): array
{
    $detail = [];
    foreach (semua_detail_transaksi() as $baris) {
        $detail[$baris['transaksi_id']][] = $baris;
    }
    $hasil = [];
    foreach (semua_transaksi() as $trx) {
        if ($proyek_id !== null && (int) $trx['proyek_id'] !== $proyek_id) {
            continue;
        }
        $trx['detail'] = $detail[$trx['id']] ?? [];
        $trx['proyek'] = cari_proyek($trx['proyek_id']);
        $hasil[] = $trx;
    }
    usort($hasil, fn ($a, $b) => strcmp($b['waktu'], $a['waktu']));
    return $hasil;
}

function tren_mingguan(int $minggu = 6): array
{
    $senin = date('Y-m-d', strtotime('monday this week', strtotime(HARI_INI)));
    $hasil = [];
    for ($i = $minggu - 1; $i >= 0; $i--) {
        $awal = date('Y-m-d', strtotime($senin . ' -' . ($i * 7) . ' days'));
        $akhir = date('Y-m-d', strtotime($awal . ' +6 days'));
        $hasil[$awal] = ['label' => tgl($awal, 'singkat'), 'akhir' => $akhir, 'Keluar' => 0, 'Kembali' => 0];
    }
    $transaksi = semua_transaksi();
    foreach (semua_detail_transaksi() as $detail) {
        $trx = $transaksi[$detail['transaksi_id']] ?? null;
        if (!$trx) {
            continue;
        }
        $tanggal = substr($trx['waktu'], 0, 10);
        foreach ($hasil as $awal => $baris) {
            if ($tanggal >= $awal && $tanggal <= $baris['akhir']) {
                $hasil[$awal][$trx['jenis']] += jumlah_detail($detail);
                break;
            }
        }
    }
    return $hasil;
}
