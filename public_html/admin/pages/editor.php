<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

require_permission('admin.ai_page_builder.access');

header('Location: /admin/page-builder', true, 302);
exit;
