<?php
declare(strict_types=1);
require_once __DIR__ . '/domain.php';

const INVENTORY_SCHEMA_VERSION = '2026_10_recommendation_catalog_v1';

class InventoryDatabaseUpgradeBusy extends RuntimeException {}

function inventory_database_is_current(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare('SELECT 1 FROM Schema_Migration WHERE migration_id = ?');
        $statement->execute([INVENTORY_SCHEMA_VERSION]);
        return $statement->fetchColumn() !== false;
    } catch (PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) === 1146) return false;
        throw $exception;
    }
}

function inventory_upgrade_lock_name(PDO $pdo): string
{
    return 'inventory_upgrade_' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
}

function run_inventory_database_upgrades(PDO $pdo): bool
{
    if (inventory_database_is_current($pdo)) return false;
    if ($pdo->inTransaction()) throw new RuntimeException('Database upgrades must run before application transactions.');
    $lockName = inventory_upgrade_lock_name($pdo);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 3)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) throw new InventoryDatabaseUpgradeBusy('A database upgrade is already in progress. Reload shortly.');
    try {
        if (inventory_database_is_current($pdo)) return false;
        // MySQL commits DDL independently; data and the completion marker commit together below.
        $schema = file_get_contents(__DIR__ . '/../database.sql');
        if ($schema === false) throw new RuntimeException('The database schema file could not be read.');
        preg_match_all('/CREATE TABLE IF NOT EXISTS (?:Vehicle_Compatibility|Vehicle_Alias|Product_Vehicle|Recommendation_State|Product_Type|Schema_Migration) .*?;/s', $schema, $tables);
        if (count($tables[0]) !== 6) throw new RuntimeException('The database upgrade schema is incomplete.');
        foreach ($tables[0] as $table) $pdo->exec($table);
        ensure_product_lifecycle_schema($pdo);
        if (!table_column_exists($pdo, 'Products', 'essential_fitment')) $pdo->exec('ALTER TABLE Products ADD COLUMN essential_fitment TEXT NULL');
        $pdo->beginTransaction();
        $products = $pdo->query("SELECT product_id, COALESCE(compatibility, '') AS compatibility FROM Products ORDER BY product_id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $lookup = $pdo->prepare('SELECT vehicle_id FROM Vehicle_Alias WHERE alias_key = ?');
        $insert = $pdo->prepare('INSERT IGNORE INTO Product_Vehicle (product_id, vehicle_id) VALUES (?, ?)');
        foreach ($products as $product) {
            foreach (explode(',', $product['compatibility']) as $name) {
                $name = trim($name);
                if ($name === '') continue;
                $lookup->execute([vehicle_name_key($name)]);
                $id = $lookup->fetchColumn();
                if ($id === false) $id = save_vehicle_option($pdo, null, $name, '');
                $insert->execute([(int) $product['product_id'], (int) $id]);
            }
        }
        $types = $pdo->query("SELECT product_id, product_type FROM Products WHERE COALESCE(product_type, '') <> '' ORDER BY product_id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $updateType = $pdo->prepare('UPDATE Products SET product_type = ? WHERE product_id = ?');
        foreach ($types as $product) {
            $type = trim($product['product_type']) === '' ? ['type_name' => ''] : save_product_type_option($pdo, $product['product_type']);
            $updateType->execute([$type['type_name'], $product['product_id']]);
        }
        refresh_product_vehicle_names($pdo);
        sync_feature_matches_for_catalog($pdo);
        $pdo->prepare('INSERT INTO Schema_Migration (migration_id) VALUES (?)')->execute([INVENTORY_SCHEMA_VERSION]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function ensure_inventory_database_ready(PDO $pdo): void
{
    try {
        if (inventory_database_is_current($pdo)) return;
        $user = $pdo->prepare('SELECT role_id, is_active FROM User WHERE user_id = ?');
        $user->execute([current_user_id()]);
        $account = $user->fetch(PDO::FETCH_ASSOC);
        if (!$account || !(bool) $account['is_active'] || (int) $account['role_id'] !== APP_ROLE_ADMIN) {
            inventory_upgrade_unavailable('A system update is pending. Ask an administrator to sign in and open the system once.');
        }
        if (run_inventory_database_upgrades($pdo)) set_flash('success', 'System updated successfully. Existing products and stock history were preserved.');
    } catch (InventoryDatabaseUpgradeBusy) {
        inventory_upgrade_unavailable('The database update is already in progress. Reload shortly.');
    } catch (Throwable $exception) {
        error_log('Automatic inventory database upgrade failed: ' . $exception->getMessage());
        inventory_upgrade_unavailable('The automatic database update could not finish. Reload to retry. If this continues, contact the system maintainer to check database permissions and logs.');
    }
}

function inventory_upgrade_unavailable(string $message): never
{
    http_response_code(503);
    header('Retry-After: 5');
    header('Cache-Control: no-store');
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type: application/json') === 0) {
            echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
            exit;
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>System update</title><body><main style="max-width:36rem;margin:4rem auto;padding:1rem;font-family:system-ui"><h1>System update</h1><p>' . h($message) . '</p><a href="' . h(app_url('login.php')) . '">Sign in</a></main></body></html>';
    exit;
}
