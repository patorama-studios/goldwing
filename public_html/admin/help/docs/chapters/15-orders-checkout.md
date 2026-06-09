# Orders & checkout flow

## For administrators

### What this is

An **order** is the record we keep every time someone pays us. Whether it's a new member paying their first year, an existing member renewing, somebody buying a polo shirt from the store, or a ticket to a rally — it all turns into an order row that we can look up later.

There are two flavours of order:

- **Membership orders** — paying for a membership term (new join, renewal, upgrade).
- **Store orders** — buying a product or ticket from the online store.

They look slightly different inside the admin because they carry different details (a membership doesn't ship anywhere; a polo does), but the basic idea is the same: one person, one payment, one record.

### What it lets you do

- Look up any order a member has placed, ever.
- See whether they actually paid, what they bought, and what state it's in (new, packed, shipped, completed, cancelled).
- Spot orders that got stuck — for example, a member who started checkout but never finished paying.
- Update the status as you work the order (mark it packed, add a tracking number, mark it shipped).
- Initiate a refund if you need to (see Chapter 17).
- Add internal notes for the next admin who looks at it.

### Who's allowed

You need the **store orders view** permission to see them, and **store orders manage** to change anything. By default the Admin, Committee Member, and Treasurer roles can do both. Membership orders are visible to anyone who can see members.

If buttons are missing or you can't open an order, your role probably doesn't have the right permission. A site admin can adjust this in Admin → Settings → Accounts & Roles.

### Where to find it

![The Store Orders queue (customer names sanitized to 'Member N')](images/15-orders-list.jpg)

Four doors lead to orders:

1. **Admin → Payments & Settings** — the **master payments dashboard**. Lists every transaction the site has ever taken (both memberships and store, mixed together), with a search box, filter panel (Status / Type / Date From / Date To / Voided), real pagination, and four row-actions per order: **View**, **Refund**, **Void**, **Delete**. The top of the page also shows Stripe connection status, the last-received webhook, and a collapsible **Payments Debug Log** of recent webhook events. This is the page you open when you don't know which order you're looking for, or when you need to action one quickly without opening the full detail page.
2. **Admin → Store → Orders** — the store-only list, with extra filters for fulfilment (new / processing / packed / shipped) and search across SKUs. Use this when you're working the fulfilment queue rather than the payments side.
3. **Admin → Members → click a member → Orders tab** — every order that member has placed, both memberships and store.
4. **Recent payments card on the admin dashboard** — the latest handful of orders from both types, useful for a quick "did that payment come through?" check.

All four routes show the same underlying data — pick whichever matches what you already know (order number vs member name vs "what came in today" vs "I need to refund this now").

### Actioning an order from the Payments dashboard

From **Admin → Payments & Settings**, every row in the Recent Transactions table is clickable: a click on the row takes you to the order's detail page (the member view for membership orders, the store order view for store orders). The four icon buttons on the right of each row let you act without opening the detail page first:

- **View** (eye icon) — opens the order detail page in the same way clicking the row does.
- **Refund** (currency icon) — only shown on **paid** orders. Opens a modal asking for an optional reason and confirms before calling the same `RefundService` used everywhere else. See [Chapter 17 — Refunds](view.php?slug=17-refunds) for what happens next.
- **Void** (block icon) — marks the order as voided without touching Stripe. Use when the order should be hidden from the books (e.g. a manual test order or a duplicate that never got paid). Voided orders are hidden by the default filter; switch the **Voided** filter to **Show** or **Only** to see them again.
- **Unvoid** (restart icon) — shown instead of Void when the order is already voided. Returns it to its previous state.
- **Delete** (trash icon) — hard-deletes the order from the database, cascading to the linked `store_orders` row for store-type orders. **There's no undo.** The modal requires you to type `DELETE` to confirm.

Voiding and deleting are restricted to the **admin** role only — the Refund button shows for Admin / Committee Member / Treasurer.

### How to look up an order

{{tour:admin-process-order}}

- **You have the order number** (looks like `M-2026-000482` for memberships or a similar code for store orders) — type it into the search box on Admin → Store → Orders.
- **You have the member's name** — go to Admin → Members, find them, open the Orders tab.
- **You have the customer's email** — the search box on Admin → Store → Orders accepts emails too.
- **You have a product code (SKU)** — same search box finds every order containing that SKU.
- **You only know the rough date** — use the date filter on Admin → Store → Orders.

### Reading an order detail page

Once you open an order, you'll see several panels. Here's what each one means in plain English:

- **Status panel** — the headline state of the order. The two big words to look at are the **payment status** (have we got their money?) and the **order status** (have we got it out the door?). A healthy in-progress store order looks like *Paid + Processing*. A finished one looks like *Paid + Completed*.
- **Customer / member** — who placed the order. Store orders now always have a linked member (the store requires login); legacy guest store orders may still appear in history and can be linked from this panel. Membership new-applicant orders show the applicant's email before their member account is activated.
- **Line items** — what they bought. Each row shows the product name, the code (SKU), the quantity, and the price. **These prices are frozen at the moment the order was placed** — even if you change the price in the store later, this order still shows what they actually paid.
- **Totals** — the subtotal, any discount that was applied, shipping, the processing fee, and the grand total. These numbers also stay frozen.
- **Shipping** — the address it's going to, and how (post, or pickup). For pickup orders you'll see the pickup instructions that were shown to the buyer at checkout.
- **Shipment / tracking panel** — where you record that you've packed it, picked the carrier, and entered the tracking number. Adding a tracking number is what flips the order to *shipped*.
- **Refunds** — any refunds that have been issued against this order. Full instructions are in Chapter 17.
- **Notes / timeline** — internal notes you (or someone before you) wrote, plus an auto-generated timeline of every state change. Use the notes for anything the next person needs to know ("rang the member, posting Monday").

### What can go wrong (and what to do)

- **The order is stuck on "pending" forever.** The member started checkout but never finished paying. The page redirected them to the payment provider and they bailed, or their card was declined. There's nothing to do — these aren't real orders. You can safely ignore them; the **stale pending-order cleanup** cron ([Chapter 34](view.php?slug=34-cron-jobs)) automatically cancels any card-checkout that's been pending for more than 24 hours, so the list won't fill up over time. The orders list also lets you filter pending orders out.
- **The payment shows "paid" but the order status is still "new".** That's normal — payment status and order status are tracked separately. *Paid* just means the money's in. The order status moves through *new → processing → packed → shipped → completed* as you (or whoever does fulfilment) works through it.
- **The member says they paid but I can't find the order.** Two things to check: (1) was it under a different email or as a guest checkout? Search by email rather than by member. (2) Look at the recent payments card on the dashboard — if Stripe took the money but the order didn't appear in the admin, the webhook might be misconfigured. Flag it to a developer with the order number and amount.
- **The order isn't linked to a member.** Some checkouts come in as guest. You can link the order to a member from the order's detail page. This matters for refunds — refunds need a linked member account.
- **The shipping address looks wrong.** What's on the order is what the customer typed at checkout. If they got it wrong, message them, get the correct address, update the address on the order, and add a note explaining why.
- **The price on the order doesn't match the current price in the store.** That's correct, not a bug. We deliberately freeze the price at purchase time so refunds and history stay accurate. The customer paid what's shown; today's store catalogue is irrelevant.
- **A member says they're being charged twice.** Look at their Orders tab. Two paid orders for the same thing = either a genuine double-purchase (refund one) or a stuck checkout that got retried (the first one should be *pending* not *paid* — confirm in the order detail before refunding). If only one shows *paid* and they're still seeing two charges on their bank, that's a Stripe-side question for the Treasurer.
- **I cancelled an order but stock didn't come back.** That's deliberate. Cancelling does not restock — the item may have already been picked or posted. If you genuinely need the units back on the shelf, use the refund flow (Chapter 17). Refunds restock; cancellations don't.

### What gets recorded

Every order keeps a permanent record of:

- Who bought, what they bought, what they paid, and when.
- Every status change, with timestamps and who made the change.
- Any internal notes typed against it.
- Any refunds issued.
- The shipping address and tracking number (for store orders).
- The receipt ID from the payment provider (looks like `pi_xxxxxxxxx`), so the Treasurer can cross-reference it in the Stripe dashboard.

This means a year-old order can be reconstructed exactly. Useful for treasurer's reports, audit questions, and member disputes.

### Good practice

- **Update the order status as you work it.** Marking an order *packed* and then *shipped* lets the next admin (and the member) see progress without asking.
- **Add a tracking number when you post something.** This is the difference between "we sent it" and "we *say* we sent it".
- **Type a note for anything weird.** "Address changed at member's request", "rang to confirm size", "out of stock — sent next colour with permission" — future-you needs to know.
- **Don't change prices on an open order.** If a member wants a different deal, refund and re-take the payment. Editing totals after the fact breaks the audit trail.
- **Filter out "pending" orders when you're triaging the list.** They're checkout abandonment, not real work.
- **Cross-check Stripe once a month.** Treasurer should make sure paid orders in the admin match payments in the Stripe dashboard. Discrepancies are rare but worth catching.

### Who to ask if stuck

- **Permission issue** — site admin can change roles in Admin → Settings → Accounts & Roles.
- **Order is stuck and I don't know why** — flag the order number to a developer; they can check whether the webhook fired.
- **Stripe says one thing, the admin says another** — Treasurer (they have the Stripe login).
- **The error message is jargon** — copy-paste it to your developer.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

Every transaction on the site — membership renewal, polo shirt, rally ticket — ends up as an **order row**. This chapter explains the two parallel order types (membership vs store), the two tables behind them, what happens during checkout, what each status means, and why line items are snapshotted at order time so refunds keep working even when the catalogue moves on.

### Why it exists

An order row is the single source of truth for *what was bought, by whom, at what price, and what state it's in*. Stripe is the cash register, but Stripe doesn't know that "Pat's 3-year renewal" is `membership_periods` row #482 — only the order row links them. When a refund or dispute lands a year later we need to look up *exactly* what was charged without re-pricing against today's catalogue. That's the snapshot rule: every order item carries its own name, SKU, quantity, and unit price frozen at purchase time.

Two types exist because the data each needs diverges. A membership order needs only "which member, which period, what was paid". A store order needs SKUs, variants, stock, shipping, fulfilment, tracking. So `orders` is the universal payment ledger Stripe sees, `store_orders` is the store-domain table — store checkouts write both and link them.

### How it works

#### The two tables

| Table | Owner | Holds |
|---|---|---|
| `orders` | `OrderService` + `MembershipOrderService` | Universal payment ledger. `order_type` is `membership` or `store`. Holds Stripe IDs, `status`, `payment_status`, `fulfillment_status`, `order_number`. Line items in `order_items`. |
| `store_orders` | `OrderRepository` + `store/checkout.php` | Store-domain table with shipping address, `fulfillment_method`, `order_status`, tracking. Line items in `store_order_items` with full snapshots (`title_snapshot`, `sku_snapshot`, `variant_snapshot`, `unit_price`, `unit_price_final`, `line_total`). |

A **store checkout writes both rows.** `store_orders` is created first; then `OrderService::createOrder()` writes the matching `orders` row with a `shipping_address_json` blob embedding `store_order_id` and `store_order_number`. That's how `PaymentWebhookService` (Ch 16) finds the store row after Stripe confirms.

#### The membership checkout flow

1. **Apply** at `/apply.php` (new) or *Renew* in the member portal — both end at `/become-a-member`.
2. **`MembershipPricingService`** (Ch 14) prices the term + chapter + pro-rata month.
3. **`MembershipOrderService::createMembershipOrder()`** inserts an `orders` row with `order_type='membership'`, `status='pending'`, and a sequential `order_number` (e.g. `M-2026-000482`) generated by atomically incrementing two `settings_global` rows under `FOR UPDATE`. One `order_items` row is inserted for the membership line.
4. **`StripeService::createCheckoutSession()`** is called with the order ID in metadata; the user is redirected to Stripe's hosted page.
5. **Success URL** returns them to `/memberships/success` — a Tailwind thank-you page with a personalised welcome, "what's next" checklist, and CTAs (Sign in / Back to dashboard / Main website). The order is still `pending` here; activation happens on the webhook.
6. **Webhook** (Ch 16) lands at `/api/stripe_webhook.php` and calls `MembershipOrderService::activateMembershipForOrder()`, which flips `orders.status` to `paid`, sets the `membership_periods` row to `ACTIVE` with calculated start/end dates (respecting any existing active period so renewals don't overlap), sets `members.status='ACTIVE'`, and writes an activity log entry.

#### The store checkout flow

1. **Browse** `/store/catalog.php`, **add to cart** at `/store/product.php`. Cart lives in `store_carts` / `store_cart_items` (DB-backed, survives logout).
2. **Review** at `/store/cart.php`, **checkout** at `/checkout`. `store_calculate_cart_totals()` applies the discount code (Ch 29), shipping, and processing fee; stock is re-checked per-variant.
3. **`store_orders` insert** with shipping fields, totals, and `pickup_instructions_snapshot` for pickup. **`store_order_items`** rows hold the per-line snapshots; **`store_order_discounts`** captures any code used.
4. **`OrderService::createOrder()`** writes the matching `orders` row with `order_type='store'` and embeds `store_order_id` in `shipping_address_json`.
5. **Stripe Invoice + on-page Payment Element.** `StoreInvoiceService::ensureInvoiceForOrder()` mints a Stripe Invoice with one itemized line per cart item (plus shipping / GST / processing-fee lines), reuses any existing draft/open invoice if the customer reopened the order, and returns the invoice's `PaymentIntent.client_secret`. The browser embeds Stripe's Payment Element directly on `/checkout` and confirms the PI in-page — there's no longer a redirect to Stripe's hosted Checkout page. On success the user lands on `/order/success?order=<order_number>` (a member-shell thank-you page); the full order detail remains one click away at `/store/orders/<order_number>`. The Stripe Invoice carries our internal `STORE-YYYY-NNNNN` invoice number in metadata + description, so a Stripe-side search finds Goldwing orders.
6. **Webhook** (`invoice.paid`) flips both tables to paid/accepted and decrements inventory on `store_products` / `store_product_variants`. See [Chapter 16](view.php?slug=16-webhooks-idempotency).

Membership renewals still use `StripeService::createCheckoutSessionWithLineItems()` (a hosted Checkout Session) — only the store side moved to invoices.

#### Order states

`orders.status` is the high-level state: `pending` → `paid` → `refunded` or `cancelled`. Finer-grained columns track payment and fulfilment separately — `payment_status` (`pending` / `accepted` / `rejected` / `failed` / `refunded`), membership `fulfillment_status` (`pending` / `active` / `expired`), and `store_orders.order_status` (`new` / `processing` / `packed` / `shipped` / `completed` / `cancelled`). The Stripe-status mapping lives in `OrderRepository::mapStripeStatus()`: `succeeded` / `processing` / `requires_capture` → `paid`, `canceled` → `cancelled`, else `pending`.

#### Snapshot pricing (the refund-math rule)

Every line item carries its **own copy** of the name and unit price. Store items also carry `unit_price_final` — the price after the order-level discount was apportioned across the cart. Nothing reads from `store_products` at refund time. If you re-price a polo from $45 to $50 tomorrow, last week's order still refunds at $45 per unit. Ch 17 covers how `RefundService` uses the snapshot.

### Where to change it

- **Master payments dashboard:** `/admin/index.php?page=payments`. The Recent Transactions table reads from `orders` directly, supports search + the filter panel (status, type, date range, voided), and renders the four-button row-actions (View / Refund / Void / Delete). POST handler at the same path handles `void_order`, `unvoid_order`, `delete_order` (gated on `AdminMemberAccess::canManualOrderFix()`); `api_refund` reuses the existing `/api/admin/refunds/create` payload, routed through `RefundService` (Ch 17).
- **Store orders list:** `/admin/store/orders` (`public_html/admin/store/orders.php`). Filters by status, fulfilment, date, and free-text search across order number, customer, email, SKU. Bulk status changes supported.
- **Single store order:** `/admin/store/order_view.php?id=X` (or `?order=ORDER_NUMBER`). Status changes, shipment creation (carrier + tracking → `store_shipments`), internal notes, refund initiation.
- **Recent payments card** on `/admin/index.php` — reads `orders`, so memberships and store both appear.
- **Member-facing order history** — in the member portal, via `OrderRepository::listByMember()` against `store_orders`. Membership renewals appear on the membership card instead.

Permission keys: `store_orders_view`, `store_orders_manage`, `store_refunds_manage`. See Ch 07.

### Settings

Order-time pricing pulls from store settings: flat vs free-over-threshold shipping, pickup on/off, processing-fee percentage, shipping region (`AU` by default — blocks non-AU postcodes). All live on the Store settings page; full reference in [Ch 29](view.php?slug=29-discounts-shipping). The membership order-number prefix is `membership.order_prefix` (default `M`), with the running counter in `membership.order_counter` / `_year`.

### Gotchas

- **The order row is created BEFORE Stripe confirms.** Both flows insert `pending`, redirect to Stripe, then wait for the webhook to flip to `paid`. Abandoned checkouts leave harmless `pending` rows forever — filter `status != 'pending'` for "real orders".
- **The webhook does the activation, not the success page.** Don't put side-effects in `/order/success/`. If the webhook is misconfigured, the user sees "thanks!" but the order is still pending. The redirect is UX; the webhook is truth.
- **Two tables, one Stripe session.** Store checkout writes both `store_orders` and `orders`, linked through `orders.shipping_address_json.store_order_id`. Use `orders` for payment-ledger questions (totals, Stripe IDs, refundability); `store_orders` for store-ops (shipping, fulfilment, tracking).
- **Snapshotted prices mean catalogue updates don't touch historical orders.** Refund math, invoice reprints, and order history all read the snapshot — never the live `store_products` row. If a price looks "wrong" on an old order, that's what it was at the time.
- **Cancellation doesn't auto-restock.** Marking a store order `cancelled` via the admin drop-down does not put units back on `store_products.stock_quantity`. Use the refund flow (Ch 17) if you need stock returned — refunds restock; cancellations don't, in case the stock was already shipped.
- **Order numbers are per-series, not per-table.** Memberships use `M-YYYY-NNNNNN`; store orders use their own series via `store_generate_order_number()`. *Invoice* numbers are a separate concern — `MEM-YYYY-NNNNN` and `STORE-YYYY-NNNNN`, minted by `PaymentSettingsService::nextInvoiceNumber()`. See [Chapter 18](view.php?slug=18-invoices).
- **Voiding ≠ deleting ≠ cancelling.** Three different actions, three different outcomes. Cancel changes the order status but keeps the row; void hides it from the default list (and from member-facing history) without touching Stripe; delete is a hard `DELETE` cascading the linked `store_orders` row. Voided rows still show in the dashboard if you flip the **Voided** filter to *Show* or *Only*.
- **Stale pending cleanup is now automated.** `cron/expire_pending_orders.php` ([Chapter 34](view.php?slug=34-cron-jobs)) closes off any card-method `pending` order older than 24h, mirroring `MembershipOrderService::markOrderRejected` for the linked `membership_periods` row. Don't try to clean those rows by hand — let the cron do it.

</details>

<!-- SCREENSHOT: Admin payments dashboard at /admin/index.php?page=payments, showing Stripe Connection card + Billing Configuration card + Recent Transactions table with at least one paid row visible (so the row-action buttons render). Save as 15-payments-dashboard.png. -->
<!-- ![Admin Payments dashboard](../images/15-payments-dashboard.png) -->

<!-- SCREENSHOT: Same page, Filters panel expanded (click "Filters" so the Status / Type / Date From / Date To / Voided controls show). Save as 15-payments-filters.png. -->
<!-- ![Payments dashboard filters](../images/15-payments-filters.png) -->

<!-- SCREENSHOT: Same page, Refund modal open (click the green currency_exchange icon on a paid order). Save as 15-payments-refund-modal.png. -->
<!-- ![Refund modal](../images/15-payments-refund-modal.png) -->

<!-- SCREENSHOT: Admin store orders list at /admin/store/orders, showing the filter row and a few rows of mixed statuses. Save as 15-store-orders-list.png. -->
<!-- ![Store orders list](../images/15-store-orders-list.png) -->

<!-- SCREENSHOT: Single store order at /admin/store/order_view.php?id=X, full page showing items, totals, status panel, shipment panel, refund panel, notes. Save as 15-store-order-view.png. -->
<!-- ![Single order view](../images/15-store-order-view.png) -->

<!-- SCREENSHOT: New store checkout page /store/orders/{order_number} with the embedded Stripe Payment Element visible (use test mode). Save as 15-store-payment-element.png. -->
<!-- ![Store on-page Payment Element](../images/15-store-payment-element.png) -->

## Related chapters

- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — keys, SDK, why one Stripe account.
- [14 — Membership pricing matrix](view.php?slug=14-membership-pricing) — how the membership amount is calculated.
- [16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency) — what flips `pending` to `paid`.
- [17 — Refunds](view.php?slug=17-refunds) — how the snapshot drives refundable balance.
- [18 — Invoices](view.php?slug=18-invoices) — PDF reads the order + snapshotted items.
- [19 — Membership lifecycle](view.php?slug=19-membership-lifecycle) — what a paid membership order does downstream.
- [27 — Store architecture](view.php?slug=27-store-architecture) — the `store_*` table family in full.
- [28 — Tickets](view.php?slug=28-tickets) — how ticket products differ at checkout.
- [29 — Discounts, shipping & fees](view.php?slug=29-discounts-shipping) — shipping and processing-fee numbers.
