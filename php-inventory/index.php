<?php
declare(strict_types=1);

require_once 'includes/app.php';

if (is_logged_in()) {
    redirect_to(dashboard_path_for_role((int) current_role_id()));
}

$flashMessages = pull_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Select - Inventory POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .custom-dark-bg { background-color: #363C52; }
    </style>
</head>
<body class="min-h-screen bg-white flex items-center justify-center p-4">

    <div class="w-full max-w-2xl">
        <!-- Main Content -->
        <div class="custom-dark-bg rounded-lg p-12 flex flex-col items-center gap-8">
            
            <!-- Logo and Company Info -->
            <div class="flex items-center gap-3 mb-4">
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
            <h1 class="text-white text-2xl font-bold text-center">
                INVENTORY MANAGEMENT AND POINT OF SALE SYSTEM
            </h1>

            <!-- Subtitle -->
            <p class="text-white text-center mb-4 text-lg">
                Please enter log in to continue
            </p>

            <?php if ($flashMessages !== []): ?>
                <div class="w-full max-w-md space-y-2">
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
                        <div class="rounded px-4 py-3 text-center text-sm text-white <?php echo $classes; ?>">
                            <?php echo h($flashMessage['message'] ?? ''); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Buttons -->
            <div class="flex flex-col gap-4 w-full max-w-xs mt-2">
                <a href="<?php echo h(app_url('login.php')); ?>"
                   class="bg-white text-black font-bold py-3 px-6 rounded-lg hover:bg-gray-100 transition-colors text-lg text-center cursor-pointer shadow-md">
                    LOG IN
                </a>
            </div>

        </div>
    </div>

</body>
</html>
