<?php
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: 3306;
$database = getenv('DB_NAME') ?: 'goldwing_wingggy3';
$username = getenv('DB_USER') ?: 'goldwing_web2398';
$password = getenv('DB_PASS') ?: '}.eb{(R${Q?#';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

return [
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'charset' => $charset,
];
