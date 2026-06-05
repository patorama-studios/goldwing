/* Tour: "How to approve a Fallen Wings tribute"
 *
 * 5 steps. Plain English for volunteer admins. Each step does one thing.
 * Anchors live in public_html/admin/index.php (the fallen-wings section).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-approve-fallen-wing', [
    {
      element: '[data-tour="approve-fallen-panel"]',
      popover: {
        title: 'Reviewing Fallen Wings tributes',
        description: "This page lists all tribute submissions for members who have passed. " +
                     "Click <strong>Next</strong> to walk through approving one.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="approve-fallen-section"]',
      popover: {
        title: 'Pending submissions',
        description: "New tributes waiting for review appear in this list. " +
                     "If it says \"No pending submissions\", there's nothing to action right now.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="approve-fallen-entry"]',
      popover: {
        title: 'Read the tribute',
        description: "Each entry shows the member's name, year of passing and the tribute text. " +
                     "Read it carefully and check the image or PDF if one is attached.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="approve-fallen-reject"]',
      popover: {
        title: 'Reject if needed',
        description: "If the submission isn't suitable, click <strong>Reject</strong> to remove it from the list. " +
                     "Use this for duplicates or content that needs to be redone.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="approve-fallen-approve"]',
      popover: {
        title: 'Approve the tribute',
        description: "When you're satisfied, click <strong>Approve</strong>. " +
                     "<br><br>The tribute is now published on the Fallen Wings page.",
        side: 'left',
        align: 'start',
      },
    },
  ]);
})();
