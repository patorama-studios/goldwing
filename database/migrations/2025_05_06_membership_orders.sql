ALTER TABLE orders
  MODIFY user_id INT NULL,
  ADD COLUMN order_number VARCHAR(30) NULL AFTER id,
  ADD COLUMN member_id INT NULL AFTER user_id,
  ADD COLUMN payment_status ENUM('pending','accepted','rejected','failed','refunded') NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN fulfillment_status ENUM('pending','active','expired') NOT NULL DEFAULT 'pending' AFTER payment_status,
  ADD COLUMN membership_period_id INT NULL AFTER order_type,
  ADD COLUMN payment_method VARCHAR(40) NULL AFTER membership_period_id,
  ADD COLUMN admin_notes TEXT NULL AFTER shipping_address_json,
  ADD COLUMN internal_notes TEXT NULL AFTER admin_notes;

ALTER TABLE orders
  ADD UNIQUE KEY uniq_orders_order_number (order_number),
  ADD INDEX idx_orders_member (member_id),
  ADD INDEX idx_orders_payment_status (payment_status),
  ADD INDEX idx_orders_fulfillment_status (fulfillment_status),
  ADD INDEX idx_orders_membership_period (membership_period_id),
  ADD CONSTRAINT fk_orders_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_orders_membership_period FOREIGN KEY (membership_period_id) REFERENCES membership_periods(id) ON DELETE SET NULL;
