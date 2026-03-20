<?php
declare(strict_types=1);

require_once 'includes/app.php';
require_once 'includes/config.php';

$error = '';
$flashMessages = pull_flash_messages();

// Retrieve remembered username if available
$remembered_username = $_COOKIE['remembered_username'] ?? '';
$remembered_role = $_COOKIE['remembered_role'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('login.php');

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $postedRole = strtolower(trim((string) ($_POST['role'] ?? 'admin')));
    $selectedRoleId = role_slug_to_id($postedRole) ?? APP_ROLE_ADMIN;
    $remember_me = isset($_POST['remember_me']);

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
            // Handle Remember Me
            if ($remember_me) {
                // Set cookie for 30 days
                setcookie('remembered_username', $email, time() + (86400 * 30), "/");
                setcookie('remembered_role', $postedRole, time() + (86400 * 30), "/");
            } else {
                // Clear cookies if unchecked
                setcookie('remembered_username', '', time() - 3600, "/");
                setcookie('remembered_role', '', time() - 3600, "/");
            }

            session_regenerate_id(true);
            sync_user_session($user);
            redirect_to(dashboard_path_for_role((int) $user['role_id']));
        }
    } else {
        $error = 'Invalid credentials for the selected role.';
    }
}
$isDarkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === '1';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $isDarkMode ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Inventory POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
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
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg width='32' height='32' viewBox='0 0 32 32' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' clip-rule='evenodd' d='M0 24.5348C0 28.6303 3.32014 31.9505 7.41573 31.9505H32L0 1.79497V24.5348Z' fill='url(%23logo_gradient_1)'/%3E%3Cpath opacity='0.983161' fill-rule='evenodd' clip-rule='evenodd' d='M0 7.40946C0 3.31387 3.32014 0 7.41573 0H32C32 0 3.73034 25.7671 1.2263 28.2342C0 29.9414 1.43276 31.8648 1.43276 31.8648C1.43276 31.8648 0 31.1225 0 29.5198C0 28.7041 0 15.8841 0 7.40946Z' fill='url(%23logo_gradient_2)'/%3E%3Cdefs%3E%3ClinearGradient id='logo_gradient_1' x1='16' y1='1.79497' x2='16' y2='31.9505' gradientUnits='userSpaceOnUse'%3E%3Cstop stop-color='%234CD995'/%3E%3Cstop offset='1' stop-color='%234CD995' stop-opacity='0'/%3E%3C/linearGradient%3E%3ClinearGradient id='logo_gradient_2' x1='16' y1='0' x2='16' y2='31.8648' gradientUnits='userSpaceOnUse'%3E%3Cstop stop-color='%238E9CFF'/%3E%3Cstop offset='1' stop-color='%238E9CFF' stop-opacity='0'/%3E%3C/linearGradient%3E%3C/defs%3E%3C/svg%3E">
</head>
<body class="min-h-screen bg-white dark:bg-gray-900 flex items-center justify-center p-4">

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
            <h1 class="text-white text-xl font-bold text-center mb-8">
                INVENTORY MANAGEMENT AND POINT OF SALE SYSTEM
            </h1>

            <?php if($error): ?>
                <div class="bg-red-500 text-white p-3 rounded mb-4 text-center w-full max-w-md text-sm">
                    <?php echo h($error); ?>
                </div>
            <?php endif; ?>

            <!-- Toast Notifications Container -->
            <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-2"></div>
            
            <script>
                function showToast(message, type = 'info') {
                    const container = document.getElementById('toast-container');
                    
                    const bgColors = {
                        'success': 'bg-white border-l-4 border-green-500 text-gray-800',
                        'error': 'bg-white border-l-4 border-red-500 text-gray-800',
                        'warning': 'bg-white border-l-4 border-yellow-500 text-gray-800',
                        'info': 'bg-white border-l-4 border-blue-500 text-gray-800'
                    };
                    
                    const icons = {
                        'success': '<svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>',
                        'error': '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>',
                        'warning': '<svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
                        'info': '<svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                    };

                    const toast = document.createElement('div');
                    toast.className = `flex items-center p-4 mb-2 shadow-lg rounded-md transition-all duration-300 transform translate-x-full opacity-0 ${bgColors[type] || bgColors['info']}`;
                    
                    toast.innerHTML = `
                        <div class="inline-flex items-center justify-center shrink-0">
                            ${icons[type] || icons['info']}
                        </div>
                        <div class="mx-3 text-sm font-medium">
                            ${message}
                        </div>
                        <button type="button" class="ml-auto -mx-1.5 -my-1.5 text-gray-400 hover:text-gray-900 rounded-lg focus:ring-2 focus:ring-gray-300 p-1.5 hover:bg-gray-100 inline-flex items-center justify-center h-8 w-8" onclick="this.parentElement.remove()">
                            <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
                            </svg>
                        </button>
                    `;
                    
                    container.appendChild(toast);
                    
                    requestAnimationFrame(() => {
                        toast.classList.remove('translate-x-full', 'opacity-0');
                    });
                    
                    setTimeout(() => {
                        toast.classList.add('translate-x-full', 'opacity-0');
                        setTimeout(() => toast.remove(), 300);
                    }, 3000);
                }
            </script>

            <?php if ($flashMessages !== []): ?>
                <script>
                    (function() {
                        const runToasts = () => {
                            <?php foreach ($flashMessages as $flashMessage): ?>
                                showToast("<?php echo addslashes(h($flashMessage['message'] ?? '')); ?>", "<?php echo addslashes(h($flashMessage['type'] ?? 'info')); ?>");
                            <?php endforeach; ?>
                        };
                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', runToasts);
                        } else {
                            runToasts();
                        }
                    })();
                </script>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="<?php echo h(app_url('login.php')); ?>" class="w-full max-w-md space-y-4">
                <?php echo csrf_field(); ?>
                
                <!-- Role Selector -->
                <div>
                    <label class="block text-white text-sm mb-1">Role</label>
                    <select name="role" required class="w-full px-4 py-3 border border-gray-300 rounded bg-white text-gray-700 font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none bg-[url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23374151%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22m6%209%206%206%206-6%22%2F%3E%3C%2Fsvg%3E')] bg-no-repeat bg-[position:right_1rem_center]">
                        <option value="admin" <?php echo $remembered_role === 'admin' ? 'selected' : ''; ?>>ADMIN</option>
                        <option value="cashier" <?php echo $remembered_role === 'cashier' ? 'selected' : ''; ?>>CASHIER</option>
                        <option value="staff" <?php echo $remembered_role === 'staff' ? 'selected' : ''; ?>>STAFF</option>
                    </select>
                </div>

                <!-- Email/Username -->
                <div>
                    <label class="block text-white text-sm mb-1">Username</label>
                    <input type="text" name="email" required
                           value="<?php echo h($remembered_username); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded bg-white text-gray-700 font-medium focus:outline-none focus:ring-2 focus:ring-blue-500" 
                           placeholder="Enter your username">
                </div>

                <!-- Password -->
                <div>
                    <label class="block text-white text-sm mb-1">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" required autocomplete="current-password"
                               class="w-full px-4 py-3 pr-10 border border-gray-300 rounded bg-white text-gray-700 font-medium focus:outline-none focus:ring-2 focus:ring-blue-500" 
                               placeholder="***********">
                        
                        <button type="button"
                                onmousedown="event.preventDefault(); togglePassword();"
                                ontouchstart="event.preventDefault(); togglePassword();"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-900 hover:text-black transition-colors duration-150 focus:outline-none select-none">
                            <!-- Eye (password hidden) -->
                            <svg id="icon-eye" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
                            <!-- Eye-off (password visible) -->
                            <svg id="icon-eye-off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Remember Me & Forgot Password -->
                <div class="flex items-center justify-between text-sm mt-2">
                    <label class="flex items-center text-white cursor-pointer hover:text-gray-200">
                        <input type="checkbox" name="remember_me" class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo $remembered_username !== '' ? 'checked' : ''; ?>>
                        Remember Me
                    </label>
                    <a href="#" class="text-white hover:underline hover:text-gray-200" onclick="alert('Please contact an administrator to reset your password.'); return false;">
                        Forgot Password
                    </a>
                </div>

                <!-- Submit Button -->
                <div class="flex justify-center mt-8">
                    <button type="submit"
                            class="bg-white text-black font-bold py-2 px-6 rounded flex items-center justify-center gap-2 hover:bg-gray-100 transition-colors text-sm shadow-md">
                        Login
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </button>
                </div>
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
