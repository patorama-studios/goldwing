/* Tour: "How to submit an event for review"
 *
 * 6 steps. Member audience — plain English, mate-like Aussie tone.
 * Anchors live in calendar/public/member_event_submit.php
 * (rendered at /calendar/member_event_submit.php).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-submit-event', [
    {
      element: '[data-tour="submit-event-form"]',
      popover: {
        title: "G'day — submitting an event",
        description: "Got a ride or get-together you'd like on the calendar? " +
                     "We'll walk through filling in the form. " +
                     "An admin or your Area Rep will check it over before it goes live. " +
                     "Click <strong>Next</strong> when you're ready.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="submit-event-title"]',
      popover: {
        title: 'Name and describe it',
        description: "Pop in a short title — this is what other members will see in the calendar. " +
                     "Then add a description with the run sheet, what to bring, and anything else folks should know. " +
                     "You can add a cover image too if you've got one.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="submit-event-classification"]',
      popover: {
        title: 'Pick the chapter',
        description: "Choose <strong>Chapter</strong> for a local ride and pick your chapter from the dropdown. " +
                     "Pick <strong>National</strong> only if it's an Australia-wide event. " +
                     "Your Area Rep will get the email to review it.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="submit-event-datetime"]',
      popover: {
        title: 'Set the date and time',
        description: "Fill in when it starts and finishes — the timezone defaults to Sydney, change it if you're elsewhere. " +
                     "Tick <strong>All-day event</strong> if there are no set times.",
        side: 'left',
        align: 'start',
      },
    },
    {
      element: '[data-tour="submit-event-location"]',
      popover: {
        title: 'Where are we meeting?',
        description: "Type in the meeting point (where folks kick off) and the destination. " +
                     "Drop in a Google Maps link if you've got one — it's optional but handy.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="submit-event-publish"]',
      popover: {
        title: 'Submit for review',
        description: "When it all looks right, hit <strong>Submit for Review</strong> at the top. " +
                     "It'll land with the admins and your Area Rep — they'll publish it to the calendar once they've had a look. " +
                     "<br><br><strong>Done — thanks for organising!</strong>",
        side: 'bottom',
        align: 'end',
      },
    },
  ]);
})();
