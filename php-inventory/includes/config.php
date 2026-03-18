<?php
declare(strict_types=1);

$host = getenv('INVENTORY_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('INVENTORY_DB_USER') ?: 'root';
$dbPass = getenv('INVENTORY_DB_PASS') ?: '';
$dbName = getenv('INVENTORY_DB_NAME') ?: 'inventory_pos_db';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('Inventory POS database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('A database connection error occurred. Please check the configuration and try again.');
}
