<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_upgrades.php';
try {
    $updated = run_inventory_database_upgrades($pdo);
    echo $updated ? "Database upgrade completed.\n" : "Database is already up to date.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
