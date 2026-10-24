<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/database_upgrades.php';
$dsn = getenv('INVENTORY_TEST_DB_DSN');
if (!$dsn) throw new RuntimeException('Set INVENTORY_TEST_DB_DSN to a disposable MySQL server.');
$user = getenv('INVENTORY_TEST_DB_USER') ?: 'root';
$password = getenv('INVENTORY_TEST_DB_PASS') ?: '';
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$other = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'inventory_catalog_update_test_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
$checks = 0;
function verify_catalog_update(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
try {
    $pdo->exec('USE `' . $database . '`');
    preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s', file_get_contents(__DIR__ . '/../database.sql'), $tables);
    foreach ($tables[0] as $table) $pdo->exec($table);
    ensure_product_lifecycle_schema($pdo);
    $pdo->exec("INSERT INTO Role (role_id, role_type) VALUES (1, 'admin')");
    $pdo->exec("INSERT INTO User (user_id, full_name, username, password, role_id) VALUES (1, 'Original Admin', 'admin', 'existing-password-hash', 1)");
    $pdo->exec("INSERT INTO Category (category_id, category_name) VALUES (1, 'Tires')");
    $pdo->exec("INSERT INTO Products (product_id, product_name, price, category_id, product_type, specification, compatibility) VALUES (1, 'Legacy Tire', 100, 1, 'Tire', '80/90-14', 'Honda Click 125'), (800, 'Archived legacy tire', 110, 1, 'Tire', '80/90-14', 'Honda Click 125')");
    $pdo->exec("UPDATE Products SET product_status = 'ARCHIVED', purge_after = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE product_id = 800");
    $pdo->exec('INSERT INTO Inventory (product_id, current_stock) VALUES (1, 5), (800, 3)');
    $pdo->exec("INSERT INTO Stock_Batch (batch_id, product_id, batch_number, quantity_received, quantity_remaining) VALUES (1, 1, 'OLD-1', 6, 5), (2, 800, 'OLD-2', 3, 3)");
    $pdo->exec("INSERT INTO Sale (sale_id, user_id, total_amount, payment_method, reference_number) VALUES (1, 1, 100, 'GCASH', '1234567890123')");
    $pdo->exec('INSERT INTO Sale_Item (sale_item_id, sale_id, product_id, quantity, selling_price, subtotal) VALUES (1, 1, 1, 1, 100, 100)');
    $pdo->exec('INSERT INTO Sale_Item_Batch (sale_item_id, batch_id, quantity_from_batch) VALUES (1, 1, 1)');
    $pdo->exec("INSERT INTO Category (category_id, category_name) VALUES (99, 'Unused legacy category')");
    $pdo->exec("INSERT INTO Schema_Migration (migration_id) VALUES ('2026_10_final_catalog_220_v1')");
    run_inventory_database_upgrades($pdo);
    verify_catalog_update(!final_catalog_is_current($pdo), 'Schema completion alone must not skip catalog replacement.');
    $lockName = inventory_upgrade_lock_name($pdo);
    $other->prepare('SELECT GET_LOCK(?, 0)')->execute([$lockName]);
    try { replace_final_catalog_once($pdo); verify_catalog_update(false, 'Concurrent replacement must wait.'); }
    catch (InventoryDatabaseUpgradeBusy) { verify_catalog_update(true, 'Concurrent update safely rejected.'); }
    $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    $pdo->exec("CREATE TRIGGER reject_catalog_import BEFORE INSERT ON Products FOR EACH ROW BEGIN IF NEW.product_name = 'Castrol Power1 4T' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated interrupted catalog import'; END IF; END");
    try { replace_final_catalog_once($pdo); verify_catalog_update(false, 'Failed import must roll back.'); }
    catch (PDOException) { verify_catalog_update(true, 'Import failure surfaced.'); }
    verify_catalog_update(!final_catalog_is_current($pdo), 'Failed import must leave completion pending.');
    verify_catalog_update((int) $pdo->query('SELECT COUNT(*) FROM Products')->fetchColumn() === 2, 'Partial product inserts must roll back.');
    verify_catalog_update($pdo->query('SELECT product_status FROM Products WHERE product_id = 1')->fetchColumn() === 'ACTIVE', 'Old catalog must survive an interrupted import.');
    verify_catalog_update((int) $pdo->query('SELECT SUM(current_stock) FROM Inventory')->fetchColumn() === 8, 'Interrupted import must preserve stock.');
    verify_catalog_update($pdo->query('SELECT status FROM Stock_Batch WHERE batch_id = 1')->fetchColumn() === 'ACTIVE', 'Interrupted import must preserve batches.');
    $pdo->exec('DROP TRIGGER reject_catalog_import');
    verify_catalog_update(replace_final_catalog_once($pdo), 'Retry must apply the replacement.');
    verify_catalog_update(final_catalog_is_current($pdo), 'Successful replacement must record completion.');
    verify_catalog_update((int) $pdo->query("SELECT COUNT(*) FROM Products WHERE product_status = 'ACTIVE'")->fetchColumn() === 220, 'Exactly the new list must be active.');
    verify_catalog_update((int) $pdo->query('SELECT COUNT(*) FROM Products')->fetchColumn() === 220 && (int) $pdo->query('SELECT COUNT(*) FROM Products WHERE product_id = 800')->fetchColumn() === 0, 'The old catalog must be physically deleted.');
    verify_catalog_update((int) $pdo->query("SELECT COUNT(*) FROM Stock_Batch WHERE status = 'ACTIVE'")->fetchColumn() === 425, 'Only new batches must remain sellable.');
    verify_catalog_update(get_sellable_stock($pdo, 1) === 0, 'The first source product must remain out of stock.');
    verify_catalog_update((int) $pdo->query('SELECT SUM(current_stock) FROM Inventory')->fetchColumn() === 1506, 'New stock must reconcile.');
    verify_catalog_update((int) $pdo->query('SELECT COUNT(*) FROM Sale')->fetchColumn() === 0, 'Previous transactions must be reset.');
    verify_catalog_update((int) $pdo->query('SELECT COUNT(*) FROM Sale_Item')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM Sale_Item_Batch')->fetchColumn() === 0, 'Previous sale items and batch links must be removed.');
    verify_catalog_update((int) $pdo->query("SELECT COUNT(*) FROM Category WHERE category_name = 'Unused legacy category'")->fetchColumn() === 0, 'Old unused categories must be deleted as well.');
    $expectedCategories = array_values(array_unique(array_column(final_catalog_payload()['products'], 'category')));
    sort($expectedCategories);
    verify_catalog_update($pdo->query('SELECT category_name FROM Category ORDER BY category_name')->fetchAll(PDO::FETCH_COLUMN) === $expectedCategories, 'Only categories from the Excel list must remain.');
    verify_catalog_update((int) $pdo->query('SELECT COUNT(*) FROM User')->fetchColumn() === 1, 'The reset must not replace or add accounts.');
    verify_catalog_update($pdo->query('SELECT password FROM User WHERE user_id = 1')->fetchColumn() === 'existing-password-hash', 'Accounts and passwords must remain intact.');
    $newId = (int) $pdo->query("SELECT MIN(product_id) FROM Products WHERE product_status = 'ACTIVE'")->fetchColumn();
    verify_catalog_update($newId === 1, 'The fresh source list must start at product one.');
    verify_catalog_update(format_product_code($pdo->query('SELECT product_id, display_code FROM Products WHERE product_id = 1')->fetch(PDO::FETCH_ASSOC)) === 'P001', 'Visible codes must start at P001.');
    verify_catalog_update((int) $pdo->query('SELECT COUNT(DISTINCT display_code) FROM Products')->fetchColumn() === 220, 'All new codes must be unique.');
    correct_catalog_presentation_once($pdo);
    $pdo->exec('UPDATE Products SET price = 123.45 WHERE product_id = ' . $newId);
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO Products (product_name, price, category_id) VALUES ('Rolled-back product', 10, 1)");
    assign_product_display_code($pdo, (int) $pdo->lastInsertId());
    $pdo->rollBack();
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO Products (product_name, price, category_id) VALUES ('Added after update', 50, 1)");
    $addedId = (int) $pdo->lastInsertId();
    assign_product_display_code($pdo, $addedId);
    $pdo->commit();
    verify_catalog_update((int) $pdo->query('SELECT display_code FROM Products WHERE product_id = ' . $addedId)->fetchColumn() === 221, 'Next added product must get P221 regardless of its database ID.');
    $pdo->exec("UPDATE Stock_Batch SET quantity_remaining = quantity_remaining - 1 WHERE status = 'ACTIVE' ORDER BY batch_id LIMIT 1");
    for ($visit = 0; $visit < 3; $visit++) verify_catalog_update(!replace_final_catalog_once($pdo), 'Later visits must skip replacement.');
    verify_catalog_update((float) $pdo->query('SELECT price FROM Products WHERE product_id = ' . $newId)->fetchColumn() === 123.45, 'Later price edits must persist.');
    verify_catalog_update((int) $pdo->query("SELECT COUNT(*) FROM Products WHERE product_status = 'ACTIVE'")->fetchColumn() === 221, 'Later added products must persist.');
    verify_catalog_update((int) $pdo->query("SELECT SUM(quantity_remaining) FROM Stock_Batch WHERE status = 'ACTIVE'")->fetchColumn() === 1505, 'Later stock changes must persist.');
    $_SESSION['cart'] = [1 => ['qty' => 1]];
    $_SESSION['csrf_token'] = 'token-from-before-reset';
    unset($_SESSION['inventory_catalog_version']);
    sync_session_catalog_version();
    verify_catalog_update(!isset($_SESSION['cart']), 'Old carts must be cleared once.');
    verify_catalog_update($_SESSION['csrf_token'] !== 'token-from-before-reset', 'Pre-reset forms must be invalidated before they can edit reused IDs.');
    $currentToken = $_SESSION['csrf_token'];
    $_SESSION['cart'] = [$newId => ['qty' => 1]];
    sync_session_catalog_version();
    verify_catalog_update(isset($_SESSION['cart'][$newId]), 'New carts must survive later opens.');
    verify_catalog_update($_SESSION['csrf_token'] === $currentToken, 'New forms must remain valid on later opens.');
    echo "Passed {$checks} one-time catalog replacement checks.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (isset($lockName)) $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
