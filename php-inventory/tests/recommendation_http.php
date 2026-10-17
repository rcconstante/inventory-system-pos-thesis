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
        (3, 'Alpha alternative', 100, 1, 'Tire', '80/90-14 tubeless', 'Honda Clik 125', 'Dunlop')");
    $pdo->exec('INSERT INTO Inventory (product_id, current_stock) VALUES (1, 0), (2, 10), (3, 10)');
    $pdo->exec("INSERT INTO Stock_Batch (product_id, batch_number, quantity_received, quantity_remaining) VALUES (2, 'PLUS', 10, 10), (3, 'ALPHA', 10, 10)");
    // Exercise upgrade from the previous schema, then repeat it to prove idempotency.
    $pdo->exec('ALTER TABLE Products DROP COLUMN essential_fitment');
    $pdo->exec('DROP TABLE Product_Vehicle, Vehicle_Alias, Vehicle_Compatibility, Recommendation_State, Product_Type');
    preg_match('/host=([^;]+)/', $dsn, $host);
    preg_match('/port=(\d+)/', $dsn, $port);
    $env = array_merge(getenv(), [
        'INVENTORY_DB_HOST' => $host[1] ?? '127.0.0.1', 'INVENTORY_DB_PORT' => $port[1] ?? '3306',
        'INVENTORY_DB_NAME' => $database, 'INVENTORY_DB_USER' => $user, 'INVENTORY_DB_PASS' => $password,
    ]);
    $phpCommand = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql'];
    for ($run = 0; $run < 2; $run++) {
        $pipes = [];
        $migration = proc_open(array_merge($phpCommand, [__DIR__ . '/../migrations/recommendation-fitment.php']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        verify_http(proc_close($migration) === 0, 'Migration failed: ' . $error . $output);
    }
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility')->fetchColumn() === 2, 'Migration must consolidate formatting variants without guessing typos.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Vehicle')->fetchColumn() === 3, 'Migration must preserve all assignments across reruns.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Type')->fetchColumn() === 1 && $pdo->query('SELECT product_type FROM Products WHERE product_id = 2')->fetchColumn() === 'Tire', 'Migration must consolidate existing product types and preserve canonical values.');
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
    [$status] = http_request($base, 'login.php', $cookies, ['csrf_token' => http_csrf($login), 'email' => 'http-admin', 'password' => 'local-test-only']);
    verify_http($status === 302, 'Admin login must succeed.');
    [$status, $inventory] = http_request($base, 'pages/products.php', $cookies);
    verify_http($status === 200 && str_contains($inventory, 'Manage Compatibility') && str_contains($inventory, 'name="vehicle_ids[]"'), 'Inventory must render new controls.');
    verify_http(str_contains($inventory, 'data-catalog-picker="type"') && str_contains($inventory, 'Add New Product Type') && str_contains($inventory, 'data-picker-apply') && str_contains($inventory, 'openDuplicateProductModal(this)'), 'Inventory must expose single-type and multiple-vehicle dropdowns with inline creation and Apply.');
    $inlineToken = http_csrf($inventory);
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
    // Remove this isolated, unassigned fixture so legacy manager assertions remain meaningful.
    $pdo->prepare('DELETE FROM Vehicle_Compatibility WHERE vehicle_id = ?')->execute([$inlineVehicle['vehicle_id']]);
    [$status, $manager] = http_request($base, 'pages/compatibility.php', $cookies);
    verify_http($status === 200 && str_contains($manager, 'Move Assignments and Remove Duplicate'), 'Compatibility manager must render.');
    $vehicleId = (int) $pdo->query("SELECT vehicle_id FROM Vehicle_Alias WHERE alias_key = 'hondaclick125'")->fetchColumn();
    $typoId = (int) $pdo->query("SELECT vehicle_id FROM Vehicle_Alias WHERE alias_key = 'hondaclik125'")->fetchColumn();
    [$status] = http_request($base, 'pages/compatibility.php', $cookies, ['csrf_token' => http_csrf($manager), 'vehicle_id' => $vehicleId, 'vehicle_name' => 'Honda Click 125i', 'aliases' => 'Honda Click 125', 'save_vehicle' => '1']);
    verify_http($status === 302 && $pdo->query('SELECT compatibility FROM Products WHERE product_id = 1')->fetchColumn() === 'Honda Click 125i', 'Vehicle name corrections must update existing products through HTTP.');
    http_request($base, 'pages/compatibility.php', $cookies, ['csrf_token' => http_csrf($manager), 'vehicle_id' => $vehicleId, 'archive_vehicle' => '1']);
    verify_http((int) $pdo->query('SELECT is_active FROM Vehicle_Compatibility WHERE vehicle_id = ' . $vehicleId)->fetchColumn() === 0, 'Vehicle archive action must work.');
    http_request($base, 'pages/compatibility.php', $cookies, ['csrf_token' => http_csrf($manager), 'vehicle_id' => $vehicleId, 'restore_vehicle' => '1']);
    verify_http((int) $pdo->query('SELECT is_active FROM Vehicle_Compatibility WHERE vehicle_id = ' . $vehicleId)->fetchColumn() === 1, 'Vehicle restore action must work.');
    [$status] = http_request($base, 'pages/compatibility.php', $cookies, ['csrf_token' => http_csrf($manager), 'vehicle_id' => $typoId, 'merge_target' => $vehicleId, 'merge_vehicle' => '1']);
    verify_http($status === 302 && (int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility')->fetchColumn() === 1, 'Duplicate must merge through authenticated HTTP action.');
    [$status, $pos] = http_request($base, 'pages/pos.php?q=Dunlop%20Front%20Tire', $cookies);
    verify_http($status === 200 && str_contains($pos, 'id="recPanel"') && str_contains($pos, 'Alpha alternative'), 'POS must show recommendations despite available Plus variant.');
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
    [$status] = http_request($base, 'pages/compatibility.php', $cookies, ['save_vehicle' => '1', 'vehicle_name' => 'Unauthorized mutation']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Vehicle_Compatibility')->fetchColumn() === 1, 'Missing CSRF must block mutations.');
    $cashierCookies = [];
    [, $login] = http_request($base, 'login.php', $cashierCookies);
    http_request($base, 'login.php', $cashierCookies, ['csrf_token' => http_csrf($login), 'email' => 'http-cashier', 'password' => 'local-test-only']);
    [$status] = http_request($base, 'pages/compatibility.php', $cashierCookies);
    verify_http($status === 302, 'Cashier must not access compatibility management.');
    [$status] = http_request($base, 'pages/vehicle_create.php', $cashierCookies, ['csrf_token' => 'invalid', 'vehicle_name' => 'Forbidden']);
    verify_http($status === 403, 'Cashier must not create vehicles inline.');
    [$status] = http_request($base, 'pages/product_type_create.php', $cashierCookies, ['csrf_token' => 'invalid', 'type_name' => 'Forbidden']);
    verify_http($status === 403, 'Cashier must not create product types.');
    $anonymous = [];
    [$status] = http_request($base, 'pages/vehicle_create.php', $anonymous, ['vehicle_name' => 'Anonymous']);
    verify_http($status === 401, 'Anonymous vehicle creation must be blocked.');
    [, $inventory] = http_request($base, 'pages/products.php', $cookies);
    $copy = $edit;
    unset($copy['edit_product'], $copy['product_id']);
    $copy['add_product'] = '1';
    $copy['product_name'] = 'Dunlop Front Tire (Copy)';
    $copy['csrf_token'] = http_csrf($inventory);
    http_request($base, 'pages/products.php', $cookies, $copy);
    $copyId = (int) $pdo->query("SELECT product_id FROM Products WHERE product_name = 'Dunlop Front Tire (Copy)'")->fetchColumn();
    verify_http($copyId > 3 && $pdo->query('SELECT product_name FROM Products WHERE product_id = 1')->fetchColumn() === 'Dunlop Front Tire', 'Saving copied details must create a separate product without changing the source.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Stock_Batch WHERE product_id = ' . $copyId)->fetchColumn() === 0 && (int) $pdo->query('SELECT current_stock FROM Inventory WHERE product_id = ' . $copyId)->fetchColumn() === 0, 'Zero-stock duplicate must not copy any batches or quantity.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Product_Vehicle WHERE product_id = ' . $copyId)->fetchColumn() === 1, 'Copied vehicle selections must be saved on the new product.');
    [, $inventory] = http_request($base, 'pages/products.php', $cookies);
    http_request($base, 'pages/products.php', $cookies, ['csrf_token' => http_csrf($inventory), 'archive_product' => '1', 'product_id' => '1']);
    verify_http($pdo->query('SELECT product_status FROM Products WHERE product_id = 1')->fetchColumn() === 'ARCHIVED', 'Archive action must preserve the product record.');
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Feature_Based_Match WHERE product_id = 1 OR alternative_product_id = 1')->fetchColumn() === 0, 'Archive must clear product recommendations.');
    [, $categoryPage] = http_request($base, 'pages/category.php', $cookies);
    http_request($base, 'pages/category.php', $cookies, ['csrf_token' => http_csrf($categoryPage), 'delete_category' => '1', 'category_id' => '1']);
    verify_http((int) $pdo->query('SELECT COUNT(*) FROM Feature_Based_Match')->fetchColumn() === 0, 'Category deletion must rebuild and clear invalid matches.');
    verify_http(!preg_match('/PHP (?:Fatal error|Warning|Parse error)/', file_get_contents($log)), 'HTTP pages must not emit PHP errors.');
    echo "Passed {$checks} HTTP and migration checks.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec('DROP DATABASE `' . $database . '`');
    if (is_file($log)) unlink($log);
}
