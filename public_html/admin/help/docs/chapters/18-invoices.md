# Invoices

## What this covers

How the site turns a successful payment into a tax invoice. Two services do the work: `App\Services\InvoiceService` orchestrates numbering, DB rows, and email; `App\Services\PdfInvoiceService` draws the PDF using the vendored [FPDF](http://www.fpdf.org/) library at `app/ThirdParty/fpdf/fpdf.php`. Invoices are created automatically by the Stripe webhook when an order moves to `paid`, written to `public_html/uploads/invoices/`, and a link is emailed to the customer.

## Why it exists

The AGA is an ABN-registered organisation taking payments for memberships, store products, and tickets. The ATO requires a **tax invoice** for any sale ≥ $82.50 on request, and members regularly need them for expense reimbursements and treasurers for bookkeeping.

Alternatives considered:

- **Stripe receipts** — we send those too (`stripe_send_receipts` toggle), but a receipt isn't a tax invoice: no ABN, no GST break-out, not our layout.
- **DomPDF / wkhtmltopdf** — HTML→PDF is nicer to template, but DomPDF is ~30 MB and wkhtmltopdf needs a system binary cPanel shared hosting won't always have.
- **FPDF** — one file (~70 KB), no PHP extensions, no binaries, no Composer. You draw layout in code instead of writing HTML; fine for a one-page invoice.

## How it works

### The trigger

`InvoiceService::createForOrder($order)` is called from `App\Services\PaymentWebhookService` in three places, all webhook-driven:

- `checkout.session.completed` — the normal "card cleared" path.
- A re-entrant safety branch when the webhook fires twice for the same session — only creates if `invoiceExists($orderId)` is false.
- Bank-transfer / async-payment confirmation.

There is currently **no admin "regenerate invoice" button**. If an invoice is missing, re-run the webhook from Stripe or add a row by hand. See *Gotchas*.

### Numbering

`PaymentSettingsService::nextInvoiceNumber($channelId)` produces the number. It's:

- **Per-channel** — each Stripe channel (default `stripe`, optional `agm` secondary — see [Chapter 13](view.php?slug=13-stripe-overview)) has its own counter row in `settings_payments`.
- **Per-year** — counter resets to 0 the first time it's read in a new calendar year.
- **Sequential within a year** — `+1` per call, under `SELECT ... FOR UPDATE` so concurrent webhooks can't grab the same number.

Format: `{prefix}-{YYYY}-{00001}`, e.g. `INV-2026-00042`. Prefix configurable per channel (default `INV`).

### What `createForOrder` does

1. Loads `settings_payments` for the order's channel; bails if none.
2. Gets the next invoice number (bails on transaction failure).
3. Inserts an `invoices` row: number, order id, user id, currency, subtotal, tax total, total, and `tax_breakdown_json` of `{"gst": <tax_total>}`.
4. If `settings_payments.generate_pdf = 1` (default), calls `PdfInvoiceService::generate(...)`.
5. The PDF path comes back as `/uploads/invoices/INV-2026-00042.pdf`. The service inserts a `files` row (`owner_type='invoice'`, `mime='application/pdf'`, `label='Tax Invoice'`), registers it with `MediaService::registerUpload()`, and stamps `invoices.pdf_file_id`.
6. Calls private `sendInvoiceEmail()` — a "Tax Invoice {number}" email through `EmailService` ([Chapter 22](view.php?slug=22-notifications-email)). The body is either the admin's custom template (tokens: `{{invoice_number}}`, `{{invoice_date}}`, `{{total}}`, `{{download_url}}`, `{{download_link}}`) or a default `<p>Thank you for your payment.</p>...` block. **The email contains a download link, not an attachment.**

### What the PDF contains

`PdfInvoiceService::generate()` draws, in this order: site name (from `site.name`), "Tax Invoice", invoice number and date, a "Billed To" block with the user's name and email, an item table (Item / Qty / Unit / Total), and a totals block (Subtotal / Tax / Shipping if non-zero / Total). Amounts are `A$` prefixed (AUD).

What's **missing**: no ABN, no GST registration statement, no club address, no logo, no footer text. For an ATO-compliant tax invoice over $82.50, ABN is mandatory — add it in `PdfInvoiceService::generate()` directly.

## Where to change it

| Change | Where |
|---|---|
| Add ABN, logo, footer, or address to the PDF | Edit `PdfInvoiceService::generate()` in `app/Services/PdfInvoiceService.php` — pull values via `SettingsService::getGlobal('site.address', ...)` etc. |
| Change the invoice number format | `PaymentSettingsService::nextInvoiceNumber()` — the `sprintf('%s-%04d-%05d', ...)` line at the bottom. |
| Reset or change the prefix | Settings → Payments → Receipts & Invoices → "Invoice prefix" per channel. |
| Stop generating PDFs (keep DB rows only) | Settings → Payments → uncheck "Generate PDF invoices" per channel. |
| Customise the email body | Settings → Payments → "Invoice email template" textarea. |
| Toggle store GST | Settings → Store → "Apply GST to orders" (`store.gst_enabled`). |

## Settings

Per-channel, in `settings_payments` (one row per `payment_channels.id`):

- `invoice_prefix` (default `INV`)
- `invoice_counter_year`, `invoice_counter` (system-managed)
- `generate_pdf` (0/1, default 1)
- `invoice_email_template` (TEXT, optional)

Site-wide, in `settings_global`: `store.gst_enabled` (boolean, default true) and `site.name` (shown at the top of the PDF).


<!-- SCREENSHOT: A sample generated PDF (e.g. INV-2026-00001.pdf) opened in a browser. Save to public_html/admin/help/images/18-sample-invoice.png. -->
<!-- ![Sample tax invoice PDF](../images/18-sample-invoice.png) -->

<!-- SCREENSHOT: Settings → Payments → "Receipts & Invoices" panel showing the Generate PDF checkbox, invoice prefix, and email template fields. Save as 18-invoice-settings.png. -->
<!-- ![Invoice settings panel](../images/18-invoice-settings.png) -->

## Gotchas

- **No admin re-download / regenerate UI.** The PDF lives at `public_html/uploads/invoices/{INV-YYYY-NNNNN}.pdf` and is reachable by direct URL — `BaseUrlService::buildUrl($file['file_path'])` is what the email links to. Add a page if needed; it should re-run `PdfInvoiceService::generate()` against the existing `invoices` row, not call `createForOrder()`.
- **Calling `createForOrder()` twice mints a second invoice number.** The "already paid" branch guards against this with `invoiceExists($orderId)`, but if you wire a new caller, check first. Numbers are sequential per year and you cannot easily skip back.
- **The PDF lives in the public uploads folder.** Anyone with the URL can download it. The URL contains the invoice number, so it's not enumerable in practice, but it's not access-controlled either. Don't put bank details on the invoice.
- **PDFs are not byte-stable.** FPDF embeds creation timestamps in the file header — re-generating the same invoice produces different bytes. Don't checksum invoice PDFs for change detection; compare the `invoices` row instead.
- **No ABN on the PDF today.** For Goldwing's volume this hasn't been an issue, but ATO rules require ABN on any tax invoice ≥ $82.50. Add the line in `PdfInvoiceService` before the next audit.
- **Links, not attachments.** The email body links to the PDF. If a customer's mail server blocks the linked URL, they get a working email but no invoice — attaching instead would mean reading the file from disk in `sendInvoiceEmail()` and passing bytes through `EmailService`/`SmtpMailer`. Currently we don't.

## Related chapters

- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — how a payment becomes a webhook event in the first place.
- [14 — Membership pricing matrix](view.php?slug=14-membership-pricing) — what flows into the membership-order line items.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — where `orders`, `order_items`, and `store_orders` come from.
- [17 — Refunds](view.php?slug=17-refunds) — refunds don't currently issue a credit-note PDF; the original invoice remains.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — `EmailService::send()` and the SMTP transport that carries the invoice email.
