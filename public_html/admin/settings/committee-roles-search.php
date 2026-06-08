<?php
// Reset OPcache so the latest deployed copy of this endpoint is what runs.
// Goldwing's stack caches bytecode aggressively (see public_html/admin/requests/view.php,
// run-migration.php) and stale bytecode is what made search return "lookup_failed"
// for hours after the fix was actually deployed.
if (function_exists('opcache_reset')) { @opcache_reset(); }
// Quick member lookup for the Committee & Leadership Role assignment page.
// Returns the top 8 active members whose name or member_number matches the
// query (minimum 2 chars). JSON shape:
//   { ok: true, results: [ { id, name, member_number, chapter } ] }
//
// Defensive endpoint design:
//   - Always sets Accept-aware JSON expectations early so the bootstrap's
//     login-redirect helper (require_login) returns JSON 401 instead of a
//     302 to /login.php that a browser fetch() would follow into HTML.
//   - Uses distinct positional placeholders so this works on any PHP/PDO
//     config — `:q` reuse depends on emulated prepares or PHP 8.1+ and has
//     bitten us before.
//   - Returns a structured error with detail when the query throws, so
//     "the search is broken" is debuggable by the next admin who looks.

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ChapterRepository;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Force a JSON-shaped Accept so require_permission's downstream branches
// return JSON 401/403 rather than redirecting to /login.php (which fetch()
// would silently follow into HTML and break res.json()).
$_SERVER['HTTP_ACCEPT'] = 'application/json';

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

require_permission('admin.members.view');

$q = trim((string) ($_GET['q'] ?? ''));
$debug = !empty($_GET['debug']);

if (mb_strlen($q) < 2) {
    $payload = ['ok' => true, 'results' => []];
    if ($debug) {
        $payload['debug'] = [
            'reason'   => 'query_too_short',
            'q_raw'    => $_GET['q'] ?? null,
            'q_trim'   => $q,
            'q_length' => mb_strlen($q),
        ];
    }
    echo json_encode($payload);
    exit;
}

try {
    $pdo = db();
    $like = '%' . $q . '%';
    $chapterDisplay = ChapterRepository::displayNameSql($pdo);

    // 4 distinct positional placeholders — never relies on named-parameter
    // reuse, which is sensitive to PDO emulation mode and PHP version.
    $sql = "
        SELECT m.id, m.first_name, m.last_name, m.member_number,
               $chapterDisplay AS chapter_name
        FROM members m
        LEFT JOIN chapters c ON c.id = m.chapter_id
        WHERE m.first_name LIKE ?
           OR m.last_name LIKE ?
           OR m.member_number LIKE ?
           OR CONCAT_WS(' ', m.first_name, m.last_name) LIKE ?
        ORDER BY m.last_name ASC, m.first_name ASC
        LIMIT 8
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Optional debug snapshot — append diagnostic facts so we can see why
    // a search returned empty (DB has 0 members? schema mismatch? wrong DB?).
    // Hit /admin/settings/committee-roles-search.php?q=foo&debug=1 directly.
    $debugInfo = null;
    if ($debug) {
        $totalMembers = (int) ($pdo->query('SELECT COUNT(*) FROM members')->fetchColumn() ?: 0);
        $sample = $pdo->query('SELECT id, first_name, last_name, member_number FROM members ORDER BY id ASC LIMIT 3')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
        $debugInfo = [
            'php_version'   => PHP_VERSION,
            'db_name'       => $dbName,
            'members_total' => $totalMembers,
            'sql'           => trim(preg_replace('/\s+/', ' ', $sql)),
            'bound'         => [$like],
            'rows_returned' => count($rows),
            'sample_first3' => $sample,
        ];
    }
} catch (Throwable $e) {
    error_log('committee-roles-search: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'lookup_failed',
        // Surface the message so this is debuggable from the browser
        // network panel without needing server log access. The endpoint is
        // admin-gated so this leaks nothing the admin can't already see.
        'detail' => $e->getMessage(),
    ]);
    exit;
}

$results = [];
foreach ($rows as $row) {
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $results[] = [
        'id'            => (int) $row['id'],
        'name'          => $name !== '' ? $name : 'Unnamed member',
        'member_number' => (string) ($row['member_number'] ?? ''),
        'chapter'       => (string) ($row['chapter_name'] ?? ''),
    ];
}

$payload = ['ok' => true, 'results' => $results];
if ($debugInfo !== null) {
    $payload['debug'] = $debugInfo;
}
// JSON_INVALID_UTF8_SUBSTITUTE protects against a legacy-imported member
// name with bad encoding silently making json_encode return false (which
// would echo nothing and look like "no matches").
$out = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
if ($out === false) {
    http_response_code(500);
    echo json_encode([
        'ok'     => false,
        'error'  => 'encode_failed',
        'detail' => json_last_error_msg(),
    ]);
    exit;
}
echo $out;
