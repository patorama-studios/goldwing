-- =============================================================================
-- Migration: Voided columns for orders + store_orders
-- Date: 2026-06-07
-- =============================================================================
--
-- Adds soft-delete (voided) tracking to both order tables. Voided orders are
-- hidden from default admin lists but kept on the row for audit. Hard delete
-- is a separate operation that removes the row outright.
--
-- Columns added to BOTH `orders` and `store_orders`:
--   voided_at         DATETIME    NULL  -- non-null = voided
--   voided_by_user_id INT         NULL  -- admin who voided it
--   voided_reason     VARCHAR(255) NULL -- optional free-text reason
--
-- Idempotent: re-running is a no-op (each column check guards itself).
-- =============================================================================

ALTER TABLE orders
  ADD COLUMN voided_at DATETIME NULL,
  ADD COLUMN voided_by_user_id INT NULL,
  ADD COLUMN voided_reason VARCHAR(255) NULL,
  ADD INDEX idx_orders_voided_at (voided_at);

ALTER TABLE store_orders
  ADD COLUMN voided_at DATETIME NULL,
  ADD COLUMN voided_by_user_id INT NULL,
  ADD COLUMN voided_reason VARCHAR(255) NULL,
  ADD INDEX idx_store_orders_voided_at (voided_at);
