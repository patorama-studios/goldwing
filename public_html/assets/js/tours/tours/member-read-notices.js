/* Tour: "How to read chapter notices"
 *
 * 4 steps. Plain English. Each step does one thing.
 * Anchors live in public_html/member/index.php (the notices-view section).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-read-notices', [
    {
      element: '[data-tour="read-notices-heading"]',
      popover: {
        title: 'The Notice Board',
        description: "This is where the club and your chapter share news and notices. " +
                     "Click <strong>Next</strong> to take a quick look.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="read-notices-view-toggle"]',
      popover: {
        title: 'Choose how you see them',
        description: "Click <strong>List view</strong> to read notices one under the other. " +
                     "Or click <strong>Grid view</strong> to see them as little cards.",
        side: 'bottom',
        align: 'end',
      },
    },
    {
      element: '[data-tour="read-notices-board"]',
      popover: {
        title: 'Read the latest notices',
        description: "Each notice shows the title, who posted it, and the date. " +
                     "Scroll down to read the message and see any pictures or PDFs.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="read-notices-section"]',
      popover: {
        title: 'All done',
        description: "Pop back here anytime to see what's new. " +
                     "<br><br><strong>That's it — you've done it!</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
