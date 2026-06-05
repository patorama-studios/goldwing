# Discounts, shipping & fees

## What this covers

The three commerce levers that sit between an order's line items and its final total: **discounts** (the `store_discounts` table and `/admin/store/discounts.php`), **shipping** (flat-rate plus optional free-over-threshold, configured under Settings → Store Settings), and **fees** (the optional Stripe processing-fee passthrough). All three flow through the totals calculator in `includes/store_helpers.php` and end up as columns on the `store_orders` row — see [Ch 15 — Orders & checkout](view.php?slug=15-orders-checkout) for the lifecycle they plug into.

## Why it exists

- **Discounts** let the committee run promotions (member-only codes, event giveaways) without writing code. Codes are date- and use-limited so they can be created in advance and expired safely.
- **Shipping** is configurable rather than hard-coded because freight prices change and because we sometimes want to run "free over $X" promotions without touching the codebase.
- **Fees** — the Stripe processing-fee passthrough — exist because the store has thin margins and absorbing Stripe's ~1.7% + $0.30 surcharge eats into the result. Showing the fee as its own line is unusual in retail but normal in club/community commerce, and it's the committee's deliberate choice. The setting is on by default but can be turned off without a redeploy.

## How it works

### Discounts

`store_discounts` is the table:

| Column | Notes |
|---|---|
| `code` | Uppercased on save; UNIQUE. |
| `type` | `percent` or `fixed`. No "free shipping" type. |
| `value` | Percent (0–100) or fixed dollars. |
| `start_date`, `end_date` | Optional date window, inclusive. |
| `max_uses`, `used_count` | Optional cap; `used_count` bumps after successful checkout. |
| `min_spend` | Optional subtotal floor. |
| `is_active` | Manual kill switch — overrides the date window. |

Scope is **cart-wide on the subtotal only**. There's no per-product or per-category targeting in the schema today (the admin page says so directly: *"Create discounts that apply to product subtotal only"*). Discounts are **code-based** — no automatic triggers.

The validator (`store_validate_discount_code()` in `includes/store_helpers.php:373`) checks, in order: code exists and is active, date window, max-uses cap, min-spend met. First failure wins.

Math (`store_calculate_discount()`):

- `percent` → `round(subtotal * value/100, 2)`
- `fixed` → `min(round(value, 2), subtotal)` (can never exceed subtotal)

**Apportionment.** Once the cart-wide discount amount is known, `store_apply_discount_to_items()` distributes it pro-rata across line items by `lineTotal / subtotal`, with the remainder dumped onto the last line so rounded shares add up exactly. The result is written into each item's `unit_price_final` and `line_total` — which is what makes [refunds](view.php?slug=17-refunds) able to compute the correct per-line refund amount. Each successful checkout also writes a row into `store_order_discounts` so the discount stays attached to the order even if the underlying `store_discounts` row is later edited.

### Shipping

Shipping is configured in **Settings → Store Settings** (the legacy `/admin/store/settings.php` redirects there) and pulled at checkout via `store_get_settings()`. Rules (`store_calculate_shipping()` at `includes/store_helpers.php:304`):

1. If buyer chose **pickup** (`store.pickup_enabled` + `fulfillment === 'pickup'`), shipping = `0`.
2. Else if `store.shipping_free_enabled` and post-discount subtotal meets `store.shipping_free_threshold`, shipping = `0`.
3. Else if `store.shipping_flat_enabled`, shipping = `store.shipping_flat_rate`.
4. Else `0`.

It's a single dollar amount stored on the order as `store_orders.shipping_total` and shown as one line on the cart, checkout summary, and invoice — **not distributed per item**. The order has one shipment address and one freight cost; splitting it would invent precision that isn't there.

Per-region rates are **not implemented**. There's a `store.shipping_region` setting (`AU` / `INTL`) but it's recorded only.

The **bulk catalogue importer** (`scripts/import_store_catalogue.php`) accepts `--update-shipping`, which lifts `shipping.flat_rate` out of the catalogue JSON and writes it into `store_settings`. See [Ch 30 — Bulk catalogue import](view.php?slug=30-catalogue-import).

### Fees

The Stripe processing-fee passthrough is controlled by three settings:

- `store.pass_stripe_fees` (bool) — master switch.
- `store.stripe_fee_percent` (float) — typically ~1.7 for AU domestic cards.
- `store.stripe_fee_fixed` (float) — typically 0.30.

`store_calculate_processing_fee()` uses a **grossed-up** formula so the fee covers itself:

```
fee = (rate * baseAmount + fixed) / (1 - rate)
```

where `baseAmount = subtotalAfterDiscount + shippingTotal + taxTotal`. The buyer pays exactly enough that the merchant nets `baseAmount` after Stripe takes its cut of `total + fee`. The fee lands in `store_orders.processing_fee_total` and appears as a separate line in the Stripe Checkout Session and on the [invoice](view.php?slug=18-invoices).

GST (`store.gst_enabled`) is a flat 10% on the post-discount subtotal, added to the base before the fee is grossed up. Invoices itemise GST separately for ATO purposes — see [Ch 18 — Invoices](view.php?slug=18-invoices).

### Pricing display

Product prices live in `store_products.base_price` as `DECIMAL(10,2)` — **dollars, not cents** (membership pricing uses cents; the store does not). Currency is **AUD** throughout; no multi-currency. Formatting goes through `store_money()`.

## Where to change it

- **Discounts** — Admin → Store → Discounts (`/admin/store/discounts.php`).
- **Shipping, fees, GST, pickup** — Admin → Settings → Store Settings (`/admin/settings/index.php?section=store`).

Both pages write through `SettingsService::setGlobal()`, which stamps the change into [`audit_log`](view.php?slug=08-activity-audit).

## Settings

All keys live under `store.*` in `settings_global`. Full reference in [Ch 32 — Settings by section](view.php?slug=32-settings-by-section#store):

| Key | Type | Default | Notes |
|---|---|---|---|
| `store.pass_stripe_fees` | bool | `true` | Master switch for fee passthrough. |
| `store.stripe_fee_percent` | float | `0.00` | Percent component (e.g. `1.7`). |
| `store.stripe_fee_fixed` | float | `0.00` | Fixed dollar component (e.g. `0.30`). |
| `store.shipping_flat_enabled` | bool | `false` | Charge a flat shipping rate. |
| `store.shipping_flat_rate` | float\|null | `null` | Flat-rate amount in dollars. |
| `store.shipping_free_enabled` | bool | `false` | Enable free-over-threshold. |
| `store.shipping_free_threshold` | float\|null | `null` | Subtotal at which shipping becomes free. |
| `store.shipping_region` | enum | `"AU"` | Recorded only; not used by calculator. |
| `store.gst_enabled` | bool | `true` | Adds 10% GST to the order. |
| `store.pickup_enabled` | bool | `false` | Offers pickup as a fulfilment option (shipping = 0). |
| `store.pickup_instructions` | string | `""` | Shown when the buyer picks pickup. |

The `store_discounts` table is its own thing — discounts are rows, not settings.

## Screenshots

<!-- SCREENSHOT: The discount create/edit form at /admin/store/discounts.php, showing code, type, value, date window, max-uses and min-spend alongside the discounts list. Save to public_html/admin/help/images/29-discount-form.png. -->
<!-- ![Discount form](../images/29-discount-form.png) -->

<!-- SCREENSHOT: The Shipping & fees card on /admin/settings/index.php?section=store — flat-rate, free threshold, and the Stripe fee passthrough toggle + percent/fixed inputs. Save as 29-store-settings-shipping.png. -->
<!-- ![Store settings — shipping & fees](../images/29-store-settings-shipping.png) -->

<!-- SCREENSHOT: The /store/checkout.php order summary with subtotal, discount, shipping, GST and "Payment processing fee" each on their own row. Save as 29-checkout-fee-breakdown.png. -->
<!-- ![Checkout fee breakdown](../images/29-checkout-fee-breakdown.png) -->

## Gotchas

- **Discount validation happens at checkout time.** A buyer can apply a code to their cart, walk away, and come back after it has expired — the validator runs again on the checkout POST and the order will be created without the discount. There's no "your discount expired" warning on the cart page.
- **`is_active` overrides the date window.** Setting `is_active = 0` blocks the code immediately even if it's still inside its date range. Use that as the kill switch.
- **Discounts apply to subtotal only.** They don't reduce shipping, GST or the processing fee. For "free shipping" as a promo, use a `fixed` discount equal to your shipping rate.
- **Processing-fee passthrough is unusual in retail.** Buyers used to Amazon/eBay expect the seller to absorb gateway fees. Checkout labels it "Payment processing fee" on its own line, but **communicate it in the product copy** — it's a common refund/complaint trigger. Turn it off via `store.pass_stripe_fees` if you'd rather absorb.
- **Per-region shipping is not implemented.** `store.shipping_region` is recorded but rates are the same for everyone. International freight = code change at `includes/store_helpers.php:304`.
- **The fee formula grosses up.** Don't enter Stripe's headline rate plus a margin "to be safe" — `1 / (1 - rate)` already does that. Entering 5% when Stripe charges 1.7% will overcharge noticeably.
- **Shipping is a single line, not per-item.** When refunding partial orders, shipping is refunded separately via [Ch 17 — Refunds](view.php?slug=17-refunds), not pro-rated.

## Related chapters

- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — the lifecycle these totals plug into.
- [17 — Refunds](view.php?slug=17-refunds) — why per-line discount apportionment matters when refunding.
- [18 — Invoices](view.php?slug=18-invoices) — how shipping, GST and the processing fee appear on the PDF.
- [27 — Store architecture](view.php?slug=27-store-architecture) — the full store data model.
- [30 — Bulk catalogue import](view.php?slug=30-catalogue-import) — the `--update-shipping` flag that syncs the flat rate from JSON.
- [32 — Settings by section](view.php?slug=32-settings-by-section#store) — the canonical list of every `store.*` key.
