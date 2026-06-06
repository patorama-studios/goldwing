/* Tour: "How to make a member a Life Member"
 *
 * 5 steps. Walks an admin through converting a regular member to a Life
 * Member from their profile page.
 *
 * Tour-target anchors live in public_html/admin/members/view.php on the
 * Orders tab inside the "Manual membership order" form.
 *
 * Entry pattern: the user lands on the members list with this tour pending.
 * They click into the right member; this tour fires once the URL contains
 * /admin/members/view.php and the Orders tab is open.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-make-life-member', [
    {
      element: '[data-tour="manual-membership-section"]',
      popover: {
        title: 'Open the Orders tab on this member',
        description: "We're going to convert this member to a Life Member. " +
                     "Make sure you're on the <strong>Orders</strong> tab, then scroll to the " +
                     "<strong>Manual membership order</strong> panel — that's where we'll do it.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="manual-membership-type"]',
      popover: {
        title: 'Pick the Life membership type',
        description: "Open this dropdown and choose <strong>Life</strong>. " +
                     "Once you pick it, the cost field below will be set ready for a Life Member.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="manual-membership-cost"]',
      popover: {
        title: 'Set the cost',
        description: "For a Life Membership, this is whatever the member paid at the AGM (often $0 if it's a comp Life Membership awarded for service). " +
                     "Leave it at <strong>0.00</strong> for an honorary Life Member, or enter the amount paid if it's a paid Life Membership.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="manual-membership-section"]',
      popover: {
        title: 'Fill out the rest of the form',
        description: "Set the <strong>Payment method</strong> (Complimentary for honorary, otherwise whatever was used), " +
                     "leave <strong>Status</strong> as Active, and add a note in <strong>Order reference</strong> like " +
                     "<em>AGM 2026 honorary Life Membership — minutes p.4</em> so future admins know why.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="manual-membership-save"]',
      popover: {
        title: 'Save the order',
        description: "Click <strong>Save manual order</strong>. The system updates the member's record straight away — " +
                     "their renewal date becomes <em>N/A</em>, the gold Life Member badge appears on their profile, " +
                     "and they show up on the Life Member list.<br><br>" +
                     "<strong>That's it — they're a Life Member.</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
