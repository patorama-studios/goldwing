-- Allow guest carts/orders and store Stripe subscription references.
ALTER TABLE store_carts
  MODIFY user_id INT NULL;

ALTER TABLE store_orders
  MODIFY user_id INT NULL;

ALTER TABLE orders
  ADD COLUMN stripe_subscription_id VARCHAR(120) NULL AFTER stripe_payment_intent_id,
  ADD COLUMN stripe_invoice_id VARCHAR(120) NULL AFTER stripe_subscription_id,
  ADD INDEX idx_orders_subscription (stripe_subscription_id),
  ADD INDEX idx_orders_invoice (stripe_invoice_id);
