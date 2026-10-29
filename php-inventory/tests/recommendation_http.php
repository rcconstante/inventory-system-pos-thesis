<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/domain.php';

$dsn = getenv('INVENTORY_TEST_DB_DSN');
if (!$dsn) throw new RuntimeException('Set INVENTORY_TEST_DB_DSN for a disposable local MySQL/MariaDB server.');
$user = getenv('INVENTORY_TEST_DB_USER') ?: 'root';
$password = getenv('INVENTORY_TEST_DB_PASS') ?: '';
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'inventory_http_test_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
$server = null;
$log = tempnam(sys_get_temp_dir(), 'inventory-http-');
$checks = 0;
function verify_http(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function http_request(string $base, string $path, array &$cookies, ?array $post = null): array {
    $header = 'Cookie: ' . implode('; ', array_map(static fn ($key, $value): string => $key . '=' . $value, array_keys($cookies), $cookies)) . "\r\n";
    if ($post !== null) $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $body = file_get_contents($base . '/php-inventory/' . $path, false, stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST', 'header' => $header,
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]));
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $line, $match)) $cookies[$match[1]] = $match[2];
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
    return [(int) ($status[1] ?? 0), $body ?: ''];
}
function http_csrf(string $html): string {
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match)) throw new RuntimeException('Missing CSRF field.');
    return $match[1];
}
try {
    $pdo->exec('USE `' . $database . '`');
    $schema = file_get_contents(__DIR__ . '/../database.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s', $schema, $tables);
    foreach ($tables[0] as $table) $pdo->exec($table);
    ensure_product_lifecycle_schema($pdo);
    $pdo->exec("INSERT INTO Role (role_id, role_type) VALUES (1, 'admin'), (2, 'cashier')");
    $insertUser = $pdo->prepare('INSERT INTO User (user_id, full_name, username, password, role_id) VALUES (?, ?, ?, ?, ?)');
    $insertUser->execute([1, 'Test Admin', 'http-admin', password_hash('local-test-only', PASSWORD_DEFAULT), 1]);
    $insertUser->execute([2, 'Test Cashier', 'http-cashier', password_hash('local-test-only', PASSWORD_DEFAULT), 2]);
    $pdo->exec("INSERT INTO Category (category_id, category_name) VALUES (1, 'Tires')");
    $pdo->exec("INSERT INTO Products (product_id, product_name, price, category_id, product_type, specification, compatibility, brand) VALUES
        (1, 'Dunlop Front Tire', 100, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Dunlop'),
        (2, 'Dunlop Front Tire Plus', 120, 1, ' tire ', 'Tubeless tire 80/90-14 extra grip', 'Honda Click-125', 'Dunlop'),
        (3, 'Alpha alternative', 100, 1, 'Tire', '80/90-14 tubeless', 'Honda Click125', 'Dunlop')");
    $pdo->exec('INSERT INTO Inventory (product_id, current_stock) VALUES (1, 0), (2, 10), (3, 10)');
    $pdo->exec("INSERT INTO Stock_Batch (product_id, batch_number, quantity_received, quantity_remaining) VALUES (2, 'PLUS', 10, 10), (3, 'ALPHA', 10, 10)");
    // Exercise upgrade from the previous schema, then repeat it to prove idempotency.
    $pdo->exec('ALTER TABLE Products DROP COLUMN essential_fitment');
    $pdo->exec('DROP TABLE Product_Vehicle, Vehicle_Alias, Vehicle_Compatibility, Recommendation_State, Product_Type, Schema_Migration');
    $pdo->exec('CREATE TABLE Schema_Migration (migration_id VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO Schema_Migration (migration_id) VALUES ('2026_10_fresh_catalog_sales_v2')");
    preg_match('/host=([^;]+)/', $dsn, $host);
    preg_match('/port=(\d+)/', $dsn, $port);
    $env = array_merge(getenv(), [
        'INVENTORY_DB_HOST' => $host[1] ?? '127.0.0.1', 'INVENTORY_DB_PORT' => $port[1] ?? '3306',
        'INVENTORY_DB_NAME' => $database, 'INVENTORY_DB_USER' => $user, 'INVENTORY_DB_PASS' => $password,
    ]);
    $phpCommand = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql'];
    $automaticUpgrade = getenv('INVENTORY_TEST_AUTOMATIC_UPGRADE') === '1';
    for ($run = 0; !$automaticUpgrade && $run < 2; $run++) {
        $pipes = [];
        $migration = proc_open(array_merge($phpCommand, [__DIR__ . '/../migrations/recommendation-fitment.php']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        verify_http(proc_close($migration) === 0, 'Migration failed: ' . $error . $output);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open(array_merge($phpCommand, ['-S', $address, '-t', dirname(__DIR__, 2)]), [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    fclose($pipes[0]);
    $base = 'http://' . $address;
    $cookies = [];
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1);
        if ($connection) { fclose($connection); break; }
        usleep(100000);
    }
    [$status, $login] = http_request($base, 'login.php', $cookies);
    verify_http($status === 200, 'Login must render.');
    if ($automaticUpgrade) {
        $pendingCookies = [];
        [, $pendingLogin] = http_request($base, 'login.php', $pendingCookies);
        http_request($base, 'login.php', $pendingCookies, ['csrf_token' => http_csrf($pendingLogin), 'email' => 'http-cashier', 'password' => 'local-test-only']);
        [$pendingStatus, $pendingBody] = http_request($base, 'pages/pos.php', $pendingCookies);
        verify_http($pendingStatus === 503 && str_contains($pendingBody, 'administrator'), 'Cashier must see a clear pending-upgrade response.');
        verify_http((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Schema_Migration'")->fetchColumn() === 1, 'Non-admin requests must leave the pre-recorded catalog marker unchanged.');
    }
    [$status] = http_request($base, 'login.php', $cookies, ['csrf_token' => http_csrf($login), 'email' => 'http-admin', 'password' => 'local-test-only']);
    verify_http($status === 302, 'Admin login must succeed.');
    if ($automaticUpgrade) {
        $pdo->prepare('UPDATE Products SET compatibility = ? WHERE product_id = 3')->execute([str_repeat('x', 101)]);
        [$failureStatus, $failureBody] = http_request($base, 'pages/products.php', $cookies);
        verify_http($failureStatus === 503 && str_contains($failureBody, 'could not finish') && !str_contains($failureBody, 'SQLSTATE'), 'Failed automatic upgrade must show a safe, actionable response.');
        verify_http((int) $pdo->query('SELECT COUNT(*) FROM Schema_Migration')->fetchColumn() === 1, 'Failed automatic upgrade must leave the schema update pending.');
        [$failureStatus, $failureJson] = http_request($base, 'pages/vehicle_create.php', $cookies, ['csrf_token' => http_csrf($login), 'vehicle_name' => 'Should not be created']);
        // Login regenerates the session but retains its CSRF token.
        verify_http($failureStatus === 503 && isset(json_decode($failureJson, true)['error']), 'Inline APIs must return JSON while the upgrade is unavailable.');
        $pdo->exec("UPDATE Products SET compatibility = 'Honda Click125' WHERE product_id = 3");
    }
    [$status, $inventory] = http_request($base, 'pages/products.php', $cookies);
    verify_http($status === 200 && str_contains($inventory, 'Manage Product Options') && str_contains($inventory, 'name="vehicle_ids[]"'), 'Inventory must render new controls.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility')->fetchColumn() === 1, 'Migration must consolidate formatting variants.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Vehicle')->fetchColumn() === 3, 'Migration must preserve all assignments across reruns.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Type')->fetchColumn() === 1 && $pdo->query('SELECT product_type FROM Products WHERE product_id = 2')->fetchColumn() === 'Tire', 'Migration must consolidate existing product types and preserve canonical values.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Schema_Migration')->fetchColumn() === 5, 'Catalog, schema and product-code completion markers must remain unique.');
    [$codeSearchStatus, $codeSearch] = http_request($base, 'pages/products.php?search=P001', $cookies);
    verify_http($codeSearchStatus === 200 && str_contains($codeSearch, 'data-product-id="1"') && !str_contains($codeSearch, 'data-product-id="2"'), 'Inventory code search must return only the matching product without parameter errors.');
    verify_http(str_contains($inventory, 'data-catalog-picker="type"') && str_contains($inventory, 'Add New Product Type') && str_contains($inventory, 'data-picker-apply') && !str_contains($inventory, 'openDuplicateProductModal'), 'Inventory must expose single-type and multiple-vehicle dropdowns with inline creation and Apply.');
    $inlineToken = http_csrf($inventory);
    verify_http(!str_contains($inventory, 'Load Final Product List'), 'The automatic catalog update must not require a manual load button.');
    [$status, $typeBody] = http_request($base, 'pages/product_type_create.php', $cookies, ['csrf_token' => $inlineToken, 'type_name' => 'Oil Filter']);
    $newType = json_decode($typeBody, true, 32, JSON_THROW_ON_ERROR)['type'];
    verify_http($status === 200 && $newType['type_name'] === 'Oil Filter', 'New product type must persist.');
    [$status, $repeatType] = http_request($base, 'pages/product_type_create.php', $cookies, ['csrf_token' => $inlineToken, 'type_name' => 'oil-filter']);
    verify_http($status === 200 && json_decode($repeatType, true)['type']['type_id'] === $newType['type_id'], 'Equivalent product types must reuse one canonical record.');
    [$status] = http_request($base, 'pages/product_type_create.php', $cookies, ['type_name' => 'Missing CSRF']);
    verify_http($status === 403, 'Product type creation must require CSRF.');
    [$status] = http_request($base, 'pages/product_type_create.php', $cookies, ['csrf_token' => $inlineToken, 'type_name' => 'Tire, Battery']);
    verify_http($status === 422, 'Combined product type values must be rejected.');
    $inlineName = 'Yamaha Mio Test';
    [$status, $inlineBody] = http_request($base, 'pages/vehicle_create.php', $cookies, ['csrf_token' => $inlineToken, 'vehicle_name' => $inlineName]);
    $inlineVehicle = json_decode($inlineBody, true, 32, JSON_THROW_ON_ERROR)['vehicle'];
    verify_http($status === 201 && $inlineVehicle['vehicle_name'] === $inlineName, 'Inline creation must save a reusable vehicle.');
    [$status, $repeatBody] = http_request($base, 'pages/vehicle_create.php', $cookies, ['csrf_token' => $inlineToken, 'vehicle_name' => 'Yamaha-Mio Test']);
    verify_http($status === 200 && json_decode($repeatBody, true)['vehicle']['vehicle_id'] === $inlineVehicle['vehicle_id'], 'Formatting duplicates must reuse the existing record.');
    [$status] = http_request($base, 'pages/vehicle_create.php', $cookies, ['vehicle_name' => 'Missing CSRF']);
    verify_http($status === 403, 'Inline creation must enforce CSRF.');
    [$status] = http_request($base, 'pages/vehicle_create.php', $cookies, ['csrf_token' => $inlineToken, 'vehicle_name' => 'Invalid, combined name']);
    verify_http($status === 422, 'Inline creation must validate vehicle names.');
    $pdo->prepare('UPDATE Vehicle_Compatibility SET is_active = 0 WHERE vehicle_id = ?')->execute([$inlineVehicle['vehicle_id']]);
    [$status] = http_request($base, 'pages/vehicle_create.php', $cookies, ['csrf_token' => $inlineToken, 'vehicle_name' => $inlineName]);
    verify_http($status === 409, 'Archived vehicle must require explicit restoration.');
    $pdo->prepare('UPDATE Vehicle_Compatibility SET is_active = 1 WHERE vehicle_id = ?')->execute([$inlineVehicle['vehicle_id']]);
    [$status] = http_request($base, 'pages/compatibility.php', $cookies);
    verify_http($status === 302, 'Old compatibility bookmarks must redirect to product options.');
    [$status] = http_request($base, 'pages/product_options.php', $cookies);
    verify_http($status === 302, 'Product options URLs must redirect to the inventory modal.');
    [$status, $manager] = http_request($base, 'pages/products.php?open_options=1', $cookies);
    verify_http($status === 200 && str_contains($manager, 'id="productOptionsDialog"') && str_contains($manager, 'id="openProductOptions"') && str_contains($manager, 'Product Type') && str_contains($manager, '+ Add Model') && !str_contains($manager, 'merge_vehicle') && !str_contains($manager, 'name="aliases"') && !str_contains($manager, 'Vehicle Compatibility'), 'Combined manager must render simple compatibility and product type controls.');
    verify_http(str_contains($manager, 'aria-label="Delete Tire"') && !str_contains($manager, '$compatibility =') && !str_contains($manager, 'Warning:'), 'Modal must render real catalog rows without source leakage or runtime warnings.');
    [$filterStatus, $filterPage] = http_request($base, 'pages/products.php?search[]=bad&category_id[]=bad&page[]=bad', $cookies);
    verify_http($filterStatus === 200 && !str_contains($filterPage, 'Warning:'), 'Malformed inventory filters must not crash or emit warnings.');
    $optionToken = http_csrf($manager);
    $optionRequest = static function (string $kind, string $action, int $id = 0, string $name = '') use ($base, &$cookies, $optionToken): void {
        http_request($base, 'pages/product_options.php', $cookies, ['csrf_token' => $optionToken, 'option_kind' => $kind, 'action' => $action, 'option_id' => $id, 'option_name' => $name]);
    };
    $beforeMalformed = (int) $pdo->query('SELECT COUNT(*) FROM Product_Type')->fetchColumn();
    http_request($base, 'pages/product_options.php', $cookies, ['csrf_token' => $optionToken, 'option_kind' => ['type'], 'action' => 'add', 'option_name' => 'Invalid']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Type')->fetchColumn() === $beforeMalformed, 'Malformed actions must be rejected without mutation or PHP errors.');
    $optionRequest('type', 'add', 0, str_repeat('製', 40));
    [$invalidNameStatus, $invalidNamePage] = http_request($base, 'pages/products.php?open_options=1', $cookies);
    verify_http($invalidNameStatus === 200 && str_contains($invalidNamePage, 'up to 50 characters') && !str_contains($invalidNamePage, 'Fatal error'), 'Overlong multibyte names must show validation feedback without breaking the modal.');
    $optionRequest('type', 'update', 999999, 'Missing');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Type')->fetchColumn() === $beforeMalformed, 'Stale deleted options must fail safely.');
    $vehicleId = (int) $pdo->query("SELECT vehicle_id FROM Vehicle_Alias WHERE alias_key = 'hondaclick125'")->fetchColumn();
    $optionRequest('vehicle', 'update', $vehicleId, 'Honda Click 125i');
    verify_http($pdo->query('SELECT compatibility FROM Products WHERE product_id = 1')->fetchColumn() === 'Honda Click 125i', 'Compatibility corrections must update linked products.');
    $optionRequest('vehicle', 'delete', $vehicleId);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility WHERE vehicle_id = ' . $vehicleId)->fetchColumn() === 1, 'Server must block deletion of used compatibility.');
    $pdo->prepare('UPDATE Vehicle_Compatibility SET is_active = 0 WHERE vehicle_id = ?')->execute([$inlineVehicle['vehicle_id']]);
    $optionRequest('vehicle', 'restore', (int) $inlineVehicle['vehicle_id']);
    verify_http((int) $pdo->query('SELECT is_active FROM Vehicle_Compatibility WHERE vehicle_id = ' . (int) $inlineVehicle['vehicle_id'])->fetchColumn() === 1, 'Existing archived compatibility must remain recoverable.');
    $optionRequest('vehicle', 'update', (int) $inlineVehicle['vehicle_id'], 'Honda Click-125i');
    verify_http($pdo->query('SELECT vehicle_name FROM Vehicle_Compatibility WHERE vehicle_id = ' . (int) $inlineVehicle['vehicle_id'])->fetchColumn() === $inlineName, 'Duplicate compatibility edits must be rejected.');
    $optionRequest('vehicle', 'delete', (int) $inlineVehicle['vehicle_id']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility')->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM Vehicle_Alias WHERE alias_key = 'yamahamiotest'")->fetchColumn() === 0, 'Unused compatibility deletion must remove its canonical lookup.');
    $typeId = (int) $pdo->query("SELECT type_id FROM Product_Type WHERE type_name = 'Tire'")->fetchColumn();
    $optionRequest('type', 'update', $typeId, 'Motorcycle Tire');
    verify_http((int) $pdo->query("SELECT COUNT(*) FROM Products WHERE product_type = 'Motorcycle Tire'")->fetchColumn() === 3, 'Type rename must update every linked product.');
    $optionRequest('type', 'delete', $typeId);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Type WHERE type_id = ' . $typeId)->fetchColumn() === 1, 'Server must block deletion of used product types.');
    $optionRequest('type', 'update', $typeId, 'Oil-Filter');
    verify_http($pdo->query('SELECT type_name FROM Product_Type WHERE type_id = ' . $typeId)->fetchColumn() === 'Motorcycle Tire', 'Duplicate type edits must not modify linked products.');
    $optionRequest('type', 'update', $typeId, 'Tire');
    $optionRequest('type', 'delete', (int) $newType['type_id']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Type')->fetchColumn() === 1, 'Unused product types must be deletable.');
    $optionRequest('type', 'add', 0, 'Oil Filter');
    $optionRequest('type', 'add', 0, 'oil-filter');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Type')->fetchColumn() === 2, 'Manager must add one canonical type and reject duplicate creation.');
    $optionRequest('vehicle', 'add', 0, 'Yamaha Mio');
    verify_http((int) $pdo->query("SELECT COUNT(*) FROM Vehicle_Compatibility WHERE vehicle_name = 'Yamaha Mio'")->fetchColumn() === 1, 'Manager must persist new compatibility.');
    $unusedVehicleId = (int) $pdo->query("SELECT vehicle_id FROM Vehicle_Compatibility WHERE vehicle_name = 'Yamaha Mio'")->fetchColumn();
    $optionRequest('vehicle', 'delete', $unusedVehicleId);
    [, $genericPos] = http_request($base, 'pages/pos.php?q=Honda', $cookies);
    verify_http(!str_contains($genericPos, 'id="recPanel"'), 'Generic compatibility search must not open a recommendation panel.');
    [, $inStockPos] = http_request($base, 'pages/pos.php?q=Dunlop%20Front%20Tire%20Plus', $cookies);
    verify_http(!str_contains($inStockPos, 'id="recPanel"'), 'An available exact product must not open a recommendation panel.');
    [$status, $pos] = http_request($base, 'pages/pos.php?q=Dunlop%20Front%20Tire', $cookies);
    verify_http($status === 200 && str_contains($pos, 'id="recPanel"') && str_contains($pos, 'Alpha alternative'), 'POS must show recommendations despite available Plus variant.');
    $originalCompatibility = $pdo->query('SELECT compatibility FROM Products WHERE product_id = 1')->fetchColumn();
    $pdo->prepare('UPDATE Products SET compatibility = ? WHERE product_id = 1')->execute([$originalCompatibility . ', Yamaha Mio']);
    $pdo->exec("INSERT INTO Vehicle_Compatibility (vehicle_name) VALUES ('Yamaha Mio')");
    $customerOtherId = (int) $pdo->lastInsertId();
    $modelQuery = 'pages/pos.php?q=Dunlop%20Front%20Tire&vehicle_id=' . $vehicleId;
    [, $modelPos] = http_request($base, $modelQuery, $cookies);
    verify_http(str_contains($modelPos, 'id="pos-vehicle-id"') && str_contains($modelPos, 'Alpha alternative'), 'POS selected model must allow alternatives with only one shared model.');
    verify_http(!str_contains($modelPos, 'OLD-P'), 'POS must display the stable product code, not an internal-ID fallback.');
    [, $otherModelPos] = http_request($base, 'pages/pos.php?q=Dunlop%20Front%20Tire&vehicle_id=' . $customerOtherId, $cookies);
    verify_http(str_contains($otherModelPos, 'No suitable alternative available'), 'POS must exclude alternatives incompatible with the selected model.');
    [, $unknownModelPos] = http_request($base, 'pages/pos.php?q=Dunlop%20Front%20Tire&vehicle_id=999999', $cookies);
    verify_http(str_contains($unknownModelPos, 'That motorcycle model is unavailable') && !str_contains($unknownModelPos, 'Alpha alternative'), 'Unknown model must show feedback without falling back to unrestricted matches.');
    [, $malformedModelPos] = http_request($base, 'pages/pos.php?q=Dunlop%20Front%20Tire&vehicle_id[]=1', $cookies);
    verify_http(str_contains($malformedModelPos, 'That motorcycle model is unavailable'), 'Malformed model input must be handled without PHP errors.');
    [, $genericModelPos] = http_request($base, 'pages/pos.php?q=Honda&vehicle_id=' . $vehicleId, $cookies);
    verify_http(!str_contains($genericModelPos, 'id="recPanel"'), 'Model selection must not make generic searches open recommendations.');
    $pdo->prepare('UPDATE Products SET compatibility = ? WHERE product_id = 1')->execute([$originalCompatibility]);
    $pdo->prepare('DELETE FROM Vehicle_Compatibility WHERE vehicle_id = ?')->execute([$customerOtherId]);
    [$status, $inventory] = http_request($base, 'pages/products.php', $cookies);
    $edit = ['csrf_token' => http_csrf($inventory), 'edit_product' => '1', 'product_id' => '1', 'product_name' => 'Dunlop Front Tire', 'category_id' => '1', 'brand' => 'Dunlop', 'price' => '100', 'product_type' => 'Tire', 'specification' => 'Premium 80/90-14', 'fitment' => ['size' => '80/90-14', 'position' => 'front'], 'vehicle_ids' => [$vehicleId], 'current_stock' => '0', 'min_stock_level' => '0'];
    [$status] = http_request($base, 'pages/products.php', $cookies, $edit);
    verify_http($status === 302 && str_contains((string) $pdo->query('SELECT essential_fitment FROM Products WHERE product_id = 1')->fetchColumn(), 'front'), 'Product fitment must save through HTTP.');
    $invalidTypeEdit = $edit;
    $invalidTypeEdit['product_type'] = 'Unregistered type';
    http_request($base, 'pages/products.php', $cookies, $invalidTypeEdit);
    verify_http($pdo->query('SELECT product_type FROM Products WHERE product_id = 1')->fetchColumn() === 'Tire', 'Tampered unregistered product types must not be stored.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Feature_Based_Match WHERE product_id = 1')->fetchColumn() === 0, 'Product edit must automatically rebuild recommendations.');
    [$status, $pos] = http_request($base, 'pages/pos.php?q=Dunlop%20Front%20Tire', $cookies);
    verify_http($status === 200 && str_contains($pos, 'No suitable alternative available'), 'Empty recommendation state must render after fitment change.');
    [$status] = http_request($base, 'pages/product_options.php', $cookies, ['action' => 'add', 'option_kind' => 'vehicle', 'option_name' => 'Unauthorized mutation']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility')->fetchColumn() === 1, 'Missing CSRF must block mutations.');
    $cashierCookies = [];
    [, $login] = http_request($base, 'login.php', $cashierCookies);
    http_request($base, 'login.php', $cashierCookies, ['csrf_token' => http_csrf($login), 'email' => 'http-cashier', 'password' => 'local-test-only']);
    [, $cashierInventory] = http_request($base, 'pages/products.php', $cashierCookies);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Products')->fetchColumn() === 3 && !str_contains($cashierInventory, 'Load Final Product List'), 'Cashiers must not access the final catalog loader.');
    [$status] = http_request($base, 'pages/product_options.php', $cashierCookies);
    verify_http($status === 302, 'Cashier must not access compatibility management.');
    [$status] = http_request($base, 'pages/vehicle_create.php', $cashierCookies, ['csrf_token' => 'invalid', 'vehicle_name' => 'Forbidden']);
    verify_http($status === 403, 'Cashier must not create vehicles inline.');
    [$status] = http_request($base, 'pages/product_type_create.php', $cashierCookies, ['csrf_token' => 'invalid', 'type_name' => 'Forbidden']);
    verify_http($status === 403, 'Cashier must not create product types.');
    $anonymous = [];
    [$status] = http_request($base, 'pages/vehicle_create.php', $anonymous, ['vehicle_name' => 'Anonymous']);
    verify_http($status === 401, 'Anonymous vehicle creation must be blocked.');
    [, $inventory] = http_request($base, 'pages/products.php', $cookies);
    $newProduct = $edit;
    unset($newProduct['edit_product'], $newProduct['product_id']);
    $newProduct['add_product'] = '1';
    $newProduct['product_name'] = 'New Front Tire';
    $newProduct['csrf_token'] = http_csrf($inventory);
    http_request($base, 'pages/products.php', $cookies, $newProduct);
    $newProductId = (int) $pdo->query("SELECT product_id FROM Products WHERE product_name = 'New Front Tire'")->fetchColumn();
    verify_http($newProductId > 3 && $pdo->query('SELECT product_name FROM Products WHERE product_id = 1')->fetchColumn() === 'Dunlop Front Tire', 'Saving product details must create a separate product without changing the source.');
    verify_http((int) $pdo->query('SELECT display_code FROM Products WHERE product_id = ' . $newProductId)->fetchColumn() === 4, 'Saving a new product must allocate the next stable display code.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Stock_Batch WHERE product_id = ' . $newProductId)->fetchColumn() === 0 && (int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = ' . $newProductId)->fetchColumn() === 0, 'Zero-stock product must not copy any batches or quantity.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Vehicle WHERE product_id = ' . $newProductId)->fetchColumn() === 1, 'Compatibility selections must be saved on the new product.');
    verify_http(!str_contains($inventory, 'fitment[mounting]') && !str_contains($inventory, 'fitment[rating]') && !str_contains($inventory, 'Vehicle Compatibility') && !str_contains($inventory, 'openDuplicateProductModal'), 'Removed controls must not appear in Inventory.');
    [, $pos] = http_request($base, 'pages/pos.php', $cookies);
    $checkoutToken = http_csrf($pos);
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'add_to_cart' => '1', 'product_id' => '2', 'qty' => '1']);
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'checkout' => '1', 'payment_method' => 'GCASH']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Sale')->fetchColumn() === 0 && (int) $pdo->query('SELECT quantity_remaining FROM Stock_Batch WHERE product_id = 2')->fetchColumn() === 10, 'Missing GCash reference must not create a sale or consume stock.');
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'checkout' => '1', 'payment_method' => 'GCASH', 'reference_number' => '<script>bad</script>']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Sale')->fetchColumn() === 0, 'Invalid reference must be rejected server-side.');
    $reference = '1234567890123';
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'checkout' => '1', 'payment_method' => 'GCASH', 'reference_number' => $reference]);
    $saleId = (int) $pdo->query('SELECT MAX(sale_id) FROM Sale')->fetchColumn();
    verify_http($saleId > 0 && $pdo->query('SELECT reference_number FROM Sale WHERE sale_id = ' . $saleId)->fetchColumn() === $reference, 'GCash reference must persist with the sale.');
    [, $receipt] = http_request($base, 'pages/pos.php?receipt_id=' . $saleId, $cookies);
    verify_http(str_contains($receipt, 'GCash Reference No.: ' . $reference), 'Owned receipt must display the stored reference.');
    [, $adminHistory] = http_request($base, 'pages/admin_dashboard.php', $cookies);
    verify_http(str_contains($adminHistory, '&quot;reference&quot;:&quot;' . $reference . '&quot;') && str_contains($adminHistory, 'id="rm-reference-row"'), 'Dashboard history must retain GCash references after checkout.');
    [, $cashierHistory] = http_request($base, 'pages/cashier_dashboard.php', $cashierCookies);
    verify_http(!str_contains($cashierHistory, $reference), 'Other cashiers must not see the GCash reference in history.');
    [, $otherReceipt] = http_request($base, 'pages/pos.php?receipt_id=' . $saleId, $cashierCookies);
    verify_http(!str_contains($otherReceipt, $reference), 'Another cashier must not see a private receipt reference.');
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'add_to_cart' => '1', 'product_id' => '2', 'qty' => '1']);
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'checkout' => '1', 'payment_method' => 'CASH', 'reference_number' => 'IGNORED']);
    verify_http($pdo->query('SELECT reference_number FROM Sale ORDER BY sale_id DESC LIMIT 1')->fetchColumn() === null, 'Cash payments must not retain GCash references.');
    [$reportStatus, $report] = http_request($base, 'pages/records.php?tab=sold_items', $cookies);
    verify_http($reportStatus === 200 && str_contains($report, 'P002'), 'Sales reports must use the same display codes as Inventory and POS.');
    verify_http(str_contains($receipt, 'Sales Invoice: SI-'), 'POS receipt must show a sales invoice number.');
    [, $settingsPage] = http_request($base, 'pages/business_settings.php', $cookies);
    http_request($base, 'pages/business_settings.php', $cookies, ['csrf_token' => http_csrf($settingsPage), 'save_property' => '1', 'property_key' => 'material', 'label' => 'Material']);
    verify_http($pdo->query("SELECT label FROM Product_Property WHERE property_key = 'material'")->fetchColumn() === 'Material', 'Custom criteria must persist.');
    verify_http((int) $pdo->query("SELECT affects_matching FROM Product_Property WHERE property_key = 'material'")->fetchColumn() === 0, 'New properties must default to information only.');
    $pdo->exec("UPDATE Products SET essential_fitment = '{\"material\":\"ceramic\"}' WHERE product_id = 3");
    $propertyEdit = ['csrf_token' => http_csrf($settingsPage), 'save_property' => '1', 'original_key' => 'material', 'property_key' => 'pad_material', 'label' => 'Pad material', 'is_active' => '1', 'affects_matching' => '1'];
    http_request($base, 'pages/business_settings.php', $cookies, $propertyEdit);
    verify_http(json_decode($pdo->query('SELECT essential_fitment FROM Products WHERE product_id = 3')->fetchColumn(), true) === ['pad_material' => 'ceramic'], 'Renaming must preserve product values under the new key.');
    [, $propertySettings] = http_request($base, 'pages/business_settings.php', $cookies);
    verify_http(str_contains($propertySettings, 'Edit Property') && str_contains($propertySettings, 'Pad material'), 'Settings must expose explicit property editing.');
    $collision = $propertyEdit; $collision['original_key'] = ''; $collision['label'] = 'Duplicate';
    http_request($base, 'pages/business_settings.php', $cookies, $collision);
    verify_http($pdo->query("SELECT label FROM Product_Property WHERE property_key = 'pad_material'")->fetchColumn() === 'Pad material', 'Duplicate property creation must not overwrite an existing definition.');
    $propertyEdit['original_key'] = 'pad_material'; unset($propertyEdit['is_active']);
    http_request($base, 'pages/business_settings.php', $cookies, $propertyEdit);
    verify_http((int) $pdo->query("SELECT is_active FROM Product_Property WHERE property_key = 'pad_material'")->fetchColumn() === 0 && str_contains($pdo->query('SELECT essential_fitment FROM Products WHERE product_id = 3')->fetchColumn(), 'ceramic'), 'Retirement must preserve saved values.');
    [, $retiredInventory] = http_request($base, 'pages/products.php', $cookies);
    verify_http(!str_contains($retiredInventory, 'name="fitment[pad_material]"'), 'Retired properties must disappear from editable product fields.');
    $retiredEdit = $edit; $retiredEdit['product_id'] = '3'; $retiredEdit['product_name'] = 'Alpha alternative'; $retiredEdit['fitment'] = ['size' => '80/90-14']; $retiredEdit['csrf_token'] = http_csrf($retiredInventory);
    http_request($base, 'pages/products.php', $cookies, $retiredEdit);
    verify_http((json_decode($pdo->query('SELECT essential_fitment FROM Products WHERE product_id = 3')->fetchColumn(), true)['pad_material'] ?? '') === 'ceramic', 'Normal product edits must preserve retired property values.');
    $pdo->exec("UPDATE Products SET essential_fitment = '{\"pad_material\":\"ceramic\"}' WHERE product_id = 3");
    $conflict = $propertyEdit; $conflict['property_key'] = 'size'; $conflict['is_active'] = '1';
    http_request($base, 'pages/business_settings.php', $cookies, $conflict);
    verify_http((int) $pdo->query("SELECT COUNT(*) FROM Product_Property WHERE property_key = 'pad_material'")->fetchColumn() === 1, 'Built-in property keys cannot be overwritten.');

    $pdo->exec("UPDATE Products SET essential_fitment = '{\"pad_material\":\"first value\"}' WHERE product_id = 1");
    $pdo->exec("UPDATE Products SET essential_fitment = '{\"pad_material\":\"ceramic\",\"conflict_key\":\"keep me\"}' WHERE product_id = 3");
    $renameConflict = $propertyEdit; $renameConflict['property_key'] = 'conflict_key';
    http_request($base, 'pages/business_settings.php', $cookies, $renameConflict);
    verify_http(array_key_exists('pad_material', json_decode($pdo->query('SELECT essential_fitment FROM Products WHERE product_id = 1')->fetchColumn(), true)) && (int) $pdo->query("SELECT COUNT(*) FROM Product_Property WHERE property_key = 'pad_material'")->fetchColumn() === 1, 'A conflicting product value must roll back all earlier product renames and the definition.');
    $pdo->exec("UPDATE Products SET essential_fitment = '{}' WHERE product_id = 1");
    $pdo->exec("UPDATE Products SET essential_fitment = '{\"pad_material\":\"ceramic\"}' WHERE product_id = 3");
    $pdo->exec("UPDATE Products SET product_status = 'ARCHIVED' WHERE product_id = 3");
    $propertyEdit['property_key'] = 'material'; $propertyEdit['label'] = 'Material'; $propertyEdit['is_active'] = '1'; unset($propertyEdit['affects_matching']);
    http_request($base, 'pages/business_settings.php', $cookies, $propertyEdit);
    verify_http(json_decode($pdo->query('SELECT essential_fitment FROM Products WHERE product_id = 3')->fetchColumn(), true) === ['material' => 'ceramic'], 'Renaming must migrate archived products too.');
    $pdo->exec("UPDATE Products SET product_status = 'ACTIVE' WHERE product_id = 3");

    http_request($base, 'pages/business_settings.php', $cookies, ['csrf_token' => http_csrf($settingsPage), 'save_business' => '1', 'business_name' => 'Test Store', 'address' => 'Test Address', 'phone' => '12345']);
    [, $savedSettings] = http_request($base, 'pages/business_settings.php', $cookies);
    verify_http(str_contains($savedSettings, 'value="Test Store"') && $pdo->query('SELECT business_name FROM Business_Settings')->fetchColumn() === 'Test Store', 'Business branding must save and reload.');
    $uploadLogo = static function (string $contents) use ($base, &$cookies, $savedSettings): void {
        $boundary = 'InventoryLogoTestBoundary'; $body = '';
        foreach (['csrf_token' => http_csrf($savedSettings), 'save_business' => '1', 'business_name' => 'Test Store', 'address' => 'Test Address', 'phone' => '12345'] as $name => $value) $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"logo\"; filename=\"logo.png\"\r\nContent-Type: image/png\r\n\r\n" . $contents . "\r\n--$boundary--\r\n";
        $cookie = implode('; ', array_map(static fn($key, $value) => "$key=$value", array_keys($cookies), $cookies));
        file_get_contents($base . '/php-inventory/pages/business_settings.php', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Cookie: $cookie\r\nContent-Type: multipart/form-data; boundary=$boundary\r\n", 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0]]));
    };
    $uploadLogo(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS8sAAAAASUVORK5CYII='));
    $savedLogo = $pdo->query('SELECT logo FROM Business_Settings WHERE setting_id = 1')->fetchColumn();
    verify_http(str_starts_with($savedLogo, 'data:image/png;base64,'), 'Validated PNG logo must persist as image data.');
    $uploadLogo('<?php echo "unsafe"; ?>');
    verify_http($pdo->query('SELECT logo FROM Business_Settings WHERE setting_id = 1')->fetchColumn() === $savedLogo, 'Disguised executable logo must be rejected without replacing the saved logo.');


    [, $inventory] = http_request($base, 'pages/products.php', $cookies);
    verify_http(str_contains($inventory, 'fitment[material]') && !str_contains($inventory, 'name="current_stock"'), 'Inventory must show custom properties and remove direct quantity input.');
    [$forbiddenSettings] = http_request($base, 'pages/business_settings.php', $cashierCookies);
    verify_http($forbiddenSettings === 302, 'Cashier must not edit business settings.');
    [$csvStatus, $csv] = http_request($base, 'pages/inventory_export.php?download=csv', $cookies);
    verify_http($csvStatus === 200 && str_contains($csv, 'P002') && str_contains($csv, 'Product'), 'Inventory download must contain real product codes.');
    [$printStatus, $printPage] = http_request($base, 'pages/inventory_export.php', $cookies);
    verify_http($printStatus === 200 && str_contains($printPage, 'Print / Save PDF'), 'Inventory must have a printable list.');
    [, $stockIn] = http_request($base, 'pages/stock_in.php', $cookies);
    $stockPayload = ['csrf_token' => http_csrf($stockIn), 'product_id' => $newProductId, 'quantity' => '2', 'cost' => '10', 'batch_number' => 'REVISION-STOCK', 'manufacturing_date' => '', 'expiration_date' => ''];
    http_request($base, 'pages/stock_in.php', $cookies, $stockPayload);
    verify_http((int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = ' . $newProductId)->fetchColumn() === 2, 'Stock-in must create a batch and update stock.');
    $stockPayload['quantity'] = '-2'; http_request($base, 'pages/stock_in.php', $cookies, $stockPayload);
    verify_http((int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = ' . $newProductId)->fetchColumn() === 2, 'Invalid stock-in must not change quantities.');
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'add_to_cart' => '1', 'product_id' => $newProductId, 'qty' => '1']);
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'add_to_cart' => '1', 'product_id' => '3', 'qty' => '1']);
    http_request($base, 'pages/pos.php', $cookies, ['csrf_token' => $checkoutToken, 'checkout' => '1', 'payment_method' => 'CASH']);
    $returnSaleId = (int) $pdo->query('SELECT MAX(sale_id) FROM Sale')->fetchColumn();
    $returnItemId = (int) $pdo->query('SELECT sale_item_id FROM Sale_Item WHERE sale_id = ' . $returnSaleId . ' AND product_id = ' . $newProductId)->fetchColumn();
    $otherReturnItem = (int) $pdo->query('SELECT sale_item_id FROM Sale_Item WHERE sale_id = ' . $returnSaleId . ' AND product_id = 3')->fetchColumn();
    [, $history] = http_request($base, 'pages/admin_dashboard.php', $cookies);
    $returnPayload = ['csrf_token' => http_csrf($history), 'return_sale' => '1', 'sale_id' => $returnSaleId, 'return_reason' => 'Wrong Item Received', 'return_destination' => 'STOCK', 'return_items' => [$returnItemId]];
    http_request($base, 'pages/admin_dashboard.php', $cookies, $returnPayload);
    verify_http((int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = ' . $newProductId)->fetchColumn() === 2 && $pdo->query('SELECT status FROM Sale WHERE sale_id = ' . $returnSaleId)->fetchColumn() === 'COMPLETED', 'Partial return must restore only selected items and preserve remaining sale.');
    http_request($base, 'pages/admin_dashboard.php', $cookies, $returnPayload);
    verify_http((int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = ' . $newProductId)->fetchColumn() === 2, 'Repeated return must not restore stock twice.');
    $otherStock = (int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = 3')->fetchColumn();
    $returnPayload['return_items'] = [$otherReturnItem]; $returnPayload['return_destination'] = 'QUARANTINE'; $returnPayload['return_reason'] = 'Damaged Item';
    http_request($base, 'pages/admin_dashboard.php', $cookies, $returnPayload);
    verify_http((int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = 3')->fetchColumn() === $otherStock && $pdo->query('SELECT status FROM Sale WHERE sale_id = ' . $returnSaleId)->fetchColumn() === 'RETURNED', 'Quarantined return must not restock and final line must mark sale returned.');
    [, $returnedReport] = http_request($base, 'pages/records.php?tab=cancelled_orders', $cookies);
    verify_http(str_contains($returnedReport, 'Damaged Item') && str_contains($returnedReport, 'Wrong Item Received'), 'Returned reports must display saved reasons, including partial returns.');
    [, $batchPage] = http_request($base, 'pages/product_batches.php?product_id=' . $newProductId, $cookies);
    verify_http(str_contains($batchPage, 'Product added:') && str_contains($batchPage, 'Edited:'), 'Batch screen must expose product and batch audit dates.');
    [, $inventory] = http_request($base, 'pages/products.php', $cookies);
    http_request($base, 'pages/products.php', $cookies, ['csrf_token' => http_csrf($inventory), 'archive_product' => '1', 'product_id' => '2']);
    [$archiveStatus, $archivePage] = http_request($base, 'pages/product_archives.php', $cookies);
    verify_http($archiveStatus === 200 && str_contains($archivePage, 'Dunlop Front Tire Plus') && str_contains($archivePage, 'Restore before'), 'Archive list must show the archived product and restoration deadline.');
    http_request($base, 'pages/product_archives.php', $cookies, ['restore_product' => '1', 'product_id' => '2']);
    verify_http($pdo->query('SELECT product_status FROM Products WHERE product_id = 2')->fetchColumn() === 'ARCHIVED', 'Restore must enforce CSRF.');
    http_request($base, 'pages/product_archives.php', $cashierCookies, ['csrf_token' => 'invalid', 'restore_product' => '1', 'product_id' => '2']);
    verify_http($pdo->query('SELECT product_status FROM Products WHERE product_id = 2')->fetchColumn() === 'ARCHIVED', 'Cashiers must not restore archived products.');
    http_request($base, 'pages/product_archives.php', $cookies, ['csrf_token' => http_csrf($archivePage), 'restore_product' => '1', 'product_id' => '2']);
    verify_http($pdo->query('SELECT product_status FROM Products WHERE product_id = 2')->fetchColumn() === 'ACTIVE' && (int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = 2')->fetchColumn() === 8, 'Restoration must preserve remaining batch stock.');
    $pdo->exec("UPDATE Products SET product_status = 'ARCHIVED', archived_at = DATE_SUB(NOW(), INTERVAL 31 DAY), purge_after = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE product_id = 3");
    http_request($base, 'pages/product_archives.php', $cookies, ['csrf_token' => http_csrf($archivePage), 'restore_product' => '1', 'product_id' => '3']);
    verify_http($pdo->query('SELECT product_status FROM Products WHERE product_id = 3')->fetchColumn() === 'DELETED', 'Expired archives must not be restorable.');
    [, $inventory] = http_request($base, 'pages/products.php', $cookies);
    http_request($base, 'pages/products.php', $cookies, ['csrf_token' => http_csrf($inventory), 'archive_product' => '1', 'product_id' => '1']);
    verify_http($pdo->query('SELECT product_status FROM Products WHERE product_id = 1')->fetchColumn() === 'ARCHIVED', 'Archive action must preserve the product record.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Feature_Based_Match WHERE product_id = 1 OR alternative_product_id = 1')->fetchColumn() === 0, 'Archive must clear product recommendations.');
    $optionRequest('type', 'add', 0, 'Archived Type');
    $archivedTypeId = (int) $pdo->query("SELECT type_id FROM Product_Type WHERE type_name = 'Archived Type'")->fetchColumn();
    $pdo->exec("UPDATE Products SET product_type = 'Archived Type' WHERE product_id = 1");
    $optionRequest('type', 'delete', $archivedTypeId);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Type WHERE type_id = ' . $archivedTypeId)->fetchColumn() === 1, 'An archived product alone must protect its type from deletion.');
    $optionRequest('type', 'update', $archivedTypeId, 'Archived Corrected');
    verify_http($pdo->query('SELECT product_type FROM Products WHERE product_id = 1')->fetchColumn() === 'Archived Corrected', 'Type renames must update archived products.');
    $optionRequest('vehicle', 'add', 0, 'Archived Model');
    $archivedModelId = (int) $pdo->query("SELECT vehicle_id FROM Vehicle_Compatibility WHERE vehicle_name = 'Archived Model'")->fetchColumn();
    $pdo->exec('DELETE FROM Product_Vehicle WHERE product_id = 1');
    $pdo->prepare('INSERT INTO Product_Vehicle (product_id, vehicle_id) VALUES (1, ?)')->execute([$archivedModelId]);
    $optionRequest('vehicle', 'delete', $archivedModelId);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility WHERE vehicle_id = ' . $archivedModelId)->fetchColumn() === 1, 'An archived product alone must protect its compatibility from deletion.');
    $optionRequest('vehicle', 'update', $archivedModelId, 'Archived Model Corrected');
    verify_http($pdo->query('SELECT compatibility FROM Products WHERE product_id = 1')->fetchColumn() === 'Archived Model Corrected', 'Compatibility renames must update archived products.');
    [, $categoryPage] = http_request($base, 'pages/category.php', $cookies);
    http_request($base, 'pages/category.php', $cookies, ['csrf_token' => http_csrf($categoryPage), 'delete_category' => '1', 'category_id' => '1']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Feature_Based_Match')->fetchColumn() === 0, 'Category deletion must rebuild and clear invalid matches.');
    verify_http(!preg_match('/(?:PHP )?(?:Fatal error|Warning|Parse error):/', file_get_contents($log)), 'HTTP pages must not emit PHP errors.');
    echo "Passed {$checks} HTTP and migration checks.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec('DROP DATABASE `' . $database . '`');
    if (is_file($log)) unlink($log);
}
