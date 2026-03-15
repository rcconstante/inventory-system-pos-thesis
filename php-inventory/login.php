<?php
declare(strict_types=1);

require_once 'includes/app.php';
require_once 'includes/config.php';

$role = strtolower((string) ($_GET['role'] ?? 'admin'));
$selectedRoleId = role_slug_to_id($role);
$error = '';
$flashMessages = pull_flash_messages();

if ($selectedRoleId === null) {
    $role = 'admin';
    $selectedRoleId = APP_ROLE_ADMIN;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('login.php?role=' . $role);

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $statement = $pdo->prepare('SELECT * FROM User WHERE email = :email OR username = :username LIMIT 1');
    $statement->execute([
        'email' => $email,
        'username' => $email,
    ]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, (string) $user['password']) && (int) $user['role_id'] === $selectedRoleId) {
        if (empty($user['is_active'])) {
            $error = 'Your account has been deactivated. Please contact an administrator.';
        } else {
            session_regenerate_id(true);
            sync_user_session($user);
            redirect_to(dashboard_path_for_role((int) $user['role_id']));
        }
    } else {
        $error = 'Invalid credentials for the selected role.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Inventory POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .custom-dark-bg { background-color: #363C52; }
        /* Suppress browser native password reveal button */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear,
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="text"]::-ms-reveal,
        input[type="text"]::-ms-clear { display: none !important; }
        input::-webkit-strong-password-auto-fill-button { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-white flex items-center justify-center p-4">

    <div class="w-full max-w-2xl">
        <!-- Main Content -->
        <div class="custom-dark-bg rounded-lg p-12 flex flex-col items-center">
            
            <!-- Logo and Company Info -->
            <div class="flex items-center gap-3 mb-6">
                <svg width="40" height="40" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M0 24.5348C0 28.6303 3.32014 31.9505 7.41573 31.9505H32L0 1.79497V24.5348Z" fill="url(#logo_gradient_1)"/>
                    <path opacity="0.983161" fill-rule="evenodd" clip-rule="evenodd" d="M0 7.40946C0 3.31387 3.32014 0 7.41573 0H32C32 0 3.73034 25.7671 1.2263 28.2342C0 29.9414 1.43276 31.8648 1.43276 31.8648C1.43276 31.8648 0 31.1225 0 29.5198C0 28.7041 0 15.8841 0 7.40946Z" fill="url(#logo_gradient_2)"/>
                    <defs>
                        <linearGradient id="logo_gradient_1" x1="7.98937" y1="10.3822" x2="37.8245" y2="18.6459" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#0043FF" />
                            <stop offset="1" stop-color="#A370F1" />
                        </linearGradient>
                        <linearGradient id="logo_gradient_2" x1="22.0813" y1="-15.8615" x2="-5.37804" y2="2.33241" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#4BF2E6" />
                            <stop offset="1" stop-color="#0065FF" />
                        </linearGradient>
                    </defs>
                </svg>
                <div class="text-white">
                    <div class="font-medium">FIVE BROTHERS TRADING</div>
                    <div class="text-sm text-gray-300">Mabuhay, Carmona, Cavite</div>
                </div>
            </div>

            <!-- Title -->
            <h1 class="text-white text-2xl font-bold text-center mb-8">
                POINT OF SALE AND INVENTORY MANAGEMENT SYSTEM<br>
                <span class="text-lg font-normal text-gray-300 capitalize">(<?php echo htmlspecialchars($role); ?> Login)</span>
            </h1>

            <?php if($error): ?>
                <div class="bg-red-500 text-white p-3 rounded mb-4 text-center w-full max-w-md">
                    <?php echo h($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($flashMessages !== []): ?>
                <div class="mb-4 w-full max-w-md space-y-2">
                    <?php foreach ($flashMessages as $flashMessage): ?>
                        <?php
                        $type = $flashMessage['type'] ?? 'info';
                        $classes = match ($type) {
                            'success' => 'bg-green-500',
                            'error' => 'bg-red-500',
                            'warning' => 'bg-yellow-500 text-black',
                            default => 'bg-blue-500',
                        };
                        ?>
                        <div class="rounded p-3 text-center text-sm text-white <?php echo $classes; ?>">
                            <?php echo h($flashMessage['message'] ?? ''); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="<?php echo h(app_url('login.php?role=' . $role)); ?>" class="w-full max-w-md space-y-6">
                <?php echo csrf_field(); ?>
                <!-- Email/Username -->
                <div>
                    <label class="block text-white text-sm mb-2">Username or Email</label>
                    <input type="text" name="email" required
                           class="w-full px-4 py-3 border border-gray-300 rounded bg-white text-black focus:outline-none focus:ring-2 focus:ring-blue-500" 
                           placeholder="Enter your username or email">
                </div>

                <!-- Password -->
                <div>
                    <label class="block text-white text-sm mb-2">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" required autocomplete="current-password"
                               class="w-full px-4 py-3 pr-10 border border-gray-300 rounded bg-white text-black focus:outline-none focus:ring-2 focus:ring-blue-500" 
                               placeholder="Enter your password">
                        
                        <button type="button"
                                onmousedown="event.preventDefault(); togglePassword();"
                                ontouchstart="event.preventDefault(); togglePassword();"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-800 transition-colors duration-150 focus:outline-none select-none">
                            <!-- Eye (password hidden) -->
                            <svg id="icon-eye" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
                            <!-- Eye-off (password visible) -->
                            <svg id="icon-eye-off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Back -->
                <div class="flex items-center justify-end text-sm">
                    <a href="<?php echo h(app_url('index.php')); ?>" class="text-white hover:underline">
                        Back to Role Select
                    </a>
                </div>

                <!-- Submit Button -->
                <button type="submit"
                        class="w-full bg-white text-black font-bold py-3 px-6 rounded-full border border-black hover:bg-gray-100 transition-colors text-base">
                    Login &rightarrow;
                </button>
            </form>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            var input = document.getElementById('password');
            var iconEye    = document.getElementById('icon-eye');
            var iconEyeOff = document.getElementById('icon-eye-off');
            if (input.type === 'password') {
                input.type = 'text';
                iconEye.classList.add('hidden');
                iconEyeOff.classList.remove('hidden');
            } else {
                input.type = 'password';
                iconEyeOff.classList.add('hidden');
                iconEye.classList.remove('hidden');
            }
        }
    </script>
</body>
</html>
