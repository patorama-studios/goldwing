/* Tour: "How to action and refund a payment"
 *
 * 7 steps. Plain English. Each step does one thing.
 * Anchors live in public_html/admin/index.php (the page=payments section).
 *
 * Walks an admin through the new Payments & Settings dashboard:
 *   1. The page itself
 *   2. The Stripe connection card (so they know what "connected" looks like)
 *   3. Finding the order via search
 *   4. Filtering by date / status / type
 *   5. The four row-action buttons (View / Refund / Void / Delete)
 *   6. The green Refund icon specifically + what happens next
 *   7. The Payments Debug Log (for when something went wrong upstream)
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-action-refund', [
    {
      element: '[data-tour="admin-payments-header"]',
      popover: {
        title: 'Payments & Settings',
        description: "This is the master payments dashboard. Every order the site has ever taken — memberships and store, mixed together — lives here. " +
                     "Click <strong>Next</strong> to see how to find and action one.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-payments-stripe-status"]',
      popover: {
        title: 'Stripe connection',
        description: "Quick health check at the top of the page. <strong>SYSTEM ONLINE</strong> + <strong>Connected</strong> + a recent webhook means money can flow. " +
                     "If this is red, refunds and new payments will fail — don't action anything until you fix it.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-payments-search"]',
      popover: {
        title: 'Find the order',
        description: "Type the member's name, email, or the order number (like <em>M-2026-000482</em>). " +
                     "Hit Apply or press Enter.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-payments-filters"]',
      popover: {
        title: "Don't know the order number?",
        description: "Click <strong>Filters</strong> to narrow by Status (paid / pending / refunded), Type (membership / store), Date From / Date To. " +
                     "Useful when a member says <em>'I paid last Tuesday'</em>.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-payments-row-actions"]',
      popover: {
        title: 'Four actions per row',
        description: "<strong>View</strong> (eye) opens the full order. " +
                     "<strong>Refund</strong> (green) only shows for paid orders. " +
                     "<strong>Void</strong> hides the order without touching Stripe. " +
                     "<strong>Delete</strong> wipes the row permanently — it makes you type DELETE to confirm.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-payments-refund-button"], [data-tour="admin-payments-row-actions"]',
      popover: {
        title: 'Issuing the refund',
        description: "Click the green <strong>Refund</strong> icon. A modal pops up — type a short reason (optional) and click <strong>Refund order</strong>. " +
                     "You'll be asked for your 2FA code if it's been a while since you used it. " +
                     "<br><br><strong>What happens next:</strong> we update our records, tell Stripe, Stripe pulls the money from the association balance, the member's card gets credited in 5–10 business days, the member gets an email, and the activity log records it. " +
                     "<strong>There's no undo</strong> — once Stripe accepts, you can't reverse it.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-payments-debug-log"]',
      popover: {
        title: 'Something went wrong?',
        description: "If a payment didn't land or a refund failed, expand the <strong>Payments Debug Log</strong>. " +
                     "It shows the last 50 Stripe webhooks we received, with any error messages. " +
                     "Send the matching row to your developer if the error looks like jargon. " +
                     "<br><br><strong>That's it — you can refund any payment from this dashboard.</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
