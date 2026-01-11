-- Payments module schema (Stripe Checkout, invoices, refunds)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS webhook_events;
DROP TABLE IF EXISTS refunds;
DROP TABLE IF EXISTS memberships;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS settings_payments;
DROP TABLE IF EXISTS payment_channels;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE payment_channels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) UNIQUE NOT NULL,
  label VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NOT NULL UNIQUE,
  publishable_key VARCHAR(255) NULL,
  secret_key_encrypted TEXT NULL,
  webhook_secret_encrypted TEXT NULL,
  mode ENUM('test','live') NOT NULL DEFAULT 'test',
  invoice_prefix VARCHAR(20) NOT NULL DEFAULT 'INV',
  invoice_counter_year INT NULL,
  invoice_counter INT NOT NULL DEFAULT 0,
  invoice_email_template VARCHAR(120) NULL,
  generate_pdf TINYINT(1) NOT NULL DEFAULT 1,
  last_webhook_received_at DATETIME NULL,
  last_webhook_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (channel_id) REFERENCES payment_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_type VARCHAR(50) NOT NULL,
  owner_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  label VARCHAR(150) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_files_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(30) NULL,
  user_id INT NULL,
  member_id INT NULL,
  status ENUM('pending','paid','refunded','cancelled') NOT NULL DEFAULT 'pending',
  payment_status ENUM('pending','accepted','rejected','failed','refunded') NOT NULL DEFAULT 'pending',
  fulfillment_status ENUM('pending','active','expired') NOT NULL DEFAULT 'pending',
  order_type ENUM('membership','store') NOT NULL,
  membership_period_id INT NULL,
  payment_method VARCHAR(40) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'AUD',
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  stripe_session_id VARCHAR(120) NULL,
  stripe_payment_intent_id VARCHAR(120) NULL,
  stripe_subscription_id VARCHAR(120) NULL,
  stripe_invoice_id VARCHAR(120) NULL,
  stripe_charge_id VARCHAR(120) NULL,
  channel_id INT NOT NULL,
  shipping_required TINYINT(1) NOT NULL DEFAULT 0,
  shipping_address_json TEXT NULL,
  admin_notes TEXT NULL,
  internal_notes TEXT NULL,
  paid_at DATETIME NULL,
  refunded_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  FOREIGN KEY (membership_period_id) REFERENCES membership_periods(id) ON DELETE SET NULL,
  FOREIGN KEY (channel_id) REFERENCES payment_channels(id),
  UNIQUE KEY uniq_orders_order_number (order_number),
  UNIQUE KEY uniq_stripe_session (stripe_session_id),
  INDEX idx_orders_member (member_id),
  INDEX idx_orders_payment_status (payment_status),
  INDEX idx_orders_fulfillment_status (fulfillment_status),
  INDEX idx_orders_membership_period (membership_period_id),
  INDEX idx_orders_payment_intent (stripe_payment_intent_id),
  INDEX idx_orders_subscription (stripe_subscription_id),
  INDEX idx_orders_invoice (stripe_invoice_id),
  INDEX idx_orders_charge (stripe_charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NULL,
  name VARCHAR(255) NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  is_physical TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order_items_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(30) UNIQUE NOT NULL,
  order_id INT NOT NULL,
  user_id INT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'AUD',
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_breakdown_json TEXT NULL,
  pdf_file_id INT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (pdf_file_id) REFERENCES files(id) ON DELETE SET NULL,
  INDEX idx_invoices_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE memberships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  year INT NOT NULL,
  status ENUM('paid','unpaid') NOT NULL DEFAULT 'unpaid',
  approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  order_id INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_memberships_user_year (user_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE refunds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  stripe_refund_id VARCHAR(120) NOT NULL,
  refunded_by_user_id INT NULL,
  refunded_at DATETIME NOT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (refunded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_refunds_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE webhook_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stripe_event_id VARCHAR(120) NOT NULL UNIQUE,
  type VARCHAR(120) NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  processed_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  error TEXT NULL,
  received_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO payment_channels (code, label, is_active, created_at)
VALUES ('primary', 'Primary', 1, NOW()), ('agm', 'AGM', 0, NOW())
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO settings_payments (channel_id, mode, invoice_prefix, created_at)
SELECT pc.id, 'test', 'INV', NOW()
FROM payment_channels pc
WHERE pc.code = 'primary'
ON DUPLICATE KEY UPDATE mode = VALUES(mode), invoice_prefix = VALUES(invoice_prefix);
