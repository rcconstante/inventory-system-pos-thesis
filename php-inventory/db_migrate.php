<?php
require_once 'includes/config.php';

try {
    $pdo->exec("ALTER TABLE User ADD COLUMN IF NOT EXISTS display_name VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE Sale ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) DEFAULT NULL");
    echo "SUCCESS: Added display_name and cancel_reason columns.";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}

try {
    $pdo->exec("ALTER TABLE Inventory ADD COLUMN IF NOT EXISTS expiry_date DATE DEFAULT NULL");
    echo "\nSUCCESS: Added expiry_date column.";
} catch (PDOException $e) {
    echo "\nERROR: " . $e->getMessage();
}
