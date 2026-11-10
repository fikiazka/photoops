<?php
require __DIR__ . '/../includes/bootstrap.php';
$pengguna = wajib_akses('pengguna.kelola');

if (is_post()) {
    cek_csrf();
    wajib_boleh('pengguna.kelola');
    $aksi = input('aksi');

    if ($aksi === 'simpan_pengguna') {
        $id = (int) input('id');
        $lama_p = $id ? db_cari('pengguna', $id) : null;
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
        if ($lama_p) {
            db_ubah('pengguna', $id, $isi);
            $ganti = $lama_p['peran'] !== $isi['peran'];
            catat_riwayat('Pengguna', $isi['nama'], $ganti ? 'Peran diubah' : 'Data akun diperbarui', $ganti ? nama_peran($lama_p['peran']) : '', $ganti ? nama_peran($isi['peran']) : '');
            flash('sukses', 'Akun ' . $isi['nama'] . ' diperbarui.');
        } else {
            db_tambah('pengguna', $isi + ['password' => password_hash('nicio123', PASSWORD_DEFAULT), 'status' => 'Aktif']);
            catat_riwayat('Pengguna', $isi['nama'], 'Akun baru dibuat sebagai ' . nama_peran($isi['peran']));
            flash('sukses', 'Akun ' . $isi['username'] . ' dibuat dengan kata sandi awal nicio123.');
        }
        muat_ulang();
    }

    if (in_array($aksi, ['ubah_status', 'reset_sandi'], true)) {
        $target = db_cari('pengguna', (int) input('id'));
        if (!$target) {
            muat_ulang();
        }
        if ((int) $target['id'] === (int) $pengguna['id']) {
            flash('error', 'Tindakan ini tidak bisa dilakukan pada akun sendiri.');
            muat_ulang();
        }
        if ($aksi === 'ubah_status') {
            $baru = $target['status'] === 'Aktif' ? 'Nonaktif' : 'Aktif';
            db_ubah('pengguna', $target['id'], ['status' => $baru]);
            catat_riwayat('Pengguna', $target['nama'], 'Status akun diubah', $target['status'], $baru);
            flash('sukses', 'Akun ' . $target['nama'] . ($baru === 'Aktif' ? ' diaktifkan kembali.' : ' dinonaktifkan dan tidak bisa masuk.'));
        } else {
            db_ubah('pengguna', $target['id'], ['password' => password_hash('nicio123', PASSWORD_DEFAULT)]);
            catat_riwayat('Pengguna', $target['nama'], 'Kata sandi direset ke bawaan');
            flash('sukses', 'Kata sandi ' . $target['nama'] . ' direset ke nicio123.');
        }
        muat_ulang();
    }

    muat_ulang();
}

$f_peran = query('peran');
$kata = query('q');
$semua = array_values(db('pengguna'));
usort($semua, fn ($a, $b) => strcmp(array_search($a['peran'], array_keys(daftar_peran())) . $a['nama'], array_search($b['peran'], array_keys(daftar_peran())) . $b['nama']));
$tampil = array_filter($semua, fn ($p) => ($f_peran === '' || $p['peran'] === $f_peran) && cocok($kata, $p['nama'], $p['username'], $p['email']));

$halaman = ['menu' => 'pengguna', 'bagian' => 'Pengaturan', 'judul' => 'Manajemen Pengguna'];
require ROOT . '/includes/layout_head.php';

judul_halaman('Manajemen Pengguna', 'Akun yang bisa masuk ke PhotoOps beserta perannya.', tombol_modal('dialog-pengguna', 'Tambah pengguna', ['_judul' => 'Tambah pengguna'], 'user-plus'));
?>
<section class="kartu">
<div class="kartu-kepala kartu-kepala-saring">
<nav class="chip-bar" aria-label="Saring peran">
<a href="<?= e(url('pages/pengguna.php')) ?>" class="chip<?= $f_peran === '' ? ' aktif' : '' ?>">Semua <span class="chip-angka"><?= count($semua) ?></span></a>
<?php foreach (daftar_peran() as $kode => $nama): ?>
<a href="<?= e(url('pages/pengguna.php?peran=' . $kode)) ?>" class="chip<?= $f_peran === $kode ? ' aktif' : '' ?>"><?= e($nama) ?> <span class="chip-angka"><?= count(array_filter($semua, fn ($p) => $p['peran'] === $kode)) ?></span></a>
<?php endforeach; ?>
</nav>
<form method="get" class="cari" role="search">
<input type="hidden" name="peran" value="<?= e($f_peran) ?>">
<?= ikon('search', 16) ?>
<label class="sr" for="cari-pengguna">Cari pengguna</label>
<input id="cari-pengguna" type="search" name="q" value="<?= e($kata) ?>" placeholder="Nama, username, email">
</form>
</div>
<?php if (!$tampil): ?>
<?= kosong('Tidak ada pengguna yang cocok', 'Ubah saringan atau kata kunci.') ?>
<?php else: ?>
<div class="tabel-wrap">
<table class="tabel tabel-kartu">
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
<?= tombol_konfirmasi(['label' => 'Reset kata sandi ' . $p['nama'], 'ikon' => 'key-round', 'hanya_ikon' => true, 'aksi' => 'reset_sandi', 'id' => $p['id'], 'judul' => 'Reset kata sandi?', 'pesan' => 'Kata sandi ' . $p['nama'] . ' dikembalikan ke nicio123. Sampaikan ke yang bersangkutan.', 'tombol' => 'Reset kata sandi', 'aman' => true]) ?>
<?= tombol_konfirmasi(['label' => ($p['status'] === 'Aktif' ? 'Nonaktifkan ' : 'Aktifkan ') . $p['nama'], 'ikon' => 'power', 'hanya_ikon' => true, 'aksi' => 'ubah_status', 'id' => $p['id'], 'judul' => $p['status'] === 'Aktif' ? 'Nonaktifkan akun?' : 'Aktifkan akun?', 'pesan' => $p['status'] === 'Aktif' ? $p['nama'] . ' tidak bisa masuk sampai diaktifkan lagi. Datanya di proyek tetap tersimpan.' : $p['nama'] . ' bisa masuk kembali.', 'tombol' => $p['status'] === 'Aktif' ? 'Nonaktifkan' : 'Aktifkan', 'aman' => $p['status'] !== 'Aktif']) ?>
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
