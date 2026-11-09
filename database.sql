-- ============================================================
-- Kasir POS - skema + data awal (MySQL 5.7+ / MariaDB 10.4+)
-- Cara pakai:
--   1. Buat database kosong dulu (lokal: kasir_pos, hosting: sesuai cPanel)
--   2. Pilih database tersebut di phpMyAdmin, lalu Import file ini
--   atau via terminal: mysql -u root -p kasir_pos < database.sql
-- Akun awal: admin / admin123  dan  kasir / kasir123
-- (password otomatis di-hash saat login pertama; segera ganti)
-- PERINGATAN: import ulang file ini akan MENGHAPUS semua data.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS print_logs;
DROP TABLE IF EXISTS transaction_items;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS shifts;
DROP TABLE IF EXISTS shift_configs;
DROP TABLE IF EXISTS stock_logs;
DROP TABLE IF EXISTS recipes;
DROP TABLE IF EXISTS materials;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS coupons;
DROP TABLE IF EXISTS discounts;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;
SET FOREIGN_KEY_CHECKS = 1;

-- Pengaturan toko, struk, dan kasir (key-value)
CREATE TABLE settings (
  k VARCHAR(50) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB;

-- Akun: 1 admin + 1 akun kasir bersama
CREATE TABLE users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  username    VARCHAR(50)  NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('admin','kasir') NOT NULL DEFAULT 'kasir',
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Kategori produk (bisa diatur admin)
CREATE TABLE categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL UNIQUE,
  sort_order INT         NOT NULL DEFAULT 0,
  active     TINYINT(1)  NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Produk. track_stock=1 -> stok porsi dihitung manual (produk siap jual)
-- Produk dengan resep -> stok dihitung dari bahan baku
CREATE TABLE products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  code        VARCHAR(20)  NULL,
  name        VARCHAR(120) NOT NULL,
  price       BIGINT       NOT NULL DEFAULT 0,
  image       VARCHAR(255) NULL,
  track_stock TINYINT(1)   NOT NULL DEFAULT 0,
  stock       BIGINT       NOT NULL DEFAULT 0,
  min_stock   BIGINT       NOT NULL DEFAULT 5,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  INDEX idx_products_cat (category_id)
) ENGINE=InnoDB;

-- Diskon otomatis. category_id NULL = berlaku untuk semua produk
CREATE TABLE discounts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  category_id INT UNSIGNED NULL,
  type        ENUM('percent','nominal') NOT NULL DEFAULT 'percent',
  value       DECIMAL(12,2) NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_disc_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE coupons (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40)  NOT NULL UNIQUE,
  type        ENUM('percent','nominal') NOT NULL DEFAULT 'percent',
  value       DECIMAL(12,2) NOT NULL DEFAULT 0,
  min_belanja BIGINT       NOT NULL DEFAULT 0,
  expiry      DATE         NULL,
  quota       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = tanpa batas',
  used        INT UNSIGNED NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Bahan baku mentah, kemasan, dan es
CREATE TABLE materials (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(120) NOT NULL,
  unit      ENUM('gram','ml','pcs') NOT NULL DEFAULT 'gram',
  pack_size DECIMAL(20,2) NOT NULL DEFAULT 1 COMMENT 'isi per kemasan beli',
  price_old BIGINT       NOT NULL DEFAULT 0 COMMENT 'harga beli per kemasan sebelumnya',
  price     BIGINT       NOT NULL DEFAULT 0 COMMENT 'harga beli per kemasan terbaru',
  stock     DECIMAL(20,2) NOT NULL DEFAULT 0 COMMENT 'dalam satuan unit',
  min_stock DECIMAL(20,2) NOT NULL DEFAULT 0,
  active    TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Resep (BOM): takaran bahan per 1 porsi produk
CREATE TABLE recipes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  qty         DECIMAL(20,2) NOT NULL,
  UNIQUE KEY uq_recipe (product_id, material_id),
  CONSTRAINT fk_rec_prod FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE,
  CONSTRAINT fk_rec_mat  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Mutasi stok produk & bahan (stok masuk, terjual, opname, koreksi)
CREATE TABLE stock_logs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_type   ENUM('product','material') NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  change_qty  DECIMAL(20,2) NOT NULL,
  stock_after DECIMAL(20,2) NOT NULL,
  reason      ENUM('masuk','penjualan','opname','koreksi') NOT NULL,
  note        VARCHAR(200) NULL,
  unit_price  BIGINT       NULL COMMENT 'harga beli per kemasan saat stok masuk',
  trx_id      INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  INDEX idx_stock_item (item_type, item_id),
  INDEX idx_stock_date (created_at)
) ENGINE=InnoDB;

-- Beban operasional (sewa, listrik, gaji, dll)
CREATE TABLE expenses (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exp_date   DATE         NOT NULL,
  name       VARCHAR(120) NOT NULL,
  amount     BIGINT       NOT NULL DEFAULT 0,
  note       VARCHAR(200) NULL,
  INDEX idx_exp_date (exp_date)
) ENGINE=InnoDB;

-- Jadwal shift yang diatur admin (Shift 1/2/3 + nama kasir bertugas)
CREATE TABLE shift_configs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(30)  NOT NULL,
  start_time   TIME         NOT NULL,
  end_time     TIME         NOT NULL,
  cashier_name VARCHAR(100) NOT NULL,
  active       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Sesi shift yang dibuka/ditutup kasir
CREATE TABLE shifts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  shift_config_id INT UNSIGNED NULL,
  shift_name      VARCHAR(30)  NOT NULL,
  cashier_name    VARCHAR(100) NOT NULL,
  opened_at       DATETIME     NOT NULL,
  closed_at       DATETIME     NULL,
  start_cash      BIGINT       NOT NULL DEFAULT 0,
  end_cash        BIGINT       NULL,
  expected_cash   BIGINT       NULL,
  CONSTRAINT fk_shift_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_shift_open (user_id, closed_at)
) ENGINE=InnoDB;

-- Nota / transaksi
CREATE TABLE transactions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no              VARCHAR(30)  NOT NULL UNIQUE,
  queue_no        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'nomor antrian harian',
  customer_name   VARCHAR(60)  NULL,
  customer_phone  VARCHAR(20)  NULL COMMENT 'no. WhatsApp pelanggan (628xx) untuk kirim struk',
  order_type      VARCHAR(20)  NOT NULL DEFAULT 'Take Away',
  table_no        VARCHAR(10)  NULL,
  user_id         INT UNSIGNED NOT NULL,
  shift_id        INT UNSIGNED NOT NULL,
  shift_name      VARCHAR(30)  NOT NULL,
  cashier_name    VARCHAR(100) NOT NULL,
  created_at      DATETIME     NOT NULL,
  gross           BIGINT       NOT NULL DEFAULT 0,
  auto_disc       BIGINT       NOT NULL DEFAULT 0,
  coupon_code     VARCHAR(40)  NULL,
  coupon_disc     BIGINT       NOT NULL DEFAULT 0,
  total           BIGINT       NOT NULL DEFAULT 0,
  hpp_total       BIGINT       NOT NULL DEFAULT 0 COMMENT 'modal bahan saat terjual',
  method          VARCHAR(20)  NOT NULL DEFAULT 'Tunai',
  paid            BIGINT       NOT NULL DEFAULT 0,
  change_amt      BIGINT       NOT NULL DEFAULT 0,
  print_count     INT UNSIGNED NOT NULL DEFAULT 0,
  last_printed_at DATETIME     NULL,
  CONSTRAINT fk_trx_user  FOREIGN KEY (user_id)  REFERENCES users(id),
  CONSTRAINT fk_trx_shift FOREIGN KEY (shift_id) REFERENCES shifts(id),
  INDEX idx_trx_date (created_at),
  INDEX idx_trx_shift (shift_id)
) ENGINE=InnoDB;

CREATE TABLE transaction_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trx_id     INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  name       VARCHAR(120) NOT NULL,
  category   VARCHAR(60)  NULL,
  qty        BIGINT UNSIGNED NOT NULL,
  price      BIGINT       NOT NULL,
  disc       BIGINT       NOT NULL DEFAULT 0 COMMENT 'diskon per unit',
  dname      VARCHAR(120) NULL,
  hpp        BIGINT       NOT NULL DEFAULT 0 COMMENT 'modal per unit',
  total      BIGINT       NOT NULL,
  CONSTRAINT fk_item_trx FOREIGN KEY (trx_id) REFERENCES transactions(id) ON DELETE CASCADE,
  INDEX idx_item_trx (trx_id)
) ENGINE=InnoDB;

-- Setiap kali struk dicetak (termasuk cetak ulang) tercatat di sini
CREATE TABLE print_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trx_id     INT UNSIGNED NOT NULL,
  copy_no    INT UNSIGNED NOT NULL COMMENT '1 = cetak pertama, 2+ = cetak ulang',
  method     VARCHAR(20)  NOT NULL COMMENT 'thermal / browser',
  device     VARCHAR(100) NULL,
  user_id    INT UNSIGNED NULL,
  printed_at DATETIME     NOT NULL,
  CONSTRAINT fk_print_trx FOREIGN KEY (trx_id) REFERENCES transactions(id) ON DELETE CASCADE,
  INDEX idx_print_date (printed_at)
) ENGINE=InnoDB;

-- ================= DATA AWAL =================
INSERT INTO users (name, username, password, role) VALUES
  ('Administrator', 'admin', 'plain:admin123', 'admin'),
  ('Kasir',         'kasir', 'plain:kasir123', 'kasir');

INSERT INTO shift_configs (name, start_time, end_time, cashier_name) VALUES
  ('Shift 1', '07:00:00', '15:00:00', 'Kasir Pagi'),
  ('Shift 2', '15:00:00', '23:00:00', 'Kasir Sore'),
  ('Shift 3', '23:00:00', '07:00:00', 'Kasir Malam');

INSERT INTO settings (k, v) VALUES
  ('store_name', 'Toko Saya'), ('store_address', 'Jl. Contoh No. 1, Kota'), ('store_phone', '0812-0000-0000'),
  ('header_note', ''), ('footer', 'Terima kasih atas kunjungan Anda'),
  ('footer2', 'Barang yang sudah dibeli tidak dapat dikembalikan'), ('paper', '58'),
  ('show_address', '1'), ('show_phone', '1'), ('show_no', '1'), ('show_date', '1'), ('show_shift', '1'),
  ('show_cashier', '1'), ('show_queue', '1'), ('show_customer', '1'), ('show_order_type', '1'),
  ('show_item_disc', '1'), ('show_payment', '1'), ('feed_lines', '3'), ('auto_cut', '1'), ('auto_print', '1'),
  ('require_customer', '1'), ('target_margin', '60'), ('schema_v', '2');

INSERT INTO categories (id, name, sort_order) VALUES
  (1, 'Kopi', 1), (2, 'Non Kopi', 2), (3, 'Makanan Berat', 3), (4, 'Makanan Ringan', 4);

INSERT INTO materials (id, name, unit, pack_size, price_old, price, stock, min_stock) VALUES
  (1,  'Biji Kopi Arabica Blend',   'gram', 1000,  180000, 200000,  2000,  500),
  (2,  'Susu Fresh Milk Full Cream','ml',   1000,   17000,  18500,  6000, 2000),
  (3,  'Sirup Gula Aren Liquid',    'ml',   1000,   22000,  25000,  1500,  300),
  (4,  'Sirup Caramel Premium',     'ml',    750,   85000,  90000,   750,  200),
  (5,  'Bubuk Matcha Japan',        'gram',  500,  120000, 120000,   400,  100),
  (6,  'Cup Plastik 16oz + Lid',    'pcs',    50,   30000,  35000,   200,   50),
  (7,  'Sedotan Steril',            'pcs',   100,   10000,  10000,   300,   50),
  (8,  'Es Batu Kristal',           'gram', 10000,  10000,  12000, 20000, 5000),
  (9,  'Croissant Frozen Dough',    'pcs',    10,   75000,  80000,    12,    5),
  (10, 'Beras',                     'gram',  5000,  65000,  70000,  8000, 2000),
  (11, 'Telur Ayam',                'pcs',    30,   55000,  58000,    40,   10),
  (12, 'Kentang Frozen',            'gram',  1000,  38000,  40000,  3000, 1000);

INSERT INTO products (id, category_id, code, name, price, image, track_stock, stock, min_stock) VALUES
  (1, 1, 'KP-01', 'Es Kopi Susu Gula Aren', 18000, '', 0, 0, 10),
  (2, 1, 'KP-02', 'Americano',              15000, '', 0, 0, 10),
  (3, 1, 'KP-03', 'Caramel Macchiato',      22000, '', 0, 0, 10),
  (4, 2, 'NK-01', 'Matcha Latte',           20000, '', 0, 0, 10),
  (5, 2, 'NK-02', 'Es Teh Lemon',           12000, '', 1, 40, 10),
  (6, 3, 'MB-01', 'Nasi Goreng Kampung',    28000, '', 0, 0, 5),
  (7, 4, 'MR-01', 'Croissant Butter',       22000, '', 0, 0, 5),
  (8, 4, 'MR-02', 'Kentang Goreng',         15000, '', 0, 0, 5),
  (9, 4, 'MR-03', 'Pisang Goreng',          14000, '', 1, 8, 10);

INSERT INTO recipes (product_id, material_id, qty) VALUES
  (1, 1, 15), (1, 2, 120), (1, 3, 25), (1, 6, 1), (1, 7, 1), (1, 8, 150),
  (2, 1, 18), (2, 6, 1), (2, 7, 1), (2, 8, 150),
  (3, 1, 15), (3, 2, 120), (3, 4, 20), (3, 6, 1), (3, 7, 1), (3, 8, 150),
  (4, 5, 15), (4, 2, 150), (4, 6, 1), (4, 7, 1), (4, 8, 150),
  (6, 10, 200), (6, 11, 1),
  (7, 9, 1),
  (8, 12, 150);

INSERT INTO discounts (name, category_id, type, value) VALUES ('Promo Non Kopi', 2, 'percent', 20);
INSERT INTO coupons (code, type, value, min_belanja) VALUES ('HEMAT10', 'percent', 10, 30000);
INSERT INTO expenses (exp_date, name, amount) VALUES
  (CURDATE(), 'Sewa Tempat (bulanan pro-rata)', 500000),
  (CURDATE(), 'Listrik & Air', 250000),
  (CURDATE(), 'Gaji Barista', 800000);
