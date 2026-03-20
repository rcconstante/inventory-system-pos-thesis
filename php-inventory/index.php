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
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg width='32' height='32' viewBox='0 0 32 32' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' clip-rule='evenodd' d='M0 24.5348C0 28.6303 3.32014 31.9505 7.41573 31.9505H32L0 1.79497V24.5348Z' fill='url(%23logo_gradient_1)'/%3E%3Cpath opacity='0.983161' fill-rule='evenodd' clip-rule='evenodd' d='M0 7.40946C0 3.31387 3.32014 0 7.41573 0H32C32 0 3.73034 25.7671 1.2263 28.2342C0 29.9414 1.43276 31.8648 1.43276 31.8648C1.43276 31.8648 0 31.1225 0 29.5198C0 28.7041 0 15.8841 0 7.40946Z' fill='url(%23logo_gradient_2)'/%3E%3Cdefs%3E%3ClinearGradient id='logo_gradient_1' x1='16' y1='1.79497' x2='16' y2='31.9505' gradientUnits='userSpaceOnUse'%3E%3Cstop stop-color='%234CD995'/%3E%3Cstop offset='1' stop-color='%234CD995' stop-opacity='0'/%3E%3C/linearGradient%3E%3ClinearGradient id='logo_gradient_2' x1='16' y1='0' x2='16' y2='31.8648' gradientUnits='userSpaceOnUse'%3E%3Cstop stop-color='%238E9CFF'/%3E%3Cstop offset='1' stop-color='%238E9CFF' stop-opacity='0'/%3E%3C/linearGradient%3E%3C/defs%3E%3C/svg%3E">
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
