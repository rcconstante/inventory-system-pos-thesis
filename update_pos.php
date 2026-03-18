<?php
$file = 'C:\\xampp\\htdocs\\inventory\\php-inventory\\pages\\pos.php';
$content = file_get_contents($file);

$old_redirect = <<<'EOD'
    }

    redirect_to('pages/pos.php');
}
EOD;
$new_redirect = <<<'EOD'
    }
    
    // Redirect preserving query string if any
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect_to('pages/pos.php' . ($qs !== '' ? '?' . $qs : ''));
}
EOD;
$content = str_replace($old_redirect, $new_redirect, $content);

$old_checkout_success = <<<'EOD'
            $_SESSION['cart'] = [];
            set_flash('success', 'Transaction completed successfully. Order ID: #' . $saleId);
        }
EOD;
$new_checkout_success = <<<'EOD'
            $_SESSION['cart'] = [];
            set_flash('success', 'Transaction completed successfully. Order ID: #' . $saleId);
            redirect_to('pages/pos.php');
            exit;
        }
EOD;
$content = str_replace($old_checkout_success, $new_checkout_success, $content);

$old_main_view = <<<'EOD'
<div class="flex h-[calc(100vh-140px)] -m-8 font-sans">
    <!-- Main Left side: POS List -->
EOD;
$new_main_view = <<<'EOD'
<!-- Main POS View -->
<div id="pos-main-view" class="<?php echo isset($_GET['checkout']) ? 'hidden' : 'flex'; ?> h-[calc(100vh-140px)] -m-8 font-sans">
    <!-- Main Left side: POS List -->
EOD;
$content = str_replace($old_main_view, $new_main_view, $content);

$start_idx = strpos($content, '<!-- Checkout Overlay -->');
$end_idx = strpos($content, '<script>');

if ($start_idx !== false && $end_idx !== false) {
    $checkout_view_html = <<<'EOD'
<!-- Checkout View (In-page) -->
<div id="pos-checkout-view" class="<?php echo isset($_GET['checkout']) ? 'flex' : 'hidden'; ?> h-[calc(100vh-140px)] -m-8 font-sans bg-white dark:bg-gray-900 text-black dark:text-white border-t border-black dark:border-gray-700">
    <!-- Left Column: Transaction Details -->
    <div class="w-[400px] flex-shrink-0 flex flex-col border-r border-black dark:border-gray-700">
        <div class="flex-1 overflow-auto pt-8">
            <h2 class="text-xl font-bold text-center mb-2 tracking-wide">TRANSACTION NO. <?php echo rand(1, 999); ?></h2>
            <p class="text-sm text-center mb-8">Date: <?php echo date('F d, Y'); ?></p>
            
            <div class="flex justify-between font-bold px-8 mb-4">
                <span>Products Name</span>
                <span>Amount</span>
            </div>
            <div class="px-8 space-y-4 text-sm font-medium mb-8">
                <?php foreach ($cart as $item): ?>
                    <div class="flex justify-between">
                        <span><?php echo h($item['name']); ?></span>
                        <span>₱<?php echo number_format((float)$item['price'], 2); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="border-t border-black dark:border-gray-700 p-8 flex flex-col gap-3 text-lg font-medium">
            <div>Total: <?php echo number_format($totalDue, 2); ?></div>
            <div class="flex items-center gap-2">
                <span>Amount Received:</span>
                <input type="number" id="amountReceived" class="border border-black dark:border-gray-400 px-3 py-1 w-32 bg-transparent focus:outline-none" onkeyup="calculateChange()">
            </div>
            <div>Change: <span id="changeAmount">0.00</span></div>
        </div>
    </div>
    
    <!-- Right Column: Payment & Recommended -->
    <div class="flex-1 flex flex-col p-12 items-center relative">
        <h3 class="text-center text-sm font-bold uppercase mb-8 tracking-wide">SELECT PAYMENT METHOD</h3>
        
        <div class="flex justify-center gap-8 mb-8 w-full max-w-lg">
            <button type="button" id="btnCASH" onclick="setPaymentMethod('CASH')" class="flex-1 border-2 border-black dark:border-gray-400 py-3 font-bold uppercase hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors bg-white dark:bg-gray-800 tracking-wide">CASH</button>
            <button type="button" id="btnGCASH" onclick="setPaymentMethod('GCASH')" class="flex-1 border border-black dark:border-gray-400 py-3 font-bold uppercase hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors bg-white dark:bg-gray-800 tracking-wide">GCASH</button>
        </div>
        
        <div id="refNumberContainer" class="hidden flex-col items-center mb-12 w-full max-w-lg">
            <label class="block text-sm mb-4">If paying via GCASH, enter Reference Number</label>
            <input type="text" id="referenceNumber" class="w-full border border-black dark:border-gray-400 px-4 py-3 bg-transparent focus:outline-none">
        </div>
        
        <div class="flex flex-col items-center mb-auto w-full max-w-lg">
            <h4 class="font-medium mb-6">Recommended Products:</h4>
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
        </div>
        
        <div class="absolute bottom-12 right-12 flex justify-end gap-4">
            <button type="button" onclick="closeCheckoutPage()" class="border border-black dark:border-gray-400 px-8 py-3 uppercase hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors font-bold bg-white dark:bg-gray-800 text-sm tracking-wide">CANCEL</button>
            <form method="POST" action="<?php echo h(app_url('pages/pos.php')); ?>" id="checkoutForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="checkout" value="1">
                <input type="hidden" name="payment_method" id="selectedPaymentMethod" value="CASH">
                <button type="submit" class="border border-black dark:border-gray-400 px-8 py-3 uppercase hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors font-bold bg-white dark:bg-gray-800 text-sm tracking-wide">CONFIRM PAYMENT</button>
            </form>
        </div>
    </div>
</div>

EOD;
    $content = substr_replace($content, $checkout_view_html, $start_idx, $end_idx - $start_idx);
}

$old_script_funcs = <<<'EOD'
    function openCheckoutModal() {
        closeCartModal();
        document.getElementById('checkoutModal').classList.remove('hidden');
        document.getElementById('checkoutModal').classList.add('flex');
    }

    function closeCheckoutModal() {
        document.getElementById('checkoutModal').classList.add('hidden');
        document.getElementById('checkoutModal').classList.remove('flex');
    }
EOD;
$new_script_funcs = <<<'EOD'
    function openCheckoutModal() {
        closeCartModal();
        const url = new URL(window.location);
        url.searchParams.set('checkout', '1');
        window.history.pushState({}, '', url);
        document.getElementById('pos-main-view').classList.add('hidden');
        document.getElementById('pos-main-view').classList.remove('flex');
        document.getElementById('pos-checkout-view').classList.remove('hidden');
        document.getElementById('pos-checkout-view').classList.add('flex');
    }

    function closeCheckoutPage() {
        const url = new URL(window.location);
        url.searchParams.delete('checkout');
        window.history.pushState({}, '', url);
        document.getElementById('pos-checkout-view').classList.add('hidden');
        document.getElementById('pos-checkout-view').classList.remove('flex');
        document.getElementById('pos-main-view').classList.remove('hidden');
        document.getElementById('pos-main-view').classList.add('flex');
    }
EOD;
$content = str_replace($old_script_funcs, $new_script_funcs, $content);

$old_payment_js = <<<'EOD'
    function setPaymentMethod(method) {
        document.getElementById('selectedPaymentMethod').value = method;
        if (method === 'CASH') {
            document.getElementById('btnCASH').classList.add('bg-[#A0A2A4]', 'dark:bg-gray-600');
            document.getElementById('btnGCASH').classList.remove('bg-[#A0A2A4]', 'dark:bg-gray-600');
            document.getElementById('refNumberContainer').classList.add('hidden');
        } else {
            document.getElementById('btnGCASH').classList.add('bg-[#A0A2A4]', 'dark:bg-gray-600');
            document.getElementById('btnCASH').classList.remove('bg-[#A0A2A4]', 'dark:bg-gray-600');
            document.getElementById('refNumberContainer').classList.remove('hidden');
        }
    }
EOD;
$new_payment_js = <<<'EOD'
    function setPaymentMethod(method) {
        document.getElementById('selectedPaymentMethod').value = method;
        if (method === 'CASH') {
            document.getElementById('btnCASH').classList.add('border-2');
            document.getElementById('btnCASH').classList.remove('border');
            document.getElementById('btnGCASH').classList.remove('border-2');
            document.getElementById('btnGCASH').classList.add('border');
            document.getElementById('refNumberContainer').classList.add('hidden');
        } else {
            document.getElementById('btnGCASH').classList.add('border-2');
            document.getElementById('btnGCASH').classList.remove('border');
            document.getElementById('btnCASH').classList.remove('border-2');
            document.getElementById('btnCASH').classList.add('border');
            document.getElementById('refNumberContainer').classList.remove('hidden');
        }
    }
EOD;
$content = str_replace($old_payment_js, $new_payment_js, $content);

file_put_contents($file, $content);
