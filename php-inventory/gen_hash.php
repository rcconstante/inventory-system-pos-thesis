<?php
require_once 'includes/config.php';

// Add missing columns if they don't exist yet
$pdo->exec("ALTER TABLE User ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1");
$pdo->exec("ALTER TABLE User ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
$pdo->exec("ALTER TABLE Category ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1");

$password = password_hash('staff123', PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    "INSERT INTO User (user_id, full_name, username, password, email, role_id, is_active)
     VALUES (3, 'Inventory Staff', 'staff', ?, 'staff@example.com', 3, 1)
     ON DUPLICATE KEY UPDATE password = VALUES(password), is_active = VALUES(is_active)"
);
$stmt->execute([$password]);

echo "<strong>Done.</strong><br>";
echo "Columns added (if missing) and staff user inserted/updated.<br>";
echo "Username: <strong>staff</strong><br>";
echo "Password: <strong>staff123</strong><br>";
echo "<br><em>Delete this file (gen_hash.php) when done.</em>";
