<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/database_upgrades.php';
$dsn = getenv('INVENTORY_TEST_DB_DSN');
if (!$dsn) throw new RuntimeException('Set INVENTORY_TEST_DB_DSN to a disposable local server.');
$user = getenv('INVENTORY_TEST_DB_USER') ?: 'root';
$password = getenv('INVENTORY_TEST_DB_PASS') ?: '';
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'inventory_upgrade_test_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
$other = null;
$checks = 0;
function verify_upgrade(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
try {
    $pdo->exec('USE `' . $database . '`');
    $schema = file_get_contents(__DIR__ . '/../database.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s', $schema, $tables);
    foreach ($tables[0] as $table) $pdo->exec($table);
    ensure_product_lifecycle_schema($pdo);
    $pdo->exec('DROP TABLE Product_Vehicle, Vehicle_Alias, Vehicle_Compatibility, Product_Type, Recommendation_State, Schema_Migration');
    $pdo->exec('ALTER TABLE Products DROP COLUMN essential_fitment');
    $pdo->exec("INSERT INTO Category (category_id, category_name) VALUES (1, 'Tires')");
    $pdo->exec("INSERT INTO Products (product_id, product_name, price, category_id, product_type, compatibility) VALUES (1, 'Tire', 100, 1, 'Tire', 'Honda Click 125')");
    $invalid = str_repeat('x', 101);
    $pdo->prepare('INSERT INTO Products (product_id, product_name, price, category_id, product_type, compatibility) VALUES (2, ?, 100, 1, ?, ?)')->execute(['Other tire', 'Tire', $invalid]);
    verify_upgrade(!inventory_database_is_current($pdo), 'Missing migration table must be treated as pending.');
    $other = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lockName = inventory_upgrade_lock_name($pdo);
    $other->prepare('SELECT GET_LOCK(?, 0)')->execute([$lockName]);
    try {
        run_inventory_database_upgrades($pdo);
        verify_upgrade(false, 'Concurrent upgrade must not proceed.');
    } catch (InventoryDatabaseUpgradeBusy) { verify_upgrade(true, 'Concurrent upgrade rejected.'); }
    $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    try {
        run_inventory_database_upgrades($pdo);
        verify_upgrade(false, 'Invalid legacy data must not produce a completed upgrade.');
    } catch (RuntimeException $exception) { verify_upgrade(str_contains($exception->getMessage(), 'vehicle name'), 'Data error must be surfaced to logs or CLI.'); }
    verify_upgrade(!inventory_database_is_current($pdo), 'Failed upgrade must not record completion.');
    verify_upgrade((int) $pdo->query('SELECT COUNT(*) FROM Product_Vehicle')->fetchColumn() === 0, 'Failed data import must roll back partial assignments.');
    verify_upgrade($pdo->query('SELECT compatibility FROM Products WHERE product_id = 2')->fetchColumn() === $invalid, 'Original data must survive failed import.');
    $otherLock = $other->prepare('SELECT GET_LOCK(?, 0)');
    $otherLock->execute([$lockName]);
    verify_upgrade((int) $otherLock->fetchColumn() === 1, 'Failure must release the upgrade lock.');
    $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    $pdo->exec("UPDATE Products SET compatibility = 'Honda Click-125' WHERE product_id = 2");
    verify_upgrade(run_inventory_database_upgrades($pdo), 'Corrected data must allow a successful retry.');
    verify_upgrade(inventory_database_is_current($pdo), 'Successful retry must record completion.');
    verify_upgrade((int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility')->fetchColumn() === 1, 'Formatting aliases must consolidate on retry.');
    verify_upgrade((int) $pdo->query('SELECT COUNT(*) FROM Product_Vehicle')->fetchColumn() === 2, 'All assignments must be preserved on retry.');
    $pdo->exec("CREATE TRIGGER reject_repeated_import BEFORE UPDATE ON Products FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Repeated import'");
    verify_upgrade(!run_inventory_database_upgrades($pdo), 'Completed upgrade must not scan or rewrite products again.');
    verify_upgrade((int) $pdo->query('SELECT COUNT(*) FROM Schema_Migration')->fetchColumn() === 1, 'Completion marker must not be duplicated.');
    echo "Passed {$checks} database upgrade checks.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($other instanceof PDO && isset($lockName)) $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
