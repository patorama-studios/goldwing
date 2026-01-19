-- Store admin order management enhancements

CREATE TABLE IF NOT EXISTS store_order_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  message VARCHAR(255) NOT NULL,
  metadata_json TEXT NULL,
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_store_order_events_order (order_id),
  INDEX idx_store_order_events_type (event_type),
  FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_refunds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  member_id INT NOT NULL,
  amount_cents INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  stripe_refund_id VARCHAR(120) NULL,
  status ENUM('requested','processed','failed') NOT NULL DEFAULT 'requested',
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_store_refunds_member (member_id),
  INDEX idx_store_refunds_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE store_orders
  ADD COLUMN order_status ENUM('new','processing','packed','shipped','completed','cancelled') NOT NULL DEFAULT 'new',
  ADD COLUMN payment_status ENUM('unpaid','paid','refunded','partial_refund') NOT NULL DEFAULT 'unpaid',
  ADD COLUMN fulfillment_status ENUM('unfulfilled','partial','fulfilled') NOT NULL DEFAULT 'unfulfilled',
  ADD COLUMN shipped_at DATETIME NULL;

ALTER TABLE store_order_items
  ADD COLUMN fulfilled_qty INT NOT NULL DEFAULT 0;

UPDATE store_orders
  SET
    payment_status = CASE
      WHEN status IN ('paid','fulfilled') THEN 'paid'
      WHEN status = 'refunded' THEN 'refunded'
      ELSE 'unpaid'
    END,
    order_status = CASE
      WHEN status = 'fulfilled' THEN 'completed'
      WHEN status IN ('cancelled','refunded') THEN 'cancelled'
      ELSE 'new'
    END,
    fulfillment_status = CASE
      WHEN status = 'fulfilled' THEN 'fulfilled'
      ELSE 'unfulfilled'
    END,
    shipped_at = CASE
      WHEN status = 'fulfilled' AND shipped_at IS NULL THEN fulfilled_at
      ELSE shipped_at
    END
  WHERE 1 = 1;

UPDATE store_order_items oi
  JOIN store_orders o ON o.id = oi.order_id
  SET oi.fulfilled_qty = oi.quantity
  WHERE o.status = 'fulfilled';
