<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('serah_terima.lihat');

if (is_post()) {
    cek_csrf();
    wajib_boleh('serah_terima.kelola');
    $aksi = input('aksi');
    $proyek = db_cari('proyek', (int) input('proyek_id'));
    if (!$proyek) {
        flash('error', 'Proyek tidak ditemukan.');
        muat_ulang();
    }
    $pilih = array_map('intval', input_daftar('alokasi'));

    if ($aksi === 'checkout') {
        // Sprint 3B: satu-satunya jalur Barang Keluar (Studio, Rental, Milik Kru).
        // Semua item divalidasi dulu; satu item gagal = seluruh transaksi batal tanpa mutasi.
        if (!preg_match('/^\d{1,9}$/', input('proyek_id'))) {
            flash('error', 'Proyek tidak ditemukan.');
            muat_ulang();
        }
        $gerbang = gerbang_keluar($proyek);
        if (!$gerbang['boleh']) {
            flash('error', 'Barang keluar untuk ' . $proyek['kode'] . ' belum bisa dicatat. ' . $gerbang['alasan']);
            muat_ulang();
        }
        $modal = 'dialog-keluar-' . $proyek['id'];
        $mentah = $_POST['alokasi'] ?? null;
        $jumlah_masuk = $_POST['jumlah'] ?? [];
        $galat = [];
        $item = [];
        $lama_pilih = [];
        if (!is_array($mentah) || !$mentah) {
            $galat['alokasi'] = 'Pilih minimal satu barang yang diserahkan.';
        } elseif (!is_array($jumlah_masuk)) {
            $galat['alokasi'] = 'Data jumlah tidak valid. Muat ulang halaman lalu ulangi.';
        } else {
            $pid_keluar = (int) $proyek['id'];
            $diperiksa = id_diperiksa($pid_keluar);
            $masalah = [];
            $terlihat = [];
            foreach ($mentah as $nilai) {
                if (!is_string($nilai) || !preg_match('/^\d{1,9}$/', $nilai)) {
                    $masalah[] = 'Data barang tidak valid. Muat ulang halaman lalu pilih kembali.';
                    continue;
                }
                $id = (int) $nilai;
                $lama_pilih[] = (string) $id;
                if (isset($terlihat[$id])) {
                    $masalah[] = 'Alokasi #' . $id . ' terkirim lebih dari sekali.';
                    continue;
                }
                $terlihat[$id] = true;
                $a = db_cari('alokasi', $id);
                if (!$a || (int) $a['proyek_id'] !== $pid_keluar) {
                    $masalah[] = 'Alokasi #' . $id . ' bukan milik proyek ' . $proyek['kode'] . '.';
                    continue;
                }
                $nama_a = nama_barang_alokasi($a) . ' (' . $a['sumber'] . ')';
                $blokir = alasan_tidak_bisa_keluar($a, $diperiksa);
                if ($blokir !== '') {
                    $masalah[] = $nama_a . ': ' . $blokir . '.';
                    continue;
                }
                $wajib = jumlah_alokasi($a);
                $j = $jumlah_masuk[$id] ?? null;
                if (!is_string($j) || trim($j) === '') {
                    $masalah[] = $nama_a . ': jumlah belum diisi.';
                } elseif (!preg_match('/^\d{1,6}$/', trim($j))) {
                    $masalah[] = $nama_a . ': jumlah harus bilangan bulat positif.';
                } elseif ((int) $j < 1) {
                    $masalah[] = $nama_a . ': jumlah minimal 1 unit.';
                } elseif ((int) $j > $wajib) {
                    $masalah[] = $nama_a . ': jumlah ' . (int) $j . ' melebihi alokasi (' . $wajib . ' unit).';
                } elseif ((int) $j < $wajib) {
                    $masalah[] = $nama_a . ': penyerahan sebagian belum didukung. Serahkan seluruh ' . $wajib . ' unit sekaligus.';
                } else {
                    $item[] = ['alokasi' => $a, 'jumlah' => $wajib];
                }
            }
            if ($masalah) {
                $galat['alokasi'] = 'Transaksi dibatalkan, tidak ada barang yang dicatat. ' . implode(' ', array_unique($masalah));
            }
        }
        $penerima_id = input('penerima_id');
        $penerima = preg_match('/^\d{1,9}$/', $penerima_id) && isset(penerima_proyek($proyek['id'])[$penerima_id]) ? db_cari('pengguna', (int) $penerima_id) : null;
        if (!$penerima) {
            $galat['penerima_id'] = 'Pilih penerima dari anggota tim proyek yang aktif.';
        }
        $kini = date('Y-m-d H:i', strtotime(sekarang()));
        $waktu = waktu_tegas(input('waktu'));
        $acuan = substr((string) ($gerbang['nilai']['dikonfirmasi_pada'] ?? ''), 0, 16);
        if (!$waktu) {
            $galat['waktu'] = 'Isi waktu serah terima dengan tanggal dan jam yang valid.';
        } elseif ($waktu > $kini) {
            $galat['waktu'] = 'Waktu serah terima tidak boleh melewati saat ini (' . tgl($kini, 'lengkap') . ').';
        } elseif ($waktu < $acuan) {
            $galat['waktu'] = 'Waktu serah terima tidak boleh sebelum persiapan dikonfirmasi (' . tgl($acuan, 'lengkap') . ').';
        } else {
            foreach ($item as $baris) {
                if ($baris['alokasi']['sumber'] === 'Rental' && $waktu < $baris['alokasi']['diterima_pada']) {
                    $galat['waktu'] = 'Waktu serah terima tidak boleh sebelum ' . $baris['alokasi']['nama_barang'] . ' diterima dari vendor (' . tgl($baris['alokasi']['diterima_pada'], 'lengkap') . ').';
                    break;
                }
            }
        }
        $catatan = input('catatan');
        if (mb_strlen($catatan) > 160) {
            $galat['catatan'] = 'Catatan maksimal 160 karakter.';
        }
        if (input('konfirmasi') !== '1') {
            $galat['konfirmasi'] = 'Centang pernyataan bahwa barang yang dipilih sudah diserahkan kepada penerima.';
        }
        if ($galat) {
            $masih_bisa = array_filter(alokasi_siap_keluar((int) $proyek['id']), fn ($b) => $b['blokir'] === '');
            if (!$masih_bisa) {
                // Dialog tidak ditampilkan bila tidak ada alokasi layak; tampilkan alasannya sebagai pesan halaman.
                flash('error', 'Barang keluar untuk ' . $proyek['kode'] . ' tidak dicatat. ' . implode(' ', $galat));
            }
            simpan_form($modal, ['alokasi' => $lama_pilih, 'penerima_id' => $penerima_id, 'waktu' => input('waktu'), 'catatan' => $catatan], $galat);
            muat_ulang();
        }
        $hasil = proses_checkout($proyek, $item, $penerima, $waktu, $catatan);
        flash('sukses', $hasil['kode'] . ': ' . $hasil['total'] . ' unit keluar untuk ' . $proyek['kode'] . ' (' . teks_per_sumber($hasil['per_sumber']) . '), diterima ' . $penerima['nama'] . ' pada ' . tgl($waktu, 'lengkap') . '.');
        muat_ulang();
    }

    if ($aksi === 'checkin') {
        $modal = 'dialog-kembali-' . $proyek['id'];
        $di_luar = array_map(fn ($b) => (int) $b['alokasi']['id'], unit_belum_kembali($proyek['id']));
        $pilih = array_values(array_intersect($pilih, $di_luar));
        $kondisi_masuk = is_array($_POST['kondisi'] ?? null) ? $_POST['kondisi'] : [];
        $kondisi = [];
        foreach ($pilih as $id) {
            $k = is_string($kondisi_masuk[$id] ?? null) ? $kondisi_masuk[$id] : 'Baik';
            $kondisi[$id] = in_array($k, daftar_kondisi(), true) ? $k : 'Baik';
        }
        $galat = [];
        if (!$pilih) {
            $galat['alokasi'] = 'Centang minimal satu unit yang dikembalikan.';
        }
        $catatan = input('catatan');
        if (array_filter($kondisi, fn ($k) => $k !== 'Baik') && mb_strlen($catatan) < 5) {
            $galat['catatan'] = 'Jelaskan masalah unit yang tidak dalam kondisi baik.';
        }
        if ($galat) {
            simpan_form($modal, ['alokasi' => array_map('strval', $pilih), 'catatan' => $catatan], $galat);
            muat_ulang();
        }
        $hasil = proses_checkin($proyek, $kondisi, $catatan);
        $pesan = $hasil['kode'] . ': ' . count($kondisi) . ' unit kembali dari ' . $proyek['kode'];
        $pesan .= $hasil['terlambat'] ? ', ' . $hasil['terlambat'] . ' terlambat' : '';
        $pesan .= $hasil['bermasalah'] ? '. Insiden dicatat untuk ' . implode(', ', $hasil['bermasalah']) . '.' : '.';
        flash($hasil['bermasalah'] ? 'info' : 'sukses', $pesan);
        muat_ulang();
    }

    muat_ulang();
}

$kelola = boleh('serah_terima.kelola');
$siap = proyek_siap_keluar();
$di_luar_semua = barang_di_luar();
$luar = kelompok_per_proyek($di_luar_semua);
$luar_studio = kelompok_per_proyek(unit_belum_kembali());
$transaksi = daftar_transaksi();
$jumlah_siap = array_sum(array_column($siap, 'bisa_unit'));
$proyek_bisa = count(array_filter($siap, fn ($s) => $s['bisa_unit'] > 0));
$luar_sumber = hitung_per_sumber($di_luar_semua);

$halaman = ['menu' => 'serah_terima', 'bagian' => 'Operasional', 'judul' => 'Serah Terima Barang'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Serah Terima Barang', 'Catat barang Studio, Rental, dan Milik Kru yang keluar untuk proyek setelah persiapan dikonfirmasi selesai. Pengembalian saat ini hanya untuk unit studio.');
?>
<div class="grid-kpi">
<div class="kartu kpi"><p class="kpi-label">Siap keluar</p><p class="kpi-angka"><?= $jumlah_siap ?></p><p class="kpi-ket">unit dari <?= $proyek_bisa ?> proyek terkonfirmasi</p></div>
<div class="kartu kpi"><p class="kpi-label">Sedang di luar</p><p class="kpi-angka teks-biru"><?= array_sum($luar_sumber) ?></p><p class="kpi-ket"><?= array_sum($luar_sumber) ? e(teks_per_sumber($luar_sumber)) . ' unit' : 'unit belum kembali' ?></p></div>
<div class="kartu kpi"><p class="kpi-label">Jatuh tempo hari ini</p><p class="kpi-angka"><?= count(array_filter($di_luar_semua, fn ($b) => $b['status'] === 'Hari ini')) ?></p><p class="kpi-ket"><?= e(tgl(HARI_INI, 'panjang')) ?></p></div>
<div class="kartu kpi"><p class="kpi-label">Terlambat</p><p class="kpi-angka teks-merah"><?= count(array_filter($di_luar_semua, fn ($b) => $b['status'] === 'Terlambat')) ?></p><p class="kpi-ket">alokasi lewat jatuh tempo</p></div>
</div>

<div class="grid-2-rata">
<section class="kartu" id="barang-keluar">
<div class="kartu-kepala">
<div><h2>Barang keluar</h2><p>Proyek aktif yang masih punya alokasi belum keluar. Barang hanya bisa dicatat keluar setelah persiapan dikonfirmasi selesai.</p></div>
</div>
<?php if (!$siap): ?>
<?= kosong('Tidak ada barang yang menunggu', 'Semua alokasi proyek aktif sudah tercatat keluar, atau belum ada alokasi baru.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($siap as $kelompok_serah): ?>
<?php
$kp = $kelompok_serah['proyek'];
$gb = $kelompok_serah['gerbang'];
$tertahan = count($kelompok_serah['unit']) - $kelompok_serah['bisa_alokasi'];
$ps = hitung_per_sumber($kelompok_serah['unit'], true);
?>
<li class="daftar-baris daftar-baris-atas" data-keluar-proyek="<?= (int) $kp['id'] ?>">
<span class="ikon-bulat ikon-<?= $gb['boleh'] ? 'biru' : 'abu' ?>"><?= ikon('arrow-up-right', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><a href="<?= e(url('pages/proyek-detail.php?id=' . $kp['id'] . '&tab=serah')) ?>"><?= e($kp['kode']) ?> · <?= e($kp['nama']) ?></a></p>
<?php if ($gb['boleh']): ?>
<p class="daftar-sub">Mulai <?= e(tgl($kp['mulai'], 'singkat_jam')) ?> · <?= $kelompok_serah['bisa_unit'] ?> unit siap<?= $kelompok_serah['bisa_unit'] ? ' (' . e(teks_per_sumber($ps)) . ')' : '' ?><?= $tertahan ? ' · <span class="teks-merah">' . $tertahan . ' alokasi tertahan</span>' : '' ?></p>
<p class="daftar-sub"><?= badge('Persiapan Dikonfirmasi Selesai', 'Persiapan dikonfirmasi') ?> <span class="redup"><?= e(nama_pengguna($gb['nilai']['dikonfirmasi_oleh'])) ?> · <?= e(tgl($gb['nilai']['dikonfirmasi_pada'], 'singkat_jam')) ?></span></p>
<?php else: ?>
<p class="daftar-sub">Mulai <?= e(tgl($kp['mulai'], 'singkat_jam')) ?> · <?= count($kelompok_serah['unit']) ?> alokasi belum keluar</p>
<p class="daftar-sub" data-gerbang-alasan><?= badge($gb['nilai']['status'] ?? 'Belum Siap') ?> <span class="teks-kuning"><?= e($gb['alasan']) ?></span>
<?php if ($kelola): ?> <a class="tautan-kecil" data-tautan-persiapan href="<?= e(url('pages/proyek-detail.php?id=' . $kp['id'] . '&tab=persiapan')) ?>">Buka Persiapan</a><?php endif; ?></p>
<?php endif; ?>
</div>
<?php if ($kelola && $kelompok_serah['bisa_alokasi']): ?>
<button type="button" class="btn btn-kecil" data-buka="dialog-keluar-<?= (int) $kp['id'] ?>"><?= ikon('arrow-up-right', 16) ?><span>Keluarkan</span></button>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Sedang di luar</h2><p>Diurutkan dari jatuh tempo paling dekat. Pengembalian Rental dan Milik Kru dari proyek belum tersedia.</p></div>
</div>
<?php if (!$luar): ?>
<?= kosong('Tidak ada barang di luar', 'Belum ada barang yang sedang dipakai proyek.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($luar as $id_luar => $kelompok_serah): ?>
<?php $kp = $kelompok_serah['proyek']; $terlambat = count(array_filter($kelompok_serah['unit'], fn ($u) => $u['status'] === 'Terlambat')); ?>
<li class="daftar-baris daftar-baris-atas">
<span class="ikon-bulat ikon-<?= $terlambat ? 'merah' : 'hijau' ?>"><?= ikon('arrow-down-left', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><a href="<?= e(url('pages/proyek-detail.php?id=' . $kp['id'] . '&tab=serah')) ?>"><?= e($kp['kode']) ?> · <?= e($kp['nama']) ?></a></p>
<ul class="unit-mini">
<?php foreach ($kelompok_serah['unit'] as $u): ?>
<?php $ua = $u['alokasi']; ?>
<li><span><?= e($ua['sumber'] === 'Studio' ? ($u['unit']['kode_unit'] ?? '?') : nama_barang_alokasi($ua) . ($u['jumlah'] > 1 ? ' × ' . $u['jumlah'] : '')) ?></span><?php if ($ua['sumber'] !== 'Studio'): ?> <span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $ua['sumber']))) ?>"><?= e($ua['sumber']) ?></span><?php endif; ?> <?= badge($u['status'], $u['status'] === 'Terlambat' ? 'Terlambat ' . hari_terlambat($ua) . ' hari' : ($u['status'] === 'Hari ini' ? 'Tempo hari ini' : 'Tempo ' . tgl($ua['tgl_selesai'], 'singkat'))) ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php if ($kelola && isset($luar_studio[$id_luar])): ?>
<button type="button" class="btn btn-sekunder btn-kecil" data-buka="dialog-kembali-<?= (int) $kp['id'] ?>"><?= ikon('arrow-down-left', 16) ?><span>Terima kembali</span></button>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
</div>

<section class="kartu">
<div class="kartu-kepala">
<div><h2>Riwayat transaksi</h2><p><?= count($transaksi) ?> transaksi. Buka baris untuk melihat barang, jumlah, dan kondisinya.</p></div>
</div>
<?php if (!$transaksi): ?>
<?= kosong('Belum ada transaksi', 'Transaksi barang keluar dan kembali akan tercatat di sini.') ?>
<?php else: ?>
<div class="trx-daftar">
<?php foreach ($transaksi as $trx): ?>
<?php $unit_trx = array_sum(array_map('jumlah_detail', $trx['detail'])); ?>
<details class="trx">
<summary>
<span class="ikon-bulat ikon-<?= $trx['jenis'] === 'Keluar' ? 'biru' : 'hijau' ?>"><?= ikon($trx['jenis'] === 'Keluar' ? 'arrow-up-right' : 'arrow-down-left', 16) ?></span>
<span class="daftar-utama">
<span class="daftar-judul"><?= e($trx['kode']) ?> · <?= e($trx['jenis'] === 'Keluar' ? 'Barang keluar' : 'Pengembalian') ?></span>
<span class="daftar-sub"><?= e($trx['proyek']['kode'] ?? '?') ?> · <?= e(tgl($trx['waktu'], 'lengkap')) ?> · <?= $unit_trx ?> unit<?= count($trx['detail']) !== $unit_trx ? ' (' . count($trx['detail']) . ' alokasi)' : '' ?></span>
</span>
<span class="trx-orang kecil redup"><?= e($trx['jenis'] === 'Keluar' ? 'Diterima ' . $trx['penerima'] : 'Dicek ' . nama_pengguna($trx['petugas_id'])) ?></span>
<span class="trx-panah" aria-hidden="true"><?= ikon('chevron-right', 16) ?></span>
</summary>
<div class="trx-isi">
<ul class="unit-mini">
<?php foreach ($trx['detail'] as $d): ?>
<?php $a = db_cari('alokasi', $d['alokasi_id']); ?>
<li><span><?= e($a ? keterangan_sumber($a) . ' · ' . nama_barang_alokasi($a) : 'Alokasi dihapus') ?><?= jumlah_detail($d) > 1 ? ' × ' . jumlah_detail($d) : '' ?></span><?php if ($a && $a['sumber'] !== 'Studio'): ?> <span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= e($a['sumber']) ?></span><?php endif; ?> <?= badge($d['kondisi']) ?></li>
<?php endforeach; ?>
</ul>
<?php if ($trx['catatan'] !== ''): ?><p class="kecil redup">Catatan: <?= e($trx['catatan']) ?></p><?php endif; ?>
<p class="kecil redup">Petugas: <?= e(nama_pengguna($trx['petugas_id'])) ?><?= !empty($trx['dicatat_pada']) && $trx['dicatat_pada'] !== $trx['waktu'] ? ' · dicatat ' . e(tgl($trx['dicatat_pada'], 'singkat_jam')) : '' ?></p>
</div>
</details>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<?php
if ($kelola) {
    $jenis_serah = 'keluar';
    foreach ($siap as $kelompok_serah) {
        if ($kelompok_serah['bisa_alokasi']) {
            require ROOT . '/partials/modal_serah_terima.php';
        }
    }
    $jenis_serah = 'kembali';
    foreach ($luar_studio as $kelompok_serah) {
        require ROOT . '/partials/modal_serah_terima.php';
    }
}
?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
