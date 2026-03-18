<?php
$pos_file = 'C:\\xampp\\htdocs\\inventory\\php-inventory\\pages\\pos.php';
$pos_content = file_get_contents($pos_file);

// 1. Redirect to receipt on successful checkout (instead of flash + pos.php)
$old_checkout_redirect = <<<'EOD'
            $_SESSION['cart'] = [];
            set_flash('success', 'Transaction completed successfully. Order ID: #' . $saleId);
            redirect_to('pages/pos.php');
            exit;
        }
EOD;
$new_checkout_redirect = <<<'EOD'
            $_SESSION['cart'] = [];
            // Remove flash message, use receipt modal instead
            redirect_to('pages/pos.php?receipt_id=' . $saleId);
            exit;
        }
EOD;
$pos_content = str_replace($old_checkout_redirect, $new_checkout_redirect, $pos_content);

// 2. Fetch Receipt data if available
$fetch_receipt_code = <<<'EOD'
$flatRecommendations = [];
foreach ($recommendations as $recList) {
    foreach ($recList as $rec) {
        $flatRecommendations[$rec['alternative_id']] = $rec;
    }
}

// Check for receipt modal
$receiptSale = null;
$receiptItems = [];
if (isset($_GET['receipt_id'])) {
    $receiptId = (int)$_GET['receipt_id'];
    $receiptSaleStmt = $pdo->prepare("SELECT * FROM Sale WHERE sale_id = ?");
    $receiptSaleStmt->execute([$receiptId]);
    $receiptSale = $receiptSaleStmt->fetch(PDO::FETCH_ASSOC);
    if ($receiptSale) {
        $receiptItemsStmt = $pdo->prepare("SELECT si.*, p.product_name FROM Sale_Item si JOIN Products p ON si.product_id = p.product_id WHERE si.sale_id = ?");
        $receiptItemsStmt->execute([$receiptId]);
        $receiptItems = $receiptItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$page_title = 'POINT OF SALE';
EOD;
$pos_content = preg_replace('/\$flatRecommendations = \[\];.*?\$page_title = \'POINT OF SALE\';/s', $fetch_receipt_code, $pos_content);


// 3. Recommended products empty state in checkout view
$old_rec_list = <<<'EOD'
            <div class="w-full space-y-4">
                <?php foreach (array_slice($flatRecommendations, 0, 3) as $rec): ?>
                    <div class="flex items-center justify-between border border-black dark:border-gray-400 pl-6 pr-0 py-0 font-medium bg-white dark:bg-gray-800">
                        <span><?php echo h($rec['alternative_name']); ?></span>
                        <div class="flex items-center gap-6">
                            <span>₱ <?php echo number_format((float)($rec['price'] ?? 0), 2); ?></span>
                            <form method="POST" action="<?php echo h(app_url('pages/pos.php?checkout=1' . (isset($_GET['q']) ? '&q=' . urlencode($_GET['q']) : ''))); ?>" class="m-0">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo h((string)$rec['alternative_id']); ?>">
                                <input type="hidden" name="qty" value="1">
                                <button type="submit" name="add_to_cart" class="border-l border-black dark:border-gray-400 px-6 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors h-full text-sm font-bold bg-white dark:bg-gray-800 text-black dark:text-white">Add</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
EOD;
$new_rec_list = <<<'EOD'
            <div class="w-full space-y-4">
                <?php if (empty($flatRecommendations)): ?>
                    <div class="border border-black dark:border-gray-400 p-6 text-center text-sm font-medium bg-white dark:bg-gray-800 text-gray-500">
                        Currently no recommended products
                    </div>
                <?php else: ?>
                    <?php foreach (array_slice($flatRecommendations, 0, 3) as $rec): ?>
                        <div class="flex items-center justify-between border border-black dark:border-gray-400 pl-6 pr-0 py-0 font-medium bg-white dark:bg-gray-800">
                            <span><?php echo h($rec['alternative_name']); ?></span>
                            <div class="flex items-center gap-6">
                                <span>₱ <?php echo number_format((float)($rec['price'] ?? 0), 2); ?></span>
                                <form method="POST" action="<?php echo h(app_url('pages/pos.php?checkout=1' . (isset($_GET['q']) ? '&q=' . urlencode($_GET['q']) : ''))); ?>" class="m-0">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo h((string)$rec['alternative_id']); ?>">
                                    <input type="hidden" name="qty" value="1">
                                    <button type="submit" name="add_to_cart" class="border-l border-black dark:border-gray-400 px-6 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors h-full text-sm font-bold bg-white dark:bg-gray-800 text-black dark:text-white">Add</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
EOD;
$pos_content = str_replace($old_rec_list, $new_rec_list, $pos_content);


// 4. Add receipt modal
$receipt_modal = <<<'EOD'
<!-- Receipt Modal -->
<?php if (isset($receiptSale) && $receiptSale): ?>
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
    <div class="w-[500px] bg-white text-black border border-black shadow-2xl flex flex-col relative">
        <a href="<?php echo h(app_url('pages/pos.php')); ?>" class="absolute top-4 right-4 text-black hover:text-gray-600">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </a>
        
        <div class="p-8 text-center border-b border-black">
            <h2 class="text-xl font-medium tracking-widest uppercase mb-2">FIVE BROTHERS TRADING</h2>
            <p class="text-sm mb-1">0961-195-6139</p>
            <p class="text-sm mb-1">Mabuhay Carmona, Cavite</p>
            <p class="text-sm mb-1">Opening Hours: 9:00 AM - 3:00 PM</p>
            <p class="text-sm mt-3">Date: <?php echo date('F d, Y', strtotime($receiptSale['sale_date'] ?? 'now')); ?></p>
        </div>
        
        <div class="p-8 flex-1 overflow-auto max-h-[40vh]">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left font-bold border-b border-black">
                        <th class="pb-3 w-16">QTY</th>
                        <th class="pb-3">DESCRIPTION</th>
                        <th class="pb-3 text-right">AMOUNT</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($receiptItems as $item): ?>
                    <tr>
                        <td class="py-4 font-medium"><?php echo h((string)$item['quantity']); ?></td>
                        <td class="py-4 font-medium"><?php echo h($item['product_name']); ?></td>
                        <td class="py-4 text-right font-medium"><?php echo number_format((float)$item['subtotal'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="p-8 border-t border-black bg-white">
            <div class="text-sm font-bold mb-3">TOTAL: <?php echo number_format((float)$receiptSale['total_amount'], 2); ?></div>
            <div class="text-sm font-bold mb-8 uppercase">PAID BY: <?php echo h($receiptSale['payment_method']); ?></div>
            <div class="text-center text-sm font-medium">Thank You :)</div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
EOD;

$pos_content = str_replace('<?php include \'../includes/footer.php\'; ?>', $receipt_modal, $pos_content);
file_put_contents($pos_file, $pos_content);


// NOW FIX HEADER
$header_file = 'C:\\xampp\\htdocs\\inventory\\php-inventory\\includes\\header.php';
$header_content = file_get_contents($header_file);

// Cart Badge
$old_cart_icon = <<<'EOD'
                    <?php if (current_role_id() === APP_ROLE_CASHIER): ?>
                        <button type="button" onclick="window.location.href='<?php echo h(app_url('pages/pos.php?cart=1')); ?>'" class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-300 dark:hover:text-white dark:hover:bg-gray-700 rounded transition-colors relative" title="Cart">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shopping-cart"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                        </button>
                    <?php endif; ?>
EOD;
$new_cart_icon = <<<'EOD'
                    <?php if (current_role_id() === APP_ROLE_CASHIER): ?>
                        <button type="button" onclick="window.location.href='<?php echo h(app_url('pages/pos.php?cart=1')); ?>'" class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-300 dark:hover:text-white dark:hover:bg-gray-700 rounded transition-colors relative" title="Cart">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shopping-cart"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                            <?php 
                            $cartCount = isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
                            if ($cartCount > 0): ?>
                                <span class="absolute top-0 right-0 w-4 h-4 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"><?php echo $cartCount; ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
EOD;
$header_content = str_replace($old_cart_icon, $new_cart_icon, $header_content);

// Toasts Notification UI
$old_flash = <<<'EOD'
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
EOD;

$new_flash = <<<'EOD'
        <!-- Content -->
        <div class="flex-1 overflow-auto p-8 relative">
            
            <!-- Toast Notifications Container -->
            <div id="toast-container" class="fixed top-24 right-8 z-[100] flex flex-col gap-3 pointer-events-none">
            <?php if ($flashMessages !== []): ?>
                <?php foreach ($flashMessages as $flashMessage): ?>
                    <?php
                    $type = $flashMessage['type'] ?? 'info';
                    $classes = match ($type) {
                        'success' => 'border-green-500 bg-green-100 text-green-900',
                        'error' => 'border-red-500 bg-red-100 text-red-900',
                        'warning' => 'border-yellow-500 bg-yellow-100 text-yellow-900',
                        default => 'border-blue-500 bg-blue-100 text-blue-900',
                    };
                    ?>
                    <div class="toast-message rounded-md border-l-4 shadow-lg px-6 py-4 text-sm font-bold pointer-events-auto transition-opacity duration-500 <?php echo $classes; ?>">
                        <?php echo h($flashMessage['message'] ?? ''); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
            
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const toasts = document.querySelectorAll('.toast-message');
                    toasts.forEach(toast => {
                        setTimeout(() => {
                            toast.style.opacity = '0';
                            setTimeout(() => toast.remove(), 500); // Remove from DOM after fade out
                        }, 3000);
                    });
                });
            </script>
EOD;
$header_content = str_replace($old_flash, $new_flash, $header_content);

file_put_contents($header_file, $header_content);
echo "OK";
