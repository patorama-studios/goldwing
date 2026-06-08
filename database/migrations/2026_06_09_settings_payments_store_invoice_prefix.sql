-- Split invoice prefixes by order type so memberships and store orders can
-- be told apart inside Stripe.
--
-- Until now, `settings_payments.invoice_prefix` + `invoice_counter` +
-- `invoice_counter_year` were a single shared sequence used only by
-- membership invoices via InvoiceService (StoreInvoiceService used Stripe's
-- own invoice numbering and never touched the local counter).
--
-- After this migration:
--   - `invoice_prefix`/`invoice_counter`/`invoice_counter_year` are the
--     MEMBERSHIP sequence (existing data preserved — INV-2026-00001 stays
--     valid for any historic membership invoices).
--   - New columns `invoice_prefix_store`/`invoice_counter_store`/
--     `invoice_counter_year_store` hold the STORE sequence (defaults to
--     "STORE", starts from 0).
--
-- PaymentSettingsService::nextInvoiceNumber($channelId, $orderType) now
-- picks the right column trio based on order type. StoreInvoiceService
-- stamps the resulting Goldwing invoice number into the Stripe Invoice's
-- metadata + description so it's findable inside the Stripe dashboard.

ALTER TABLE settings_payments
  ADD COLUMN invoice_prefix_store VARCHAR(20) NOT NULL DEFAULT 'STORE' AFTER invoice_prefix,
  ADD COLUMN invoice_counter_store INT NOT NULL DEFAULT 0 AFTER invoice_counter,
  ADD COLUMN invoice_counter_year_store INT NOT NULL DEFAULT 0 AFTER invoice_counter_year;
