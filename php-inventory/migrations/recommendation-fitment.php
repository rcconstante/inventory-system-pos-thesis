<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/domain.php';

// DDL runs before the data transaction because MySQL implicitly commits schema changes.
ensure_product_lifecycle_schema($pdo);
if (!table_column_exists($pdo, 'Products', 'essential_fitment')) {
    $pdo->exec('ALTER TABLE Products ADD COLUMN essential_fitment TEXT NULL');
}
$schema = file_get_contents(__DIR__ . '/../database.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS (?:Vehicle_Compatibility|Vehicle_Alias|Product_Vehicle|Recommendation_State) .*?;/s', $schema, $tables);
foreach ($tables[0] as $table) $pdo->exec($table);
$pdo->beginTransaction();
try {
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
    refresh_product_vehicle_names($pdo);
    sync_feature_matches_for_catalog($pdo);
    $pdo->commit();
    echo "Recommendation schema, compatibility records and matches updated.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
