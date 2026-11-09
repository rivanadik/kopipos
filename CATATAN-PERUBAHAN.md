# Catatan perubahan (v2)

## 1. Stok jutaan sampai miliaran
- Kolom stok dilebarkan: `products.stock/min_stock` -> BIGINT; `materials.stock/min_stock/pack_size`, `recipes.qty`,
  `stock_logs.change_qty/stock_after` -> DECIMAL(20,2); `transaction_items.qty` -> BIGINT.
- Batas input 1.000.000.000.000 (1 triliun). Lebih dari itu ditolak dengan pesan jelas (bukan halaman error).
- Stok Opname, Stok Masuk bahan, dan form Produk/Bahan dibungkus penanganan error; transaksi di-rollback jika gagal.
- Angka stok di Kasir, Stok, Produk, Dashboard memakai pemisah ribuan dan tidak merusak tampilan kartu/tabel.

## 2. Bahan baku (edit)
- Form Ubah Bahan kini punya kolom **Stok saat ini** (koreksi tercatat di riwayat mutasi; bahan baru bisa diberi stok awal).
- Stok hanya ditimpa kalau kolom itu benar-benar diubah, jadi penjualan yang terjadi saat form terbuka tidak tertimpa nilai lama.
- Ubah dan Hapus bahan kini meminta konfirmasi: Ubah menampilkan nilai lama → baru; Hapus menampilkan nilai asli bahan dan peringatan jika dipakai resep.

## 3. Kirim struk ke WhatsApp (fitur baru)
- Kolom **No. WhatsApp pelanggan** (opsional) di halaman Kasir. Nomor 08xx / +62 / 62 otomatis dinormalkan.
- Tombol **Kirim ke WhatsApp** di preview struk (juga dari Riwayat/Laporan Nota). Membuka `wa.me` dengan teks struk.
- Cetak struk yang sudah ada TIDAK diubah; nomor pelanggan tidak dicetak di struk kertas.

## Cara memasang
1. Backup database. 2. Timpa file (kecuali `config.php`). 3. Buka aplikasi: migrasi DB jalan otomatis sekali.
   Jika tidak ada hak ALTER: jalankan `migrasi-v2.sql` manual. Data lama tidak dihapus.
