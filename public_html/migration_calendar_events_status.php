<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../calendar/lib/db.php';
$pdo = calendar_db();
try {
    $pdo->query("ALTER TABLE calendar_events MODIFY COLUMN status ENUM('published', 'cancelled', 'pending', 'rejected') NOT NULL DEFAULT 'published'");
    echo "Successfully updated calendar_events status column.\n";
} catch (Exception $e) {
    echo "Error updating schema: " . $e->getMessage() . "\n";
}
