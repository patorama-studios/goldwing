/* Tour: "How to manage trophy categories"
 *
 * 4 steps. Covers the categories table and create/edit form.
 * Anchors live in public_html/admin/awards/categories.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-awards-categories', [
    {
      element: '[data-tour="categories-header"]',
      popover: {
        title: 'Trophy Categories',
        description: "This page defines the 16 trophy types awarded at the AGM — their names, groupings, and memorial trophy names. " +
                     "This is a one-time setup. Once the categories exist you rarely need to come back here. " +
                     "Click <strong>Next</strong> to see how it works.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="categories-table"]',
      popover: {
        title: 'The Category List',
        description: "Each row is one trophy. The columns show: " +
                     "<br>• <strong>Sort</strong> — controls the display order on the Wall of Awards " +
                     "<br>• <strong>Name</strong> — the trophy name (e.g. Best Original GL1800) " +
                     "<br>• <strong>Group</strong> — groups trophies together under a heading (e.g. Best Original Goldwing) " +
                     "<br>• <strong>Memorial Trophy</strong> — the formal trophy name if it honours someone (e.g. Burden Memorial Trophy) " +
                     "<br>• <strong>Status</strong> — Active categories appear on the Wall; Inactive ones are hidden. " +
                     "<br><br>Click <strong>Edit</strong> on any row to modify it.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="categories-new-btn"]',
      popover: {
        title: 'Adding a New Category',
        description: "Click <strong>New Category</strong> to open the form below the header. " +
                     "Fill in the trophy name, set its group label so it clusters with related trophies, " +
                     "add the memorial trophy name if it has one, and tick <strong>Active</strong> so it appears on the Wall. " +
                     "<br><br>The sort order controls where it appears within its group — lower numbers appear first.",
        side: 'bottom',
        align: 'end',
      },
    },
    {
      element: '[data-tour="categories-header"]',
      popover: {
        title: 'All set',
        description: "Once your categories are in place, head back to <strong>AGM Awards</strong> (top-left back link) " +
                     "to start recording winners for each trophy. " +
                     "<br><br><strong>That's all there is to it!</strong>",
        side: 'bottom',
        align: 'start',
      },
    },
  ]);
})();
