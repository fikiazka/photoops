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
    if ($batal) {
        $status = '';
        $ket = 'Alokasi dibatalkan';
    } elseif ($dikembalikan) {
        $status = 'Sudah Dikembalikan';
        $ket = 'Diserahkan ke vendor ' . tgl($dikembalikan, 'singkat_jam');
    } elseif (!$jatuh_tempo) {
        $status = 'Belum Dicatat';
        $ket = 'Jatuh tempo vendor belum diatur';
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
        'bisa_kembali' => !$batal && $diterima !== null && $dikembalikan === null,
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
