/* Tour: "How to pay your yearly fees"
 *
 * 4 steps. Plain English. Each step does one thing.
 * Anchors live in public_html/member/index.php (the billing section).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-pay-fees', [
    {
      element: '[data-tour="pay-fees-status-card"]',
      popover: {
        title: 'Your membership',
        description: "This box shows your membership and any fees that need paying. " +
                     "Click <strong>Next</strong> to see how to pay.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="pay-fees-expiry"]',
      popover: {
        title: 'When your membership runs out',
        description: "This is the date your membership ends. " +
                     "If it's getting close, it's time to pay your fees.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="pay-fees-pay-now"], [data-tour="pay-fees-renew"], [data-tour="pay-fees-status-card"]',
      popover: {
        title: 'Pay your fees',
        description: "Click the gold <strong>Pay now</strong> button (or <strong>Renew membership</strong> if it's close to expiring). " +
                     "You'll be taken to a safe page to pay with your card.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="pay-fees-history"]',
      popover: {
        title: 'Check your past payments',
        description: "Down here you can see every fee you've paid. " +
                     "<br><br><strong>That's it — you've done it!</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
