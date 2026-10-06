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

## Parameter dan Ambang (FR-05)

- `?page=dashboard&section=parameters`: CRUD parameter terukur (kode tetap, bahaya, satuan, agregasi). Parameter yang sudah punya ambang tidak dapat dihapus, nonaktifkan saja.
- `?page=dashboard&section=thresholds`: ambang per parameter/wilayah/lokasi dengan operator, histeresis (nilai reset), persistensi, prioritas, masa berlaku, dan versi.
- Alur: draf → diajukan → disetujui/ditolak. **Pembuat tidak dapat menyetujui ambangnya sendiri** (butuh dua akun `manage_master_data`; seed memuat `admin@ews.local` dan `admin@example.test`). Ambang yang disetujui tidak diubah langsung: buat versi baru, dan saat versi baru disetujui versi lama menjadi "Digantikan".
- Seed demo: `sqlite3 storage/db_ews.sqlite < database/thresholds_demo.sql` (muat satu halaman aplikasi dulu agar tabel terbentuk).

## Status sensor di peta

Tab Peta menampilkan titik status sensor pada penanda (hijau sehat, merah terlambat, kuning pemeliharaan, biru belum ada data, abu tanpa sensor/nonaktif), daftar sensor di popup, dan filter "Status sensor".

## Aturan & eskalasi (FR-06)

- `?page=dashboard&section=rules`: aturan menggabungkan indikator (hanya ambang berstatus **disetujui** dengan bahaya yang sama) memakai mode DAN/ATAU, menghasilkan tingkat peringatan, kelompok penerima, kanal (dashboard/email/SMS/WhatsApp/push), jeda pengulangan, batas pengakuan, dan jam aktif.
- Eskalasi bila belum diakui: langkah berurutan (menit sejak peringatan, penerima, kanal). Langkah harus lebih lama dari langkah sebelumnya dan tidak lebih cepat dari batas pengakuan.
- Semua perubahan wajib beralasan, dibatasi cakupan wilayah, dan dicatat di audit. Pengiriman notifikasi nyata dan evaluasi otomatis menyusul (FR-07/08, modul Penerima notifikasi).
- Seed demo: `sqlite3 storage/db_ews.sqlite < database/rules_demo.sql` (setelah `thresholds_demo.sql`).

## Penerima notifikasi (FR-06)

Menu `?page=dashboard&section=recipients`: kelompok penerima per wilayah/bahaya (kanal, jam aktif) beserta anggota. Kontak disamarkan di daftar dan tidak masuk audit; kelompok yang dipakai aturan tidak bisa dihapus (nonaktifkan). Nama kelompok disarankan di form aturan lewat datalist. Seed: `sqlite3 storage/db_ews.sqlite < database/recipients_demo.sql`.

## Integrasi data (FR-07)

Menu `?page=dashboard&section=integrations`: token API per wilayah (hanya hash disimpan, token tampil sekali), input manual, impor CSV (`sensor_code,value,timestamp`), rentang valid dan batas keterlambatan per sensor, serta riwayat pembacaan. Pembacaan ditandai `accepted`, `late`, `duplicate`, `out_of_range`, atau `invalid`.

Endpoint: `POST /?page=api-ingest` dengan `Authorization: Bearer <token>` dan JSON `{"readings":[{"sensor_code":"...","value":1.2,"timestamp":"2026-10-06T08:00:00+07:00"}]}` (maks. 100 per permintaan). Waktu tanpa zona dibaca UTC. Gunakan HTTPS di produksi.

## Laporan & ekspor (FR-12)

Menu `?page=dashboard&section=reports` (izin `view_reports`): filter periode/wilayah/bahaya, ringkasan kejadian (per tingkat, bahaya, wilayah, hari; rata-rata waktu pengakuan dan penyelesaian), kualitas data per sensor, serta ekspor CSV (kejadian, kualitas data, pembacaan; maks. 10.000 baris, UTC, aman dari formula injection). Setiap ekspor dicatat di audit.

## Kesehatan sistem (FR-14)

Menu `?page=dashboard&section=health`: banner status keseluruhan, ringkasan kesehatan sumber (sehat, terlambat, belum ada data, pemeliharaan, nonaktif), pemeriksaan layanan (database, integritas, relasi, penyimpanan, disk, HTTPS, PHP), aktivitas ingest 1 jam/24 jam, dan tabel status sumber yang diurutkan dari yang paling terlambat. Sumber dianggap terlambat bila tidak terlihat lebih dari 2× interval yang diharapkan.

## Pengguna & akses

Menu `?page=admin`: ringkasan akun, pencarian/filter (nama, email, peran, status), tambah pengguna (tautan atur password dikirim lewat email, admin tidak pernah melihat password), ubah peran/status/wilayah, dan kirim tautan reset password. Admin tidak bisa mengubah peran atau menangguhkan akunnya sendiri. Pengiriman email membutuhkan `APP_BASE_URL` dan `APP_MAIL_FROM`; bila belum diatur, akun tetap dibuat dan tautan bisa dikirim ulang. Semua perubahan masuk audit akses.

## Audit aktivitas

Menu `?page=dashboard&section=audit` (izin `manage_access`): tampilan baca-saja yang menggabungkan audit konfigurasi/akses, jenis bahaya, dan penanganan kejadian. Filter: kata kunci, sumber, pelaku, aksi, dan periode; paginasi 25 catatan; detail JSON bisa dibuka; ekspor CSV (maks. 10.000 baris, UTC) yang juga dicatat di audit. Tidak ada fitur ubah/hapus.

## Data demo utama: Deli Serdang

`database/deli_serdang_demo.sql` (SQLite) menggantikan data demo Jawa Barat/DKI: Sumatera Utara → Kabupaten Deli Serdang → 11 kecamatan, 14 titik rawan (tornado, sungai, cuaca, pantai) dengan koordinat, 1 sensor per lokasi, serta kejadian, ambang, dan aturan yang diselaraskan. Jalankan: `sqlite3 storage/db_ews.sqlite < database/deli_serdang_demo.sql`.

## Monitoring cuaca

Menu `?page=dashboard&section=weather` (grup Pemantauan): menampilkan lokasi berbahaya **Cuaca** beserta pembacaan sensor terbaru (curah hujan mm/jam dan klasifikasinya, suhu, kelembapan, angin, tekanan). Browser memuat ulang data tiap 15 detik dari `GET /?page=api-weather-feed` (wajib login, dibatasi cakupan wilayah).

- Saat ini data berasal dari **simulasi** (`weather_dummy_feed()` di `app/weather.php`), tidak disimpan ke database.
- Untuk memakai API sensor asli, atur env `APP_WEATHER_FEED_URL` ke endpoint JSON dengan skema: `{"generated_at": "...", "stations": [{"code": "<kode lokasi>", "observed_at": "ISO8601", "rain_mm_h": 0, "temperature_c": 0, "humidity_pct": 0, "wind_kmh": 0, "wind_deg": 0, "pressure_hpa": 0}]}`. Bila gagal dibaca, tampilan kembali ke simulasi.

## Monitoring sungai

Menu `?page=dashboard&section=river` (grup Pemantauan): menampilkan lokasi berbahaya **Banjir sungai** dengan tinggi muka air (cm), tren 1 jam, hujan hulu, debit, serta status Normal/Waspada/Siaga/Awas yang dihitung dari ambang `water_level` berstatus *approved* (lokasi atau wilayah induk). Data dimuat ulang tiap 15 detik dari `GET /?page=api-river-feed` (wajib login, sesuai cakupan wilayah).

- Saat ini data berasal dari **simulasi** (`river_dummy_feed()` di `app/river.php`), tidak disimpan.
- API asli: atur env `APP_RIVER_FEED_URL` ke JSON `{"generated_at": "...", "stations": [{"code": "<kode lokasi>", "observed_at": "ISO8601", "water_level_cm": 0, "change_1h_cm": 0, "rain_upstream_mm_h": 0, "flow_m3s": 0}]}`. Bila gagal dibaca, kembali ke simulasi.

## Monitoring pasang surut

Menu **Pemantauan → Monitoring pasang surut** menampilkan pos pantai/muara (bahaya `coastal_tide`) beserta tinggi pasang terbaru. Status Normal/Waspada/Siaga/Awas dihitung dari ambang `tide_height` yang berstatus approved (cm di Parameter dikonversi ke meter).

- Endpoint JSON: `GET /?page=api-tide-feed` (wajib login, dibatasi cakupan wilayah). Halaman memperbarui diri tiap 15 detik.
- Tanpa konfigurasi, data berasal dari simulasi (tidak disimpan). Isi `APP_TIDE_FEED_URL` dengan URL http(s) API sensor asli; bila gagal, kembali ke simulasi.
- Skema JSON: `{"generated_at": "...", "stations": [{"code": "DS-PAN-PALUH-KURAU", "observed_at": "...", "tide_level_m": 1.5, "change_1h_m": 0.2, "wave_height_m": 0.4, "wind_kmh": 18}]}`

## Monitoring tornado

Menu **Pemantauan → Monitoring tornado** menampilkan titik rawan tornado (bahaya `tornado`) beserta kecepatan angin terbaru. Status dihitung dari ambang `wind_speed` (km/jam) yang berstatus approved (seed: Waspada ≥40, Siaga ≥60, Awas ≥90).

- Endpoint JSON: `GET /?page=api-tornado-feed` (wajib login, dibatasi cakupan wilayah); halaman memperbarui diri tiap 15 detik.
- Tanpa konfigurasi, data berasal dari simulasi. Isi `APP_TORNADO_FEED_URL` dengan URL http(s) API sensor asli; bila gagal, kembali ke simulasi.
- Skema JSON: `{"generated_at": "...", "stations": [{"code": "DS-TOR-PAYA-BAKUNG", "observed_at": "...", "wind_kmh": 52.5, "change_1h_kmh": -11.3, "gust_kmh": 62.9, "pressure_hpa": 1003.6}]}`
