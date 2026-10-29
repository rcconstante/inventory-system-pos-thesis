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
    $GLOBALS['business_settings'] = $pdo->query('SELECT * FROM Business_Settings WHERE setting_id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $GLOBALS['custom_product_properties'] = $pdo->query('SELECT property_key, label FROM Product_Property ORDER BY label')->fetchAll(PDO::FETCH_KEY_PAIR);
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
