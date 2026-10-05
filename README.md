# Early Warning System

Portal autentikasi awal EWS menggunakan PHP native, SQLite, HTML, CSS, dan JavaScript tanpa framework.

## Kebutuhan

- PHP 8.1 atau lebih baru dengan ekstensi `PDO_SQLite`
- Konfigurasi pengiriman email PHP (`mail()`) untuk reset password

## Menjalankan secara lokal

Dari direktori proyek:

```sh
APP_BASE_URL=http://localhost:8000 APP_MAIL_FROM=no-reply@example.test php -S localhost:8000 -t public
```

`APP_MAIL_FROM` harus diganti dengan alamat pengirim yang diizinkan oleh konfigurasi mail server. Jika aplikasi dijalankan di jaringan publik, gunakan HTTPS dan `APP_BASE_URL` dengan URL HTTPS.

Database SQLite bernama `db_ews` dibuat otomatis di `storage/db_ews.sqlite`, di luar direktori publik. Lokasinya dapat diubah dengan variabel lingkungan `EWS_DB_PATH`. Direktori database harus dapat ditulis oleh proses PHP.

## Membuat administrator pertama

Pendaftaran publik selalu membuat akun berstatus menunggu persetujuan dan tidak dapat masuk sampai administrator mengaktifkannya. Buat akun administrator pertama lewat terminal:

```sh
php bin/create-admin.php
```

Perintah ini meminta nama, email, dan password (minimal 12 karakter; input password disembunyikan pada terminal). Akun disimpan melalui pernyataan parameter pada [`database/create_admin.sql`](./database/create_admin.sql); password di-hash oleh PHP dan tidak pernah ditulis sebagai teks biasa ke database. Perintah hanya berjalan jika belum ada administrator aktif. Setelah itu, masuk ke aplikasi dan buka **Kelola akun dan wilayah**.

Untuk mengisi akun dummy lokal sesuai contoh, jalankan `database/seed_admin.sql` sekali pada database SQLite. Akun uji adalah `admin@example.test` dengan password `Admin123!`. SQL menggunakan format hash PHP yang dapat diverifikasi aplikasi; contoh `SHA2(..., 256)` dari MySQL tidak kompatibel dengan `password_verify()` PHP maupun SQL SQLite. Login masih dapat menerima hash SHA-256 lama dan langsung memperbaruinya ke format hash PHP yang lebih kuat setelah autentikasi berhasil. Akun demo hanya untuk pengujian lokal; hapus atau ganti password sebelum deployment.

## Fitur

- Daftar akun dan login menggunakan password hash PHP.
- Reset password melalui token acak sekali pakai dengan masa berlaku 60 menit. Token disimpan dalam bentuk hash.
- Token CSRF pada seluruh formulir yang mengubah data.
- Session cookie `HttpOnly`, `SameSite=Lax`, dan `Secure` saat koneksi HTTPS.
- Pesan reset password tidak membocorkan apakah sebuah email terdaftar. Kegagalan konfigurasi/pengiriman email dicatat di log PHP.
- Administrator sistem dapat menyetujui/menangguhkan akun, menetapkan peran, mengelola hierarki wilayah, serta memberikan satu atau beberapa wilayah kepada pengguna.
- Peran yang tersedia: administrator sistem, pengelola master data, operator/analis, pimpinan/pengamat, dan petugas lapangan. Hak akses peran ditegakkan di server, bukan hanya disembunyikan di antarmuka.
- Cakupan wilayah mencakup wilayah yang ditetapkan beserta seluruh turunannya. Gunakan `user_has_region_access($user, $regionId)` untuk membatasi setiap pembacaan/operasi data wilayah pada modul berikutnya. Administrator sistem memiliki cakupan seluruh wilayah.
- Perubahan peran, status akun, cakupan, pembuatan wilayah, dan pembuatan administrator pertama dicatat pada tabel `access_audit_log`. Alasan wajib diisi untuk perubahan melalui antarmuka admin.
- Sistem mencegah administrator menonaktifkan administrator aktif terakhir.

Dashboard setelah login masih berupa placeholder. Izin untuk modul-modul berikutnya telah dipetakan menurut peran; setiap halaman/data baru tetap harus menerapkan pemeriksaan izin dan cakupan wilayah di sisi server.
