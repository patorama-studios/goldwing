<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();
$pdo->exec("ALTER TABLE fallen_wings ADD COLUMN image_url VARCHAR(255) NULL AFTER tribute, ADD COLUMN pdf_url VARCHAR(255) NULL AFTER image_url;");
echo "Migration complete.\n";
