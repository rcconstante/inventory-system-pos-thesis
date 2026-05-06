<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login();
ensure_product_lifecycle_schema($pdo);
purge_expired_product_archives($pdo);

function products_post_redirect_path(): string
{
    if (!empty($_POST['return_to_batches'])) {
        $sourceProductId = max(0, (int) ($_POST['source_product_id'] ?? $_POST['product_id'] ?? 0));
        if ($sourceProductId > 0) {
            return 'pages/product_batches.php?product_id=' . $sourceProductId;
        }
    }

    return 'pages/products.php';
}

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

                $inventoryStatement = $pdo->prepare('SELECT inventory_id, current_stock FROM Inventory WHERE product_id = :product_id LIMIT 1');
                $inventoryStatement->execute(['product_id' => $productId]);
                $inventoryRow = $inventoryStatement->fetch(PDO::FETCH_ASSOC);

                if ($inventoryRow) {
                    $updateInventory = $pdo->prepare(
                        'UPDATE Inventory
                         SET min_stock_level = :min_stock_level, expiry_date = :expiry_date
                         WHERE product_id = :product_id'
                    );
                    $updateInventory->execute([
                        'min_stock_level' => $minStockLevel,
                        'expiry_date' => $expiryDate,
                        'product_id' => $productId,
                    ]);

                    // Adjust stock if quantity changed via edit
                    $existingStock = (int) ($inventoryRow['current_stock'] ?? 0);
                    if ($currentStock !== $existingStock) {
                        $diff = $currentStock - $existingStock;
                        if ($diff > 0) {
                            create_stock_batch($pdo, $productId, $diff, $acquisitionCost, $manufacturingDate, $expiryDate);
                        } else {
                            $decreaseRemaining = abs($diff);
                            $batchesForDecrease = $pdo->prepare(
                                "SELECT batch_id, quantity_remaining FROM Stock_Batch
                                 WHERE product_id = :pid AND is_depleted = 0 AND status = 'ACTIVE'
                                 ORDER BY date_received DESC, batch_id DESC"
                            );
                            $batchesForDecrease->execute(['pid' => $productId]);
                            foreach ($batchesForDecrease->fetchAll(PDO::FETCH_ASSOC) as $batch) {
                                if ($decreaseRemaining <= 0) break;
                                $batchQty = (int) $batch['quantity_remaining'];
                                $take = min($decreaseRemaining, $batchQty);
                                $newQty = $batchQty - $take;
                                $pdo->prepare(
                                    'UPDATE Stock_Batch SET quantity_remaining = :qty, is_depleted = CASE WHEN :qty2 <= 0 THEN 1 ELSE 0 END WHERE batch_id = :bid'
                                )->execute(['qty' => $newQty, 'qty2' => $newQty, 'bid' => $batch['batch_id']]);
                                $decreaseRemaining -= $take;
                            }
                            sync_inventory_from_batches($pdo, $productId);
                        }
                    }
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
        } elseif (isset($_POST['archive_product']) || isset($_POST['delete_product'])) {
            if (!$canDelete) {
                throw new RuntimeException('You do not have permission to delete products.');
            }
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Invalid product selected.');
            }

            $pdo->beginTransaction();

            archive_product($pdo, $productId);

            sync_reorder_alerts_for_catalog($pdo);
            sync_feature_matches_for_catalog($pdo);
            $pdo->commit();

            set_flash('success', 'Product archived successfully. It will be removed from the active catalog after 30 days.');
        } elseif (isset($_POST['add_batch'])) {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $batchNumber = trim((string) ($_POST['batch_number'] ?? ''));
            $batchQty = max(0, (int) ($_POST['batch_qty'] ?? 0));
            $batchCost = ($_POST['batch_cost'] ?? '') !== '' ? (float) $_POST['batch_cost'] : null;
            $batchMfgDate = !empty($_POST['batch_mfg_date']) ? $_POST['batch_mfg_date'] : null;
            $batchExpDate = !empty($_POST['batch_exp_date']) ? $_POST['batch_exp_date'] : null;

            if ($productId <= 0) { throw new RuntimeException('Invalid product.'); }
            if ($batchNumber === '') { throw new RuntimeException('Batch number is required.'); }
            if ($batchQty <= 0) { throw new RuntimeException('Quantity must be at least 1.'); }

            $pdo->beginTransaction();
            create_stock_batch($pdo, $productId, $batchQty, $batchCost, $batchMfgDate, $batchExpDate, $batchNumber);
            sync_reorder_alerts_for_catalog($pdo);
            $pdo->commit();

            set_flash('success', 'Batch added successfully.');
        } elseif (isset($_POST['edit_batch'])) {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $batchNumber = trim((string) ($_POST['batch_number'] ?? ''));
            $batchQty = max(0, (int) ($_POST['batch_qty'] ?? 0));
            $batchCost = ($_POST['batch_cost'] ?? '') !== '' ? (float) $_POST['batch_cost'] : null;
            $batchMfgDate = !empty($_POST['batch_mfg_date']) ? $_POST['batch_mfg_date'] : null;
            $batchExpDate = !empty($_POST['batch_exp_date']) ? $_POST['batch_exp_date'] : null;
            $batchStatus = in_array(($_POST['batch_status'] ?? ''), ['ACTIVE','EXPIRED','DISPOSED'], true) ? $_POST['batch_status'] : 'ACTIVE';

            if ($batchId <= 0) { throw new RuntimeException('Invalid batch.'); }
            if ($batchNumber === '') { throw new RuntimeException('Batch number is required.'); }

            $pdo->beginTransaction();
            update_stock_batch($pdo, $batchId, [
                'batch_number' => $batchNumber,
                'acquisition_cost' => $batchCost,
                'manufacturing_date' => $batchMfgDate,
                'expiration_date' => $batchExpDate,
                'quantity_remaining' => $batchQty,
                'status' => $batchStatus,
            ]);
            sync_reorder_alerts_for_catalog($pdo);
            $pdo->commit();

            set_flash('success', 'Batch updated successfully.');
        } elseif (isset($_POST['delete_batch'])) {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            if ($batchId <= 0) { throw new RuntimeException('Invalid batch.'); }

            $pdo->beginTransaction();
            delete_stock_batch($pdo, $batchId);
            sync_reorder_alerts_for_catalog($pdo);
            $pdo->commit();

            set_flash('success', 'Batch deleted successfully.');
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

        set_flash('error', 'The action could not be completed.');
    }

    redirect_to(products_post_redirect_path());
}

$categoriesStatement = $pdo->query('SELECT * FROM Category ORDER BY category_name ASC');
$categories = $categoriesStatement->fetchAll(PDO::FETCH_ASSOC);

$selectedCategoryId = max(0, (int) ($_GET['category_id'] ?? 0));
$searchTerm = trim((string) ($_GET['search'] ?? ''));

// Count total products for pagination
$countQuery = "
    SELECT COUNT(*)
    FROM Products p
    LEFT JOIN Category c ON p.category_id = c.category_id
    WHERE COALESCE(p.product_status, 'ACTIVE') = 'ACTIVE'
";
$queryParams = [];
if ($selectedCategoryId > 0) {
    $countQuery .= ' AND p.category_id = :category_id';
    $queryParams['category_id'] = $selectedCategoryId;
}
if ($searchTerm !== '') {
    $countQuery .= ' AND (p.product_name LIKE :st1 OR p.brand LIKE :st2 OR p.compatibility LIKE :st3 OR p.specification LIKE :st4 OR c.category_name LIKE :st5)';
    $queryParams['st1'] = '%' . $searchTerm . '%';
    $queryParams['st2'] = '%' . $searchTerm . '%';
    $queryParams['st3'] = '%' . $searchTerm . '%';
    $queryParams['st4'] = '%' . $searchTerm . '%';
    $queryParams['st5'] = '%' . $searchTerm . '%';
}
$countStmt = $pdo->prepare($countQuery);
foreach ($queryParams as $param => $value) {
    if ($param === 'category_id') {
        $countStmt->bindValue(':category_id', $value, PDO::PARAM_INT);
    } else {
        $countStmt->bindValue(':' . $param, $value, PDO::PARAM_STR);
    }
}
$countStmt->execute();
$totalProducts = (int) $countStmt->fetchColumn();

$perPage = 10;
$paginationPage = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
if ($paginationPage > $totalPages) { $paginationPage = $totalPages; }
$offset = ($paginationPage - 1) * $perPage;

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
    WHERE COALESCE(p.product_status, 'ACTIVE') = 'ACTIVE'
";

if ($selectedCategoryId > 0) {
    $productsQuery .= ' AND p.category_id = :category_id';
}
if ($searchTerm !== '') {
    $productsQuery .= ' AND (p.product_name LIKE :st1 OR p.brand LIKE :st2 OR p.compatibility LIKE :st3 OR p.specification LIKE :st4 OR c.category_name LIKE :st5)';
}

$productsQuery .= ' ORDER BY p.product_name ASC LIMIT :limit OFFSET :offset';
$productsStatement = $pdo->prepare($productsQuery);
foreach ($queryParams as $param => $value) {
    if ($param === 'category_id') {
        $productsStatement->bindValue(':category_id', $value, PDO::PARAM_INT);
    } else {
        $productsStatement->bindValue(':' . $param, $value, PDO::PARAM_STR);
    }
}
$productsStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$productsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$productsStatement->execute();
$products = $productsStatement->fetchAll(PDO::FETCH_ASSOC);

$recommendations = recommendations_enabled()
    ? fetch_recommendations_for_products($pdo, array_map(static fn (array $product): int => (int) $product['product_id'], $products))
    : [];

// Compute average sales count to classify products relatively
$allSalesCounts = array_map(static fn (array $p): int => (int) ($p['sales_count'] ?? 0), $products);
$avgSalesCount = count($allSalesCounts) > 0 ? array_sum($allSalesCounts) / count($allSalesCounts) : 0;

// Pre-compute product data JSON in PHP context (NOT inside the script tag) so any
// PDOException from fetch_batches_for_product never corrupts the <script> block.
try {
    $productDataJson = json_encode(array_map(function($p) use ($pdo, $recommendations, $canManage, $avgSalesCount) {
        try {
            $batches = fetch_batches_for_product($pdo, (int)$p['product_id']);
        } catch (Exception $e) {
            $batches = [];
        }
        $recs = $recommendations[(int)$p['product_id']] ?? [];
        $salesCount = (int)($p['sales_count'] ?? 0);
        $statusLabel = ($salesCount > 0 && $salesCount >= $avgSalesCount) ? 'FAST MOVING' : 'SLOW MOVING';
        return [
            'id' => (int)$p['product_id'],
            'name' => $p['product_name'],
            'brand' => $p['brand'] ?? '',
            'category' => $p['category_name'] ?? 'N/A',
            'category_id' => (int)($p['category_id'] ?? 0),
            'description' => $p['description'] ?? '',
            'price' => (float)$p['price'],
            'retail_price' => isset($p['retail_price']) && $p['retail_price'] !== null ? (float)$p['retail_price'] : null,
            'acquisition_cost' => isset($p['acquisition_cost']) && $p['acquisition_cost'] !== null ? (float)$p['acquisition_cost'] : null,
            'manufacturing_date' => $p['manufacturing_date'] ?? '',
            'expiration_date' => $p['expiration_date'] ?? '',
            'product_type' => $p['product_type'] ?? '',
            'specification' => $p['specification'] ?? '',
            'compatibility' => $p['compatibility'] ?? '',
            'current_stock' => (int)$p['current_stock'],
            'min_stock_level' => (int)$p['min_stock_level'],
            'expiry_date' => $p['expiry_date'] ?? '',
            'sales_count' => $salesCount,
            'status_label' => $statusLabel,
            'batches' => array_map(function($b) {
                return [
                    'batch_id' => (int)$b['batch_id'],
                    'batch_number' => $b['batch_number'],
                    'qty_remaining' => (int)$b['quantity_remaining'],
                    'qty_received' => (int)$b['quantity_received'],
                    'acquisition_cost' => $b['acquisition_cost'] !== null ? (float)$b['acquisition_cost'] : null,
                    'manufacturing_date' => $b['manufacturing_date'] ?? '',
                    'expiration_date' => $b['expiration_date'] ?? '',
                    'date_received' => $b['date_received'] ?? '',
                    'is_depleted' => (int)$b['is_depleted'],
                    'status' => $b['status'] ?? 'ACTIVE',
                ];
            }, $batches),
            'recommendations' => array_map(function($r) {
                return ['name' => $r['alternative_name'], 'brand' => $r['alternative_brand'] ?? '', 'price' => (float)$r['price'], 'score' => (float)$r['similarity_score']];
            }, $recs),
            'can_manage' => $canManage,
        ];
    }, $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($productDataJson === false) { $productDataJson = '[]'; }
} catch (Exception $e) {
    error_log('products.php productDataJson error: ' . $e->getMessage());
    $productDataJson = '[]';
}

$page_title = 'INVENTORY';
include '../includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-center gap-4">
    <form id="inventoryFilterForm" method="GET" class="flex flex-1 flex-wrap items-center justify-center gap-4">
        <select name="category_id" onchange="this.form.submit()" class="cursor-pointer rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 dark:text-gray-100 px-3 py-2 text-sm">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?php echo h((string) $category['category_id']); ?>" <?php echo $selectedCategoryId === (int) $category['category_id'] ? 'selected' : ''; ?>>
                    <?php echo h($category['category_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="relative w-full max-w-xl">
            <input type="text" name="search" value="<?php echo h($searchTerm); ?>" placeholder="Search products..." autocomplete="off" class="w-full rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 dark:text-gray-100 pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring focus:ring-blue-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        </div>
    </form>

    <?php if ($canManage): ?>
        <button type="button" onclick="toggleProductModal('addProductModal', true)" class="flex items-center gap-2 rounded-md border border-black dark:border-gray-600 px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">
            <span>+ Add New Product</span>
        </button>
    <?php endif; ?>
</div>

<div id="productsTableContainer" class="relative" data-products='<?php echo $productDataJson; ?>'>
    <div id="productsTableLoading" class="hidden absolute inset-0 bg-white/80 dark:bg-gray-900/80 flex items-center justify-center z-10 backdrop-blur-sm">
        <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-black dark:border-white"></div>
    </div>
    <div class="overflow-x-auto bg-white dark:bg-gray-800 mb-4">
        <table class="w-full border-collapse border border-black dark:border-gray-600 text-sm">
        <thead>
            <tr class="text-sm font-bold uppercase text-gray-700 dark:text-gray-300">
                <th class="border border-black dark:border-gray-600 p-4 w-[100px] text-center">PRODUCT ID</th>
                <th class="border border-black dark:border-gray-600 p-4 text-left">PRODUCTS</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center">CATEGORY</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center">PRICE</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center w-[90px]">STOCK</th>
                <th class="border border-black dark:border-gray-600 p-4 text-center w-[150px]">STATUS</th>
                <?php if ($canManage): ?>
                    <th class="border border-black dark:border-gray-600 p-4 text-center w-[180px]">ACTION</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php if ($products === []): ?>
            <tr>
                <td colspan="<?php echo $canManage ? '7' : '6'; ?>" class="border border-black dark:border-gray-600 p-8 text-center text-gray-500 dark:text-gray-400">No products found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($products as $product): ?>
                <?php
                $productId = (int) $product['product_id'];
                $currentStock = (int) $product['current_stock'];
                $minStockLevel = (int) $product['min_stock_level'];
                $salesCount = (int) ($product['sales_count'] ?? 0);
                $statusLabel = ($salesCount > 0 && $salesCount >= $avgSalesCount) ? 'FAST MOVING' : 'SLOW MOVING';
                ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer" onclick="window.location.href='<?php echo h(app_url('pages/product_batches.php?product_id=' . $productId)); ?>'">
                    <td class="border border-black dark:border-gray-600 p-4 text-center font-medium dark:text-gray-100">
                        P<?php echo str_pad((string)$productId, 3, '0', STR_PAD_LEFT); ?>
                    </td>
                    <td class="border border-black dark:border-gray-600 p-4 text-left">
                        <div class="font-medium text-black dark:text-gray-100"><?php echo h($product['product_name']); ?></div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            <?php echo h($product['brand'] ?: 'No brand'); ?>
                            <?php if ($product['compatibility'] !== '' && $product['compatibility'] !== null): ?>
                                <span class="mx-1">|</span>
                                <?php echo h($product['compatibility']); ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center text-black dark:text-gray-100"><?php echo h($product['category_name'] ?? 'N/A'); ?></td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center text-black dark:text-gray-100"><?php echo h(number_format((float) $product['price'], 2)); ?></td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center <?php echo $currentStock <= $minStockLevel && $minStockLevel > 0 ? 'text-red-600 font-bold' : 'text-black dark:text-gray-100'; ?>"><?php echo h((string) $currentStock); ?></td>
                    <td class="border border-black dark:border-gray-600 p-4 text-center text-black dark:text-gray-100">
                        <span class="text-xs font-semibold uppercase <?php echo $statusLabel === 'FAST MOVING' ? 'text-green-600' : 'text-orange-500'; ?>"><?php echo h($statusLabel); ?></span>
                    </td>
                    <?php if ($canManage): ?>
                        <td class="border border-black dark:border-gray-600 p-4 text-center" onclick="event.stopPropagation()">
                            <div class="flex items-center justify-center gap-2">
                                <button type="button" title="Edit" class="p-1 hover:bg-gray-100 dark:hover:bg-gray-600 rounded"
                                    data-product-id="<?php echo $productId; ?>"
                                    data-product-name="<?php echo h($product['product_name']); ?>"
                                    data-category-id="<?php echo (int)($product['category_id'] ?? 0); ?>"
                                    data-brand="<?php echo h($product['brand'] ?? ''); ?>"
                                    data-description="<?php echo h($product['description'] ?? ''); ?>"
                                    data-price="<?php echo (float)$product['price']; ?>"
                                    data-retail-price="<?php echo $product['retail_price'] !== null ? (float)$product['retail_price'] : ''; ?>"
                                    data-acquisition-cost="<?php echo $product['acquisition_cost'] !== null ? (float)$product['acquisition_cost'] : ''; ?>"
                                    data-manufacturing-date="<?php echo h($product['manufacturing_date'] ?? ''); ?>"
                                    data-product-type="<?php echo h($product['product_type'] ?? ''); ?>"
                                    data-specification="<?php echo h($product['specification'] ?? ''); ?>"
                                    data-compatibility="<?php echo h($product['compatibility'] ?? ''); ?>"
                                    data-current-stock="<?php echo $currentStock; ?>"
                                    data-min-stock-level="<?php echo $minStockLevel; ?>"
                                    data-expiry-date="<?php echo h($product['expiry_date'] ?? ''); ?>"
                                    onclick="openEditProductModal(this)">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                </button>
                                <?php if ($canDelete): ?>
                                <button type="button" title="Archive" class="p-1 hover:bg-gray-100 dark:hover:bg-gray-600 rounded"
                                    data-product-id="<?php echo $productId; ?>"
                                    data-product-name="<?php echo h($product['product_name']); ?>"
                                    onclick="openDeleteProductModal(this)">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div class="flex justify-end gap-3 mb-8">
    <?php
    $paginationParams = [];
    if ($selectedCategoryId > 0) { $paginationParams['category_id'] = $selectedCategoryId; }
    if ($searchTerm !== '') { $paginationParams['search'] = $searchTerm; }
    ?>
    <?php if ((int)$paginationPage > 1): ?>
        <?php $paginationParams['page'] = (int)$paginationPage - 1; ?>
        <a href="?<?php echo h(http_build_query($paginationParams)); ?>" class="rounded-lg border border-black dark:border-gray-600 px-6 py-2 text-black dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm active:scale-95 transition-transform duration-100">Previous</a>
    <?php else: ?>
        <button disabled class="rounded-lg border border-gray-300 dark:border-gray-600 px-6 py-2 text-gray-400 dark:text-gray-500 cursor-not-allowed text-sm">Previous</button>
    <?php endif; ?>

    <span class="flex items-center text-sm text-gray-600 dark:text-gray-400">Page <?php echo $paginationPage; ?> of <?php echo $totalPages; ?></span>

    <?php if ((int)$paginationPage < (int)$totalPages): ?>
        <?php $paginationParams['page'] = (int)$paginationPage + 1; ?>
        <a href="?<?php echo h(http_build_query($paginationParams)); ?>" class="rounded-lg border border-black dark:border-gray-600 px-6 py-2 text-black dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm active:scale-95 transition-transform duration-100">Next</a>
    <?php else: ?>
        <button disabled class="rounded-lg border border-gray-300 dark:border-gray-600 px-6 py-2 text-gray-400 dark:text-gray-500 cursor-not-allowed text-sm">Next</button>
    <?php endif; ?>
</div>
</div>

<?php if ($canManage): ?>
    <!-- Add Product Modal -->
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

    <!-- Edit Product Modal -->
    <div id="editProductModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white dark:bg-gray-800 p-6 shadow-xl dark:text-gray-100">
            <div class="mb-4 flex items-center justify-between border-b dark:border-gray-600 pb-3">
                <h2 class="text-xl font-bold">ADD/EDIT PRODUCT DETAILS</h2>
                <button type="button" onclick="toggleProductModal('editProductModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">Close</button>
            </div>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>" class="space-y-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" id="edit_product_id">
                <?php $formPrefix = 'edit_'; include __DIR__ . '/product_form_fields.php'; ?>
                <div class="flex justify-end gap-2 border-t dark:border-gray-600 pt-4">
                    <button type="button" onclick="toggleProductModal('editProductModal', false)" class="rounded border dark:border-gray-600 px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">Close</button>
                    <button type="submit" name="edit_product" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-white hover:bg-gray-800 dark:hover:bg-gray-500">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($canDelete): ?>
    <!-- Delete Product Modal -->
    <div id="deleteProductModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-lg bg-white dark:bg-gray-800 p-6 shadow-xl dark:text-gray-100">
            <div class="mb-4 flex items-center justify-between border-b dark:border-gray-600 pb-3">
                <h2 class="text-lg font-bold">Archive Product</h2>
                <button type="button" onclick="toggleProductModal('deleteProductModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">Close</button>
            </div>
            <p class="mb-6 text-sm text-gray-700 dark:text-gray-300">Are you sure you want to archive <strong id="deleteProductName"></strong>? Archived products are hidden immediately and removed from the active catalog after 30 days.</p>
            <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" id="deleteProductId">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="toggleProductModal('deleteProductModal', false)" class="rounded border dark:border-gray-600 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">Cancel</button>
                    <button type="submit" name="archive_product" class="rounded bg-amber-600 px-4 py-2 text-sm text-white hover:bg-amber-700">Archive</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Product Detail Modal -->
<div id="productDetailModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-xl bg-white dark:bg-gray-800 border border-black dark:border-black shadow-lg flex flex-col" style="max-height:90vh;">
        <div class="flex items-center justify-between border-b border-black dark:border-black p-5">
            <h3 class="text-lg font-bold dark:text-white">PRODUCT DETAILS</h3>
            <button type="button" onclick="toggleProductModal('productDetailModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div id="detailProductContent" class="p-5 overflow-y-auto dark:text-gray-100"></div>
        <div id="detailProductActions" class="flex justify-end gap-2 border-t border-black dark:border-black p-4"></div>
    </div>
</div>

<!-- Batch Management Modal -->
<div id="batchManagementModal" class="hidden fixed inset-0 z-[60] items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-2xl bg-white dark:bg-gray-800 border border-black dark:border-black shadow-lg flex flex-col dark:text-gray-100" style="max-height:90vh;">
        <div class="flex items-center justify-between border-b border-black dark:border-black p-5">
            <h3 class="text-lg font-bold">BATCH MANAGEMENT</h3>
            <button type="button" onclick="toggleProductModal('batchManagementModal', false)" class="text-gray-500 hover:text-black dark:hover:text-white">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div id="batchModalContent" class="p-5 overflow-y-auto"></div>
        <div class="flex justify-end gap-2 border-t border-black dark:border-black p-4">
            <button type="button" onclick="toggleProductModal('batchManagementModal', false)" class="rounded border border-black dark:border-gray-500 px-6 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700">Close</button>
        </div>
    </div>
</div>

<!-- Add Batch Modal -->
<div id="addBatchModal" class="hidden fixed inset-0 z-[70] items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-2xl bg-white dark:bg-gray-800 border border-black dark:border-black shadow-lg p-6 dark:text-gray-100">
        <h3 class="text-lg font-bold mb-4">ADD NEW BATCH</h3>
        <div class="text-sm mb-4 space-y-1">
            <p><span class="font-bold">Product ID:</span> <span id="addBatch_productId"></span></p>
            <p><span class="font-bold">Product Name:</span> <span id="addBatch_productName"></span></p>
        </div>
        <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="product_id" id="addBatch_product_id">
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium mb-1">Batch Number</label>
                    <input type="text" name="batch_number" id="addBatch_number" required class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Quantity</label>
                    <input type="number" name="batch_qty" min="1" required class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Cost Price</label>
                    <input type="number" step="0.01" min="0" name="batch_cost" class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Manufacturing Date</label>
                    <input type="date" name="batch_mfg_date" class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Expiration Date</label>
                    <input type="date" name="batch_exp_date" class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button type="button" onclick="toggleProductModal('addBatchModal', false)" class="rounded border dark:border-gray-500 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Close</button>
                <button type="submit" name="add_batch" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-sm text-white hover:bg-gray-800 dark:hover:bg-gray-500">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Batch Modal -->
<div id="editBatchModal" class="hidden fixed inset-0 z-[70] items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-2xl bg-white dark:bg-gray-800 border border-black dark:border-black shadow-lg p-6 dark:text-gray-100">
        <h3 class="text-lg font-bold mb-4">EDIT BATCH</h3>
        <div class="text-sm mb-4 space-y-1">
            <p><span class="font-bold">Product ID:</span> <span id="editBatch_productId"></span></p>
            <p><span class="font-bold">Product Name:</span> <span id="editBatch_productName"></span></p>
        </div>
        <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="batch_id" id="editBatch_id">
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium mb-1">Batch Number</label>
                    <input type="text" name="batch_number" id="editBatch_number" required class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Cost Price</label>
                    <input type="number" step="0.01" min="0" name="batch_cost" id="editBatch_cost" class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Manufacturing Date</label>
                    <input type="date" name="batch_mfg_date" id="editBatch_mfg" class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Quantity</label>
                    <input type="number" name="batch_qty" id="editBatch_qty" min="0" required class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Expiration Date</label>
                    <input type="date" name="batch_exp_date" id="editBatch_exp" class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Status</label>
                    <select name="batch_status" id="editBatch_status" class="w-full rounded border px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <option value="ACTIVE">ACTIVE</option>
                        <option value="EXPIRED">EXPIRED</option>
                        <option value="DISPOSED">DISPOSED</option>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button type="button" onclick="toggleProductModal('editBatchModal', false)" class="rounded border dark:border-gray-500 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Close</button>
                <button type="submit" name="edit_batch" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-sm text-white hover:bg-gray-800 dark:hover:bg-gray-500">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Batch Confirm Modal -->
<div id="deleteBatchModal" class="hidden fixed inset-0 z-[70] items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-sm bg-white dark:bg-gray-800 border border-black dark:border-black p-6 shadow-xl dark:text-gray-100">
        <h2 class="text-lg font-bold mb-4">Delete Batch</h2>
        <p class="mb-6 text-sm">Are you sure you want to delete batch <strong id="deleteBatchName"></strong>?</p>
        <form method="POST" action="<?php echo h(app_url('pages/products.php')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="batch_id" id="deleteBatch_id">
            <div class="flex justify-end gap-2">
                <button type="button" onclick="toggleProductModal('deleteBatchModal', false)" class="rounded border dark:border-gray-600 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" name="delete_batch" class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ─── Utility Functions (defined first, before productData) ───
    function toggleProductModal(modalId, shouldOpen) {
        var modal = document.getElementById(modalId);
        if (!modal) return;
        if (shouldOpen) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        } else {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text || ''));
        return div.innerHTML;
    }

    function escapeAttr(text) {
        return (text || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
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

    // ─── Product Data (read from container data attribute) ───
    var productData = (function() {
        var el = document.getElementById('productsTableContainer');
        if (!el) return [];
        var raw = el.getAttribute('data-products');
        if (!raw) return [];
        try { return JSON.parse(raw); } catch(e) { return []; }
    })();

    // ─── Product Detail Modal ───
    function openProductDetailModal(productId) {
        var product = productData.find(function(p) { return p.id === productId; });
        if (!product) return;

        var html = '<div class="space-y-3 text-sm">';
        html += '<div><span class="font-bold">Product ID:</span> P' + String(product.id).padStart(3, '0') + '</div>';
        html += '<div><span class="font-bold">Product Name:</span> ' + escapeHtml(product.name) + '</div>';
        html += '<div><span class="font-bold">Category:</span> ' + escapeHtml(product.category) + '</div>';
        html += '<div><span class="font-bold">Brand:</span> ' + escapeHtml(product.brand || 'N/A') + '</div>';
        html += '<div><span class="font-bold">Selling Price:</span> ₱' + product.price.toFixed(2) + '</div>';
        html += '<div><span class="font-bold">Critical Stock:</span> ' + product.min_stock_level + ' pcs</div>';
        html += '<div class="mt-3"><span class="font-bold">Total Stock:</span> ' + product.current_stock + ' pcs</div>';
        html += '<div><span class="font-bold">Status:</span> <span class="' + (product.status_label === 'FAST MOVING' ? 'text-green-600' : 'text-orange-500') + ' font-bold">' + product.status_label + '</span></div>';

        if (product.specification) {
            html += '<div class="mt-3"><span class="font-bold">Specifications:</span> ' + escapeHtml(product.specification) + '</div>';
        }
        if (product.compatibility) {
            html += '<div><span class="font-bold">Compatibility:</span><br>';
            var parts = product.compatibility.split(',');
            parts.forEach(function(part) {
                html += '&nbsp;&nbsp;- ' + escapeHtml(part.trim()) + '<br>';
            });
            html += '</div>';
        }

        html += '</div>';

        document.getElementById('detailProductContent').innerHTML = html;

        // Actions
        var actionsHtml = '<button type="button" onclick="openBatchModalFromDetail(' + product.id + ')" class="rounded border border-black dark:border-gray-500 px-6 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700">View Batches</button>';
        actionsHtml += '<button type="button" onclick="toggleProductModal(\'productDetailModal\', false)" class="rounded border border-black dark:border-gray-500 px-6 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700">Close</button>';
        document.getElementById('detailProductActions').innerHTML = actionsHtml;

        toggleProductModal('productDetailModal', true);
    }

    // ─── Batch Management Modal ───
    function openBatchModal(productId) {
        var product = productData.find(function(p) { return p.id === productId; });
        if (!product) return;

        var html = '<div class="space-y-3 text-sm mb-4">';
        html += '<div><span class="font-bold">Product ID:</span> P' + String(product.id).padStart(3, '0') + '</div>';
        html += '<div><span class="font-bold">Product Name:</span> ' + escapeHtml(product.name) + '</div>';
        html += '<div><span class="font-bold">Critical Stock:</span> ' + product.min_stock_level + ' pcs</div>';
        html += '<div><span class="font-bold">Total Stock:</span> ' + product.current_stock + ' pcs</div>';
        html += '</div>';

        <?php if ($canManage): ?>
        html += '<div class="mb-4"><button type="button" onclick="openAddBatchModal(' + product.id + ')" class="flex items-center gap-2 rounded-md border border-black dark:border-gray-600 px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/></svg> Add New Batch</button></div>';
        <?php endif; ?>

        if (product.batches.length === 0) {
            html += '<div class="text-gray-500 text-center py-4">No batches recorded.</div>';
        } else {
            html += '<div class="overflow-x-auto"><table class="w-full text-xs border-collapse border border-gray-300 dark:border-gray-600">';
            html += '<thead><tr class="bg-gray-100 dark:bg-gray-700 text-left">';
            html += '<th class="border border-gray-300 dark:border-gray-600 p-2 font-bold">Batch No</th>';
            html += '<th class="border border-gray-300 dark:border-gray-600 p-2 font-bold text-center">Qty</th>';
            html += '<th class="border border-gray-300 dark:border-gray-600 p-2 font-bold text-center">Cost Price</th>';
            html += '<th class="border border-gray-300 dark:border-gray-600 p-2 font-bold text-center">MFG Date</th>';
            html += '<th class="border border-gray-300 dark:border-gray-600 p-2 font-bold text-center">EXP Date</th>';
            html += '<th class="border border-gray-300 dark:border-gray-600 p-2 font-bold text-center">Status</th>';
            <?php if ($canManage): ?>
            html += '<th class="border border-gray-300 dark:border-gray-600 p-2 font-bold text-center">Actions</th>';
            <?php endif; ?>
            html += '</tr></thead><tbody>';
            product.batches.forEach(function(b) {
                var statusClass = b.status === 'ACTIVE' ? 'text-green-600' : (b.status === 'EXPIRED' ? 'text-red-500' : 'text-gray-500');
                html += '<tr>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 font-medium">' + escapeHtml(b.batch_number) + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">' + b.qty_remaining + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">' + (b.acquisition_cost !== null ? b.acquisition_cost.toFixed(2) : 'N/A') + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">' + (b.manufacturing_date || 'N/A') + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">' + (b.expiration_date || 'N/A') + '</td>';
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center ' + statusClass + ' font-bold">' + b.status + '</td>';
                <?php if ($canManage): ?>
                html += '<td class="border border-gray-300 dark:border-gray-600 p-2 text-center">';
                html += '<div class="flex items-center justify-center gap-1">';
                html += '<button type="button" title="Edit" class="p-1 hover:bg-gray-100 dark:hover:bg-gray-600 rounded" onclick="openEditBatchModal(this)"'
                    + ' data-batch-id="' + b.batch_id + '"'
                    + ' data-product-id="' + product.id + '"'
                    + ' data-product-name="' + escapeAttr(product.name) + '"'
                    + ' data-batch-number="' + escapeAttr(b.batch_number) + '"'
                    + ' data-cost="' + (b.acquisition_cost !== null ? b.acquisition_cost : '') + '"'
                    + ' data-mfg-date="' + (b.manufacturing_date || '') + '"'
                    + ' data-qty="' + b.qty_remaining + '"'
                    + ' data-exp-date="' + (b.expiration_date || '') + '"'
                    + ' data-status="' + (b.status || 'ACTIVE') + '"'
                    + '>'; 
                html += '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>';
                html += '</button>';
                html += '<button type="button" title="Delete" class="p-1 hover:bg-gray-100 dark:hover:bg-gray-600 rounded" onclick="openDeleteBatchModal(' + b.batch_id + ', \'' + escapeAttr(b.batch_number) + '\')">';
                html += '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>';
                html += '</button>';
                html += '</div></td>';
                <?php endif; ?>
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }

        document.getElementById('batchModalContent').innerHTML = html;
        toggleProductModal('batchManagementModal', true);
    }

    function openBatchModalFromDetail(productId) {
        toggleProductModal('productDetailModal', false);
        openBatchModal(productId);
    }

    function openAddBatchModal(productId) {
        document.getElementById('addBatch_product_id').value = productId;
        var product = productData.find(function(p) { return p.id === productId; });
        document.getElementById('addBatch_productId').textContent = 'P' + String(productId).padStart(3, '0');
        document.getElementById('addBatch_productName').textContent = product ? product.name : '';
        var nextNum = product ? product.batches.length + 1 : 1;
        document.getElementById('addBatch_number').value = 'B' + String(nextNum).padStart(3, '0');
        toggleProductModal('addBatchModal', true);
    }

    function openEditBatchModal(btn) {
        document.getElementById('editBatch_id').value = btn.dataset.batchId;
        document.getElementById('editBatch_productId').textContent = 'P' + String(btn.dataset.productId).padStart(3, '0');
        document.getElementById('editBatch_productName').textContent = btn.dataset.productName;
        document.getElementById('editBatch_number').value = btn.dataset.batchNumber;
        document.getElementById('editBatch_cost').value = btn.dataset.cost || '';
        document.getElementById('editBatch_mfg').value = btn.dataset.mfgDate || '';
        document.getElementById('editBatch_qty').value = btn.dataset.qty;
        document.getElementById('editBatch_exp').value = btn.dataset.expDate || '';
        document.getElementById('editBatch_status').value = btn.dataset.status || 'ACTIVE';
        toggleProductModal('editBatchModal', true);
    }

    function openDeleteBatchModal(batchId, batchNumber) {
        document.getElementById('deleteBatch_id').value = batchId;
        document.getElementById('deleteBatchName').textContent = batchNumber;
        toggleProductModal('deleteBatchModal', true);
    }

    // Smooth inventory filtering and pagination without full page reload
    (function() {
        var container = document.getElementById('productsTableContainer');
        var filterForm = document.getElementById('inventoryFilterForm');
        var searchTimer = null;
        if (!container || !filterForm) return;

        function setLoadingState(isLoading) {
            var loading = document.getElementById('productsTableLoading');
            if (!loading) return;
            loading.classList.toggle('hidden', !isLoading);
        }

        function buildInventoryUrl(form) {
            var url = new URL(form.getAttribute('action') || window.location.href, window.location.origin);
            url.search = '';

            var formData = new FormData(form);
            formData.forEach(function(value, key) {
                var normalizedValue = String(value).trim();
                if (normalizedValue === '' || (key === 'category_id' && normalizedValue === '0')) {
                    url.searchParams.delete(key);
                    return;
                }
                url.searchParams.set(key, normalizedValue);
            });

            url.searchParams.delete('page');
            return url.toString();
        }

        function syncProductsTable(newContainer) {
            container.setAttribute('data-products', newContainer.getAttribute('data-products') || '[]');
            container.innerHTML = newContainer.innerHTML;

            var raw = container.getAttribute('data-products');
            try {
                productData = raw ? JSON.parse(raw) : [];
            } catch (error) {
                productData = [];
            }
        }

        function fetchInventory(url, preserveScroll) {
            setLoadingState(true);

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.text();
                })
                .then(function(html) {
                    var doc = (new DOMParser()).parseFromString(html, 'text/html');
                    var newContainer = doc.getElementById('productsTableContainer');
                    if (!newContainer) {
                        window.location.href = url;
                        return;
                    }

                    syncProductsTable(newContainer);
                    history.pushState({}, '', url);

                    if (!preserveScroll) {
                        window.scrollTo({ top: container.offsetTop - 20, behavior: 'smooth' });
                    }
                })
                .catch(function() {
                    window.location.href = url;
                })
                .finally(function() {
                    setLoadingState(false);
                });
        }

        if (!window.inventoryLiveSearchAttached) {
            window.inventoryLiveSearchAttached = true;

            document.addEventListener('input', function(e) {
                if (!e.target || e.target.name !== 'search' || e.target.closest('#inventoryFilterForm') !== filterForm) {
                    return;
                }

                clearTimeout(searchTimer);
                searchTimer = setTimeout(function() {
                    fetchInventory(buildInventoryUrl(filterForm), true);
                }, 220);
            });

            document.addEventListener('submit', function(e) {
                if (e.target !== filterForm) {
                    return;
                }

                e.preventDefault();
                clearTimeout(searchTimer);
                fetchInventory(buildInventoryUrl(filterForm), true);
            });
        }

        document.addEventListener('click', function(e) {
            var link = e.target.closest('a[href^="?"]');
            if (!link || !container.contains(link)) return;
            if (!link.textContent.match(/Previous|Next/)) return;
            e.preventDefault();

            fetchInventory(link.href, false);
        });

        window.addEventListener('popstate', function() {
            window.location.reload();
        });
    })();
</script>

<?php include '../includes/footer.php'; ?>
