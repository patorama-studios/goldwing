<?php
require_once __DIR__ . '/../../app/bootstrap.php';

http_response_code(501);
echo 'Google SSO is not configured. Configure OAuth client settings to enable.';
