 # Product Requirements Document

## Platform Early Warning System
Master data dan monitoring realtime untuk cuaca, tornado, banjir sungai, serta pasang surut pantai dan muara.
Versi 1.0  |  5 Oktober 2026  |  Status: Draft untuk validasi kebutuhan

## Ringkasan
Dokumen ini mendefinisikan kebutuhan produk EWS terpadu yang mengelola konfigurasi lokasi, sensor, ambang peringatan, aturan eskalasi, dan kanal notifikasi melalui master data. Petugas kemudian memantau kondisi serta status peringatan secara realtime pada peta dan daftar kejadian. Sistem mendukung empat jenis bahaya: cuaca, tornado, banjir sungai, dan pasang surut pantai atau muara.
Ambang operasional, sumber data, istilah status, dan tindakan respons harus dapat dikonfigurasi oleh organisasi yang berwenang. PRD ini tidak menetapkan ambang bahaya numerik karena nilainya harus ditentukan dan disahkan oleh otoritas teknis setempat.

| Item | Rancangan |
| --- | --- |
| Pengguna utama | Administrator, analis/operator EWS, pimpinan/pengamat, petugas lapangan |
| Cakupan MVP | Master data, ingest data, evaluasi aturan, dashboard peta dan kejadian, notifikasi, audit |
| Prinsip | Konfigurasi berbasis bahaya; data bertanda waktu; status dan perubahan dapat ditelusuri |
| Asumsi awal | Integrasi melalui API, MQTT, atau impor data; mekanisme final dipilih setelah inventaris sumber data |


## Isi dokumen
- 1. Latar belakang dan tujuan
- 2. Pengguna dan kebutuhan
- 3. Ruang lingkup
- 4. Alur produk
- 5. Kebutuhan fungsional
- 6. Master data dan model informasi
- 7. Monitoring realtime dan status peringatan
- 8. Kebutuhan nonfungsional
- 9. Kriteria penerimaan dan metrik
- 10. Risiko, dependensi, dan pertanyaan terbuka

## 1. Latar belakang dan tujuan
Informasi dari sensor, prakiraan, dan laporan kejadian sering tersebar di beberapa kanal. Petugas membutuhkan satu tampilan untuk melihat kondisi terakhir, mengetahui data yang terlambat, memahami alasan suatu peringatan aktif, serta mencatat tindakan yang sudah dilakukan.

### Tujuan produk
- Menyediakan katalog konfigurasi yang konsisten untuk empat jenis EWS dan seluruh lokasi pemantauan.
- Menampilkan pembacaan terakhir, kualitas dan keterlambatan data, status bahaya, serta kejadian aktif dalam satu dashboard.
- Membantu operator meninjau, mengakui, mengeskalasi, dan menutup peringatan dengan jejak audit.
- Mendukung pengembangan aturan tanpa perubahan kode untuk parameter dan ambang yang disetujui.

### Hasil yang diharapkan
Operator dapat menjawab dalam satu layar: di mana risiko berada, apa indikator pemicunya, kapan data terakhir diterima, siapa yang sedang menangani, dan tindakan apa yang perlu dilakukan selanjutnya.

## 2. Pengguna dan kebutuhan

| Peran | Kebutuhan utama | Hak akses yang disarankan |
| --- | --- | --- |
| Administrator sistem | Mengelola pengguna, peran, integrasi, referensi, dan audit | Konfigurasi global dan akses pengguna |
| Pengelola master data | Mengelola bahaya, lokasi, sensor, ambang, kanal dan kontak | Buat, ubah, aktif/nonaktif; persetujuan sesuai kebijakan |
| Operator / analis | Memantau data, meninjau peringatan, menambah catatan dan meneruskan kejadian | Operasional; tidak mengubah ambang yang disahkan |
| Pimpinan / pengamat | Melihat ringkasan, tren, dan status penanganan | Baca dashboard dan laporan |
| Petugas lapangan | Menerima penugasan dan memperbarui progres/tindakan | Akses terbatas pada penugasan terkait |


## 3. Ruang lingkup

### Dalam MVP
- Manajemen master data untuk empat bahaya dan objek pemantauan.
- Penerimaan data terstruktur dari sensor, API, atau input manual terkontrol.
- Pemetaan nilai terukur ke aturan ambang dan status peringatan.
- Dashboard peta, daftar peringatan, ringkasan KPI, detail lokasi/sensor, dan riwayat.
- Notifikasi berdasarkan tingkat bahaya, lokasi, dan daftar penerima.
- Jejak audit atas perubahan konfigurasi dan tindakan operator.

### Di luar MVP
- Pemodelan prediksi berbasis machine learning atau simulasi hidrologi/meteorologi.
- Diseminasi publik tanpa persetujuan dan tata kelola konten.
- Kontrol otomatis terhadap sirene, pintu air, pompa, atau perangkat keselamatan.
- Aplikasi mobile native; MVP dapat menggunakan antarmuka web responsif.

## 4. Alur produk
1. Administrator menetapkan area, lokasi pantau, jenis bahaya, sensor atau sumber data, parameter, satuan, ambang, dan penerima notifikasi.
1. Sistem menerima data dan memvalidasi format, waktu pengukuran, rentang nilai, serta identitas sumber.
1. Mesin aturan mengevaluasi data terhadap konfigurasi yang aktif dan membuat atau memperbarui peringatan.
1. Operator memeriksa peta dan detail indikator, mengakui peringatan, menambahkan catatan, lalu meneruskan atau menutup kejadian.
1. Dashboard dan riwayat menyimpan perubahan status, data pemicu, penerima notifikasi, dan tindakan pengguna.

## 5. Kebutuhan fungsional

| ID | Fitur | Kebutuhan |
| --- | --- | --- |
| FR-01 | Akses dan peran | Pengguna masuk dengan autentikasi; akses dibatasi berdasarkan peran dan cakupan wilayah. |
| FR-02 | Master bahaya | Admin mengelola jenis bahaya, kode, deskripsi, ikon/warna, satuan default, serta status aktif. |
| FR-03 | Master wilayah dan lokasi | Pengelola membuat hierarki wilayah, lokasi pantau, koordinat/geometri, elevasi opsional, dan bahaya yang dipantau. |
| FR-04 | Master sensor/sumber | Sistem menyimpan identitas sumber, tipe, parameter, satuan, koordinat, status kesehatan, metode koneksi, dan waktu data terakhir. |
| FR-05 | Parameter dan ambang | Pengelola mengatur parameter, operator perbandingan, nilai ambang, durasi persistensi, histeresis/reset, prioritas, masa berlaku, dan status persetujuan. |
| FR-06 | Aturan dan eskalasi | Aturan dapat menggabungkan indikator dan menentukan tingkat, penerima, kanal, jeda pengulangan, serta eskalasi bila belum diakui. |
| FR-07 | Penerimaan dan validasi | Data masuk dicatat dengan timestamp sumber dan timestamp penerimaan; data duplikat, terlambat, tidak valid, atau di luar rentang ditandai. |
| FR-08 | Evaluasi peringatan | Sistem mengevaluasi data sesuai konfigurasi aktif dan membuat, memperbarui, meredakan, atau menyelesaikan peringatan secara deterministik. |
| FR-09 | Dashboard realtime | Dashboard menampilkan peta, ringkasan kondisi, kejadian aktif, status koneksi, dan waktu pembaruan terakhir dengan filter. |
| FR-10 | Penanganan kejadian | Operator dapat mengakui, memberi catatan, menetapkan penanggung jawab, meneruskan, dan menutup peringatan dengan alasan. |
| FR-11 | Notifikasi | Sistem mengirim notifikasi ke kanal yang dikonfigurasi dan mencatat status terkirim/gagal serta percobaan ulang. |
| FR-12 | Riwayat dan ekspor | Pengguna berizin mencari serta memfilter riwayat peringatan dan mengunduh rekap. |
| FR-13 | Audit konfigurasi | Perubahan master data merekam nilai lama/baru, waktu, pelaku, alasan, dan status persetujuan. |
| FR-14 | Kesehatan sistem | Sistem menandai sumber data yang tidak mengirim sesuai batas keterlambatan yang ditetapkan pengelola. |


## 6. Master data dan model informasi
Master data menjadi sumber konfigurasi yang dipakai mesin aturan dan dashboard. Perubahan konfigurasi yang memengaruhi keselamatan perlu memiliki versi, masa berlaku, dan persetujuan sesuai tata kelola organisasi.

| Kelompok master | Data minimum |
| --- | --- |
| Jenis bahaya | Kode, nama, deskripsi, ikon/warna, unit operasional, aktif/nonaktif |
| Wilayah dan lokasi | Kode, nama, tingkat administrasi, koordinat/geometri, zona waktu, pengelola, bahaya terkait |
| Parameter | Nama, kode, satuan, tipe nilai, sumber data, rentang valid, frekuensi harapan |
| Sensor / feed | ID, tipe, parameter, lokasi, protokol/endpoint, status, heartbeat, kontak teknis |
| Ambang dan aturan | Parameter, operator, nilai, durasi, level, prioritas, masa berlaku, versi, approver |
| Penerima dan kanal | Kelompok penerima, wilayah/bahaya, kanal, jam aktif, urutan eskalasi |
| Kode referensi | Level status, satuan, alasan penutupan, kategori tindakan, sumber laporan |


### Parameter per jenis EWS

| Jenis EWS | Contoh parameter yang dapat dikonfigurasi | Catatan konfigurasi |
| --- | --- | --- |
| Cuaca | Curah hujan, kecepatan/arah angin, suhu, kelembapan, petir, visibilitas | Sumber sensor atau feed prakiraan; durasi agregasi dan cakupan wilayah |
| Tornado | Indikator peringatan resmi, kecepatan/rotasi angin, tekanan, laporan terverifikasi | Peringatan resmi menjadi sumber otoritatif jika tersedia; indikator tidak otomatis menyatakan tornado terkonfirmasi |
| Banjir sungai | Tinggi muka air, debit, laju kenaikan, curah hujan, status pos | Ambang dapat berbeda per pos dan dikaitkan dengan profil sungai/lokasi |
| Pasang surut pantai/muara | Tinggi muka air, pasang prediksi/observasi, gelombang, angin, status rob | Lokasi pantai/muara dan datum vertikal harus jelas; dukung nilai prediksi dan observasi |

Nilai ambang, datum, periode agregasi, kombinasi indikator, dan sumber otoritatif diisi dan disahkan oleh pemilik domain sebelum sistem digunakan untuk keputusan operasional.

## 7. Monitoring realtime dan status peringatan

### Susunan dashboard
- Baris ringkasan: jumlah lokasi dipantau, peringatan aktif per tingkat, sensor sehat/terlambat, dan waktu data terbaru.
- Peta utama: lokasi berwarna menurut status tertinggi; filter jenis bahaya, wilayah, status sensor, dan rentang waktu.
- Panel kejadian: prioritas, bahaya, lokasi, pemicu, waktu mulai, umur data, status penanganan, dan petugas.
- Panel detail: tren grafik, nilai saat ini, ambang aktif beserta versinya, data terakhir, kesehatan sumber, serta catatan operator.
- Indikator pembaruan: waktu pembaruan terakhir dan status koneksi; data stale tidak boleh tampak seolah realtime.

### Siklus status

| Status | Makna dan tindakan |
| --- | --- |
| Normal | Data tersedia dan tidak memenuhi aturan peringatan aktif. |
| Waspada | Kondisi memenuhi aturan tingkat rendah; operator meninjau dan bersiap. |
| Siaga | Kondisi memenuhi aturan tingkat menengah; operator mengakui dan menjalankan prosedur terkait. |
| Awas | Kondisi memenuhi aturan tingkat tertinggi; eskalasi dan notifikasi prioritas tinggi dijalankan. |
| Diakui | Petugas menyatakan telah melihat; status bahaya tetap mengikuti data dan aturan. |
| Selesai | Kondisi pemicu mereda sesuai aturan reset atau operator menutup dengan alasan; seluruh riwayat tersimpan. |
| Data terputus | Sumber melewati batas keterlambatan; kondisi ini ditampilkan terpisah dari status bahaya. |

Nama dan jumlah tingkat bahaya dapat disesuaikan dengan SOP lokal. Perubahan status harus menjaga pemisahan antara status kondisi bahaya dan status penanganan oleh operator.

### Perilaku realtime
- Target rancangan: data terlihat pada dashboard paling lambat 60 detik setelah diterima sistem untuk aliran near real-time, dengan target lebih ketat ditentukan per sumber.
- UI memperbarui data tanpa refresh manual dan menunjukkan timestamp setiap nilai.
- Saat koneksi hilang, sistem menampilkan status koneksi dan waktu terakhir berhasil; data lama diberi penanda jelas.
- Peringatan yang sama pada lokasi dan aturan yang sama diperbarui sebagai satu kejadian selama belum selesai agar daftar tidak berulang tanpa kendali.

## 8. Kebutuhan nonfungsional

| Area | Kebutuhan awal |
| --- | --- |
| Ketersediaan | Target awal layanan 99,5% per bulan, tidak termasuk pemeliharaan terjadwal; validasi terhadap kapasitas dan SLA organisasi. |
| Kinerja | Dashboard utama terbuka ≤5 detik pada beban normal; pembaruan data diterapkan ≤60 detik setelah diterima (target awal). |
| Keamanan | TLS saat transit, kontrol akses berbasis peran, manajemen rahasia integrasi, pencatatan akses, dan kebijakan retensi. |
| Ketahanan | Antrean menahan lonjakan data; retry integrasi dan notifikasi; backup dan pemulihan sesuai RPO/RTO yang disepakati. |
| Auditabilitas | Riwayat perubahan konfigurasi dan tindakan peringatan dapat ditelusuri berdasarkan pengguna dan waktu. |
| Aksesibilitas | Warna status didampingi teks/ikon; navigasi keyboard dan kontras warna layak digunakan. |
| Skalabilitas | Penambahan wilayah, lokasi, parameter, dan sensor tidak memerlukan perubahan struktur layar untuk setiap entri baru. |
| Interoperabilitas | API terdokumentasi serta dukungan format waktu, satuan, identitas sumber, dan geospasial yang disepakati. |


## 9. Kriteria penerimaan dan metrik

| Area | Kriteria penerimaan MVP |
| --- | --- |
| Master data | Pengguna berizin dapat membuat, mengubah, menonaktifkan, mencari, dan melihat riwayat versi data master. |
| Aturan | Aturan aktif memproses sampel uji yang memenuhi dan tidak memenuhi kondisi; hasil menyertakan indikator pemicu dan versi aturan. |
| Data terlambat | Sumber melewati waktu keterlambatan konfigurasi ditandai stale/terputus dan dibedakan dari status bahaya. |
| Dashboard | Filter jenis bahaya dan wilayah memperbarui peta, KPI, dan daftar kejadian secara konsisten. |
| Penanganan | Operator dapat mengakui dan menutup kejadian dengan catatan; aksi tercatat bersama pengguna dan waktu. |
| Notifikasi | Notifikasi uji dapat dikirim ke penerima yang sesuai dan status pengiriman tersimpan. |
| Keamanan | Pengguna baca-saja tidak dapat mengubah konfigurasi; akses ditolak tercatat. |
| Audit | Perubahan ambang menampilkan pelaku, nilai sebelum/sesudah, waktu, dan alasan. |


### Metrik produk awal
- Persentase lokasi yang memiliki data valid dan segar dibanding lokasi aktif.
- Waktu dari penerimaan data ke tampilan dashboard dan pembentukan peringatan.
- Keberhasilan pengiriman notifikasi per kanal dan waktu hingga diakui.
- Jumlah peringatan duplikat, salah eskalasi, serta kejadian yang tidak memiliki penanggung jawab.
- Kelengkapan audit untuk perubahan master dan penanganan kejadian.

## 10. Risiko, dependensi, dan pertanyaan terbuka

| Topik | Risiko / keputusan yang diperlukan |
| --- | --- |
| Otoritas ambang | Siapa pemilik dan pemberi persetujuan ambang untuk tiap bahaya dan wilayah? |
| Sumber data | Sensor, feed prakiraan, peringatan resmi, dan format/protokol apa yang tersedia? |
| SOP respons | Apa definisi status, waktu pengakuan, jalur eskalasi, dan alasan penutupan yang berlaku? |
| Peta dan koordinat | Sistem koordinat, datum elevasi, batas wilayah, dan sumber peta apa yang akan dipakai? |
| Kanal notifikasi | Kanal yang disetujui, kebijakan jam aktif, daftar penerima, serta mekanisme cadangan? |
| Operasional | Target ketersediaan, RPO/RTO, retensi data, kebutuhan pusat operasi, dan dukungan di luar jam kerja? |
| Tata kelola | Apakah perubahan ambang perlu persetujuan dua pihak dan periode berlaku terjadwal? |


### Rencana rilis awal

| Tahap | Cakupan | Keluar bila |
| --- | --- | --- |
| 1. Fondasi | Peran, wilayah/lokasi, jenis bahaya, parameter, sumber data, audit | Master data inti dan integrasi uji tervalidasi |
| 2. Monitoring | Ingest, kesehatan sumber, peta, daftar kejadian, grafik detail | Operator dapat memantau data segar dan data terputus |
| 3. Peringatan | Aturan, status, notifikasi, pengakuan, eskalasi, penutupan | Skenario uji tiap bahaya lolos persetujuan pemilik domain |
| 4. Pilot | Konfigurasi lokasi terbatas, latihan operator, evaluasi metrik | SOP, ambang, dan kesiapan operasional disahkan |

Keputusan untuk memulai implementasi: tetapkan pemilik produk, pemilik domain per bahaya, lokasi pilot, sumber data yang tersedia, serta SOP dan ambang yang akan dipakai. Setelah itu, kebutuhan integrasi dan rancangan teknis dapat diturunkan menjadi backlog.
