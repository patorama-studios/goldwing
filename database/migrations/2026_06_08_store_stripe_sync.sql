-- =============================================================================
-- Migration: Stripe product + invoice sync for store
-- Date: 2026-06-08
-- =============================================================================
--
-- Adds the column store_products.stripe_product_id so each store product can
-- be lazily mirrored as a Stripe Product (visible under
-- dashboard.stripe.com/products) and referenced from invoice line items.
--
-- Adds store_orders.stripe_invoice_id so we can persist the Stripe Invoice ID
-- created during checkout and look it up from the webhook (invoice.paid event).
--
-- Both columns are nullable + indexed. Idempotent: re-running is a no-op.
-- =============================================================================

ALTER TABLE store_products
  ADD COLUMN stripe_product_id VARCHAR(120) NULL;

ALTER TABLE store_products
  ADD INDEX idx_store_products_stripe (stripe_product_id);

ALTER TABLE store_orders
  ADD COLUMN stripe_invoice_id VARCHAR(120) NULL;

ALTER TABLE store_orders
  ADD INDEX idx_store_orders_stripe_invoice (stripe_invoice_id);
