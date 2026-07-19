<?php
/**
 * Print-ready committee manual — the entire System Documentation compiled
 * into one branded document. Admins open this and use "Save as PDF"
 * (or the browser's Print dialog) to export the manual.
 */
require_once __DIR__ . '/../../../../app/bootstrap.php';

require_role(['admin', 'webmaster']);

define('GW_DOCS_PRINT', true);
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/manual_build.php';

echo gw_build_manual_html([
    'generated' => date('j F Y'),
    'print_button' => true,
]);
