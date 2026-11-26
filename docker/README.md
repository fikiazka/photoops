# PhotoOps di Vercel Container Runtime

Image: `Dockerfile` di root proyek (PHP 8.4 + Apache, `php:8.4-apache-trixie`). Vercel membangun berkas ini dan
meneruskan request ke port 80, atau ke `$PORT` bila variabel itu diisi di Project Settings. Region diset ke Tokyo
(`hnd1`) di `vercel.json` agar dekat dengan database Supabase (`ap-northeast-1`).

## Isi image

- Hanya kode aplikasi (whitelist eksplisit): `index.php`, `auth.php`, `logout.php`, `dashboard.php`, `assets/`,
  `includes/`, `data/`, `pages/`, `partials/`. Halaman PHP baru di root harus ditambahkan ke `Dockerfile` secara sadar.
- Tidak masuk ke image: `database/` (SQL/seed), `tests/`, `docs/`, `config/database.local.php`, `.git`, berkas Markdown.
- Folder `includes/`, `data/`, `partials/`, `config/` ditolak Apache (403) bila dibuka lewat URL
  (`docker/apache-photoops.conf`). Berkas tersembunyi, `.sql`, `.md`, `.ini`, `.crt`, dan cadangan juga ditolak.
- `display_errors` mati; error hanya ke log container (Vercel Runtime Logs).

## Root CA Supabase (wajib sebelum build)

Produksi memakai `PHOTOOPS_DB_SSLMODE=verify-full`, yang memverifikasi sertifikat **dan** nama host Session Pooler.
Sertifikat pooler ditandatangani CA privat Supabase, jadi CA sistem tidak cukup.

1. Dashboard Supabase (project `PBL-PhotoOps`) → **Project Settings** → **Database** → bagian **SSL Configuration** →
   *Download certificate*. Biasanya bernama `prod-ca-2021.crt`. Simpan dulu di luar repository, misalnya di Downloads.
2. Cocokkan identitas dan sidik jarinya (PowerShell 5.1, tanpa alat tambahan):
   ```powershell
   $c = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 "$env:USERPROFILE\Downloads\prod-ca-2021.crt"
   $c.Subject
   (([Security.Cryptography.SHA256]::Create().ComputeHash($c.RawData)) | ForEach-Object { $_.ToString('X2') }) -join ':'
   ```
   Hasil yang benar:
   - Subject memuat `CN=Supabase Root 2021 CA, O=Supabase Inc`
   - SHA-256 `80:70:25:AD:50:D4:ED:21:9D:2C:9C:7D:29:9C:00:4F:82:4E:B0:0C:F7:F6:5A:FE:F6:07:D0:7B:72:E6:CA:FA`

   Nilai acuan ini dibaca dari rantai sertifikat yang dikirim Session Pooler pada 2026-11-25. Bila berbeda, **berhenti**
   dan jangan memakai berkas itu.
3. Pastikan formatnya PEM (libpq hanya menerima PEM):
   ```powershell
   Select-String -Path "$env:USERPROFILE\Downloads\prod-ca-2021.crt" -Pattern 'BEGIN CERTIFICATE' -Quiet   # True = PEM
   ```
   Bila `False` (format DER), ubah dulu:
   `& "C:\Program Files\Git\usr\bin\openssl.exe" x509 -inform der -in <berkas.crt> -out <berkas-pem.crt>`.
4. Salin ke `docker/supabase-ca.crt`. Ini sertifikat publik, bukan rahasia, dan boleh di-commit. Build Docker sengaja
   gagal bila berkas ini belum ada.
5. Uji bahwa berkas itu benar-benar memvalidasi Session Pooler, **tanpa password**. Verifikasi sertifikat terjadi
   sebelum autentikasi, dan `PGPASSFILE` diarahkan ke berkas kosong supaya pgpass lokal tidak terkirim. Di Windows,
   tulis `sslrootcert` dengan garis miring `/`: libpq membaca `\` sebagai karakter escape, sehingga
   `docker\supabase-ca.crt` terbaca `dockersupabase-ca.crt`.
   ```powershell
   $env:PGPASSFILE = "$env:TEMP\kosong.pgpass"
   & 'D:\PostgreSQL 17\bin\psql.exe' -w "host=aws-1-ap-northeast-1.pooler.supabase.com port=5432 dbname=postgres user=photoops_app.<project-ref> sslmode=verify-full sslrootcert=docker/supabase-ca.crt" -c 'select 1'
   Remove-Item Env:PGPASSFILE
   ```
   - `fe_sendauth: no password supplied` berarti **benar**: sertifikat dan nama host terverifikasi.
   - `certificate verify failed` berarti berkasnya salah.

## Environment variable (Vercel → Project Settings → Environment Variables)

| Nama | Wajib | Contoh nilai (nonrahasia) | Fungsi |
| --- | --- | --- | --- |
| `PHOTOOPS_DB_HOST` | ya | `aws-1-ap-northeast-1.pooler.supabase.com` | Supabase Session Pooler |
| `PHOTOOPS_DB_PORT` | tidak | `5432` | Session Pooler. Port 6543 (Transaction Pooler) ditolak. |
| `PHOTOOPS_DB_NAME` | ya | `postgres` | Database Supabase |
| `PHOTOOPS_DB_USER` | ya | `photoops_app.<project-ref>` | Role aplikasi terbatas; role admin `postgres` ditolak aplikasi |
| `PHOTOOPS_DB_PASSWORD` | ya | *(rahasia, tipe Sensitive)* | Password `photoops_app` |
| `PHOTOOPS_DB_SSLMODE` | tidak | `verify-full` | Bawaan produksi `verify-full`; `require` hanya bila CA belum tersedia (tetap terenkripsi, tanpa verifikasi host) |
| `PHOTOOPS_DB_SSLROOTCERT` | tidak | `/opt/photoops/supabase-ca.crt` | Bawaan sudah benar. Path tidak boleh memuat spasi, tanda kutip, atau `\` (di Windows `\` otomatis diubah menjadi `/`). |
| `PHOTOOPS_TANGGAL_DEMO` | tidak | `2026-11-07` | Tanggal simulasi untuk presentasi dengan data demo. Kosong = tanggal nyata (Asia/Jakarta). |
| `PHOTOOPS_ENV` | sudah di image | `production` | Jangan diubah |
| `PHOTOOPS_SESSION` | sudah di image | `db` | Session di tabel `photoops.sesi_php` |

Tanpa variabel database yang lengkap, aplikasi berhenti dengan 503 (tidak ada fallback ke database lain). Alasannya
hanya tercatat di log, tanpa nilai rahasia.

## Uji container lokal (bila Docker tersedia)

```powershell
docker build -t photoops:lokal .
# Uji tanpa database: harus 503, tanpa detail teknis, tanpa cookie session.
docker run --rm -p 8080:80 photoops:lokal
curl.exe -si http://localhost:8080/ | Select-Object -First 15
curl.exe -so NUL -w "%{http_code}\n" http://localhost:8080/includes/koneksi.php    # 403
curl.exe -so NUL -w "%{http_code}\n" http://localhost:8080/assets/css/app.css       # 200
docker run --rm photoops:lokal php -m                                               # memuat pdo_pgsql
```

Uji dengan Supabase sungguhan menulis tabel `sesi_php` (data session), jadi hanya dilakukan dengan persetujuan.
Password diberikan lewat `--env-file` di luar repository, jangan lewat argumen perintah.
