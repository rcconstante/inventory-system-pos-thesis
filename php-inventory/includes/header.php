<?php
$flashMessages = pull_flash_messages();

// Fetch Notifications
$notifications = [];
try {
    // 1. Critical Stock Alert
    $criticalStatement = $pdo->query(
        "SELECT p.product_name, p.brand, i.current_stock
         FROM Products p
         JOIN Inventory i ON p.product_id = i.product_id
         WHERE i.current_stock <= i.min_stock_level"
    );
    while ($row = $criticalStatement->fetch(PDO::FETCH_ASSOC)) {
        $name = $row['product_name'] . ($row['brand'] ? ' (' . $row['brand'] . ')' : '');
        $notifications[] = [
            'type' => 'critical',
            'title' => 'System Notification',
            'message' => 'Inventory level for ' . h($name) . ' has reached the critical threshold.',
            'emoji' => '⚠️'
        ];
    }

    // 2. Low Stock Alert
    $lowStockStatement = $pdo->query(
        "SELECT p.product_name, p.brand, i.current_stock
         FROM Products p
         JOIN Inventory i ON p.product_id = i.product_id
         WHERE i.current_stock > i.min_stock_level AND i.current_stock <= (i.min_stock_level + 5)"
    );
    while ($row = $lowStockStatement->fetch(PDO::FETCH_ASSOC)) {
        $name = $row['product_name'] . ($row['brand'] ? ' (' . $row['brand'] . ')' : '');
        $notifications[] = [
            'type' => 'low_stock',
            'title' => 'Low Stock Alert',
            'message' => h($name) . ' is running low. Only ' . $row['current_stock'] . ' items remaining.',
            'emoji' => '⚠️'
        ];
    }

    // 3. Expiry Warning (within 30 days)
    $expiryWarningStatement = $pdo->query(
        "SELECT p.product_name, p.brand, i.expiry_date, DATEDIFF(i.expiry_date, CURDATE()) as days_left
         FROM Products p
         JOIN Inventory i ON p.product_id = i.product_id
         WHERE i.expiry_date IS NOT NULL AND i.expiry_date >= CURDATE() AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
    );
    while ($row = $expiryWarningStatement->fetch(PDO::FETCH_ASSOC)) {
        $name = $row['product_name'] . ($row['brand'] ? ' (' . $row['brand'] . ')' : '');
        $days = (int) $row['days_left'];
        $timeText = $days === 1 ? '1 day' : $days . ' days';
        
        $notifications[] = [
            'type' => 'expiry_warning',
            'title' => 'Expiry Warning',
            'message' => h($name) . ' will expire in ' . $timeText . '. Please take necessary action.',
            'emoji' => '⏳'
        ];
    }

    // 4. Expired Product
    $expiredStatement = $pdo->query(
        "SELECT p.product_name, p.brand
         FROM Products p
         JOIN Inventory i ON p.product_id = i.product_id
         WHERE i.expiry_date IS NOT NULL AND i.expiry_date < CURDATE()"
    );
    while ($row = $expiredStatement->fetch(PDO::FETCH_ASSOC)) {
        $name = $row['product_name'] . ($row['brand'] ? ' (' . $row['brand'] . ')' : '');
        $notifications[] = [
            'type' => 'expired',
            'title' => 'Expired Product',
            'message' => h($name) . ' has already expired. Please remove it from inventory.',
            'emoji' => '❌',
            'title_color' => 'text-red-600'
        ];
    }
} catch (PDOException $e) {
    // Ignore if there's an error, just show empty
}

$notificationCount = count($notifications);
?>
<?php
$isDarkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === '1';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $isDarkMode ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : "Dashboard"; ?> - Inventory POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <style>
        .custom-dark-bg { background-color: #363C52; }
    </style>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%234338ca' rx='20'/%3E%3Ctext x='50' y='70' fill='white' font-family='Arial, sans-serif' font-size='60' font-weight='bold' text-anchor='middle'%3EI%3C/text%3E%3C/svg%3E">
</head>
<body class="flex h-screen bg-gray-50 dark:bg-gray-900 text-black dark:text-gray-100 transition-colors duration-200">
    
    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col overflow-hidden">
        
        <!-- Header -->
        <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-8 py-6 flex items-center justify-between transition-colors duration-200">
            <?php if(isset($page_title)): ?>
                <h1 class="text-3xl font-bold uppercase tracking-wider"><?php echo htmlspecialchars($page_title); ?></h1>
            <?php else: ?>
                <div></div>
            <?php endif; ?>
            
            <div class="flex flex-col items-end gap-1">
                <div class="text-sm font-medium mr-3 text-gray-600 dark:text-gray-400">
                    <?php echo date('d/m/Y'); ?>
                </div>
                <div class="flex items-center gap-4">
                    <?php if (current_role_id() === APP_ROLE_CASHIER): ?>
                        <?php
                            $cartCount = 0;
                            if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
                                foreach ($_SESSION['cart'] as $item) {
                                    $cartCount += (int)($item['qty'] ?? 0);
                                }
                            }
                        ?>
                        <button type="button" onclick="window.location.href='<?php echo h(app_url('pages/pos.php?cart=1')); ?>'" class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-300 dark:hover:text-white dark:hover:bg-gray-700 rounded transition-colors relative" title="Cart">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shopping-cart"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                            <?php if ($cartCount > 0): ?>
                                <span class="absolute lg:-top-1 lg:-right-1 -top-1.5 -right-1.5 bg-red-500 text-white text-xs font-bold px-1.5 py-0.5 rounded-full min-w-[20px] flex items-center justify-center">
                                    <?php echo $cartCount; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" onclick="toggleNotificationsModal(true)" class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-300 dark:hover:text-white dark:hover:bg-gray-700 rounded transition-colors relative" title="Notifications">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bell"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        <?php if (isset($notificationCount) && $notificationCount > 0): ?>
                            <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-red-500 rounded-full"></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" onclick="openSettingsModal()" class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-300 dark:hover:text-white dark:hover:bg-gray-700 rounded transition-colors" title="Settings">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-settings"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-auto p-8 relative">
            <!-- Toast Notifications Container -->
            <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-2"></div>
            
            <script>
                function showToast(message, type = 'info') {
                    const container = document.getElementById('toast-container');
                    
                    const bgColors = {
                        'success': 'bg-white dark:bg-gray-800 border-l-4 border-green-500 text-gray-800 dark:text-white',
                        'error': 'bg-white dark:bg-gray-800 border-l-4 border-red-500 text-gray-800 dark:text-white',
                        'warning': 'bg-white dark:bg-gray-800 border-l-4 border-yellow-500 text-gray-800 dark:text-white',
                        'info': 'bg-white dark:bg-gray-800 border-l-4 border-blue-500 text-gray-800 dark:text-white'
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
                        <button type="button" class="ml-auto -mx-1.5 -my-1.5 text-gray-400 hover:text-gray-900 rounded-lg focus:ring-2 focus:ring-gray-300 p-1.5 hover:bg-gray-100 inline-flex items-center justify-center h-8 w-8 dark:hover:bg-gray-700 dark:hover:text-white" onclick="this.parentElement.remove()">
                            <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
                            </svg>
                        </button>
                    `;
                    
                    container.appendChild(toast);
                    
                    // Trigger animation
                    requestAnimationFrame(() => {
                        toast.classList.remove('translate-x-full', 'opacity-0');
                    });
                    
                    // Auto remove after 3 seconds
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
            <?php include __DIR__ . '/settings_modal.php'; ?>
            <?php include __DIR__ . '/notifications_modal.php'; ?>
