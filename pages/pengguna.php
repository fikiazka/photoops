<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('pengguna.kelola');

if (is_post()) {
    cek_csrf();
    wajib_boleh('pengguna.kelola');
    $aksi = input('aksi');

    if ($aksi === 'simpan_pengguna') {
        $id = (int) input('id');
        $lama_p = $id ? cari_pengguna($id) : null;
        if ($id && !$lama_p) {
            flash('error', 'Akun tidak ditemukan.');
            muat_ulang();
        }
        $data = [
            'id' => $id ?: '',
            'nama' => input('nama'),
            'username' => mb_strtolower(input('username')),
            'email' => input('email'),
            'telepon' => input('telepon'),
            'peran' => input('peran'),
            'jenis_anggota' => input('jenis_anggota'),
        ];
        $galat = validasi_pengguna($data, $lama_p ? $id : null);
        if ($lama_p && $id === (int) $pengguna['id'] && $data['peran'] !== 'sa') {
            $galat['peran'] = 'Anda tidak bisa menurunkan peran akun sendiri.';
        }
        if ($lama_p && $lama_p['peran'] === 'us' && $data['peran'] !== 'us' && id_proyek_pengguna($id)) {
            $galat['peran'] = 'User ini masih tergabung di proyek. Lepas dari tim proyek dulu.';
        }
        if ($galat) {
            simpan_form('dialog-pengguna', $data, $galat);
            muat_ulang();
        }
        $isi = $data;
        unset($isi['id']);
        $sandi_baru = $lama_p ? null : buat_sandi_sementara();
        try {
            // Perubahan akun dan catatan riwayatnya disimpan dalam satu transaksi.
            $berhasil = sql_transaksi(function () use ($lama_p, $id, $isi, $sandi_baru) {
                if (!$lama_p) {
                    sql_jalankan("INSERT INTO pengguna (nama, username, email, telepon, peran, jenis_anggota, password_hash, status)
                                  VALUES (:nama, :username, :email, :telepon, :peran, :jenis_anggota, :password_hash, 'Aktif')",
                        $isi + ['password_hash' => password_hash($sandi_baru, PASSWORD_DEFAULT)]);
                    catat_riwayat_pengguna($isi['nama'], 'Akun baru dibuat sebagai ' . nama_peran($isi['peran']));
                    return true;
                }
                // Menurunkan peran Super Admin aktif hanya boleh bila masih ada Super Admin aktif lain.
                if ($lama_p['peran'] === 'sa' && $isi['peran'] !== 'sa' && $lama_p['status'] === 'Aktif' && !ada_super_admin_lain($id)) {
                    return false;
                }
                sql_jalankan('UPDATE pengguna
                              SET nama = :nama, username = :username, email = :email, telepon = :telepon,
                                  peran = :peran, jenis_anggota = :jenis_anggota
                              WHERE id = :id', $isi + ['id' => $id]);
                $ganti = $lama_p['peran'] !== $isi['peran'];
                catat_riwayat_pengguna($isi['nama'], $ganti ? 'Peran diubah' : 'Data akun diperbarui',
                    $ganti ? nama_peran($lama_p['peran']) : '', $ganti ? nama_peran($isi['peran']) : '');
                return true;
            });
        } catch (PDOException $e) {
            $galat = galat_unik_pengguna($e);
            if (!$galat) {
                error_log('PhotoOps: simpan pengguna gagal: ' . $e->getMessage());
                flash('error', 'Data pengguna gagal disimpan. Coba lagi.');
                muat_ulang();
            }
            simpan_form('dialog-pengguna', $data, $galat);
            muat_ulang();
        }
        if (!$berhasil) {
            flash('error', 'Peran tidak diubah: harus tetap ada minimal satu Super Admin aktif.');
        } elseif ($lama_p) {
            flash('sukses', 'Akun ' . $isi['nama'] . ' diperbarui.');
        } else {
            titip_sandi_sementara($isi['nama'], $isi['username'], $sandi_baru, 'baru');
        }
        muat_ulang();
    }

    if (in_array($aksi, ['ubah_status', 'reset_sandi', 'hapus_pengguna'], true)) {
        $target = cari_pengguna((int) input('id'));
        if (!$target) {
            muat_ulang();
        }
        if ($target['id'] === (int) $pengguna['id']) {
            flash('error', 'Tindakan ini tidak bisa dilakukan pada akun sendiri.');
            muat_ulang();
        }
        try {
            if ($aksi === 'ubah_status') {
                $baru = $target['status'] === 'Aktif' ? 'Nonaktif' : 'Aktif';
                $hasil = sql_transaksi(function () use ($target, $baru) {
                    if ($target['peran'] === 'sa' && $baru === 'Nonaktif' && !ada_super_admin_lain($target['id'])) {
                        return 'super_admin_terakhir';
                    }
                    // Syarat status lama mencegah dua admin membalik status akun yang sama bersamaan.
                    $jumlah = sql_jalankan('UPDATE pengguna SET status = :baru WHERE id = :id AND status = :lama',
                        ['baru' => $baru, 'id' => $target['id'], 'lama' => $target['status']])->rowCount();
                    if ($jumlah === 0) {
                        return 'sudah_berubah';
                    }
                    catat_riwayat_pengguna($target['nama'], 'Status akun diubah', $target['status'], $baru);
                    return 'ok';
                });
                if ($hasil === 'ok') {
                    flash('sukses', 'Akun ' . $target['nama'] . ($baru === 'Aktif' ? ' diaktifkan kembali.' : ' dinonaktifkan dan tidak bisa masuk.'));
                } elseif ($hasil === 'super_admin_terakhir') {
                    flash('error', 'Akun tidak dinonaktifkan: harus tetap ada minimal satu Super Admin aktif.');
                } else {
                    flash('error', 'Status akun ' . $target['nama'] . ' sudah berubah. Periksa lagi daftar pengguna.');
                }
            } elseif ($aksi === 'hapus_pengguna') {
                [$hasil, $relasi] = hapus_pengguna($target);
                if ($hasil === 'ok') {
                    flash('sukses', 'Akun ' . $target['nama'] . ' dihapus permanen.');
                } elseif ($hasil === 'belum_terverifikasi') {
                    flash('error', 'Akun tidak dapat dihapus karena memiliki riwayat penggunaan atau status penggunaannya belum dapat diverifikasi. Silakan nonaktifkan akun.');
                } elseif ($hasil === 'dipakai') {
                    flash('error', 'Akun ' . $target['nama'] . ' tidak bisa dihapus karena sudah dipakai di: ' . implode(', ', $relasi)
                        . '. Nonaktifkan akun agar tidak bisa masuk; datanya tetap tersimpan.');
                } elseif ($hasil === 'super_admin_terakhir') {
                    flash('error', 'Akun tidak dihapus: harus tetap ada minimal satu Super Admin aktif.');
                } else {
                    flash('error', 'Akun ' . $target['nama'] . ' sudah berubah. Periksa lagi daftar pengguna.');
                }
            } else {
                $sandi_baru = buat_sandi_sementara();
                sql_transaksi(function () use ($target, $sandi_baru) {
                    sql_jalankan('UPDATE pengguna SET password_hash = :password_hash WHERE id = :id',
                        ['password_hash' => password_hash($sandi_baru, PASSWORD_DEFAULT), 'id' => $target['id']]);
                    catat_riwayat_pengguna($target['nama'], 'Kata sandi direset ke sandi sementara baru');
                });
                titip_sandi_sementara($target['nama'], $target['username'], $sandi_baru, 'reset');
            }
        } catch (PDOException $e) {
            error_log('PhotoOps: ' . $aksi . ' gagal: ' . $e->getMessage());
            if ($aksi === 'hapus_pengguna' && $e->getCode() === '23503') {
                // Ditolak FOREIGN KEY: akun keburu dipakai data lain di antara pemeriksaan dan DELETE.
                flash('error', 'Akun ' . $target['nama'] . ' tidak bisa dihapus karena sudah dipakai data lain. Nonaktifkan akun agar tidak bisa masuk; datanya tetap tersimpan.');
            } else {
                flash('error', 'Perubahan akun gagal disimpan. Coba lagi.');
            }
        }
        muat_ulang();
    }

    muat_ulang();
}

// Sandi sementara (akun baru/reset) hanya ditampilkan sekali; halaman ini tidak boleh disimpan di cache browser.
$sandi_sementara = ambil_sandi_sementara();
if ($sandi_sementara) {
    header('Cache-Control: no-store, max-age=0');
}

$f_peran = query('peran');
$kata = query('q');
// Saring status akun: Aktif (bawaan), Nonaktif, atau Semua. Hitungan chip peran mengikuti saringan ini.
$f_status = in_array(query('status'), ['Nonaktif', 'Semua'], true) ? query('status') : 'Aktif';
$semua = array_values(array_filter(semua_pengguna(), fn ($p) => $f_status === 'Semua' || $p['status'] === $f_status));
usort($semua, fn ($a, $b) => strcmp(array_search($a['peran'], array_keys(daftar_peran())) . $a['nama'], array_search($b['peran'], array_keys(daftar_peran())) . $b['nama']));
$tampil = array_filter($semua, fn ($p) => ($f_peran === '' || $p['peran'] === $f_peran) && cocok($kata, $p['nama'], $p['username'], $p['email']));
$tautan_peran = function (string $peran) use ($f_status): string {
    $q = http_build_query(array_filter(['peran' => $peran, 'status' => $f_status === 'Aktif' ? '' : $f_status]));
    return url('pages/pengguna.php' . ($q !== '' ? '?' . $q : ''));
};

$halaman = ['menu' => 'pengguna', 'bagian' => 'Pengaturan', 'judul' => 'Manajemen Pengguna'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Manajemen Pengguna', 'Akun yang bisa masuk ke PhotoOps beserta perannya.', tombol_modal('dialog-pengguna', 'Tambah pengguna', ['_judul' => 'Tambah pengguna'], 'user-plus'));
?>
<?php if ($sandi_sementara): ?>
<div class="pemberitahuan pemberitahuan-sukses" role="status" data-sandi-sementara>
<?= ikon('key-round') ?>
<span><?= $sandi_sementara['jenis'] === 'baru' ? 'Akun ' . e($sandi_sementara['nama']) . ' dibuat.' : 'Kata sandi ' . e($sandi_sementara['nama']) . ' direset.' ?>
Kata sandi sementara untuk <strong>@<?= e($sandi_sementara['username']) ?></strong>: <strong class="sandi-sementara"><?= e($sandi_sementara['sandi']) ?></strong>
— catat dan sampaikan langsung ke yang bersangkutan. Kata sandi ini hanya ditampilkan sekali.</span>
</div>
<?php endif; ?>
<section class="kartu">
<div class="kartu-kepala kartu-kepala-saring">
<nav class="chip-bar" aria-label="Saring peran">
<a href="<?= e($tautan_peran('')) ?>" class="chip<?= $f_peran === '' ? ' aktif' : '' ?>">Semua <span class="chip-angka"><?= count($semua) ?></span></a>
<?php foreach (daftar_peran() as $kode => $nama): ?>
<a href="<?= e($tautan_peran($kode)) ?>" class="chip<?= $f_peran === $kode ? ' aktif' : '' ?>"><?= e($nama) ?> <span class="chip-angka"><?= count(array_filter($semua, fn ($p) => $p['peran'] === $kode)) ?></span></a>
<?php endforeach; ?>
</nav>
<form method="get" class="saring-pengguna" role="search">
<input type="hidden" name="peran" value="<?= e($f_peran) ?>">
<label class="sr" for="saring-status-akun">Status akun</label>
<select id="saring-status-akun" name="status" data-kirim-otomatis>
<?= opsi(['Aktif' => 'Aktif', 'Nonaktif' => 'Nonaktif', 'Semua' => 'Semua status'], $f_status) ?>
</select>
<div class="cari">
<?= ikon('search', 16) ?>
<label class="sr" for="cari-pengguna">Cari pengguna</label>
<input id="cari-pengguna" type="search" name="q" value="<?= e($kata) ?>" placeholder="Nama, username, email">
</div>
</form>
</div>
<?php if (!$tampil): ?>
<?= kosong('Tidak ada pengguna yang cocok', 'Ubah saringan atau kata kunci.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu tabel-pengguna">
<thead><tr><th>Pengguna</th><th>Kontak</th><th>Peran</th><th class="angka">Proyek</th><th>Status</th><th><span class="sr">Aksi</span></th></tr></thead>
<tbody>
<?php foreach ($tampil as $p): ?>
<?php $diri = (int) $p['id'] === (int) $pengguna['id']; ?>
<tr<?= $p['status'] !== 'Aktif' ? ' class="baris-redup"' : '' ?>>
<td data-label="Pengguna">
<div class="orang">
<span class="avatar avatar-kecil" aria-hidden="true"><?= e(inisial($p['nama'])) ?></span>
<div><strong><?= e($p['nama']) ?></strong><?= $diri ? ' <span class="redup">(Anda)</span>' : '' ?><div class="kecil redup">@<?= e($p['username']) ?></div></div>
</div>
</td>
<td data-label="Kontak"><?= e($p['email']) ?><div class="kecil redup"><?= e($p['telepon'] !== '' ? $p['telepon'] : '—') ?></div></td>
<td data-label="Peran"><?= badge(nama_peran($p['peran'])) ?><?= $p['peran'] === 'us' ? '<div class="kecil redup">' . e($p['jenis_anggota']) . '</div>' : '' ?></td>
<td data-label="Proyek" class="angka"><?= $p['peran'] === 'us' ? count(id_proyek_pengguna($p['id'])) : '—' ?></td>
<td data-label="Status"><?= badge($p['status']) ?></td>
<td class="kolom-aksi">
<div class="aksi-baris">
<?= tombol_ikon_modal('dialog-pengguna', 'Ubah ' . $p['nama'], ['id' => $p['id'], 'nama' => $p['nama'], 'username' => $p['username'], 'email' => $p['email'], 'telepon' => $p['telepon'], 'peran' => $p['peran'], 'jenis_anggota' => $p['jenis_anggota'], '_judul' => 'Ubah pengguna'], 'pencil') ?>
<?php if (!$diri): ?>
<?= tombol_konfirmasi(['label' => 'Reset kata sandi ' . $p['nama'], 'ikon' => 'key-round', 'hanya_ikon' => true, 'aksi' => 'reset_sandi', 'id' => $p['id'], 'judul' => 'Reset kata sandi?', 'pesan' => 'Kata sandi lama ' . $p['nama'] . ' langsung tidak berlaku. Kata sandi sementara baru dibuat acak dan hanya ditampilkan sekali.', 'tombol' => 'Reset kata sandi', 'aman' => true]) ?>
<?= tombol_konfirmasi(['label' => ($p['status'] === 'Aktif' ? 'Nonaktifkan ' : 'Aktifkan ') . $p['nama'], 'ikon' => 'power', 'hanya_ikon' => true, 'aksi' => 'ubah_status', 'id' => $p['id'], 'judul' => $p['status'] === 'Aktif' ? 'Nonaktifkan akun?' : 'Aktifkan akun?', 'pesan' => $p['status'] === 'Aktif' ? $p['nama'] . ' tidak bisa masuk sampai diaktifkan lagi. Datanya di proyek tetap tersimpan.' : $p['nama'] . ' bisa masuk kembali.', 'tombol' => $p['status'] === 'Aktif' ? 'Nonaktifkan' : 'Aktifkan', 'aman' => $p['status'] !== 'Aktif']) ?>
<?php if (!HAPUS_PERMANEN_TERSEDIA): ?>
<button type="button" class="tombol-ikon tombol-ikon-kecil" aria-disabled="true" aria-label="Hapus <?= e($p['nama']) ?> tidak tersedia. Pemakaian akun belum dapat diverifikasi; nonaktifkan akun." title="Hapus permanen belum tersedia: pemakaian akun di Proyek dan Tim belum dapat diverifikasi. Gunakan Nonaktifkan akun."><?= ikon('trash-2', 16) ?></button>
<?php else: ?>
<?= tombol_konfirmasi(['label' => 'Hapus ' . $p['nama'], 'ikon' => 'trash-2', 'hanya_ikon' => true, 'aksi' => 'hapus_pengguna', 'id' => $p['id'], 'judul' => 'Hapus akun permanen?', 'pesan' => 'Akun ' . $p['nama'] . ' (@' . $p['username'] . ') dihapus permanen dan tidak bisa dikembalikan. Akun yang sudah dipakai di proyek, serah terima, insiden, atau riwayat akan ditolak — nonaktifkan saja.', 'tombol' => 'Hapus permanen']) ?>
<?php endif; ?>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<?php require ROOT . '/partials/modal_pengguna.php'; ?>
<?php require ROOT . '/includes/layout_foot.php'; ?>
