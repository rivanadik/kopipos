-- ============================================================
-- Migrasi v2 untuk database KASIR POS yang SUDAH berjalan (data tidak dihapus)
--  1. Stok boleh jutaan sampai miliaran (kolom dilebarkan)
--  2. Kolom nomor WhatsApp pelanggan untuk fitur kirim struk
--
-- CATATAN: aplikasi versi ini menjalankan migrasi ini OTOMATIS saat halaman pertama
-- dibuka. File ini hanya cadangan jika user database tidak punya hak ALTER dan Anda
-- ingin menjalankannya manual lewat phpMyAdmin (tab SQL).
-- Jangan jalankan 2x pada baris "ADD COLUMN" (akan error "Duplicate column" - aman diabaikan).
-- ============================================================
ALTER TABLE transactions ADD COLUMN customer_phone VARCHAR(20) NULL AFTER customer_name;

ALTER TABLE products MODIFY stock BIGINT NOT NULL DEFAULT 0, MODIFY min_stock BIGINT NOT NULL DEFAULT 5;
ALTER TABLE materials
  MODIFY pack_size DECIMAL(20,2) NOT NULL DEFAULT 1 COMMENT 'isi per kemasan beli',
  MODIFY stock     DECIMAL(20,2) NOT NULL DEFAULT 0 COMMENT 'dalam satuan unit',
  MODIFY min_stock DECIMAL(20,2) NOT NULL DEFAULT 0;
ALTER TABLE recipes MODIFY qty DECIMAL(20,2) NOT NULL;
ALTER TABLE stock_logs MODIFY change_qty DECIMAL(20,2) NOT NULL, MODIFY stock_after DECIMAL(20,2) NOT NULL;
ALTER TABLE transaction_items MODIFY qty BIGINT UNSIGNED NOT NULL;

INSERT INTO settings (k, v) VALUES ('schema_v', '2') ON DUPLICATE KEY UPDATE v = '2';
