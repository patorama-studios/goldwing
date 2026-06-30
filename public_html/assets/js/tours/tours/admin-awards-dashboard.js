/* Tour: "How to manage AGM Awards — the dashboard"
 *
 * 5 steps. Covers the main awards dashboard, visibility toggle, year selector, and trophy list.
 * Anchors live in public_html/admin/awards/index.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-awards-dashboard', [
    {
      element: '[data-tour="awards-header"]',
      popover: {
        title: 'AGM Awards Dashboard',
        description: "This is your command centre for managing trophy winners. " +
                     "You can record winners for each year, upload photos of winning bikes, and control what members see. " +
                     "Click <strong>Next</strong> to walk through it.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="awards-feature-toggle"]',
      popover: {
        title: 'Member-Facing Visibility',
        description: "This card controls what members see when they visit the Awards page. " +
                     "<br><br><strong>Coming Soon</strong> — shows a teaser page to build excitement before the AGM. " +
                     "<br><strong>Live</strong> — shows the full Wall of Awards with all recorded winners. " +
                     "<br><br>Click <strong>Go Live</strong> or <strong>Switch to Coming Soon</strong> to toggle at any time.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="awards-year-selector"]',
      popover: {
        title: 'AGM Year Selector',
        description: "Use this dropdown to switch between AGM years. " +
                     "The counter shows how many of the trophy categories have a winner recorded for the selected year. " +
                     "Future years appear automatically so you can start entering winners early.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="awards-trophy-group"]',
      popover: {
        title: 'Trophy List',
        description: "Trophies are grouped by category — Best Original Goldwing, Best Custom Goldwing, and Individual Trophies. " +
                     "Each row shows a photo thumbnail, the winner's name, and their bike description. " +
                     "Empty slots show an italic placeholder until a winner is recorded. " +
                     "<br><br>Click <strong>Add Winner</strong> on any empty row, or <strong>Edit</strong> on a filled one.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="awards-manage-trophies-btn"]',
      popover: {
        title: 'Manage Trophy Categories',
        description: "Click <strong>Manage Trophies</strong> to view, add, or edit the 16 trophy category definitions — names, groupings, memorial trophy names, and sort order. " +
                     "This is a one-time setup that rarely needs changing. " +
                     "<br><br><strong>That's the dashboard — you're across it!</strong>",
        side: 'bottom',
        align: 'end',
      },
    },
  ]);
})();
