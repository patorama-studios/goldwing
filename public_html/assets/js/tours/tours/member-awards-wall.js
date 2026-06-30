/* Tour: "How to view the Wall of Awards"
 *
 * 4 steps. Covers the member-facing awards page in both teaser and live states.
 * Anchors live in public_html/members/awards/index.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-awards-wall', [
    {
      element: '[data-tour="awards-teaser"], [data-tour="awards-wall-header"]',
      popover: {
        title: 'The AGA Wall of Awards',
        description: "This page celebrates every trophy winner at the AGM — 16 categories, covering original and custom Goldwings, distance achievements, and the People's Choice. " +
                     "Click <strong>Next</strong> to see how it works.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="awards-teaser"], [data-tour="awards-wall-year"]',
      popover: {
        title: 'Coming Soon or Live?',
        description: "If the page shows a gold <strong>Coming Soon</strong> banner, the committee is still entering winner data before the reveal. " +
                     "Keep an eye on this page — once results are published it switches to the full Wall of Awards automatically. " +
                     "<br><br>When it's live, a <strong>Year</strong> dropdown appears here so you can browse back through past AGMs.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="awards-wall-group"], [data-tour="awards-teaser"]',
      popover: {
        title: 'Trophy Cards',
        description: "Winners are displayed as cards grouped by category — Best Original Goldwing, Best Custom Goldwing, and Individual Trophies. " +
                     "Each card shows a photo of the winning bike, the trophy name, the winner's name, and their bike description. " +
                     "<br><br>If a winner hasn't been entered yet, the card shows <em>Winner not yet recorded</em> in grey italic text.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="awards-teaser"], [data-tour="awards-wall-header"]',
      popover: {
        title: 'Your trophy cabinet',
        description: "If you've won a trophy at an AGM and your name is linked to your member record, your wins will appear on your member profile too. " +
                     "Contact the webmaster if you believe a past win is missing from your record. " +
                     "<br><br><strong>That's the Wall of Awards — enjoy browsing the history!</strong>",
        side: 'bottom',
        align: 'start',
      },
    },
  ]);
})();
