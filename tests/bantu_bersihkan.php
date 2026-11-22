<?php
// Pembersihan data uji yang dipakai uji_kebutuhan_alokasi.php dan uji_serah_terima.php (hanya untuk database _test).
// Membutuhkan fungsi satu(), semua(), dan jalankan() dari berkas uji pemanggil.
//
// Sejak Sprint Backend 4A serah terima tersimpan di PostgreSQL. Transaksi serah terima hanya boleh ditambah (aplikasi tidak
// punya hak DELETE pada transaksi_barang/detail_transaksi) dan dirujuk FOREIGN KEY tanpa CASCADE, sehingga proyek uji yang
// sudah punya transaksi TIDAK bisa dihapus — sama dengan aturan di aplikasi. Proyek seperti itu diarsipkan:
//   1. sisa barang yang masih keluar dicatat kembali (kondisi Baik) agar unit tidak tertahan "Dipinjam";
//   2. alokasinya dibatalkan agar jadwal unit bebas untuk putaran uji berikutnya;
//   3. proyek diganti nama 'ARSIP-UJI <kode>' dan berstatus Dibatalkan.
// Proyek uji lain (tanpa transaksi) dihapus; kebutuhan, alokasi, kru, dan persiapannya ikut terhapus (CASCADE).
if (PHP_SAPI !== 'cli') {
    exit;
}

function arsipkan_proyek_uji(string $awalan): void
{
    $proyek = semua("SELECT DISTINCT p.id FROM proyek p JOIN transaksi_barang t ON t.proyek_id = p.id WHERE p.nama LIKE :n", ['n' => $awalan . '%']);
    foreach ($proyek as $p) {
        $sisa = semua('SELECT alokasi_id, sisa FROM v_saldo_alokasi WHERE proyek_id = :p AND sisa > 0', ['p' => $p['id']]);
        if ($sisa) {
            $trx = satu("INSERT INTO transaksi_barang (kode, proyek_id, jenis, waktu, petugas_id, penerima, catatan, dicatat_pada)
                         VALUES ('TRX-' || LPAD(nextval('seq_kode_transaksi')::text, 3, '0'), :p, 'Kembali', NOW(), 1, 'Pembersihan uji',
                                 'Pembersihan data uji otomatis', NOW()) RETURNING id", ['p' => $p['id']]);
            foreach ($sisa as $s) {
                jalankan("INSERT INTO detail_transaksi (transaksi_id, alokasi_id, kondisi, jumlah) VALUES (:t, :a, 'Baik', :j)",
                    ['t' => $trx, 'a' => $s['alokasi_id'], 'j' => $s['sisa']]);
            }
        }
        jalankan("UPDATE alokasi_barang SET status = 'Dibatalkan', diperiksa = FALSE
                  WHERE kebutuhan_id IN (SELECT id FROM kebutuhan_proyek WHERE proyek_id = :p)", ['p' => $p['id']]);
        jalankan("UPDATE proyek SET nama = 'ARSIP-UJI ' || kode, status = 'Dibatalkan' WHERE id = :p", ['p' => $p['id']]);
    }
    jalankan('DELETE FROM proyek WHERE nama LIKE :n', ['n' => $awalan . '%']);
}
