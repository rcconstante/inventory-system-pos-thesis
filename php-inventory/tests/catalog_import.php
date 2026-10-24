<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/database_upgrades.php';
$dsn = getenv('INVENTORY_TEST_DB_DSN');
if (!$dsn) throw new RuntimeException('Set INVENTORY_TEST_DB_DSN for a disposable MySQL server.');
$pdo = new PDO($dsn, getenv('INVENTORY_TEST_DB_USER') ?: 'root', getenv('INVENTORY_TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'inventory_import_test_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
$checks = 0;
function verify_import(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
try {
    $pdo->exec('USE `' . $database . '`');
    preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s', file_get_contents(__DIR__ . '/../database.sql'), $tables);
    foreach ($tables[0] as $table) $pdo->exec($table);
    $pdo->exec("INSERT INTO Role (role_id, role_type) VALUES (1, 'admin')");
    $pdo->exec("INSERT INTO User (user_id, full_name, username, password, role_id) VALUES (1, 'Preserved Admin', 'admin', 'test', 1)");
    replace_final_catalog_once($pdo);
    verify_import((int) $pdo->query('SELECT COUNT(*) FROM Products')->fetchColumn() === 220, 'All source products must load.');
    verify_import((int) $pdo->query('SELECT COUNT(*) FROM Stock_Batch')->fetchColumn() === 425, 'All source batches must load.');
    verify_import((int) $pdo->query('SELECT SUM(quantity_remaining) FROM Stock_Batch')->fetchColumn() === 1506, 'Batch units must reconcile.');
    verify_import((int) $pdo->query('SELECT SUM(current_stock) FROM Inventory')->fetchColumn() === 1506, 'Inventory must derive from batches.');
    verify_import((int) $pdo->query('SELECT COUNT(*) FROM Inventory WHERE current_stock = 0')->fetchColumn() === 17, 'Out-of-stock products must stay out of stock.');
    verify_import((int) $pdo->query('SELECT COUNT(*) FROM Stock_Batch WHERE product_id = 1')->fetchColumn() === 0, 'No invented batches for zero stock.');
    verify_import($pdo->query('SELECT manufacturing_date FROM Stock_Batch WHERE product_id = 2 ORDER BY batch_id DESC LIMIT 1')->fetchColumn() === '2026-04-14', 'Batch dates must use DD/MM/YYYY.');
    verify_import((float) $pdo->query('SELECT price FROM Products WHERE product_id = 2')->fetchColumn() === 305.0, 'Source selling prices must remain intact.');
    verify_import((int) $pdo->query("SELECT COUNT(*) FROM Products WHERE product_name = 'Shell Advance 4T'")->fetchColumn() === 5, 'Same-name specification variants must remain separate products.');
    verify_import($pdo->query('SELECT full_name FROM User WHERE user_id = 1')->fetchColumn() === 'Preserved Admin', 'Import must preserve accounts.');
    verify_import((int) $pdo->query('SELECT COUNT(*) FROM Product_Vehicle WHERE product_id = 1')->fetchColumn() === 9, 'Compatibility assignments must be imported.');
    $alternatives = fetch_recommendations_for_products($pdo, [1]);
    verify_import(!empty($alternatives[1]), 'Imported out-of-stock oil must have valid alternatives.');
    foreach ($alternatives[1] as $alternative) {
        $value = json_decode($pdo->query('SELECT essential_fitment FROM Products WHERE product_id = ' . (int) $alternative['alternative_id'])->fetchColumn(), true);
        verify_import($value['viscosity'] === '10W-40', 'Oil recommendations must match essential viscosity.');
    }
    verify_import(!replace_final_catalog_once($pdo), 'Completed update must not run again.');
    verify_import((int) $pdo->query('SELECT COUNT(*) FROM Stock_Batch')->fetchColumn() === 425, 'Rejected repeat must not duplicate batches.');
    $pdo->exec("INSERT INTO Sale (sale_id, user_id, total_amount, payment_method) VALUES (1, 1, 1830, 'CASH')");
    $pdo->exec('INSERT INTO Sale_Item (sale_item_id, sale_id, product_id, quantity, selling_price, subtotal) VALUES (1, 1, 2, 6, 305, 1830)');
    $pdo->beginTransaction();
    deduct_stock_fifo($pdo, 2, 6, 1);
    $pdo->commit();
    verify_import(get_sellable_stock($pdo, 2) === 3, 'Checkout must consume imported batches.');
    verify_import((int) $pdo->query('SELECT COUNT(*) FROM Sale_Item_Batch WHERE sale_item_id = 1')->fetchColumn() === 2, 'FIFO must span imported batches.');
    echo "Passed {$checks} final catalog import checks.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
