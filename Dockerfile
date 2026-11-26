# PhotoOps - image untuk Vercel Container Runtime (beta): PHP 8.4 + Apache (mod_php).
# Vercel membangun Dockerfile di root proyek dan meneruskan request ke port 80 (atau $PORT bila diset di Project Settings).
# Kredensial TIDAK ada di image: semuanya dari environment variable Vercel (lihat docker/README.md).
FROM php:8.4-apache-trixie

# Ekstensi PostgreSQL (pdo_pgsql) dan OPcache. mbstring, json, session, dan openssl sudah ada di image resmi.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq5 libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache; \
    apt-get purge -y --auto-remove libpq-dev; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qx 'pdo_pgsql'

# Mode produksi PhotoOps: error tidak ditampilkan, session di PostgreSQL, database wajib dari environment.
ENV PORT=80 \
    PHOTOOPS_ENV=production \
    PHOTOOPS_SESSION=db

# PHP produksi + Apache: port dari $PORT, folder internal ditutup, header keamanan, tanpa daftar isi folder.
COPY docker/php-photoops.ini "$PHP_INI_DIR/conf.d/zz-photoops.ini"
COPY docker/apache-photoops.conf /etc/apache2/conf-available/photoops.conf
RUN set -eux; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf; \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf; \
    grep -q 'Listen ${PORT}' /etc/apache2/ports.conf; \
    a2enmod headers; \
    a2dismod -f autoindex status; \
    a2enconf photoops; \
    apache2ctl -t

# Root CA Supabase (sertifikat publik, bukan rahasia) untuk PHOTOOPS_DB_SSLMODE=verify-full (bawaan produksi).
# Unduh dari Dashboard Supabase -> Database Settings -> SSL, cocokkan sidik jarinya (docker/README.md), simpan sebagai
# docker/supabase-ca.crt. Build sengaja gagal bila berkas ini belum ada.
COPY docker/supabase-ca.crt /opt/photoops/supabase-ca.crt
# Pastikan berkas itu sertifikat (PEM) dan tidak memuat private key.
RUN set -eux; \
    grep -q -- '-----BEGIN CERTIFICATE-----' /opt/photoops/supabase-ca.crt; \
    if grep -qi -- 'PRIVATE KEY' /opt/photoops/supabase-ca.crt; then echo 'supabase-ca.crt memuat private key' >&2; exit 1; fi; \
    chmod 644 /opt/photoops/supabase-ca.crt

# Kode aplikasi saja (whitelist eksplisit). SQL, uji, dokumentasi, config lokal, dan berkas Docker tidak masuk ke web root.
# Halaman PHP di root didaftarkan satu per satu: berkas PHP baru di root tidak ikut ke image tanpa sengaja.
WORKDIR /var/www/html
RUN rm -rf /var/www/html/*
COPY index.php auth.php logout.php dashboard.php ./
COPY assets/ ./assets/
COPY includes/ ./includes/
COPY data/ ./data/
COPY pages/ ./pages/
COPY partials/ ./partials/
RUN set -eux; \
    chown -R root:root /var/www/html; \
    find /var/www/html -type d -exec chmod 755 {} +; \
    find /var/www/html -type f -exec chmod 644 {} +; \
    php -l /var/www/html/includes/bootstrap.php

EXPOSE 80
# CMD bawaan image: apache2-foreground (Apache di foreground, log ke stdout/stderr). SIGTERM dari Vercel menghentikan
# Apache seketika; instance hanya di-scale-in saat tidak ada traffic, dan data/session ada di PostgreSQL.
