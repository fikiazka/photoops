<?php
defined('ROOT') || exit;

// ---------------------------------------------------------------------------------------------
// Persiapan H-1 (Sprint Backend 4B) di PostgreSQL.
//   - Checklist: kolom alokasi_barang.diperiksa (satu centang per alokasi; CHECK ck_alokasi_diperiksa: hanya alokasi
//     Dikonfirmasi yang boleh dicentang).
//   - Konfirmasi dan pembatalan konfirmasi terakhir: tabel persiapan_proyek (dikonfirmasi_*, batal_*; riwayat lengkapnya di
//     riwayat_aktivitas aksi 'Persiapan').
//   - Penilaian syarat (syarat_persiapan, penilaian_persiapan di logic_proyek.php) dihitung dari data PostgreSQL saja.
//   - Urutan kunci sama dengan modul lain: proyek -> persiapan_proyek -> alokasi -> unit perlengkapan. Perubahan
//     kebutuhan/alokasi (3C) dan barang keluar (4A) juga mengunci proyek lebih dulu, jadi konfirmasi tidak bisa
//     berselang-seling dengan perubahan yang menjadi dasarnya.
// ---------------------------------------------------------------------------------------------

const KOLOM_PERSIAPAN = "proyek_id, TO_CHAR(diperbarui_pada, 'YYYY-MM-DD HH24:MI') AS diperbarui_pada, diperbarui_oleh,
    TO_CHAR(dikonfirmasi_pada, 'YYYY-MM-DD HH24:MI') AS dikonfirmasi_pada, dikonfirmasi_oleh,
    TO_CHAR(batal_pada, 'YYYY-MM-DD HH24:MI') AS batal_pada, batal_oleh, batal_otomatis::int AS batal_otomatis, batal_alasan,
    TO_CHAR(batal_konfirmasi_pada, 'YYYY-MM-DD HH24:MI') AS batal_konfirmasi_pada, batal_konfirmasi_oleh";

// Bentuk data sama dengan rekam persiapan versi session, agar tampilan dan penilaian tidak berubah.
function persiapan_dari_baris(array $b): array
{
    $angka = fn ($v) => $v === null ? null : (int) $v;
    return [
        'proyek_id' => (int) $b['proyek_id'],
        'diperbarui_pada' => $b['diperbarui_pada'],
        'diperbarui_oleh' => $angka($b['diperbarui_oleh']),
        'dikonfirmasi_pada' => $b['dikonfirmasi_pada'],
        'dikonfirmasi_oleh' => $angka($b['dikonfirmasi_oleh']),
        'konfirmasi_batal' => $b['batal_pada'] === null ? null : [
            'pada' => $b['batal_pada'],
            'oleh' => $angka($b['batal_oleh']),
            'otomatis' => (bool) (int) $b['batal_otomatis'],
            'alasan' => $b['batal_alasan'],
            'dikonfirmasi_pada' => $b['batal_konfirmasi_pada'],
            'dikonfirmasi_oleh' => $angka($b['batal_konfirmasi_oleh']),
        ],
    ];
}

// Semua baris persiapan_proyek, ber-key proyek_id. Dibaca sekali per request; $muat_ulang = true sesudah ada perubahan.
function semua_persiapan(bool $muat_ulang = false): array
{
    static $daftar = null;
    if ($daftar === null || $muat_ulang) {
        $daftar = [];
        foreach (sql_semua('SELECT ' . KOLOM_PERSIAPAN . ' FROM persiapan_proyek ORDER BY proyek_id') as $baris) {
            $baris = persiapan_dari_baris($baris);
            $daftar[$baris['proyek_id']] = $baris;
        }
    }
    return $daftar;
}

// Id alokasi proyek yang dicentang di checklist persiapan.
function id_diperiksa(int $proyek_id): array
{
    $hasil = [];
    foreach (semua_alokasi() as $a) {
        if ($a['proyek_id'] === $proyek_id && $a['diperiksa']) {
            $hasil[] = $a['id'];
        }
    }
    return $hasil;
}

// Rekam persiapan satu proyek (konfirmasi, pembatalan terakhir, progres) + daftar centang. null bila belum ada barisnya.
function rekam_persiapan(int $proyek_id): ?array
{
    $rekam = semua_persiapan()[$proyek_id] ?? null;
    return $rekam === null ? null : $rekam + ['diperiksa' => id_diperiksa($proyek_id)];
}

function muat_ulang_persiapan(): void
{
    semua_persiapan(true);
    semua_alokasi(true);
    proyek_berubah(true);
}

// Baca ulang semua data yang dipakai penilaian persiapan. Dipanggil di dalam transaksi SETELAH baris dikunci, sehingga
// syarat dinilai dari data terbaru (bukan dari cache yang dibaca sebelum menunggu kunci).
function segarkan_data_persiapan(): void
{
    semua_proyek(true);
    semua_anggota(true);
    semua_perlengkapan(true);
    semua_kebutuhan(true);
    semua_alokasi(true);
    semua_transaksi(true);
    semua_detail_transaksi(true);
    semua_persiapan(true);
}

// Kunci baris persiapan proyek (dibuat bila belum ada; relasi 1:1 dengan proyek). Dipanggil SETELAH kunci_proyek().
function kunci_persiapan(int $proyek_id): array
{
    sql_jalankan('INSERT INTO persiapan_proyek (proyek_id) VALUES (:p) ON CONFLICT (proyek_id) DO NOTHING', ['p' => $proyek_id]);
    return persiapan_dari_baris(sql_satu('SELECT ' . KOLOM_PERSIAPAN . ' FROM persiapan_proyek WHERE proyek_id = :p FOR UPDATE', ['p' => $proyek_id]));
}

// Kunci semua alokasi aktif (selain Dibatalkan) proyek, urut id. Dipanggil SETELAH kunci_proyek().
function kunci_alokasi_aktif_proyek(int $proyek_id): array
{
    $hasil = [];
    foreach (sql_semua('SELECT ' . KOLOM_ALOKASI . DARI_ALOKASI . " WHERE k.proyek_id = :p AND a.status <> 'Dibatalkan' ORDER BY a.id FOR UPDATE OF a",
        ['p' => $proyek_id]) as $baris) {
        $baris = alokasi_dari_baris($baris);
        $hasil[$baris['id']] = $baris;
    }
    return $hasil;
}

// Simpan checklist. $kiriman: id alokasi yang dicentang di form. Centang untuk alokasi yang belum bisa diperiksa (rental
// belum diterima) dan sudah tersimpan sebelumnya dipertahankan, sama dengan perilaku lama.
// Hasil: ['status' => ok | proyek_tutup | terkunci, 'diabaikan' => n].
function simpan_checklist_persiapan(int $proyek_id, array $kiriman, int $pengguna_id): array
{
    $hasil = sql_transaksi(function () use ($proyek_id, $kiriman, $pengguna_id) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
        }
        $rekam = kunci_persiapan($proyek_id);
        if ($rekam['dikonfirmasi_pada'] !== null) {
            return ['status' => 'terkunci', 'rekam' => $rekam];
        }
        $alokasi = kunci_alokasi_aktif_proyek($proyek_id);
        $boleh = array_keys(array_filter($alokasi, 'alokasi_bisa_diperiksa'));
        $tertahan = array_keys(array_filter($alokasi, fn ($a) => $a['diperiksa'] && $a['status'] === 'Dikonfirmasi' && !alokasi_bisa_diperiksa($a)));
        $kiriman = array_values(array_unique(array_map('intval', $kiriman)));
        $pilih = array_values(array_unique(array_merge(array_intersect($kiriman, $boleh), $tertahan)));
        sql_jalankan('UPDATE alokasi_barang SET diperiksa = FALSE
                      WHERE diperiksa AND kebutuhan_id IN (SELECT id FROM kebutuhan_proyek WHERE proyek_id = :p)', ['p' => $proyek_id]);
        foreach ($pilih as $id) {
            sql_jalankan('UPDATE alokasi_barang SET diperiksa = TRUE WHERE id = :id', ['id' => $id]);
        }
        sql_jalankan('UPDATE persiapan_proyek SET diperbarui_pada = :w, diperbarui_oleh = :u WHERE proyek_id = :p',
            ['w' => sekarang(), 'u' => $pengguna_id, 'p' => $proyek_id]);
        $selesai = count(array_intersect($pilih, $boleh));
        catat_riwayat_proyek('Persiapan', $proyek['kode'], $selesai . ' dari ' . count($alokasi) . ' barang diperiksa');
        return ['status' => 'ok', 'diabaikan' => count(array_diff($kiriman, $boleh))];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_persiapan();
    }
    return $hasil;
}

// Konfirmasi persiapan selesai. Semua syarat dinilai ulang dari data terbaru setelah proyek, persiapan, alokasi, dan unit
// Studio dikunci. Konfirmasi kedua (misalnya dari browser lain bersamaan) ditolak, jadi status dan riwayat tidak ganda.
// Hasil: ['status' => ok | proyek_tutup | sudah | belum_siap, 'masalah' => [...], 'rekam' => ..., 'syarat' => ...].
function konfirmasi_persiapan(int $proyek_id, int $pengguna_id): array
{
    $hasil = sql_transaksi(function () use ($proyek_id, $pengguna_id) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
        }
        $rekam = kunci_persiapan($proyek_id);
        if ($rekam['dikonfirmasi_pada'] !== null) {
            return ['status' => 'sudah', 'rekam' => $rekam];
        }
        $alokasi = kunci_alokasi_aktif_proyek($proyek_id);
        foreach ($alokasi as $a) {
            if ($a['sumber'] === 'Studio') {
                sql_jalankan('SELECT id FROM perlengkapan WHERE id = :id FOR SHARE', ['id' => $a['perlengkapan_id']]);
            }
        }
        segarkan_data_persiapan();
        $np = penilaian_persiapan($proyek);
        if (!$np['boleh_konfirmasi']) {
            return ['status' => 'belum_siap', 'masalah' => $np['masalah']];
        }
        $waktu = sekarang();
        sql_jalankan('UPDATE persiapan_proyek SET dikonfirmasi_pada = :w, dikonfirmasi_oleh = :u WHERE proyek_id = :p',
            ['w' => $waktu, 'u' => $pengguna_id, 'p' => $proyek_id]);
        $s = $np['syarat'];
        // Batas persiapan saat konfirmasi ikut ditulis di keterangan agar bisa ditelusuri; penilaian Tepat Waktu/Terlambat
        // memakai urutan id log (lihat deadline_saat_konfirmasi() di logic_riwayat.php).
        catat_riwayat_proyek('Persiapan', $proyek['kode'], 'Persiapan dikonfirmasi selesai · ' . $s['kebutuhan'] . ' kebutuhan, ' . $s['memenuhi']
            . ' alokasi (Studio ' . $s['unit_sumber']['Studio'] . ', Rental ' . $s['unit_sumber']['Rental'] . ', Milik Kru ' . $s['unit_sumber']['Milik Kru'] . ' unit)'
            . ' · batas persiapan ' . $proyek['deadline_persiapan'], 'Siap untuk Dikonfirmasi', 'Dikonfirmasi Selesai');
        return ['status' => 'ok', 'syarat' => $s];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_persiapan();
    }
    return $hasil;
}

// Cabut konfirmasi persiapan: dibuka ulang (manual, $alasan diisi) atau otomatis karena syarat tidak lagi terpenuhi
// ($alasan = null; syarat dinilai ulang di dalam kunci). Konfirmasi yang barangnya sudah keluar (beku) tidak dicabut.
// Hasil: ['status' => ok | tidak_ada | beku | masih_berlaku | proyek_tutup, 'alasan' => ...].
function cabut_konfirmasi_persiapan(int $proyek_id, ?string $alasan, int $pengguna_id = 0): array
{
    $otomatis = $alasan === null;
    $hasil = sql_transaksi(function () use ($proyek_id, $alasan, $otomatis, $pengguna_id) {
        $proyek = kunci_proyek($proyek_id);
        if (!$proyek || !proyek_aktif($proyek)) {
            return ['status' => 'proyek_tutup'];
        }
        $rekam = kunci_persiapan($proyek_id);
        if ($rekam['dikonfirmasi_pada'] === null) {
            return ['status' => 'tidak_ada'];
        }
        kunci_alokasi_aktif_proyek($proyek_id);
        segarkan_data_persiapan();
        $np = penilaian_persiapan($proyek);
        if ($np['beku']) {
            return ['status' => 'beku', 'sudah_keluar' => $np['syarat']['sudah_keluar']];
        }
        if ($otomatis) {
            if (!$np['perlu_cabut']) {
                return ['status' => 'masih_berlaku'];
            }
            $alasan = implode(' ', $np['syarat']['masalah']);
        }
        sql_jalankan('UPDATE persiapan_proyek SET batal_pada = :w, batal_oleh = :u, batal_otomatis = :o, batal_alasan = :a,
                             batal_konfirmasi_pada = dikonfirmasi_pada, batal_konfirmasi_oleh = dikonfirmasi_oleh,
                             dikonfirmasi_pada = NULL, dikonfirmasi_oleh = NULL
                      WHERE proyek_id = :p', [
            'w' => sekarang(), 'u' => $pengguna_id ?: ($_SESSION['user_id'] ?? null), 'o' => $otomatis ? 'true' : 'false', 'a' => $alasan, 'p' => $proyek_id,
        ]);
        catat_riwayat_proyek('Persiapan', $proyek['kode'], ($otomatis ? 'Konfirmasi persiapan selesai tidak berlaku lagi: ' : 'Persiapan dibuka ulang: ') . $alasan,
            'Dikonfirmasi Selesai', $otomatis ? 'Perlu konfirmasi ulang' : 'Dibuka ulang');
        return ['status' => 'ok', 'alasan' => $alasan, 'kode' => $proyek['kode']];
    });
    if ($hasil['status'] === 'ok') {
        muat_ulang_persiapan();
    }
    return $hasil;
}

// Dipanggil bootstrap sesudah POST yang mengubah data: konfirmasi yang syaratnya tidak lagi terpenuhi (misalnya unit menjadi
// Rusak di Inventaris atau rental dikembalikan ke vendor) dicabut otomatis. Berlaku untuk semua browser karena datanya di
// PostgreSQL; penilaian ulang terjadi di dalam kunci (cabut_konfirmasi_persiapan).
function sinkron_konfirmasi_persiapan(): void
{
    try {
        segarkan_data_persiapan();
        foreach (semua_persiapan() as $rekam) {
            $proyek = $rekam['dikonfirmasi_pada'] !== null ? cari_proyek($rekam['proyek_id']) : null;
            if (!$proyek || !proyek_aktif($proyek) || !penilaian_persiapan($proyek)['perlu_cabut']) {
                continue;
            }
            $hasil = cabut_konfirmasi_persiapan($proyek['id'], null);
            if ($hasil['status'] === 'ok') {
                flash('info', 'Konfirmasi persiapan ' . $proyek['kode'] . ' tidak berlaku lagi karena data berubah: ' . $hasil['alasan'] . ' Periksa ulang lalu konfirmasi kembali.');
                segarkan_data_persiapan();
            }
        }
    } catch (Throwable $e) {
        if (!galat_db($e)) {
            throw $e;
        }
        error_log('PhotoOps: sinkron konfirmasi persiapan gagal: ' . $e->getMessage());
    }
}

// Pemeriksaan awal di halaman (pesan yang jelas). Pengaman sebenarnya ada di dalam transaksi kebutuhan/alokasi
// (persiapan_dikonfirmasi_db) dan checklist (simpan_checklist_persiapan).
function wajib_persiapan_terbuka(array $proyek): void
{
    $rekam = rekam_persiapan((int) $proyek['id']);
    if (!proyek_aktif($proyek) || empty($rekam['dikonfirmasi_pada'])) {
        return;
    }
    flash('error', pesan_persiapan_terkunci($proyek, $rekam));
    muat_ulang();
}

// Hasil fungsi kebutuhan/alokasi berstatus 'persiapan_terkunci' (persiapan dikonfirmasi dari browser lain setelah halaman
// dibuka): tampilkan pesan kunci dan hentikan aksi.
function tolak_bila_persiapan_terkunci(array $hasil, array $proyek): void
{
    if (($hasil['status'] ?? '') === 'persiapan_terkunci') {
        flash('error', pesan_persiapan_terkunci($proyek));
        muat_ulang();
    }
}

function pesan_persiapan_terkunci(array $proyek, ?array $rekam = null): string
{
    $rekam = $rekam ?? rekam_persiapan((int) $proyek['id']);
    return 'Persiapan ' . $proyek['kode'] . ' sudah dikonfirmasi selesai' . (!empty($rekam['dikonfirmasi_pada']) ? ' oleh ' . nama_pengguna($rekam['dikonfirmasi_oleh'])
        . ' pada ' . tgl($rekam['dikonfirmasi_pada'], 'lengkap') : '') . '. Kebutuhan, alokasi, dan checklist dikunci selama konfirmasi berlaku. Buka ulang persiapan di tab Persiapan jika memang perlu diubah.';
}
