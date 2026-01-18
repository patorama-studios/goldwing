<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

require_role(['admin']);

header('Location: /admin/page-builder', true, 302);
exit;
