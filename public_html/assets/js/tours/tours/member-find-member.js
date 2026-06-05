/* Tour: "How to find another member"
 *
 * 4 steps. Plain English. Each step does one thing.
 * Anchors live in public_html/member/index.php (the directory section).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-find-member', [
    {
      element: '[data-tour="find-member-section"]',
      popover: {
        title: 'The Members Directory',
        description: "This is the list of all your fellow members. " +
                     "Click <strong>Next</strong> and we'll show you how to look someone up.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="find-member-search"]',
      popover: {
        title: 'Search by name',
        description: "Click in this box and type a name or member number. " +
                     "The list below will narrow down as you type.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="find-member-chapter"]',
      popover: {
        title: 'Pick a chapter',
        description: "Want to see members from one chapter only? " +
                     "Click this box and choose a chapter from the list.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="find-member-table"]',
      popover: {
        title: 'Their details',
        description: "Each row shows a member's name, phone and email. " +
                     "Click their phone number to call, or their email to send a message." +
                     "<br><br><strong>That's it — you've done it!</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
