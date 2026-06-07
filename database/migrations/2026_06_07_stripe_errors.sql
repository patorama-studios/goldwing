-- Stripe error logging.
--
-- Every time a Stripe SDK call throws ApiErrorException, and every time
-- a webhook handler silently bails out (missing metadata, order not
-- found, etc.), we drop a row in here. Future "why did payments fail
-- mysteriously" investigations should hit this table first.
--
-- Written by:
--   - App\Services\StripeErrorLogger::log()              (SDK failures)
--   - App\Services\StripeErrorLogger::logWebhookSkip()   (silent webhook bails)
--
-- Read by: admin diagnostics (planned), manual SQL.
--
-- Notes:
--   - context_json is redacted in PHP before insert: anything matching
--     sk_live_..., sk_test_..., whsec_..., rk_live_... is replaced
--     with [REDACTED].
--   - We don't FK webhook_event_id to webhook_events(id) because the
--     logger is best-effort and we want it to survive even if the row
--     hasn't landed yet (e.g. signature verification failed before
--     recordEvent() ran).

CREATE TABLE IF NOT EXISTS stripe_errors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  occurred_at DATETIME NOT NULL,
  source VARCHAR(80) NOT NULL,          -- e.g. 'StripeService::createCheckoutSession' or 'webhook.handleCheckoutCompleted'
  operation VARCHAR(80) NULL,           -- short tag: 'checkout_session.create', 'webhook.silent_skip'
  stripe_account VARCHAR(40) NULL,      -- 'primary' | 'agm' | null
  error_code VARCHAR(80) NULL,          -- Stripe error code, if any
  error_message TEXT NULL,
  context_json MEDIUMTEXT NULL,         -- inputs / metadata snapshot, redacted
  related_order_id INT NULL,
  related_store_order_id INT NULL,
  related_stripe_session_id VARCHAR(120) NULL,
  related_stripe_pi_id VARCHAR(120) NULL,
  webhook_event_id INT NULL,            -- FK-ish to webhook_events.id (not enforced)
  INDEX idx_stripe_errors_occurred (occurred_at),
  INDEX idx_stripe_errors_source (source),
  INDEX idx_stripe_errors_order (related_order_id),
  INDEX idx_stripe_errors_store_order (related_store_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
