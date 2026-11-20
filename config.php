<?php
// XAMPP's usual local MySQL settings. Change these if yours are different.
$dbHost = getenv('ALLO_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('ALLO_DB_PORT') ?: '3306';
$dbName = getenv('ALLO_DB_NAME') ?: 'allo_wallet';
$dbUser = getenv('ALLO_DB_USER') ?: 'root';
$dbPassword = getenv('ALLO_DB_PASSWORD') ?: '';

// PDO is PHP's built-in way to talk to a database.
$pdo = new PDO(
    "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPassword,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]
);
