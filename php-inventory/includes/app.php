<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_name('inventory_pos_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

const APP_ROLE_ADMIN = 1;
const APP_ROLE_CASHIER = 2;
const APP_ROLE_STAFF = 3;

function is_https_request(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function app_base_url(): string
{
    static $baseUrl = null;

    if ($baseUrl !== null) {
        return $baseUrl;
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $marker = '/php-inventory/';
    $markerPosition = strpos($scriptName, $marker);

    if ($markerPosition !== false) {
        $baseUrl = substr($scriptName, 0, $markerPosition + strlen('/php-inventory'));
        return rtrim($baseUrl, '/');
    }

    $baseUrl = rtrim(dirname($scriptName), '/');
    return $baseUrl === '' ? '/php-inventory' : $baseUrl;
}

function app_url(string $path = ''): string
{
    $baseUrl = app_base_url();
    $normalizedPath = ltrim($path, '/');

    if ($normalizedPath === '') {
        return $baseUrl;
    }

    return $baseUrl . '/' . $normalizedPath;
}

function redirect_to(string $path): never
{
    header('Location: ' . app_url($path));
    exit();
}

function redirect_back(string $fallback = 'index.php'): never
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $baseUrl = app_base_url();

    if ($referer !== '' && strpos($referer, $baseUrl) === 0) {
        header('Location: ' . $referer);
        exit();
    }

    redirect_to($fallback);
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money_format_php(float $amount): string
{
    return number_format($amount, 2);
}

function set_flash(string $type, string $message): void
{
    if (!isset($_SESSION['flash_messages']) || !is_array($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }

    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function pull_flash_messages(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);

    return is_array($messages) ? $messages : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function validate_csrf_or_fail(string $fallback = 'index.php'): void
{
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
        set_flash('error', 'Your session expired. Please try again.');
        redirect_back($fallback);
    }
}

function role_name(int $roleId): string
{
    return match ($roleId) {
        APP_ROLE_ADMIN => 'Admin',
        APP_ROLE_CASHIER => 'Cashier',
        APP_ROLE_STAFF => 'Staff',
        default => 'User',
    };
}

function role_slug_to_id(string $roleSlug): ?int
{
    return match (strtolower(trim($roleSlug))) {
        'admin' => APP_ROLE_ADMIN,
        'cashier' => APP_ROLE_CASHIER,
        'staff' => APP_ROLE_STAFF,
        default => null,
    };
}

function role_id_to_slug(int $roleId): string
{
    return match ($roleId) {
        APP_ROLE_ADMIN => 'admin',
        APP_ROLE_CASHIER => 'cashier',
        APP_ROLE_STAFF => 'staff',
        default => 'user',
    };
}

function dashboard_path_for_role(int $roleId): string
{
    return match ($roleId) {
        APP_ROLE_ADMIN => 'pages/admin_dashboard.php',
        APP_ROLE_CASHIER => 'pages/cashier_dashboard.php',
        APP_ROLE_STAFF => 'pages/staff_dashboard.php',
        default => 'index.php',
    };
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_role_id(): ?int
{
    return isset($_SESSION['role_id']) ? (int) $_SESSION['role_id'] : null;
}

function has_any_role(array $allowedRoleIds): bool
{
    $currentRoleId = current_role_id();

    return $currentRoleId !== null && in_array($currentRoleId, $allowedRoleIds, true);
}

function can_manage_catalog(): bool
{
    return has_any_role([APP_ROLE_ADMIN, APP_ROLE_STAFF]);
}

function require_login(array $allowedRoleIds = []): void
{
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to continue.');
        redirect_to('index.php');
    }

    if ($allowedRoleIds !== [] && !has_any_role($allowedRoleIds)) {
        set_flash('error', 'You do not have permission to access that page.');
        $roleId = current_role_id();
        redirect_to($roleId !== null ? dashboard_path_for_role($roleId) : 'index.php');
    }
}

function sync_user_session(array $user): void
{
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['role_id'] = (int) $user['role_id'];
}

function current_user_session(): array
{
    return [
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'full_name' => (string) ($_SESSION['full_name'] ?? 'User'),
        'username' => (string) ($_SESSION['username'] ?? ''),
        'email' => (string) ($_SESSION['email'] ?? ''),
        'role_id' => (int) ($_SESSION['role_id'] ?? 0),
    ];
}

function default_preferences(): array
{
    return [
        'show_recommendations' => true,
        'default_payment_method' => 'CASH',
    ];
}

function get_preferences(): array
{
    if (!isset($_SESSION['preferences']) || !is_array($_SESSION['preferences'])) {
        $preferences = default_preferences();
        $cookiePayload = $_COOKIE['inventory_preferences'] ?? '';

        if (is_string($cookiePayload) && $cookiePayload !== '') {
            $decoded = json_decode($cookiePayload, true);
            if (is_array($decoded)) {
                $preferences['show_recommendations'] = !empty($decoded['show_recommendations']);
                $paymentMethod = strtoupper((string) ($decoded['default_payment_method'] ?? 'CASH'));
                $preferences['default_payment_method'] = in_array($paymentMethod, ['CASH', 'GCASH', 'CARD'], true)
                    ? $paymentMethod
                    : 'CASH';
            }
        }

        $_SESSION['preferences'] = $preferences;
    }

    return $_SESSION['preferences'];
}

function save_preferences(array $preferences): void
{
    $normalized = default_preferences();
    $normalized['show_recommendations'] = !empty($preferences['show_recommendations']);

    $paymentMethod = strtoupper((string) ($preferences['default_payment_method'] ?? 'CASH'));
    $normalized['default_payment_method'] = in_array($paymentMethod, ['CASH', 'GCASH', 'CARD'], true)
        ? $paymentMethod
        : 'CASH';

    $_SESSION['preferences'] = $normalized;

    setcookie('inventory_preferences', json_encode($normalized), [
        'expires' => time() + (60 * 60 * 24 * 30),
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function recommendations_enabled(): bool
{
    $preferences = get_preferences();
    return !empty($preferences['show_recommendations']);
}

function preferred_payment_method(): string
{
    $preferences = get_preferences();
    return (string) ($preferences['default_payment_method'] ?? 'CASH');
}

function normalize_date_input(?string $date): ?string
{
    if (!is_string($date) || $date === '') {
        return null;
    }

    $dateTime = DateTime::createFromFormat('Y-m-d', $date);
    if ($dateTime === false || $dateTime->format('Y-m-d') !== $date) {
        return null;
    }

    return $date;
}
