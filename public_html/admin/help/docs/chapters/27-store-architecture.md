# Store architecture

## For administrators

### What this is

The **members-only association store**. It's where members can buy merch (caps, badges, manuals, patches), and where they buy tickets to rallies and other events.

It is **not** a public retail shop. Non-members can't see the storefront at all — if they hit `/store` without being logged in as a paid-up member, they're bounced to the login page. That's deliberate. The store exists to serve the association, not the wider public.

You (as an admin) manage the catalogue — what's for sale, how much it costs, how much stock is left — and you process the orders that come in.

### What you can do

- **Add and edit products** — give them a name, a price, photos, a description, put them in categories.
- **Set prices** — a base price per product, with the option to charge more (or less) for specific variants (e.g. a 3XL costs $5 more than a M).
- **Manage stock** — type in how many you have on hand; the site decrements it as orders come in.
- **See orders** — every order placed, sorted newest first, with the member's name, what they bought, what they paid.
- **Issue refunds** — full or partial. See [Chapter 17](view.php?slug=17-refunds).
- **Mark orders as shipped** — flips the order's status and emails the member a "your order is on its way" notification.
- **Manage discount codes** — one-off coupons or campaign codes. See [Chapter 29](view.php?slug=29-discounts-shipping).
- **Bulk-import a catalogue** — useful if a supplier sends you a spreadsheet of new merch. See [Chapter 30](view.php?slug=30-catalogue-import).

### Who's allowed

Two roles can manage the store:

- **Admin** — full access to everything.
- **Store Manager** — same access to the store specifically, but doesn't see other parts of admin like members or settings.

If you can't see Store in the admin sidebar, you don't have one of these roles. Ask a site admin to assign you Store Manager.

### Where to find it

![The Store Products list](images/27-products-list.png)

Everything sits under **Admin → Store** in the sidebar:

{{link:/admin/store/orders|Take me to Orders}}
{{link:/admin/store/products|Take me to Products}}
{{tour:admin-add-product}}

- **Orders** — the queue of orders to process.
- **Products** — the catalogue editor.
- **Categories** — group products ("Apparel", "Manuals", "Patches").
- **Tags** — flexible labels for cross-cutting filters ("New", "Clearance").
- **Discounts** — coupon codes.

There's also a **Low stock** page that lists anything running low, and a **Settings** sub-page for store-wide knobs (shipping rates, pickup option, fee passthrough).

### Product types in plain English

When you create a product, you pick its **type**. There are two:

- **Physical** — sweets, patches, caps, manuals, merch. The kinds of things that live in a box and need to be posted to the buyer. Physical products usually have **stock** (a count of how many you have) and need a **shipping address** at checkout.
- **Ticket** — entry to an event (a rally, a dinner, an AGM). When the member pays, the system automatically emails them a unique code — that's their ticket. They show the code at the door. No box, no postage. See [Chapter 28](view.php?slug=28-tickets) for how tickets work end-to-end.

Both types live in the same products list and use the same cart and checkout. The difference is what happens *after* the member pays.

### Variants

A **variant** is a buyable version of a product. The classic example: a t-shirt that comes in **Small**, **Medium**, **Large**. That's **one product** ("Goldwing Club Tee") with **three variants** (S, M, L).

Each variant can have its own price (an XXL might cost a few dollars more) and its own stock count (you might have 10 mediums but only 2 smalls left). The member picks their variant on the product page before adding to cart.

Use variants for size, colour, or anything where it's really the same product in different flavours. If two things are genuinely different items, make them two separate products.

### How orders flow

The path a typical order takes:

1. **Member adds to cart.** They browse `/store`, pick a product, choose a variant if there is one, click "Add to cart".
2. **They check out.** They review the cart, enter a discount code if they have one, pick **shipping** or **pickup** (if you've enabled it), then click through to Stripe.
3. **They pay.** Stripe handles the card details — we never see them. On success Stripe sends the member back to the site.
4. **The order lands in your queue.** Admin → Store → Orders. It'll be sitting there marked **Paid**, waiting for you.
5. **You fulfil it.**
    - For **physical** orders: package it up, post it, then click **Mark as shipped** on the order. The member gets a notification.
    - For **ticket** orders: nothing to do — the system already emailed them their code(s) the moment they paid.
6. **Done.** The order moves to **Completed** once shipped (for physical) or stays at **Paid** with codes issued (for tickets).

### What can go wrong

- **Stock ran out during a sale.** Two members can technically check out simultaneously — if your stock is low, you may end up with one more order than you have items. Apologise, refund the second member, top up your stock count before reopening.
- **Wrong variant ordered.** Member meant to buy a Large but picked Medium. Easiest fix: refund the order ([Ch 17](view.php?slug=17-refunds)) and ask them to re-order the right variant. Don't edit the order in place — the snapshot of what they bought is the audit trail.
- **Shipping address wrong or incomplete.** Email the member, confirm the correct address, ship it there, and add a note on the order so the next admin can see what happened.
- **A member says "I paid but didn't get my ticket code."** Check the order in admin — if it's marked **Paid**, the codes were issued. Look in the order detail for the codes and resend them manually (or ask the member to check their spam folder first).
- **A member wants to cancel an event ticket.** Refund the order; the ticket code is then invalid for entry. See [Chapter 28](view.php?slug=28-tickets) for the ticket side of this.

### Good practice

- **Keep stock numbers accurate.** If you don't enable stock tracking on a product, the store will happily sell it forever, even when you've run out. When new stock arrives, update the count straight away.
- **Process orders within a few business days.** Members notice when their patch takes three weeks. Aim to mark physical orders shipped within 2-3 business days.
- **Mark orders as shipped.** This isn't just bookkeeping — it triggers the "your order is on its way" email so the member knows it's coming.
- **Take good photos.** A clear photo of the actual product sells more than a stock image. Multiple angles for clothing.
- **Use categories and tags consistently.** Categories are for browsing ("Apparel", "Manuals"); tags are for filters and seasonal flags ("New", "Clearance", "Rally 2026"). Don't put everything in every category.
- **Test new products before announcing them.** Add the product, place a test order yourself, refund it, then announce. Catches missing photos, wrong prices, broken variants.

### Who to ask if stuck

- **Can't see Store in the sidebar** — a site admin needs to give you the Admin or Store Manager role.
- **Stripe rejected a payment / refund** — Treasurer (they have the Stripe dashboard login).
- **Shipping rates or pickup setup** — site admin (the knobs are in Settings → Store Settings).
- **Bulk import didn't do what you expected** — your developer; see [Chapter 30](view.php?slug=30-catalogue-import).
- **An error message that reads like jargon** — copy-paste it to your developer; they can decode it.

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

The members-only storefront at `/store` and its admin counterpart at `/admin/store/`. Gateway chapter for Part 7 — lays out the data model (`database/store_module.sql`), the two product types (`physical` and `ticket`), how the cart flows into Stripe, and how shipping, discounts, tickets and bulk import plug into the same tables. The follow-up chapters ([28](view.php?slug=28-tickets), [29](view.php?slug=29-discounts-shipping), [30](view.php?slug=30-catalogue-import)) assume you've read this one.

### Why it exists

The site sells merchandise (caps, badges, manuals) and rally tickets to its members — but it isn't a retail business. So:

- **Members-only by design.** The store serves the association, not the public. Gating the whole storefront behind `require_login()` keeps it simple — no per-product visibility rules, no guest accounts to clean up.
- **One Stripe account, not two.** Memberships and store orders run through the same Stripe account ([Ch 13](view.php?slug=13-stripe-overview)) — the Treasurer reconciles one set of payouts. Order type is distinguished by Stripe Checkout `metadata` — the webhook handler reads `metadata.type` (e.g. `store_order`, `membership_renewal`) to dispatch.
- **Tickets and merch in one product table.** Rally registration is just a product with `type = 'ticket'`. Same catalogue UI, same cart, same checkout — the only divergence is that a paid ticket order generates redeemable codes ([Ch 28](view.php?slug=28-tickets)).

### How it works

#### The data model

All store tables live in `database/store_module.sql` and are prefixed `store_`. The shape:

| Table | Holds |
|---|---|
| `store_settings` | Single-row config (store name, members-only flag, fees, shipping, pickup). |
| `store_categories`, `store_tags` | Taxonomy. Both many-to-many via `store_product_categories` / `store_product_tags`. |
| `store_products` | The catalogue. `type` = `'physical'` \| `'ticket'`; holds `base_price`, `track_inventory`, `stock_quantity`, `low_stock_threshold`, `event_name`, `is_active`. |
| `store_product_images` | Ordered gallery per product. |
| `store_product_options` + `*_option_values` | Option groups ("Size", "Colour") and their allowed values. |
| `store_product_variants` + `*_variant_option_values` | Buyable SKUs — one per option combo, with optional `price_override` and per-variant stock. |
| `store_discounts` | Coupon codes — percent/fixed with date window, max uses, min spend ([Ch 29](view.php?slug=29-discounts-shipping)). |
| `store_carts` + `store_cart_items` | Working cart; items snapshot title/variant/SKU/price at add-time. |
| `store_orders` | Submitted orders. Stripe IDs, totals, shipping address, four status enums. |
| `store_order_items` | Line items with their own snapshots — survives product edits/deletions. |
| `store_order_discounts`, `store_shipments`, `store_order_events` | Discount log, carrier + tracking, per-order timeline. |
| `store_tickets` | One row per ticket unit purchased; carries the unique redeemable `ticket_code`. |

A product can sit in multiple categories and carry multiple tags — categories are the primary browse axis, tags the flexible filters.

#### Storefront flow

`/store` is routed by `public_html/store/index.php`, a single front controller that dispatches by URL segment:

| URL | View | Notes |
|---|---|---|
| `/store` | `catalog.php` | Product grid, filtered by category/tag/search. |
| `/store/product/<slug>` | `product.php` | Single product with variant picker. |
| `/store/cart` | `cart.php` | Cart edit, discount-code entry. |
| `/store/checkout` | `checkout.php` | Shipping/pickup, redirect to Stripe Checkout. |
| `/store/orders`, `/store/order/<order_number>` | `orders.php`, `order_view.php` | "My orders" + order detail / ticket codes. |

If `store.members_only` is on and Stripe `allow_guest_checkout` is off (the default), `require_login()` fires immediately and non-members are bounced to `/login.php?return=…`.

#### Admin flow

`public_html/admin/store/index.php` is the admin front controller. Its pages:

- **`products.php` / `product_form.php`** — catalogue editor (options + variants, images, categories, tags).
- **`categories.php`, `tags.php`** — taxonomy CRUD.
- **`discounts.php`** — coupon CRUD ([Ch 29](view.php?slug=29-discounts-shipping)).
- **`orders.php` / `order_view.php`** — list + detail (fulfilment, refunds, notes). See [Ch 15](view.php?slug=15-orders-checkout), [Ch 17](view.php?slug=17-refunds).
- **`low-stock.php`** — products/variants at or below their threshold (only when `track_inventory = 1`).
- **`settings.php`** — store-specific settings (mirrors `/admin/settings/?section=store`).
- **`merge.php`** — fold one product into another, preserving order history.
- **`import.php`** — bulk catalogue import ([Ch 30](view.php?slug=30-catalogue-import)).

#### Who can manage it

Two roles unlock the admin store: **`admin`** and **`store_manager`**. The store-module migration seeds them via `INSERT IGNORE INTO roles (name) VALUES ('admin'), ('store_manager')`, so a fresh DB has the role waiting. Within admin pages, finer checks use `store_require_permission('store_orders_view')`, `'store_inventory_manage'`, `'store_refunds_manage'` etc. See [Ch 07](view.php?slug=07-roles-permissions).

#### Inventory & low-stock alerts

Inventory tracking is **opt-in per product**: set `track_inventory = 1` and a `stock_quantity` (per variant too if applicable). When the on-hand count hits `low_stock_threshold`, the product surfaces on `/admin/store/low-stock`. Stock decrements at order-paid time via the webhook handler. Products without `track_inventory` are treated as unlimited.

#### Shipping, fees and Stripe

`store_settings` holds three knobs (mirrored in the Settings Hub as `store.*`):

- **Shipping** — flat-rate (`shipping_flat_rate`), with optional free-over-threshold. Pickup is a separate option with custom instructions.
- **Processing fee passthrough** — `stripe_fee_enabled` plus percent/fixed components add the Stripe surcharge to the buyer's total instead of absorbing it.
- **Stripe Checkout** — checkout builds a Session with `metadata.type = 'store_order'` and `metadata.order_id`; Stripe redirects back to `/store/order/<order_number>`.

Webhooks land on `/api/stripe_webhook.php` and are dispatched by `PaymentWebhookService` ([Ch 16](view.php?slug=16-webhooks-idempotency)). The handler reads `metadata.type` and routes store events (`checkout.session.completed`, `charge.refunded`) to the store branch — marking the order paid, decrementing stock, generating ticket codes for ticket items, and sending the confirmation email.

### Where to change it

- **Catalogue, orders, discounts** — `/admin/store/` (sidebar **Store**).
- **Store-wide settings** — `/admin/settings/index.php?section=store` or the mirrored `/admin/store/settings.php`.
- **Code** — `public_html/store/` and `public_html/admin/store/`. Shared helpers in `includes/store_helpers.php`. Order/cart logic in `app/Services/OrderService.php` and `OrderRepository.php`.

### Settings

All store settings are namespaced `store.*` in `settings_global`, edited under **Settings → Store Settings**. Full key list (names, types, defaults) in [Ch 32 — Settings by section](view.php?slug=32-settings-by-section#store). The most-touched: `store.members_only`, `store.shipping_flat_enabled` / `shipping_flat_rate`, `store.shipping_free_threshold`, `store.pickup_enabled`, and the Stripe-fee passthrough trio.

### Gotchas

- **Non-members get bounced.** With `store.members_only = true` and guest checkout off (defaults), every `/store/*` page calls `require_login()` — anonymous visitors land on `/login.php?return=/store/…`. To demo to a non-member, flip the setting; don't comment out the check.
- **`physical` vs `ticket` share the same table.** Both live in `store_products` and use the same cart/checkout path. Divergence happens **after** payment: the webhook creates `store_tickets` rows for ticket items and emails the codes. Don't add a separate "tickets" table — see [Ch 28](view.php?slug=28-tickets).
- **Bulk import replaces variants.** The importer ([Ch 30](view.php?slug=30-catalogue-import)) matches by SKU and is idempotent, but for variant products it **replaces all options/variants** on each run. Carts and orders are safe (snapshots + `ON DELETE SET NULL`), but mid-flight carts may suddenly point at a different variant SKU. Time imports during quiet hours.
- **Order snapshots are the source of truth post-checkout.** Editing the product title or deleting the variant won't change the invoice — the snapshots on `store_order_items` win. Don't "fix" historical orders by editing the product.
- **Inventory only decrements if `track_inventory = 1`.** Forget to enable it and the store will happily oversell. The low-stock page only lists products with tracking on.
- **The webhook is shared with memberships.** `PaymentWebhookService` handles every Stripe event. If a store event goes missing, check the Payments Debug log to confirm it dispatched via `metadata.type`.
- **One Stripe account, two ledgers.** Reconciling store revenue means filtering Stripe payouts by `metadata.type = store_order`. The Treasurer's report does this; ad-hoc dashboard queries don't.

</details>

<!-- SCREENSHOT: Storefront /store as a logged-in member. Save as 27-storefront-catalog.png. -->
<!-- ![Storefront catalog](../images/27-storefront-catalog.png) -->

<!-- SCREENSHOT: Single product page with variant picker. Save as 27-storefront-product.png. -->
<!-- ![Single product page](../images/27-storefront-product.png) -->

<!-- SCREENSHOT: /admin/store/products list with filters. Save as 27-admin-products.png. -->
<!-- ![Admin products page](../images/27-admin-products.png) -->

<!-- SCREENSHOT: /admin/settings/index.php?section=store. Save as 27-store-settings.png. -->
<!-- ![Store settings](../images/27-store-settings.png) -->

## Related chapters

- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — `store_manager` role and `store_*` permission keys.
- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — shared Stripe account and `metadata` conventions.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — Place order → Stripe success.
- [16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency) — `PaymentWebhookService` dispatch.
- [17 — Refunds](view.php?slug=17-refunds) — refunding a store order.
- [28 — Tickets](view.php?slug=28-tickets) — `type = 'ticket'` and `store_tickets` codes.
- [29 — Discounts, shipping & fees](view.php?slug=29-discounts-shipping) — coupons, shipping, fee passthrough.
- [30 — Bulk catalogue import](view.php?slug=30-catalogue-import) — the JSON importer.
- [32 — Settings by section](view.php?slug=32-settings-by-section#store) — full `store.*` reference.
