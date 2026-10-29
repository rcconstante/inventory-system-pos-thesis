<?php
declare(strict_types=1);

function ensure_revision_schema(PDO $pdo): void
{
    $marker = '2026_10_inventory_revisions_v1';
    $check = $pdo->prepare('SELECT 1 FROM Schema_Migration WHERE migration_id = ?');
    $check->execute([$marker]);
    if (!$check->fetchColumn()) {
        $lockName = inventory_upgrade_lock_name($pdo);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)'); $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('System update is busy. Reload shortly.');
        try {
            $check->execute([$marker]);
            if (!$check->fetchColumn()) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS Business_Settings (setting_id INT PRIMARY KEY, business_name VARCHAR(150) NOT NULL, address VARCHAR(250) NOT NULL, phone VARCHAR(50) NOT NULL, logo MEDIUMTEXT NULL)");
                $pdo->exec("CREATE TABLE IF NOT EXISTS Product_Property (property_key VARCHAR(40) PRIMARY KEY, label VARCHAR(80) NOT NULL)");
                $columnsByTable = [
                    'Products' => ['created_at' => 'TIMESTAMP NULL DEFAULT NULL', 'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'],
                    'Stock_Batch' => ['updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'],
                    'Sale_Item' => ['returned_at' => 'DATETIME NULL', 'return_reason' => 'VARCHAR(500) NULL', 'return_destination' => 'VARCHAR(20) NULL'],
                ];
                foreach ($columnsByTable as $table => $columns) {
                    foreach ($columns as $name => $type) {
                        if (!table_column_exists($pdo, $table, $name)) {
                            $pdo->exec("ALTER TABLE $table ADD COLUMN $name $type");
                        }
                    }
                }
                $pdo->exec('ALTER TABLE Products MODIFY COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
                $pdo->prepare('INSERT IGNORE INTO Schema_Migration (migration_id) VALUES (?)')->execute([$marker]);
            }
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
    ensure_property_management_schema($pdo);
    $GLOBALS['business_settings'] = $pdo->query('SELECT * FROM Business_Settings WHERE setting_id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    load_product_property_settings($pdo);
}

function business_setting(string $key): string
{
    $defaults = ['business_name' => 'FIVE BROTHERS TRADING', 'address' => 'Mabuhay, Carmona, Cavite', 'phone' => '0961-195-6139', 'logo' => ''];
    return (string) ($GLOBALS['business_settings'][$key] ?? $defaults[$key] ?? '');
}

function sales_invoice_number(int $saleId): string
{
    return 'SI-' . str_pad((string) $saleId, 8, '0', STR_PAD_LEFT);
}

function return_selected_sale_items(PDO $pdo, int $saleId, string $reason, array $selected, string $destination): void
{
    if (!in_array($destination, ['STOCK', 'QUARANTINE'], true)) throw new RuntimeException('Select a return destination.');
    if ($selected === [] || count($selected) > 100) throw new RuntimeException('Select the products to return.');
    foreach ($selected as $id) {
        if (!is_scalar($id) || !filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new RuntimeException('Select valid return items.');
    }
    $selected = array_values(array_unique(array_map('intval', $selected)));
    if (strlen($reason) > 500) throw new RuntimeException('Return reason must be at most 500 characters.');
    $itemsStmt = $pdo->prepare('SELECT sale_item_id, product_id, quantity, returned_at FROM Sale_Item WHERE sale_id = ? FOR UPDATE');
    $itemsStmt->execute([$saleId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    $eligible = array_filter($items, static fn(array $item): bool => !$item['returned_at'] && in_array((int) $item['sale_item_id'], $selected, true));
    if (count($eligible) !== count($selected)) throw new RuntimeException('A selected item is invalid or already returned.');
    $returnItem = $pdo->prepare('UPDATE Sale_Item SET returned_at = NOW(), return_reason = ?, return_destination = ? WHERE sale_item_id = ?');
    foreach ($eligible as $item) {
        if ($destination === 'STOCK') {
            $safe = $pdo->prepare("SELECT COUNT(*) FROM Sale_Item_Batch sib JOIN Stock_Batch b ON b.batch_id = sib.batch_id JOIN Products p ON p.product_id = b.product_id WHERE sib.sale_item_id = ? AND (b.status <> 'ACTIVE' OR (b.expiration_date IS NOT NULL AND b.expiration_date < CURDATE()) OR COALESCE(p.product_status, 'ACTIVE') <> 'ACTIVE')");
            $safe->execute([$item['sale_item_id']]);
            if ((int) $safe->fetchColumn() > 0) throw new RuntimeException('This item cannot return to sellable stock. Choose Do not restock.');
            restore_stock_to_batches($pdo, (int) $item['sale_item_id']);
        }
        $returnItem->execute([$reason, $destination, $item['sale_item_id']]);
    }
    $remaining = $pdo->prepare('SELECT COUNT(*) FROM Sale_Item WHERE sale_id = ? AND returned_at IS NULL');
    $remaining->execute([$saleId]);
    if ((int) $remaining->fetchColumn() === 0) $pdo->prepare("UPDATE Sale SET status = 'RETURNED' WHERE sale_id = ?")->execute([$saleId]);
}

function ensure_property_management_schema(PDO $pdo): void
{
    $marker = '2026_10_product_properties_v2';
    $check = $pdo->prepare('SELECT 1 FROM Schema_Migration WHERE migration_id = ?');
    $check->execute([$marker]);
    if ($check->fetchColumn()) return;
    $name = inventory_upgrade_lock_name($pdo);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)'); $lock->execute([$name]);
    if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Property update is busy. Reload shortly.');
    try {
        $check->execute([$marker]);
        if ($check->fetchColumn()) return;
        if (!table_column_exists($pdo, 'Product_Property', 'is_active')) $pdo->exec('ALTER TABLE Product_Property ADD COLUMN is_active TINYINT NOT NULL DEFAULT 1');
        // Existing criteria keep their previous behavior. New properties default to information only.
        if (!table_column_exists($pdo, 'Product_Property', 'affects_matching')) {
            $pdo->exec('ALTER TABLE Product_Property ADD COLUMN affects_matching TINYINT NOT NULL DEFAULT 1');
        }
        $pdo->exec('ALTER TABLE Product_Property MODIFY COLUMN affects_matching TINYINT NOT NULL DEFAULT 0');
        $pdo->prepare('INSERT INTO Schema_Migration (migration_id) VALUES (?)')->execute([$marker]);
    } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
}

function load_product_property_settings(PDO $pdo): void
{
    $all = $pdo->query('SELECT property_key, label, is_active, affects_matching FROM Product_Property ORDER BY label, property_key')->fetchAll(PDO::FETCH_ASSOC);
    $GLOBALS['product_property_settings'] = $all;
    $GLOBALS['custom_product_properties'] = [];
    $GLOBALS['matching_product_properties'] = [];
    $GLOBALS['retired_product_properties'] = [];
    foreach ($all as $property) {
        $key = $property['property_key'];
        if (!(int) $property['is_active']) { $GLOBALS['retired_product_properties'][$key] = $property['label']; continue; }
        $GLOBALS['custom_product_properties'][$key] = $property['label'];
        if ((int) $property['affects_matching']) $GLOBALS['matching_product_properties'][$key] = $property['label'];
    }
}

function save_product_property(PDO $pdo, array $input): void
{
    $key = $input['property_key'] ?? '';
    $label = $input['label'] ?? '';
    $original = $input['original_key'] ?? '';
    if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key)
        || !is_string($label) || trim($label) === '' || strlen($label) > 80 || !is_string($original)) {
        throw new RuntimeException('Use a label up to 80 characters and a key with lowercase letters, numbers or underscores.');
    }
    $builtIn = ['size', 'position', 'voltage', 'viscosity'];
    if (in_array($key, $builtIn, true) || in_array($original, $builtIn, true)) throw new RuntimeException('Built-in fitment keys are reserved.');
    $pdo->beginTransaction();
    try {
        $rows = $pdo->query('SELECT * FROM Product_Property ORDER BY property_key FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_column($rows, null, 'property_key');
        if ($original !== '' && !isset($existing[$original])) throw new RuntimeException('This property changed. Reload settings before editing.');
        if ($key !== $original && isset($existing[$key])) throw new RuntimeException('That property key is already in use, including retired properties.');
        if ($original !== '' && $key !== $original) {
            $products = $pdo->query('SELECT product_id, essential_fitment FROM Products ORDER BY product_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            $update = $pdo->prepare('UPDATE Products SET essential_fitment = ? WHERE product_id = ?');
            foreach ($products as $product) {
                $values = json_decode($product['essential_fitment'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($values)) throw new RuntimeException('Saved product properties are invalid. Rename was cancelled.');
                if (!array_key_exists($original, $values)) continue;
                if (array_key_exists($key, $values)) throw new RuntimeException('A product already has a value under the new key. Choose a different key.');
                $values[$key] = $values[$original]; unset($values[$original]);
                $update->execute([json_encode((object) $values, JSON_THROW_ON_ERROR), $product['product_id']]);
            }
        }
        $active = $original === '' ? 1 : (!empty($input['is_active']) ? 1 : 0);
        $matching = !empty($input['affects_matching']) ? 1 : 0;
        if ($original === '') {
            $pdo->prepare('INSERT INTO Product_Property (property_key, label, is_active, affects_matching) VALUES (?, ?, ?, ?)')->execute([$key, trim($label), $active, $matching]);
        } else {
            $pdo->prepare('UPDATE Product_Property SET property_key = ?, label = ?, is_active = ?, affects_matching = ? WHERE property_key = ?')->execute([$key, trim($label), $active, $matching, $original]);
        }
        load_product_property_settings($pdo);
        sync_feature_matches_for_catalog($pdo);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        load_product_property_settings($pdo);
        throw $e;
    }
}
