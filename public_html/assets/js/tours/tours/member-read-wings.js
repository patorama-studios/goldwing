/* Tour: "How to read this month's Wings"
 *
 * 5 steps. Plain English. Each step does one tiny thing.
 * Anchors live in public_html/member/read_wings.php (the flipbook reader).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-read-wings', [
    {
      element: '[data-tour="wings-title"]',
      popover: {
        title: "G'day — welcome to Wings",
        description: "This is the latest Wings magazine, ready to read on your screen. " +
                     "Click <strong>Next</strong> and we'll show you around.",
        side: 'bottom',
        align: 'center',
      },
    },
    {
      element: '[data-tour="wings-book"]',
      popover: {
        title: 'The magazine',
        description: "This is the magazine itself — just like the printed copy. " +
                     "You can read it right here on the page.",
        side: 'top',
        align: 'center',
      },
    },
    {
      element: '[data-tour="wings-next"]',
      popover: {
        title: 'Turn the page',
        description: "Click this arrow to flip to the next page. " +
                     "There's a matching arrow on the left to go back.",
        side: 'left',
        align: 'center',
      },
    },
    {
      element: '[data-tour="wings-download"]',
      popover: {
        title: 'Save a copy to your computer',
        description: "Click <strong>Download PDF</strong> if you'd like to save the magazine " +
                     "to read later or print it out.",
        side: 'top',
        align: 'end',
      },
    },
    {
      element: '[data-tour="wings-reader"]',
      popover: {
        title: "You're all set",
        description: "That's it — flip through the pages with the arrows, or download a copy to keep.<br><br>" +
                     "<strong>Happy reading!</strong>",
        side: 'top',
        align: 'center',
      },
    },
  ]);
})();
