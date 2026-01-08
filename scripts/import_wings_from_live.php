<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\SettingsService;

const SOURCE_URL = 'https://goldwing.patorama.com.au/wings/';

function usage(): void
{
    echo "Usage: php scripts/import_wings_from_live.php [--apply] [--set-latest]\n";
    echo "Fetches Wings PDFs/covers from " . SOURCE_URL . " and imports into uploads + wings_issues.\n";
    echo "Dry-run by default. Use --apply to download/write DB.\n";
}

function fetch_html(string $url): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to init curl.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'GoldwingWingsImporter/1.0',
    ]);
    $html = curl_exec($ch);
    if ($html === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Failed to fetch {$url}: {$err}");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Failed to fetch {$url}: HTTP {$status}");
    }
    return (string) $html;
}

function resolve_url(string $base, string $url): ?string
{
    $url = trim(html_entity_decode($url));
    if ($url === '') {
        return null;
    }
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }
    if (str_starts_with($url, '/')) {
        return rtrim($base, '/') . $url;
    }
    return rtrim($base, '/') . '/' . ltrim($url, '/');
}

function extract_pairs(string $html, string $baseUrl): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $links = $xpath->query("//a[contains(@href, '.pdf')]");
    $pairs = [];

    if ($links === false) {
        return $pairs;
    }

    foreach ($links as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }
        $href = resolve_url($baseUrl, $link->getAttribute('href'));
        if (!$href || !filter_var($href, FILTER_VALIDATE_URL)) {
            continue;
        }
        if (!str_ends_with(strtolower(parse_url($href, PHP_URL_PATH) ?? ''), '.pdf')) {
            continue;
        }
        $img = $link->getElementsByTagName('img')->item(0);
        $cover = null;
        if ($img instanceof DOMElement) {
            $cover = resolve_url($baseUrl, $img->getAttribute('src'));
        }
        $pairs[$href] = [
            'pdf' => $href,
            'cover' => $cover,
        ];
    }

    return array_values($pairs);
}

function parse_issue_meta(string $pdfUrl): ?array
{
    $path = parse_url($pdfUrl, PHP_URL_PATH) ?? '';
    $basename = pathinfo($path, PATHINFO_FILENAME);
    $name = str_replace(['_', '.'], '-', $basename);
    $nameLower = strtolower($name);

    $year = null;
    if (preg_match('/(19|20)\\d{2}/', $nameLower, $match)) {
        $year = (int) $match[0];
    }

    $monthMap = [
        'january' => 1, 'jan' => 1,
        'february' => 2, 'feb' => 2,
        'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'may' => 5,
        'june' => 6, 'jun' => 6,
        'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9,
        'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
    ];

    $monthNum = null;
    foreach ($monthMap as $namePart => $num) {
        if (preg_match('/' . preg_quote($namePart, '/') . '/', $nameLower)) {
            $monthNum = $num;
            break;
        }
    }

    if ($monthNum === null && preg_match('/^(\\d{1,2})[-_]/', $nameLower, $match)) {
        $candidate = (int) $match[1];
        if ($candidate >= 1 && $candidate <= 12) {
            $monthNum = $candidate;
        }
    }

    if (!$year || !$monthNum) {
        return null;
    }

    $monthName = date('F', mktime(0, 0, 0, $monthNum, 1));
    $title = "Wings {$monthName} {$year}";

    return [
        'year' => $year,
        'month' => $monthNum,
        'month_name' => $monthName,
        'title' => $title,
        'published_at' => sprintf('%04d-%02d-01', $year, $monthNum),
    ];
}

function sanitize_filename(string $name): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    return $safe === '' ? 'file' : $safe;
}

function download_file(string $url, string $dest): bool
{
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen($dest, 'wb');
    if ($fp === false) {
        return false;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fp);
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'GoldwingWingsImporter/1.0',
    ]);
    $ok = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return $ok !== false && $status >= 200 && $status < 300;
}

function ensure_media(PDO $pdo, string $type, string $title, string $path, ?string $tags, string $visibility): void
{
    $stmt = $pdo->prepare('SELECT id FROM media WHERE path = :path LIMIT 1');
    $stmt->execute(['path' => $path]);
    if ($stmt->fetch()) {
        return;
    }
    $insert = $pdo->prepare('INSERT INTO media (type, title, path, tags, visibility, uploaded_by, created_at) VALUES (:type, :title, :path, :tags, :visibility, :uploaded_by, NOW())');
    $insert->execute([
        'type' => $type,
        'title' => $title,
        'path' => $path,
        'tags' => $tags,
        'visibility' => $visibility,
        'uploaded_by' => null,
    ]);
}

$apply = in_array('--apply', $argv, true);
$setLatest = in_array('--set-latest', $argv, true);
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    usage();
    exit(0);
}

$pdo = db();
$baseUrl = 'https://goldwing.patorama.com.au';
$uploadsRoot = __DIR__ . '/../public_html/uploads';
$wingsRoot = $uploadsRoot . '/wings';
$defaultVisibility = (string) SettingsService::getGlobal('media.privacy_default', 'member');

echo "Fetching " . SOURCE_URL . "...\n";
$html = fetch_html(SOURCE_URL);
$pairs = extract_pairs($html, $baseUrl);
echo "Found " . count($pairs) . " PDF links.\n";

$existingStmt = $pdo->query('SELECT pdf_url FROM wings_issues');
$existing = [];
foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $url) {
    $existing[(string) $url] = true;
}

$stats = [
    'skipped_existing' => 0,
    'skipped_missing_meta' => 0,
    'downloaded' => 0,
    'inserted' => 0,
    'errors' => 0,
];

$latestDate = null;
$latestPdfUrl = null;

foreach ($pairs as $pair) {
    $pdfUrl = $pair['pdf'];
    $coverUrl = $pair['cover'];
    if ($coverUrl && !filter_var($coverUrl, FILTER_VALIDATE_URL)) {
        $coverUrl = null;
    }

    if (isset($existing[$pdfUrl])) {
        $stats['skipped_existing']++;
        continue;
    }

    $meta = parse_issue_meta($pdfUrl);
    if ($meta === null) {
        $stats['skipped_missing_meta']++;
        echo "Skipping (missing date): {$pdfUrl}\n";
        continue;
    }

    $year = $meta['year'];
    $month = sprintf('%02d', $meta['month']);
    $monthDir = "{$wingsRoot}/{$year}/{$month}";
    $pdfName = sanitize_filename(basename(parse_url($pdfUrl, PHP_URL_PATH) ?? 'wings.pdf'));
    $pdfDest = "{$monthDir}/{$pdfName}";
    $pdfRel = "/uploads/wings/{$year}/{$month}/{$pdfName}";

    $coverRel = null;
    $coverDest = null;
    $coverName = null;
    if ($coverUrl) {
        $coverName = sanitize_filename(basename(parse_url($coverUrl, PHP_URL_PATH) ?? 'cover.jpg'));
        $coverDest = "{$monthDir}/{$coverName}";
        $coverRel = "/uploads/wings/{$year}/{$month}/{$coverName}";
    }

    if ($apply) {
        if (!is_file($pdfDest)) {
            if (!download_file($pdfUrl, $pdfDest)) {
                $stats['errors']++;
                echo "Failed to download PDF: {$pdfUrl}\n";
                continue;
            }
            $stats['downloaded']++;
        }
        if ($coverUrl && $coverDest && !is_file($coverDest)) {
            if (!download_file($coverUrl, $coverDest)) {
                $stats['errors']++;
                echo "Failed to download cover: {$coverUrl}\n";
                continue;
            }
            $stats['downloaded']++;
        }
    }

    if ($apply) {
        $stmt = $pdo->prepare('INSERT INTO wings_issues (title, pdf_url, cover_image_url, is_latest, published_at, created_by, created_at) VALUES (:title, :pdf_url, :cover_url, :is_latest, :published_at, :created_by, NOW())');
        $stmt->execute([
            'title' => $meta['title'],
            'pdf_url' => $pdfRel,
            'cover_url' => $coverRel,
            'is_latest' => 0,
            'published_at' => $meta['published_at'],
            'created_by' => null,
        ]);
        ensure_media($pdo, 'file', $meta['title'] . ' PDF', $pdfRel, 'wings,magazine', $defaultVisibility);
        if ($coverRel) {
            ensure_media($pdo, 'image', $meta['title'] . ' Cover', $coverRel, 'wings,magazine', $defaultVisibility);
        }
        $stats['inserted']++;
    }

    if ($latestDate === null || $meta['published_at'] > $latestDate) {
        $latestDate = $meta['published_at'];
        $latestPdfUrl = $pdfRel;
    }
}

if ($apply && $setLatest && $latestPdfUrl) {
    $pdo->query('UPDATE wings_issues SET is_latest = 0');
    $stmt = $pdo->prepare('UPDATE wings_issues SET is_latest = 1 WHERE pdf_url = :pdf_url');
    $stmt->execute(['pdf_url' => $latestPdfUrl]);
}

echo "Done.\n";
foreach ($stats as $key => $value) {
    echo "{$key}: {$value}\n";
}
if (!$apply) {
    echo "Dry-run only. Re-run with --apply to download and import.\n";
}
