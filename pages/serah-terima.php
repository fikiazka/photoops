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
        // Sprint 3C: pengembalian Studio, Rental, Milik Kru (boleh sebagian untuk Rental/Kru).
        // Tidak memakai gerbang konfirmasi persiapan; proyek Selesai/Dibatalkan tetap boleh menyelesaikan kewajiban.
        // Semua item divalidasi dulu; satu item gagal = seluruh pengembalian batal tanpa mutasi.
        if (!preg_match('/^\d{1,9}$/', input('proyek_id'))) {
            flash('error', 'Proyek tidak ditemukan.');
            muat_ulang();
        }
        $modal = 'dialog-kembali-' . $proyek['id'];
        $pid_kembali = (int) $proyek['id'];
        $mentah = $_POST['alokasi'] ?? null;
        $saldo_masuk = $_POST['saldo'] ?? [];
        $kondisi_masuk = $_POST['kondisi'] ?? [];
        $jumlah_masuk = $_POST['jumlah'] ?? [];
        $galat = [];
        $item = [];
        $lama_pilih = [];
        $waktu_minimal = '';
        $ada_masalah_kondisi = false;
        if (!is_array($mentah) || !$mentah) {
            $galat['alokasi'] = 'Centang minimal satu barang yang dikembalikan.';
        } elseif (!is_array($saldo_masuk) || !is_array($kondisi_masuk) || !is_array($jumlah_masuk)) {
            $galat['alokasi'] = 'Data pengembalian tidak valid. Muat ulang halaman lalu ulangi.';
        } else {
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
                if (!$a || (int) $a['proyek_id'] !== $pid_kembali) {
                    $masalah[] = 'Alokasi #' . $id . ' bukan milik proyek ' . $proyek['kode'] . '.';
                    continue;
                }
                $nama_a = nama_barang_alokasi($a) . ' (' . $a['sumber'] . ')';
                $serah = serah_alokasi($id);
                if (!isset($serah['keluar'])) {
                    $masalah[] = $nama_a . ': belum pernah tercatat keluar, jadi tidak bisa dikembalikan.';
                    continue;
                }
                $sisa = $serah['sisa'] ?? 0;
                if ($sisa < 1) {
                    $masalah[] = $nama_a . ': seluruh unit sudah tercatat kembali (' . (db_cari('transaksi', (int) ($serah['trx_kembali'] ?? 0))['kode'] ?? '?') . ').';
                    continue;
                }
                $saldo = $saldo_masuk[$id] ?? null;
                if (!is_string($saldo) || !preg_match('/^\d{1,6}$/', $saldo)) {
                    $masalah[] = $nama_a . ': saldo formulir tidak ditemukan. Muat ulang halaman lalu ulangi.';
                    continue;
                }
                if ((int) $saldo !== $sisa) {
                    $masalah[] = $nama_a . ': saldo berubah sejak formulir dibuka (sekarang ' . $sisa . ' unit masih di proyek). Pengembalian tidak dicatat dua kali; muat ulang lalu periksa kembali.';
                    continue;
                }
                $per_kondisi = ['Baik' => 0, 'Maintenance' => 0, 'Rusak' => 0];
                if ($a['sumber'] === 'Studio') {
                    $k = $kondisi_masuk[$id] ?? null;
                    if (!is_string($k) || !in_array($k, daftar_kondisi(), true)) {
                        $masalah[] = $nama_a . ': pilih kondisi Baik, Maintenance, atau Rusak.';
                        continue;
                    }
                    $per_kondisi[$k] = 1;
                } else {
                    $baris_j = $jumlah_masuk[$id] ?? null;
                    if (!is_array($baris_j) || array_diff(array_keys($baris_j), daftar_kondisi())) {
                        $masalah[] = $nama_a . ': jumlah per kondisi tidak valid.';
                        continue;
                    }
                    $salah = '';
                    foreach ($baris_j as $k => $j) {
                        if (!is_string($j)) {
                            $salah = 'jumlah per kondisi tidak valid.';
                            break;
                        }
                        $j = trim($j);
                        if ($j === '') {
                            continue;
                        }
                        if (!preg_match('/^\d{1,6}$/', $j)) {
                            $salah = 'jumlah harus bilangan bulat positif.';
                            break;
                        }
                        $per_kondisi[$k] = (int) $j;
                    }
                    if ($salah !== '') {
                        $masalah[] = $nama_a . ': ' . $salah;
                        continue;
                    }
                    $total_j = array_sum($per_kondisi);
                    if ($total_j < 1) {
                        $masalah[] = $nama_a . ': isi jumlah yang dikembalikan minimal 1 unit.';
                        continue;
                    }
                    if ($total_j > $sisa) {
                        $masalah[] = $nama_a . ': jumlah ' . $total_j . ' melebihi saldo di proyek (' . $sisa . ' unit).';
                        continue;
                    }
                }
                if ($per_kondisi['Maintenance'] + $per_kondisi['Rusak'] > 0) {
                    $ada_masalah_kondisi = true;
                }
                $batas = max((string) $serah['keluar'], (string) ($serah['kembali_terakhir'] ?? ''));
                $waktu_minimal = max($waktu_minimal, $batas);
                $item[] = ['alokasi' => $a, 'per_kondisi' => $per_kondisi, 'trx_asal' => (int) $serah['trx_keluar']];
            }
            if ($masalah) {
                $galat['alokasi'] = 'Pengembalian dibatalkan, tidak ada barang yang dicatat. ' . implode(' ', array_unique($masalah));
            }
        }
        $penyerah_id = input('penyerah_id');
        $penyerah = null;
        if (isset($_POST['penyerah_id']) && !is_string($_POST['penyerah_id'])) {
            $galat['penyerah_id'] = 'Pilih anggota tim yang menyerahkan, atau kosongkan.';
        } elseif ($penyerah_id !== '') {
            $penyerah = preg_match('/^\d{1,9}$/', $penyerah_id) && peran_di_proyek($pid_kembali, (int) $penyerah_id) !== '' ? db_cari('pengguna', (int) $penyerah_id) : null;
            if (!$penyerah) {
                $galat['penyerah_id'] = 'Pilih anggota tim yang menyerahkan, atau kosongkan.';
            }
        }
        $kini = date('Y-m-d H:i', strtotime(sekarang()));
        $waktu = waktu_tegas(input('waktu'));
        if (!$waktu) {
            $galat['waktu'] = 'Isi waktu pengembalian dengan tanggal dan jam yang valid.';
        } elseif ($waktu > $kini) {
            $galat['waktu'] = 'Waktu pengembalian tidak boleh melewati saat ini (' . tgl($kini, 'lengkap') . ').';
        } elseif ($waktu_minimal !== '' && $waktu < $waktu_minimal) {
            $galat['waktu'] = 'Waktu pengembalian tidak boleh sebelum barang keluar atau pengembalian sebelumnya (' . tgl($waktu_minimal, 'lengkap') . ').';
        }
        $catatan = input('catatan');
        if (mb_strlen($catatan) > 300) {
            $galat['catatan'] = 'Catatan maksimal 300 karakter.';
        } elseif ($ada_masalah_kondisi && mb_strlen($catatan) < 5) {
            $galat['catatan'] = 'Jelaskan masalah barang yang kembali dalam kondisi Maintenance atau Rusak.';
        }
        if (input('konfirmasi') !== '1') {
            $galat['konfirmasi'] = 'Centang pernyataan bahwa barang sudah diterima kembali dan kondisinya sudah diperiksa.';
        }
        if ($galat) {
            if (!barang_di_luar($pid_kembali)) {
                flash('error', 'Pengembalian untuk ' . $proyek['kode'] . ' tidak dicatat. ' . implode(' ', $galat));
            }
            simpan_form($modal, ['alokasi' => $lama_pilih, 'penyerah_id' => $penyerah_id, 'waktu' => input('waktu'), 'catatan' => $catatan], $galat);
            muat_ulang();
        }
        $hasil = proses_pengembalian($proyek, $item, $penyerah, $waktu, $catatan);
        $pesan = $hasil['kode'] . ': ' . $hasil['total'] . ' unit kembali dari ' . $proyek['kode'] . ' (' . teks_per_sumber($hasil['per_sumber']) . '; kondisi ' . teks_kondisi($hasil['per_kondisi']) . ')';
        $pesan .= $hasil['sisa_proyek'] > 0 ? ', sisa di proyek ' . $hasil['sisa_proyek'] . ' unit' : ', seluruh barang proyek sudah kembali';
        $pesan .= $hasil['bermasalah'] ? '. Insiden dicatat untuk ' . implode(', ', $hasil['bermasalah']) . ' (' . implode(', ', $hasil['insiden']) . ')' : '';
        $pesan .= $hasil['eksternal_bermasalah'] ? '. Kondisi barang eksternal dicatat di transaksi ini: ' . implode(', ', $hasil['eksternal_bermasalah']) . '; inventaris studio tidak berubah' : '';
        $pesan .= $hasil['per_sumber']['Rental'] ? '. Rental belum dikembalikan ke vendor' : '';
        flash($hasil['bermasalah'] || $hasil['eksternal_bermasalah'] ? 'info' : 'sukses', $pesan . '.');
        muat_ulang();
    }

    muat_ulang();
}

$kelola = boleh('serah_terima.kelola');
$siap = proyek_siap_keluar();
$di_luar_semua = barang_di_luar();
$luar = kelompok_per_proyek($di_luar_semua);
$transaksi = daftar_transaksi();
$jumlah_siap = array_sum(array_column($siap, 'bisa_unit'));
$proyek_bisa = count(array_filter($siap, fn ($s) => $s['bisa_unit'] > 0));
$luar_sumber = hitung_per_sumber($di_luar_semua);

$halaman = ['menu' => 'serah_terima', 'bagian' => 'Operasional', 'judul' => 'Serah Terima Barang'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Serah Terima Barang', 'Catat barang Studio, Rental, dan Milik Kru yang keluar untuk proyek setelah persiapan dikonfirmasi selesai, lalu pengembaliannya dari proyek. Rental dan Milik Kru bisa dikembalikan sebagian.');
?>
<div class="grid-kpi">
<div class="kartu kpi"><p class="kpi-label">Siap keluar</p><p class="kpi-angka"><?= $jumlah_siap ?></p><p class="kpi-ket">unit dari <?= $proyek_bisa ?> proyek terkonfirmasi</p></div>
<div class="kartu kpi"><p class="kpi-label">Sedang di luar</p><p class="kpi-angka teks-biru"><?= array_sum($luar_sumber) ?></p><p class="kpi-ket"><?= array_sum($luar_sumber) ? e(teks_per_sumber($luar_sumber)) . ' unit masih di proyek' : 'unit belum kembali' ?></p></div>
<div class="kartu kpi"><p class="kpi-label">Alokasi belum kembali</p><p class="kpi-angka" data-kpi-alokasi-di-proyek><?= count($di_luar_semua) ?></p><p class="kpi-ket">baris alokasi dengan sisa di <?= count($luar) ?> proyek</p></div>
<div class="kartu kpi"><p class="kpi-label">Unit Studio di proyek</p><p class="kpi-angka" data-kpi-studio-di-proyek><?= $luar_sumber['Studio'] ?></p><p class="kpi-ket">unit fisik, tidak tersedia untuk proyek lain</p></div>
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

<section class="kartu" id="pengembalian">
<div class="kartu-kepala">
<div><h2>Pengembalian</h2><p>Barang yang masih berada di proyek, diurutkan dari jadwal selesai proyek paling awal. Angka menunjukkan unit keluar, sudah kembali, dan sisa di proyek. Jadwal selesai hanya informasi, bukan batas pengembalian.</p></div>
</div>
<?php if (!$luar): ?>
<?= kosong('Tidak ada tanggungan barang', 'Semua barang yang tercatat keluar sudah kembali dari proyek.') ?>
<?php else: ?>
<ul class="daftar">
<?php foreach ($luar as $id_luar => $kelompok_serah): ?>
<?php $kp = $kelompok_serah['proyek']; ?>
<li class="daftar-baris daftar-baris-atas" data-kembali-proyek="<?= (int) $kp['id'] ?>">
<span class="ikon-bulat ikon-biru"><?= ikon('arrow-down-left', 16) ?></span>
<div class="daftar-utama">
<p class="daftar-judul"><a href="<?= e(url('pages/proyek-detail.php?id=' . $kp['id'] . '&tab=serah')) ?>"><?= e($kp['kode']) ?> · <?= e($kp['nama']) ?></a><?= proyek_aktif($kp) ? '' : ' <span class="redup">· ' . e($kp['status']) . '</span>' ?></p>
<ul class="unit-mini">
<?php foreach ($kelompok_serah['unit'] as $u): ?>
<?php $ua = $u['alokasi']; ?>
<li data-saldo-alokasi="<?= (int) $ua['id'] ?>"><span><?= e($ua['sumber'] === 'Studio' ? ($u['unit']['kode_unit'] ?? '?') : nama_barang_alokasi($ua) . ($u['jumlah'] > 1 ? ' × ' . $u['jumlah'] : '')) ?></span><?php if ($ua['sumber'] !== 'Studio'): ?> <span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $ua['sumber']))) ?>"><?= e($ua['sumber']) ?></span> <span class="redup">keluar <?= (int) $u['jumlah_keluar'] ?> · kembali <?= (int) $u['jumlah_kembali'] ?> · sisa <?= (int) $u['jumlah'] ?></span><?php endif; ?> <?= $u['status_kembali'] === 'Kembali Sebagian' ? badge('Kembali Sebagian') : '' ?> <span class="redup" data-jadwal-selesai>jadwal selesai <?= e(tgl($ua['tgl_selesai'], 'singkat')) ?></span></li>
<?php endforeach; ?>
</ul>
</div>
<?php if ($kelola): ?>
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
<span class="trx-orang kecil redup"><?= e($trx['jenis'] === 'Keluar' ? 'Diterima ' . $trx['penerima'] : 'Dicek ' . nama_pengguna($trx['petugas_id']) . (!empty($trx['penyerah']) ? ' · dari ' . $trx['penyerah'] : '')) ?></span>
<span class="trx-panah" aria-hidden="true"><?= ikon('chevron-right', 16) ?></span>
</summary>
<div class="trx-isi">
<ul class="unit-mini">
<?php foreach ($trx['detail'] as $d): ?>
<?php $a = db_cari('alokasi', $d['alokasi_id']); ?>
<li><span><?= e($a ? keterangan_sumber($a) . ' · ' . nama_barang_alokasi($a) : 'Alokasi dihapus') ?><?= jumlah_detail($d) > 1 ? ' × ' . jumlah_detail($d) : '' ?></span><?php if ($a && $a['sumber'] !== 'Studio'): ?> <span class="sumber sumber-<?= e(strtolower(str_replace(' ', '-', $a['sumber']))) ?>"><?= e($a['sumber']) ?></span><?php endif; ?> <?= badge($d['kondisi']) ?><?= !empty($d['trx_asal']) ? ' <span class="redup">asal ' . e(db_cari('transaksi', (int) $d['trx_asal'])['kode'] ?? '?') . '</span>' : '' ?></li>
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
    foreach ($luar as $kelompok_serah) {
        require ROOT . '/partials/modal_serah_terima.php';
    }
}
?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
