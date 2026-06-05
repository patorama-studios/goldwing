# Discounts, shipping & fees

## For administrators

### What this is

The commerce dials. This is the page (well, three pages) where you decide what members pay on top of the price tag. Three levers:

- **Discounts** — promo codes a member types at checkout for money off.
- **Shipping** — the flat freight rate added to mail-order items.
- **Processing fee passthrough** — whether the member pays Stripe's ~2.5% card-handling fee, or whether the association absorbs it.

All three are tweakable from admin without anyone touching code.

### What you can do here

- Create a **discount code** (percentage or fixed dollar amount, with a date window and a use limit).
- Edit or kill an existing discount (the kill switch overrides everything else).
- Set a **flat-rate shipping price** that gets added to mail-order purchases.
- Optionally set a **free-shipping threshold** (e.g. "free shipping over $100").
- Toggle whether **Stripe's processing fee** is added to the member's total or absorbed by the association.
- Turn on/off **pickup** as a fulfilment option (which makes shipping zero for those orders).

### Who's allowed

- **Admin**
- **Store Manager**

If you don't see the discounts page or the store settings card, your role doesn't have the permission. Ask an admin.

### Where to find it in admin

Three places, depending on what you're changing:

1. **Discount codes** — Admin → Store → Discounts.
2. **Shipping rate, free threshold, pickup** — Admin → Store → Settings *(legacy URL — it redirects to Settings → Store Settings).*
3. **Processing fee passthrough, GST, all the dials together** — Admin → Settings → Store Settings.

### How to create a discount code (step by step)

1. Go to **Admin → Store → Discounts**.
2. Click **New discount**.
3. Fill in:
    - **Code** — what the member types at checkout (e.g. `WINTER25`). Saved in uppercase.
    - **Type** — `Percent` (e.g. 10% off) or `Fixed` (e.g. $20 off).
    - **Value** — the number. Percent is 0–100; fixed is dollars.
    - **Valid from / valid to** — optional date window (inclusive). Leave blank for "no limit".
    - **Max uses** — optional cap on how many times the code can be redeemed across all members. Leave blank for unlimited.
    - **Minimum spend** — optional subtotal floor (e.g. only valid on orders over $50).
4. Save.

The discount applies to the **subtotal only** — not to shipping, GST, or the processing fee. That's intentional.

To stop a code working immediately (even mid-date-window), edit it and untick **Active**. That's the kill switch.

### How to set flat-rate shipping

1. Go to **Admin → Settings → Store Settings**.
2. Find the **Shipping & fees** card.
3. Turn on **Flat-rate shipping**.
4. Enter the dollar amount (e.g. `12.50`).
5. *(Optional)* Turn on **Free shipping over** and enter a threshold (e.g. `100`) — orders above that subtotal get free shipping automatically.
6. *(Optional)* Turn on **Pickup** if you want to offer pickup-instead-of-post as a checkout option. When a member picks pickup, shipping is zero.
7. Save.

Shipping is one flat line on the order — it's not split across products, and it's not per-region. International freight at a different rate isn't supported today.

### How the processing fee passthrough works

Stripe charges the association roughly **1.7% + $0.30** for every card transaction. You get to choose who wears that.

- **Passthrough ON** (default) — the fee is added to the member's total at checkout as a separate line called **"Payment processing fee"**. They pay the listed price + shipping + GST + the fee. The association nets the full listed price.
- **Passthrough OFF** — the fee comes out of what the association receives. The member only pays the listed price (+ shipping + GST). The association absorbs the ~2.5%.

The toggle lives at **Admin → Settings → Store Settings → Stripe processing fee**. There's a percent field and a fixed field — set them to whatever Stripe is currently charging you (check the Stripe dashboard if unsure).

The formula grosses up — meaning the fee covers itself. Don't enter Stripe's rate plus a margin "to be safe"; the maths already does that.

### What can go wrong (and what to do)

- **"My discount code isn't working."** — Three common reasons. (1) Typo — codes are case-insensitive but a missing letter still fails. (2) Outside the date window. (3) Hit the max-uses cap. (4) Order subtotal is below the minimum spend. The discount page shows the use count and date window for each code — check there first.
- **"The code worked an hour ago and now it doesn't."** — Probably hit the use cap, or someone toggled it inactive. Check the **Active** flag and the `used_count` on the discounts list.
- **"Shipping wasn't charged on a ticket order."** — Intentional. Event tickets and digital products don't post anywhere, so shipping is skipped. Only mail-order physical products attract the freight charge.
- **"A member's complaining the total at checkout is higher than the price tag."** — Almost always the processing fee passthrough. They paid the listed price + GST + shipping + ~2.5% fee. Two fixes: (1) explain it on the product page so it's not a surprise, or (2) turn the passthrough off if the association would rather absorb the fee.
- **"Two discounts seemed to combine."** — The system only allows one code per checkout, but if a member typed one code then refreshed and typed another, the cart can briefly show stacked behaviour. The order itself only records the final code. If you see a real double-discount on a placed order, screenshot it and send to the developer — that's a bug.
- **"Discount expired between the cart and checkout."** — A member can sit on a cart for hours. The code is re-validated at the final payment step, so an expired one silently drops off. There's no "your discount expired" warning. If a member complains, refund the difference or extend the code.

### Good practice

- **Always set a date window.** Open-ended codes get forwarded around forever. A code with `valid_to = 2026-06-30` expires itself.
- **Always set a max-uses cap on shareable codes.** Especially for social-media promos. 50 uses is fine for most member-only codes; raise it if needed.
- **Explain the processing fee on the product page.** One line: *"A small Stripe card-processing fee is added at checkout."* That single sentence prevents 90% of the "why is my total higher?" emails.
- **Match Stripe's actual rate.** Check the Stripe dashboard quarterly — Stripe occasionally adjusts rates and you don't want the passthrough under- or over-charging.
- **Reconcile monthly.** Pull the store orders total for the month and compare to Stripe deposits. They should match within the fee. Discrepancies are very rare but worth catching.
- **Use "free shipping over $X" sparingly.** It boosts cart size but eats margin if the threshold is too low. $100 is a reasonable floor for the Goldwing store.

### Who to ask if you're stuck

- **Discount not applying and you can't figure out why** — Treasurer or developer. Send the code and the order number.
- **Total at checkout looks wrong** — developer. Send a screenshot of the checkout summary and the order ID.
- **Stripe rate changed and the passthrough is now wrong** — admin or developer; update the percent and fixed fields under Settings → Store Settings.
- **Shipping needs to be different for a one-off (e.g. heavy item)** — there's no per-product shipping today. Either absorb it or create a fixed-amount discount equal to the regular shipping rate.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

The three commerce levers that sit between an order's line items and its final total: **discounts** (the `store_discounts` table and `/admin/store/discounts.php`), **shipping** (flat-rate plus optional free-over-threshold, configured under Settings → Store Settings), and **fees** (the optional Stripe processing-fee passthrough). All three flow through the totals calculator in `includes/store_helpers.php` and end up as columns on the `store_orders` row — see [Ch 15 — Orders & checkout](view.php?slug=15-orders-checkout) for the lifecycle they plug into.

### Why it exists

- **Discounts** let the committee run promotions (member-only codes, event giveaways) without writing code. Codes are date- and use-limited so they can be created in advance and expired safely.
- **Shipping** is configurable rather than hard-coded because freight prices change and because we sometimes want to run "free over $X" promotions without touching the codebase.
- **Fees** — the Stripe processing-fee passthrough — exist because the store has thin margins and absorbing Stripe's ~1.7% + $0.30 surcharge eats into the result. Showing the fee as its own line is unusual in retail but normal in club/community commerce, and it's the committee's deliberate choice. The setting is on by default but can be turned off without a redeploy.

### How it works

#### Discounts

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

#### Shipping

Shipping is configured in **Settings → Store Settings** (the legacy `/admin/store/settings.php` redirects there) and pulled at checkout via `store_get_settings()`. Rules (`store_calculate_shipping()` at `includes/store_helpers.php:304`):

1. If buyer chose **pickup** (`store.pickup_enabled` + `fulfillment === 'pickup'`), shipping = `0`.
2. Else if `store.shipping_free_enabled` and post-discount subtotal meets `store.shipping_free_threshold`, shipping = `0`.
3. Else if `store.shipping_flat_enabled`, shipping = `store.shipping_flat_rate`.
4. Else `0`.

It's a single dollar amount stored on the order as `store_orders.shipping_total` and shown as one line on the cart, checkout summary, and invoice — **not distributed per item**. The order has one shipment address and one freight cost; splitting it would invent precision that isn't there.

Per-region rates are **not implemented**. There's a `store.shipping_region` setting (`AU` / `INTL`) but it's recorded only.

The **bulk catalogue importer** (`scripts/import_store_catalogue.php`) accepts `--update-shipping`, which lifts `shipping.flat_rate` out of the catalogue JSON and writes it into `store_settings`. See [Ch 30 — Bulk catalogue import](view.php?slug=30-catalogue-import).

#### Fees

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

#### Pricing display

Product prices live in `store_products.base_price` as `DECIMAL(10,2)` — **dollars, not cents** (membership pricing uses cents; the store does not). Currency is **AUD** throughout; no multi-currency. Formatting goes through `store_money()`.

### Where to change it

- **Discounts** — Admin → Store → Discounts (`/admin/store/discounts.php`).
- **Shipping, fees, GST, pickup** — Admin → Settings → Store Settings (`/admin/settings/index.php?section=store`).

Both pages write through `SettingsService::setGlobal()`, which stamps the change into [`audit_log`](view.php?slug=08-activity-audit).

### Settings

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

### Gotchas

- **Discount validation happens at checkout time.** A buyer can apply a code to their cart, walk away, and come back after it has expired — the validator runs again on the checkout POST and the order will be created without the discount. There's no "your discount expired" warning on the cart page.
- **`is_active` overrides the date window.** Setting `is_active = 0` blocks the code immediately even if it's still inside its date range. Use that as the kill switch.
- **Discounts apply to subtotal only.** They don't reduce shipping, GST or the processing fee. For "free shipping" as a promo, use a `fixed` discount equal to your shipping rate.
- **Processing-fee passthrough is unusual in retail.** Buyers used to Amazon/eBay expect the seller to absorb gateway fees. Checkout labels it "Payment processing fee" on its own line, but **communicate it in the product copy** — it's a common refund/complaint trigger. Turn it off via `store.pass_stripe_fees` if you'd rather absorb.
- **Per-region shipping is not implemented.** `store.shipping_region` is recorded but rates are the same for everyone. International freight = code change at `includes/store_helpers.php:304`.
- **The fee formula grosses up.** Don't enter Stripe's headline rate plus a margin "to be safe" — `1 / (1 - rate)` already does that. Entering 5% when Stripe charges 1.7% will overcharge noticeably.
- **Shipping is a single line, not per-item.** When refunding partial orders, shipping is refunded separately via [Ch 17 — Refunds](view.php?slug=17-refunds), not pro-rated.

</details>

<!-- SCREENSHOT: The discount create/edit form at /admin/store/discounts.php, showing code, type, value, date window, max-uses and min-spend alongside the discounts list. Save to public_html/admin/help/images/29-discount-form.png. -->
<!-- ![Discount form](../images/29-discount-form.png) -->

<!-- SCREENSHOT: The Shipping & fees card on /admin/settings/index.php?section=store — flat-rate, free threshold, and the Stripe fee passthrough toggle + percent/fixed inputs. Save as 29-store-settings-shipping.png. -->
<!-- ![Store settings — shipping & fees](../images/29-store-settings-shipping.png) -->

<!-- SCREENSHOT: The /store/checkout.php order summary with subtotal, discount, shipping, GST and "Payment processing fee" each on their own row. Save as 29-checkout-fee-breakdown.png. -->
<!-- ![Checkout fee breakdown](../images/29-checkout-fee-breakdown.png) -->

## Related chapters

- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — the lifecycle these totals plug into.
- [17 — Refunds](view.php?slug=17-refunds) — why per-line discount apportionment matters when refunding.
- [18 — Invoices](view.php?slug=18-invoices) — how shipping, GST and the processing fee appear on the PDF.
- [27 — Store architecture](view.php?slug=27-store-architecture) — the full store data model.
- [30 — Bulk catalogue import](view.php?slug=30-catalogue-import) — the `--update-shipping` flag that syncs the flat rate from JSON.
- [32 — Settings by section](view.php?slug=32-settings-by-section#store) — the canonical list of every `store.*` key.
