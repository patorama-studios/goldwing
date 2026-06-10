<?php
// Reset OPcache so the latest deployed copy of this endpoint is what runs.
// Goldwing's stack caches bytecode aggressively — stale bytecode caused the
// committee-roles search to return "lookup_failed" for hours after the fix.
if (function_exists('opcache_reset')) { @opcache_reset(); }

// Member typeahead for the AGM awards winner form. Mirrors the shape of
// /admin/settings/committee-roles-search.php so the same client-side
// rendering pattern (avatar + name + member number + chapter) can be used.
//
// JSON response shape:
//   { ok: true, results: [
//     { id, name, member_number, chapter, avatar_url }
//   ] }

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ChapterRepository;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Force a JSON-shaped Accept so require_permission's auth path returns JSON
// 401/403 rather than a 302 to /login.php that fetch() would follow into HTML.
$_SERVER['HTTP_ACCEPT'] = 'application/json';

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

require_permission('admin.awards.view');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

try {
    $pdo = db();
    $like = '%' . $q . '%';
    $chapterDisplay = ChapterRepository::displayNameSql($pdo);

    // 5 distinct positional placeholders — never relies on named-parameter
    // reuse, which is sensitive to PDO emulation mode and PHP version.
    $sql = "
        SELECT m.id, m.first_name, m.last_name,
               CONCAT_WS('.', m.member_number_base, NULLIF(m.member_number_suffix, 0)) AS member_number,
               m.avatar_url,
               $chapterDisplay AS chapter_name
        FROM members m
        LEFT JOIN chapters c ON c.id = m.chapter_id
        WHERE m.first_name LIKE ?
           OR m.last_name LIKE ?
           OR CAST(m.member_number_base AS CHAR) LIKE ?
           OR CONCAT_WS(' ', m.first_name, m.last_name) LIKE ?
           OR m.email LIKE ?
        ORDER BY (m.status = 'ACTIVE') DESC, m.last_name ASC, m.first_name ASC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$like, $like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('awards/search-members: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'     => false,
        'error'  => 'lookup_failed',
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
        'avatar_url'    => (string) ($row['avatar_url'] ?? ''),
    ];
}

$out = json_encode(['ok' => true, 'results' => $results], JSON_INVALID_UTF8_SUBSTITUTE);
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
