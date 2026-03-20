<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(dashboard_path_for_role((int) current_role_id()));
}

validate_csrf_or_fail(dashboard_path_for_role((int) current_role_id()));

$settingsAction = $_POST['settings_action'] ?? '';
$currentUserId = (int) current_user_id();
$currentUser = fetch_user_by_id($pdo, $currentUserId);

if ($currentUser === null) {
    session_unset();
    session_destroy();
    redirect_to('index.php');
}

try {
    if ($settingsAction === 'update_preferences') {
        $existingPreferences = get_preferences();
        save_preferences([
            'show_recommendations' => !empty($_POST['show_recommendations']),
            'default_payment_method' => $existingPreferences['default_payment_method'] ?? 'CASH',
        ]);

        set_flash('success', 'Your settings were updated successfully.');
    } else {
        throw new RuntimeException('Unknown settings action.');
    }
} catch (RuntimeException $exception) {
    set_flash('error', $exception->getMessage());
}

redirect_back(dashboard_path_for_role((int) current_role_id()));
