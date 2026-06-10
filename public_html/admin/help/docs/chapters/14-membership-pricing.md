# Membership pricing

## For administrators

### What this is

The Membership pricing settings page is where the association decides **what every membership costs and how long it lasts**. It has four parts, and you don't need to be technical to use any of them — each one is built around plain English questions, icons, and a live preview.

The four parts are:

1. **Membership year** — when a year of membership starts and when it ends. Every membership the website sells lines up with this date.
2. **Renewal pricing** — the choices an *existing* member sees when they renew. You can name them anything (1 Year, 3 Years, 5 Years), set how long each one runs, and price them per magazine format and member type.
3. **Pro-rata for new joiners** — what a *brand-new* member pays when they join part-way through the year. You set one full-year base price; the system charges a fair fraction based on how many months are left until the next expiry.
4. **Live preview** — a dark card at the bottom that shows the exact prices the public website would quote *right now*, based on everything you've typed above. Save the page to make those prices live.

Change a number in any of these sections and click Save — the new price is live on the next checkout, no developer or deploy required.

### What it lets you do

- **Add, rename, reorder, or remove renewal periods** any time. The default ships with 1 Year and 3 Years, but you can add 2 Years, 5 Years, Lifetime, whatever the committee decides.
- **Edit the price of every renewal period** for each combination of magazine format and member type, all in one grid with icons.
- **Switch pro-rata on or off** for new joiners, and set the full-year base price the calculator uses.
- **Move the membership year** — change the anchor month (when memberships start) and the expiry date if the committee ever votes to change them.
- **See live pricing for today** in the preview card without having to open the public site.

### Who's allowed to do this

Two roles can edit Membership pricing:

- **Treasurer**
- **Admin**

Other roles can view the Settings hub but won't see the Save button on this page. Ask an admin to update your role if you need access.

### Where to find it in admin

{{link:/admin/settings/?section=membership_pricing|Take me to Membership & Pricing}}

Admin → **Settings** → **Membership Settings** (in the Settings hub sidebar).

The same page also has member-ID sequencing, manual migration controls, the Associate → Full upgrade fee, and chapter records. Pricing is one of several sections on it — scroll down to the cards with the calendar icon, replay icon, and "trending down" icon.

### Walk-through: changing a renewal price

{{link:/admin/settings/?section=membership_pricing|Take me to Membership & Pricing}}

1. Go to **Admin → Settings → Membership Settings**.
2. Scroll to the **Renewal pricing** card.
3. In the grid below the period list, find the cell you want to change. Each row is labelled with a coloured chip — `📖 Printed Wings`, `📱 PDF Wings`, `👤 Full member`, `👥 Associate member`.
4. Type the new dollar amount (e.g. `90.00`). Use dollars and cents, not cents.
5. Scroll down to the **If a member checked out today…** preview card — your new price will already be reflected there.
6. Click **Save Settings** at the bottom of the page. The page reloads and the new price is live immediately.

### Walk-through: adding a new renewal period

1. In the **Renewal pricing** card, click **Add another renewal period**.
2. A new row appears with placeholder text. Type the name (e.g. `2 Years`) and the length in months (e.g. `24`).
3. The price grid below grows a new column for that period. Type the prices for each (magazine × member type) cell.
4. Click **Save Settings**. The new period is now an option in the renewal modal members see in their profile.

### Walk-through: reordering periods

1. In the **Renewal pricing** card, hover over the drag handle (the six-dot icon on the left of each row).
2. Click and drag the row up or down to its new position. A coloured line shows where it will land when you drop it.
3. Click **Save Settings**. The new order is what members see at renewal time.

### Walk-through: turning pro-rata off (charge full price all year)

1. Scroll to the **Pro-rata for new joiners** card.
2. Uncheck **Enable pro-rata pricing for new joiners**.
3. Click **Save Settings**.

With pro-rata off, every new joiner pays the full annual base price regardless of when they join. With it on, a member joining in (e.g.) April pays only 4/12 of the annual price because they only get 4 months of membership before the year resets.

### How the matrix maps to what members see at checkout

There are three distinct flows on the public site, and they each use a different part of the pricing page:

- **A brand-new member joining on `/apply.php`** — sees the pro-rata price for "this year only", plus the option to extend with any active renewal period on top. Both are calculated server-side from the **Pro-rata** card and the **Renewal pricing** card.
- **An existing member renewing from their profile** — sees one button per active renewal period from the **Renewal pricing** card. No pro-rata applies; renewals always run whole years from the anchor date.
- **An Associate upgrading to Full** — pays the 12-month Full member renewal price by default (set in the **Renewal pricing** card), or a custom flat fee if you've set one in the **Associate → Full Upgrade** section higher up the page.

The dark **If a member checked out today…** card at the bottom shows exactly what each of these three flows would quote right now, so you can sanity-check before saving.

### What can go wrong (and what to do)

- **You typed cents instead of dollars** — putting `9000` in a cell will charge $9,000.00, not $90.00. Always use the format `90.00`. If you spot this after saving, fix it and save again — the next checkout will use the corrected price.
- **You set a price to zero by accident** — anyone joining or renewing on that combination will get a free membership. Fix it as soon as you notice; any free memberships that already went through can be reviewed in the member list and corrected manually.
- **You removed a renewal period that members had previously chosen** — the form refuses to let you remove the last remaining period. Older orders that referenced a now-removed period stay valid; just be aware that members renewing now will see only the periods that remain.
- **The Save button refuses to save** — the pricing page shares a form with member-ID and chapter settings. If someone has broken one of those (e.g. removed a required token from the ID format), the whole form won't save. Check for red error messages near the top of the page and fix those first.
- **Renewal-reminder emails quote a different price** — renewal reminders read a separate legacy table that doesn't auto-sync with these prices. If you've changed prices here, ask your developer to refresh the Stripe Price IDs at **Settings → Payments** so the emails agree with what's on this page.
- **You want to give a discount or coupon** — the pricing page doesn't support coupons. One-off discounts have to be handled outside the public checkout (e.g. taking payment manually).

### What gets recorded

Every save is logged. You can see:

- **In the activity log** — Admin → Security Log. Search for `settings` to find the save event.
- **Who changed it and when** — the log captures the admin user and the timestamp of every save.

If a member ever disputes a price they were charged, you can look back at the activity log to see what the configuration held on the day they paid.

### Good practice

- **Set prices once a year.** The cleanest cadence is to review pricing with the committee, agree the figures, then update the page once. Avoid mid-year tweaks unless you really have to.
- **Run it past the committee first.** Pricing is a committee decision, not an individual one. Update the page only after the new figures are minuted.
- **Document changes in committee minutes.** Note the date, the new figures, and the reason. The activity log captures the "who and when" — your minutes capture the "why".
- **Double-check the cents.** Type `90.00`, not `90`. Type `0.00` only if you really mean a free membership.
- **Use the preview card.** Before clicking Save, scroll down and check the "If a member checked out today…" card shows what you expect. It's the cheapest mistake-catcher you have.
- **Keep at least one renewal period active.** Members can't renew without one.
- **If you change the membership year**, also update committee minutes and any printed forms or constitution clauses that quote the old dates.

### Who to ask if you're stuck

- **Permission issue (no Save button)** — site admin can change roles in Admin → Settings → Accounts & Roles.
- **The form won't save and the errors aren't about pricing** — the page also holds member-ID and chapter settings; flag it to your developer.
- **Renewal reminders quoting an old price** — your developer can sync the legacy Stripe Price IDs to match this page.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

How the site decides what a new or renewing membership costs. Replaces the old "pricing matrix" (a flat 2×2×6 grid with hard-coded period keys) with a richer config: admin-defined renewal periods + a continuous pro-rata engine for new joiners. Stored as JSON in `settings_global`, edited through the Settings Hub, and read by every page that quotes or charges a membership fee. The old `getPriceCents()` and `getMembershipPricing()` API surface still works — legacy callers keep going through a backwards-compat shim that derives the old shape from the new config.

### Why it exists

The old pricing matrix had six hard-coded period keys (`ONE_THIRD`, `TWO_THIRDS`, `ONE_YEAR`, `TWO_ONE_THIRDS`, `TWO_TWO_THIRDS`, `THREE_YEARS`) baked into the service. Two problems:
- The pro-rata buckets were fixed (Apr/Dec breakpoints) and couldn't represent a continuous mid-year join price.
- Renewal vs. new-join were tangled in one matrix — the PDF renewal form only has 1Y and 3Y, so the buckets were dead weight for renewers.

The new model splits the two concerns cleanly:
- **Renewal periods** are admin-defined `{id, label, duration_months, sort_order, active}` records with a price per (magazine × type × period) cell.
- **Pro-rata** is a continuous engine: one annual base price per (magazine × type), and the service calculates `months_remaining ÷ 12 × annual_price` from the configured anchor/expiry dates.

### How it works

#### The configuration

Defined in `app/Services/MembershipPricingService.php`:

```php
public const MAGAZINE_TYPES   = ['PRINTED', 'PDF'];
public const MEMBERSHIP_TYPES = ['FULL', 'ASSOCIATE'];
public const CONFIG_KEY       = 'membership.pricing.config';
public const LEGACY_MATRIX_KEY = 'membership.pricing_matrix';
```

The config blob (one JSON value at `settings_global['membership.pricing.config']`):

```json
{
  "anchor_month": 8, "anchor_day": 1,
  "expiry_month": 7, "expiry_day": 31,
  "currency": "AUD",
  "prorata_enabled": true,
  "prorata_minimum_months": 1,
  "prorata_rounding": "nearest_dollar",
  "renewal_periods": [
    {"id": "P_1Y", "label": "1 Year", "duration_months": 12, "sort_order": 10, "active": true},
    {"id": "P_3Y", "label": "3 Years", "duration_months": 36, "sort_order": 30, "active": true}
  ],
  "renewal_prices": {
    "PRINTED": {"FULL": {"P_1Y": 7500, "P_3Y": 21000}, "ASSOCIATE": {"P_1Y": 1500, "P_3Y": 3000}},
    "PDF":     {"FULL": {"P_1Y": 5500, "P_3Y": 15000}, "ASSOCIATE": {"P_1Y": 1500, "P_3Y": 3000}}
  },
  "prorata_annual_prices": {
    "PRINTED": {"FULL": 7500, "ASSOCIATE": 1500},
    "PDF":     {"FULL": 5500, "ASSOCIATE": 1500}
  }
}
```

#### Backwards compatibility

The first call to `getConfig()` after the new code deploys reads the old `membership.pricing_matrix` (if it still exists), extracts `ONE_YEAR` → `prorata_annual_prices` + `renewal_prices['P_1Y']` and `THREE_YEARS` → `renewal_prices['P_3Y']`, and returns the migrated config. The old key is left in place until the admin saves; the first save writes the new key and the old key effectively becomes dead.

Legacy callers (`getPriceCents`, `getMembershipPricing`, `periodDefinitions`, `defaultPricingRows`, `updateMembershipPricing`) all still work — they delegate to a `buildLegacyMatrix()` view that derives the old 6-period shape from the new config:
- `ONE_YEAR` → renewal price for the 12-month period, falling through to pro-rata annual.
- `THREE_YEARS` → renewal price for the 36-month period.
- `ONE_THIRD` / `TWO_THIRDS` / `TWO_ONE_THIRDS` / `TWO_TWO_THIRDS` → `annual × months/12` rounded per config.

#### Reading a price

Three accessor families, picked by caller type:

```php
// Renewals (member profile, renewal modal, cron):
MembershipPricingService::getRenewalPeriods();
MembershipPricingService::getRenewalPriceCents($mag, $type, $periodId);
MembershipPricingService::findRenewalPeriodByMonths(12);

// New joins (apply.php, /api/membership/create-application-intent):
MembershipPricingService::getJoinOptions($mag, $type, ?$joinDate);
MembershipPricingService::resolveJoinPriceCents($mag, $type, $key, ?$joinDate);
MembershipPricingService::calculateProRataCents($mag, $type, ?$joinDate);
MembershipPricingService::monthsRemainingUntilExpiry(?$from);

// Legacy callers (membership_content.php, migrate.php):
MembershipPricingService::getPriceCents($mag, $type, $legacyKey);
MembershipPricingService::getMembershipPricing();
```

`getJoinOptions()` produces the dropdown apply.php and the API both consume. Each option has a stable `key` (`JOIN_ONLY` or `JOIN_PLUS_<period_id>`), a human label, and a server-side-resolved `cents` value. `resolveJoinPriceCents()` is the canonical accessor when an option key comes back over the wire — it understands `JOIN_ONLY`, `JOIN_PLUS_*`, and (for safety) the legacy keys.

#### How prices flow into Stripe

Membership applications still use a **PaymentIntent** flow (not a Stripe Checkout Session). The API route `/api/membership/create-application-intent` in `public_html/api/index.php` (~line 343) reads `full_magazine_type`, `full_period_key`, `associate_period_key` from the request body and calls `MembershipPricingService::resolveJoinPriceCents()` for each. Renewals from the member profile go through `public_html/member/index.php` `membership_renewal_amount_cents()` → `findRenewalPeriodByMonths()` → `getRenewalPriceCents()`. Both compute amounts server-side milliseconds before creating the PaymentIntent — Stripe never sees a fixed Price object for memberships.

#### The legacy `stripe.membership_prices` block

`config/app.php` still declares `stripe.membership_prices` (`FULL_1Y`, `FULL_3Y`, `ASSOCIATE_1Y`, `ASSOCIATE_3Y`, `LIFE`), read by `cron/send_renewal_reminders.php` to build "renew now" email links. A separate Checkout Session route at `public_html/api/index.php` (~line 753) reads `payments.membership_prices` from `StripeSettingsService`. **Both are legacy** — for everything member-facing (the apply PaymentIntent flow and the member-initiated renewal modal), the new pricing config is the source of truth and Stripe is charged ad-hoc `price_data` amounts. The Stripe Price IDs only matter for the email-reminder cron and the legacy `/api/index.php` Checkout route.

### Where to change it (in code)

- **Admin UI**: `public_html/admin/settings/index.php` — search for `elseif ($section === 'membership_pricing')` (handler around line ~410, view around line ~2862). Four cards: Membership year, Renewal pricing, Pro-rata, Live preview. Inline JS handles add/remove/drag of renewal-period rows and keeps the price-matrix columns in sync.
- **Service**: `app/Services/MembershipPricingService.php` — `getConfig`, `updateConfig`, plus the three accessor families above.
- **Read sites**:
  - `public_html/apply.php` — uses `getJoinOptions()` + `resolveJoinPriceCents()`.
  - `public_html/api/index.php` — same.
  - `public_html/member/index.php` — `membership_renewal_amount_cents()` uses `findRenewalPeriodByMonths()`.
  - `app/Services/MembershipUpgradeService.php` — `getUpgradePriceCents()` uses the 12-month period.
  - `public_html/migrate.php` — legacy callers (`ONE_YEAR`/`THREE_YEARS`), kept on `getPriceCents()` for compatibility.
  - `app/Views/partials/membership_content.php` — public showcase, uses `getPriceCents()` with legacy keys.

### Settings

- `membership.pricing.config` — JSON, the new full config. Source of truth.
- `membership.pricing_matrix` — JSON, legacy 24-row matrix. Read once on first migration, otherwise inert.
- `membership.member_number_start` — int, first base number for new Full members (default `1000`).
- `membership.associate_suffix_start` — int, first suffix for Associate IDs (default `1`).
- `membership.member_number_format_full` / `_associate` — string, ID token formats.
- `membership.member_number_base_padding` / `_suffix_padding` — int 0–12.
- `membership.manual_migration_enabled` / `_expiry_days` — manual migration controls.
- `membership.upgrade_mode` / `upgrade_custom_fee_cents` — Associate → Full upgrade controls.
- `payments.membership_prices` — Stripe Price IDs, legacy Checkout Session path.
- `stripe.membership_prices.*` (`config/app.php`) — fallback Price IDs read by `cron/send_renewal_reminders.php`.

### Gotchas (technical)

- **Amounts are integer cents, not dollars.** `parse_money_to_cents()` parses dollar strings into cents on save; everything in the config blob is cents.
- **Missing prices clamp to zero, not null.** `normalizeConfig()` walks every (magazine × type × period) cell and inserts `0` where the form didn't post a value. This means deleting a period and re-adding it with the same ID does not resurrect old prices.
- **Pro-rata uses `DateTimeImmutable` in `Australia/Sydney` timezone.** `monthsRemainingUntilExpiry()` is anchor-aware: if today is at or before the expiry date this year, it counts to *this* year's expiry; otherwise to next year's. Day-precision: an extra day past a month boundary rounds *up*, so a join on the 30th of a month still gets credit for that whole month.
- **No discounts or coupons in the config.** It's a flat lookup — no member-of-member discount, no chapter override. The store discount-codes system does **not** apply to memberships.
- **Two pricing systems coexist.** The new config (PaymentIntent path) is current; the Stripe Price IDs (Checkout Session + renewal cron) are legacy. If you change prices here, also update the Stripe Price IDs at `?section=payments` so renewal-reminder emails quote the same number. They don't auto-sync.
- **Type constants are case-sensitive and currency is AUD-only.** Always `'PRINTED'` / `'PDF'` / `'FULL'` / `'ASSOCIATE'` uppercase. The JSON has a `currency` field but every write-path hardcodes `'AUD'`.
- **The page is shared with ID sequencing and chapters.** Saving pricing re-saves the ID format strings. If those fail validation (e.g. someone removed the `{base}` token), the whole form refuses to save — pricing included.
- **The legacy `defaultPricingRows()` is still wired** into `SettingsService` seed defaults (`config/app.php` doesn't reference it but `SettingsService::DEFAULTS` does). It's now regenerated from `defaultConfig()` via `buildLegacyMatrix()` so it stays in sync.

</details>

<!-- SCREENSHOT: The Membership pricing page at /admin/settings/index.php?section=membership_pricing showing all four cards (Membership year, Renewal pricing, Pro-rata, Live preview). Save as public_html/admin/help/images/14-pricing-matrix.png. -->
<!-- ![Pricing page](../images/14-pricing-matrix.png) -->

<!-- SCREENSHOT: The "If a member checked out today…" dark preview card on the same page. Save as 14-pricing-preview.png. -->
<!-- ![Live pricing preview](../images/14-pricing-preview.png) -->

## Related chapters

- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — `StripeService`, `StripeSettingsService` and how the Price IDs fit in.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — the PaymentIntent → webhook → order-row lifecycle that consumes a price.
- [19 — Membership lifecycle](view.php?slug=19-membership-lifecycle) — provisioning, expiry, renewal reminders.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `SettingsService` works and why pricing is one JSON blob instead of many keys.
