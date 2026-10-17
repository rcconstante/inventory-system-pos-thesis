<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function product_type_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}
if (!is_logged_in()) product_type_response(401, ['error' => 'Your session expired. Sign in again.']);
if (!can_manage_catalog()) product_type_response(403, ['error' => 'You cannot manage product types.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); product_type_response(405, ['error' => 'Use POST to add a product type.']); }
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) product_type_response(403, ['error' => 'Refresh the page before adding a product type.']);
$name = $_POST['type_name'] ?? null;
if (!is_string($name) || trim($name) === '' || strlen($name) > 50) product_type_response(422, ['error' => 'Enter a product type of up to 50 characters.']);
$recent = array_values(array_filter($_SESSION['type_create_times'] ?? [], static fn (int $timestamp): bool => $timestamp > time() - 60));
if (count($recent) >= 30) { header('Retry-After: 60'); product_type_response(429, ['error' => 'Wait a minute before adding another type.']); }
$recent[] = time(); $_SESSION['type_create_times'] = $recent;
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/domain.php';
try {
    $pdo->beginTransaction();
    $type = save_product_type_option($pdo, $name);
    $pdo->commit();
    product_type_response(200, ['type' => ['type_id' => (int) $type['type_id'], 'type_name' => $type['type_name']]]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Product type creation failed: ' . $exception->getMessage());
    product_type_response(409, ['error' => 'The product type could not be saved. Try searching for it or retry.']);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    product_type_response(422, ['error' => $exception->getMessage()]);
}
