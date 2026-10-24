<?php
declare(strict_types=1);
require_once __DIR__ . '/domain.php';

function final_catalog_payload(): array
{
    $path = __DIR__ . '/../catalog/final-products.php';
    if (!is_readable($path)) throw new RuntimeException('The final product list is unavailable. Contact the administrator.');
    try {
        $payload = require $path;
    } catch (Throwable $exception) {
        error_log('Final product list validation failed: ' . $exception->getMessage());
        throw new RuntimeException('The final product list could not be read. Contact the administrator.');
    }
    $products = $payload['products'] ?? [];
    if (count($products) !== 220) throw new RuntimeException('The final product list is incomplete.');
    $batchCount = 0;
    $quantity = 0;
    foreach ($products as $index => $product) {
        if ($product['number'] !== $index + 1) throw new RuntimeException('Invalid product numbering.');
        foreach (['category' => 100, 'name' => 150, 'brand' => 100, 'type' => 50] as $key => $limit) {
            if (!is_string($product[$key]) || trim($product[$key]) === '' || strlen($product[$key]) > $limit) throw new RuntimeException('Invalid product ' . $key . '.');
        }
        foreach (['price', 'retail_price', 'cost'] as $key) {
            if (!is_numeric($product[$key]) || $product[$key] < 0 || $product[$key] > 99999999.99) throw new RuntimeException('Invalid product price.');
        }
        foreach (['quantity', 'minimum'] as $key) {
            if (!is_int($product[$key]) || $product[$key] < 0) throw new RuntimeException('Invalid product stock.');
        }
        parse_essential_fitment($product['fitment']);
        foreach (array_merge([$product], $product['batches']) as $dated) {
            foreach (['manufacturing_date', 'expiration_date'] as $key) {
                if ($dated[$key] !== null && normalize_date_input($dated[$key]) !== $dated[$key]) throw new RuntimeException('Invalid catalog date.');
            }
            if ($dated['manufacturing_date'] && $dated['expiration_date'] && $dated['manufacturing_date'] > $dated['expiration_date']) throw new RuntimeException('Expiration precedes manufacturing.');
        }
        $total = 0;
        $seen = [];
        foreach ($product['batches'] as $batch) {
            if (!is_int($batch['quantity']) || $batch['quantity'] <= 0 || !is_numeric($batch['cost']) || $batch['cost'] < 0 || $batch['cost'] > 99999999.99 || strlen($batch['number']) > 50 || $batch['number'] === '' || isset($seen[$batch['number']])) throw new RuntimeException('Invalid or duplicate batch.');
            $seen[$batch['number']] = true;
            $total += $batch['quantity'];
            $batchCount++;
        }
        if ($total !== $product['quantity']) throw new RuntimeException('Batch stock does not match product stock.');
        $quantity += $total;
    }
    if ($batchCount !== 425 || $quantity !== 1506) throw new RuntimeException('The final batch list is incomplete.');
    return $payload;
}

function import_final_catalog(PDO $pdo): void
{
    $payload = final_catalog_payload();
    ensure_product_lifecycle_schema($pdo);
    if ($pdo->inTransaction()) throw new RuntimeException('Finish the current operation before importing products.');
    $pdo->beginTransaction();
    try {
        // Lock the catalog range: another import or product insertion cannot interleave.
        if ($pdo->query('SELECT product_id FROM Products FOR UPDATE')->fetchColumn() !== false) throw new RuntimeException('The final list can only be loaded into an empty catalog. Existing products and history were kept.');
        $categories = [];
        foreach ($pdo->query('SELECT category_id, category_name FROM Category ORDER BY category_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $category) $categories[normalize_recommendation_attribute($category['category_name'])] = (int) $category['category_id'];
        $models = [];
        foreach (fetch_vehicle_options($pdo) as $model) $models[vehicle_name_key($model['vehicle_name'])] = (int) $model['vehicle_id'];
        $insert = $pdo->prepare('INSERT INTO Products (product_id, category_id, product_name, brand, product_type, specification, essential_fitment, description, price, retail_price, acquisition_cost, manufacturing_date, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $inventory = $pdo->prepare('INSERT INTO Inventory (product_id, current_stock, min_stock_level, expiry_date) VALUES (?, 0, ?, ?)');
        foreach ($payload['products'] as $product) {
            $categoryKey = normalize_recommendation_attribute($product['category']);
            if (!isset($categories[$categoryKey])) {
                $pdo->prepare('INSERT INTO Category (category_name) VALUES (?)')->execute([$product['category']]);
                $categories[$categoryKey] = (int) $pdo->lastInsertId();
            }
            $type = save_product_type_option($pdo, $product['type']);
            $insert->execute([$product['number'], $categories[$categoryKey], $product['name'], $product['brand'], $type['type_name'], $product['specification'], json_encode((object) parse_essential_fitment($product['fitment']), JSON_THROW_ON_ERROR), $product['description'], $product['price'], $product['retail_price'], $product['cost'], $product['manufacturing_date'], $product['expiration_date']]);
            $inventory->execute([$product['number'], $product['minimum'], $product['expiration_date']]);
            $ids = [];
            foreach ($product['compatibility'] as $name) {
                $key = vehicle_name_key($name);
                if (!isset($models[$key])) $models[$key] = save_vehicle_option($pdo, null, $name);
                $ids[] = $models[$key];
            }
            save_product_vehicles($pdo, $product['number'], $ids);
            foreach ($product['batches'] as $batch) create_stock_batch($pdo, $product['number'], $batch['quantity'], (float) $batch['cost'], $batch['manufacturing_date'], $batch['expiration_date'], $batch['number']);
        }
        sync_reorder_alerts_for_catalog($pdo);
        sync_feature_matches_for_catalog($pdo);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
