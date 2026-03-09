<?php
require_once __DIR__ . '/admin/shared/config.php';
try {
    $pdo->exec('ALTER TABLE wings_issues ADD COLUMN downloads INT DEFAULT 0');
    echo "Column 'downloads' added successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column 'downloads' already exists.\n";
    } else {
        echo "Error adding 'downloads': " . $e->getMessage() . "\n";
    }
}
?>