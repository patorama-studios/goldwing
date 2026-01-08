<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\AuthService;

AuthService::logout();
header('Location: /');
exit;
