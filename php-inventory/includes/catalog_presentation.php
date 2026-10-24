<?php
declare(strict_types=1);
require_once __DIR__ . '/catalog_import.php';

const INVENTORY_CATALOG_PRESENTATION_VERSION = '2026_10_catalog_codes_categories_v1';

function catalog_presentation_is_current(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare('SELECT 1 FROM Schema_Migration WHERE migration_id = ?');
        $statement->execute([INVENTORY_CATALOG_PRESENTATION_VERSION]);
        return $statement->fetchColumn() !== false;
    } catch (PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) === 1146) return false;
        throw $exception;
    }
}

function ensure_product_display_schema(PDO $pdo): void
{
    if (!table_column_exists($pdo, 'Products', 'display_code')) $pdo->exec('ALTER TABLE Products ADD COLUMN display_code INT UNSIGNED NULL UNIQUE');
    $pdo->exec('CREATE TABLE IF NOT EXISTS Product_Code_Sequence (sequence_id TINYINT PRIMARY KEY, next_number INT UNSIGNED NOT NULL)');
}

function assign_product_display_code(PDO $pdo, int $productId): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Product codes must be assigned while saving the product.');
    $number = $pdo->query('SELECT next_number FROM Product_Code_Sequence WHERE sequence_id = 1 FOR UPDATE')->fetchColumn();
    if ($number === false) throw new RuntimeException('Product numbering is not ready. Refresh the page.');
    $pdo->prepare('UPDATE Product_Code_Sequence SET next_number = next_number + 1 WHERE sequence_id = 1')->execute();
    $statement = $pdo->prepare('UPDATE Products SET display_code = ? WHERE product_id = ? AND display_code IS NULL');
    $statement->execute([(int) $number, $productId]);
    if ($statement->rowCount() !== 1) throw new RuntimeException('The product already has a code. Refresh the page.');
}

function correct_catalog_presentation_once(PDO $pdo): bool
{
    if (catalog_presentation_is_current($pdo)) return false;
    if ($pdo->inTransaction()) throw new RuntimeException('Catalog corrections must run before other transactions.');
    $lockName = inventory_upgrade_lock_name($pdo);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 3)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) throw new InventoryDatabaseUpgradeBusy('A catalog correction is in progress.');
    try {
        if (catalog_presentation_is_current($pdo)) return false;
        ensure_product_display_schema($pdo);
        $pdo->beginTransaction();
        $products = $pdo->query("SELECT product_id, display_code FROM Products WHERE product_status <> 'DELETED' ORDER BY product_id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $next = 1;
        foreach ($products as $product) if ($product['display_code'] !== null) $next = max($next, (int) $product['display_code'] + 1);
        $update = $pdo->prepare('UPDATE Products SET display_code = ? WHERE product_id = ? AND display_code IS NULL');
        foreach ($products as $product) if ($product['display_code'] === null) $update->execute([$next++, $product['product_id']]);
        $pdo->prepare('INSERT INTO Product_Code_Sequence (sequence_id, next_number) VALUES (1, ?) ON DUPLICATE KEY UPDATE next_number = GREATEST(next_number, VALUES(next_number))')->execute([$next]);

        $pdo->prepare('INSERT INTO Schema_Migration (migration_id) VALUES (?)')->execute([INVENTORY_CATALOG_PRESENTATION_VERSION]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}
