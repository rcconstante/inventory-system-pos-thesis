<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login([APP_ROLE_CASHIER]);

$cashierId = (int) current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['return_sale'])) {
        try {
            validate_csrf_or_fail('pages/cashier_dashboard.php');
            
            // Example of role check (if we wanted to restrict returns)
            // if (current_role_id() !== APP_ROLE_ADMIN) {
            //     throw new RuntimeException("Only Admins can process returns.");
            // }

            $saleId = (int)($_POST['sale_id'] ?? 0);
            $reason = trim((string)($_POST['return_reason'] ?? ''));

            if ($saleId <= 0) {
                throw new RuntimeException("Invalid sale ID.");
            }
            if ($reason === '') {
                throw new RuntimeException("Return reason is required.");
            }

            $pdo->beginTransaction();
            
            // Verify the sale exists AND belongs to this cashier (prevents IDOR)
            $checkSale = $pdo->prepare("SELECT status FROM Sale WHERE sale_id = ? AND user_id = ? FOR UPDATE");
            $checkSale->execute([$saleId, $cashierId]);
            $saleStatus = $checkSale->fetchColumn();
            
            if ($saleStatus === false) {
                throw new RuntimeException("Sale not found.");
            }
            if (strtoupper($saleStatus) !== 'COMPLETED') {
                throw new RuntimeException("Only completed transactions can be returned.");
            }
            
            // Mark sale as returned
            $updateSale = $pdo->prepare("UPDATE Sale SET status = 'RETURNED' WHERE sale_id = ?");
            $updateSale->execute([$saleId]);
            
            // Add items back to inventory
            $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM Sale_Item WHERE sale_id = ?");
            $itemsStmt->execute([$saleId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $updateInv = $pdo->prepare("UPDATE Inventory SET current_stock = current_stock + ? WHERE product_id = ?");
            foreach ($items as $item) {
                $updateInv->execute([$item['quantity'], $item['product_id']]);
            }
            
            // If we had a returns table we would log the reason here.
            
            $pdo->commit();
            set_flash('success', 'Transaction #' . $saleId . ' successfully returned.');
            
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', $e->getMessage());
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'An error occurred processing the return.');
        }
        
        redirect_to('pages/cashier_dashboard.php');
    }
}

$transactionPage = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(5, min(50, (int) ($_GET['per_page'] ?? 10)));
$offset = ($transactionPage - 1) * $perPage;

$summaryStatement = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN DATE(`date`) = CURDATE() AND status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) AS daily_sales,
        COALESCE(SUM(CASE WHEN DATE(`date`) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) AS weekly_sales
     FROM Sale
     WHERE user_id = :user_id"
);
$summaryStatement->execute(['user_id' => $cashierId]);
$summary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: ['daily_sales' => 0, 'weekly_sales' => 0];

$countStatement = $pdo->prepare('SELECT COUNT(*) FROM Sale WHERE user_id = :user_id AND DATE(`date`) = CURDATE()');
$countStatement->execute(['user_id' => $cashierId]);
$totalTransactions = (int) $countStatement->fetchColumn();

// Separate count for the summary card — only COMPLETED (not returned) sales count as productive transactions
$completedCountStatement = $pdo->prepare("SELECT COUNT(*) FROM Sale WHERE user_id = :user_id AND DATE(`date`) = CURDATE() AND status = 'COMPLETED'");
$completedCountStatement->execute(['user_id' => $cashierId]);
$completedTransactions = (int) $completedCountStatement->fetchColumn();

$totalPages = max(1, (int) ceil($totalTransactions / $perPage));

if ($transactionPage > $totalPages) {
    $transactionPage = $totalPages;
    $offset = ($transactionPage - 1) * $perPage;
}

$transactionsStatement = $pdo->prepare(
    "SELECT sale_id, payment_method, total_amount, status, `date`
     FROM Sale
     WHERE user_id = :user_id AND DATE(`date`) = CURDATE()
     ORDER BY `date` DESC, sale_id DESC
     LIMIT :limit OFFSET :offset"
);
$transactionsStatement->bindValue(':user_id', $cashierId, PDO::PARAM_INT);
$transactionsStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$transactionsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$transactionsStatement->execute();
$transactions = $transactionsStatement->fetchAll(PDO::FETCH_ASSOC);

// Fetch items for these transactions to display in the modal
$transactionItems = [];
if (!empty($transactions)) {
    $saleIds = array_column($transactions, 'sale_id');
    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT si.sale_id, si.quantity, si.selling_price, si.subtotal, p.product_name FROM Sale_Item si JOIN Products p ON si.product_id = p.product_id WHERE si.sale_id IN ($placeholders)");
    $itemsStmt->execute($saleIds);
    $all_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_items as $item) {
        $transactionItems[$item['sale_id']][] = $item;
    }
}

$page_title = 'DASHBOARD';
include '../includes/header.php';
?>

<h2 class="mb-8 text-2xl font-normal text-black dark:text-gray-100">WELCOME TO FIVE BROTHERS TRADING</h2>

<div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2">
    <div class="flex flex-col items-center justify-center rounded-lg bg-[#8E9CFF] p-8 text-black text-center shadow-sm h-32">
        <p class="text-lg font-normal mb-1">Daily Transaction (Today)</p>
        <p class="text-3xl font-bold"><?php echo h((string) $completedTransactions); ?></p>
    </div>

    <div class="flex flex-col items-center justify-center rounded-lg bg-[#4CD995] p-8 text-black text-center shadow-sm h-32">
        <p class="text-lg font-normal mb-1">Daily Sales (Today)</p>
        <p class="text-3xl font-bold">P<?php echo h(money_format_php((float) $summary['daily_sales'])); ?></p>
    </div>
</div>

<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
        <h3 class="text-base font-bold">TODAY'S TRANSACTION</h3>
    </div>

    <div class="overflow-hidden rounded-lg border border-black dark:border-gray-600">
        <div class="border-b border-black dark:border-gray-600 bg-white dark:bg-gray-800">
            <div class="grid grid-cols-4 gap-4 p-4 text-sm font-semibold dark:text-gray-300">
                <div>ORDER ID</div>
                <div>PAYMENT METHOD</div>
                <div>AMOUNT</div>
                <div>STATUS</div>
            </div>
        </div>

        <?php if ($transactions === []): ?>
            <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">No transactions recorded today.</div>
        <?php else: ?>
            <?php foreach ($transactions as $transaction): 
                $saleId = $transaction['sale_id'];
                $items = $transactionItems[$saleId] ?? [];
                $jsonSale = json_encode([
                    'id' => $saleId,
                    'date' => date('F d, Y', strtotime($transaction['date'])),
                    'method' => $transaction['payment_method'],
                    'total' => $transaction['total_amount'],
                    'status' => strtoupper($transaction['status']),
                    'items' => $items
                ]);
            ?>
                <div onclick="openReceiptModal(<?php echo htmlspecialchars($jsonSale, ENT_QUOTES, 'UTF-8'); ?>)" class="grid grid-cols-4 gap-4 border-b border-black dark:border-gray-600 p-4 text-sm last:border-b-0 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100 transition-colors">
                    <div>#<?php echo h((string) $transaction['sale_id']); ?></div>
                    <div><?php echo h((string) $transaction['payment_method']); ?></div>
                    <div>P<?php echo h(money_format_php((float) $transaction['total_amount'])); ?></div>
                    <div class="font-medium <?php echo strtoupper((string) $transaction['status']) === 'COMPLETED' ? 'text-green-700' : (strtoupper((string) $transaction['status']) === 'RETURNED' ? 'text-red-600' : 'text-yellow-700'); ?>">
                        <?php echo h((string) $transaction['status']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="mt-6 flex justify-end gap-3">
        <?php if ($transactionPage > 1): ?>
            <a href="?page=<?php echo h((string) ($transactionPage - 1)); ?>&per_page=<?php echo h((string) $perPage); ?>" class="rounded-lg border border-black dark:border-gray-600 px-6 py-2 text-black dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700">Previous</a>
        <?php else: ?>
            <button disabled class="rounded-lg border border-gray-300 dark:border-gray-600 px-6 py-2 text-gray-400 dark:text-gray-500 cursor-not-allowed">Previous</button>
        <?php endif; ?>

        <?php if ($transactionPage < $totalPages): ?>
            <a href="?page=<?php echo h((string) ($transactionPage + 1)); ?>&per_page=<?php echo h((string) $perPage); ?>" class="rounded-lg border border-black dark:border-gray-600 px-6 py-2 text-black dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700">Next</a>
        <?php else: ?>
            <button disabled class="rounded-lg border border-gray-300 dark:border-gray-600 px-6 py-2 text-gray-400 dark:text-gray-500 cursor-not-allowed">Next</button>
        <?php endif; ?>
    </div>
</div>


<!-- Receipt Modal -->
<div id="receiptModal" class="hidden fixed inset-0 z-[110] items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
    <div class="w-[500px] bg-white dark:bg-gray-800 text-black dark:text-white border border-black dark:border-gray-600 shadow-2xl flex flex-col relative">
        <button type="button" onclick="closeReceiptModal()" class="absolute top-4 right-4 text-black dark:text-white hover:text-gray-600">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        
        <div class="p-8 text-center border-b border-black dark:border-gray-600">
            <h2 class="text-xl font-medium tracking-widest uppercase mb-2">FIVE BROTHERS TRADING</h2>
            <p class="text-sm mb-1 text-gray-800 dark:text-gray-300">0961-195-6139</p>
            <p class="text-sm mb-1 text-gray-800 dark:text-gray-300">Mabuhay Carmona, Cavite</p>
            <p class="text-sm mb-1 text-gray-800 dark:text-gray-300">Opening Hours: 9:00 AM - 3:00 PM</p>
            <p class="text-sm mt-3 font-medium" id="rm-date">Date: </p>
        </div>
        
        <div class="p-8 flex-1 overflow-auto max-h-[40vh]">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left font-bold border-b border-black dark:border-gray-600">
                        <th class="pb-3 w-16">QTY</th>
                        <th class="pb-3">DESCRIPTION</th>
                        <th class="pb-3 text-right">AMOUNT</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700" id="rm-items">
                    <!-- Items injected here -->
                </tbody>
            </table>
        </div>
        
        <div class="p-8 border-t border-black dark:border-gray-600 bg-white dark:bg-gray-800">
            <div class="text-sm font-bold mb-3 uppercase">TOTAL: <span id="rm-total"></span></div>
            <div class="text-sm font-bold mb-8 uppercase">PAID BY: <span id="rm-method"></span></div>
            
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium">Thank You :)</div>
                <button type="button" id="rm-return-btn" onclick="openReturnModal()" class="border border-black dark:border-gray-400 px-6 py-2 text-sm font-bold hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors uppercase tracking-widest">RETURN</button>
            </div>
        </div>
    </div>
</div>

<!-- Return Modal -->
<div id="returnModal" class="hidden fixed inset-0 z-[120] items-center justify-center bg-black/60 p-4 backdrop-blur-md">
    <div class="w-[450px] bg-white dark:bg-gray-800 text-black dark:text-white border border-black dark:border-gray-600 shadow-2xl p-8">
        <h3 class="text-lg font-bold uppercase mb-6 tracking-wide border-b border-black dark:border-gray-600 pb-4">Process Return</h3>
        <p class="mb-4 text-sm font-medium">Transaction: <span id="ret-sale-id-display" class="font-bold"></span></p>
        
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="return_sale" value="1">
            <input type="hidden" name="sale_id" id="ret-sale-id" value="">
            
            <div class="mb-8">
                <label class="block text-sm font-bold mb-2 uppercase tracking-wide">Reason for Return</label>
                <select name="return_reason" required class="w-full border border-black dark:border-gray-500 bg-transparent px-4 py-3 focus:outline-none dark:text-gray-100">
                    <option value="" class="text-black dark:text-gray-100 dark:bg-gray-800">Select a reason...</option>
                    <option value="Wrong Item Received" class="text-black dark:text-gray-100 dark:bg-gray-800">Wrong Item Received</option>
                    <option value="Damaged Item" class="text-black dark:text-gray-100 dark:bg-gray-800">Damaged Item</option>
                    <option value="Expired Product" class="text-black dark:text-gray-100 dark:bg-gray-800">Expired Product</option>
                    <option value="Customer Changed Mind" class="text-black dark:text-gray-100 dark:bg-gray-800">Customer Changed Mind</option>
                    <option value="Other" class="text-black dark:text-gray-100 dark:bg-gray-800">Other</option>
                </select>
            </div>
            
            <div class="flex justify-end gap-4">
                <button type="button" onclick="closeReturnModal()" class="border border-black dark:border-gray-500 px-6 py-2 text-sm font-bold uppercase hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors tracking-wide">CANCEL</button>
                <button type="submit" class="bg-red-600 text-white px-6 py-2 text-sm font-bold uppercase hover:bg-red-700 transition-colors tracking-wide">CONFIRM RETURN</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentSaleId = null;

    function openReceiptModal(saleData) {
        currentSaleId = saleData.id;
        document.getElementById('rm-date').textContent = 'Date: ' + saleData.date;
        document.getElementById('rm-total').textContent = parseFloat(saleData.total).toFixed(2);
        document.getElementById('rm-method').textContent = saleData.method;
        
        const tbody = document.getElementById('rm-items');
        tbody.innerHTML = '';
        saleData.items.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="py-4 font-medium">${item.quantity}</td>
                <td class="py-4 font-medium">${item.product_name}</td>
                <td class="py-4 text-right font-medium">${parseFloat(item.subtotal).toFixed(2)}</td>
            `;
            tbody.appendChild(tr);
        });
        
        // Hide return button if already returned
        const returnBtn = document.getElementById('rm-return-btn');
        if (saleData.status === 'RETURNED') {
            returnBtn.style.display = 'none';
        } else {
            returnBtn.style.display = 'block';
        }

        document.getElementById('receiptModal').classList.remove('hidden');
        document.getElementById('receiptModal').classList.add('flex');
    }

    function closeReceiptModal() {
        document.getElementById('receiptModal').classList.add('hidden');
        document.getElementById('receiptModal').classList.remove('flex');
    }

    function openReturnModal() {
        closeReceiptModal();
        document.getElementById('ret-sale-id').value = currentSaleId;
        document.getElementById('ret-sale-id-display').textContent = '#' + currentSaleId;
        document.getElementById('returnModal').classList.remove('hidden');
        document.getElementById('returnModal').classList.add('flex');
    }

    function closeReturnModal() {
        document.getElementById('returnModal').classList.add('hidden');
        document.getElementById('returnModal').classList.remove('flex');
    }
</script>
<?php include '../includes/footer.php'; ?>
