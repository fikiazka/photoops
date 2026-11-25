<?php
defined('ROOT') || exit;

function daftar_status_proyek(): array
{
    return ['Draf', 'Dijadwalkan', 'Berlangsung', 'Selesai', 'Dibatalkan'];
}

function daftar_sumber(): array
{
    return ['Studio', 'Rental', 'Milik Kru'];
}

function proyek_aktif(array $proyek): bool
{
    return !in_array($proyek['status'], ['Selesai', 'Dibatalkan'], true);
}

// ---------------------------------------------------------------------------------------------
// Proyek dan anggota proyek di PostgreSQL (tabel proyek, anggota_proyek, persiapan_proyek).
//   - PIC disimpan di proyek.pic_id; anggota_proyek hanya berisi kru (PIC tidak dicatat di sana).
//   - Modul lain membaca proyek lewat semua_proyek() / cari_proyek(); kebutuhan dan alokasi ada di data/logic_alokasi.php.
//   - Waktu dibaca dengan TO_CHAR berformat 'YYYY-MM-DD HH24:MI' (dipakai tgl(), waktu_input(), substr(..., 0, 10),
//     dan perbandingan dengan sekarang()).
// ---------------------------------------------------------------------------------------------

const KOLOM_PROYEK = "id, kode, nama, klien, lokasi,
    TO_CHAR(mulai, 'YYYY-MM-DD HH24:MI') AS mulai,
    TO_CHAR(selesai, 'YYYY-MM-DD HH24:MI') AS selesai,
    TO_CHAR(deadline_persiapan, 'YYYY-MM-DD HH24:MI') AS deadline_persiapan,
    status, pic_id, catatan, dibuat_oleh";

function proyek_dari_baris(array $baris): array
{
    foreach (['id', 'pic_id', 'dibuat_oleh'] as $kolom) {
        $baris[$kolom] = (int) $baris[$kolom];
    }
    return $baris;
}

// Semua proyek, ber-key id. Dibaca sekali per request; $muat_ulang = true sesudah proyek diubah.
function semua_proyek(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT ' . KOLOM_PROYEK . ' FROM proyek ORDER BY id') as $baris) {
            $baris = proyek_dari_baris($baris);
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

function cari_proyek($id): ?array
{
    return $id ? (semua_proyek()[(int) $id] ?? null) : null;
}

// Semua baris kru (anggota_proyek), ber-key id.
function semua_anggota(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT id, proyek_id, pengguna_id FROM anggota_proyek ORDER BY id') as $baris) {
            $baris = array_map('intval', $baris);
            $daftar[$baris['id']] = $baris;
        }
    }
    return $daftar;
}

// Penanda bahwa proyek/tim berubah di PostgreSQL pada request ini. Bootstrap memakainya untuk menjalankan
// sinkron_konfirmasi_persiapan() sesudah POST (konfirmasi persiapan yang syaratnya tidak lagi terpenuhi dicabut).
function proyek_berubah(?bool $atur = null): bool
{
    static $berubah = false;
    if ($atur !== null) {
        $berubah = $atur;
    }
    return $berubah;
}

function muat_ulang_proyek(): void
{
    semua_proyek(true);
    semua_anggota(true);
    proyek_berubah(true);
}

// Proyek yang diikuti pengguna, sebagai PIC (proyek.pic_id) atau kru (anggota_proyek).
function id_proyek_pengguna(int $pengguna_id): array
{
    $hasil = [];
    foreach (semua_proyek() as $proyek) {
        if ($proyek['pic_id'] === $pengguna_id) {
            $hasil[] = $proyek['id'];
        }
    }
    foreach (semua_anggota() as $anggota) {
        if ($anggota['pengguna_id'] === $pengguna_id) {
            $hasil[] = $anggota['proyek_id'];
        }
    }
    return array_values(array_unique($hasil));
}

function boleh_lihat_proyek(array $proyek, array $pengguna): bool
{
    return boleh('proyek.semua') || in_array((int) $proyek['id'], id_proyek_pengguna($pengguna['id']), true);
}

function urutkan_proyek(array $daftar): array
{
    usort($daftar, function ($a, $b) {
        $aktif_a = proyek_aktif($a);
        $aktif_b = proyek_aktif($b);
        if ($aktif_a !== $aktif_b) {
            return $aktif_a ? -1 : 1;
        }
        return $aktif_a ? strcmp($a['mulai'], $b['mulai']) : strcmp($b['mulai'], $a['mulai']);
    });
    return $daftar;
}

function proyek_terlihat(array $pengguna): array
{
    $daftar = array_values(semua_proyek());
    if (!boleh('proyek.semua')) {
        $milik = id_proyek_pengguna($pengguna['id']);
        $daftar = array_values(array_filter($daftar, fn ($p) => in_array((int) $p['id'], $milik, true)));
    }
    return urutkan_proyek($daftar);
}

// 'PIC' bila pengguna = proyek.pic_id, 'Kru' bila tercatat di anggota_proyek, '' bila bukan anggota.
function peran_di_proyek(int $proyek_id, int $pengguna_id): string
{
    $proyek = cari_proyek($proyek_id);
    if ($proyek && $proyek['pic_id'] === $pengguna_id) {
        return 'PIC';
    }
    foreach (semua_anggota() as $anggota) {
        if ($anggota['proyek_id'] === $proyek_id && $anggota['pengguna_id'] === $pengguna_id) {
            return 'Kru';
        }
    }
    return '';
}

// Daftar tim untuk tampilan: PIC (dari proyek.pic_id, baris dengan id 0 karena tidak ada di anggota_proyek) lalu kru.
// Bentuk baris sama dengan data session lama: id, proyek_id, pengguna_id, peran, pengguna.
function anggota_proyek(int $proyek_id): array
{
    $proyek = cari_proyek($proyek_id);
    if (!$proyek) {
        return [];
    }
    $baris = [['id' => 0, 'proyek_id' => $proyek_id, 'pengguna_id' => $proyek['pic_id'], 'peran' => 'PIC']];
    foreach (semua_anggota() as $anggota) {
        if ($anggota['proyek_id'] === $proyek_id && $anggota['pengguna_id'] !== $proyek['pic_id']) {
            $baris[] = $anggota + ['peran' => 'Kru'];
        }
    }
    $hasil = [];
    foreach ($baris as $anggota) {
        $anggota['pengguna'] = cari_pengguna($anggota['pengguna_id']);
        if ($anggota['pengguna']) {
            $hasil[] = $anggota;
        }
    }
    usort($hasil, fn ($a, $b) => ($a['peran'] === 'PIC' ? 0 : 1) <=> ($b['peran'] === 'PIC' ? 0 : 1) ?: strcmp($a['pengguna']['nama'], $b['pengguna']['nama']));
    return $hasil;
}

// PIC dan kru baru harus User/Kru (peran us) yang Aktif. Dipanggil di dalam transaksi: FOR SHARE menahan baris akun
// sampai COMMIT, sehingga akun tidak bisa dinonaktifkan/diubah perannya di tengah penugasan. Hasil: nama, atau null.
function kunci_pengguna_kru(int $pengguna_id): ?string
{
    $baris = sql_satu("SELECT nama FROM pengguna WHERE id = :id AND peran = 'us' AND status = 'Aktif' FOR SHARE", ['id' => $pengguna_id]);
    return $baris['nama'] ?? null;
}

// Proyek baru: kode dari sequence seq_kode_proyek, baris persiapan_proyek (relasi 1:1), dan riwayat dalam SATU transaksi.
// Hasil: ['id' => ..., 'kode' => ...], atau null bila PIC tidak sah (tidak ada yang disimpan).
function buat_proyek(array $isi, int $pembuat_id): ?array
{
    $hasil = sql_transaksi(function () use ($isi, $pembuat_id) {
        if (kunci_pengguna_kru($isi['pic_id']) === null) {
            return null;
        }
        $nomor = (int) sql_satu("SELECT nextval('seq_kode_proyek') AS nomor")['nomor'];
        $kode = 'PRJ-' . str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);
        $id = (int) sql_satu('INSERT INTO proyek (kode, nama, klien, lokasi, mulai, selesai, deadline_persiapan, status, pic_id, catatan, dibuat_oleh)
                              VALUES (:kode, :nama, :klien, :lokasi, :mulai, :selesai, :deadline_persiapan, :status, :pic_id, :catatan, :dibuat_oleh)
                              RETURNING id', ['kode' => $kode, 'dibuat_oleh' => $pembuat_id] + $isi)['id'];
        sql_jalankan('INSERT INTO persiapan_proyek (proyek_id) VALUES (:id)', ['id' => $id]);
        catat_riwayat_proyek('Proyek', $kode . ' · ' . $isi['nama'], 'Proyek baru dibuat');
        return ['id' => $id, 'kode' => $kode];
    });
    if ($hasil) {
        muat_ulang_proyek();
    }
    return $hasil;
}

// Ubah proyek. SELECT ... FOR UPDATE mengunci baris proyek sehingga perubahan tim, PIC, kebutuhan, dan alokasi pada proyek
// yang sama berjalan bergantian. Bila PIC diganti: PIC lama menjadi kru, baris kru milik PIC baru dihapus (PIC tidak dicatat ganda).
// Bila jadwal berubah, rentang alokasi aktif ikut digeser dan dicek bentrok dalam transaksi yang sama (sinkron_jadwal_alokasi);
// bila ditolak, tidak ada yang disimpan.
// Proyek Selesai/Dibatalkan hanya bisa dibuka kembali (status -> aktif, PIC boleh diganti bila PIC lama tidak aktif lagi);
// data lain baru bisa diubah sesudah proyek aktif. Saat dibuka kembali, unit Studio-nya dicek bentrok ulang.
// Hasil: ['status' => ok | tidak_ada | pic_tidak_sah | proyek_tutup | jadwal_terkunci | bentrok, 'lama' => data proyek sebelum diubah, 'jadwal' => ...].
function ubah_proyek(int $id, array $isi): array
{
    $hasil = sql_transaksi(function () use ($id, $isi) {
        $lama = sql_satu('SELECT ' . KOLOM_PROYEK . ' FROM proyek WHERE id = :id FOR UPDATE', ['id' => $id]);
        if (!$lama) {
            return ['status' => 'tidak_ada', 'lama' => null];
        }
        $lama = proyek_dari_baris($lama);
        if (!proyek_aktif($lama)) {
            $berubah = array_filter(['nama', 'klien', 'lokasi', 'mulai', 'selesai', 'deadline_persiapan', 'catatan'], fn ($k) => (string) $lama[$k] !== (string) $isi[$k]);
            if (!proyek_aktif($isi) || $berubah) {
                return ['status' => 'proyek_tutup', 'lama' => $lama];
            }
        }
        $pic_baru = kunci_pengguna_kru($isi['pic_id']);
        if ($pic_baru === null) {
            return ['status' => 'pic_tidak_sah', 'lama' => $lama];
        }
        $jadwal = sinkron_jadwal_alokasi($id, $lama, $isi);
        if ($jadwal['status'] !== 'ok') {
            return ['status' => $jadwal['status'], 'lama' => $lama, 'jadwal' => $jadwal];
        }
        sql_jalankan('UPDATE proyek
                      SET nama = :nama, klien = :klien, lokasi = :lokasi, mulai = :mulai, selesai = :selesai,
                          deadline_persiapan = :deadline_persiapan, status = :status, pic_id = :pic_id, catatan = :catatan
                      WHERE id = :id', $isi + ['id' => $id]);
        $label = $lama['kode'] . ' · ' . $isi['nama'];
        if ($lama['pic_id'] !== $isi['pic_id']) {
            sql_jalankan('INSERT INTO anggota_proyek (proyek_id, pengguna_id) VALUES (:proyek_id, :pengguna_id)
                          ON CONFLICT (proyek_id, pengguna_id) DO NOTHING', ['proyek_id' => $id, 'pengguna_id' => $lama['pic_id']]);
            sql_jalankan('DELETE FROM anggota_proyek WHERE proyek_id = :proyek_id AND pengguna_id = :pengguna_id',
                ['proyek_id' => $id, 'pengguna_id' => $isi['pic_id']]);
            catat_riwayat_proyek('Tim', $lama['kode'], 'PIC diganti, PIC lama menjadi kru', nama_pengguna($lama['pic_id']), $pic_baru);
        }
        $ganti = $lama['status'] !== $isi['status'];
        catat_riwayat_proyek('Proyek', $label, $ganti ? 'Status proyek diubah' : 'Detail proyek diperbarui', $ganti ? $lama['status'] : '', $ganti ? $isi['status'] : '');
        if ($lama['deadline_persiapan'] !== $isi['deadline_persiapan']) {
            // Dicatat eksplisit (lama → baru, format Y-m-d H:i): konfirmasi persiapan lama tanpa snapshot deadline
            // tetap dinilai terhadap deadline yang berlaku saat itu (lihat deadline_saat_konfirmasi()).
            catat_riwayat_proyek('Proyek', $label, 'Deadline persiapan diubah', $lama['deadline_persiapan'], $isi['deadline_persiapan']);
        }
        if ($lama['mulai'] !== $isi['mulai'] || $lama['selesai'] !== $isi['selesai']) {
            catat_riwayat_proyek('Proyek', $label, 'Jadwal acara diubah', $lama['mulai'] . ' s.d. ' . $lama['selesai'], $isi['mulai'] . ' s.d. ' . $isi['selesai']);
        }
        if ($jadwal['jumlah'] > 0) {
            catat_riwayat_proyek('Alokasi', $lama['kode'], 'Jadwal ' . $jadwal['jumlah'] . ' alokasi disesuaikan dengan jadwal proyek',
                implode(' s.d. ', $jadwal['rentang_lama']), implode(' s.d. ', $jadwal['rentang_baru']));
        }
        return ['status' => 'ok', 'lama' => $lama, 'jadwal' => $jadwal];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_proyek();
        if ($hasil['jadwal']['jumlah'] > 0) {
            muat_ulang_alokasi();
        }
    }
    return $hasil;
}

// Tambah kru. Baris proyek dikunci agar tidak bertabrakan dengan penggantian PIC; UNIQUE (proyek_id, pengguna_id)
// + ON CONFLICT DO NOTHING mencegah baris ganda walau dua permintaan datang bersamaan.
// Hasil: ok | tidak_ada | proyek_tutup | calon_tidak_sah | pic | sudah_ada.
function tambah_kru(int $proyek_id, int $pengguna_id): string
{
    $hasil = sql_transaksi(function () use ($proyek_id, $pengguna_id) {
        $proyek = sql_satu('SELECT kode, status, pic_id FROM proyek WHERE id = :id FOR UPDATE', ['id' => $proyek_id]);
        if (!$proyek) {
            return 'tidak_ada';
        }
        if (!proyek_aktif($proyek)) {
            return 'proyek_tutup';
        }
        $nama = kunci_pengguna_kru($pengguna_id);
        if ($nama === null) {
            return 'calon_tidak_sah';
        }
        if ((int) $proyek['pic_id'] === $pengguna_id) {
            return 'pic';
        }
        $baru = sql_satu('INSERT INTO anggota_proyek (proyek_id, pengguna_id) VALUES (:proyek_id, :pengguna_id)
                          ON CONFLICT (proyek_id, pengguna_id) DO NOTHING RETURNING id', ['proyek_id' => $proyek_id, 'pengguna_id' => $pengguna_id]);
        if (!$baru) {
            return 'sudah_ada';
        }
        catat_riwayat_proyek('Tim', $proyek['kode'], $nama . ' ditambahkan sebagai kru');
        return 'ok';
    });
    if ($hasil === 'ok') {
        muat_ulang_proyek();
    }
    return $hasil;
}

// Lepas kru (baris anggota_proyek). PIC tidak ada di tabel ini, jadi tidak bisa dilepas lewat fungsi ini.
// Kru yang masih tercatat membawa barang Milik Kru (alokasi selain Dibatalkan) di proyek ini tidak bisa dilepas; sejak
// Sprint Backend 3C alokasi ada di PostgreSQL, jadi pemeriksaan ini berlaku untuk semua browser.
// Hasil: ok | tidak_ada | proyek_tutup | bawa_barang.
function lepas_kru(int $proyek_id, int $anggota_id): string
{
    $hasil = sql_transaksi(function () use ($proyek_id, $anggota_id) {
        $proyek = sql_satu('SELECT kode, status FROM proyek WHERE id = :id FOR UPDATE', ['id' => $proyek_id]);
        if (!$proyek) {
            return 'tidak_ada';
        }
        if (!proyek_aktif($proyek)) {
            return 'proyek_tutup';
        }
        $kru = sql_satu('SELECT pengguna_id FROM anggota_proyek WHERE id = :id AND proyek_id = :proyek_id', ['id' => $anggota_id, 'proyek_id' => $proyek_id]);
        if (!$kru) {
            return 'tidak_ada';
        }
        if (sql_satu("SELECT 1 FROM alokasi_barang a JOIN kebutuhan_proyek k ON k.id = a.kebutuhan_id
                      WHERE k.proyek_id = :proyek_id AND a.sumber = 'Milik Kru' AND a.pemilik_id = :pengguna_id AND a.status <> 'Dibatalkan' LIMIT 1",
            ['proyek_id' => $proyek_id, 'pengguna_id' => $kru['pengguna_id']])) {
            return 'bawa_barang';
        }
        $hapus = sql_satu('DELETE FROM anggota_proyek WHERE id = :id AND proyek_id = :proyek_id RETURNING pengguna_id',
            ['id' => $anggota_id, 'proyek_id' => $proyek_id]);
        if (!$hapus) {
            return 'tidak_ada';
        }
        catat_riwayat_proyek('Tim', $proyek['kode'], nama_pengguna($hapus['pengguna_id']) . ' dilepas dari tim');
        return 'ok';
    });
    if ($hasil === 'ok') {
        muat_ulang_proyek();
    }
    return $hasil;
}

function kebutuhan_proyek(int $proyek_id): array
{
    $hasil = array_values(array_filter(semua_kebutuhan(), fn ($k) => $k['proyek_id'] === $proyek_id));
    usort($hasil, fn ($a, $b) => $a['kategori_id'] <=> $b['kategori_id'] ?: $a['id'] <=> $b['id']);
    return $hasil;
}

function wajib_proyek_aktif(array $proyek): void
{
    if (!proyek_aktif($proyek)) {
        flash('error', 'Proyek ' . $proyek['kode'] . ' berstatus ' . $proyek['status'] . '. Kebutuhan dan alokasinya tidak bisa diubah lagi.');
        muat_ulang();
    }
}

function validasi_kebutuhan(array $data, ?array $lama): array
{
    $galat = [];
    $panjang_nama = mb_strlen($data['nama']);
    if ($panjang_nama < 2) {
        $galat['nama'] = 'Nama kebutuhan wajib diisi.';
    } elseif ($panjang_nama > 60) {
        $galat['nama'] = 'Nama kebutuhan maksimal 60 karakter.';
    }
    if (!preg_match('/^\d{1,9}$/', $data['kategori_id']) || !cari_kategori((int) $data['kategori_id'])) {
        $galat['kategori_id'] = 'Pilih kategori.';
    } elseif ($lama && (int) $data['kategori_id'] !== (int) $lama['kategori_id']) {
        $aktif = count(array_filter(alokasi_kebutuhan($lama['id']), fn ($a) => $a['status'] !== 'Dibatalkan'));
        if (kebutuhan_punya_serah($lama['id'])) {
            $galat['kategori_id'] = 'Kategori tidak bisa diubah karena barang untuk kebutuhan ini sudah tercatat keluar di serah terima. Kategori dikunci agar alokasi dan riwayat transaksinya tetap sah.';
        } elseif ($aktif > 0) {
            $galat['kategori_id'] = 'Kategori tidak bisa diubah karena masih ada ' . $aktif . ' alokasi aktif. Batalkan atau hapus alokasi terkait terlebih dahulu.';
        }
    }
    if (!preg_match('/^\d{1,3}$/', $data['jumlah']) || (int) $data['jumlah'] < 1 || (int) $data['jumlah'] > 50) {
        $galat['jumlah'] = 'Jumlah harus bilangan bulat 1 sampai 50.';
    } elseif ($lama && (int) $data['jumlah'] < pemenuhan_kebutuhan($lama)['terisi']) {
        $galat['jumlah'] = 'Tidak bisa kurang dari ' . pemenuhan_kebutuhan($lama)['terisi'] . ' unit yang sudah ada di alokasi aktif. Batalkan atau hapus alokasinya dulu.';
    }
    if (mb_strlen($data['acuan']) > 60) {
        $galat['acuan'] = 'Model acuan maksimal 60 karakter.';
    }
    return $galat;
}

function kebutuhan_punya_serah(int $kebutuhan_id): bool
{
    foreach (alokasi_kebutuhan($kebutuhan_id) as $alokasi) {
        if (serah_alokasi($alokasi['id'])) {
            return true;
        }
    }
    return false;
}

function alokasi_proyek(int $proyek_id, bool $aktif_saja = false): array
{
    $hasil = [];
    foreach (semua_alokasi() as $alokasi) {
        if ($alokasi['proyek_id'] === $proyek_id && (!$aktif_saja || $alokasi['status'] !== 'Dibatalkan')) {
            $hasil[] = $alokasi;
        }
    }
    return $hasil;
}

function alokasi_kebutuhan(int $kebutuhan_id): array
{
    return array_values(array_filter(semua_alokasi(), fn ($a) => $a['kebutuhan_id'] === $kebutuhan_id));
}

function jumlah_alokasi(array $alokasi): int
{
    if ($alokasi['sumber'] === 'Studio') {
        return 1;
    }
    return max(1, (int) ($alokasi['jumlah'] ?? 1));
}

function alokasi_bisa_diperiksa(array $alokasi): bool
{
    return $alokasi['status'] === 'Dikonfirmasi' && ($alokasi['sumber'] !== 'Rental' || !empty($alokasi['diterima_pada']));
}

function alokasi_diperiksa(array $alokasi, array $diperiksa): bool
{
    return alokasi_bisa_diperiksa($alokasi) && in_array((int) $alokasi['id'], $diperiksa, true);
}

function alokasi_siap(array $alokasi, array $diperiksa): bool
{
    if (!alokasi_diperiksa($alokasi, $diperiksa) || serah_alokasi($alokasi['id'])) {
        return false;
    }
    $proyek = cari_proyek((int) $alokasi['proyek_id']);
    if (!$proyek || !proyek_aktif($proyek)) {
        return false;
    }
    if ($alokasi['sumber'] !== 'Studio') {
        return empty($alokasi['dikembalikan_vendor_pada']);
    }
    $unit = cari_perlengkapan((int) $alokasi['perlengkapan_id']);
    return $unit && $unit['status_aktif'] === 'Aktif' && $unit['kondisi'] === 'Baik' && !alokasi_keluar_unit($unit['id']);
}

function pemenuhan_kebutuhan(array $kebutuhan): array
{
    $diperiksa = id_diperiksa((int) $kebutuhan['proyek_id']);
    $proyek = cari_proyek((int) $kebutuhan['proyek_id']);
    $aktif = $proyek && proyek_aktif($proyek);
    $hasil = ['dialokasikan' => 0, 'direncanakan' => 0, 'dikonfirmasi' => 0, 'diperiksa' => 0, 'siap' => 0, 'siap_eksternal' => 0, 'eksternal' => 0,
        'eksternal_keluar' => 0, 'keluar' => 0, 'di_luar' => 0, 'kembali' => 0, 'kembali_bermasalah' => 0];
    foreach (alokasi_kebutuhan($kebutuhan['id']) as $alokasi) {
        if ($alokasi['status'] === 'Dibatalkan') {
            continue;
        }
        $qty = jumlah_alokasi($alokasi);
        $hasil['dialokasikan'] += $qty;
        if ($alokasi['sumber'] !== 'Studio') {
            $hasil['eksternal'] += $qty;
        }
        if ($alokasi['status'] !== 'Dikonfirmasi') {
            $hasil['direncanakan'] += $qty;
            continue;
        }
        $hasil['dikonfirmasi'] += $qty;
        if (alokasi_diperiksa($alokasi, $diperiksa)) {
            $hasil['diperiksa'] += $qty;
        }
        if (alokasi_siap($alokasi, $diperiksa)) {
            $hasil['siap'] += $qty;
            if ($alokasi['sumber'] !== 'Studio') {
                $hasil['siap_eksternal'] += $qty;
            }
        }
        $serah = serah_alokasi($alokasi['id']);
        if (isset($serah['keluar'])) {
            // Sprint 3C: hitung dari jumlah transaksi (keluar, akumulasi kembali, sisa), bukan dari banyaknya baris.
            $keluar_j = $serah['jumlah_keluar'] ?? $qty;
            $kembali_j = $serah['jumlah_kembali'] ?? 0;
            $sisa_j = $serah['sisa'] ?? max(0, $keluar_j - $kembali_j);
            $hasil['keluar'] += $keluar_j;
            if ($alokasi['sumber'] !== 'Studio') {
                $hasil['eksternal_keluar'] += $keluar_j;
            }
            $hasil['kembali'] += $kembali_j;
            $hasil['kembali_bermasalah'] += $kembali_j - ($serah['kondisi_rincian']['Baik'] ?? 0);
            if ($sisa_j > 0) {
                $hasil['di_luar'] += $sisa_j;
            }
        }
    }
    $jumlah = (int) $kebutuhan['jumlah'];
    if ($hasil['dialokasikan'] === 0) {
        $status = 'Belum Terpenuhi';
    } elseif ($hasil['dialokasikan'] < $jumlah) {
        $status = 'Sebagian';
    } elseif ($hasil['dikonfirmasi'] < $jumlah) {
        $status = 'Menunggu Konfirmasi';
    } elseif ($hasil['keluar'] > 0 || !$aktif) {
        $status = $hasil['diperiksa'] >= $jumlah ? 'Diperiksa' : 'Belum Diperiksa';
    } elseif ($hasil['siap'] >= $jumlah) {
        $status = $hasil['siap_eksternal'] > 0 ? 'Diperiksa Petugas' : 'Siap';
    } else {
        $status = 'Belum Diperiksa';
    }
    return $hasil + [
        'terisi' => $hasil['dialokasikan'],
        'jumlah' => $jumlah,
        'sisa' => max(0, $jumlah - $hasil['dialokasikan']),
        'status' => $status,
        'pergerakan' => status_pergerakan($hasil),
        'proyek_aktif' => $aktif,
    ];
}

function rincian_pemenuhan(array $p): string
{
    $teks = 'Dialokasikan ' . $p['dialokasikan'] . '/' . $p['jumlah'] . ' · direncanakan ' . $p['direncanakan'] . ' · dikonfirmasi ' . $p['dikonfirmasi'] . ' · diperiksa ' . $p['diperiksa'];
    if (!$p['proyek_aktif']) {
        $teks .= ' · proyek ditutup, kesiapan saat ini tidak dihitung';
    } else {
        $teks .= ' · siap ' . $p['siap'];
        if ($p['siap_eksternal'] > 0) {
            $teks .= ' (' . $p['siap_eksternal'] . ' Rental/Kru berdasarkan pemeriksaan petugas)';
        }
    }
    if ($p['keluar'] > 0) {
        $teks .= ' · unit keluar ' . $p['keluar'] . ': di luar ' . $p['di_luar'] . ', kembali ' . $p['kembali'];
        if ($p['kembali_bermasalah'] > 0) {
            $teks .= ' (' . $p['kembali_bermasalah'] . ' Rusak/Maintenance)';
        }
        if ($p['eksternal'] > $p['eksternal_keluar']) {
            $teks .= ' · ' . ($p['eksternal'] - $p['eksternal_keluar']) . ' unit Rental/Kru belum keluar';
        }
    }
    return $teks;
}

function status_pergerakan(array $hitung): string
{
    if ($hitung['keluar'] === 0) {
        return '';
    }
    if ($hitung['di_luar'] > 0) {
        // Sprint 3E final: berdasarkan saldo transaksi saja, tidak lagi membedakan "lewat jadwal event".
        return $hitung['kembali'] > 0 ? 'Pengembalian Sebagian' : 'Belum Kembali';
    }
    return $hitung['eksternal'] > 0 ? 'Studio Sudah Kembali' : 'Sudah Kembali';
}

function nama_barang_alokasi(array $alokasi): string
{
    if ($alokasi['sumber'] === 'Studio') {
        return cari_perlengkapan((int) $alokasi['perlengkapan_id'])['nama'] ?? 'Unit dihapus';
    }
    return $alokasi['nama_barang'];
}

function keterangan_sumber(array $alokasi): string
{
    if ($alokasi['sumber'] === 'Studio') {
        return cari_perlengkapan((int) $alokasi['perlengkapan_id'])['kode_unit'] ?? '—';
    }
    if ($alokasi['sumber'] === 'Rental') {
        return $alokasi['vendor'];
    }
    return 'Milik ' . nama_pengguna($alokasi['pemilik_id']);
}

function label_status_alokasi(array $alokasi): string
{
    if ($alokasi['sumber'] === 'Rental') {
        $peta = ['Direncanakan' => 'Menunggu vendor', 'Dikonfirmasi' => 'Disetujui vendor'];
        return $peta[$alokasi['status']] ?? $alokasi['status'];
    }
    return $alokasi['status'];
}

function persiapan_proyek(array $proyek): array
{
    $item = alokasi_proyek($proyek['id'], true);
    $rekam = rekam_persiapan((int) $proyek['id']);
    $tersimpan = array_map('intval', $rekam['diperiksa'] ?? []);
    $id_bisa = array_map(fn ($a) => (int) $a['id'], array_filter($item, 'alokasi_bisa_diperiksa'));
    $id_tunggu_vendor = array_map(fn ($a) => (int) $a['id'], array_filter($item, fn ($a) => $a['status'] === 'Dikonfirmasi' && !alokasi_bisa_diperiksa($a)));
    $diperiksa = array_values(array_intersect($tersimpan, $id_bisa));
    $total = count($item);
    $selesai = count($diperiksa);
    $menunggu = count(array_filter($item, fn ($a) => $a['status'] !== 'Dikonfirmasi'));
    $kurang = 0;
    foreach (kebutuhan_proyek((int) $proyek['id']) as $kebutuhan) {
        $kurang += pemenuhan_kebutuhan($kebutuhan)['sisa'];
    }
    if ($total === 0 || $selesai === 0) {
        $status = 'Belum Dimulai';
    } elseif ($selesai < $total || $kurang > 0) {
        $status = 'Dalam Persiapan';
    } else {
        $status = 'Siap';
    }
    $lewat = proyek_aktif($proyek) && ($total > 0 || $kurang > 0) && $status !== 'Siap' && sekarang() > $proyek['deadline_persiapan'];
    return [
        'item' => $item,
        'diperiksa' => $diperiksa,
        'total' => $total,
        'selesai' => $selesai,
        'persen' => persen($selesai, $total),
        'status' => $status,
        'menunggu' => $menunggu,
        'menunggu_vendor' => count($id_tunggu_vendor),
        'centang_tertahan' => array_values(array_intersect($tersimpan, $id_tunggu_vendor)),
        'kurang' => $kurang,
        'lewat' => $lewat,
        'diperbarui_pada' => $rekam['diperbarui_pada'] ?? null,
        'diperbarui_oleh' => $rekam['diperbarui_oleh'] ?? null,
    ];
}

function alokasi_sesuai_kebutuhan(array $alokasi, ?array $kebutuhan, int $proyek_id): bool
{
    if (!$kebutuhan || (int) $kebutuhan['proyek_id'] !== $proyek_id || !in_array($alokasi['sumber'], daftar_sumber(), true)) {
        return false;
    }
    if ($alokasi['sumber'] === 'Studio') {
        $unit = cari_perlengkapan((int) $alokasi['perlengkapan_id']);
        return !$unit || (int) $unit['kategori_id'] === (int) $kebutuhan['kategori_id'];
    }
    if (trim((string) $alokasi['nama_barang']) === '') {
        return false;
    }
    if ($alokasi['sumber'] === 'Rental') {
        return trim((string) $alokasi['vendor']) !== '';
    }
    return peran_di_proyek($proyek_id, (int) $alokasi['pemilik_id']) !== '';
}

function syarat_persiapan(array $proyek): array
{
    $pid = (int) $proyek['id'];
    $diperiksa = id_diperiksa($pid);
    $h = ['kebutuhan' => 0, 'unit_butuh' => 0, 'kurang' => 0, 'lebih' => 0, 'alokasi' => 0, 'memenuhi' => 0, 'sudah_keluar' => 0,
        'tidak_sesuai' => 0, 'belum_konfirmasi' => 0, 'rental_belum_terima' => 0, 'rental_dikembalikan' => 0,
        'unit_tidak_layak' => 0, 'unit_bentrok' => 0, 'belum_diperiksa' => 0,
        'unit_sumber' => ['Studio' => 0, 'Rental' => 0, 'Milik Kru' => 0]];
    $kebutuhan = [];
    $kebutuhan_masalah = [];
    foreach (kebutuhan_proyek($pid) as $k) {
        $kebutuhan[(int) $k['id']] = $k;
        $p = pemenuhan_kebutuhan($k);
        $h['kebutuhan']++;
        $h['unit_butuh'] += $p['jumlah'];
        $h['kurang'] += $p['sisa'];
        $h['lebih'] += max(0, $p['dialokasikan'] - $p['jumlah']);
        if ($p['sisa'] > 0 || $p['dialokasikan'] > $p['jumlah']) {
            $kebutuhan_masalah[(int) $k['id']] = true;
        }
    }
    $alokasi_masalah = 0;
    $id_masalah = [];
    foreach (alokasi_proyek($pid, true) as $a) {
        $h['alokasi']++;
        $k = $kebutuhan[(int) $a['kebutuhan_id']] ?? null;
        $keluar = isset(serah_alokasi($a['id'])['keluar']);
        if ($keluar) {
            $h['sudah_keluar']++;
        }
        $unit = $a['sumber'] === 'Studio' ? cari_perlengkapan((int) $a['perlengkapan_id']) : null;
        $lain = $unit && !$keluar ? alokasi_keluar_unit((int) $unit['id']) : null;
        $masalah = '';
        if (!alokasi_sesuai_kebutuhan($a, $k, $pid)) {
            $masalah = 'tidak_sesuai';
        } elseif ($a['status'] !== 'Dikonfirmasi') {
            $masalah = 'belum_konfirmasi';
        } elseif ($a['sumber'] === 'Rental' && empty($a['diterima_pada'])) {
            $masalah = 'rental_belum_terima';
        } elseif ($a['sumber'] === 'Rental' && !empty($a['dikembalikan_vendor_pada'])) {
            $masalah = 'rental_dikembalikan';
        } elseif ($a['sumber'] === 'Studio' && !$keluar && (!$unit || $unit['status_aktif'] !== 'Aktif' || $unit['kondisi'] !== 'Baik')) {
            $masalah = 'unit_tidak_layak';
        } elseif ($lain && (int) $lain['id'] !== (int) $a['id']) {
            $masalah = 'unit_bentrok';
        } elseif (!in_array((int) $a['id'], $diperiksa, true)) {
            $masalah = 'belum_diperiksa';
        }
        if ($masalah !== '') {
            $h[$masalah]++;
            $alokasi_masalah++;
            $id_masalah[$masalah][] = (int) $a['id'];
            if ($k) {
                $kebutuhan_masalah[(int) $k['id']] = true;
            }
            continue;
        }
        $h['memenuhi']++;
        $h['unit_sumber'][$a['sumber']] += jumlah_alokasi($a);
    }
    $pesan = [];
    if (!proyek_aktif($proyek)) {
        $pesan['proyek_tutup'] = 'Proyek berstatus ' . $proyek['status'] . ', jadi persiapannya tidak bisa dikonfirmasi lagi.';
    }
    if ($h['kebutuhan'] === 0) {
        $pesan['tanpa_kebutuhan'] = 'Proyek belum punya kebutuhan perlengkapan.';
    }
    $teks = [
        'kurang' => 'Masih ada %d unit perlengkapan yang belum dialokasikan.',
        'lebih' => 'Ada %d unit alokasi yang melebihi jumlah kebutuhan.',
        'tidak_sesuai' => 'Ada %d alokasi yang sumber atau kategorinya tidak sesuai dengan kebutuhan.',
        'belum_konfirmasi' => 'Masih ada %d alokasi yang belum dikonfirmasi.',
        'rental_belum_terima' => 'Ada %d barang rental yang belum tercatat diterima dari vendor.',
        'rental_dikembalikan' => 'Ada %d barang rental yang sudah dikembalikan ke vendor, jadi tidak lagi tersedia untuk proyek ini.',
        'unit_tidak_layak' => 'Ada %d unit studio yang tidak layak pakai (rusak, maintenance, nonaktif, atau dihapus).',
        'unit_bentrok' => 'Ada %d unit studio yang masih keluar di proyek lain.',
        'belum_diperiksa' => 'Masih ada %d perlengkapan yang belum diperiksa.',
    ];
    foreach ($teks as $kunci => $format) {
        if ($h[$kunci] > 0) {
            $pesan[$kunci] = sprintf($format, $h[$kunci]);
        }
    }
    return $h + [
        'kebutuhan_bermasalah' => count($kebutuhan_masalah),
        'alokasi_bermasalah' => $alokasi_masalah,
        'masalah' => array_values($pesan),
        'masalah_kunci' => $pesan,
        'id_masalah' => $id_masalah,
        'siap' => !$pesan,
    ];
}

function penilaian_persiapan(array $proyek): array
{
    $syarat = syarat_persiapan($proyek);
    $rekam = rekam_persiapan((int) $proyek['id']);
    $pada = $rekam['dikonfirmasi_pada'] ?? null;
    $aktif = proyek_aktif($proyek);
    $masalah_kunci = $syarat['masalah_kunci'];
    if (!$pada && $aktif && $syarat['sudah_keluar'] > 0) {
        $masalah_kunci['data_lama'] = $syarat['sudah_keluar'] . ' alokasi barang sudah tercatat keluar sebelum ada konfirmasi persiapan (data lama). Konfirmasi baru tidak dibuat agar urutan transaksi tetap jujur.';
    }
    $masalah = array_values($masalah_kunci);
    if ($pada) {
        $status = 'Persiapan Dikonfirmasi Selesai';
    } elseif (!$aktif) {
        $status = 'Tidak Dikonfirmasi';
    } else {
        $status = $masalah ? 'Belum Siap' : 'Siap untuk Dikonfirmasi';
    }
    $beku = $pada && $aktif && $syarat['sudah_keluar'] > 0;
    return [
        'syarat' => $syarat,
        'masalah' => $masalah,
        'masalah_kunci' => $masalah_kunci,
        'status' => $status,
        'dikonfirmasi_pada' => $pada,
        'dikonfirmasi_oleh' => $rekam['dikonfirmasi_oleh'] ?? null,
        'konfirmasi_batal' => $rekam['konfirmasi_batal'] ?? null,
        'terkunci' => $pada && $aktif,
        'beku' => $beku,
        'boleh_konfirmasi' => !$pada && $aktif && !$masalah,
        'boleh_buka' => $pada && $aktif && !$beku,
        'perlu_cabut' => $pada && $aktif && !$beku && !$syarat['siap'],
    ];
}

// Pesan form bila perubahan jadwal ditolak sinkron_jadwal_alokasi(). Rentang alokasi = tanggal batas persiapan/mulai s.d. selesai.
function pesan_jadwal_ditolak(array $jadwal): string
{
    $baru = rentang_tgl(...$jadwal['rentang_baru']);
    if ($jadwal['status'] === 'jadwal_terkunci') {
        return 'Tanggal pemakaian barang tidak bisa digeser karena ' . $jadwal['keluar'] . ' alokasi proyek ini sudah tercatat di Serah Terima. '
            . 'Jam pada tanggal yang sama tetap bisa diubah.';
    }
    $daftar = [];
    foreach ($jadwal['bentrok'] as $b) {
        $daftar[] = $b['kode_unit'] . ' sudah dipesan ' . (cari_proyek($b['proyek_id'])['kode'] ?? '?') . ' (' . rentang_tgl($b['tgl_mulai'], $b['tgl_selesai']) . ')';
    }
    if ($jadwal['dibuka']) {
        return 'Proyek tidak bisa dibuka kembali karena reservasinya (pemakaian barang ' . $baru . ') bentrok: ' . implode('; ', $daftar)
            . '. Selama proyek ini ditutup, unit itu sudah dipesan proyek lain.';
    }
    return 'Jadwal baru (pemakaian barang ' . $baru . ') bentrok: ' . implode('; ', $daftar)
        . '. Ganti atau batalkan alokasi unit itu di tab Kebutuhan, atau pilih jadwal lain.';
}

// Validasi sebelum menyimpan; batasnya sama dengan kolom/CHECK di tabel proyek agar galat tampil di form, bukan 503.
function validasi_proyek(array $data): array
{
    $galat = [];
    foreach (['nama' => 'Nama proyek', 'klien' => 'Klien', 'lokasi' => 'Lokasi'] as $kunci => $label) {
        if ($data[$kunci] === '') {
            $galat[$kunci] = $label . ' wajib diisi.';
        } elseif (mb_strlen($data[$kunci]) > 80) {
            $galat[$kunci] = $label . ' maksimal 80 karakter.';
        }
    }
    if (mb_strlen($data['catatan']) > 300) {
        $galat['catatan'] = 'Catatan maksimal 300 karakter.';
    }
    $mulai = waktu_valid($data['mulai']);
    $selesai = waktu_valid($data['selesai']);
    $deadline = waktu_valid($data['deadline_persiapan']);
    if (!$mulai) {
        $galat['mulai'] = 'Isi waktu mulai.';
    }
    if (!$selesai) {
        $galat['selesai'] = 'Isi waktu selesai.';
    } elseif ($mulai && $selesai < $mulai) {
        $galat['selesai'] = 'Waktu selesai harus setelah waktu mulai.';
    }
    if (!$deadline) {
        $galat['deadline_persiapan'] = 'Isi batas persiapan.';
    } elseif ($mulai && $deadline > $mulai) {
        $galat['deadline_persiapan'] = 'Batas persiapan harus sebelum acara dimulai.';
    }
    $pic = preg_match('/^\d{1,9}$/', $data['pic_id']) ? cari_pengguna((int) $data['pic_id']) : null;
    if (!$pic || $pic['peran'] !== 'us' || $pic['status'] !== 'Aktif') {
        $galat['pic_id'] = 'Pilih PIC dari daftar User/Kru yang aktif.';
    }
    if (!in_array($data['status'], daftar_status_proyek(), true)) {
        $galat['status'] = 'Pilih status.';
    }
    return $galat;
}

function data_form_proyek(array $proyek): array
{
    return [
        'id' => $proyek['id'],
        'nama' => $proyek['nama'],
        'klien' => $proyek['klien'],
        'lokasi' => $proyek['lokasi'],
        'mulai' => waktu_input($proyek['mulai']),
        'selesai' => waktu_input($proyek['selesai']),
        'deadline_persiapan' => waktu_input($proyek['deadline_persiapan']),
        'pic_id' => $proyek['pic_id'],
        'status' => $proyek['status'],
        'catatan' => $proyek['catatan'],
        '_judul' => 'Ubah proyek ' . $proyek['kode'],
    ];
}

function simpan_proyek_dari_form(array $pengguna): void
{
    wajib_boleh('proyek.kelola');
    $id = (int) input('id');
    $lama = $id ? cari_proyek($id) : null;
    if ($id && !$lama) {
        flash('error', 'Proyek tidak ditemukan.');
        arahkan('pages/proyek.php');
    }
    $data = [
        'nama' => input('nama'),
        'klien' => input('klien'),
        'lokasi' => input('lokasi'),
        'mulai' => input('mulai'),
        'selesai' => input('selesai'),
        'deadline_persiapan' => input('deadline_persiapan'),
        'pic_id' => input('pic_id'),
        'status' => input('status'),
        'catatan' => input('catatan'),
    ];
    $galat = validasi_proyek($data);
    if ($galat) {
        simpan_form('dialog-proyek', $data + ['id' => $id ?: ''], $galat);
        muat_ulang();
    }
    $isi = [
        'nama' => $data['nama'],
        'klien' => $data['klien'],
        'lokasi' => $data['lokasi'],
        'mulai' => waktu_valid($data['mulai']),
        'selesai' => waktu_valid($data['selesai']),
        'deadline_persiapan' => waktu_valid($data['deadline_persiapan']),
        'status' => $data['status'],
        'pic_id' => (int) $data['pic_id'],
        'catatan' => $data['catatan'],
    ];
    $pesan_pic = ['pic_id' => 'PIC tidak lagi aktif atau bukan User/Kru. Pilih PIC lain.'];
    if ($lama) {
        $hasil = ubah_proyek($id, $isi);
        if ($hasil['status'] === 'tidak_ada') {
            flash('error', 'Proyek tidak ditemukan.');
            arahkan('pages/proyek.php');
        }
        if ($hasil['status'] === 'pic_tidak_sah') {
            simpan_form('dialog-proyek', $data + ['id' => $id], $pesan_pic);
            muat_ulang();
        }
        if ($hasil['status'] === 'proyek_tutup') {
            simpan_form('dialog-proyek', $data + ['id' => $id], ['status' => 'Proyek ' . $hasil['lama']['kode'] . ' berstatus ' . $hasil['lama']['status']
                . '. Proyek tertutup hanya bisa dibuka kembali: ubah Status ke Draf, Dijadwalkan, atau Berlangsung tanpa mengubah data lain. Jadwal dan data lain bisa diubah setelah proyek aktif.']);
            muat_ulang();
        }
        if (in_array($hasil['status'], ['jadwal_terkunci', 'bentrok'], true)) {
            simpan_form('dialog-proyek', $data + ['id' => $id], [($hasil['jadwal']['dibuka'] ? 'status' : 'mulai') => pesan_jadwal_ditolak($hasil['jadwal'])]);
            muat_ulang();
        }
        $digeser = $hasil['jadwal']['jumlah'];
        flash('sukses', 'Proyek ' . $hasil['lama']['kode'] . ' diperbarui.' . ($digeser > 0 ? ' Jadwal ' . $digeser . ' alokasi barang ikut disesuaikan (' . rentang_tgl(...$hasil['jadwal']['rentang_baru']) . ').' : ''));
        muat_ulang();
    }
    $baru = buat_proyek($isi, (int) $pengguna['id']);
    if (!$baru) {
        simpan_form('dialog-proyek', $data + ['id' => ''], $pesan_pic);
        muat_ulang();
    }
    flash('sukses', 'Proyek ' . $baru['kode'] . ' dibuat. Lanjutkan dengan menambah tim dan kebutuhan.');
    arahkan('pages/proyek-detail.php?id=' . $baru['id']);
}

// Kebijakan: proyek tidak dihapus permanen lewat aplikasi; proyek yang tidak jadi dilaksanakan diberi status Dibatalkan
// sehingga riwayat, serah terima, dan kode proyek tetap utuh. Sejak Sprint 4C seluruh data turunan proyek ada di PostgreSQL,
// jadi hapus proyek tanpa transaksi bisa diaktifkan (true) bila tim memutuskan; transaksi serah terima tetap dijaga FOREIGN KEY.
const HAPUS_PROYEK_TERSEDIA = false;

function proyek_mendatang(int $hari = 14): array
{
    $batas = date('Y-m-d', strtotime(HARI_INI . ' +' . $hari . ' days'));
    $hasil = array_filter(semua_proyek(), fn ($p) => proyek_aktif($p) && substr($p['selesai'], 0, 10) >= HARI_INI && substr($p['mulai'], 0, 10) <= $batas);
    return urutkan_proyek(array_values($hasil));
}
