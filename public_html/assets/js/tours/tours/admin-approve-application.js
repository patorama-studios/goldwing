/* Tour: "How to approve a new member application"
 *
 * 5 steps. Plain English for volunteer admins. Each step does one thing.
 * Anchors live in public_html/admin/index.php (the applications section).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-approve-application', [
    {
      element: '[data-tour="approve-app-panel"]',
      popover: {
        title: 'Reviewing applications',
        description: "This is where new membership applications appear. " +
                     "Click <strong>Next</strong> to walk through approving one.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="approve-app-filters"]',
      popover: {
        title: 'Filter by status',
        description: "Use these tabs to switch between Pending, Approved and Rejected applications. " +
                     "New applications waiting for review sit under <strong>Pending</strong>.",
        side: 'bottom',
        align: 'end',
      },
    },
    {
      element: '[data-tour="approve-app-view"]',
      popover: {
        title: 'Open the full details',
        description: "Click <strong>View</strong> to read the applicant's full submission before deciding. " +
                     "You can return to this list using your browser's back button.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="approve-app-chapter"]',
      popover: {
        title: 'Assign a chapter',
        description: "Pick the chapter the applicant should belong to from the dropdown, then click <strong>Assign</strong>. " +
                     "This step is optional but recommended before approval.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="approve-app-button"]',
      popover: {
        title: 'Approve the application',
        description: "Click the green tick to open the confirmation dialog, then choose <strong>Yes, approve</strong>. " +
                     "<br><br>The applicant will receive an approval email and become a full member.",
        side: 'left',
        align: 'start',
      },
    },
  ]);
})();
