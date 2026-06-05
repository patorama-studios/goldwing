/* Tour: "How to turn on extra login security"
 *
 * 5 steps. Very plain English — no jargon.
 * Anchors live in public_html/member/2fa_enroll.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-2fa', [
    {
      element: '[data-tour="twofa-title"]',
      popover: {
        title: 'Extra login security',
        description: "This adds a second step when you log in — a short code from an app on your phone. " +
                     "It keeps your account safe even if someone learns your password.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="twofa-qr"]',
      popover: {
        title: 'First, grab an app on your phone',
        description: "Open an app like <strong>Google Authenticator</strong> (free from the App Store or Play Store), " +
                     "tap <strong>Add</strong>, then point your phone's camera at this square picture.",
        side: 'right',
        align: 'start',
      },
    },
    {
      element: '[data-tour="twofa-secret"]',
      popover: {
        title: "Can't scan? Type this instead",
        description: "If the camera won't read the picture, just type these letters and numbers into the app. " +
                     "Either way works the same.",
        side: 'right',
        align: 'start',
      },
    },
    {
      element: '[data-tour="twofa-code"]',
      popover: {
        title: 'Type the 6-digit code',
        description: "Your phone app will now show a 6-digit code that changes every minute. " +
                     "Type that code into this box.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="twofa-submit"]',
      popover: {
        title: 'Turn it on',
        description: "Click <strong>Enable 2FA</strong> and you're done. " +
                     "Next time you log in, you'll type your password and then a fresh code from your phone.<br><br>" +
                     "<strong>You did it — your account is now extra safe!</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
