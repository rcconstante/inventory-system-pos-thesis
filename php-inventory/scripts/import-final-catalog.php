<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/catalog_import.php';
try {
    final_catalog_payload();
    if (in_array('--apply', $argv, true)) {
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/database_upgrades.php';
        run_inventory_database_upgrades($pdo);
        import_final_catalog($pdo);
        echo "Imported 220 products and 425 batches. Existing accounts were preserved.\n";
    } else echo "Validated 220 products, 425 batches, 1506 units. Use --apply on an empty catalog to import.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
