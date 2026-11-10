<?php
defined('ROOT') || exit;

function e($nilai): string
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $berkas = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $akar = str_replace('\\', '/', ROOT);
    $relatif = substr($berkas, strlen($akar));
    $skrip = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = rtrim(substr($skrip, 0, max(0, strlen($skrip) - strlen($relatif))), '/');
    return $base;
}

function url(string $jalur = ''): string
{
    return base_url() . '/' . ltrim($jalur, '/');
}

function aset(string $jalur): string
{
    $berkas = ROOT . '/assets/' . $jalur;
    $versi = is_file($berkas) ? filemtime($berkas) : 0;
    return url('assets/' . $jalur) . '?v=' . $versi;
}

function arahkan(string $jalur): void
{
    header('Location: ' . url($jalur));
    exit;
}

function muat_ulang(): void
{
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url()));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $kunci, string $bawaan = ''): string
{
    $nilai = $_POST[$kunci] ?? $bawaan;
    return is_string($nilai) ? trim($nilai) : $bawaan;
}

function query(string $kunci, string $bawaan = ''): string
{
    $nilai = $_GET[$kunci] ?? $bawaan;
    return is_string($nilai) ? trim($nilai) : $bawaan;
}

function flash(string $jenis, string $pesan): void
{
    $_SESSION['flash'][] = ['jenis' => $jenis, 'pesan' => $pesan];
}

function ambil_flash(): array
{
    $isi = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $isi;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function cek_csrf(): void
{
    $kiriman = $_POST['csrf'] ?? '';
    if (!is_string($kiriman) || !hash_equals(csrf_token(), $kiriman)) {
        flash('error', 'Sesi formulir sudah kedaluwarsa. Silakan ulangi.');
        muat_ulang();
    }
}

function simpan_form(string $modal, array $lama, array $galat): void
{
    $_SESSION['_form'] = ['modal' => $modal, 'lama' => $lama, 'galat' => $galat];
}

function form_state(): array
{
    static $state = null;
    if ($state === null) {
        $state = $_SESSION['_form'] ?? ['modal' => '', 'lama' => [], 'galat' => []];
        unset($_SESSION['_form']);
    }
    return $state;
}

function lingkup_form(?string $modal = null): string
{
    static $aktif = '';
    if ($modal !== null) {
        $aktif = $modal;
    }
    return $aktif;
}

function di_luar_lingkup(): bool
{
    return lingkup_form() !== '' && lingkup_form() !== modal_awal();
}

function lama(string $kunci, string $bawaan = ''): string
{
    if (di_luar_lingkup()) {
        return $bawaan;
    }
    $nilai = form_state()['lama'][$kunci] ?? $bawaan;
    return is_scalar($nilai) ? (string) $nilai : $bawaan;
}

function lama_daftar(string $kunci): array
{
    if (di_luar_lingkup()) {
        return [];
    }
    $nilai = form_state()['lama'][$kunci] ?? [];
    return is_array($nilai) ? array_map('strval', $nilai) : [];
}

function input_daftar(string $kunci): array
{
    $nilai = $_POST[$kunci] ?? [];
    if (!is_array($nilai)) {
        return [];
    }
    return array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $nilai), fn ($v) => $v !== ''));
}

function galat(string $kunci): string
{
    if (di_luar_lingkup()) {
        return '';
    }
    return form_state()['galat'][$kunci] ?? '';
}

function modal_awal(): string
{
    return form_state()['modal'];
}

function attr_galat(string $kunci): string
{
    if (galat($kunci) === '') {
        return '';
    }
    return ' aria-invalid="true" aria-describedby="galat-' . e($kunci) . '"';
}

function pesan_galat(string $kunci): string
{
    $pesan = galat($kunci);
    if ($pesan === '') {
        return '';
    }
    return '<p class="galat" id="galat-' . e($kunci) . '">' . e($pesan) . '</p>';
}

function sekarang(): string
{
    return HARI_INI . ' ' . date('H:i:s');
}

function nama_bulan(int $bulan, bool $pendek = false): string
{
    $panjang = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $singkat = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return $pendek ? $singkat[$bulan] : $panjang[$bulan];
}

function nama_hari(int $urutan): string
{
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $hari[$urutan];
}

function tgl(string $waktu, string $format = 'pendek'): string
{
    $t = strtotime($waktu);
    if ($t === false) {
        return '—';
    }
    $hari = (int) date('j', $t);
    $bulan = (int) date('n', $t);
    $tahun = date('Y', $t);
    $jam = date('H.i', $t);
    switch ($format) {
        case 'panjang':
            return nama_hari((int) date('w', $t)) . ', ' . $hari . ' ' . nama_bulan($bulan) . ' ' . $tahun;
        case 'singkat':
            return $hari . ' ' . nama_bulan($bulan, true);
        case 'jam':
            return $jam;
        case 'lengkap':
            return $hari . ' ' . nama_bulan($bulan, true) . ' ' . $tahun . ' · ' . $jam;
        case 'singkat_jam':
            return $hari . ' ' . nama_bulan($bulan, true) . ' · ' . $jam;
        default:
            return $hari . ' ' . nama_bulan($bulan, true) . ' ' . $tahun;
    }
}

function rentang_tgl(string $mulai, string $selesai): string
{
    $a = strtotime($mulai);
    $b = strtotime($selesai);
    if ($a === false || $b === false) {
        return '—';
    }
    if (date('Y-m-d', $a) === date('Y-m-d', $b)) {
        return tgl($mulai);
    }
    if (date('Y-m', $a) === date('Y-m', $b)) {
        return date('j', $a) . '–' . date('j', $b) . ' ' . nama_bulan((int) date('n', $b), true) . ' ' . date('Y', $b);
    }
    return tgl($mulai, 'singkat') . ' – ' . tgl($selesai);
}

function db(string $tabel): array
{
    return $_SESSION['db'][$tabel] ?? [];
}

function db_cari(string $tabel, $id): ?array
{
    return $_SESSION['db'][$tabel][$id] ?? null;
}

function db_saring(string $tabel, callable $syarat): array
{
    return array_filter(db($tabel), $syarat);
}

function db_tambah(string $tabel, array $data): int
{
    $baris = db($tabel);
    $id = $baris ? max(array_keys($baris)) + 1 : 1;
    $data = ['id' => $id] + $data;
    $_SESSION['db'][$tabel][$id] = $data;
    return $id;
}

function db_ubah(string $tabel, int $id, array $perubahan): void
{
    if (isset($_SESSION['db'][$tabel][$id])) {
        $_SESSION['db'][$tabel][$id] = array_merge($_SESSION['db'][$tabel][$id], $perubahan);
    }
}

function db_hapus(string $tabel, int $id): void
{
    unset($_SESSION['db'][$tabel][$id]);
}

function inisial(string $nama): string
{
    $kata = preg_split('/\s+/', trim($nama)) ?: [];
    $hasil = '';
    foreach (array_slice($kata, 0, 2) as $k) {
        $hasil .= mb_strtoupper(mb_substr($k, 0, 1));
    }
    return $hasil;
}

function nama_depan(string $nama): string
{
    $kata = preg_split('/\s+/', trim($nama)) ?: [''];
    return $kata[0];
}

function ikon(string $nama, int $ukuran = 18): string
{
    static $daftar = [
        'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'calendar-days' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/>',
        'layers' => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'camera' => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
        'arrow-left-right' => '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
        'file-clock' => '<path d="M16 22h2a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v3"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><circle cx="8" cy="16" r="6"/><path d="M9.5 17.5 8 16.25V14"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'folder-kanban' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/><path d="M8 10v4"/><path d="M12 10v2"/><path d="M16 10v6"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'pencil' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'trash-2' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'triangle-alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'circle-check' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'rotate-ccw' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
        'package' => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'user-minus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6"/>',
        'arrow-up-right' => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        'arrow-down-left' => '<path d="M17 7 7 17"/><path d="M17 17H7V7"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'list-checks' => '<path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'truck' => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'ban' => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'key-round' => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'power' => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
        'map-pin' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
    ];
    $isi = $daftar[$nama] ?? $daftar['info'];
    return '<svg class="ikon" width="' . $ukuran . '" height="' . $ukuran . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $isi . '</svg>';
}

function warna_status(string $status): string
{
    static $peta = [
        'hijau' => ['Selesai', 'Baik', 'Tersedia', 'Terpenuhi', 'Siap', 'Disetujui', 'Dikonfirmasi', 'Kembali', 'Aktif', 'Tepat waktu'],
        'biru' => ['Dijadwalkan', 'Berlangsung', 'Dalam Persiapan', 'Ditangani', 'Dipinjam', 'Hari ini', 'Keluar', 'Petugas Inventaris'],
        'kuning' => ['Maintenance', 'Sebagian', 'Menunggu', 'Direncanakan', 'Masih keluar'],
        'merah' => ['Terlambat', 'Rusak', 'Hilang', 'Dilaporkan', 'Bentrok', 'Belum Terpenuhi', 'Lewat deadline', 'Tidak Tersedia', 'Nonaktif'],
        'tinta' => ['PIC', 'Super Admin'],
    ];
    foreach ($peta as $warna => $daftar) {
        if (in_array($status, $daftar, true)) {
            return $warna;
        }
    }
    return 'abu';
}

function badge(string $status, ?string $label = null): string
{
    return '<span class="badge badge-' . warna_status($status) . '">' . e($label ?? $status) . '</span>';
}

function judul_halaman(string $judul, string $sub = '', string $aksi = ''): void
{
    echo '<div class="kepala-halaman"><div><h1>' . e($judul) . '</h1>';
    if ($sub !== '') {
        echo '<p class="sub">' . e($sub) . '</p>';
    }
    echo '</div>';
    if ($aksi !== '') {
        echo '<div class="kepala-aksi">' . $aksi . '</div>';
    }
    echo '</div>';
}

function isi_json(array $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_UNICODE));
}

function opsi(array $daftar, string $terpilih = '', string $kosong = ''): string
{
    $html = $kosong !== '' ? '<option value="">' . e($kosong) . '</option>' : '';
    foreach ($daftar as $nilai => $label) {
        $nilai = (string) $nilai;
        $html .= '<option value="' . e($nilai) . '"' . ($nilai === $terpilih ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

function cocok(string $kata, string ...$kolom): bool
{
    if ($kata === '') {
        return true;
    }
    $kata = mb_strtolower($kata);
    foreach ($kolom as $teks) {
        if (mb_strpos(mb_strtolower($teks), $kata) !== false) {
            return true;
        }
    }
    return false;
}

function persen(int $bagian, int $total): int
{
    return $total > 0 ? (int) round($bagian / $total * 100) : 0;
}

function bilah(int $nilai, string $warna = 'tinta'): string
{
    $nilai = max(0, min(100, $nilai));
    return '<span class="bilah"><span class="bilah-isi bilah-' . e($warna) . '" style="width:' . $nilai . '%"></span></span>';
}

function waktu_input(string $waktu): string
{
    $t = strtotime($waktu);
    return $t ? date('Y-m-d\TH:i', $t) : '';
}

function waktu_valid(string $nilai): ?string
{
    $t = strtotime($nilai);
    return ($nilai !== '' && $t !== false) ? date('Y-m-d H:i', $t) : null;
}

function tombol_konfirmasi(array $opsi): string
{
    $data = [
        'aksi' => $opsi['aksi'],
        'id' => $opsi['id'],
        '_judul' => $opsi['judul'],
        '_pesan' => $opsi['pesan'],
        '_tombol' => $opsi['tombol'] ?? $opsi['label'],
        '_kelas' => !empty($opsi['aman']) ? 'btn' : 'btn btn-bahaya',
    ];
    $kelas = $opsi['kelas'] ?? 'btn btn-sekunder btn-kecil';
    $ikon = !empty($opsi['ikon']) ? ikon($opsi['ikon'], 16) : '';
    if (!empty($opsi['hanya_ikon'])) {
        return '<button type="button" class="tombol-ikon tombol-ikon-kecil" data-buka="dialog-konfirmasi" data-isi="' . isi_json($data) . '" aria-label="' . e($opsi['label']) . '" title="' . e($opsi['label']) . '">' . $ikon . '</button>';
    }
    return '<button type="button" class="' . e($kelas) . '" data-buka="dialog-konfirmasi" data-isi="' . isi_json($data) . '">' . $ikon . '<span>' . e($opsi['label']) . '</span></button>';
}

function tombol_modal(string $dialog, string $label, array $data = [], string $ikon = '', string $kelas = 'btn'): string
{
    return '<button type="button" class="' . e($kelas) . '" data-buka="' . e($dialog) . '" data-isi="' . isi_json($data) . '">' . ($ikon !== '' ? ikon($ikon, 16) : '') . '<span>' . e($label) . '</span></button>';
}

function tombol_ikon_modal(string $dialog, string $label, array $data, string $ikon): string
{
    return '<button type="button" class="tombol-ikon tombol-ikon-kecil" data-buka="' . e($dialog) . '" data-isi="' . isi_json($data) . '" aria-label="' . e($label) . '" title="' . e($label) . '">' . ikon($ikon, 16) . '</button>';
}

function tab_bar(array $tab, string $aktif, string $dasar): string
{
    $html = '<nav class="tab-bar" aria-label="Bagian halaman">';
    foreach ($tab as $kunci => $label) {
        $sambung = strpos($dasar, '?') === false ? '?' : '&';
        $html .= '<a href="' . e(url($dasar . $sambung . 'tab=' . $kunci)) . '" class="tab' . ($kunci === $aktif ? ' aktif' : '') . '"' . ($kunci === $aktif ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    return $html . '</nav>';
}

function dialog_buka(string $id, string $judul, string $sub = '', string $kelas = 'dialog'): string
{
    lingkup_form($id);
    $html = '<dialog class="' . e($kelas) . '" id="' . e($id) . '" aria-labelledby="' . e($id) . '-judul">';
    $html .= '<form method="post" class="dialog-isi" novalidate>' . csrf_field();
    $html .= '<div class="dialog-kepala"><div><h2 id="' . e($id) . '-judul" data-isi-teks="_judul">' . e($judul) . '</h2>';
    if ($sub !== '') {
        $html .= '<p class="sub" data-isi-teks="_sub">' . e($sub) . '</p>';
    }
    $html .= '</div><button type="button" class="tombol-ikon" data-tutup aria-label="Tutup">' . ikon('x', 20) . '</button></div>';
    return $html . '<div class="dialog-badan">';
}

function dialog_tutup(string $tombol, string $kelas = 'btn'): string
{
    lingkup_form('');
    return '</div><div class="dialog-kaki"><button type="button" class="btn btn-sekunder" data-tutup>Batal</button><button type="submit" class="' . e($kelas) . '">' . e($tombol) . '</button></div></form></dialog>';
}

function kosong(string $judul, string $pesan, string $aksi = ''): string
{
    $html = '<div class="kosong">' . ikon('info', 22) . '<div><p class="kosong-judul">' . e($judul) . '</p><p class="kosong-pesan">' . e($pesan) . '</p></div>';
    if ($aksi !== '') {
        $html .= '<div class="kosong-aksi">' . $aksi . '</div>';
    }
    return $html . '</div>';
}
