/* Tour: "How to find and edit a member"
 *
 * 5 steps. Plain English. Each step does one thing.
 * This tour runs on the members list page. Editing happens on the
 * member view page, so the final step asks the admin to open the
 * member's record to make changes there.
 * Anchors live in public_html/admin/members/index.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-find-edit-member', [
    {
      element: '[data-tour="admin-find-member-search"]',
      popover: {
        title: 'Finding a member',
        description: "We'll walk through searching for a member and opening their record to edit it. " +
                     "Click in this box and type a name, email, or phone number.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-find-member-chapter"]',
      popover: {
        title: 'Narrow it down by chapter',
        description: "If you know the member's chapter, pick it here to shorten the list. " +
                     "Leave it on <strong>All chapters</strong> if you're not sure.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-find-member-status"]',
      popover: {
        title: 'Filter by status',
        description: "Use this to show only active, pending, or expired members. " +
                     "Leave it on <strong>All statuses</strong> to see everyone.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-find-member-apply"]',
      popover: {
        title: 'Apply your filters',
        description: "Click the gold <strong>Apply filters</strong> button to update the list below. " +
                     "Your matching members will appear underneath.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-find-member-row"]',
      popover: {
        title: 'Open the member',
        description: "Find the member you want in the list, then click their name to open their record. " +
                     "From there you can update their details and save the changes.<br><br>" +
                     "<strong>That's it — you're ready to edit them!</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
