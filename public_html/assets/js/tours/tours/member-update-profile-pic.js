/* Tour: "How to update your profile photo"
 *
 * 4 steps. Anchors live in public_html/member/index.php (the settings section).
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('member-update-profile-pic', [
    {
      element: '[data-tour="settings-panel"]',
      popover: {
        title: 'Your Account Settings',
        description: "We'll walk through uploading a profile photo. " +
                     "Your photo appears next to your name in the member directory and on chapter notices. " +
                     "Click <strong>Next</strong> when you're ready.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="profile-image-field"]',
      popover: {
        title: 'Profile image',
        description: "This is your current photo. If you haven't uploaded one yet, you'll see a placeholder person icon. " +
                     "Your photo is shown to other members in the directory.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="profile-image-upload-btn"]',
      popover: {
        title: 'Upload a photo',
        description: "Click <strong>Upload image</strong> to open the photo picker. " +
                     "Choose a clear head-and-shoulders photo — a square or portrait image works best. " +
                     "Once selected, a preview will appear in the circle above the button.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="settings-save-btn"]',
      popover: {
        title: 'Save your photo',
        description: "When you're happy with the preview, click <strong>Save settings</strong>. " +
                     "You'll see a green confirmation message at the top of the page. " +
                     "Your photo will now appear in the member directory and next to your notices.<br><br>" +
                     "<strong>That's it — you're done!</strong>",
        side: 'left',
        align: 'start',
      },
    },
  ]);
})();
