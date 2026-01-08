<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

require_role(['admin']);

header('Location: /admin/navigation.php', true, 302);
exit;
