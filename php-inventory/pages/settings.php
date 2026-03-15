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
    if ($settingsAction === 'update_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new RuntimeException('Please complete all password fields.');
        }

        if (!password_verify($currentPassword, (string) $currentUser['password'])) {
            throw new RuntimeException('Your current password is incorrect.');
        }

        if (strlen($newPassword) < 8) {
            throw new RuntimeException('Your new password must be at least 8 characters long.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('Your new password confirmation does not match.');
        }

        $update = $pdo->prepare('UPDATE User SET password = :password WHERE user_id = :user_id');
        $update->execute([
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'user_id' => $currentUserId,
        ]);

        set_flash('success', 'Your password was updated successfully.');
    } elseif ($settingsAction === 'update_preferences') {
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
