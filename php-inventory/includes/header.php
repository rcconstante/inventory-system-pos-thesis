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
                        <button type="button" onclick="window.location.href='<?php echo h(app_url('pages/pos.php?cart=1')); ?>'" class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-300 dark:hover:text-white dark:hover:bg-gray-700 rounded transition-colors relative" title="Cart">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shopping-cart"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
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
        <div class="flex-1 overflow-auto p-8">
            <?php if ($flashMessages !== []): ?>
                <div class="mb-6 space-y-3">
                    <?php foreach ($flashMessages as $flashMessage): ?>
                        <?php
                        $type = $flashMessage['type'] ?? 'info';
                        $classes = match ($type) {
                            'success' => 'border-green-200 bg-green-50 text-green-800',
                            'error' => 'border-red-200 bg-red-50 text-red-800',
                            'warning' => 'border-yellow-200 bg-yellow-50 text-yellow-800',
                            default => 'border-blue-200 bg-blue-50 text-blue-800',
                        };
                        ?>
                        <div class="rounded-lg border px-4 py-3 text-sm <?php echo $classes; ?>">
                            <?php echo h($flashMessage['message'] ?? ''); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php include __DIR__ . '/settings_modal.php'; ?>
            <?php include __DIR__ . '/notifications_modal.php'; ?>
