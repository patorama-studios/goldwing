<?php
declare(strict_types=1);

/**
 * Imports the AGA store catalogue from a JSON spec.
 *
 * Usage:
 *   php scripts/import_store_catalogue.php [--apply] [--update-shipping] [path/to/catalogue.json]
 *
 *   - Dry-run by default. Pass --apply to write to the database.
 *   - --update-shipping also writes the catalogue's shipping.flat_rate into store_settings.
 *   - If no JSON path is given, defaults to scripts/data/store_catalogue_2026_04.json.
 *
 * For the admin web wrapper, see public_html/admin/store/import.php.
 * Shared logic lives in includes/store_catalogue_import.php.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../includes/store_catalogue_import.php';

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$updateShipping = in_array('--update-shipping', $args, true);
$jsonPath = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    $jsonPath = $arg;
    break;
}
if ($jsonPath === null) {
    $jsonPath = catalogue_default_paths()['default'];
}

try {
    $result = catalogue_import_run(db(), $jsonPath, $apply, $updateShipping);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($result['log'] as $line) {
    echo $line . "\n";
}
echo "\nSummary ({$result['mode']}):\n";
foreach ($result['stats'] as $key => $value) {
    echo "  {$key}: {$value}\n";
}
if (!$apply) {
    echo "\nDry-run only. Re-run with --apply to write changes.\n";
}
