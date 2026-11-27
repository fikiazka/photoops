# Migrasi PhotoOps ke Supabase (PostgreSQL)

Runbook operator untuk menyiapkan database PhotoOps di project Supabase dari **Windows PowerShell**. Semua objek aplikasi
ada di **schema privat `photoops`**, yang tidak dimasukkan ke *Exposed schemas* Data API. Aplikasi memakai role
**`photoops_app`** (least privilege). Schema bawaan Supabase (`public`, `auth`, `storage`, dst.) tidak diubah.

## File

| File | Fungsi | Mengubah database? |
| --- | --- | --- |
| `00_cek.sql` | Preflight (`-v gerbang=awal`) dan cek status sesudah setiap langkah | Tidak (read-only) |
| `01_persiapan.sql` | Schema `photoops` dan role `photoops_app` (tanpa password) | Ya |
| `02_skema.sql` | 15 tabel (14 bisnis + `sesi_php`), sequence, view, hak akses; memakai `../001_schema.sql`, `../sesi_php.sql`, `hak_akses_supabase.sql` | Ya |
| `03_seed_demo.sql` | Data demo dari `../002_seed_demo.sql` (bukan salinan database development) | Ya |
| `buat_sandi_demo.php` | Password acak untuk 7 akun demo; hanya hash yang dikirim ke database | Ya (lewat psql) |
| `05_verifikasi.sql` | 15 pemeriksaan akhir | Tidak (read-only) |
| `uji_pdo_pooler.php` | Uji koneksi PDO aplikasi lewat Session Pooler sebagai `photoops_app` | Tidak (semua transaksi di-ROLLBACK) |
| `99_rollback.sql` | Menghapus schema `photoops` dan role aplikasi | Ya (destruktif, wajib konfirmasi) |

Semua skrip yang mengubah database punya pengaman. Skrip menolak berjalan bila databasenya salah, servernya bukan Supabase
(misalnya karena host koneksi kosong sehingga psql tersambung ke PostgreSQL lokal), login bukan sebagai `postgres`, nama
role tidak berawalan `photoops_`, atau objek yang akan dibuat sudah ada. Skrip juga tidak pernah berjalan di
`photoops_si3_dev` atau `photoops_si3_test`.

## Sebelum mulai

1. Dashboard Supabase → **Connect** → **Session pooler**: catat host (`aws-…-<region>.pooler.supabase.com`), port `5432`, dan
   username `postgres.<project-ref>`. Jangan pakai *Direct connection* (hanya IPv6) atau *Transaction pooler* (port
   6543, tidak mendukung prepared statement).
2. Dashboard → **Data API**: pastikan `photoops` **tidak** ada di *Exposed schemas*. PhotoOps tidak memakai Data API,
   jadi menonaktifkan **Enable Data API** juga aman dan menutup jalur REST/GraphQL sepenuhnya (keputusan operator).
3. Siapkan password manager untuk dua hal: password baru role `photoops_app` (disarankan 32 karakter huruf+angka) dan
   daftar password 7 akun demo.
4. Password admin `postgres` **tidak** ditulis di perintah, file repository, atau chat. psql akan memintanya di setiap
   langkah. Jangan menjalankan runbook ini di jendela yang sedang direkam `Start-Transcript`.

## Menyiapkan sesi PowerShell

```powershell
Set-Location 'D:\KULIAH\PBL\Codingan\photoops'
git branch --show-current                        # harus: feat/deployment-vercel-supabase
$PSQL = 'D:\PostgreSQL 17\bin\psql.exe'          # psql 15+ (butuh \if)
chcp 65001 > $null                               # keluaran UTF-8 di konsol
$env:PGSSLMODE        = 'require'                # tolak koneksi tanpa SSL
$env:PGCLIENTENCODING = 'UTF8'                   # data seed memuat karakter non-ASCII
$env:PGCONNECT_TIMEOUT = '15'
$HOSTPOOLER = '<host session pooler dari Dashboard>'
$REF        = '<project-ref>'
$ADMIN = "host=$HOSTPOOLER port=5432 dbname=postgres user=postgres.$REF"

function Invoke-LangkahPsql {
    param([string]$Nama, [string[]]$Argumen)
    & $PSQL $ADMIN -v ON_ERROR_STOP=1 @Argumen
    if ($LASTEXITCODE -ne 0) { throw "$Nama GAGAL (exit $LASTEXITCODE). Berhenti; lihat bagian Pemeriksaan kegagalan." }
    Write-Host "$Nama selesai." -ForegroundColor Green
}
```

## Urutan eksekusi dan verifikasi

Jalankan satu per satu. Lanjut ke langkah berikutnya hanya bila langkah sebelumnya selesai tanpa `throw` dan
verifikasinya sesuai.

| # | Perintah | Verifikasi sesudahnya |
| --- | --- | --- |
| 0 | `Invoke-LangkahPsql 'Preflight' @('-v','gerbang=awal','-f','database/supabase/00_cek.sql')` | `\conninfo` menyebut **SSL connection**; `Platform : Supabase`; `Preflight OK` |
| 1 | `Invoke-LangkahPsql 'Langkah 1' @('-f','database/supabase/01_persiapan.sql')` | `Invoke-LangkahPsql 'Status' @('-f','database/supabase/00_cek.sql')` → schema ada (0 objek), role login `t`, superuser/createrole/bypassrls `f`, konfigurasi `search_path=photoops; TimeZone=Asia/Jakarta; …` |
| 2 | `Invoke-LangkahPsql 'Langkah 2' @('-f','database/supabase/02_skema.sql')` | Status → `69 objek (15 tabel)`, `Tabel dgn RLS : 0`, `Hak role API : 0` |
| 3 | `Invoke-LangkahPsql 'Langkah 3' @('-f','database/supabase/03_seed_demo.sql')` | Status → `Data pengguna : 7 akun`, `Sandi seed lama : 7` (normal sebelum langkah 4) |
| 4 | lihat **Langkah 4** di bawah | Status → `Sandi seed lama : 0 akun` |
| 5 | `Invoke-LangkahPsql 'Langkah 5' @('-c','\password photoops_app')` | lihat **Langkah 5** di bawah (uji login role aplikasi) |
| 6 | `Invoke-LangkahPsql 'Verifikasi' @('-f','database/supabase/05_verifikasi.sql')` | 15 baris `OK`, lalu `Verifikasi selesai: semua pemeriksaan OK.` |
| 7 | lihat **Langkah 7** di bawah | `OK: koneksi PDO lewat Session Pooler kompatibel.` |

### Langkah 4: password akun demo

```powershell
php database/supabase/buat_sandi_demo.php | & $PSQL $ADMIN -v ON_ERROR_STOP=1
if ($LASTEXITCODE -ne 0) { throw 'Langkah 4 GAGAL: daftar password yang tampil TIDAK berlaku; jalankan ulang langkah 4.' }
```

- Daftar password tampil **sekali** di layar (STDERR) sebelum psql meminta password admin. Simpan ke password manager
  **hanya bila** psql mencetak `Langkah 4 selesai`. Bila gagal, buang daftar itu dan ulangi langkah 4 (hash lama ditimpa).
- Jangan mengalihkan STDERR ke file (`2>`), jangan menempelkan daftar ke chat/laporan, lalu bersihkan layar (`Clear-Host`).
- SQL yang dialirkan hanya berisi ASCII (username dan hash bcrypt), jadi aman untuk pipe PowerShell 5.1.

### Langkah 5: password role aplikasi dan uji login

`\password` meminta password baru dua kali dan mengirimnya ke server sudah dalam bentuk hash SCRAM, sehingga password
asli tidak tercatat di log server. Uji login lewat pooler sebagai role aplikasi:

```powershell
$APP = "host=$HOSTPOOLER port=5432 dbname=postgres user=photoops_app.$REF"
& $PSQL $APP -c 'SELECT current_user, current_setting(''search_path'') AS search_path, current_setting(''TimeZone'') AS tz, (SELECT count(*) FROM pengguna) AS akun'
```

Hasil yang benar: `photoops_app | photoops | Asia/Jakarta | 7`.

### Langkah 7: uji koneksi PDO (pola koneksi aplikasi)

```powershell
$env:PHOTOOPS_DB_HOST = $HOSTPOOLER
$env:PHOTOOPS_DB_USER = "photoops_app.$REF"
$env:PHOTOOPS_DB_SSLMODE = 'verify-full'
$env:PHOTOOPS_DB_SSLROOTCERT = 'docker/supabase-ca.crt'   # garis miring "/" (lihat catatan di bawah)
$rahasia = Read-Host 'Password photoops_app' -AsSecureString
$env:PHOTOOPS_DB_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($rahasia))
php database/supabase/uji_pdo_pooler.php
Remove-Item Env:PHOTOOPS_DB_PASSWORD; Remove-Variable rahasia
```

> **Windows: tulis path sertifikat dengan garis miring `/`**, misalnya `docker/supabase-ca.crt` atau
> `D:/KULIAH/PBL/Codingan/photoops/docker/supabase-ca.crt`. libpq (psql dan PDO) membaca `\` di string koneksi sebagai
> karakter escape, jadi `docker\supabase-ca.crt` terbaca `dockersupabase-ca.crt`. Skrip PhotoOps sekarang mengubah `\`
> menjadi `/` secara otomatis, tetapi perintah psql manual tidak. Hindari path yang memuat spasi.

Skrip membuka tiga koneksi berturut-turut, seperti tiga request PHP, lalu memeriksa:
- SSL;
- `search_path`, zona waktu, dan `statement_timeout`;
- prepared statement native berparameter;
- view `security_invoker`;
- `FOR UPDATE` dan `LOCK TABLE` (di-ROLLBACK);
- tidak ada prepared statement yang tertinggal dari koneksi sebelumnya.

Bila ada statement yang tertinggal, berarti pooler tidak mereset sesi dan nama statement PDO bisa bentrok. Catat
hasilnya untuk perubahan koneksi di Phase 2B.

## Pemeriksaan kegagalan

| Pesan | Penyebab | Tindakan |
| --- | --- | --- |
| `BERHENTI: server ini bukan Supabase` | `$ADMIN` kosong/salah sehingga tersambung ke PostgreSQL lokal | Periksa `$HOSTPOOLER`/`$REF`, lalu ulangi langkah itu. Tidak ada yang berubah. |
| `Tenant or user not found` | Username tanpa `.<project-ref>` atau host pooler salah region | Salin ulang dari Dashboard → Connect → Session pooler |
| `password authentication failed` | Password salah; atau password `photoops_app` belum diset (langkah 5) | Ulangi dengan password yang benar |
| `could not translate host name` / timeout | Host salah atau jaringan memblokir port 5432 | Periksa host; coba jaringan lain |
| `SSL ... required` / `server does not support SSL` | Bukan endpoint Supabase | Periksa host |
| `BERHENTI: schema photoops sudah ada/berisi objek` | Langkah ini atau langkah sebelumnya pernah (sebagian) berjalan | Lihat status (`00_cek.sql`), lalu ikuti tabel pemulihan |
| `BERHENTI: photoops.pengguna sudah berisi data` | Seed sudah pernah berjalan | Tidak perlu mengulang langkah 3 |
| `Verifikasi GAGAL` / `Preflight GAGAL` | Baris `GAGAL`/`MASALAH` di atasnya menyebut penyebabnya | Perbaiki sesuai baris itu; jangan lanjut |
| `permission denied` di langkah 1–3 | Hak role `postgres` di Supabase berbeda dari dugaan | Berhenti dan laporkan pesan lengkapnya (tanpa password) |

## Rollback dan pemulihan

| Situasi | Tindakan |
| --- | --- |
| Preflight (0) gagal | Belum ada yang berubah. Perbaiki penyebabnya. |
| Langkah 1 atau 3 gagal | Isinya satu transaksi, jadi tidak ada sisa. Perbaiki penyebabnya, lalu ulangi langkah itu. |
| Langkah 2 gagal | Langkah 2 terdiri dari tiga transaksi (skema, `sesi_php`, hak akses), jadi bagian yang sudah selesai tetap tersimpan. Role Data API sudah dicabut dari schema sejak langkah 1, sehingga sisa tabel tidak terjangkau. Jalankan rollback, lalu mulai lagi dari langkah 0. |
| Langkah 4 gagal | Transaksi dibatalkan dan hash lama tetap ada. Ulangi langkah 4. |
| Langkah 5–7 gagal | Database sudah lengkap; perbaiki password/koneksi, lalu ulangi langkah itu. Rollback tidak perlu. |
| Verifikasi (6) gagal pada struktur/hak | Jalankan rollback, lalu ulangi dari langkah 0. |
| Migrasi perlu dibatalkan total | `Invoke-LangkahPsql 'Rollback' @('-v','konfirmasi=HAPUS-PHOTOOPS','-f','database/supabase/99_rollback.sql')`, lalu `Invoke-LangkahPsql 'Status' @('-f','database/supabase/00_cek.sql')` → schema dan role `belum ada` |
| Sudah ada data sungguhan | Backup dulu, simpan di luar repository: `& 'D:\PostgreSQL 17\bin\pg_dump.exe' $ADMIN --schema=photoops -Fc -f "$env:USERPROFILE\photoops.dump"`. Pulihkan dengan `pg_restore`. |

`99_rollback.sql` hanya menghapus schema `photoops` dan role aplikasi. Skrip ini menolak berjalan tanpa
`-v konfirmasi=HAPUS-PHOTOOPS`, di server non-Supabase, untuk role yang tidak berawalan `photoops_` atau yang punya hak
istimewa, dan bila ada objek di schema lain (misalnya view atau foreign key di `public`) yang bergantung pada `photoops`
sehingga akan ikut terhapus. Semuanya berjalan dalam satu transaksi: bila `DROP ROLE` gagal, schema juga tidak jadi dihapus.

## Simulasi di PostgreSQL lokal

Gunakan database dan role **sementara** dengan nama lain. Role lokal `photoops_app` sudah ada dan tidak boleh disentuh.

```powershell
# contoh: -v simulasi_lokal=ya -v target_db=photoops_supabase_sim_test -v app_role=photoops_app_sim
```

## Keamanan deployment

- `database/`, `tests/`, dan `docs/` dikecualikan dari image lewat `.dockerignore`, begitu juga `config/database.local.php`
  dan file Markdown.
- `database/.htaccess` dan `tests/.htaccess` (`Require all denied`) menjadi lapisan kedua bila folder ikut terunggah ke
  server Apache.
- `buat_sandi_demo.php` dan `uji_pdo_pooler.php` hanya bisa dijalankan dari CLI.
