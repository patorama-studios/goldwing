/* Tour: "Set new-member joining prices"
 *
 * 5 steps. Walks a treasurer/admin through the New-member joining prices
 * matrix on Settings → Membership Settings, and how it differs from the
 * renewal table above it.
 *
 * Tour-target anchors live in public_html/admin/settings/index.php in the
 * "New-member joining prices" card (data-pricing-card="joining").
 *
 * Entry pattern: the tour fires on the membership-pricing settings section
 * (page_match includes ?section=membership_pricing). The card only renders
 * on that section, so the selectors resolve only there.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-membership-joining-prices', [
    {
      element: '[data-tour="joining-card"]',
      popover: {
        title: 'New-member joining prices',
        description: "This card sets what a <strong>brand-new</strong> member pays. It's separate from the " +
                     "Renewal pricing table above on purpose: renewals are the plain membership price, while " +
                     "joiners pay the membership <em>plus</em> a one-off joining fee — and less if they join late " +
                     "in the year. The numbers here match the committee's printed fee matrix cell-for-cell.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="joining-enabled"]',
      popover: {
        title: 'Use the joining matrix',
        description: "Leave this ticked so new joiners are charged the exact prices in this card. If you ever " +
                     "untick it, new joiners fall back to the automatic pro-rata calculator further down — but " +
                     "for matching the committee's sheet exactly, keep it on.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="joining-matrix"]',
      popover: {
        title: 'One price per join window',
        description: "Each term (1 Year, 3 Years) has three prices: <strong>Start of year</strong> (joined before " +
                     "1 Dec), <strong>After 1 Dec</strong>, and <strong>After 1 Apr</strong>. The website picks the " +
                     "right column automatically from the member's join date — you don't calculate anything, you " +
                     "just type the figures from the committee's matrix. Type dollars, e.g. <code>90.00</code>.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="joining-fee"]',
      popover: {
        title: 'The one-off joining fee',
        description: "This is the joining fee on its own. The prices in the matrix above <em>already include</em> it — " +
                     "this field just sets the default that pre-fills on the <strong>Add Member</strong> wizard when " +
                     "you add someone manually. Keep it in step with the fee built into the matrix (currently $15).",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="pricing-save"]',
      popover: {
        title: 'Save to make it live',
        description: "Click <strong>Save Settings</strong>. The new joining prices are live on the next checkout — " +
                     "no developer or deploy needed. Use the dark <em>'If a member checked out today…'</em> preview " +
                     "card above to sanity-check before you save.<br><br><strong>That's it.</strong>",
        side: 'top',
        align: 'end',
      },
    },
  ]);
})();
