<?php
// Quick member lookup for the Committee & Leadership Role assignment page.
// Returns the top 8 active members whose name or member_number matches the
// query (minimum 2 chars). JSON shape:
//   { ok: true, results: [ { id, name, member_number, chapter, avatar } ] }

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ChapterRepository;

header('Content-Type: application/json');

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
    $stmt = $pdo->prepare("
        SELECT m.id, m.first_name, m.last_name, m.member_number,
               $chapterDisplay AS chapter_name
        FROM members m
        LEFT JOIN chapters c ON c.id = m.chapter_id
        WHERE (
            m.first_name LIKE :q
            OR m.last_name LIKE :q
            OR m.member_number LIKE :q
            OR CONCAT_WS(' ', m.first_name, m.last_name) LIKE :q
          )
        ORDER BY m.last_name ASC, m.first_name ASC
        LIMIT 8
    ");
    $stmt->execute([':q' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'lookup_failed']);
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
