<?php
/**
 * "Become a Member" — the public join page.
 *
 * This page is wired to the committee pricing matrix
 * (`membership.pricing.config` via MembershipPricingService), the single source
 * of truth. Rather than maintain a second join form, it serves the same
 * matrix-driven application flow as /apply.php, so it inherits — identically —
 * its three guarantees:
 *
 *   • dynamic prices read from the matrix (getJoinOptions / getMembershipPricing)
 *   • the charge is computed SERVER-SIDE via resolveJoinPriceCents()
 *   • the client never submits a price; it only sends a period key
 *
 * The old standalone Stripe-subscription form (which read the decoupled
 * `payments.membership_prices` Stripe Price IDs) has been removed — it is
 * recoverable from git history if a recurring-subscription join is ever wanted
 * as its own feature. See PRICING_WIRE_PLAN.md.
 *
 * apply.php self-posts to window.location.pathname for its AJAX completion and
 * uses __DIR__-relative requires, so it runs correctly when served from this
 * path too.
 */
require __DIR__ . '/apply.php';
