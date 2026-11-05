# Kasir POS (PHP + MySQL)

Aplikasi kasir (Point of Sale) berbasis web untuk kafe, rumah makan, dan toko. Admin mengatur semuanya:
produk, kategori, bahan baku, resep & HPP, stok, diskon, kupon, jadwal shift, struk, dan laporan keuangan.
Kasir (satu akun bersama) tinggal klik produk, isi nama pelanggan, lalu struk tercetak di **printer thermal**.

![Halaman kasir](docs/screenshots/03-pos-kasir.png)

> Screenshot di dokumen ini memakai data contoh (nama toko, produk, dan transaksi rekaan).

---

## Daftar isi
1. [Fitur](#1-fitur)
2. [Screenshot dan penjelasan halaman](#2-screenshot-dan-penjelasan-halaman)
3. [Kebutuhan sistem](#3-kebutuhan-sistem)
4. [Menjalankan di komputer sendiri dengan VS Code](#4-menjalankan-di-komputer-sendiri-dengan-vs-code)
5. [Upload ke shared hosting (cPanel)](#5-upload-ke-shared-hosting-cpanel)
6. [Alur pemakaian sehari-hari](#6-alur-pemakaian-sehari-hari)
7. [Printer thermal](#7-printer-thermal)
8. [Cara kerja HPP, stok, dan transparansi cetak](#8-cara-kerja-hpp-stok-dan-transparansi-cetak)
9. [Struktur folder dan database](#9-struktur-folder-dan-database)
10. [Mengatasi masalah](#10-mengatasi-masalah)
11. [Keamanan dan backup](#11-keamanan-dan-backup)

---

## 1. Fitur

### Kasir
| Fitur | Keterangan |
|---|---|
| Halaman kasir (POS) | Kartu produk bergambar, cari produk (tekan `/`), tab kategori, keranjang +/−, kupon, 4 metode bayar (Tunai, QRIS, Transfer, Debit), tombol uang pas & nominal cepat, hitung kembalian. |
| Nama pelanggan | Diisi sebelum bayar agar pesanan mudah dipanggil. Bisa dibuat wajib atau opsional. |
| Dine In / Take Away | Pilih tipe pesanan, isi nomor meja untuk Dine In. Tercetak besar di struk. |
| Nomor antrian | Otomatis per hari (1, 2, 3, ...), dicetak huruf besar di struk. |
| Stok di kartu produk | Label "Stok 31", "Sisa 6" (menipis), "Habis" (tidak bisa dipilih), atau "Bahan menipis" untuk produk dari resep. |
| Shift | Shift terdeteksi otomatis dari jam. Buka dengan kas awal, tutup dengan kas dihitung, cetak rekap shift. |
| Riwayat nota & cek stok | Lihat/cetak ulang nota, cek ketersediaan produk dan bahan. |

### Admin
| Fitur | Keterangan |
|---|---|
| Dashboard | Omset, laba kotor, jumlah nota, tren vs kemarin, peringatan stok, nota belum dicetak, **grafik** omset & laba 14 hari, nota per jam, penjualan per kategori, Dine In vs Take Away, metode bayar, produk terlaris. |
| Laporan Nota | Semua nota tersimpan: antrian, pelanggan, tipe, item, shift, metode, HPP, total, laba, dan **jumlah cetak**. Filter tanggal/shift/tipe/metode/status cetak, export CSV, cetak/PDF, cetak rekap harian ke thermal. |
| Log Cetak Struk | Setiap cetakan tercatat: waktu, cetak pertama atau ulang ke-berapa, printer thermal atau browser, akun yang mencetak. |
| Laba Rugi | Penjualan kotor − diskon − HPP − beban operasional = laba bersih. Pilihan periode cepat, laba per kategori, export CSV, cetak/PDF. |
| Rekap Shift | Omset, kas seharusnya, kas dihitung, selisih, dan cetak rekap shift ke thermal. |
| Produk | Kode, nama, kategori, harga, gambar, HPP & margin otomatis, stok, batas stok menipis. |
| Kategori | Atur sendiri: Kopi, Non Kopi, Makanan Berat, Makanan Ringan, Dessert, dll. Urutan menentukan posisi tab di kasir. |
| Bahan Baku | Ukuran beli, harga lama vs baru (selisih kenaikan), harga pokok per satuan, stok masuk, terpakai, sisa stok. |
| Resep & HPP | Racikan bahan per porsi, HPP dan margin dihitung langsung, rekomendasi harga jual sesuai target margin. |
| Stok Opname | Stok produk & bahan, peringatan menipis/habis, penyesuaian hasil hitung fisik, riwayat mutasi stok. |
| Diskon & Kupon | Diskon otomatis per kategori; kupon dengan minimal belanja, masa berlaku, dan kuota. |
| Beban Operasional | Catat sewa, listrik, gaji, gas, dll. Dipakai di Laba Rugi. |
| Jadwal Shift, Customize Struk, Printer, Akun | Pengaturan shift & nama kasir, isi struk dengan preview langsung, koneksi printer, akun kasir & admin. |

### Teknis
- Harga, diskon, kupon, HPP, dan stok **selalu dihitung ulang di server**; stok dikunci saat transaksi agar tidak minus.
- Grafik dibuat sendiri dengan SVG, tanpa CDN, sehingga tetap jalan offline.
- Struk dikirim sebagai perintah **ESC/POS** lewat USB, Serial/COM, atau Bluetooth.
- Password memakai `password_hash`, semua form dan API dilindungi token CSRF.
- Tanpa framework dan tanpa Composer; mudah dipasang di shared hosting mana pun.

---

## 2. Screenshot dan penjelasan halaman

### Login
![Login](docs/screenshots/01-login.png)
Admin dan kasir masuk dari halaman yang sama.

### Dashboard
![Dashboard](docs/screenshots/02-dashboard.png)
Kartu ringkasan hari ini dengan tren dibanding kemarin, peringatan stok (kuning), dan peringatan nota yang belum dicetak (merah).
Grafik bisa diarahkan kursor untuk melihat angka persisnya.

### Kasir (POS)
![Kasir](docs/screenshots/03-pos-kasir.png)
1. Pilih **Dine In** atau **Take Away**, isi **nama pelanggan** dan **nomor meja**.
2. Klik produk. Angka biru = jumlah dipesan. Label bawah kartu = stok (kuning menipis, merah habis).
3. Masukkan kupon bila ada, pilih metode bayar, isi uang diterima.
4. Klik **Bayar**. Tombol hanya aktif jika nama terisi, stok cukup, dan uang cukup.

Nomor **Antrian** berikutnya tampil di atas keranjang.

### Struk setelah bayar
![Preview struk](docs/screenshots/04-preview-struk.png)
Struk berisi **nomor antrian besar**, **Dine In - Meja 5**, nama pelanggan, shift, dan kasir. Jika struk dicetak lagi,
bagian atasnya otomatis bertuliskan **"CETAK ULANG KE-1"**, "KE-2", dst., sehingga struk asli dan salinan selalu bisa dibedakan.

![Riwayat cetak nota](docs/screenshots/04b-riwayat-cetak-nota.png)
Di bawah preview tampil riwayat cetak nota tersebut: kapan, cetakan ke berapa, lewat apa, dan oleh siapa.

### Laporan Nota
![Laporan nota](docs/screenshots/05-laporan-nota.png)
Semua nota tersimpan dan bisa dilihat lagi kapan saja (klik baris untuk membuka struknya).
Kolom **Cetak**: merah **Belum** = belum pernah dicetak, hijau **1x** = normal, kuning **2x/3x** = pernah dicetak ulang.
Kartu **Struk dicetak** dan **Nota belum dicetak** membantu memeriksa kejujuran transaksi.
Tombol **Cetak Rekap Harian (Thermal)** mencetak ringkasan hari itu ke printer kasir.

### Log Cetak Struk
![Log cetak](docs/screenshots/06-log-cetak.png)
Daftar setiap kejadian cetak. Cetak ulang ditandai kuning lengkap dengan akun yang mencetak.

### Laba Rugi
![Laba rugi](docs/screenshots/07-laba-rugi.png)
Format laporan laba rugi standar. Beban baru bisa dicatat langsung dari panel kanan.

### Produk
![Produk](docs/screenshots/08-produk.png)
HPP dan margin terisi otomatis dari resep. Kolom stok menunjukkan stok porsi manual atau perkiraan porsi dari bahan.

### Kategori
![Kategori](docs/screenshots/09-kategori.png)
Tambah, ubah urutan, atau sembunyikan kategori. Kategori juga dipakai untuk diskon otomatis dan laporan.

### Bahan Baku
![Bahan baku](docs/screenshots/10-bahan-baku.png)
Klik **+ Stok Masuk** saat belanja bahan: isi jumlah kemasan dan harga beli. Jika harga berubah,
kolom **Selisih** menunjukkan kenaikan, dan HPP semua produk ikut diperbarui.

### Resep & HPP
![Resep & HPP](docs/screenshots/11-resep-hpp.png)
Ringkasan racikan semua produk beserta HPP, margin, dan rekomendasi harga jual.

![Edit resep](docs/screenshots/12-resep-edit.png)
Isi takaran per porsi; biaya per bahan, total HPP, laba, dan margin dihitung langsung saat mengetik.
Tombol **Pakai harga ini** mengisi harga jual sesuai target margin.

### Stok Opname
![Stok opname](docs/screenshots/13-stok-opname.png)
Isi **Stok fisik** lalu klik **Opname** untuk menyamakan stok sistem dengan hasil hitung. Tombol −1/+5/+10 untuk penyesuaian cepat.
Semua perubahan masuk **Riwayat mutasi stok** (terjual, stok masuk, opname, koreksi). Kasir bisa membuka halaman ini dalam mode lihat saja.

### Shift, jadwal, dan rekap
| Buka shift (kasir) | Jadwal shift (admin) |
|---|---|
| ![Buka shift](docs/screenshots/14-buka-shift.png) | ![Jadwal shift](docs/screenshots/15-jadwal-shift.png) |

![Rekap shift](docs/screenshots/16-rekap-shift.png)

### Customize Struk
![Customize struk](docs/screenshots/17-customize-struk.png)
Atur informasi yang tampil (antrian, pelanggan, tipe pesanan, shift, kasir, dll.), ukuran kertas, auto cut,
cetak otomatis, dan apakah nama pelanggan wajib diisi.

### Printer thermal
| Belum terhubung | Terhubung |
|---|---|
| ![Printer belum terhubung](docs/screenshots/18-printer-belum-terhubung.png) | ![Printer terhubung](docs/screenshots/19-printer-terhubung.png) |

### Akun
![Akun](docs/screenshots/20-akun.png)

---

## 3. Kebutuhan sistem

| Komponen | Versi |
|---|---|
| PHP | 8.0 atau lebih baru, dengan extension `pdo_mysql` dan `mbstring` |
| Database | MySQL 5.7+ atau MariaDB 10.4+ |
| Web server | Apache (XAMPP/Laragon/cPanel), Nginx, atau server bawaan PHP |
| Browser kasir | **Google Chrome** atau **Microsoft Edge** terbaru (untuk koneksi printer langsung) |

---

## 4. Menjalankan di komputer sendiri dengan VS Code

VS Code adalah editor kode, jadi tetap perlu PHP dan MySQL. Cara termudah di Windows adalah memakai **XAMPP** atau **Laragon**.

### Langkah 0 — Pasang ekstensi VS Code (sekali saja)
Saat folder project dibuka, VS Code akan menawarkan ekstensi yang direkomendasikan (dari `.vscode/extensions.json`). Klik **Install All**, atau pasang manual:
- **PHP Intelephense**: autocomplete dan pengecekan kode PHP
- **PHP Debug**: debugging dengan Xdebug (opsional)
- **MySQL** (cweijan): melihat isi database langsung dari VS Code (opsional)

### Opsi A — XAMPP + VS Code (paling umum)
1. Unduh dan pasang **XAMPP** dari apachefriends.org (pilih versi PHP 8.x).
2. Buka **XAMPP Control Panel**, klik **Start** pada **Apache** dan **MySQL**.
3. Ekstrak `kasir-pos-php.zip` ke `C:\xampp\htdocs\`, sehingga menjadi `C:\xampp\htdocs\kasir-pos-php\`.
4. Di VS Code: **File → Open Folder** → pilih `C:\xampp\htdocs\kasir-pos-php`.
5. Buat database:
   1. Buka `http://localhost/phpmyadmin`.
   2. Klik **New** → nama database `kasir_pos` → collation `utf8mb4_unicode_ci` → **Create**.
   3. Pilih database `kasir_pos` → tab **Import** → pilih file `database.sql` → **Import**.
6. Cek `config.php`. Bawaan XAMPP sudah cocok (user `root`, password kosong).
7. Buka `http://localhost/kasir-pos-php/` lalu login dengan akun awal.

### Opsi B — Laragon + VS Code
1. Pasang **Laragon** (laragon.org), klik **Start All**.
2. Ekstrak project ke `C:\laragon\www\kasir-pos-php`.
3. Buka folder tersebut di VS Code.
4. Klik **Database** di Laragon (HeidiSQL/phpMyAdmin), buat database `kasir_pos`, lalu import `database.sql`.
5. Buka `http://localhost/kasir-pos-php/` (atau `http://kasir-pos-php.test` jika auto virtual host aktif).

### Opsi C — Server bawaan PHP lewat VS Code (tanpa Apache)
Cocok kalau PHP sudah terpasang dan ada di PATH (cek dengan `php -v` di terminal). MySQL tetap harus berjalan (misalnya dari XAMPP).
1. Buka folder project di VS Code.
2. Buat database dan import `database.sql` seperti Opsi A langkah 5.
3. Jalankan server dengan salah satu cara:
   - Tekan **Ctrl + Shift + B** untuk menjalankan task *"Jalankan Kasir POS"* yang sudah disiapkan di `.vscode/tasks.json`.
   - Atau buka **Terminal → New Terminal** lalu ketik `php -S localhost:8000`.
4. Buka `http://localhost:8000/`.
5. Hentikan server dengan **Ctrl + C** di terminal.

> Server bawaan PHP hanya untuk pengembangan. File `.htaccess` tidak dibaca oleh server ini, jadi jangan dipakai untuk produksi.

### Akun awal
| Peran | Username | Password |
|---|---|---|
| Admin | `admin` | `admin123` |
| Kasir | `kasir` | `kasir123` |

Segera ganti di menu **Akun**. Password awal otomatis di-hash saat login pertama.

### Setelah login pertama
Database berisi data contoh (kategori, bahan, produk, resep) agar bisa langsung dicoba. Setelah itu:
1. **Customize Struk** → isi nama toko, alamat, telepon.
2. **Jadwal Shift** → sesuaikan jam dan nama kasir.
3. **Kategori**, **Bahan Baku**, **Produk**, **Resep & HPP** → ganti data contoh dengan data toko Anda.
4. **Stok Opname** → isi stok awal produk siap jual; stok bahan lewat **Bahan Baku → + Stok Masuk**.
5. **Beban Operasional** → hapus beban contoh.
6. Login sebagai kasir → **Printer Thermal** → **Hubungkan** → **Test Print**.

---

## 5. Upload ke shared hosting (cPanel)

Contoh berikut memakai cPanel. Panel lain (DirectAdmin, hPanel Hostinger, Plesk) langkahnya mirip.

### Langkah 1 — Atur versi PHP
cPanel → **Select PHP Version** atau **MultiPHP Manager** → pilih **PHP 8.0 atau lebih baru**.
Pastikan extension `pdo_mysql` dan `mbstring` dicentang.

### Langkah 2 — Buat database dan user
1. cPanel → **MySQL® Databases**.
2. **Create New Database**: misalnya `kasir`. Nama lengkapnya akan menjadi `namaakun_kasir`.
3. **Add New User**: misalnya `kasir` dengan password kuat. Nama lengkapnya `namaakun_kasir`.
4. **Add User To Database**: pilih user dan database tadi → centang **ALL PRIVILEGES** → **Make Changes**.

Catat nama database, nama user, dan password.

### Langkah 3 — Import database
1. cPanel → **phpMyAdmin**.
2. Klik database `namaakun_kasir` di panel kiri.
3. Tab **Import** → pilih `database.sql` → **Import** (atau **Go**).
4. Pastikan muncul tabel `users`, `products`, `shift_configs`, `transactions`, dan lainnya.

> `database.sql` sengaja tidak berisi perintah `CREATE DATABASE`, karena di shared hosting user biasanya tidak boleh membuat database dari SQL.

### Langkah 4 — Upload file
1. cPanel → **File Manager** → buka `public_html`.
2. Tentukan alamatnya:
   - Di domain utama (`https://tokosaya.com`): upload ke `public_html`.
   - Di subfolder (`https://tokosaya.com/kasir`): buat folder `public_html/kasir` dan upload ke sana.
   - Di subdomain (`https://kasir.tokosaya.com`): buat subdomain di cPanel → **Domains/Subdomains**, lalu upload ke folder subdomain itu.
3. Klik **Upload** → pilih `kasir-pos-php.zip`.
4. Klik kanan zip → **Extract**.
5. Isi folder `kasir-pos-php` harus berada langsung di folder tujuan. Kalau hasil ekstrak menjadi `public_html/kasir-pos-php/...`, pindahkan isinya (**Select All → Move**) ke folder tujuan, atau biarkan dan akses lewat `/kasir-pos-php/`.
6. Aktifkan **Show Hidden Files** (Settings di kanan atas File Manager) untuk memastikan file `.htaccess` ikut terekstrak.
7. Hapus file zip dan folder `docs` (berisi screenshot, tidak diperlukan di server).

### Langkah 5 — Isi `config.php`
Di File Manager, klik kanan `config.php` → **Edit**:
```php
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'namaakun_kasir',
        'user'    => 'namaakun_kasir',
        'pass'    => 'password-database-anda',
        'charset' => 'utf8mb4',
    ],
];
```
Di shared hosting, host biasanya `localhost`.

### Langkah 6 — Aktifkan HTTPS (wajib untuk printer)
Koneksi printer langsung (USB/Serial/Bluetooth) hanya berjalan lewat **HTTPS**.
1. cPanel → **SSL/TLS Status** → klik **Run AutoSSL** (gratis, Let's Encrypt).
2. Setelah SSL aktif, buka `.htaccess` dan hapus tanda `#` pada blok "paksa HTTPS" agar pengunjung selalu diarahkan ke `https://`.

### Langkah 7 — Uji
1. Buka `https://domainanda.com/` (atau subfolder Anda).
2. Login admin → ganti password di **Akun**.
3. Coba buka `https://domainanda.com/config.php`, `.../database.sql`, dan `.../app/init.php`. Ketiganya harus menampilkan **403 Forbidden** atau halaman kosong. Itu tanda `.htaccess` bekerja.
4. Di komputer kasir, login → **Printer Thermal** → **Hubungkan** → **Test Print**.

### Catatan hosting
- **Zona waktu** sudah diatur ke WIB (`Asia/Jakarta`) di kode, jadi jam transaksi dan deteksi shift tetap benar walau server berada di luar negeri. Untuk WITA/WIT, ubah `date_default_timezone_set` dan `SET time_zone` di `app/init.php`.
- **Nginx** tidak membaca `.htaccess`. Kalau hosting memakai Nginx murni, minta admin hosting memblokir akses ke `config.php`, `database.sql`, folder `app/` dan `pages/`.
- **Update aplikasi**: upload file baru dan timpa file lama, tetapi **jangan timpa `config.php`** dan **jangan import ulang `database.sql`**, karena akan menghapus data.

---

## 6. Alur pemakaian sehari-hari

```
Admin (sekali di awal)                    Kasir (setiap shift)
──────────────────────────               ──────────────────────────
1. Kategori                               1. Login akun kasir
2. Bahan Baku (+ stok awal lewat           2. Pastikan printer hijau
   Stok Masuk)                            3. Buka Shift + kas awal
3. Produk + Resep & HPP                   4. Layani pembeli:
4. Stok porsi produk siap jual               Dine In/Take Away → nama →
   (Stok Opname)                             produk → bayar → struk
5. Jadwal Shift, Customize Struk          5. Tutup Shift + kas dihitung
6. Ganti password akun                       → Cetak Rekap

Admin (rutin)
──────────────────────────
Harian  : Dashboard, Laporan Nota (cek nota belum dicetak / cetak ulang)
Belanja : Bahan Baku → + Stok Masuk
Mingguan: Stok Opname (hitung fisik)
Bulanan : catat Beban Operasional → Laba Rugi
```

**Memanggil pesanan.** Struk memuat nomor antrian besar, nama pelanggan, dan Dine In/meja,
sehingga barista cukup memanggil "Antrian 49, Andi" atau mengantar ke meja 5.

**Pergantian shift.** Kasir Shift 1 menutup shift dan mencetak rekap; kasir Shift 2 membuka shift baru.
Nama kasir di struk mengikuti jadwal shift.

---

## 7. Printer thermal

### Status di pojok kanan atas
| Warna | Arti |
|---|---|
| 🟢 Hijau | Printer terhubung, nama printer ditampilkan. Struk langsung tercetak. |
| 🔴 Merah (berkedip) | Printer belum terhubung. Klik label untuk membuka halaman Printer. |
| 🟡 Kuning | Sedang menghubungkan. |
| 🔵 Biru | Mode cetak browser (lewat dialog print). |

### Jenis koneksi
| Koneksi | Cocok untuk | Catatan |
|---|---|---|
| **USB** | Printer thermal USB | Di Windows sering gagal karena printer sudah dipakai driver Windows. Kalau gagal, pakai Serial/COM atau Cetak browser. |
| **Serial / COM** | Printer USB-serial, RS232, atau Bluetooth klasik yang sudah di-pair | Paling stabil. Pilih baud rate sesuai printer (umumnya 9600). |
| **Bluetooth** | Printer portable BLE (printer kecil 58 mm) | Perlu klik **Hubungkan** lagi setelah halaman dimuat ulang. Halaman kasir tidak memuat ulang saat transaksi, jadi koneksi tetap bertahan selama melayani. |
| **Cetak browser** | Semua printer yang punya driver Windows | Set printer thermal sebagai default, ukuran kertas 58/80 mm, margin *None*, matikan *Headers and footers*. |

USB dan Serial **tersambung ulang otomatis** saat pindah halaman atau saat kabel dicolok ulang. Kalau printer dicabut, status langsung berubah merah.

### Cara menghubungkan
1. Login sebagai kasir → menu **Printer Thermal**.
2. Pilih jenis koneksi → klik **Hubungkan**.
3. Browser menampilkan daftar perangkat → pilih printer → **Connect**.
4. Status berubah hijau → klik **Test Print**.

Langkah ini cukup dilakukan sekali per komputer. Pilihan disimpan di browser.

### Bluetooth klasik di Windows (jadi port COM)
1. Nyalakan printer → Windows **Settings → Bluetooth & devices → Add device** → pilih printer (PIN biasanya `0000` atau `1234`).
2. Di aplikasi, pilih **Serial / COM** → **Hubungkan** → pilih port yang muncul.

### Lebar kertas
- 58 mm: 32 karakter per baris
- 80 mm: 48 karakter per baris

Atur di **Customize Struk**. Kalau struk terpotong atau terlalu sempit, kemungkinan lebar kertas di pengaturan tidak sesuai printer.

---

---

## 8. Cara kerja HPP, stok, dan transparansi cetak

### HPP (modal per porsi)
```
Harga per satuan = Harga beli per kemasan ÷ Isi kemasan
                   contoh: Rp 18.500 ÷ 1.000 ml = Rp 18,5 / ml
HPP produk       = Σ (takaran bahan × harga per satuan)
Margin           = (Harga jual − HPP) ÷ Harga jual
Rekomendasi      = HPP ÷ (1 − target margin), dibulatkan ke atas per Rp 1.000
```
HPP setiap item **disimpan saat transaksi terjadi**, jadi laporan laba bulan lalu tidak berubah walaupun harga bahan naik hari ini.

### Dua cara menghitung stok produk
| Cara | Cocok untuk | Perilaku di kasir |
|---|---|---|
| **Dari resep (bahan baku)** | Minuman & masakan yang diracik | Setiap terjual, bahan berkurang sesuai resep. Stok porsi = perkiraan dari bahan yang paling sedikit. Muncul label "Bahan menipis". Tidak memblokir penjualan. |
| **Stok porsi manual** | Kue titipan, minuman botol, porsi siap jual | Centang *Hitung stok porsi manual* di produk, isi stok lewat Stok Opname. Berkurang setiap terjual; tidak bisa dijual melebihi stok. |

Peringatan muncul saat stok ≤ **batas stok menipis** (diatur per produk dan per bahan) di dashboard, menu Stok Opname
(angka kuning di menu), dan kartu produk di kasir.

### Transparansi cetak struk
- Setiap kali struk dicetak, sistem mencatat **waktu, cetakan ke berapa, cara cetak, perangkat, dan akun**.
- Struk salinan otomatis bertuliskan **"CETAK ULANG KE-n"**.
- Laporan Nota menampilkan jumlah cetak per nota dan dapat difilter **Belum pernah dicetak** atau **Pernah dicetak ulang**.
- Dashboard memberi peringatan jika ada nota hari ini yang belum dicetak.
- Nota tidak bisa dihapus dari aplikasi, sehingga riwayat penjualan tetap utuh.

> Untuk cetak lewat **dialog browser**, sistem mencatat saat dialog print dibuka, karena browser tidak memberi tahu apakah
> pengguna benar-benar menekan Print. Cetak lewat **printer thermal** tercatat setelah data terkirim ke printer.

---

## 9. Struktur folder dan database

```
kasir-pos-php/
├── index.php  config.php  database.sql  .htaccess
├── .vscode/                 rekomendasi ekstensi & task "Jalankan server"
├── app/
│   ├── init.php             helper, login, shift, HPP, stok, diskon/kupon, nota
│   └── layout.php           sidebar, status printer, modal struk + riwayat cetak
├── pages/
│   ├── dashboard.php  laporan.php  labarugi.php  cetak.php  shift.php
│   ├── produk.php  kategori.php  bahan.php  resep.php  stok.php
│   ├── diskon.php  kupon.php  beban.php  jadwal.php  struk.php  printer.php  akun.php
│   ├── pos.php  riwayat.php  login.php  _crud.php
├── api/
│   ├── calc.php             hitung total keranjang
│   ├── checkout.php         simpan nota, potong stok, simpan HPP, nomor antrian
│   ├── trx.php              data struk + riwayat cetak
│   ├── print_log.php        catat setiap cetakan
│   ├── summary.php          rekap shift / harian untuk printer thermal
│   └── test.php             pengaturan struk untuk test print
├── assets/
│   ├── style.css  pos.js  printer.js  charts.js
└── docs/screenshots/        gambar README (tidak perlu di-upload ke hosting)
```

| Tabel | Isi |
|---|---|
| `users` | Akun admin dan akun kasir bersama |
| `categories` | Kategori produk |
| `products` | Produk, harga, kategori, stok porsi, batas menipis |
| `materials` | Bahan baku: ukuran kemasan, harga lama & baru, stok |
| `recipes` | Takaran bahan per porsi produk |
| `stock_logs` | Semua mutasi stok produk & bahan |
| `discounts`, `coupons` | Diskon otomatis dan kupon |
| `expenses` | Beban operasional |
| `shift_configs`, `shifts` | Jadwal shift dan riwayat buka/tutup shift |
| `transactions` | Nota: antrian, pelanggan, tipe, total, HPP, metode, jumlah cetak |
| `transaction_items` | Item per nota beserta HPP saat terjual |
| `print_logs` | Riwayat setiap cetakan struk |
| `settings` | Pengaturan toko, struk, dan kasir |

### Memperbarui dari versi sebelumnya
Struktur database versi ini berubah. Jika sebelumnya sudah memasang versi lama:
1. Backup dulu (phpMyAdmin → Export).
2. Import `database.sql` yang baru. **Semua data lama akan terhapus**, karena file ini membuat ulang tabel.
3. Timpa file aplikasi kecuali `config.php`.

---

## 10. Mengatasi masalah

| Gejala | Penyebab & solusi |
|---|---|
| `SQLSTATE[HY000] [1045] Access denied` | Username/password database di `config.php` salah. Di hosting, pastikan nama memakai awalan akun (`namaakun_...`). |
| `SQLSTATE[HY000] [1049] Unknown database` | Database belum dibuat atau nama di `config.php` salah. |
| `SQLSTATE[HY000] [2002]` | MySQL belum berjalan (Start MySQL di XAMPP) atau host salah. Coba `localhost` atau `127.0.0.1`. |
| `Table ... doesn't exist` | `database.sql` belum di-import ke database yang benar. |
| Halaman putih kosong | Error PHP tersembunyi. Cek versi PHP minimal 8.0, lalu lihat **Errors** di cPanel atau `C:\xampp\apache\logs\error.log`. |
| "Sesi tidak valid" | Halaman terlalu lama dibuka atau sesi habis. Muat ulang halaman. |
| Tidak bisa login dengan akun awal | `database.sql` belum di-import, atau password sudah pernah diganti. |
| Opsi USB/Serial/Bluetooth abu-abu | Bukan Chrome/Edge, atau halaman dibuka tanpa HTTPS (selain `localhost`). |
| Printer USB tidak muncul atau gagal "claim" | Driver Windows memakai printer. Pakai Serial/COM atau Cetak browser. |
| "Port sedang dipakai tab lain" | Aplikasi terbuka di lebih dari satu tab. Tutup tab lain lalu klik Hubungkan. |
| Struk terpotong atau terlalu sempit | Lebar kertas di Customize Struk tidak sesuai printer (58 vs 80 mm). |
| Huruf aneh di struk | Struk otomatis memakai karakter ASCII. Hindari emoji di pengaturan struk. |
| Kertas tidak terpotong | Printer tidak punya auto cutter. Matikan *Potong kertas otomatis* lalu atur baris kosong. |
| Shift terdeteksi salah | Cek jam di Jadwal Shift dan zona waktu di `app/init.php`. |
| Tombol Bayar tertulis "Isi nama pelanggan" | Nama pelanggan wajib diisi. Matikan di Customize Struk → *Wajib isi nama pelanggan* bila tidak diperlukan. |
| Produk tidak bisa diklik / "Habis" | Stok porsi manual habis. Tambah stok di Stok Opname. |
| HPP produk kosong | Produk belum punya resep, atau harga/isi kemasan bahan belum diisi. |
| Stok bahan minus | Penjualan tetap jalan walau bahan habis di sistem. Lakukan Stok Masuk atau Opname agar sesuai fisik. |
| Grafik kosong | Belum ada transaksi pada periode grafik. |

---

## 11. Keamanan dan backup

- Ganti password akun awal segera setelah instalasi.
- Gunakan HTTPS di hosting.
- `.htaccess` memblokir akses ke `config.php`, `database.sql`, `README.md`, serta folder `app/` dan `pages/`.
- **Backup rutin**: phpMyAdmin → pilih database → tab **Export** → **Go**. Simpan file `.sql` di tempat aman.
  Di cPanel juga bisa memakai menu **Backup** atau **JetBackup** bila tersedia.
- Untuk memulihkan data, import file backup tersebut ke database kosong.
