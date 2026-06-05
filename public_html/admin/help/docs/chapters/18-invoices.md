# Invoices

## For administrators

### What this is

An **invoice** is the PDF tax invoice the site emails to a member the moment they pay for something — a membership renewal, a store order, or an event ticket. It's their record of the transaction: the date, what they bought, what they paid, and (eventually) our ABN and GST break-out so they can claim it on tax or get reimbursed.

The site generates one automatically every time a payment clears in Stripe. The member gets an email with a download link to the PDF — no admin action needed for the normal case.

### What it lets you do

- **Re-download** a copy of an invoice that's already been sent (a member lost the email, or the treasurer needs it for the books).
- **Re-send** an invoice email to the member who placed the order.
- **Spot-check** what an invoice looked like when it went out — useful when a member queries the figures or you're reconciling with Stripe.

### Who's allowed to do this

Three roles can view and re-issue invoices by default:

- **Admin**
- **Committee Member**
- **Treasurer**

The Treasurer role exists specifically for this kind of bookkeeping work — if someone's helping with the books, that's the role they want.

### Where to find it in admin

There are two routes, depending on what you're looking at:

1. **From an order** — Admin → Store → Orders → click the order number → the **Invoice** button in the order detail page.
2. **From a member's profile** — Admin → Members → click the member → **Orders** tab → click the order.

Both routes show the same PDF and pull from the same record.

### How invoice numbering works

Every invoice gets a unique sequential number in the format `INV-2026-00042`:

- `INV` is the **prefix** (configurable — set per payment channel in Settings → Payments).
- `2026` is the **year** the invoice was issued.
- `00042` is the **sequence number** for that year. It starts at `00001` on the first invoice of the year and counts up by one each time.

**Numbers reset each January** — the first invoice of 2027 will be `INV-2027-00001`. The sequence is strictly per-year and per-channel, which is what auditors and the ATO expect.

You don't pick numbers; the site assigns them. You can't skip, reuse, or reorder them either — that's deliberate so the run stays gap-free for audit purposes.

### How GST appears on invoices

The site separates **GST** out as its own line on the invoice's totals block. You'll see a Subtotal (the pre-tax amount), a Tax line, and the Total — which is what the customer actually paid.

Whether GST applies to store orders is controlled in **Admin → Settings → Store → "Apply GST to orders"**. Membership and event pricing handle GST in their own settings; if you're unsure whether something should be GST-inclusive, ask the Treasurer before flipping a switch.

### How the association's ABN and details get into the invoice

The PDF header pulls a handful of fields from **Admin → Settings → Site Settings**:

- **Site name** — appears at the top of the invoice as the issuing entity.
- **ABN** — required by the ATO on any tax invoice for sales of $82.50 or more.
- **Postal / business address** — useful for member reimbursements where the employer wants the supplier's address.

> **Heads up:** the ABN line isn't drawn on the PDF today. If you're treasurer, raise it with the developer — it's a one-line code change but it does need to be done before any serious audit. The setting fields exist; they're just not being read by the PDF generator yet.

### What can go wrong (and what to do)

- **The member didn't get the email.** First check **Admin → Notifications log** to see if the invoice email was sent and accepted. If it sent, it's almost always the member's spam folder. If it didn't, re-send from the order page.
- **The PDF won't open.** Try a different browser or download and open in Acrobat / Preview. PDFs occasionally render poorly inline in Chrome but open fine in a real PDF reader.
- **Wrong details on the header** (site name, ABN, address). Don't edit the PDF — fix the source. Go to Admin → Settings → Site Settings, correct the field, and future invoices will be right. Past invoices stay as they were issued (which is correct — you don't rewrite history on a tax document).
- **No invoice was generated** for a paid order. The PDF only fires when Stripe's webhook reports the payment as cleared. If a manual / cash / complimentary order needs an invoice, ask the developer — there's no admin "create invoice" button today.
- **Invoice number looks out of sequence.** Check whether two payment channels are in play (default `stripe` plus an optional secondary). Each channel has its own counter — that's by design, but it can surprise you on the first AGM payment of the year.

### What gets recorded

Every invoice is logged in three places:

- **In the `invoices` table** — the canonical record with number, order link, member, amounts, and the path to the PDF.
- **On the order itself** — the order detail page shows which invoice number was issued.
- **In the notifications log** — every invoice email send is recorded, so you can prove it went out.

This means a future audit, member complaint, or treasurer's reconciliation can always be reconstructed from the data.

### Good practice

- **Verify your ABN once a year.** A quick spot-check on Admin → Settings → Site Settings every January — and re-print a fresh invoice — catches mistakes before the auditor does.
- **Reconcile monthly against Stripe.** Invoice totals in our records should match Stripe payouts. Pick a random 5–10 orders each month, eyeball the invoice and the Stripe receipt side-by-side, and confirm they agree.
- **Never edit a sent PDF.** If an invoice was wrong, the right answer is a refund + new order, not a doctored PDF. Tax documents need to be honest about what was issued at the time.
- **Don't worry about gaps.** The sequence is gap-free by design. If you ever see a missing number, that's a bug worth flagging — not a missing invoice.

### Who to ask if you're stuck

- **Permission issue** — site admin can change roles in Admin → Settings → Accounts & Roles.
- **Bookkeeping or GST treatment question** — Treasurer.
- **PDF won't generate, or a paid order has no invoice** — flag it to your developer with the order number; they can re-run the webhook or insert the row.
- **ATO / audit question** — the association's accountant. Treat the on-site invoices as evidence, not advice.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

How the site turns a successful payment into a tax invoice. Two services do the work: `App\Services\InvoiceService` orchestrates numbering, DB rows, and email; `App\Services\PdfInvoiceService` draws the PDF using the vendored [FPDF](http://www.fpdf.org/) library at `app/ThirdParty/fpdf/fpdf.php`. Invoices are created automatically by the Stripe webhook when an order moves to `paid`, written to `public_html/uploads/invoices/`, and a link is emailed to the customer.

### Why it exists

The AGA is an ABN-registered organisation taking payments for memberships, store products, and tickets. The ATO requires a **tax invoice** for any sale ≥ $82.50 on request, and members regularly need them for expense reimbursements and treasurers for bookkeeping.

Alternatives considered:

- **Stripe receipts** — we send those too (`stripe_send_receipts` toggle), but a receipt isn't a tax invoice: no ABN, no GST break-out, not our layout.
- **DomPDF / wkhtmltopdf** — HTML→PDF is nicer to template, but DomPDF is ~30 MB and wkhtmltopdf needs a system binary cPanel shared hosting won't always have.
- **FPDF** — one file (~70 KB), no PHP extensions, no binaries, no Composer. You draw layout in code instead of writing HTML; fine for a one-page invoice.

### How it works

#### The trigger

`InvoiceService::createForOrder($order)` is called from `App\Services\PaymentWebhookService` in three places, all webhook-driven:

- `checkout.session.completed` — the normal "card cleared" path.
- A re-entrant safety branch when the webhook fires twice for the same session — only creates if `invoiceExists($orderId)` is false.
- Bank-transfer / async-payment confirmation.

There is currently **no admin "regenerate invoice" button**. If an invoice is missing, re-run the webhook from Stripe or add a row by hand. See *Gotchas*.

#### Numbering

`PaymentSettingsService::nextInvoiceNumber($channelId)` produces the number. It's:

- **Per-channel** — each Stripe channel (default `stripe`, optional `agm` secondary — see [Chapter 13](view.php?slug=13-stripe-overview)) has its own counter row in `settings_payments`.
- **Per-year** — counter resets to 0 the first time it's read in a new calendar year.
- **Sequential within a year** — `+1` per call, under `SELECT ... FOR UPDATE` so concurrent webhooks can't grab the same number.

Format: `{prefix}-{YYYY}-{00001}`, e.g. `INV-2026-00042`. Prefix configurable per channel (default `INV`).

#### What `createForOrder` does

1. Loads `settings_payments` for the order's channel; bails if none.
2. Gets the next invoice number (bails on transaction failure).
3. Inserts an `invoices` row: number, order id, user id, currency, subtotal, tax total, total, and `tax_breakdown_json` of `{"gst": <tax_total>}`.
4. If `settings_payments.generate_pdf = 1` (default), calls `PdfInvoiceService::generate(...)`.
5. The PDF path comes back as `/uploads/invoices/INV-2026-00042.pdf`. The service inserts a `files` row (`owner_type='invoice'`, `mime='application/pdf'`, `label='Tax Invoice'`), registers it with `MediaService::registerUpload()`, and stamps `invoices.pdf_file_id`.
6. Calls private `sendInvoiceEmail()` — a "Tax Invoice {number}" email through `EmailService` ([Chapter 22](view.php?slug=22-notifications-email)). The body is either the admin's custom template (tokens: `{{invoice_number}}`, `{{invoice_date}}`, `{{total}}`, `{{download_url}}`, `{{download_link}}`) or a default `<p>Thank you for your payment.</p>...` block. **The email contains a download link, not an attachment.**

#### What the PDF contains

`PdfInvoiceService::generate()` draws, in this order: site name (from `site.name`), "Tax Invoice", invoice number and date, a "Billed To" block with the user's name and email, an item table (Item / Qty / Unit / Total), and a totals block (Subtotal / Tax / Shipping if non-zero / Total). Amounts are `A$` prefixed (AUD).

What's **missing**: no ABN, no GST registration statement, no club address, no logo, no footer text. For an ATO-compliant tax invoice over $82.50, ABN is mandatory — add it in `PdfInvoiceService::generate()` directly.

### Where to change it

| Change | Where |
|---|---|
| Add ABN, logo, footer, or address to the PDF | Edit `PdfInvoiceService::generate()` in `app/Services/PdfInvoiceService.php` — pull values via `SettingsService::getGlobal('site.address', ...)` etc. |
| Change the invoice number format | `PaymentSettingsService::nextInvoiceNumber()` — the `sprintf('%s-%04d-%05d', ...)` line at the bottom. |
| Reset or change the prefix | Settings → Payments → Receipts & Invoices → "Invoice prefix" per channel. |
| Stop generating PDFs (keep DB rows only) | Settings → Payments → uncheck "Generate PDF invoices" per channel. |
| Customise the email body | Settings → Payments → "Invoice email template" textarea. |
| Toggle store GST | Settings → Store → "Apply GST to orders" (`store.gst_enabled`). |

### Settings

Per-channel, in `settings_payments` (one row per `payment_channels.id`):

- `invoice_prefix` (default `INV`)
- `invoice_counter_year`, `invoice_counter` (system-managed)
- `generate_pdf` (0/1, default 1)
- `invoice_email_template` (TEXT, optional)

Site-wide, in `settings_global`: `store.gst_enabled` (boolean, default true) and `site.name` (shown at the top of the PDF).

### Gotchas

- **No admin re-download / regenerate UI.** The PDF lives at `public_html/uploads/invoices/{INV-YYYY-NNNNN}.pdf` and is reachable by direct URL — `BaseUrlService::buildUrl($file['file_path'])` is what the email links to. Add a page if needed; it should re-run `PdfInvoiceService::generate()` against the existing `invoices` row, not call `createForOrder()`.
- **Calling `createForOrder()` twice mints a second invoice number.** The "already paid" branch guards against this with `invoiceExists($orderId)`, but if you wire a new caller, check first. Numbers are sequential per year and you cannot easily skip back.
- **The PDF lives in the public uploads folder.** Anyone with the URL can download it. The URL contains the invoice number, so it's not enumerable in practice, but it's not access-controlled either. Don't put bank details on the invoice.
- **PDFs are not byte-stable.** FPDF embeds creation timestamps in the file header — re-generating the same invoice produces different bytes. Don't checksum invoice PDFs for change detection; compare the `invoices` row instead.
- **No ABN on the PDF today.** For Goldwing's volume this hasn't been an issue, but ATO rules require ABN on any tax invoice ≥ $82.50. Add the line in `PdfInvoiceService` before the next audit.
- **Links, not attachments.** The email body links to the PDF. If a customer's mail server blocks the linked URL, they get a working email but no invoice — attaching instead would mean reading the file from disk in `sendInvoiceEmail()` and passing bytes through `EmailService`/`SmtpMailer`. Currently we don't.

</details>

<!-- SCREENSHOT: A sample generated PDF (e.g. INV-2026-00001.pdf) opened in a browser. Save to public_html/admin/help/images/18-sample-invoice.png. -->
<!-- ![Sample tax invoice PDF](../images/18-sample-invoice.png) -->

<!-- SCREENSHOT: Settings → Payments → "Receipts & Invoices" panel showing the Generate PDF checkbox, invoice prefix, and email template fields. Save as 18-invoice-settings.png. -->
<!-- ![Invoice settings panel](../images/18-invoice-settings.png) -->

## Related chapters

- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — how a payment becomes a webhook event in the first place.
- [14 — Membership pricing matrix](view.php?slug=14-membership-pricing) — what flows into the membership-order line items.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — where `orders`, `order_items`, and `store_orders` come from.
- [17 — Refunds](view.php?slug=17-refunds) — refunds don't currently issue a credit-note PDF; the original invoice remains.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — `EmailService::send()` and the SMTP transport that carries the invoice email.
