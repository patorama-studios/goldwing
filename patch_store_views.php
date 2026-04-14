<?php
$files = [
    'categories.php',
    'discounts.php',
    'low-stock.php',
    'order_view.php',
    'orders.php',
    'product_form.php',
    'products.php',
    'settings.php',
    'tags.php'
];

$dir = __DIR__ . '/public_html/admin/store/';

foreach ($files as $file) {
    $path = $dir . $file;
    $content = file_get_contents($path);
    if (strpos($content, 'IN_STORE_ADMIN') === false) {
        $content = preg_replace('/<\?php/', "<?php\nif (!defined('IN_STORE_ADMIN')) exit('No direct access allowed');", $content, 1);
        file_put_contents($path, $content);
        echo "Patched $file\n";
    }
}
