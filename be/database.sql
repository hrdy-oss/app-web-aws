CREATE DATABASE IF NOT EXISTS kasir_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kasir_db;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(80) NOT NULL,
  role ENUM('pemilik','dapur') NOT NULL DEFAULT 'pemilik',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  category ENUM('ayam','nasi','lainnya') NOT NULL DEFAULT 'lainnya',
  price INT UNSIGNED NOT NULL,
  has_part TINYINT(1) NOT NULL DEFAULT 0,      -- 1 = pelanggan memilih bagian ayam
  photo_key VARCHAR(255) NULL,                 -- key objek di S3
  is_available TINYINT(1) NOT NULL DEFAULT 1,  -- 0 = habis
  is_active TINYINT(1) NOT NULL DEFAULT 1,     -- 0 = disembunyikan
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source ENUM('langsung','whatsapp') NOT NULL DEFAULT 'langsung',
  customer_name VARCHAR(80) NULL,
  payment_method ENUM('tunai','qris') NOT NULL DEFAULT 'tunai',
  is_paid TINYINT(1) NOT NULL DEFAULT 0,
  paid_at DATETIME NULL,
  status ENUM('baru','diproses','siap','selesai','batal') NOT NULL DEFAULT 'baru',
  total INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,                -- diisi aplikasi (zona waktu Asia/Jakarta)
  updated_at DATETIME NOT NULL,
  INDEX idx_created (created_at),
  INDEX idx_status (status),
  INDEX idx_unpaid (is_paid, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  menu_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,                   -- nama saat dipesan
  part VARCHAR(30) NULL,                       -- bagian ayam: paha atas, dada, dst.
  price INT UNSIGNED NOT NULL,                 -- harga saat dipesan
  qty SMALLINT UNSIGNED NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO menu_items (name, category, price, has_part) VALUES
  ('Ayam Original', 'ayam', 7000, 1),
  ('Ayam Geprek',   'ayam', 9000, 1),
  ('Nasi',          'nasi', 3000, 0);
