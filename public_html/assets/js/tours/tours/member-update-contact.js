/* Tour: "How to update your phone or email"
 *
 * 4 steps. Plain English. Each step does one thing.
 * Anchors live in public_html/member/index.php (the profile section).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-update-contact', [
    {
      element: '[data-tour="profile-form"]',
      popover: {
        title: 'Updating your details',
        description: "We'll walk through changing your email or phone number. " +
                     "Click <strong>Next</strong> when you're ready.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="profile-email"]',
      popover: {
        title: 'Your email address',
        description: "This is where the club sends your magazine and notices. " +
                     "Click in the box and type your new email if you'd like to change it.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="profile-phone"]',
      popover: {
        title: 'Your phone number',
        description: "Click in the box and type your new phone number. " +
                     "Don't worry about spaces — type it any way you like.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="profile-save"]',
      popover: {
        title: 'Save your changes',
        description: "When you're happy, click the gold <strong>Save changes</strong> button. " +
                     "You'll see a green message at the top to confirm it worked.<br><br>" +
                     "<strong>That's it — you did it!</strong>",
        side: 'left',
        align: 'start',
      },
    },
  ]);
})();
