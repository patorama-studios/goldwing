/* Tour: "How to post a notice for members"
 *
 * 5 steps. Plain English for volunteer admins. Each step does one thing.
 * Anchors live in public_html/admin/index.php (the notices section).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-send-notice', [
    {
      element: '[data-tour="send-notice-form"]',
      popover: {
        title: 'Posting a notice',
        description: "We'll walk through creating a notice that members see on their dashboard. " +
                     "Click <strong>Next</strong> to begin.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="send-notice-title"]',
      popover: {
        title: 'Give it a title',
        description: "Type a short, clear title that members will see in their notice list. " +
                     "Keep it under about ten words.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="send-notice-audience"]',
      popover: {
        title: 'Choose who sees it',
        description: "Pick <strong>All members</strong>, a single <strong>State</strong>, or a single <strong>Chapter</strong>. " +
                     "The State and Chapter dropdowns only enable when you select that option.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="send-notice-editor"]',
      popover: {
        title: 'Write the notice',
        description: "Click into this box and type your message. " +
                     "Use the toolbar above for basic formatting or to add a link.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="send-notice-publish"]',
      popover: {
        title: 'Publish it',
        description: "When you're happy, click <strong>Publish Notice</strong>. " +
                     "<br><br>The notice is now live on members' dashboards.",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
