<?php
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
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

try {
    $pdo = db();
    $like = '%' . $q . '%';
    $chapterDisplay = ChapterRepository::displayNameSql($pdo);

    // 4 distinct positional placeholders — never relies on named-parameter
    // reuse, which is sensitive to PDO emulation mode and PHP version.
    $stmt = $pdo->prepare("
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
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

echo json_encode(['ok' => true, 'results' => $results]);
