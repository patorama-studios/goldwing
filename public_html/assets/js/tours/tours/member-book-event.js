/* Tour: "How to book a ride or event"
 *
 * 4 steps. Member audience — plain English, mate-like Aussie tone.
 * Anchors live in calendar/public/events.php (rendered at /calendar/events.php).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-book-event', [
    {
      element: '[data-tour="book-event-header"]',
      popover: {
        title: 'G\'day — booking a ride',
        description: "We'll walk through finding a ride or event and confirming your spot. " +
                     "Click <strong>Next</strong> when you're ready.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour~="book-event-list"]',
      popover: {
        title: 'All the upcoming events',
        description: "This is the list of rides and events you can join. " +
                     "Have a scroll and find one that takes your fancy.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour~="book-event-row"]',
      popover: {
        title: 'Pick one you like',
        description: "Each row shows the event name, chapter, and when it's on. " +
                     "Find one that looks good to you.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour~="book-event-view"]',
      popover: {
        title: 'Click View to book',
        description: "Click <strong>View</strong> on that row to open the event, then tap the RSVP button to lock in your spot. " +
                     "<br><br><strong>That's it — you've booked!</strong>",
        side: 'left',
        align: 'start',
      },
    },
  ]);
})();
