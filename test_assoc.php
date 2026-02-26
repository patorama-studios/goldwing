<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();
$memberId = 15;
$searchTerm = 'test';
$term = '%' . mb_strtolower($searchTerm) . '%';
$numberTerm = '%' . ($searchTerm === '' ? '' : str_replace(' ', '', $searchTerm)) . '%';

try {
    $sql = 'SELECT id FROM members WHERE member_type = "ASSOCIATE" AND (full_member_id IS NULL OR full_member_id = 0 OR full_member_id <> :member_id) AND (LOWER(CONCAT(first_name, " ", last_name)) LIKE :term OR LOWER(email) LIKE :term_email OR COALESCE(CONCAT(member_number_base, CASE WHEN member_number_suffix > 0 THEN CONCAT(".", member_number_suffix) ELSE "" END), "") LIKE :number) LIMIT 12';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'member_id' => $memberId,
        'term' => $term,
        'term_email' => $term,
        'number' => $numberTerm,
    ]);
    echo "Success\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
