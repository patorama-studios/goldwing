/* Tour: "How to create an event"
 *
 * 5 steps. Admin audience — slightly more formal but still plain English.
 * Anchors live in calendar/public/admin_event_create.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-create-event', [
    {
      element: '[data-tour="create-event-form"]',
      popover: {
        title: 'Creating a new event',
        description: "We'll walk through the four key pieces: name, date, location, and chapter. " +
                     "Click <strong>Next</strong> to begin.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="create-event-title"]',
      popover: {
        title: 'Name your event',
        description: "Type a clear, short title — this is what members will see in the calendar. " +
                     "A description below the title helps members know what to expect.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="create-event-classification"]',
      popover: {
        title: 'Choose the chapter',
        description: "Pick <strong>Chapter</strong> for a chapter ride or <strong>National</strong> for an Australia-wide event. " +
                     "If it's a chapter event, select the chapter from the dropdown.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="create-event-datetime"]',
      popover: {
        title: 'Set the date and time',
        description: "Fill in the start and end times — the timezone defaults to Sydney. " +
                     "Tick <strong>All-day event</strong> if there are no specific times.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="create-event-location"]',
      popover: {
        title: 'Add the location',
        description: "Enter the meeting point and destination so members know where to go. " +
                     "A Google Maps link is helpful but optional.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="create-event-publish"]',
      popover: {
        title: 'Publish the event',
        description: "When everything looks right, click <strong>Publish Event</strong> at the top. " +
                     "Members and area reps will be notified.<br><br>" +
                     "<strong>Done — your event is up.</strong>",
        side: 'bottom',
        align: 'end',
      },
    },
  ]);
})();
