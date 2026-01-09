-- Store module schema (drops existing store tables to ensure a clean install)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS store_variant_option_values;
DROP TABLE IF EXISTS store_product_option_values;
DROP TABLE IF EXISTS store_product_options;
DROP TABLE IF EXISTS store_product_images;
DROP TABLE IF EXISTS store_product_categories;
DROP TABLE IF EXISTS store_product_tags;
DROP TABLE IF EXISTS store_product_variants;
DROP TABLE IF EXISTS store_order_items;
DROP TABLE IF EXISTS store_order_discounts;
DROP TABLE IF EXISTS store_shipments;
DROP TABLE IF EXISTS store_tickets;
DROP TABLE IF EXISTS store_cart_items;
DROP TABLE IF EXISTS store_carts;
DROP TABLE IF EXISTS store_orders;
DROP TABLE IF EXISTS store_products;
DROP TABLE IF EXISTS store_categories;
DROP TABLE IF EXISTS store_tags;
DROP TABLE IF EXISTS store_discounts;
DROP TABLE IF EXISTS store_settings;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE store_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_name VARCHAR(150) NOT NULL,
  store_slug VARCHAR(80) NOT NULL,
  members_only TINYINT(1) NOT NULL DEFAULT 1,
  notification_emails TEXT NULL,
  stripe_fee_enabled TINYINT(1) NOT NULL DEFAULT 1,
  stripe_fee_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  stripe_fee_fixed DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  shipping_flat_enabled TINYINT(1) NOT NULL DEFAULT 0,
  shipping_flat_rate DECIMAL(8,2) NULL,
  shipping_free_enabled TINYINT(1) NOT NULL DEFAULT 0,
  shipping_free_threshold DECIMAL(8,2) NULL,
  pickup_enabled TINYINT(1) NOT NULL DEFAULT 0,
  pickup_instructions VARCHAR(255) NULL,
  email_logo_url VARCHAR(255) NULL,
  email_footer_text VARCHAR(255) NULL,
  support_email VARCHAR(150) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) UNIQUE NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) UNIQUE NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(200) UNIQUE NOT NULL,
  description MEDIUMTEXT NULL,
  type ENUM('physical','ticket') NOT NULL DEFAULT 'physical',
  base_price DECIMAL(10,2) NOT NULL,
  sku VARCHAR(100) NULL,
  has_variants TINYINT(1) NOT NULL DEFAULT 0,
  track_inventory TINYINT(1) NOT NULL DEFAULT 0,
  stock_quantity INT NULL,
  low_stock_threshold INT NULL,
  event_name VARCHAR(200) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  image_url VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_product_categories (
  product_id INT NOT NULL,
  category_id INT NOT NULL,
  PRIMARY KEY (product_id, category_id),
  FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES store_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_product_tags (
  product_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (product_id, tag_id),
  FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES store_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_product_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_product_option_values (
  id INT AUTO_INCREMENT PRIMARY KEY,
  option_id INT NOT NULL,
  value VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (option_id) REFERENCES store_product_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_product_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  sku VARCHAR(100) NULL,
  price_override DECIMAL(10,2) NULL,
  stock_quantity INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_variant_option_values (
  variant_id INT NOT NULL,
  option_value_id INT NOT NULL,
  PRIMARY KEY (variant_id, option_value_id),
  FOREIGN KEY (variant_id) REFERENCES store_product_variants(id) ON DELETE CASCADE,
  FOREIGN KEY (option_value_id) REFERENCES store_product_option_values(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_carts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  status ENUM('open','converted','abandoned') NOT NULL DEFAULT 'open',
  discount_code VARCHAR(50) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_store_carts_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_cart_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cart_id INT NOT NULL,
  product_id INT NOT NULL,
  variant_id INT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  title_snapshot VARCHAR(200) NOT NULL,
  variant_snapshot VARCHAR(200) NULL,
  sku_snapshot VARCHAR(100) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (cart_id) REFERENCES store_carts(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE CASCADE,
  FOREIGN KEY (variant_id) REFERENCES store_product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_discounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) UNIQUE NOT NULL,
  type ENUM('percent','fixed') NOT NULL,
  value DECIMAL(10,2) NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  max_uses INT NULL,
  used_count INT NOT NULL DEFAULT 0,
  min_spend DECIMAL(10,2) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(30) UNIQUE NOT NULL,
  user_id INT NOT NULL,
  member_id INT NULL,
  status ENUM('pending','paid','fulfilled','cancelled','refunded') NOT NULL DEFAULT 'pending',
  subtotal DECIMAL(10,2) NOT NULL,
  discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  processing_fee_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL,
  discount_code VARCHAR(50) NULL,
  discount_id INT NULL,
  fulfillment_method ENUM('shipping','pickup') NOT NULL DEFAULT 'shipping',
  shipping_name VARCHAR(150) NULL,
  shipping_address_line1 VARCHAR(150) NULL,
  shipping_address_line2 VARCHAR(150) NULL,
  shipping_city VARCHAR(100) NULL,
  shipping_state VARCHAR(100) NULL,
  shipping_postal_code VARCHAR(20) NULL,
  shipping_country VARCHAR(100) NULL,
  pickup_instructions_snapshot VARCHAR(255) NULL,
  customer_name VARCHAR(150) NULL,
  customer_email VARCHAR(150) NULL,
  stripe_session_id VARCHAR(120) NULL,
  stripe_payment_intent_id VARCHAR(120) NULL,
  paid_at DATETIME NULL,
  fulfilled_at DATETIME NULL,
  admin_notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (member_id) REFERENCES members(id),
  FOREIGN KEY (discount_id) REFERENCES store_discounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NULL,
  variant_id INT NULL,
  title_snapshot VARCHAR(200) NOT NULL,
  variant_snapshot VARCHAR(200) NULL,
  sku_snapshot VARCHAR(100) NULL,
  type ENUM('physical','ticket') NOT NULL DEFAULT 'physical',
  event_name_snapshot VARCHAR(200) NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  unit_price_final DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE SET NULL,
  FOREIGN KEY (variant_id) REFERENCES store_product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_order_discounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  discount_id INT NULL,
  code VARCHAR(50) NOT NULL,
  type ENUM('percent','fixed') NOT NULL,
  value DECIMAL(10,2) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (discount_id) REFERENCES store_discounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_shipments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  carrier VARCHAR(100) NULL,
  tracking_number VARCHAR(100) NULL,
  shipped_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_item_id INT NOT NULL,
  ticket_code VARCHAR(60) UNIQUE NOT NULL,
  status ENUM('active','redeemed') NOT NULL DEFAULT 'active',
  event_name VARCHAR(200) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (order_item_id) REFERENCES store_order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO roles (name) VALUES ('super_admin'), ('store_manager');

INSERT INTO store_settings (
  id,
  store_name,
  store_slug,
  members_only,
  notification_emails,
  stripe_fee_enabled,
  stripe_fee_percent,
  stripe_fee_fixed,
  shipping_flat_enabled,
  shipping_flat_rate,
  shipping_free_enabled,
  shipping_free_threshold,
  pickup_enabled,
  pickup_instructions,
  email_logo_url,
  email_footer_text,
  support_email,
  created_at
) VALUES (
  1,
  'Australian Goldwing Association Store',
  'store',
  1,
  '',
  1,
  0.00,
  0.00,
  0,
  NULL,
  0,
  NULL,
  0,
  'Pickup from Canberra -- we will email instructions.',
  NULL,
  'Thanks for supporting the Australian Goldwing Association.',
  NULL,
  NOW()
) ON DUPLICATE KEY UPDATE
  store_name = VALUES(store_name),
  store_slug = VALUES(store_slug),
  notification_emails = VALUES(notification_emails);
