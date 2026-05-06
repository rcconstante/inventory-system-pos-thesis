<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login();
ensure_product_lifecycle_schema($pdo);
purge_expired_product_archives($pdo);

$canManage = can_manage_catalog();
$productId = max(0, (int) ($_GET['product_id'] ?? 0));

if ($productId <= 0) {
    set_flash('error', 'Select a product to manage its batches.');
    redirect_to('pages/products.php');
}

$productStatement = $pdo->prepare(
    "SELECT
        p.product_id,
        p.product_name,
        COALESCE(p.brand, '') AS brand,
        COALESCE(c.category_name, 'Uncategorized') AS category_name,
        COALESCE(i.current_stock, 0) AS current_stock,
        COALESCE(i.min_stock_level, 0) AS min_stock_level,
        p.price
     FROM Products p
     LEFT JOIN Category c ON c.category_id = p.category_id
     LEFT JOIN Inventory i ON i.product_id = p.product_id
     WHERE p.product_id = :product_id
       AND COALESCE(p.product_status, 'ACTIVE') = 'ACTIVE'
     LIMIT 1"
);
$productStatement->execute(['product_id' => $productId]);
$product = $productStatement->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    set_flash('error', 'That product is not available in the active catalog.');
    redirect_to('pages/products.php');
}

$batches = fetch_batches_for_product($pdo, $productId);

$page_title = 'BATCH MANAGEMENT';
include '../includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <a href="<?php echo h(app_url('pages/products.php')); ?>" class="inline-flex items-center gap-2 rounded border border-black dark:border-gray-600 px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Back to Products
    </a>
    <?php if ($canManage): ?>
        <button type="button" onclick="toggleBatchModal('addBatchModal', true)" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 dark:hover:bg-gray-500">Add Batch</button>
    <?php endif; ?>
</div>

<div class="mb-6 grid gap-4 md:grid-cols-4">
    <div class="rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-5">
        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Product</p>
        <p class="mt-2 text-lg font-bold dark:text-gray-100"><?php echo h($product['product_name']); ?></p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?php echo h($product['brand'] !== '' ? $product['brand'] : 'No brand'); ?></p>
    </div>
    <div class="rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-5">
        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Category</p>
        <p class="mt-2 text-lg font-bold dark:text-gray-100"><?php echo h($product['category_name']); ?></p>
    </div>
    <div class="rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-5">
        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Stock On Hand</p>
        <p class="mt-2 text-lg font-bold dark:text-gray-100"><?php echo h((string) $product['current_stock']); ?></p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Min stock: <?php echo h((string) $product['min_stock_level']); ?></p>
    </div>
    <div class="rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-5">
        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Selling Price</p>
        <p class="mt-2 text-lg font-bold dark:text-gray-100">P<?php echo h(money_format_php((float) $product['price'])); ?></p>
    </div>
</div>

<div class="overflow-hidden rounded-lg border border-black dark:border-gray-600 bg-white dark:bg-gray-800">
    <div class="border-b border-black dark:border-gray-600 px-5 py-4">
        <h2 class="text-base font-bold uppercase tracking-wide dark:text-gray-100">Batches for <?php echo h($product['product_name']); ?></h2>
    </div>

    <?php if ($batches === []): ?>
        <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">No batches recorded for this product.</div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-900/40 text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                        <th class="border-b border-black dark:border-gray-600 p-4 text-left">Batch No</th>
                        <th class="border-b border-black dark:border-gray-600 p-4 text-center">Qty Received</th>
                        <th class="border-b border-black dark:border-gray-600 p-4 text-center">Qty Remaining</th>
                        <th class="border-b border-black dark:border-gray-600 p-4 text-center">Cost</th>
                        <th class="border-b border-black dark:border-gray-600 p-4 text-center">MFG Date</th>
                        <th class="border-b border-black dark:border-gray-600 p-4 text-center">EXP Date</th>
                        <th class="border-b border-black dark:border-gray-600 p-4 text-center">Status</th>
                        <?php if ($canManage): ?>
                            <th class="border-b border-black dark:border-gray-600 p-4 text-center">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $batch): ?>
                        <?php
                        $batchStatus = (string) ($batch['status'] ?? 'ACTIVE');
                        $statusClass = $batchStatus === 'ACTIVE' ? 'text-green-600' : ($batchStatus === 'EXPIRED' ? 'text-red-600' : 'text-gray-500');
                        ?>
                        <tr class="border-b border-black dark:border-gray-600 last:border-b-0 dark:text-gray-100">
                            <td class="p-4 font-medium"><?php echo h($batch['batch_number']); ?></td>
                            <td class="p-4 text-center"><?php echo h((string) $batch['quantity_received']); ?></td>
                            <td class="p-4 text-center"><?php echo h((string) $batch['quantity_remaining']); ?></td>
                            <td class="p-4 text-center"><?php echo h($batch['acquisition_cost'] !== null ? money_format_php((float) $batch['acquisition_cost']) : 'N/A'); ?></td>
                            <td class="p-4 text-center"><?php echo h($batch['manufacturing_date'] ?? 'N/A'); ?></td>
                            <td class="p-4 text-center"><?php echo h($batch['expiration_date'] ?? 'N/A'); ?></td>
                            <td class="p-4 text-center font-semibold <?php echo $statusClass; ?>"><?php echo h($batchStatus); ?></td>
                            <?php if ($canManage): ?>
                                <td class="p-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button" class="rounded border border-black dark:border-gray-600 px-3 py-1 text-xs font-medium hover:bg-gray-50 dark:hover:bg-gray-700"
                                            data-batch-id="<?php echo h((string) $batch['batch_id']); ?>"
                                            data-batch-number="<?php echo h($batch['batch_number']); ?>"
                                            data-batch-cost="<?php echo h($batch['acquisition_cost'] !== null ? (string) $batch['acquisition_cost'] : ''); ?>"
                                            data-batch-mfg="<?php echo h($batch['manufacturing_date'] ?? ''); ?>"
                                            data-batch-exp="<?php echo h($batch['expiration_date'] ?? ''); ?>"
                                            data-batch-qty="<?php echo h((string) $batch['quantity_remaining']); ?>"
                                            data-batch-status="<?php echo h($batchStatus); ?>"
                                            onclick="openEditBatchModal(this)">Edit</button>
                                        <button type="button" class="rounded border border-red-600 px-3 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
                                            data-batch-id="<?php echo h((string) $batch['batch_id']); ?>"
                                            data-batch-number="<?php echo h($batch['batch_number']); ?>"
                                            onclick="openDeleteBatchModal(this)">Delete</button>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
    <div id="addBatchModal" class="hidden fixed inset-0 z-[70] items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-2xl rounded-lg bg-white dark:bg-gray-800 p-6 shadow-xl dark:text-gray-100">
            <div class="mb-4 flex items-center justify-between border-b border-black dark:border-gray-600 pb-3">
                <h3 class="text-lg font-bold">Add New Batch</h3>
                <button type="button" onclick="toggleBatchModal('addBatchModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">Close</button>
            </div>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" value="<?php echo h((string) $productId); ?>">
                <input type="hidden" name="source_product_id" value="<?php echo h((string) $productId); ?>">
                <input type="hidden" name="return_to_batches" value="1">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Batch Number</label>
                        <input type="text" name="batch_number" required class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Quantity</label>
                        <input type="number" name="batch_qty" min="1" required class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Cost Price</label>
                        <input type="number" step="0.01" min="0" name="batch_cost" class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Manufacturing Date</label>
                        <input type="date" name="batch_mfg_date" class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-medium">Expiration Date</label>
                        <input type="date" name="batch_exp_date" class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="toggleBatchModal('addBatchModal', false)" class="rounded border border-black dark:border-gray-600 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                    <button type="submit" name="add_batch" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-sm text-white hover:bg-gray-800 dark:hover:bg-gray-500">Save Batch</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editBatchModal" class="hidden fixed inset-0 z-[70] items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-2xl rounded-lg bg-white dark:bg-gray-800 p-6 shadow-xl dark:text-gray-100">
            <div class="mb-4 flex items-center justify-between border-b border-black dark:border-gray-600 pb-3">
                <h3 class="text-lg font-bold">Edit Batch</h3>
                <button type="button" onclick="toggleBatchModal('editBatchModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">Close</button>
            </div>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="batch_id" id="editBatchId">
                <input type="hidden" name="source_product_id" value="<?php echo h((string) $productId); ?>">
                <input type="hidden" name="return_to_batches" value="1">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Batch Number</label>
                        <input type="text" name="batch_number" id="editBatchNumber" required class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Quantity Remaining</label>
                        <input type="number" name="batch_qty" id="editBatchQty" min="0" required class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Cost Price</label>
                        <input type="number" step="0.01" min="0" name="batch_cost" id="editBatchCost" class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Manufacturing Date</label>
                        <input type="date" name="batch_mfg_date" id="editBatchMfg" class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Expiration Date</label>
                        <input type="date" name="batch_exp_date" id="editBatchExp" class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Status</label>
                        <select name="batch_status" id="editBatchStatus" class="w-full rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-700 dark:text-white">
                            <option value="ACTIVE">ACTIVE</option>
                            <option value="EXPIRED">EXPIRED</option>
                            <option value="DISPOSED">DISPOSED</option>
                        </select>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="toggleBatchModal('editBatchModal', false)" class="rounded border border-black dark:border-gray-600 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                    <button type="submit" name="edit_batch" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-sm text-white hover:bg-gray-800 dark:hover:bg-gray-500">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteBatchModal" class="hidden fixed inset-0 z-[70] items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-lg bg-white dark:bg-gray-800 p-6 shadow-xl dark:text-gray-100">
            <div class="mb-4 flex items-center justify-between border-b border-black dark:border-gray-600 pb-3">
                <h3 class="text-lg font-bold">Delete Batch</h3>
                <button type="button" onclick="toggleBatchModal('deleteBatchModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">Close</button>
            </div>
            <p class="mb-6 text-sm text-gray-700 dark:text-gray-300">Are you sure you want to delete batch <strong id="deleteBatchName"></strong>?</p>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="batch_id" id="deleteBatchId">
                <input type="hidden" name="source_product_id" value="<?php echo h((string) $productId); ?>">
                <input type="hidden" name="return_to_batches" value="1">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="toggleBatchModal('deleteBatchModal', false)" class="rounded border border-black dark:border-gray-600 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                    <button type="submit" name="delete_batch" class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleBatchModal(modalId, shouldOpen) {
            var modal = document.getElementById(modalId);
            if (!modal) {
                return;
            }

            if (shouldOpen) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function openEditBatchModal(button) {
            document.getElementById('editBatchId').value = button.dataset.batchId || '';
            document.getElementById('editBatchNumber').value = button.dataset.batchNumber || '';
            document.getElementById('editBatchQty').value = button.dataset.batchQty || '';
            document.getElementById('editBatchCost').value = button.dataset.batchCost || '';
            document.getElementById('editBatchMfg').value = button.dataset.batchMfg || '';
            document.getElementById('editBatchExp').value = button.dataset.batchExp || '';
            document.getElementById('editBatchStatus').value = button.dataset.batchStatus || 'ACTIVE';
            toggleBatchModal('editBatchModal', true);
        }

        function openDeleteBatchModal(button) {
            document.getElementById('deleteBatchId').value = button.dataset.batchId || '';
            document.getElementById('deleteBatchName').textContent = button.dataset.batchNumber || '';
            toggleBatchModal('deleteBatchModal', true);
        }
    </script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>