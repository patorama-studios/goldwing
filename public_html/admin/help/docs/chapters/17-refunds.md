# Refunds

## For administrators

### What this is

A **refund** is giving a member their money back after they've paid. The money goes from the association's Stripe account back to whatever card or bank account they used. Stripe usually delivers it in 5–10 business days.

Only admins can issue refunds. Members can't refund themselves — that's deliberate.

### What you can do

- Refund a member their **full order** (e.g. they cancelled a ride and want all their money back)
- Refund **part of an order** (e.g. they bought a $100 jacket and a $20 patch — refund just the patch)
- See every refund that's ever been issued, who issued it, and why

### Who's allowed to do this

Three roles have the "refund" permission by default:

- **Admin**
- **Committee Member**
- **Treasurer**

If you're none of these, you won't see the refund button. Ask an admin to change your role if you should have it.

### Where to find it in admin

There are two places, depending on what you're looking at:

1. **From an order** — Admin → Store → Orders → click the order number → scroll to the **Refunds** panel.

    {{link:/admin/store/orders|Take me to Store Orders}}

2. **From a member's profile** — Admin → Members → click the member → **Orders** tab → click the order → **Refund** button.

    {{link:/admin/members/|Take me to Members}}

Both routes do exactly the same thing.

### How to issue a refund (step by step)

The Refunds panel sits at the bottom of the order detail page and looks like this:

![Refunds panel on the order detail page](images/17-refund-button.png)

Then:

1. Find the order you want to refund (either route above).
2. Scroll to the **Refunds** panel.
3. Type the **amount** (in dollars). Leave it blank to refund the whole order's remaining balance.
4. Type a short **reason** (a sentence is fine — this gets emailed to the member and saved in our records).
5. Click **Process refund**.
6. You'll be asked for your **2FA code** if it's been more than a few minutes since you last entered one (this is the "step-up" check — a deliberate extra layer of safety on actions involving money).
7. Confirm the browser pop-up: "Process this refund in Stripe?"

    ![Browser confirm dialog before the Stripe call](images/17-refund-confirm.png)

8. Wait a few seconds. The page reloads and you'll see the refund listed in the panel with a status of **Processed**.

That's it. Stripe sends the money. The member gets an email immediately. We never touch any cards or bank details ourselves — Stripe does all of that.

### What the member gets

An email like this:

> Your refund for order **#10293** has been processed. Amount: **$50.00**. Reason: **Cancelled attendance**.

The money lands on whatever card they originally paid with. We don't promise a specific number of days in the email because Stripe's timing depends on the member's bank — 5–10 business days is the usual range.

### What can go wrong (and what to do)

- **"Stripe payment intent is not available for this order."** — This order was paid manually (cash, bank transfer, complimentary ticket), so there's nothing in Stripe to refund. You'll need to refund off-platform (bank transfer, cash back to the member) and add an admin note on the order.
- **"Refunds require a linked member account."** — The order was placed as a guest checkout and isn't attached to a member. Link the order to the member first (from the order's detail page) and try again.
- **"Stripe refund could not be completed."** — Something at Stripe's end blocked it. The most common reasons are: the order's already been fully refunded, the original charge is older than 180 days, or the Stripe account doesn't have enough balance to refund right now. Go to the Stripe dashboard (your Treasurer has the login), check the **Events** log, and you'll see the real reason there.
- **"Refunds table is missing…"** — A database setup step was skipped. Contact your developer; they'll need to run a one-off SQL file.
- **"There's no undo!"** — Correct, and intentional. Once Stripe accepts a refund, you can't reverse it. If a refund was a mistake, take payment again as a brand-new order.

### What gets recorded

Every refund — successful or failed — is logged. You can see:

- **In the order** — a refund row with amount, date, who issued it, and the Stripe receipt ID (looks like `re_3Q4...`).
- **In the activity log** — Admin → Security Log. You can search for `refund` to see every attempt.
- **In Stripe** — a corresponding refund record. Searchable by the receipt ID.

This means a future audit, member dispute, or treasurer's report can always be reconstructed.

### Good practice

- **Always type a reason.** Future-you (or the next treasurer) will thank you.
- **Refund the smallest sensible amount.** If a member cancelled half their order, only refund that half. You can always refund more later; you can't undo too much.
- **Tell the member first.** A refund email landing without warning can be confusing. A quick "we're refunding you for X" message gives context.
- **Check the Stripe dashboard once a month.** Make sure the refunds you've issued in the admin match what Stripe records. Discrepancies are very rare but worth catching.

### Who to ask if you're stuck

- **Permission issue** — site admin can change roles in Admin → Settings → Accounts & Roles.
- **Stripe rejected a refund** — Treasurer or whoever has the Stripe login.
- **The error message is jargon, not English** — flag it to your developer and copy-paste the message. They can look up the exact cause.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

How an admin gives a customer their money back. The button lives on the order detail page; the work happens in `App\Services\RefundService::processRefund`, which calls Stripe, writes a row to `store_refunds`, updates the order, logs the action, and emails the customer. Full and partial refunds use the same path — a blank amount means "refund whatever's left".

### Why it exists

Refunds move real money out of the Goldwing Stripe account and into a member's bank, so we want one code path doing it. Wrapping it in a service lets us enforce step-up auth, check the refundable balance against past refunds (no double-refunding $50 on a $50 order), keep a local mirror in `store_refunds`, log every attempt to `activity_log`, and fire the customer email and security alert from the same place.

Members can **not** refund themselves. There is no member-portal refund button by design — every refund is admin-initiated.

### How it works

#### Who can issue refunds

The permission key is `admin.payments.refund`. Per `ADMIN_GUIDE.md` and `includes/admin_permissions.php`, three roles have it by default: Admin, Committee Member, Treasurer. The store-side check uses a parallel key `store_refunds_manage` (`includes/store_helpers.php`) which is admin-only.

Two entry points, same service call:

| Entry point | Check | File |
|---|---|---|
| Order detail → Refunds panel | `store_user_can($user, 'store_refunds_manage')` | `public_html/admin/store/order_view.php` (action `refund_order`) |
| Member profile → Orders → Refund | `AdminMemberAccess::canRefund($user)` | `public_html/admin/members/actions.php` (action `refund_submit`) |

#### Step-up auth

Before the service is called, the page invokes `require_stepup()`:

```php
require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/store/orders');
```

If the admin hasn't completed a 2FA challenge in the last few minutes, they're bounced to `/stepup.php` and returned to the refund form after re-verifying. See [Chapter 06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup).

#### What `processRefund` actually does

`RefundService::processRefund($orderId, $memberId, $amountCents, $reason, $adminUserId)` does:

1. `OrderRepository::getById()` — bails if missing.
2. `OrderRepository::calculateRefundableCents()` — order total minus every `store_refunds` row already `processed`. Rejects amounts ≤ 0 or > remaining.
3. Reads `stripe_payment_intent_id`. No intent = no refund (manual/free orders need a bank transfer).
4. Logs `refund.requested` to `activity_log` *before* hitting Stripe.
5. `StripeService::createRefund($intent, $amountCents)` calls `refunds->create` with the active secret key. Omitting `amount` = full refund.
6. If Stripe returns nothing, logs `refund.failed` and throws — the page surfaces the message.
7. Inserts a `store_refunds` row with `status = 'processed'` and the returned `stripe_refund_id`.
8. Updates `store_orders` — `payment_status` becomes `refunded` (full) or `partial_refund`; `order_status` flips to `cancelled` on full refunds.
9. Adds a `refund.processed` event to the order timeline.
10. Logs `refund.processed` to `activity_log`.
11. Fires `SecurityAlertService::send('refund_created', …)` to security recipients.
12. Dispatches the `store_refund_processed` notification to the customer.
13. Returns `['refund_id', 'stripe_refund_id', 'remaining_refundable_cents']`.

#### Local ↔ Stripe link

Every `store_orders` row from checkout has a `stripe_payment_intent_id` — that's the only handle we need; Stripe figures out which charge to refund. The returned `stripe_refund_id` (e.g. `re_3Q4xxxxxxxx`) is the canonical receipt — searchable in the Stripe dashboard.

#### What the customer sees

The `store_refund_processed` template (in `App\Services\NotificationService`) emails: "Your refund for order **#{{order_number}}** has been processed. Amount: {{refund_amount}}. Reason: {{refund_reason}}." The money lands back on the original card; Stripe quotes 5–10 business days depending on the issuing bank — we don't promise a number in the email because we can't enforce it. Email only, no SMS.

### Where to change it (in code)

- **Button / form** — `public_html/admin/store/order_view.php` (Refunds panel ~line 409) and the orders tab in `public_html/admin/members/view.php`.
- **Refund logic** — `app/Services/RefundService.php`. One file, one method.
- **Stripe call** — `app/Services/StripeService::createRefund()` (line 156). Add refund-with-reason or refund-as-fraud params here.
- **Customer email copy** — Settings → Notifications → "Store refund processed".
- **Permission keys** — `includes/admin_permissions.php` and `includes/store_helpers.php`.

### Settings

- **Notification template** — `Settings → Notifications → Store refund processed` (subject and body, with `{{order_number}}`, `{{refund_amount}}`, `{{refund_reason}}` placeholders).
- **Security alert recipients** — who gets the `refund_created` alert. Configured in the Security alerts settings; consumed by `SecurityAlertService`.
- **Stripe secret key** — `Settings → Payments → Stripe`. Refunds use whatever key `StripeSettingsService` reports as active. See [Chapter 13 — Stripe integration overview](view.php?slug=13-stripe-overview).

### Gotchas (technical)

- **No payment intent, no refund.** Orders created manually (cash, bank transfer, comp tickets) have no `stripe_payment_intent_id` and throw `"Stripe payment intent is not available for this order."` Refund off-platform and add an admin note.
- **No member, no refund.** The order-view path bails with "Refunds require a linked member account" if `member_id` is null. Guest-checkout orders need the member link patched first.
- **Stripe rejections look like generic failures.** `StripeService::createRefund()` swallows the `ApiErrorException` and returns `null`, so `RefundService` throws the generic `"Stripe refund could not be completed."` That covers already-refunded, insufficient balance, expired charge (>180 days), and rate-limit — check the Stripe dashboard's Events log for the real reason. The `refund.failed` row in `activity_log` confirms the attempt happened.
- **Rerunning a failed refund after key rotation.** Failed refunds leave the order unchanged and write no `store_refunds` row — just hit "Process refund" again with the new key in place. No cleanup needed.
- **The refundable total is computed live.** `calculateRefundableCents()` sums only `status = 'processed'` rows, so a future `requested`/`failed` status wouldn't lock funds. If you ever introduce a "pending refund" workflow, update that query.
- **`store_refunds` is created by the members module.** If a fresh DB is missing the table, the service throws `"Refunds table is missing…"` — apply `database/members_module.sql`. See [Chapter 03 — Database & migrations](view.php?slug=03-database-migrations).
- **Security alerts fire even on partial refunds.** Intentional — every refund is a money-movement event worth flagging.

</details>

<!--
  Screenshots to capture once the pipeline is ready:
  • Refunds panel on an order detail page at /admin/store/orders/<order_number>,
    showing amount + reason fields and the red "Process refund" button.
    Save as public_html/admin/help/images/17-refund-button.png and add:
    ![Refund button on order detail page](images/17-refund-button.png)
  • The confirm() dialog that fires on click. Save as 17-refund-confirm.png.
-->

## Related chapters

- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — what the "step-up" check actually does.
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where to see every refund attempt.
- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — how Stripe connects to the site.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — how orders get paid in the first place.
- [16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency) — Stripe also tells us about refunds it processes itself; this is how we don't double-handle them.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — editing the refund email the member sees.
