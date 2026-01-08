<?php
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: 3306;
$database = getenv('DB_NAME') ?: 'patoramacom_wings2f';
$username = getenv('DB_USER') ?: 'patoramacom_winggy3';
$password = getenv('DB_PASS') ?: 'M)^bP{G2&sRb';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

return [
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'charset' => $charset,
];
