<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../includes/store_helpers.php';

store_require_admin();

header('Location: /admin/settings/index.php?section=store');
exit;
