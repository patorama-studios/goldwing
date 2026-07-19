<?php
/**
 * CLI build of the committee manual (same output as admin/help/docs/manual.php,
 * with image/logo paths resolved to local files so a headless browser can
 * print it to PDF without a running site).
 *
 *   php scripts/build_manual.php [output.html]
 *
 * Then print to PDF, e.g.:
 *   chrome --headless --print-to-pdf=manual.pdf --no-pdf-header-footer output.html
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

$root = dirname(__DIR__);
$docs = $root . '/public_html/admin/help/docs';

define('GW_DOCS_PRINT', true);
define('GW_DOCS_IMG_BASE', 'file://' . $docs . '/images/');
require_once $docs . '/markdown.php';
require_once $docs . '/manual_build.php';

$out = $argv[1] ?? ($root . '/manual.html');
$logoFs = $root . '/public_html/uploads/library/2024/good-logo-cropped-white-notag.png';

$html = gw_build_manual_html([
    'generated' => date('j F Y'),
    'logo' => is_file($logoFs) ? 'file://' . $logoFs : '',
]);

file_put_contents($out, $html);
echo 'Wrote ' . $out . ' (' . number_format(strlen($html)) . " bytes)\n";
