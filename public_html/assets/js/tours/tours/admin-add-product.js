/* Tour: "How to add a new product to the store"
 *
 * 6 steps. Plain English. Each step does one thing.
 * Anchors live in public_html/admin/store/product_form.php.
 */
(function () {
  if (!window.GoldwingTours) return;
  window.GoldwingTours.register('admin-add-product', [
    {
      element: '[data-tour="admin-add-product-form"]',
      popover: {
        title: 'Adding a new product',
        description: "We'll walk through listing a new item in the members' store. " +
                     "Click <strong>Next</strong> when you're ready.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-add-product-title"]',
      popover: {
        title: 'Name the product',
        description: "Click in the box and type the product name (for example, <em>Goldwing Cap</em>). " +
                     "This is what members will see in the store.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-add-product-price"]',
      popover: {
        title: 'Set the price',
        description: "Type the price in dollars (for example, <em>25.00</em>). " +
                     "Don't include the dollar sign.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-add-product-stock"]',
      popover: {
        title: 'How many do you have?',
        description: "Type the number of items you have on hand. " +
                     "The store will count down as members buy them.",
        side: 'bottom',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-add-product-images"]',
      popover: {
        title: 'Add a photo',
        description: "Click <strong>Choose Files</strong> and pick a photo from your computer. " +
                     "A clear photo helps members see what they're buying.",
        side: 'top',
        align: 'start',
      },
    },
    {
      element: '[data-tour="admin-add-product-save"]',
      popover: {
        title: 'Save the product',
        description: "Click the gold <strong>Save product</strong> button to make it live. " +
                     "<strong>Members can now buy this product.</strong>",
        side: 'left',
        align: 'start',
      },
    },
  ]);
})();
