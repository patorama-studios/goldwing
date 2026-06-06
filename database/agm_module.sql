-- AGM Registration Module
-- Tables for Annual General Meeting event setup, registration form, and order tracking.
-- Payments flow through the secondary Stripe account (account_key='agm'), set up in payments_module.sql.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS agm_registration_motorcycles;
DROP TABLE IF EXISTS agm_registration_items;
DROP TABLE IF EXISTS agm_registrations;
DROP TABLE IF EXISTS agm_form_fields;
DROP TABLE IF EXISTS agm_products;
DROP TABLE IF EXISTS agm_events;

CREATE TABLE agm_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year INT NOT NULL,
  slug VARCHAR(100) NOT NULL,
  title VARCHAR(200) NOT NULL,
  subtitle VARCHAR(200) NULL,
  hosting_chapter VARCHAR(150) NULL,
  venue_name VARCHAR(200) NULL,
  venue_address VARCHAR(255) NULL,
  venue_phone VARCHAR(40) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  registration_opens_at DATETIME NULL,
  registration_closes_at DATETIME NULL,
  late_fee_starts_at DATETIME NULL,
  description_html MEDIUMTEXT NULL,
  cover_image_path VARCHAR(255) NULL,
  contact_name VARCHAR(150) NULL,
  contact_phone VARCHAR(40) NULL,
  contact_email VARCHAR(150) NULL,
  bank_transfer_instructions MEDIUMTEXT NULL,
  allow_bank_transfer TINYINT(1) NOT NULL DEFAULT 1,
  allow_stripe TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  stripe_account_key VARCHAR(40) NOT NULL DEFAULT 'agm',
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_agm_events_year_slug (year, slug),
  INDEX idx_agm_events_year (year),
  INDEX idx_agm_events_status (status),
  INDEX idx_agm_events_is_current (is_current),
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agm_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agm_event_id INT NOT NULL,
  category ENUM('registration','merchandise','meal','custom') NOT NULL DEFAULT 'custom',
  name VARCHAR(200) NOT NULL,
  description TEXT NULL,
  early_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  late_price DECIMAL(10,2) NULL,
  member_only TINYINT(1) NOT NULL DEFAULT 0,
  non_member_only TINYINT(1) NOT NULL DEFAULT 0,
  requires_choice TINYINT(1) NOT NULL DEFAULT 0,
  choices_json TEXT NULL,
  quantity_limit INT NULL,
  per_registration_limit INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX idx_agm_products_event (agm_event_id, category, sort_order),
  FOREIGN KEY (agm_event_id) REFERENCES agm_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agm_form_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agm_event_id INT NOT NULL,
  field_key VARCHAR(80) NOT NULL,
  label VARCHAR(200) NOT NULL,
  helper_text VARCHAR(255) NULL,
  field_group ENUM('personal','bike','emergency','other') NOT NULL DEFAULT 'other',
  field_type ENUM('text','number','checkbox','select','textarea') NOT NULL DEFAULT 'text',
  options_json TEXT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_agm_form_field_key (agm_event_id, field_key),
  INDEX idx_agm_form_fields_event (agm_event_id, sort_order),
  FOREIGN KEY (agm_event_id) REFERENCES agm_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agm_registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agm_event_id INT NOT NULL,
  registration_number VARCHAR(40) NOT NULL,
  member_id INT NULL,
  user_id INT NULL,
  submitted_by_user_id INT NULL,
  attendee1_name VARCHAR(150) NOT NULL,
  attendee1_member_number VARCHAR(40) NULL,
  attendee1_is_over_65 TINYINT(1) NOT NULL DEFAULT 0,
  attendee2_name VARCHAR(150) NULL,
  attendee2_member_number VARCHAR(40) NULL,
  attendee2_is_over_65 TINYINT(1) NOT NULL DEFAULT 0,
  children_text TEXT NULL,
  contact_phone_1 VARCHAR(40) NULL,
  contact_phone_2 VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  postcode VARCHAR(20) NULL,
  email VARCHAR(150) NOT NULL,
  chapter VARCHAR(120) NULL,
  emergency_1_name VARCHAR(150) NULL,
  emergency_1_phone VARCHAR(40) NULL,
  emergency_2_name VARCHAR(150) NULL,
  emergency_2_phone VARCHAR(40) NULL,
  dietary_requirements TEXT NULL,
  custom_fields_json TEXT NULL,
  pricing_tier ENUM('early','late') NOT NULL DEFAULT 'early',
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('stripe','bank_transfer','manual','comp') NOT NULL DEFAULT 'stripe',
  payment_status ENUM('pending','awaiting_bank_transfer','paid','refunded','cancelled') NOT NULL DEFAULT 'pending',
  stripe_session_id VARCHAR(120) NULL,
  stripe_payment_intent_id VARCHAR(120) NULL,
  paid_at DATETIME NULL,
  refunded_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  admin_notes TEXT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_agm_registration_number (registration_number),
  INDEX idx_agm_registrations_event (agm_event_id, payment_status, created_at),
  INDEX idx_agm_registrations_member (member_id),
  INDEX idx_agm_registrations_email (email),
  INDEX idx_agm_registrations_stripe_session (stripe_session_id),
  INDEX idx_agm_registrations_stripe_intent (stripe_payment_intent_id),
  FOREIGN KEY (agm_event_id) REFERENCES agm_events(id) ON DELETE RESTRICT,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agm_registration_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agm_registration_id INT NOT NULL,
  agm_product_id INT NULL,
  category VARCHAR(40) NOT NULL,
  name_snapshot VARCHAR(200) NOT NULL,
  choice_label_snapshot VARCHAR(200) NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  pricing_tier_snapshot ENUM('early','late') NOT NULL DEFAULT 'early',
  quantity INT NOT NULL DEFAULT 1,
  line_total DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_agm_registration_items_reg (agm_registration_id),
  FOREIGN KEY (agm_registration_id) REFERENCES agm_registrations(id) ON DELETE CASCADE,
  FOREIGN KEY (agm_product_id) REFERENCES agm_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agm_registration_motorcycles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agm_registration_id INT NOT NULL,
  position TINYINT NOT NULL DEFAULT 1,
  owner_name VARCHAR(150) NULL,
  make VARCHAR(80) NULL,
  model VARCHAR(80) NULL,
  year_built SMALLINT NULL,
  registration_plate VARCHAR(40) NULL,
  is_trike TINYINT(1) NOT NULL DEFAULT 0,
  is_sidecar TINYINT(1) NOT NULL DEFAULT 0,
  has_trailer TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_agm_motos_reg (agm_registration_id, position),
  FOREIGN KEY (agm_registration_id) REFERENCES agm_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Ensure the AGM payment channel and its settings row exist.
-- payment_channels seed already includes the 'agm' row (see payments_module.sql);
-- this re-asserts it and adds a settings_payments row keyed to the AGM channel
-- so AGM invoice numbering and webhook health tracking work independently.
INSERT INTO payment_channels (code, label, is_active, created_at)
VALUES ('agm', 'AGM', 0, NOW())
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO settings_payments (channel_id, mode, invoice_prefix, created_at)
SELECT pc.id, 'test', 'AGM', NOW()
FROM payment_channels pc
WHERE pc.code = 'agm'
ON DUPLICATE KEY UPDATE invoice_prefix = VALUES(invoice_prefix);

-- Permission role for AGM admins (separate from membership/store admins
-- so the AGM coordinator can be granted access without seeing store finances).
INSERT IGNORE INTO roles (name) VALUES ('agm_manager');
