/* Tour: "How to process a member's order"
 *
 * 5 steps. Plain English. Each step does one thing.
 * Anchors live in public_html/admin/store/order_view.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-process-order', [
    {
      element: '[data-tour="admin-process-order-summary"]',
      popover: {
        title: "Processing an order",
        description: "We'll walk through packing and shipping this order. " +
                     "Click <strong>Next</strong> when you're ready.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-process-order-items"]',
      popover: {
        title: 'Check what was ordered',
        description: "This shows the items the member bought and how many of each. " +
                     "Gather these items so you can pack them.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-process-order-fulfillment"]',
      popover: {
        title: 'Add the tracking details',
        description: "Once the parcel is packed, type the carrier (for example, <em>Australia Post</em>) and the tracking number. " +
                     "Set the date you posted it.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-process-order-ship-button"]',
      popover: {
        title: 'Mark it as shipped',
        description: "Click the gold <strong>Save tracking + mark shipped</strong> button. " +
                     "The buyer will get an email with the tracking number.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-process-order-refund"]',
      popover: {
        title: 'Need to refund instead?',
        description: "If something went wrong, type an amount here (or leave blank for a full refund) and click <strong>Process refund</strong>. " +
                     "<strong>That's it — the order is marked as shipped and the buyer's been emailed.</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
