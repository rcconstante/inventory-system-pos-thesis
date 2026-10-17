<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vehicle_create_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

if (!is_logged_in()) vehicle_create_response(401, ['error' => 'Your session expired. Sign in again.']);
if (!can_manage_catalog()) vehicle_create_response(403, ['error' => 'You cannot manage compatibility records.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    vehicle_create_response(405, ['error' => 'Use POST to add a vehicle.']);
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) vehicle_create_response(403, ['error' => 'Refresh the page before adding a vehicle.']);
$name = $_POST['vehicle_name'] ?? null;
if (!is_string($name) || strlen($name) > 100 || trim($name) === '') vehicle_create_response(422, ['error' => 'Enter a vehicle name of up to 100 characters.']);
$recentRequests = array_values(array_filter($_SESSION['vehicle_create_times'] ?? [], static fn (int $timestamp): bool => $timestamp > time() - 60));
if (count($recentRequests) >= 30) {
    header('Retry-After: 60');
    vehicle_create_response(429, ['error' => 'Too many requests. Wait a minute before adding another vehicle.']);
}
$recentRequests[] = time();
$_SESSION['vehicle_create_times'] = $recentRequests;
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/domain.php';
if (str_contains($name, ',') || vehicle_name_key($name) === '') vehicle_create_response(422, ['error' => 'Enter one vehicle name without commas.']);
try {
    $pdo->beginTransaction();
    $lookup = $pdo->prepare('SELECT v.vehicle_id, v.vehicle_name, v.is_active FROM Vehicle_Alias a JOIN Vehicle_Compatibility v ON v.vehicle_id = a.vehicle_id WHERE a.alias_key = ? FOR UPDATE');
    $lookup->execute([vehicle_name_key($name)]);
    $vehicle = $lookup->fetch(PDO::FETCH_ASSOC);
    $created = false;
    if ($vehicle) {
        if (!$vehicle['is_active']) {
            $pdo->rollBack();
            vehicle_create_response(409, ['error' => 'This vehicle is archived. Restore it in Manage Compatibility first.']);
        }
    } else {
        $id = save_vehicle_option($pdo, null, $name, '');
        $lookup = $pdo->prepare('SELECT vehicle_id, vehicle_name, is_active FROM Vehicle_Compatibility WHERE vehicle_id = ?');
        $lookup->execute([$id]);
        $vehicle = $lookup->fetch(PDO::FETCH_ASSOC);
        $created = true;
    }
    $pdo->commit();
    vehicle_create_response($created ? 201 : 200, ['vehicle' => ['vehicle_id' => (int) $vehicle['vehicle_id'], 'vehicle_name' => $vehicle['vehicle_name'], 'is_active' => true], 'created' => $created]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Inline vehicle creation failed: ' . $exception->getMessage());
    vehicle_create_response(409, ['error' => 'The vehicle could not be saved. Search for an existing entry or try again.']);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    vehicle_create_response(422, ['error' => $exception->getMessage()]);
}
