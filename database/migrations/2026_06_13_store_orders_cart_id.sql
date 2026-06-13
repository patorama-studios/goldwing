-- Migration 036 — store_orders.cart_id (idempotent checkout)
--
-- Links each store_order back to the store_carts row it was created from so the
-- /api/stripe/create-payment-intent endpoint can REUSE an existing pending order
-- for the same cart instead of inserting a new one on every checkout revisit.
-- Without this, opening the Payment step repeatedly spawned a fresh pending
-- store_order (+ mirror orders row + admin "order placed" email) each time.
--
-- Run via /admin/run-migration.php (Migration 036) on the live host — this file
-- is the canonical reference. Nullable so legacy rows are unaffected.

ALTER TABLE store_orders ADD COLUMN cart_id INT NULL AFTER member_id;
ALTER TABLE store_orders ADD INDEX idx_store_orders_cart_pending (cart_id, status);
