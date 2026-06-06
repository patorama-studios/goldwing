<?php
/**
 * One-shot: stream the contents of /admin/help/docs/images/ as a zip so
 * captures taken on the live server can be pulled down to a dev machine
 * and committed to the repo.
 *
 * Admin-only. DELETE after the images have been committed.
 */

require_once __DIR__ . '/../../../../app/bootstrap.php';
require_role(['admin', 'webmaster']);

$dir = __DIR__ . '/images';
if (!is_dir($dir)) {
    http_response_code(404);
    echo 'images directory does not exist';
    exit;
}

$files = array_merge(
    glob($dir . '/*.png') ?: [],
    glob($dir . '/*.jpg') ?: [],
    glob($dir . '/*.jpeg') ?: [],
    glob($dir . '/*.webp') ?: []
);

if (!$files) {
    http_response_code(404);
    echo 'no image files found in ' . $dir;
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'php-zip extension is not available on this server';
    exit;
}

$tmp = tempnam(sys_get_temp_dir(), 'gwz');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'could not open temp zip';
    exit;
}
foreach ($files as $f) {
    $zip->addFile($f, basename($f));
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="goldwing-docs-images.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
