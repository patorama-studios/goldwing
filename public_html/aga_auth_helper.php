<?php
// Temporary auth helper for screenshot capture — sets up a logged-in admin session
require_once __DIR__ . '/../app/bootstrap.php';

// Bootstrap already started the session via DbSessionHandler — do NOT call session_start() again

// Look up first admin user from the database
$pdo = db();
$stmt = $pdo->prepare("
    SELECT u.id, u.email, u.name, m.member_number as member_id
    FROM users u
    LEFT JOIN members m ON m.user_id = u.id
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE r.name = 'admin'
    ORDER BY u.id ASC
    LIMIT 1
");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Fallback: get any user with id=1
    $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE id = 1 LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $user['member_id'] = 'AGA001';
    $user['roles'] = ['admin'];
} else {
    $user['roles'] = ['admin'];
}

// Set session exactly as AuthService::completeLogin does
$_SESSION['user'] = [
    'id'        => (int) $user['id'],
    'email'     => $user['email'],
    'name'      => $user['name'],
    'member_id' => $user['member_id'] ?? '',
    'roles'     => $user['roles'],
];

header('Content-Type: application/json');
echo json_encode([
    'ok'         => true,
    'session_id' => session_id(),
    'user_id'    => $user['id'],
    'email'      => $user['email'],
]);
