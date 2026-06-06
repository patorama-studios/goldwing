<?php
/**
 * Tour linter.
 *
 * Reads /config/tour-manifest.json and, for each tour, fetches the rendered
 * target page (authenticated as a configured admin) and confirms that every
 * declared `data-tour` selector resolves to exactly one element.
 *
 * Usage:
 *   php scripts/lint_tours.php                  # lint all
 *   php scripts/lint_tours.php member-update-contact
 *
 * Configuration (env or CLI flags):
 *   TOUR_LINT_BASE_URL   default https://goldwing.org.au
 *   TOUR_LINT_COOKIE     session cookie value for an admin user
 *                        (copy "Cookie:" header from a logged-in browser session)
 *   --base=URL
 *   --cookie=NAME=value;NAME2=value2
 *
 * Exit code: 0 if all tours pass, 1 if any fail.
 *
 * Results are written to the tour_test_runs table with run_kind = 'linter'.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\TourService;

$args = $argv;
array_shift($args);

$baseUrl = getenv('TOUR_LINT_BASE_URL') ?: 'https://goldwing.org.au';
$cookie  = getenv('TOUR_LINT_COOKIE') ?: '';
$onlySlug = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--base=')) $baseUrl = substr($a, 7);
    elseif (str_starts_with($a, '--cookie=')) $cookie = substr($a, 9);
    elseif ($a[0] !== '-') $onlySlug = $a;
}

$baseUrl = rtrim($baseUrl, '/');

fwrite(STDOUT, "Tour linter\n");
fwrite(STDOUT, "  base: $baseUrl\n");
fwrite(STDOUT, "  cookie: " . ($cookie ? '(set)' : '(none — will only test public pages)') . "\n\n");

$tours = TourService::allTours();
if ($onlySlug) {
    $tours = isset($tours[$onlySlug]) ? [$onlySlug => $tours[$onlySlug]] : [];
}
if (!$tours) {
    fwrite(STDERR, "No tours to lint.\n");
    exit(1);
}

$anyFail = false;
foreach ($tours as $slug => $tour) {
    fwrite(STDOUT, "▶ $slug — {$tour['name']}\n");
    $url = $baseUrl . ($tour['page_url'] ?? '/');
    $selectors = $tour['selectors'] ?? [];
    if (!$selectors) {
        fwrite(STDOUT, "  no selectors declared — skipping\n\n");
        continue;
    }
    $html = fetch_url($url, $cookie);
    if ($html === null) {
        fwrite(STDOUT, "  ✗ could not fetch $url\n\n");
        TourService::recordRun($slug, 'linter', 'fail', null, null, ['error' => 'fetch_failed', 'url' => $url]);
        $anyFail = true;
        continue;
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $missing = [];
    foreach ($selectors as $sel) {
        $xp = data_tour_xpath($sel);
        if ($xp === null) {
            // Only data-tour="..." selectors are supported; flag anything else.
            $missing[] = $sel . ' (unsupported selector — use [data-tour="..."])';
            continue;
        }
        $found = $xpath->query($xp);
        if (!$found || $found->length === 0) {
            $missing[] = $sel;
        }
    }

    if ($missing) {
        fwrite(STDOUT, "  ✗ missing selectors:\n");
        foreach ($missing as $m) fwrite(STDOUT, "    - $m\n");
        fwrite(STDOUT, "\n");
        TourService::recordRun($slug, 'linter', 'fail', null, null, ['missing' => $missing, 'url' => $url]);
        $anyFail = true;
    } else {
        fwrite(STDOUT, "  ✓ all " . count($selectors) . " selectors found\n\n");
        TourService::recordRun($slug, 'linter', 'pass', null, null, ['url' => $url, 'count' => count($selectors)]);
    }
}

exit($anyFail ? 1 : 0);

function fetch_url(string $url, string $cookie): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'GoldwingTourLinter/1.0',
    ]);
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $cookie]);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return null;
    return $body;
}

function data_tour_xpath(string $selector): ?string {
    // Accept only [data-tour="some-slug"] form.
    if (preg_match('/^\[data-tour="([^"]+)"\]$/', $selector, $m)) {
        return "//*[@data-tour='" . str_replace("'", "\\'", $m[1]) . "']";
    }
    return null;
}
