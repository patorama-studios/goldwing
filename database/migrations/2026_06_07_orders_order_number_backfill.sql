-- =============================================================================
-- Migration: Backfill orders.order_number for legacy store orders
-- Date: 2026-06-07
-- =============================================================================
--
-- Context:
--   For thousands of rows in `orders` (order_type = 'store'), `order_number`
--   is NULL because `OrderService::createOrder()` historically omitted the
--   column from its INSERT (it did not accept or bind `order_number`).
--   The store-checkout call sites in `public_html/api/index.php` (~line 607,
--   1559) and `public_html/store/checkout.php` (~line 220) all already had
--   the GW-… `$orderNumber` (the store_orders.order_number) in scope, and
--   serialised it into `shipping_address_json` under the JSON key
--   `store_order_number`. They simply never passed it through to
--   `createOrder()`.
--
--   The membership flow uses a SEPARATE path (`MembershipOrderService::
--   createMembershipOrder()`) that writes order_number directly (M-YYYY-NNNNNN
--   prefix), so it is unaffected by this bug and excluded from this backfill.
--
--   The create-path bug was fixed in the same commit as this migration —
--   `OrderService::createOrder()` now accepts `order_number` and the three
--   call sites now pass it through. This migration repairs historical rows.
--
-- Strategy:
--   Read the GW number from `shipping_address_json.store_order_number`
--   (the canonical convention written at api/index.php:623, 1575 and
--   store/checkout.php:236) and copy it into `orders.order_number`.
--
-- Constraint safety:
--   `orders` has UNIQUE KEY uniq_orders_order_number (order_number).
--   - GW store numbers and M-YYYY membership numbers use disjoint prefixes,
--     so collisions with existing membership rows are not possible by design.
--   - `store_orders.order_number` is itself UNIQUE, so two store rows in
--     `orders` should not legitimately share the same store_order_number.
--   - However, defensive duplicates can occur if the same checkout attempt
--     was retried (e.g. stale cart, bank_transfer + stripe race) and produced
--     two `orders` rows pointing at one `store_orders` row. To avoid the
--     UNIQUE blowing up the whole migration, we only backfill rows whose
--     extracted store_order_number is NOT already present in
--     `orders.order_number`. Any duplicates left NULL after this run should
--     be inspected manually (see the diagnostic SELECT at the bottom).
--
-- Idempotent: rerunning is a no-op (the WHERE clause guards on order_number
-- IS NULL and on absence of an existing match).
-- =============================================================================

UPDATE orders o
LEFT JOIN orders dup
  ON dup.order_number = JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_json, '$.store_order_number'))
SET o.order_number = JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_json, '$.store_order_number'))
WHERE o.order_type = 'store'
  AND o.order_number IS NULL
  AND o.shipping_address_json IS NOT NULL
  AND JSON_EXTRACT(o.shipping_address_json, '$.store_order_number') IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_json, '$.store_order_number')) <> ''
  AND dup.id IS NULL;

-- Diagnostic (commented out — run manually after the UPDATE if curious):
-- Rows that still have NULL order_number after the backfill, grouped by reason.
--
-- SELECT
--   CASE
--     WHEN shipping_address_json IS NULL THEN 'no_shipping_address_json'
--     WHEN JSON_EXTRACT(shipping_address_json, '$.store_order_number') IS NULL THEN 'missing_store_order_number_key'
--     WHEN JSON_UNQUOTE(JSON_EXTRACT(shipping_address_json, '$.store_order_number')) IN (
--       SELECT order_number FROM orders WHERE order_number IS NOT NULL
--     ) THEN 'collision_with_existing_order_number'
--     ELSE 'other'
--   END AS reason,
--   COUNT(*) AS rows_affected
-- FROM orders
-- WHERE order_type = 'store' AND order_number IS NULL
-- GROUP BY reason;
