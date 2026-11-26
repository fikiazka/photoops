<?php
// Development lokal: salin menjadi config/database.local.php (diabaikan Git), lalu isi password role photoops_si3_app.
// PHOTOOPS_DB_NAME boleh menimpa dbname (misalnya database uji).
//
// Cloud/produksi (Vercel + Supabase) TIDAK memakai berkas ini. Konfigurasi dibaca dari environment variable
// PHOTOOPS_DB_HOST, PHOTOOPS_DB_PORT, PHOTOOPS_DB_NAME, PHOTOOPS_DB_USER, PHOTOOPS_DB_PASSWORD, PHOTOOPS_DB_SSLMODE,
// PHOTOOPS_DB_SSLROOTCERT (lihat docker/README.md). Bila PHOTOOPS_ENV=production, variabel itu wajib lengkap.
return [
    'host' => 'localhost',
    'port' => '5432',
    'dbname' => 'photoops_si3_dev',
    'user' => 'photoops_si3_app',
    'password' => '',
];
