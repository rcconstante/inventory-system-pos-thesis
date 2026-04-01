<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login();

$canManage = can_manage_catalog();
$canDelete = can_delete_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        set_flash('error', 'You do not have permission to manage products.');
        redirect_to('pages/products.php');
    }

    validate_csrf_or_fail('pages/products.php');

    try {
        if (isset($_POST['add_product']) || isset($_POST['edit_product'])) {
            $productId = isset($_POST['edit_product']) ? (int) ($_POST['product_id'] ?? 0) : null;
            $productName = trim((string) ($_POST['product_name'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $brand = trim((string) ($_POST['brand'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $price = (float) ($_POST['price'] ?? 0);
            $retailPrice = ($_POST['retail_price'] ?? '') !== '' ? (float) $_POST['retail_price'] : null;
            $acquisitionCost = ($_POST['acquisition_cost'] ?? '') !== '' ? (float) $_POST['acquisition_cost'] : null;
            $manufacturingDate = !empty($_POST['manufacturing_date']) ? $_POST['manufacturing_date'] : null;
            $expirationDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $productType = trim((string) ($_POST['product_type'] ?? ''));
            $specification = trim((string) ($_POST['specification'] ?? ''));
            $compatibility = trim((string) ($_POST['compatibility'] ?? ''));
            $currentStock = max(0, (int) ($_POST['current_stock'] ?? 0));
            $minStockLevel = max(0, (int) ($_POST['min_stock_level'] ?? 0));
            $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

            if ($productName === '') {
                throw new RuntimeException('Product name is required.');
            }

            if ($categoryId <= 0) {
                throw new RuntimeException('Please select a category.');
            }

            if ($price < 0) {
                throw new RuntimeException('Price cannot be negative.');
            }

            $pdo->beginTransaction();

            if ($productId === null) {
                $insertProduct = $pdo->prepare(
                    'INSERT INTO Products (
                        product_name,
                        brand,
                        description,
                        price,
                        retail_price,
                        acquisition_cost,
                        manufacturing_date,
                        expiration_date,
                        category_id,
                        product_type,
                        specification,
                        compatibility
                     ) VALUES (
                        :product_name,
                        :brand,
                        :description,
                        :price,
                        :retail_price,
                        :acquisition_cost,
                        :manufacturing_date,
                        :expiration_date,
                        :category_id,
                        :product_type,
                        :specification,
                        :compatibility
                     )'
                );
                $insertProduct->execute([
                    'product_name' => $productName,
                    'brand' => $brand,
                    'description' => $description,
                    'price' => $price,
                    'retail_price' => $retailPrice,
                    'acquisition_cost' => $acquisitionCost,
                    'manufacturing_date' => $manufacturingDate,
                    'expiration_date' => $expirationDate,
                    'category_id' => $categoryId,
                    'product_type' => $productType,
                    'specification' => $specification,
                    'compatibility' => $compatibility,
                ]);

                $productId = (int) $pdo->lastInsertId();

                $insertInventory = $pdo->prepare(
                    'INSERT INTO Inventory (product_id, current_stock, min_stock_level, expiry_date)
                     VALUES (:product_id, :current_stock, :min_stock_level, :expiry_date)'
                );
                $insertInventory->execute([
                    'product_id' => $productId,
                    'current_stock' => $currentStock,
                    'min_stock_level' => $minStockLevel,
                    'expiry_date' => $expiryDate,
                ]);

                // Create initial batch
                if ($currentStock > 0) {
                    create_stock_batch($pdo, $productId, $currentStock, $acquisitionCost, $manufacturingDate, $expiryDate);
                }

                set_flash('success', 'Product created successfully.');
            } else {
                $updateProduct = $pdo->prepare(
                    'UPDATE Products
                     SET product_name = :product_name,
                         brand = :brand,
                         description = :description,
                         price = :price,
                         retail_price = :retail_price,
                         acquisition_cost = :acquisition_cost,
                         manufacturing_date = :manufacturing_date,
                         expiration_date = :expiration_date,
                         category_id = :category_id,
                         product_type = :product_type,
                         specification = :specification,
                         compatibility = :compatibility
                     WHERE product_id = :product_id'
                );
                $updateProduct->execute([
                    'product_name' => $productName,
                    'brand' => $brand,
                    'description' => $description,
                    'price' => $price,
                    'retail_price' => $retailPrice,
                    'acquisition_cost' => $acquisitionCost,
                    'manufacturing_date' => $manufacturingDate,
                    'expiration_date' => $expirationDate,
                    'category_id' => $categoryId,
                    'product_type' => $productType,
                    'specification' => $specification,
                    'compatibility' => $compatibility,
                    'product_id' => $productId,
                ]);

                $inventoryStatement = $pdo->prepare('SELECT inventory_id FROM Inventory WHERE product_id = :product_id LIMIT 1');
                $inventoryStatement->execute(['product_id' => $productId]);
                $inventoryRow = $inventoryStatement->fetch(PDO::FETCH_ASSOC);

                if ($inventoryRow) {
                    $updateInventory = $pdo->prepare(
                        'UPDATE Inventory
                         SET current_stock = :current_stock, min_stock_level = :min_stock_level, expiry_date = :expiry_date
                         WHERE product_id = :product_id'
                    );
                    $updateInventory->execute([
                        'current_stock' => $currentStock,
                        'min_stock_level' => $minStockLevel,
                        'expiry_date' => $expiryDate,
                        'product_id' => $productId,
                    ]);
                } else {
                    $insertInventory = $pdo->prepare(
                        'INSERT INTO Inventory (product_id, current_stock, min_stock_level, expiry_date)
                         VALUES (:product_id, :current_stock, :min_stock_level, :expiry_date)'
                    );
                    $insertInventory->execute([
                        'product_id' => $productId,
                        'current_stock' => $currentStock,
                        'min_stock_level' => $minStockLevel,
                        'expiry_date' => $expiryDate,
                    ]);
                }

                set_flash('success', 'Product updated successfully.');
            }

            sync_reorder_alerts_for_catalog($pdo);
            sync_feature_matches_for_catalog($pdo);
            $pdo->commit();
        } elseif (isset($_POST['delete_product'])) {
            if (!$canDelete) {
                throw new RuntimeException('You do not have permission to delete products.');
            }
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Invalid product selected.');
            }

            $pdo->beginTransaction();

            $deleteStatement = $pdo->prepare('DELETE FROM Products WHERE product_id = :product_id');
            $deleteStatement->execute(['product_id' => $productId]);

            sync_reorder_alerts_for_catalog($pdo);
            sync_feature_matches_for_catalog($pdo);
            $pdo->commit();

            set_flash('success', 'Product deleted successfully.');
        }
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        set_flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        set_flash('error', 'The product action could not be completed.');
    }

    redirect_to('pages/products.php');
}

$categoriesStatement = $pdo->query('SELECT * FROM Category ORDER BY category_name ASC');
$categories = $categoriesStatement->fetchAll(PDO::FETCH_ASSOC);

$selectedCategoryId = max(0, (int) ($_GET['category_id'] ?? 0));
$searchTerm = trim((string) ($_GET['search'] ?? ''));

$productsQuery = "
    SELECT
        p.*,
        c.category_name,
        COALESCE(i.current_stock, 0) AS current_stock,
        COALESCE(i.min_stock_level, 0) AS min_stock_level,
        i.expiry_date,
        COALESCE(si.sales_count, 0) AS sales_count
    FROM Products p
    LEFT JOIN Category c ON p.category_id = c.category_id
    LEFT JOIN Inventory i ON p.product_id = i.product_id
    LEFT JOIN (
        SELECT si.product_id, SUM(si.quantity) AS sales_count
        FROM Sale_Item si
        JOIN Sale s ON si.sale_id = s.sale_id AND s.status = 'COMPLETED'
        GROUP BY si.product_id
    ) si ON p.product_id = si.product_id
    WHERE 1=1
";

$queryParams = [];
if ($selectedCategoryId > 0) {
    $productsQuery .= ' AND p.category_id = :category_id';
    $queryParams['category_id'] = $selectedCategoryId;
}
if ($searchTerm !== '') {
    $productsQuery .= ' AND (p.product_name LIKE :st1 OR p.brand LIKE :st2 OR p.compatibility LIKE :st3 OR p.specification LIKE :st4 OR c.category_name LIKE :st5)';
    $queryParams['st1'] = '%' . $searchTerm . '%';
    $queryParams['st2'] = '%' . $searchTerm . '%';
    $queryParams['st3'] = '%' . $searchTerm . '%';
    $queryParams['st4'] = '%' . $searchTerm . '%';
    $queryParams['st5'] = '%' . $searchTerm . '%';
}

$productsQuery .= ' ORDER BY p.product_name ASC';
$productsStatement = $pdo->prepare($productsQuery);
foreach ($queryParams as $param => $value) {
    if ($param === 'category_id') {
        $productsStatement->bindValue(':category_id', $value, PDO::PARAM_INT);
    } else {
        $productsStatement->bindValue(':' . $param, $value, PDO::PARAM_STR);
    }
}
$productsStatement->execute();
$products = $productsStatement->fetchAll(PDO::FETCH_ASSOC);

$recommendations = recommendations_enabled()
    ? fetch_recommendations_for_products($pdo, array_map(static fn (array $product): int => (int) $product['product_id'], $products))
    : [];

// Compute average sales count to classify products relatively (consistent with dashboard ranking)
$allSalesCounts = array_map(static fn (array $p): int => (int) ($p['sales_count'] ?? 0), $products);
$avgSalesCount = count($allSalesCounts) > 0 ? array_sum($allSalesCounts) / count($allSalesCounts) : 0;

$page_title = 'PRODUCTS';
include '../includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <form method="GET" class="flex items-center gap-4 flex-wrap">
        <span class="text-sm font-medium">Stock Inventory</span>
        <select name="category_id" onchange="this.form.submit()" class="cursor-pointer rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 dark:text-gray-100 px-3 py-2 text-sm">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?php echo h((string) $category['category_id']); ?>" <?php echo $selectedCategoryId === (int) $category['category_id'] ? 'selected' : ''; ?>>
                    <?php echo h($category['category_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="relative">
            <input type="text" name="search" value="<?php echo h($searchTerm); ?>" placeholder="Search products..." class="rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 dark:text-gray-100 pl-9 pr-3 py-2 text-sm w-64 focus:outline-none focus:ring focus:ring-blue-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        </div>
        <button type="submit" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-sm text-white hover:bg-gray-800 dark:hover:bg-gray-500">Search</button>
    </form>

    <?php if ($canManage): ?>
        <button type="button" onclick="toggleProductModal('addProductModal', true)" class="flex items-center gap-2 rounded-md border border-black dark:border-gray-600 px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">
            <span>+ Add New Product</span>
        </button>
    <?php endif; ?>
</div>

<div class="overflow-x-auto bg-white dark:bg-gray-800 mb-8">
    <table class="w-full border-collapse border border-black dark:border-gray-600 text-sm">
        <thead>
            <tr class="text-sm font-bold uppercase text-gray-700 dark:text-gray-300">
                <th class="border border-black dark:border-gray-600 p-4 w-[100px] text-center">PRODUCT ID</th>
                <th class="border border-black dark:border-gray-600 p-4 text-left">PRODUCTS</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center">CATEGORY</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center">PRICE</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center w-[130px]">EXP DATE</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center w-[90px]">STOCK</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center w-[110px]">SALES COUNT</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center w-[150px]">STATUS</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($products === []): ?>
            <tr>
                <td colspan="8" class="border border-black dark:border-gray-600 p-8 text-center text-gray-500 dark:text-gray-400">No products found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($products as $product): ?>
                <?php
                $productId = (int) $product['product_id'];
                $currentStock = (int) $product['current_stock'];
                $minStockLevel = (int) $product['min_stock_level'];
                $salesCount = (int) ($product['sales_count'] ?? 0);
                
                // Classify relative to the average across displayed products (consistent with dashboard ranking)
                $statusLabel = ($salesCount > 0 && $salesCount >= $avgSalesCount) ? 'FAST MOVING' : 'SLOW MOVING';

                $productRecommendations = $recommendations[$productId] ?? [];
                ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer" onclick="openProductDetailModal(<?php echo $productId; ?>)">
                    <td class="border border-black dark:border-gray-600 p-4 text-center font-medium dark:text-gray-100">
                        P<?php echo str_pad((string)$productId, 3, '0', STR_PAD_LEFT); ?>
                    </td>
                    <td class="border border-black dark:border-gray-600 p-4 text-left">
                        <div class="font-medium text-black dark:text-gray-100"><?php echo h($product['product_name']); ?></div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            <?php echo h($product['brand'] ?: 'No brand'); ?>
                            <?php if ($product['product_type'] !== ''): ?>
                                <span class="mx-1">|</span>
                                <?php echo h($product['product_type']); ?>
                            <?php endif; ?>
                            <?php if ($product['compatibility'] !== ''): ?>
                                <span class="mx-1">|</span>
                                <?php echo h($product['compatibility']); ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center text-black dark:text-gray-100"><?php echo h($product['category_name'] ?? 'N/A'); ?></td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center text-black dark:text-gray-100">&#8369;<?php echo h(money_format_php((float) $product['price'])); ?></td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center text-black dark:text-gray-100"><?php echo h($product['expiry_date'] ? date('M d, Y', strtotime($product['expiry_date'])) : 'N/A'); ?></td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center <?php echo $currentStock <= $minStockLevel && $minStockLevel > 0 ? 'text-red-600 font-bold' : 'text-black dark:text-gray-100'; ?>"><?php echo h((string) $currentStock); ?></td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center text-black dark:text-gray-100"><?php echo h((string) $salesCount); ?></td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center text-black dark:text-gray-100">
                        <span class="text-xs font-semibold uppercase <?php echo $statusLabel === 'FAST MOVING' ? 'text-green-600' : 'text-orange-500'; ?>"><?php echo h($statusLabel); ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($canManage): ?>
    <div id="addProductModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white dark:bg-gray-800 p-8 shadow-xl dark:text-gray-100">
            <h2 class="text-xl font-bold mb-6">ADD PRODUCT</h2>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
                <?php echo csrf_field(); ?>
                <?php $formPrefix = ''; include __DIR__ . '/product_form_fields.php'; ?>
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" onclick="toggleProductModal('addProductModal', false)" class="rounded border border-black dark:border-gray-500 px-6 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">Close</button>
                    <button type="submit" name="add_product" class="rounded bg-black dark:bg-gray-600 px-6 py-2 text-sm font-medium text-white hover:bg-gray-800 dark:hover:bg-gray-500">Create</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editProductModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white dark:bg-gray-800 p-6 shadow-xl dark:text-gray-100">
            <div class="mb-4 flex items-center justify-between border-b dark:border-gray-600 pb-3">
                <h2 class="text-xl font-bold">Edit Product</h2>
                <button type="button" onclick="toggleProductModal('editProductModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">Close</button>
            </div>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>" class="space-y-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" id="edit_product_id">
                <?php $formPrefix = 'edit_'; include __DIR__ . '/product_form_fields.php'; ?>
                <div class="flex justify-end gap-2 border-t dark:border-gray-600 pt-4">
                    <button type="button" onclick="toggleProductModal('editProductModal', false)" class="rounded border dark:border-gray-600 px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">Cancel</button>
                    <button type="submit" name="edit_product" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-white hover:bg-gray-800 dark:hover:bg-gray-500">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php if ($canDelete): ?>
    <div id="deleteProductModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-lg bg-white dark:bg-gray-800 p-6 shadow-xl dark:text-gray-100">
            <div class="mb-4 flex items-center justify-between border-b dark:border-gray-600 pb-3">
                <h2 class="text-lg font-bold">Delete Product</h2>
                <button type="button" onclick="toggleProductModal('deleteProductModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">Close</button>
            </div>
            <p class="mb-6 text-sm text-gray-700 dark:text-gray-300">Are you sure you want to delete <strong id="deleteProductName"></strong>? This action cannot be undone.</p>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" id="deleteProductId">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="toggleProductModal('deleteProductModal', false)" class="rounded border dark:border-gray-600 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">Cancel</button>
                    <button type="submit" name="delete_product" class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">Delete</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Product Detail Modal -->
<div id="productDetailModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-2xl rounded-lg bg-white dark:bg-gray-800 shadow-lg flex flex-col" style="max-height:90vh;">
        <div class="flex items-center justify-between border-b dark:border-gray-700 p-4">
            <h3 id="detailProductTitle" class="text-lg font-bold dark:text-white"></h3>
            <button type="button" onclick="toggleProductModal('productDetailModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">Close</button>
        </div>
        <div id="detailProductContent" class="p-4 overflow-y-auto dark:text-gray-100"></div>
        <div id="detailProductActions" class="flex justify-end gap-2 border-t dark:border-gray-700 p-4"></div>
    </div>
</div>

<script>
    function toggleProductModal(modalId, shouldOpen) {
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

    function openEditProductModal(button) {
        document.getElementById('edit_product_id').value = button.dataset.productId || '';
        document.getElementById('edit_product_name').value = button.dataset.productName || '';
        document.getElementById('edit_category_id').value = button.dataset.categoryId || '';
        document.getElementById('edit_brand').value = button.dataset.brand || '';
        document.getElementById('edit_description').value = button.dataset.description || '';
        document.getElementById('edit_price').value = button.dataset.price || '';
        document.getElementById('edit_retail_price').value = button.dataset.retailPrice || '';
        document.getElementById('edit_acquisition_cost').value = button.dataset.acquisitionCost || '';
        document.getElementById('edit_manufacturing_date').value = button.dataset.manufacturingDate || '';
        document.getElementById('edit_product_type').value = button.dataset.productType || '';
        document.getElementById('edit_specification').value = button.dataset.specification || '';
        document.getElementById('edit_compatibility').value = button.dataset.compatibility || '';
        document.getElementById('edit_current_stock').value = button.dataset.currentStock || '';
        document.getElementById('edit_min_stock_level').value = button.dataset.minStockLevel || '';
        document.getElementById('edit_expiry_date').value = button.dataset.expiryDate || '';
        toggleProductModal('editProductModal', true);
    }

    function openDeleteProductModal(button) {
        document.getElementById('deleteProductId').value = button.dataset.productId || '';
        document.getElementById('deleteProductName').textContent = button.dataset.productName || '';
        toggleProductModal('deleteProductModal', true);
    }

    // Product Detail Modal
    var productData = <?php echo json_encode(array_map(function($p) use ($pdo, $recommendations, $canManage) {
        $batches = fetch_batches_for_product($pdo, (int)$p['product_id']);
        $recs = $recommendations[(int)$p['product_id']] ?? [];
        return [
            'id' => (int)$p['product_id'],
            'name' => $p['product_name'],
            'brand' => $p['brand'] ?? '',
            'category' => $p['category_name'] ?? 'N/A',
            'category_id' => (int)($p['category_id'] ?? 0),
            'description' => $p['description'] ?? '',
            'price' => (float)$p['price'],
            'retail_price' => $p['retail_price'] !== null ? (float)$p['retail_price'] : null,
            'acquisition_cost' => $p['acquisition_cost'] !== null ? (float)$p['acquisition_cost'] : null,
            'manufacturing_date' => $p['manufacturing_date'] ?? '',
            'expiration_date' => $p['expiration_date'] ?? '',
            'product_type' => $p['product_type'] ?? '',
            'specification' => $p['specification'] ?? '',
            'compatibility' => $p['compatibility'] ?? '',
            'current_stock' => (int)$p['current_stock'],
            'min_stock_level' => (int)$p['min_stock_level'],
            'expiry_date' => $p['expiry_date'] ?? '',
            'sales_count' => (int)($p['sales_count'] ?? 0),
            'batches' => array_map(function($b) {
                return [
                    'batch_number' => $b['batch_number'],
                    'qty_remaining' => (int)$b['quantity_remaining'],
                    'qty_received' => (int)$b['quantity_received'],
                    'acquisition_cost' => $b['acquisition_cost'] !== null ? (float)$b['acquisition_cost'] : null,
                    'manufacturing_date' => $b['manufacturing_date'] ?? '',
                    'expiration_date' => $b['expiration_date'] ?? '',
                    'date_received' => $b['date_received'] ?? '',
                    'is_depleted' => (int)$b['is_depleted'],
                ];
            }, $batches),
            'recommendations' => array_map(function($r) {
                return ['name' => $r['alternative_name'], 'brand' => $r['alternative_brand'] ?? '', 'price' => (float)$r['price'], 'score' => (float)$r['similarity_score']];
            }, array_slice($recs, 0, 5)),
            'can_manage' => $canManage,
        ];
    }, $products)); ?>;

    function openProductDetailModal(productId) {
        var product = productData.find(function(p) { return p.id === productId; });
        if (!product) return;

        var html = '<div class="space-y-4">';
        html += '<div class="grid grid-cols-2 gap-4 text-sm">';
        html += '<div><span class="font-bold">Product ID:</span> P' + String(product.id).padStart(3, '0') + '</div>';
        html += '<div><span class="font-bold">Category:</span> ' + escapeHtml(product.category) + '</div>';
        html += '<div><span class="font-bold">Brand:</span> ' + escapeHtml(product.brand || 'N/A') + '</div>';
        html += '<div><span class="font-bold">Type:</span> ' + escapeHtml(product.product_type || 'N/A') + '</div>';
        html += '<div><span class="font-bold">Selling Price:</span> ₱' + product.price.toFixed(2) + '</div>';
        if (product.retail_price !== null) html += '<div><span class="font-bold">Retail Price:</span> ₱' + product.retail_price.toFixed(2) + '</div>';
        if (product.acquisition_cost !== null) html += '<div><span class="font-bold">Acquisition Cost:</span> ₱' + product.acquisition_cost.toFixed(2) + '</div>';
        html += '<div><span class="font-bold">Stock:</span> ' + product.current_stock + '</div>';
        html += '<div><span class="font-bold">Critical Stock:</span> ' + product.min_stock_level + '</div>';
        html += '<div><span class="font-bold">Sales Count:</span> ' + product.sales_count + '</div>';
        if (product.manufacturing_date) html += '<div><span class="font-bold">Mfg Date:</span> ' + product.manufacturing_date + '</div>';
        if (product.expiry_date) html += '<div><span class="font-bold">Exp Date:</span> ' + product.expiry_date + '</div>';
        html += '</div>';

        if (product.compatibility) html += '<div class="text-sm"><span class="font-bold">Compatibility:</span> ' + escapeHtml(product.compatibility) + '</div>';
        if (product.specification) html += '<div class="text-sm"><span class="font-bold">Specification:</span> ' + escapeHtml(product.specification) + '</div>';
        if (product.description) html += '<div class="text-sm"><span class="font-bold">Description:</span> ' + escapeHtml(product.description) + '</div>';

        // Batches
        html += '<div class="mt-4"><h4 class="font-bold text-sm mb-2 uppercase">Batch Inventory (FIFO)</h4>';
        if (product.batches.length === 0) {
            html += '<div class="text-sm text-gray-500">No batches recorded.</div>';
        } else {
            html += '<div class="overflow-x-auto"><table class="w-full text-xs border-collapse border border-gray-300 dark:border-gray-600">';
            html += '<thead><tr class="bg-gray-100 dark:bg-gray-700"><th class="border border-gray-300 dark:border-gray-600 p-2">Batch #</th><th class="border border-gray-300 dark:border-gray-600 p-2">Qty</th><th class="border border-gray-300 dark:border-gray-600 p-2">Cost</th><th class="border border-gray-300 dark:border-gray-600 p-2">Mfg Date</th><th class="border border-gray-300 dark:border-gray-600 p-2">Exp Date</th><th class="border border-gray-300 dark:border-gray-600 p-2">Status</th></tr></thead><tbody>';
            product.batches.forEach(function(b) {
                var statusClass = b.is_depleted ? 'text-red-500' : 'text-green-600';
                var statusText = b.is_depleted ? 'Depleted' : 'Active';
                html += '<tr><td class="border border-gray-300 dark:border-gray-600 p-2">' + escapeHtml(b.batch_number) + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">' + b.qty_remaining + '/' + b.qty_received + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">' + (b.acquisition_cost !== null ? '₱' + b.acquisition_cost.toFixed(2) : 'N/A') + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">' + (b.manufacturing_date || 'N/A') + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">' + (b.expiration_date || 'N/A') + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center ' + statusClass + ' font-bold">' + statusText + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        html += '</div>';

        // Recommendations
        if (product.recommendations.length > 0) {
            html += '<div class="mt-4"><h4 class="font-bold text-sm mb-2 uppercase">Recommended Alternatives</h4>';
            html += '<div class="space-y-1">';
            product.recommendations.forEach(function(r) {
                html += '<div class="text-xs flex justify-between border-b dark:border-gray-600 pb-1"><span>' + escapeHtml(r.name) + ' (' + escapeHtml(r.brand) + ')</span><span>₱' + r.price.toFixed(2) + ' | Score: ' + r.score.toFixed(2) + '</span></div>';
            });
            html += '</div></div>';
        }

        html += '</div>';

        document.getElementById('detailProductTitle').textContent = product.name;
        document.getElementById('detailProductContent').innerHTML = html;

        // Show/hide action buttons
        var actionsDiv = document.getElementById('detailProductActions');
        if (product.can_manage) {
            actionsDiv.innerHTML = '<button type="button" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-sm text-white hover:bg-gray-800 dark:hover:bg-gray-500" ' +
                'data-product-id="' + product.id + '" ' +
                'data-product-name="' + escapeAttr(product.name) + '" ' +
                'data-category-id="' + product.category_id + '" ' +
                'data-brand="' + escapeAttr(product.brand) + '" ' +
                'data-description="' + escapeAttr(product.description) + '" ' +
                'data-price="' + product.price + '" ' +
                'data-retail-price="' + (product.retail_price !== null ? product.retail_price : '') + '" ' +
                'data-acquisition-cost="' + (product.acquisition_cost !== null ? product.acquisition_cost : '') + '" ' +
                'data-manufacturing-date="' + escapeAttr(product.manufacturing_date) + '" ' +
                'data-product-type="' + escapeAttr(product.product_type) + '" ' +
                'data-specification="' + escapeAttr(product.specification) + '" ' +
                'data-compatibility="' + escapeAttr(product.compatibility) + '" ' +
                'data-current-stock="' + product.current_stock + '" ' +
                'data-min-stock-level="' + product.min_stock_level + '" ' +
                'data-expiry-date="' + escapeAttr(product.expiry_date) + '" ' +
                'onclick="toggleProductModal(\'productDetailModal\', false); openEditProductModal(this);">Edit Product</button>';
        } else {
            actionsDiv.innerHTML = '';
        }

        toggleProductModal('productDetailModal', true);
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text || ''));
        return div.innerHTML;
    }
    function escapeAttr(text) {
        return (text || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
</script>

<?php include '../includes/footer.php'; ?>
