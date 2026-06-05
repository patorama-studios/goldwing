/* Tour: "How to manage your events"
 *
 * 5 steps. Admin audience.
 * Anchors live in calendar/public/events.php (rendered at /calendar/admin_events.php).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-manage-events', [
    {
      element: '[data-tour~="manage-events-list"]',
      popover: {
        title: 'Your events list',
        description: "This table shows every event in the calendar — chapter and national. " +
                     "Click <strong>Next</strong> to see how to edit and cancel one.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="manage-events-columns"]',
      popover: {
        title: 'Sort and scan the columns',
        description: "Use the columns to find what you need — Title, Chapter, Start and Status. " +
                     "Pending events sit at the top with an amber highlight.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour~="manage-events-view"]',
      popover: {
        title: 'View or edit an event',
        description: "Click <strong>View</strong> on any row to open the event page. " +
                     "From there you can edit details, see RSVPs, and manage registrations.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="manage-events-delete"]',
      popover: {
        title: 'Cancel an event',
        description: "Click <strong>Delete</strong> on the row to cancel the event — you'll be asked to confirm. " +
                     "This removes the event and all its RSVPs.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="manage-events-create"]',
      popover: {
        title: 'Add a new event',
        description: "Click <strong>Create Event</strong> in the top right to start a new one. " +
                     "<br><br><strong>Done — you can manage events with confidence.</strong>",
        side: 'bottom',
        align: 'end',
      },
    },
  ]);
})();
