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
- Dashboard pasca-login menyediakan sidebar sesuai peran untuk pemantauan, kejadian, riwayat, master data bahaya/lokasi/sensor/parameter/ambang/aturan/penerima, integrasi, laporan, kesehatan sistem, pengguna, dan audit.
- Ringkasan dashboard, kejadian aktif, riwayat peringatan, pengelolaan jenis bahaya, serta administrasi pengguna/wilayah sudah tersedia. Peta dan metrik sensor belum terhubung ke data operasional; master lokasi, sensor, parameter, dan aturan masih menunggu implementasi.
- Kejadian aktif mendukung laporan manual terkontrol, pengakuan, penetapan petugas, eskalasi Waspada → Siaga → Awas, catatan tindakan, penutupan beralasan, filter, dan riwayat tindakan. Riwayat peringatan mendukung filter dan ekspor CSV yang dibatasi cakupan wilayah.
- Peringatan otomatis belum dihasilkan karena ingest sensor dan mesin evaluasi aturan belum dibuat. Laporan manual ditandai sebagai laporan awal, bukan hasil evaluasi sensor.

## Data demo pemantauan dan riwayat

Setelah aplikasi dijalankan setidaknya sekali (agar tabel akun dan wilayah dibuat), isi contoh kejadian aktif, kejadian selesai, kronologi tindakan, dan wilayah demo dengan:

```sh
sqlite3 storage/db_ews.sqlite < database/monitoring_demo.sql
```

Skrip membuat tabel kejadian/kronologi bila belum tersedia dan aman dijalankan ulang tanpa menggandakan contoh. Semua catatan demo memakai sumber `DEMO-SEED:` agar mudah dikenali. Administrator sistem dapat melihat seluruh wilayah; pengguna lain hanya melihat data yang berada dalam cakupan wilayah akunnya.

## Jenis bahaya

Katalog jenis bahaya disimpan di tabel `hazard_types` dan otomatis disiapkan aplikasi saat startup. Untuk menyiapkan katalog empat jenis EWS secara manual di SQLite:

```sh
sqlite3 storage/db_ews.sqlite < database/hazard_types.sql
```

Menu **Master data → Jenis bahaya** menyediakan tambah, lihat, ubah, nonaktifkan, dan hapus jenis bahaya. Kode tidak dapat diubah setelah dibuat. Alasan dan nilai sebelum/sesudah setiap perubahan dicatat di `hazard_type_audit_log`. Jenis yang telah dipakai kejadian tidak dapat dihapus agar referensi riwayat tetap utuh; nonaktifkan jenis tersebut untuk mencegah kejadian baru menggunakannya.

Dashboard pasca-login dan navigasi peran sudah tersedia. Peringatan otomatis, peta monitoring, dan halaman master operasional masih menunggu implementasi modul berikutnya. Setiap modul/data baru tetap harus menerapkan pemeriksaan izin dan cakupan wilayah di sisi server.

## Wilayah & lokasi

Menu **Master data → Wilayah & lokasi** (FR-03) memiliki dua tab:

- **Lokasi pantau**: tambah, filter, ubah, nonaktifkan, dan hapus lokasi dengan koordinat, elevasi/datum opsional, geometri GeoJSON opsional, pengelola, dan jenis bahaya yang dipantau.
- **Hierarki wilayah**: tambah, ubah (nama, induk, tingkat administrasi, zona waktu), dan hapus wilayah. Wilayah yang masih punya turunan, lokasi, kejadian, atau akses pengguna tidak dapat dihapus; induk tidak boleh berupa turunannya sendiri.

Akses dibatasi oleh cakupan wilayah pengguna, dan setiap perubahan beserta alasannya dicatat di `access_audit_log`. Data contoh (7 lokasi, idempoten; jalankan setelah aplikasi dibuka sekali dan `monitoring_demo.sql` dimuat):

```bash
sqlite3 storage/db_ews.sqlite < database/locations_demo.sql
```

Tab **Peta** menampilkan lokasi pada peta interaktif (seret, scroll/tombol untuk zoom, tombol ⤢ untuk menampilkan semua lokasi) dengan ikon dan warna berbeda per tipe alat/lokasi, popup detail, dan daftar lokasi di samping. Ubin peta dimuat dari `tile.openstreetmap.org` (diizinkan pada CSP `img-src`); tanpa internet, peta tetap menampilkan penanda pada latar polos.
