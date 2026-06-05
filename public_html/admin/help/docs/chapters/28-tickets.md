# Tickets

## For administrators

### What this is

A **ticket** is a special kind of store product — built for selling entry to an event rather than shipping a jacket or a patch. When someone buys a ticket product, the system generates a unique code (something like `TKT-A3F9B2C1`) and emails it to them. On the day of the event, an organiser checks the code at the door against the list of paid attendees.

It's the same store, the same checkout, the same Stripe payment — just with a code attached.

### When to use it

Use a ticket product whenever you need to confirm a member has paid before letting them in:

- **Rallies** — pre-paid attendance, especially if the venue has a capacity limit.
- **Dinners and social nights** — the caterer needs head counts; you don't want walk-ins.
- **Ride-day registrations** — anything where the cost has to be settled before the day.
- **Workshops, training, anything with a per-head fee.**

If the event is free and you just want a head-count, use Events & RSVPs (Chapter 26) instead — that's a separate, lighter system with no payment.

### Who's allowed to set up ticket products

Two roles can create and edit ticket products:

- **Admin**
- **Store Manager**

If you're a Committee Member without the store role, you can see ticket orders but you can't create the product itself. Ask an admin.

### Where to find it in admin

To create a ticket product:

**Admin → Store → Products → New Product**

In the **Product Type** select, choose **"Ticket / Event"** (instead of the default "Physical Product"). That single choice is what turns this product into a code-generating ticket. A new **Event Name** field appears once you pick that type.

To see tickets that have already been sold for a given event, open the product and click through to any paid order against it — the codes show in the order detail page.

### How to set up a ticket product (step by step)

1. **Admin → Store → Products → New Product.**
2. Set **Product Type** to **Ticket / Event**.
3. Fill in the **Name** (this is what the buyer sees in the store, e.g. "2026 Spring Rally — Saturday Dinner").
4. Fill in the **Event Name** — usually the same as the product name, or shorter (e.g. "Spring Rally Dinner"). This is what appears on the ticket code email.
5. Set the **Price**. Member-only pricing works exactly like physical products.
6. Tick **Track Inventory** and set the **Stock quantity** to the number of tickets you have available — usually the venue's capacity. When stock hits zero, the product shows a "sold out" badge automatically.
7. Add a description, an image if you have one, save.

Test it by buying one yourself in Stripe test mode (use the test product trick from Chapter 27) and confirm the email arrives with a code.

### What the buyer receives

Two emails:

1. The usual **order confirmation** (same as any store purchase — confirms what they paid for).
2. A second **ticket codes** email with subject *"Your ticket codes for order #XXXX"*. The body is a small table:

    | Event | Code |
    |---|---|
    | Spring Rally Dinner | `TKT-A3F9B2C1` |

If the member bought 4 tickets, they get 4 different codes in that table — one per seat. They can forward the email to whoever is going with them.

### How to check codes at the door

There's **no scanner app yet** — admission is checked manually. The simplest workflow:

1. Open the product in admin: **Admin → Store → Products → click the ticket product**.
2. Open each paid order against the product and copy the codes into a printed list before the event.
3. At the door, ask each attendee for the code from their email and tick it off.

The buyer can also pull their codes up again any time at `/store/order/<their order number>` if they've lost the email — they just need to be logged in.

### What if a ticket buyer can't make it

Refund the order through the normal store refund flow (Chapter 17). **Important caveat**: refunding the order **doesn't currently invalidate the code**. The buyer's email still shows a working-looking code after the refund.

Until we add an automatic invalidation step, the workaround is:

1. Process the refund as normal.
2. Write the voided code down on your printed attendee list with a line through it.
3. If they turn up at the door anyway, the code's on your "do not admit" list.

This is rare in practice — people who ask for a refund don't usually then turn up — but worth knowing.

### What can go wrong (and what to do)

- **Stock not set, so unlimited tickets sell.** If you forget to tick **Track Inventory** (or leave stock as 0 with tracking off), the product never goes "sold out" and you can oversell the venue. Always set stock to match capacity *before* you publish the product.
- **Member bought a ticket twice by mistake.** Two orders, two codes — they get two emails. Refund the second order (Chapter 17) so they only pay once. The duplicate code stays in your records but no harm is done if they only present one at the door.
- **"I can't find my code email."** Three options, fastest first: ask them to check spam; tell them to log in and visit their order at `/store/order/<order number>` — the codes are listed there too; or open their order in admin and click **Resend ticket email**.
- **Two people share the same code at the door.** Means the buyer forwarded their email and both came. Whoever shows first gets in — the second person needs their own ticket. (We can't currently auto-detect this; it's a manual eyeball.)
- **Codes aren't in the email at all.** The webhook from Stripe didn't fire (or fired and emailing failed). Open the order in admin — if the codes appear there, hit **Resend ticket email**. If the codes are missing from the order too, contact your developer.

### Good practice

- **Always set a stock cap matching the venue.** This is the only thing standing between you and overselling a 50-seat dinner to 80 people.
- **Print the attendee list the day before.** Don't rely on having WiFi at the venue. The list lives in admin under the product's orders — copy it into a doc and print.
- **Send a reminder email the day before the event.** Tell people to bring their code (a screenshot of the email is fine). Saves a lot of "I can't find it" at the door.
- **Use a `[TEST]` prefix on test products.** When you're trialling a new ticket type in Stripe test mode, name the product `[TEST] Spring Rally` so the codes generated don't pollute real attendee exports.
- **Reconcile after the event.** Once it's over, refund any genuine no-shows you've agreed to refund and archive the product so it doesn't show in the store any more.

### Who to ask if you're stuck

- **Permission issue** (can't see "Ticket / Event" in the Product Type select) — site admin can give you the Store Manager role.
- **Refund issue** — see [Chapter 17 — Refunds](view.php?slug=17-refunds) or ask the Treasurer.
- **Codes never arrived / appear corrupted / webhook trouble** — your developer. The codes live in the database; recovery is almost always possible.

---

<details>
<summary><strong>Dev notes</strong></summary>

## What this covers

A "ticket" is a flavour of store product (`store_products.type = 'ticket'`) that, on successful payment, generates one unique redeemable code per unit purchased. Chapter 27 covers the store as a whole; this chapter is the deep-dive on the divergent path ticket items take after Stripe says "paid": code generation, the `store_tickets` table, the email that delivers codes, and the (deliberately thin) redemption story.

## Why it exists

Goldwing runs paid events — rallies, dinners, regional ride-ins — and needs to take money up front while knowing who paid on the day. Bolting on a SaaS (Eventbrite, Humanitix) would mean a second login, a second refund flow, and re-implementing member pricing. Reusing the existing store means `OrderService`, Stripe fees, discount codes and member-only pricing apply for free.

The trade-off: tickets are intentionally simple. No seating map, no tiers within a single product, no QR scanning UI today. A "ticket product" is a physical product plus "generate a code per unit." Seat assignment or scanned admission would be a new schema, not a tweak to this one.

## How it works

### Code generation

Generation lives in `App\Services\PaymentWebhookService::markStoreOrderPaid()` (line ~473–492). The webhook fires on Stripe's `checkout.session.completed` / `payment_intent.succeeded` event ([Ch 16](view.php?slug=16-webhooks-idempotency)), marks the order paid, then loops the order's line items:

```php
foreach ($items as $item) {
    if (($item['type'] ?? '') !== 'ticket') continue;
    for ($i = 0; $i < (int) $item['quantity']; $i++) {
        $code = 'TKT-' . strtoupper(bin2hex(random_bytes(4)));
        // INSERT INTO store_tickets (order_item_id, ticket_code, status, event_name, created_at)
    }
}
```

Each unit gets its own row. Code format: prefix `TKT-` + 8 uppercase hex chars from `random_bytes(4)` (32 bits ≈ 4.3 billion possibilities), e.g. `TKT-A3F9B2C1`. Stored in `store_tickets.ticket_code VARCHAR(60) UNIQUE NOT NULL` — the UNIQUE index doubles as collision guard.

The `event_name` on the ticket row is copied from `store_order_items.event_name_snapshot`, itself snapshotted from `store_products.event_name` at checkout (`public_html/store/checkout.php` line ~180). Renaming the event on the product later doesn't rewrite history.

### Email delivery

Immediately after generation, the webhook dispatches the `store_ticket_codes` notification, passing a `ticket_list_html` table built by `store_ticket_list_html()` in `includes/store_helpers.php`. Default subject: `Your ticket codes for order #{{order_number}}`; body is an HTML table (event name | code). Admins edit subject/body/recipients in **Settings → Notifications**. Delivery is SMTP via `SmtpMailer` — see [Ch 22](view.php?slug=22-notifications-email).

### Redemption

This is the part most people expect to be richer than it is. **There is no admin "scan a ticket" UI today.** What exists:

- `store_tickets.status` is an `ENUM('active','redeemed')` defaulting to `'active'`.
- No code path anywhere flips a row to `'redeemed'`.
- On event day, the organiser exports / prints the attendee list from the admin order view and crosses names off manually.

If we add scanned admission later, the natural shape is: an endpoint that requires the right role and runs `UPDATE store_tickets SET status='redeemed' WHERE ticket_code=:c AND status='active'`, accepting only when affected-rows is exactly 1 (so a double-scan fails). The schema is already set up — only the UI is missing.

## Where to change it

| Change | File |
|---|---|
| Code format / generation | `app/Services/PaymentWebhookService.php` (search `'TKT-'`) |
| What gets emailed | `App\Services\NotificationService` (`store_ticket_codes` template), `includes/store_helpers.php` (`store_ticket_list_html`) |
| Mark a product as a ticket | `public_html/admin/store/product_form.php` → Product Type select + Event Name |
| Admin view of tickets on an order, **Resend ticket email** | `public_html/admin/store/order_view.php` (~237–240, ~381, ~485) |
| Buyer's view of their codes | `public_html/store/order_view.php` |
| Schema | `database/store_module.sql` — `store_products.type`, `store_products.event_name`, `store_order_items.event_name_snapshot`, `store_tickets` |

There is no dedicated "all tickets ever sold" admin index page. To get an attendee list for one event today, open the product, click into each paid order against it, and copy the codes table — or query `store_tickets` joined on `store_order_items` and `store_orders` directly.

## Settings

Tickets don't have their own settings section. They reuse:

- `store_ticket_codes` notification (Settings → Notifications) — subject, body, from-name, recipients.
- All Stripe / fee settings from [Ch 13](view.php?slug=13-stripe-overview) and Ch 27.
- The product's **Track Inventory** + **Stock quantity** fields, identical to physical products — stock decrements by quantity when the webhook marks the order paid. Set stock to venue capacity to get a "sold out" badge automatically.

## Gotchas

- **Refunding a ticket order does *not* invalidate the codes.** `RefundService` only touches `store_orders` / `store_order_items` / Stripe — it never updates `store_tickets`. After a refund, the buyer's email still contains a working code. Until the redemption UI exists, chase this manually: refund, then keep a written list of voided codes against your attendee printout. See [Ch 17 — Refunds](view.php?slug=17-refunds).
- **Tickets are not linked to the Events module.** [Ch 26 — Events & RSVPs](view.php?slug=26-events-rsvps) covers `calendar_event_tickets` and event RSVPs — a separate, unrelated table from `store_tickets`. The store-side ticket only knows its `event_name` as a free-text snapshot — no FK to `calendar_events`. Selling pre-paid admission to a calendar event today means keying the event title into the product's Event Name field and reconciling attendance off two systems.
- **SMTP failure = no code.** Codes are in the DB the moment payment captures, but email is the only push channel. If SMTP is down or the address bounces, the buyer has no code until an admin clicks **Resend ticket email**, or until the buyer opens their order receipt at `/store/order/<id>` (which also renders the codes).
- **Test Stripe generates real-looking codes.** `random_bytes(4)` doesn't care whether the payment came from `sk_test_` or `sk_live_`. A draft-mode code is structurally identical to a live one and lives in `store_tickets` forever unless you delete the order. When testing, prefer a dedicated `[TEST] …` product so attendee exports don't pull in fake rows.
- **Generation is at webhook time, not checkout time.** If Stripe captures payment but the webhook is delayed, the buyer sees the order paid before codes appear. Re-running the webhook is safe — `markStoreOrderPaid` no-ops when the order is already paid, so the `for` loop only fires once.
- **Quantity = codes, always.** Buying 4 tickets generates 4 separate rows with 4 different codes. No "group ticket admits 4" mode.

</details>

<!-- SCREENSHOT: A ticket product being configured. /admin/store/product/edit/<id>, Product Type set to "Ticket / Event", Event Name filled in. Save to public_html/admin/help/images/28-ticket-product-form.png. -->
<!-- ![Ticket product configuration](../images/28-ticket-product-form.png) -->

<!-- SCREENSHOT: The buyer's ticket email. Render the store_ticket_codes notification preview from Settings → Notifications. Save as 28-ticket-email.png. -->
<!-- ![Ticket codes email](../images/28-ticket-email.png) -->

<!-- SCREENSHOT: Admin order view for an order with tickets, showing the codes table and the "Resend ticket email" button. /admin/store/orders/<id>. Save as 28-admin-order-tickets.png. -->
<!-- ![Admin ticket list on order](../images/28-admin-order-tickets.png) -->

## Related chapters

- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — how the order that carries ticket items is created.
- [16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency) — the Stripe webhook that triggers code generation.
- [17 — Refunds](view.php?slug=17-refunds) — what a refund touches, and what it doesn't (codes).
- [22 — Notifications & email](view.php?slug=22-notifications-email) — the `store_ticket_codes` template + SMTP delivery.
- [26 — Events & RSVPs](view.php?slug=26-events-rsvps) — the separate calendar/RSVP system, currently not linked to store tickets.
- [27 — Store architecture](view.php?slug=27-store-architecture) — `store_products.type`, shared cart/checkout, and where tickets diverge from physical products.
