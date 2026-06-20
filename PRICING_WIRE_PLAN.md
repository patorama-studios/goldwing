# Pricing Wire-Up Plan

**Goal (Pat):** The admin pricing matrix is the source of truth. Make sure the
*Become a Member* page and the *sign-up form* both read pricing **dynamically**
from the admin config, so any change an admin makes reflects on the front end
**and** the backend (what Stripe actually charges) immediately — no hard-coded
prices, no second copy of the truth.

**Status of this doc:** Decision (2026-06-20): **keep `/become-a-member` and wire
it to the matrix** (consistent with the `apply.php` flow). **Implemented** (see
§6). The requested "strip the orphan Stripe Price IDs" is **blocked** — they turn
out not to be orphaned (see §7). Awaiting staging verification after deploy.

---

## 1. Current state (mapped before touching anything)

There are **two independent pricing systems** and **two separate sign-up flows**
in the codebase. This is the root issue.

### System 1 — The committee matrix (the source of truth) ✅ already dynamic

| Thing | Where |
|---|---|
| Service | `app/Services/MembershipPricingService.php` |
| Stored config key | `membership.pricing.config` (in `settings_global`) |
| Admin editor | `/admin/settings/?section=membership_pricing` → saves via `MembershipPricingService::updateConfig()` (`public_html/admin/settings/index.php:662`) |
| Contents | Real dollar amounts (cents): renewal periods + renewal prices + **joining matrix** (per magazine × type × period × join-window, with the $15 joining fee baked in) + pro-rata engine + membership-year anchor/expiry |

This is the table the treasurer/committee reconciles against. It is **cents-based**
and term/joining-fee/pro-rata shaped — it does **not** map onto recurring Stripe
subscription "Price" objects.

### System 2 — Stripe Price IDs (a separate, manual list) ⚠️ decoupled

| Thing | Where |
|---|---|
| Service | `app/Services/StripeSettingsService.php` |
| Stored config key | `payments.membership_prices` (in `settings_global`) |
| Admin editor | `/admin/settings/?section=payments` → "Membership Stripe Price IDs" card → saves via `StripeSettingsService::saveAdminSettings()` |
| Contents | Stripe **Price IDs** (strings like `price_…`), keyed `FULL_12 / FULL_24 / FULL_36 / ASSOCIATE_12 / …`. **Not dollar amounts.** |

Nothing syncs System 1 → System 2. Editing the committee matrix has **zero**
effect on these Price IDs.

### The two sign-up flows

**Flow A — the canonical, matrix-driven path (already correct):**

```
Homepage "Join the Association"  (index.php:152 → /?page=membership)
   → app/Views/partials/membership_content.php   [renders prices DYNAMICALLY from System 1]
      → "Apply today"  (membership_content.php:158,199 → /apply.php)
         → public_html/apply.php                  [renders join options DYNAMICALLY from System 1]
            → POST /api/stripe/create-application-payment-intent
               → MembershipPricingService::resolveJoinPriceCents()   [server recomputes the charge]
```

- Front-end prices: dynamic from the matrix (`getMembershipPricing()`,
  `getJoinOptions()`, `buildProRataPreview()`).
- **Server-side price authority: SOLID.** Both the `apply.php` POST handler
  (`apply.php:194,200`) and the API (`api/index.php:760,772`) call
  `resolveJoinPriceCents()` to compute the amount from the matrix. The
  client-submitted period **key** is used, but the **price is always looked up
  server-side** — a tampered amount cannot be charged. ✅
- Payment model: one-off Stripe **PaymentIntent** (matches the committee's
  term + joining-fee + pro-rata model).

**Flow B — the decoupled legacy path (the gap):**

```
/become-a-member   (become-a-member/index.php → require become-a-member.php)
404 page link (404.php:117) + post-payment retry (memberships/success/index.php:40)
   → public_html/become-a-member.php             [shows NO prices; term dropdown only]
      → POST /api/stripe/create-subscription
         → reads Stripe Price IDs from System 2 (api/index.php:1216-1243)
            → Stripe recurring SUBSCRIPTION
```

- Front-end prices: **none shown at all**.
- Backend charge: whatever the hand-entered Stripe Price ID says — **ignores the
  committee matrix entirely**.
- Payment model: recurring **subscription** (auto-renew), a different product
  behaviour from Flow A.
- Security note: not exploitable (Stripe enforces the server-set Price ID, client
  can't inject an amount) — but it charges from the **wrong source**.

### Caching

No cache work is required. `SettingsService` keeps only a per-request in-memory
static cache (`SettingsService.php:8-9`) that is invalidated on every write
(`:73`). There is no Redis, no transients, no page cache. **Admin price edits take
effect on the very next request.**

---

## 2. Gaps (admin matrix → each surface)

| Surface | Reads the matrix? | Server-authoritative price? | Gap |
|---|---|---|---|
| Homepage `/?page=membership` | ✅ yes | n/a (display only) | none |
| Sign-up form `/apply.php` (+ its API) | ✅ yes | ✅ yes (`resolveJoinPriceCents`) | none |
| Renewal flow (`/api/payments/membership-intent`) | ✅ yes | ✅ yes (`renewalAmountCents`) | none |
| **`/become-a-member` (`become-a-member.php` + `create-subscription`)** | ❌ **no** | charges from System 2 Stripe Price IDs | **decoupled — won't reflect matrix changes; shows no prices** |

**So the only real break is Flow B.** Everything Pat clicks from the homepage is
already wired correctly; the hazard is the second, parallel `/become-a-member`
entry point that nobody re-pointed at the matrix when the matrix was built.

---

## 3. Decision needed before implementation

Flow B can't be "made dynamic" without choosing a direction, because the
committee matrix (one-off, term + joining-fee + pro-rata, in cents) does not map
cleanly onto recurring Stripe subscription Price IDs. The options:

### Option 1 — Consolidate on the matrix flow *(recommended)*

Retire Flow B's divergent pricing. Make `/become-a-member` and `become-a-member.php`
**redirect to the matrix-driven flow** (the membership page / `apply.php`), and
update the 404 + success-retry links to match. One source of truth, permanently.

- ➕ Eliminates the wrong-price hazard for good; matches the committee's actual
  pricing model; nothing left to keep in sync (the June cross-contamination class
  of bug can't recur on this surface).
- ➖ Drops Stripe **auto-renew subscriptions** for *new* sign-ups. New members pay
  per term (one-off) and renew through the existing, matrix-driven renewal flow.
- ↪️ Existing subscriptions already created in Stripe keep billing (managed in
  Stripe) — no member is disrupted. No data migration needed.

### Option 2 — Keep subscriptions, wire Flow B to the matrix

Rebuild `become-a-member.php` to render prices from `MembershipPricingService`,
and change `create-subscription` to build **dynamic** Stripe prices (`price_data`
from matrix cents) instead of fixed Price IDs.

- ➕ Keeps recurring auto-renew.
- ➖ Significantly more work and ongoing complexity; the matrix's joining fee +
  thirds + per-term joining cells don't translate to a clean recurring price, so
  this risks re-introducing a subtle second interpretation of the matrix.

**Recommendation: Option 1.** It's the smallest, safest change and makes the
matrix genuinely the single source of truth. Auto-renew for new members can be
added later as its own feature if the committee wants it.

---

## 4. Implementation plan (pending Pat's choice above)

### If Option 1 (recommended)
1. Repoint `/become-a-member` to the canonical flow:
   - `become-a-member/index.php` + `become-a-member.php` → 302 redirect to
     `/?page=membership` (or directly `/apply.php` — Pat's preference).
   - Update `404.php:117` and `memberships/success/index.php:40` links.
2. Leave `create-subscription` / `payments.membership_prices` in place but unused
   by the public join path (still available for any admin/Stripe portal use);
   optionally hide the "Membership Stripe Price IDs" admin card to avoid
   confusion, or label it clearly as legacy.
3. Add a short note in admin help chapter `14-membership-pricing.md` that the
   matrix is the sole public pricing source.

### If Option 2
1. Rewrite `become-a-member.php` to load `getJoinOptions()` / matrix prices and
   render them, like `apply.php`.
2. Rewrite `create-subscription` to derive `price_data` (currency + unit_amount)
   from `resolveJoinPriceCents()` server-side; never trust client amounts.
3. Reconcile term model (12/24/36M vs matrix renewal periods).

### Common to both
- **Server-side authority stays the rule:** any charge amount is computed from
  the matrix server-side; the client only ever sends a period **key**, never a
  price. (Already true for Flow A; must hold for whatever Flow B becomes.)
- **No caching changes** (none needed).
- **Backwards compatibility:** no migration of existing members/prices required.
  Historical charges live in `membership_periods`; the matrix is forward-looking.

---

## 5. Verification (after sign-off + implementation)
1. Edit one cell in the admin matrix (`/admin/settings/?section=membership_pricing`).
2. Confirm it changes on `/?page=membership` immediately.
3. Confirm `/apply.php` shows the new join-option price.
4. Curl `/api/stripe/create-application-payment-intent` and confirm the returned
   amount matches the new matrix cell (proves the backend charge follows the
   matrix).
5. Confirm `/become-a-member` now lands on the matrix-driven flow (Option 1) or
   shows the dynamic price (Option 2).
6. Capture screenshot/curl proof on `draft.goldwing.org.au` (staging).

---

## 6. What was implemented (wire to matrix)

| File | Change |
|---|---|
| `public_html/become-a-member.php` | Replaced the decoupled Stripe-subscription form with `require __DIR__ . '/apply.php';` — `/become-a-member` now serves the identical matrix-driven application flow. Old form recoverable from git history. |
| `public_html/404.php:117` | Restored "Become a member" link → `/become-a-member.php`. |
| `public_html/memberships/success/index.php:40` | Restored payment-failed "Try again" link → `/become-a-member`. |

**Why an include rather than a parallel rebuild:** `apply.php` already implements
all three required guarantees (matrix-sourced prices, server-side
`resolveJoinPriceCents()` charge, no client price). Serving it from
`/become-a-member` makes the two URLs behave identically with one source of
truth — no second form to drift out of sync (the drift that caused the June
incident). `apply.php` self-posts to `window.location.pathname` and uses
`__DIR__`-relative requires, so it runs correctly from this path; its AJAX
completion POSTs back to `/become-a-member.php`, which re-includes `apply.php`'s
POST handler to create the member/application.

**Verification (local, `php -S`):**
- `GET /become-a-member.php` → HTTP 200, renders the full Membership Application
  flow (no Fatal/Parse/SQLSTATE).
- The embedded `joinOptionsMap` carries live matrix cents — e.g. PRINTED Full
  1-Year = `4000` ($40.00) in the `APR` window, which is exactly the matrix's
  `joining_prices.PRINTED.FULL.P_1Y.APR` cell for today's (final-third) join
  window. Proves the page reads the matrix *and* the date-based window.
- Page drives `/api/stripe/create-application-payment-intent`, which computes the
  amount via `resolveJoinPriceCents()` (api/index.php:760,772) and sends only the
  period **key** to the server. A live test charge on staging is still pending
  (needs the staging Stripe + DB env).

All touched files pass `php -l`. tour-impact: none. doc-impact: chapter 14 updated.

---

## 7. ⚠️ Strip blocker — the Stripe Price IDs are NOT orphaned

The brief asked to "strip the orphan Stripe Price ID list" (`payments.membership_prices`)
once the page was wired. **Do not strip it yet** — it is still load-bearing:

| Reader | What it does | Status |
|---|---|---|
| `public_html/admin/index.php:607` | On admin **approval** of a membership application paid by Stripe, builds `<TYPE>_<TERM>` → looks up the Stripe Price ID → `StripeService::createCheckoutSession()` for the member's payment link | **LIVE — would break** |
| `public_html/member/index.php:5589` | Reads `$renewPrices` in the renewal modal (the displayed amounts now come from the matrix via `membership_renewal_amount_cents()`; this read looks vestigial but needs confirming) | likely dead, unconfirmed |
| `public_html/api/index.php:1216` | `create-subscription` endpoint — was only called by the old become-a-member form; now uncalled | dead (dormant) |
| `public_html/api/index.php:97`, `:2432` | Stripe config / settings surfaces | needs review |
| `StripeSettingsService`, `SettingsService` defaults/migrate, `migrate.php:219` | read/write/seed the setting | infra |

Removing the setting/admin card now would break the **admin-approves-member →
Stripe Checkout** payment path. Correct sequence to actually retire the Price IDs:
1. Migrate `admin/index.php`'s approval flow to charge from the matrix (ad-hoc
   `price_data` from `resolveJoinPriceCents()`/`renewalAmountCents()`), like
   `apply.php` does — then it no longer needs Price IDs.
2. Confirm `member/index.php:5589` `$renewPrices` is truly unused and remove it.
3. Remove the now-dead `create-subscription` endpoint.
4. Only then remove the admin "Membership Stripe Price IDs" card and the
   `payments.membership_prices` setting.

This is a separate, test-worthy change (it touches a live payment path) and is
left for Pat's go-ahead rather than bundled into this wiring.

---

> Note: a sibling task is rebuilding the Wings flipbook reader
> (`public_html/member/read_wings.php`, `assets/js/wings-reader-core.js`). This
> plan does not touch those files — no collision.
