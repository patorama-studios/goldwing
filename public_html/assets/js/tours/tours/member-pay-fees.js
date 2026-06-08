/* Tour: "How to pay your yearly fees"
 *
 * Plain English. Each step does one thing.
 * Anchors live in public_html/member/index.php — both the billing section
 * and the renewal lightbox (the modal that opens when you click Renew now).
 *
 * The tour auto-detects which buttons exist. A member outside the 60-day
 * window sees only the status / expiry / history steps; a member who's
 * due to renew sees the Renew-now CTA + lightbox steps too.
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
                     "If it's within 60 days, a red <strong>Renew now</strong> button appears automatically — that's your prompt to pay.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="pay-fees-pay-now"], [data-tour="pay-fees-renew"], [data-tour="pay-fees-status-card"]',
      popover: {
        title: 'Start the renewal',
        description: "Click the gold <strong>Pay now</strong> button (for a one-off charge), or the red <strong>Renew now</strong> button (which opens the renewal options). " +
                     "We'll walk through the renewal options next.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="renew-term"], [data-tour="pay-fees-renew"], [data-tour="pay-fees-status-card"]',
      popover: {
        title: 'Pick how long you want to renew for',
        description: "<strong>Open the Renew now button first, then click Next.</strong> " +
                     "Inside the pop-up you'll see three options — <strong>1 year</strong>, <strong>2 years</strong>, or <strong>3 years</strong>. " +
                     "Each one shows your price. Pick whichever suits you.",
        side: 'right',
        align: 'start',
      },
    },
    {
      element: '[data-tour="renew-partner"], [data-tour="renew-term"]',
      popover: {
        title: 'Renew your partner too (if you have one)',
        description: "If your spouse / partner is also a member, you'll see a tick box to <strong>also renew them</strong> at the same time. " +
                     "Tick it and the price updates live — you only pay once for both of you.",
        side: 'right',
        align: 'start',
      },
    },
    {
      element: '[data-tour="renew-submit"], [data-tour="renew-term"]',
      popover: {
        title: 'Confirm and pay',
        description: "Tick the <em>'my details are correct'</em> box, then click the red <strong>Continue to payment</strong> button. " +
                     "We'll send you to a safe Stripe page to pay with your card. Once you pay, your membership is renewed straight away.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="renew-cancel-link"], [data-tour="renew-term"]',
      popover: {
        title: "Don't want to renew?",
        description: "Down the bottom of the pop-up there's a small <strong>Cancel my membership instead</strong> link. " +
                     "Clicking it lets you tell us you're not renewing this year — you keep access until your current paid period ends, and the committee gets a heads-up to follow up.",
        side: 'top',
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
