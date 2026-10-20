<?php
declare(strict_types=1);
require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';
require_login([APP_ROLE_ADMIN, APP_ROLE_STAFF]);
$returnPath = 'pages/products.php?' . http_build_query([
    'open_options' => '1',
    'search' => is_string($_POST['return_search'] ?? null) ? substr($_POST['return_search'], 0, 150) : '',
    'category_id' => max(0, (int) filter_var($_POST['return_category_id'] ?? 0, FILTER_VALIDATE_INT)),
    'page' => max(1, (int) filter_var($_POST['return_page'] ?? 1, FILTER_VALIDATE_INT)),
]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['product_options_notice'] = ['type' => 'error', 'message' => 'Your form expired. Please try again.'];
        redirect_to($returnPath);
    }
    unset($_SESSION['product_options_draft']);
    $id = 0;
    try {
        if (!can_manage_catalog()) throw new RuntimeException('You cannot manage product options.');
        $kind = $_POST['option_kind'] ?? null;
        $action = $_POST['action'] ?? null;
        if (!in_array($kind, ['vehicle', 'type'], true) || !in_array($action, ['add', 'update', 'delete', 'restore'], true)) throw new RuntimeException('Choose a valid product option action.');
        $id = filter_var($_POST['option_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name = $_POST['option_name'] ?? '';
        if (!is_string($name)) throw new RuntimeException('Enter a valid name.');
        if ($action !== 'add' && !$id) throw new RuntimeException('Choose an existing option.');
        $pdo->beginTransaction();
        if ($action === 'delete') {
            if (!can_delete_catalog()) throw new RuntimeException('You cannot delete product options.');
            delete_product_option($pdo, $kind, $id);
        } elseif ($action === 'restore') {
            if ($kind !== 'vehicle') throw new RuntimeException('Invalid restore action.');
            $restore = $pdo->prepare('UPDATE Vehicle_Compatibility SET is_active = 1 WHERE vehicle_id = ? AND is_active = 0');
            $restore->execute([$id]);
            if ($restore->rowCount() !== 1) throw new RuntimeException('The archived entry was not found.');
        } elseif ($kind === 'vehicle') {
            save_vehicle_option($pdo, $action === 'add' ? null : $id, $name);
        } elseif ($action === 'add') {
            $existing = $pdo->prepare('SELECT type_id FROM Product_Type WHERE type_key = ?');
            $existing->execute([vehicle_name_key($name)]);
            if ($existing->fetchColumn() !== false) throw new RuntimeException('That product type already exists.');
            save_product_type_option($pdo, $name);
        } else update_product_type_option($pdo, $id, $name);
        sync_feature_matches_for_catalog($pdo);
        $pdo->commit();
        $_SESSION['product_options_notice'] = ['type' => 'success', 'message' => $action === 'delete' ? 'Option deleted.' : ($action === 'add' ? 'Option added.' : 'Option updated. Linked products are up to date.')];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Product option update failed: ' . $exception->getMessage());
        $_SESSION['product_options_notice'] = ['type' => 'error', 'message' => 'The option could not be saved. Check for an existing name and try again.'];
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['product_options_notice'] = ['type' => 'error', 'message' => $exception->getMessage()];
    }
    if (($_SESSION['product_options_notice']['type'] ?? '') === 'error') {
        $_SESSION['product_options_draft'] = [
            'action' => is_string($_POST['action'] ?? null) ? $_POST['action'] : '',
            'kind' => is_string($_POST['option_kind'] ?? null) ? $_POST['option_kind'] : '',
            'id' => (int) $id,
            'name' => is_string($_POST['option_name'] ?? null) ? substr($_POST['option_name'], 0, 100) : '',
        ];
    }
    redirect_to($returnPath);
}

redirect_to($returnPath);
