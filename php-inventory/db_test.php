<?php
$host = '127.0.0.1'; // Force TCP/IP
$dbUser = 'root';
$dbPass = '';
$dbName = 'inventory_pos_db';

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
    echo "SUCCESS: Connected to database.";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
