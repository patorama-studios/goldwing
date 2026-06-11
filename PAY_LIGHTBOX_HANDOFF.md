# Pay-membership lightbox — handoff for the next chat

Pat wants the membership-pay flow to be a **single centred lightbox** that
slides between two views, visually consistent with the rest of the site,
with **no full-page Stripe redirect anywhere**. We're partway there. This
doc captures the goal, the gap, and everything the next session needs to
land it.

---

## 1. What Pat sees right now (screenshot from current chat)

After clicking **Pay membership** on `/member/?page=billing`:

- ✅ Centred lightbox opens with the red top border (matches `#renew-modal`)
- ✅ Heading reads "Renew your membership / Pick a plan — payment comes next"
- ✅ Layout has MEMBERSHIP TYPE → "Full Member" radio card + TERM → "1 year" card
- ❌ A red error reads **"Could not start the payment."** — see §4
- ❌ The Pay button has rendered TWICE: once on the right edge bleeding outside the card (the footer button), once inside the slider (the Continue button). Looks broken.
- ❌ Term price label is `—` because the API never returned a pricing matrix
- ❌ The Stripe Payment Element area is just empty lines

Layout proof from Pat:
> "Can we go back to the layout we had before which was choose your
> membership year first and then also pay for associate checkbox at the
> same time. Can we then hit the continue to payment which takes you to
> a second screen which just has summary of choice and credit card
> payment info. Have a look at the checkout page we have and the choose
> payment method that is the card — I want on the second screen. Then
> you hit the pay now button to confirm and register transaction."

---

## 2. The intended UX (THIS is what the rebuild should produce)

### Trigger

Any of these opens the lightbox:
- "Pay membership" button on `/member/?page=billing` (pending-order card)
- "Complete payment" button on `/member/` (dashboard hero, when period status = PENDING_PAYMENT)
- In-row "Pay now" on the Order History grid for any pending membership order
- URL `?pay=1` on `/member/?page=billing` (auto-open — used by post-renew redirect)
- Any element with `data-pay-drawer-open`

### View 1 — "Renew your membership" (the existing renew-modal IS this shape)

Reference: lines 5822-5740 (approx) of `public_html/member/index.php` — the
`#renew-modal` element. **Match its layout AND interactions**:

```
┌─ [⛑] Renew membership                                  × ┐
│      Choose your renewal term and confirm your details.   │
├───────────────────────────────────────────────────────────┤
│  Pat Lindley - MASTER ADMIN · Full Member                 │
│  Current period ends 31/07/2026.                          │
│                                                           │
│  Renewal term                                             │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐                │
│  │ 1 Year   │  │ 2 Years  │  │ 3 Years  │                │
│  │ $70.00   │  │ $135.00  │  │ $165.00  │                │
│  │ AUD      │  │ AUD      │  │ AUD      │                │
│  └──────────┘  └──────────┘  └──────────┘                │
│                                                           │
│  ☐ Also pay for my Associate (Carol Boswarva)             │
│      +$35.00 AUD                                          │
│                                                           │
│  ┌─ You pay today                            $70.00 AUD ─┐│
│  │   Secure payment by card via Stripe                  ││
│  └──────────────────────────────────────────────────────┘│
│                                                           │
│  ☐ I confirm my membership details (name, address, etc.) │
│      Review my details                                    │
│                                                           │
│  Cancel my membership instead       [ Close ]  [ Continue│
│                                                  to pay → │
└───────────────────────────────────────────────────────────┘
```

**Key requirements:**
- Term cards in 1×3 grid (or 1×2 if only 1y + 3y configured), red-border style
- "Also pay for my Associate" checkbox — only shown if the member HAS an associate AND associate price exists. Toggling it updates the "You pay today" total in real time.
- Live total breakdown card (yellow background like the existing renew-modal `bg-amber-50`)
- "Review my details" link (just a `<a>` to `/member/?page=profile`)
- "I confirm details" mandatory checkbox before Continue is enabled
- Continue to payment button SLIDES the modal to View 2 (DOESN'T POST)

### View 2 — Card payment

Reference for visual: `public_html/checkout.php` lines ~440-460 (`/checkout/`
store checkout). The "Credit or debit card / Bank transfer" radio cards +
Stripe Payment Element below. **Use the same pattern**:

```
┌─ ← [⛑] Renew membership                                × ┐
│        Enter your payment details                         │
├───────────────────────────────────────────────────────────┤
│  Order summary                                            │
│  ┌──────────────────────────────────────────────────────┐│
│  │ Full membership renewal (1 year)         $70.00 AUD ││
│  │ Associate add-on (Carol Boswarva)        $35.00 AUD ││ if associate checked
│  │ ────────────────────────────────────────────────────││
│  │ Total                                   $105.00 AUD ││
│  └──────────────────────────────────────────────────────┘│
│                                                           │
│  🛡  Your card details never touch our servers.           │
│      Powered by Stripe (Level 1 PCI · TLS 1.2+)           │
│                                                           │
│  ◉ Credit or debit card                                   │
│     Visa, Mastercard, Amex · Apple Pay · Google Pay       │
│                                                           │
│  [ Stripe Payment Element mounted here, just like        ]│
│  [ /checkout/ does it — same `<div id="…element">` shell ]│
│                                                           │
│            [ 🔒  Pay $105.00 AUD ]                        │
│       Secure · 256-bit SSL · Powered by Stripe            │
└───────────────────────────────────────────────────────────┘
```

**Key requirements:**
- Back arrow in the header takes the user back to View 1, **keeping their selection**
- Order summary breaks down full-price line + associate line (if checked) + total
- Stripe Payment Element mounts when View 2 opens (NOT lazy on Pay click — see §4)
- Pay button calls `stripe.confirmPayment({elements, confirmParams: {return_url: '/member/?renewed=1'}})`
- Bank-transfer is NOT in this lightbox (out of scope)

### View 3 — Thank-you (already built, don't touch)

On success Stripe redirects to `/member/?renewed=1` which fires the existing
`#renewed-lightbox` (confetti via `canvas-confetti` CDN + "Thank you, Pat!
🎉" message). Already works. See `public_html/member/index.php` lines
~5478-5588.

---

## 3. What's already in place (DO NOT REBUILD)

### Backend (works, leave alone)

- **`/api/payments/membership-intent`** in `public_html/api/index.php`
  starting around line 297 — POST `{tier, term}` → returns `{client_secret,
  publishable_key, amount_label, amount_cents, order_id, order_number,
  pricing}`. Voids non-matching pending orders. Reuses still-confirmable PI.
- **PaymentWebhookService::handlePaymentIntentSucceeded** already marks
  orders paid via `metadata.order_id` and triggers treasurer notification +
  membership-period activation.
- The legacy `POST membership_order_pay` handler in `public_html/member/index.php`
  has already been gutted (round `f929aa6`) to just redirect to
  `/member/?page=billing&pay=1`. The `POST membership_renew` handler
  (renew-modal submission) ALSO redirects to the same URL. Neither creates
  a hosted Checkout session anymore.

### Frontend (delete & rebuild)

- Current pay-membership lightbox markup lives in `public_html/member/index.php`
  starting around the comment "Pay-membership lightbox" near line 5422,
  through the closing `</div><?php endif; ?>` near line 5640. This is the
  WRONG shape — replace it.
- Current controller JS: `public_html/assets/js/pay-membership-drawer.js`.
  Rewrite for the new shape (or keep and rewire).
- Existing renew-modal (`#renew-modal` near line 5822) IS the right shape
  for View 1. It POSTs to `membership_renew`. The next session can either:
  - (a) Merge the renew-modal AND the pay-lightbox into one element with two
    views (cleaner — pat's stated preference), OR
  - (b) Keep the renew-modal as-is and make the pay-lightbox's View 1 mirror
    its layout, with the renew-modal continuing to handle the partner /
    "I confirm details" flow.
  Recommendation: **(a)** — replace `#renew-modal`'s submit-and-redirect with
  the slide-to-View-2 behaviour from the pay-lightbox. Single source of truth.

### Visual reference

- Look at `/checkout/` (`public_html/checkout.php`) for the Stripe Element
  mount pattern that's already proven to work in production. The selectors
  used are `#stripe-payment-element` and `#stripe-payment-error`.
- Look at `#renew-modal` (lines 5822+ of `public_html/member/index.php`) for
  the centred-lightbox shell that Pat wants matched.

---

## 4. The bug to fix first — "Could not start the payment"

The error in Pat's screenshot comes from `pay-membership-drawer.js` →
`fetchIntent()` → POST `/api/payments/membership-intent` returning either
non-200 or no `client_secret`.

Most likely causes (check in order):

1. **No pricing configured** for Pat's tier/term combo in
   `payments.membership_prices` setting. The endpoint computes amount via
   `membership_renewal_amount_cents()` which falls through to legacy
   lookups. If both fail it returns:
   `{"error":"No price configured for FULL / 12M. Ask an admin to set membership prices."}`
   Pat IS an admin so he can fix in `/admin/settings/?section=stripe` →
   Membership prices.

2. **Stripe checkout disabled** — the endpoint calls
   `require_stripe_checkout_enabled()` which returns 422 if
   `stripe.checkout_enabled` is false in settings.

3. **Step-up required** — `require_csrf_json` should pass since the
   CSRF token is rendered in the modal. But check for `step-up` errors.

4. **Member missing** — the endpoint checks `!empty($user['member_id'])`.
   Pat's admin user IS member_id 1, so this should pass.

**To diagnose in the next session, hit the endpoint directly with curl + the
CSRF token from `/member/?page=billing` — the response body will tell you
exactly which guard failed.**

---

## 5. Login + working credentials

- Admin: `hi@patorama.com.au` / `Archie1805!.` (mind the trailing period!)
- Stripe is in **TEST mode** on live (publishable key prefix `pk_test_`).
  Safe to use `4242 4242 4242 4242` and Stripe's other test cards.
- Live site is `goldwing.org.au`. Staging (`draft.goldwing.org.au`)
  301-redirects to live now — testing happens on live.

---

## 6. Deploy workflow (CRITICAL — don't skip)

This bit the previous session multiple times.

1. Make code changes.
2. Commit + push to `origin/main`.
3. Pat goes to **cPanel → Git Version Control → Manage → Pull or Deploy**.
4. Pat clicks **Update from Remote** (pulls into bare repo).
5. **Pat clicks Deploy HEAD Commit** — this triggers `.cpanel.yml` which
   rsyncs `/home2/goldwing/repositories/goldwing/` → `/home2/goldwing/`.

The `.cpanel.yml` is at the repo root and IS working. Verification:

- Every recent commit bumps a `DEPLOY_MARKER_2026_06_11_PAYDRAWER_RN`
  HTML comment in `public_html/member/index.php` (currently R4 / commit
  `d14b1a9`).
- After deploy, curl + grep for the marker to confirm files actually
  copied. If marker is missing → rsync failed silently (rare). If marker
  is present but new behaviour isn't → likely a PHP conditional hiding
  output. Both have happened this session.

**Use the marker on every change. It saves an hour of "why isn't this
working" debugging.**

---

## 7. Recent commits to know about (all live as of last deploy)

| SHA | What it does |
|---|---|
| `d14b1a9` | Restyle pay-drawer as centred lightbox, add visible Element errors |
| `a82a0ec` | Remove the `$_drawerOk` try/catch that was silently hiding the drawer |
| `b358ff8` | Deploy diagnostic |
| `6b5eb56` | Add deploy marker |
| `88ac95c` | Member profile updates → notification hub (separate feature) |
| `f929aa6` | Two-panel inline pay-drawer + kill all Stripe redirects |
| `c2ab973` | `file_exists` guards on partials |
| `4b063b5` | First pay-drawer attempt (right-side panel — wrong shape) |
| `20f6821` | Security-block partial across all pay areas |
| `69c2672` | Original inline Payment Element widget + `/api/payments/intent` |

---

## 8. Quick reference — key file locations

| Thing | Path | Lines (approx) |
|---|---|---|
| Pay lightbox markup (DELETE/REBUILD) | `public_html/member/index.php` | 5422–5640 |
| Pay lightbox JS controller (REWRITE) | `public_html/assets/js/pay-membership-drawer.js` | full file |
| Renew modal (REFERENCE for screen 1) | `public_html/member/index.php` | 5822–5740 |
| Thank-you lightbox + confetti (KEEP) | `public_html/member/index.php` | 5478–5588 |
| Store checkout Element pattern (REFERENCE for screen 2) | `public_html/checkout.php` | 440–470, 695–960 |
| Membership intent API (KEEP) | `public_html/api/index.php` | 297–460 |
| Pricing calc fn | `public_html/member/index.php` | 123–144 |
| Renew-modal POST handler (KEEP — redirects to drawer now) | `public_html/member/index.php` | 773–960 |
| `.cpanel.yml` deploy config | repo root | 5 lines |

---

## 9. Acceptance test for the next session

After rebuild + deploy:

1. Land on `/member/?page=billing` as Pat (a lapsed Full Member with the
   "Renew my membership" hero button).
2. Click **Renew my membership** → centred lightbox opens looking like the
   current `#renew-modal` but with View 1 from §2.
3. Click a Term card, optionally tick "Also pay for my Associate" → the
   "You pay today" total updates live.
4. Tick "I confirm details", click **Continue to payment**.
5. Lightbox card SLIDES horizontally (no full-page redirect). View 2 shows
   order summary + Stripe Payment Element fully loaded + Pay button.
6. Type test card `4242 4242 4242 4242 / 12/34 / 123`, click **Pay $X.XX**.
7. Stripe processes inline (no hop to checkout.stripe.com), then redirects
   to `/member/?renewed=1`.
8. `#renewed-lightbox` confetti modal fires; member status updates to
   ACTIVE; treasurer email lands with the round-7 reconciliation table.

If steps 5 or 7 fail, the inline `data-pay-drawer-error-pay` /
`data-pay-drawer-error-select` boxes should now show the actual error
(round `d14b1a9` added those). Paste them back into the next chat to
debug.

---

That's it. The next chat can `cat` this file as its first action and
land the rebuild. Good luck. 🤞
