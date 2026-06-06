/* Tour: "The Notification Hub — what it is and how it works"
 *
 * 6 steps. Walks an admin through the request inbox at /admin/requests/.
 * Anchors live in public_html/admin/requests/index.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-notification-hub', [
    {
      element: '[data-tour="notif-hub-header"]',
      popover: {
        title: 'What the Notification Hub is for',
        description: "This is your admin <strong>inbox</strong>. " +
                     "Every time a member does something that needs an admin decision — applying to join, asking to switch chapter, nominating someone for Member of the Year, submitting feedback — a request lands here. " +
                     "Nothing important slips through the cracks because every action is tracked from here.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="notif-hub-stats"]',
      popover: {
        title: 'The four numbers at a glance',
        description: "<strong>Pending</strong> is what you still have to act on. <strong>Approved</strong> and <strong>Rejected</strong> count your decisions over time. <strong>Total</strong> is everything in the hub, including archived items. The orange Pending number is the one to keep low — it's also what the sidebar badge shows.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="notif-hub-type-filters"]',
      popover: {
        title: 'Filter by what kind of request it is',
        description: "Click any of these chips to see only one type of request — <strong>Chapter Change</strong>, <strong>Member of the Year</strong>, <strong>Beta Feedback</strong>, etc. Click <strong>All Notifications</strong> to clear the filter. The little number on each chip is how many of that type are waiting.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="notif-hub-status-pills"]',
      popover: {
        title: 'Filter by what stage they\'re in',
        description: "Stack this on top of the type filter to narrow further. <strong>Pending</strong> is the default — your to-do list. Switch to <strong>Approved</strong> or <strong>Rejected</strong> to look back at past decisions, or <strong>Archived</strong> to find old items you've finished with.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="notif-hub-list"]',
      popover: {
        title: 'A request, ready for your decision',
        description: "Each card shows the type, who asked, what they want, and how long it's been waiting. Click <strong>View Details</strong> to open the full request — that's where you see all the information and the <strong>Approve</strong> or <strong>Reject</strong> buttons. You can add a short message; the member sees your reason in their email and in their portal.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="notif-hub-header"]',
      popover: {
        title: 'That\'s the loop',
        description: "Pick a request type, open one, decide, repeat. Everything you do gets recorded — see the <strong>Approved</strong> / <strong>Rejected</strong> tabs for history, or open <strong>Admin → Settings → Audit Log</strong> for the full record.<br><br>" +
                     "<strong>That's the Notification Hub — you're ready.</strong>",
        side: 'bottom',
        align: 'start',
      },
    },
  ]);
})();
