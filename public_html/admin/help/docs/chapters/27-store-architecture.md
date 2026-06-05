# Store architecture

## What this covers

The members-only storefront at `/store` and its admin counterpart at `/admin/store/`. Gateway chapter for Part 7 — lays out the data model (`database/store_module.sql`), the two product types (`physical` and `ticket`), how the cart flows into Stripe, and how shipping, discounts, tickets and bulk import plug into the same tables. The follow-up chapters ([28](view.php?slug=28-tickets), [29](view.php?slug=29-discounts-shipping), [30](view.php?slug=30-catalogue-import)) assume you've read this one.

## Why it exists

The site sells merchandise (caps, badges, manuals) and rally tickets to its members — but it isn't a retail business. So:

- **Members-only by design.** The store serves the association, not the public. Gating the whole storefront behind `require_login()` keeps it simple — no per-product visibility rules, no guest accounts to clean up.
- **One Stripe account, not two.** Memberships and store orders run through the same Stripe account ([Ch 13](view.php?slug=13-stripe-overview)) — the Treasurer reconciles one set of payouts. Order type is distinguished by Stripe Checkout `metadata` — the webhook handler reads `metadata.type` (e.g. `store_order`, `membership_renewal`) to dispatch.
- **Tickets and merch in one product table.** Rally registration is just a product with `type = 'ticket'`. Same catalogue UI, same cart, same checkout — the only divergence is that a paid ticket order generates redeemable codes ([Ch 28](view.php?slug=28-tickets)).

## How it works

### The data model

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

### Storefront flow

`/store` is routed by `public_html/store/index.php`, a single front controller that dispatches by URL segment:

| URL | View | Notes |
|---|---|---|
| `/store` | `catalog.php` | Product grid, filtered by category/tag/search. |
| `/store/product/<slug>` | `product.php` | Single product with variant picker. |
| `/store/cart` | `cart.php` | Cart edit, discount-code entry. |
| `/store/checkout` | `checkout.php` | Shipping/pickup, redirect to Stripe Checkout. |
| `/store/orders`, `/store/order/<order_number>` | `orders.php`, `order_view.php` | "My orders" + order detail / ticket codes. |

If `store.members_only` is on and Stripe `allow_guest_checkout` is off (the default), `require_login()` fires immediately and non-members are bounced to `/login.php?return=…`.

### Admin flow

`public_html/admin/store/index.php` is the admin front controller. Its pages:

- **`products.php` / `product_form.php`** — catalogue editor (options + variants, images, categories, tags).
- **`categories.php`, `tags.php`** — taxonomy CRUD.
- **`discounts.php`** — coupon CRUD ([Ch 29](view.php?slug=29-discounts-shipping)).
- **`orders.php` / `order_view.php`** — list + detail (fulfilment, refunds, notes). See [Ch 15](view.php?slug=15-orders-checkout), [Ch 17](view.php?slug=17-refunds).
- **`low-stock.php`** — products/variants at or below their threshold (only when `track_inventory = 1`).
- **`settings.php`** — store-specific settings (mirrors `/admin/settings/?section=store`).
- **`merge.php`** — fold one product into another, preserving order history.
- **`import.php`** — bulk catalogue import ([Ch 30](view.php?slug=30-catalogue-import)).

### Who can manage it

Two roles unlock the admin store: **`admin`** and **`store_manager`**. The store-module migration seeds them via `INSERT IGNORE INTO roles (name) VALUES ('admin'), ('store_manager')`, so a fresh DB has the role waiting. Within admin pages, finer checks use `store_require_permission('store_orders_view')`, `'store_inventory_manage'`, `'store_refunds_manage'` etc. See [Ch 07](view.php?slug=07-roles-permissions).

### Inventory & low-stock alerts

Inventory tracking is **opt-in per product**: set `track_inventory = 1` and a `stock_quantity` (per variant too if applicable). When the on-hand count hits `low_stock_threshold`, the product surfaces on `/admin/store/low-stock`. Stock decrements at order-paid time via the webhook handler. Products without `track_inventory` are treated as unlimited.

### Shipping, fees and Stripe

`store_settings` holds three knobs (mirrored in the Settings Hub as `store.*`):

- **Shipping** — flat-rate (`shipping_flat_rate`), with optional free-over-threshold. Pickup is a separate option with custom instructions.
- **Processing fee passthrough** — `stripe_fee_enabled` plus percent/fixed components add the Stripe surcharge to the buyer's total instead of absorbing it.
- **Stripe Checkout** — checkout builds a Session with `metadata.type = 'store_order'` and `metadata.order_id`; Stripe redirects back to `/store/order/<order_number>`.

Webhooks land on `/api/stripe_webhook.php` and are dispatched by `PaymentWebhookService` ([Ch 16](view.php?slug=16-webhooks-idempotency)). The handler reads `metadata.type` and routes store events (`checkout.session.completed`, `charge.refunded`) to the store branch — marking the order paid, decrementing stock, generating ticket codes for ticket items, and sending the confirmation email.

## Where to change it

- **Catalogue, orders, discounts** — `/admin/store/` (sidebar **Store**).
- **Store-wide settings** — `/admin/settings/index.php?section=store` or the mirrored `/admin/store/settings.php`.
- **Code** — `public_html/store/` and `public_html/admin/store/`. Shared helpers in `includes/store_helpers.php`. Order/cart logic in `app/Services/OrderService.php` and `OrderRepository.php`.

## Settings

All store settings are namespaced `store.*` in `settings_global`, edited under **Settings → Store Settings**. Full key list (names, types, defaults) in [Ch 32 — Settings by section](view.php?slug=32-settings-by-section#store). The most-touched: `store.members_only`, `store.shipping_flat_enabled` / `shipping_flat_rate`, `store.shipping_free_threshold`, `store.pickup_enabled`, and the Stripe-fee passthrough trio.

## Screenshots

<!-- SCREENSHOT: Storefront /store as a logged-in member. Save as 27-storefront-catalog.png. -->
<!-- ![Storefront catalog](../images/27-storefront-catalog.png) -->

<!-- SCREENSHOT: Single product page with variant picker. Save as 27-storefront-product.png. -->
<!-- ![Single product page](../images/27-storefront-product.png) -->

<!-- SCREENSHOT: /admin/store/products list with filters. Save as 27-admin-products.png. -->
<!-- ![Admin products page](../images/27-admin-products.png) -->

<!-- SCREENSHOT: /admin/settings/index.php?section=store. Save as 27-store-settings.png. -->
<!-- ![Store settings](../images/27-store-settings.png) -->

## Gotchas

- **Non-members get bounced.** With `store.members_only = true` and guest checkout off (defaults), every `/store/*` page calls `require_login()` — anonymous visitors land on `/login.php?return=/store/…`. To demo to a non-member, flip the setting; don't comment out the check.
- **`physical` vs `ticket` share the same table.** Both live in `store_products` and use the same cart/checkout path. Divergence happens **after** payment: the webhook creates `store_tickets` rows for ticket items and emails the codes. Don't add a separate "tickets" table — see [Ch 28](view.php?slug=28-tickets).
- **Bulk import replaces variants.** The importer ([Ch 30](view.php?slug=30-catalogue-import)) matches by SKU and is idempotent, but for variant products it **replaces all options/variants** on each run. Carts and orders are safe (snapshots + `ON DELETE SET NULL`), but mid-flight carts may suddenly point at a different variant SKU. Time imports during quiet hours.
- **Order snapshots are the source of truth post-checkout.** Editing the product title or deleting the variant won't change the invoice — the snapshots on `store_order_items` win. Don't "fix" historical orders by editing the product.
- **Inventory only decrements if `track_inventory = 1`.** Forget to enable it and the store will happily oversell. The low-stock page only lists products with tracking on.
- **The webhook is shared with memberships.** `PaymentWebhookService` handles every Stripe event. If a store event goes missing, check the Payments Debug log to confirm it dispatched via `metadata.type`.
- **One Stripe account, two ledgers.** Reconciling store revenue means filtering Stripe payouts by `metadata.type = store_order`. The Treasurer's report does this; ad-hoc dashboard queries don't.

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
