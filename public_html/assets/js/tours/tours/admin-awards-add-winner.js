/* Tour: "How to record a trophy winner"
 *
 * 6 steps. Covers the add/edit winner form including member picker, bike details, photos.
 * Anchors live in public_html/admin/awards/edit.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-awards-add-winner', [
    {
      element: '[data-tour="winner-form"]',
      popover: {
        title: 'Recording a Trophy Winner',
        description: "This form lets you record a winner for a single trophy category and year. " +
                     "Fill in the details below and upload a photo of the winning bike. " +
                     "Click <strong>Next</strong> to step through each field.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="winner-member-picker"]',
      popover: {
        title: 'Assign a Member',
        description: "Start typing a name or member number in the search box to find the winner in the member directory. " +
                     "Once selected, their name, avatar, and chapter appear in a green chip. " +
                     "<br><br>If the winner is not in the system (e.g. a guest or historical record), leave this blank and type their name in the <strong>Name Override</strong> field to the right instead.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="winner-bike-fields"]',
      popover: {
        title: 'Bike Details',
        description: "<strong>Bike Description</strong> — enter the year, model, and colour of the winning bike (e.g. <em>1985 GL1200 Aspencade — Burgundy</em>). This appears on the Wall of Awards card. " +
                     "<br><br><strong>Awarded On</strong> — the date the trophy was presented at the AGM. Used for record-keeping.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="winner-photos"]',
      popover: {
        title: 'Upload Photos',
        description: "Click the file picker to choose one or more photos of the winning bike. " +
                     "JPG, PNG, and WEBP are accepted. " +
                     "<br><br>The <strong>first photo you upload</strong> on a new winner automatically becomes the primary — it's the image shown on the Wall of Awards card. " +
                     "You can change which photo is primary after saving.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="winner-save"]',
      popover: {
        title: 'Save the Winner',
        description: "Click <strong>Add winner</strong> (or <strong>Save changes</strong> if editing) to save the record. " +
                     "You'll be returned to the awards dashboard where the trophy row will now show the winner's name and photo.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="winner-photo-gallery"]',
      popover: {
        title: 'Managing Uploaded Photos',
        description: "After saving, uploaded photos appear here as a grid. " +
                     "The photo with a gold <strong>Primary</strong> badge is the one shown on the Wall of Awards. " +
                     "<br><br>Click <strong>Set primary</strong> on any other photo to promote it. " +
                     "Click <strong>Delete</strong> to remove a photo. " +
                     "<br><br><strong>All done — the winner is recorded!</strong>",
        side: 'top',
        align: 'start',
      },
    },
  ]);
})();
