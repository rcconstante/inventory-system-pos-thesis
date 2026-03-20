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
                        category_id,
                        product_type,
                        specification,
                        compatibility
                     ) VALUES (
                        :product_name,
                        :brand,
                        :description,
                        :price,
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

                set_flash('success', 'Product created successfully.');
            } else {
                $updateProduct = $pdo->prepare(
                    'UPDATE Products
                     SET product_name = :product_name,
                         brand = :brand,
                         description = :description,
                         price = :price,
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
        SELECT product_id, SUM(quantity) as sales_count 
        FROM Sale_Item 
        GROUP BY product_id
    ) si ON p.product_id = si.product_id
";

if ($selectedCategoryId > 0) {
    $productsQuery .= ' WHERE p.category_id = :category_id';
}

$productsQuery .= ' ORDER BY p.product_name ASC';
$productsStatement = $pdo->prepare($productsQuery);
if ($selectedCategoryId > 0) {
    $productsStatement->bindValue(':category_id', $selectedCategoryId, PDO::PARAM_INT);
}
$productsStatement->execute();
$products = $productsStatement->fetchAll(PDO::FETCH_ASSOC);

$recommendations = recommendations_enabled()
    ? fetch_recommendations_for_products($pdo, array_map(static fn (array $product): int => (int) $product['product_id'], $products))
    : [];

$page_title = 'PRODUCTS';
include '../includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <form method="GET" class="flex items-center gap-4">
        <span class="text-sm font-medium">Stock Inventory</span>
        <select name="category_id" onchange="this.form.submit()" class="cursor-pointer rounded border border-black bg-white px-3 py-2 text-sm">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?php echo h((string) $category['category_id']); ?>" <?php echo $selectedCategoryId === (int) $category['category_id'] ? 'selected' : ''; ?>>
                    <?php echo h($category['category_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($canManage): ?>
        <button type="button" onclick="toggleProductModal('addProductModal', true)" class="flex items-center gap-2 rounded-md border border-black px-4 py-2 text-sm font-medium hover:bg-gray-50">
            <span>+ Add New Product</span>
        </button>
    <?php endif; ?>
</div>

<div class="overflow-x-auto bg-white mb-8">
    <table class="w-full border-collapse border border-black text-sm">
        <thead>
            <tr class="text-sm font-bold uppercase text-gray-700">
                <th class="border border-black p-4 w-[100px] text-center">PRODUCT ID</th>
                <th class="border border-black p-4 text-left">PRODUCTS</th>
                <th class="border border-black p-4 text-center">CATEGORY</th>
                <th class="border border-black p-4 text-center">PRICE</th>
                <th class="border border-black p-4 text-center w-[130px]">EXP DATE</th>
                <th class="border border-black p-4 text-center w-[90px]">STOCK</th>
                <th class="border border-black p-4 text-center w-[110px]">SALES COUNT</th>
                <th class="border border-black p-4 text-center w-[150px]">STATUS</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($products === []): ?>
            <tr>
                <td colspan="8" class="border border-black p-8 text-center text-gray-500">No products found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($products as $product): ?>
                <?php
                $productId = (int) $product['product_id'];
                $currentStock = (int) $product['current_stock'];
                $minStockLevel = (int) $product['min_stock_level'];
                $salesCount = (int) ($product['sales_count'] ?? 0);
                
                if ($salesCount >= 50) {
                    $statusLabel = 'FAST MOVING';
                } else {
                    $statusLabel = 'SLOW MOVING';
                }

                $productRecommendations = $recommendations[$productId] ?? [];
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="border border-black p-4 text-center font-medium">
                        P<?php echo str_pad((string)$productId, 3, '0', STR_PAD_LEFT); ?>
                    </td>
                    <td class="border border-black p-4 text-left">
                        <div class="font-medium text-black"><?php echo h($product['product_name']); ?></div>
                        <div class="mt-1 text-xs text-gray-500">
                            <?php echo h($product['product_type'] !== '' ? $product['product_type'] : 'General product'); ?>
                            <?php if ($product['compatibility'] !== ''): ?>
                                <span class="mx-1">|</span>
                                <?php echo h($product['compatibility']); ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="border border-black p-4 text-center text-black"><?php echo h($product['category_name'] ?? 'N/A'); ?></td>
                    <td class="border border-black p-4 text-center text-black"><?php echo h(money_format_php((float) $product['price'])); ?></td>
                    <td class="border border-black p-4 text-center text-black"><?php echo h($product['expiry_date'] ? date('F d, Y', strtotime($product['expiry_date'])) : 'N/A'); ?></td>
                    <td class="border border-black p-4 text-center text-black"><?php echo h((string) $currentStock); ?></td>
                    <td class="border border-black p-4 text-center text-black"><?php echo h((string) $salesCount); ?></td>
                    <td class="border border-black p-4 text-center text-black">
                        <div class="text-xs font-semibold uppercase flex flex-col items-center justify-center gap-2">
                            <span><?php echo h($statusLabel); ?></span>
                            
                            <?php if ($canManage): ?>
                                <div class="flex gap-1 justify-center">
                                    <button
                                        type="button"
                                        title="Edit product"
                                        class="rounded bg-blue-50 p-1.5 text-blue-700 hover:bg-blue-100"
                                        data-product-id="<?php echo h((string) $productId); ?>"
                                        data-product-name="<?php echo h($product['product_name']); ?>"
                                        data-category-id="<?php echo h((string) $product['category_id']); ?>"
                                        data-brand="<?php echo h($product['brand']); ?>"
                                        data-description="<?php echo h($product['description']); ?>"
                                        data-price="<?php echo h((string) $product['price']); ?>"
                                        data-product-type="<?php echo h($product['product_type']); ?>"
                                        data-specification="<?php echo h($product['specification']); ?>"
                                        data-compatibility="<?php echo h($product['compatibility']); ?>"
                                        data-current-stock="<?php echo h((string) $currentStock); ?>"
                                        data-min-stock-level="<?php echo h((string) $minStockLevel); ?>"
                                        data-expiry-date="<?php echo h($product['expiry_date'] ?? ''); ?>"
                                        onclick="openEditProductModal(this)"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                    </button>
                                    <?php if ($canDelete): ?>
                                    <button
                                        type="button"
                                        title="Delete product"
                                        class="rounded bg-red-50 p-1.5 text-red-700 hover:bg-red-100"
                                        data-product-id="<?php echo h((string) $productId); ?>"
                                        data-product-name="<?php echo h($product['product_name']); ?>"
                                        onclick="openDeleteProductModal(this)"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($canManage): ?>
    <div id="addProductModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
            <div class="mb-4 flex items-center justify-between border-b pb-3">
                <h2 class="text-xl font-bold">Add New Product</h2>
                <button type="button" onclick="toggleProductModal('addProductModal', false)" class="text-gray-500 hover:text-black">Close</button>
            </div>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>" class="space-y-4">
                <?php echo csrf_field(); ?>
                <?php include __DIR__ . '/product_form_fields.php'; ?>
                <div class="flex justify-end gap-2 border-t pt-4">
                    <button type="button" onclick="toggleProductModal('addProductModal', false)" class="rounded border px-4 py-2 hover:bg-gray-50">Cancel</button>
                    <button type="submit" name="add_product" class="rounded bg-black px-4 py-2 text-white hover:bg-gray-800">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editProductModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
            <div class="mb-4 flex items-center justify-between border-b pb-3">
                <h2 class="text-xl font-bold">Edit Product</h2>
                <button type="button" onclick="toggleProductModal('editProductModal', false)" class="text-gray-500 hover:text-black">Close</button>
            </div>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>" class="space-y-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" id="edit_product_id">
                <?php $formPrefix = 'edit_'; include __DIR__ . '/product_form_fields.php'; ?>
                <div class="flex justify-end gap-2 border-t pt-4">
                    <button type="button" onclick="toggleProductModal('editProductModal', false)" class="rounded border px-4 py-2 hover:bg-gray-50">Cancel</button>
                    <button type="submit" name="edit_product" class="rounded bg-black px-4 py-2 text-white hover:bg-gray-800">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php if ($canDelete): ?>
    <div id="deleteProductModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <div class="mb-4 flex items-center justify-between border-b pb-3">
                <h2 class="text-lg font-bold">Delete Product</h2>
                <button type="button" onclick="toggleProductModal('deleteProductModal', false)" class="text-gray-500 hover:text-black">Close</button>
            </div>
            <p class="mb-6 text-sm text-gray-700">Are you sure you want to delete <strong id="deleteProductName"></strong>? This action cannot be undone.</p>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" id="deleteProductId">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="toggleProductModal('deleteProductModal', false)" class="rounded border px-4 py-2 text-sm hover:bg-gray-50">Cancel</button>
                    <button type="submit" name="delete_product" class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">Delete</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

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
</script>

<?php include '../includes/footer.php'; ?>
