<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login([APP_ROLE_ADMIN, APP_ROLE_CASHIER]);

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('pages/pos.php');

    try {
        if (isset($_POST['add_to_cart'])) {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $quantity = (int) ($_POST['qty'] ?? 0);

            if ($productId <= 0) {
                throw new RuntimeException('Invalid product selected.');
            }

            if ($quantity <= 0) {
                throw new RuntimeException('Quantity must be at least 1.');
            }

            $productStatement = $pdo->prepare(
                'SELECT p.product_id, p.product_name, p.brand, p.price FROM Products p WHERE p.product_id = :product_id LIMIT 1'
            );
            $productStatement->execute(['product_id' => $productId]);
            $product = $productStatement->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new RuntimeException('The selected product was not found.');
            }

            $availableStock = get_sellable_stock($pdo, $productId);
            if ($availableStock <= 0) {
                throw new RuntimeException('That product is currently out of stock.');
            }

            $existingQuantity = isset($_SESSION['cart'][$productId]['qty']) ? (int) $_SESSION['cart'][$productId]['qty'] : 0;
            $requestedTotalQuantity = $existingQuantity + $quantity;

            if ($requestedTotalQuantity > $availableStock) {
                throw new RuntimeException('Requested quantity exceeds available stock (only ' . $availableStock . ' sellable units remain).');
            }

            $_SESSION['cart'][$productId] = [
                'name' => $product['product_name'],
                'brand' => $product['brand'] ?? '',
                'price' => (float) $product['price'],
                'qty' => $requestedTotalQuantity,
            ];
            
            set_flash('success', 'Product added to cart.');
        } elseif (isset($_POST['update_cart_qty'])) {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $action = $_POST['action'] ?? '';
            
            if (isset($_SESSION['cart'][$productId])) {
                if ($action === 'increase') {
                    $stock = get_sellable_stock($pdo, $productId);
                    if ($_SESSION['cart'][$productId]['qty'] < $stock) {
                        $_SESSION['cart'][$productId]['qty']++;
                    } else {
                        // Max stock reached
                    }
                } elseif ($action === 'decrease') {
                    if ($_SESSION['cart'][$productId]['qty'] > 1) {
                        $_SESSION['cart'][$productId]['qty']--;
                    } else {
                        unset($_SESSION['cart'][$productId]);
                    }
                }
            }
            
            // Redirect back to checkout view if that's where the update came from
            if (isset($_GET['checkout'])) {
                redirect_to('pages/pos.php?checkout=1');
            }
            $redirectUrl = 'pos.php?cart=1' . (isset($_GET['q']) ? '&q=' . urlencode($_GET['q']) : '');
            redirect_to('pages/' . basename($redirectUrl));
            
        } elseif (isset($_POST['remove_cart_item'])) {
            $productId = (int) ($_POST['product_id'] ?? 0);
            unset($_SESSION['cart'][$productId]);
            set_flash('success', 'Product removed from cart.');
        } elseif (isset($_POST['checkout'])) {
            if ($_SESSION['cart'] === []) {
                throw new RuntimeException('Add at least one item to the cart before checkout.');
            }

            $paymentMethod = strtoupper((string) ($_POST['payment_method'] ?? preferred_payment_method()));
            if (!in_array($paymentMethod, ['CASH', 'GCASH', 'E-WALLET', 'CARD'], true)) {
                throw new RuntimeException('Please select a valid payment method.');
            }

            $pdo->beginTransaction();

            $validatedItems = [];
            $totalAmount = 0.0;

            $productStatement = $pdo->prepare(
                'SELECT p.product_name, p.price FROM Products p WHERE p.product_id = :product_id FOR UPDATE'
            );

            foreach ($_SESSION['cart'] as $productId => $item) {
                $quantity = (int) ($item['qty'] ?? 0);
                if ($quantity <= 0) {
                    throw new RuntimeException('Cart contains an invalid quantity.');
                }

                $productStatement->execute(['product_id' => (int) $productId]);
                $productRow = $productStatement->fetch(PDO::FETCH_ASSOC);

                if (!$productRow) {
                    throw new RuntimeException('A cart item is no longer available.');
                }

                $availableStock = get_sellable_stock($pdo, (int) $productId);
                if ($quantity > $availableStock) {
                    throw new RuntimeException('Insufficient stock for ' . $productRow['product_name'] . ' (only ' . $availableStock . ' sellable units remain).');
                }

                $price = (float) $productRow['price'];
                $subtotal = $price * $quantity;
                $totalAmount += $subtotal;

                $validatedItems[] = [
                    'product_id' => (int) $productId,
                    'product_name' => $productRow['product_name'],
                    'quantity' => $quantity,
                    'price' => $price,
                    'subtotal' => $subtotal,
                ];
            }

            $saleStatement = $pdo->prepare(
                "INSERT INTO Sale (user_id, total_amount, payment_method, status)
                 VALUES (:user_id, :total_amount, :payment_method, 'COMPLETED')"
            );
            $saleStatement->execute([
                'user_id' => (int) current_user_id(),
                'total_amount' => $totalAmount,
                'payment_method' => $paymentMethod,
            ]);
            $saleId = (int) $pdo->lastInsertId();

            $saleItemStatement = $pdo->prepare(
                'INSERT INTO Sale_Item (sale_id, product_id, quantity, selling_price, subtotal)
                 VALUES (:sale_id, :product_id, :quantity, :selling_price, :subtotal)'
            );

            foreach ($validatedItems as $validatedItem) {
                $saleItemStatement->execute([
                    'sale_id' => $saleId,
                    'product_id' => $validatedItem['product_id'],
                    'quantity' => $validatedItem['quantity'],
                    'selling_price' => $validatedItem['price'],
                    'subtotal' => $validatedItem['subtotal'],
                ]);
                $saleItemId = (int) $pdo->lastInsertId();

                // FIFO batch deduction
                deduct_stock_fifo($pdo, $validatedItem['product_id'], $validatedItem['quantity'], $saleItemId);
            }

            sync_reorder_alerts_for_catalog($pdo);
            $pdo->commit();

            $_SESSION['cart'] = [];
            // Remove flash message, use receipt modal instead
            redirect_to('pages/pos.php?receipt_id=' . $saleId);
            exit;
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

        set_flash('error', 'The checkout action could not be completed.');
    }
    
    // Redirect preserving query string if any
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect_to('pages/pos.php' . ($qs !== '' ? '?' . $qs : ''));
}

$searchTerm = trim((string) ($_GET['q'] ?? ''));

$productQuery = "
    SELECT
        p.product_id,
        p.product_name,
        COALESCE(p.brand, '') AS brand,
        COALESCE(p.product_type, '') AS product_type,
        COALESCE(p.compatibility, '') AS compatibility,
        COALESCE(p.specification, '') AS specification,
        COALESCE(p.category_id, 0) AS category_id,
        COALESCE(c.category_name, 'Uncategorized') AS category_name,
        p.price,
        COALESCE(i.current_stock, 0) AS current_stock,
        COALESCE(i.min_stock_level, 0) AS min_stock_level
    FROM Products p
    LEFT JOIN Category c ON c.category_id = p.category_id
    LEFT JOIN Inventory i ON i.product_id = p.product_id
    WHERE COALESCE(i.current_stock, 0) > 0
";

$productParameters = [];
if ($searchTerm !== '') {
    $productQuery .= ' AND (p.product_name LIKE :st1 OR p.brand LIKE :st2 OR p.compatibility LIKE :st3)';
    $productParameters['st1'] = '%' . $searchTerm . '%';
    $productParameters['st2'] = '%' . $searchTerm . '%';
    $productParameters['st3'] = '%' . $searchTerm . '%';
}

$productQuery .= ' ORDER BY p.product_name ASC';
$productStatement = $pdo->prepare($productQuery);
foreach ($productParameters as $parameter => $value) {
    $productStatement->bindValue(':' . $parameter, $value, PDO::PARAM_STR);
}
$productStatement->execute();
$products = $productStatement->fetchAll(PDO::FETCH_ASSOC);

// Show recommendations when search is specific and results are limited (≤ 3 in-stock matches)
$showRecommendations = ($searchTerm !== '' && count($products) <= 3 && recommendations_enabled());

$recommendations = [];
if ($showRecommendations) {
    // Search ALL products matching the search term (including out-of-stock) to find sources for recommendations
    $searchAllQuery = "
        SELECT p.product_id
        FROM Products p
        WHERE (p.product_name LIKE :st1 OR p.brand LIKE :st2 OR p.compatibility LIKE :st3)
    ";
    $searchAllStmt = $pdo->prepare($searchAllQuery);
    $searchAllStmt->bindValue(':st1', '%' . $searchTerm . '%', PDO::PARAM_STR);
    $searchAllStmt->bindValue(':st2', '%' . $searchTerm . '%', PDO::PARAM_STR);
    $searchAllStmt->bindValue(':st3', '%' . $searchTerm . '%', PDO::PARAM_STR);
    $searchAllStmt->execute();
    $sourceIds = array_map('intval', $searchAllStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!empty($sourceIds)) {
        $recommendations = fetch_recommendations_for_products($pdo, $sourceIds);
    }
}

// Build a set of product IDs already visible in the main list (so we don't recommend duplicates)
$inStockProductIds = array_flip(array_map('intval', array_column($products, 'product_id')));

$flatRecommendations = [];
foreach ($recommendations as $recList) {
    foreach ($recList as $rec) {
        $altId = (int) $rec['alternative_id'];
        // Skip if this alternative is already in the main in-stock product list
        if (isset($inStockProductIds[$altId])) {
            continue;
        }
        // Keep only the highest similarity_score when the same alternative appears for multiple source products
        if (!isset($flatRecommendations[$altId]) || (float) $rec['similarity_score'] > (float) $flatRecommendations[$altId]['similarity_score']) {
            $flatRecommendations[$altId] = $rec;
        }
    }
}
// Sort by best similarity score so the most relevant alternatives appear first
uasort($flatRecommendations, static fn (array $a, array $b): int => (float) $b['similarity_score'] <=> (float) $a['similarity_score']);

// Check for receipt modal
$receiptSale = null;
$receiptItems = [];
if (isset($_GET['receipt_id'])) {
    $receiptId = (int)$_GET['receipt_id'];
    // Verify ownership — a cashier may only view their own receipts (prevents IDOR)
    $receiptSaleStmt = $pdo->prepare("SELECT * FROM Sale WHERE sale_id = ? AND user_id = ?");
    $receiptSaleStmt->execute([$receiptId, current_user_id()]);
    $receiptSale = $receiptSaleStmt->fetch(PDO::FETCH_ASSOC);
    if ($receiptSale) {
        $receiptItemsStmt = $pdo->prepare("SELECT si.*, p.product_name FROM Sale_Item si JOIN Products p ON si.product_id = p.product_id WHERE si.sale_id = ?");
        $receiptItemsStmt->execute([$receiptId]);
        $receiptItems = $receiptItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$cart = $_SESSION['cart'] ?? [];
$totalDue = 0.0;
foreach ($cart as $cartItem) {
    $totalDue += (float)($cartItem['price'] ?? 0) * (int)($cartItem['qty'] ?? 0);
}

// Next transaction number for checkout header
$nextTransactionStmt = $pdo->query("SELECT COALESCE(MAX(sale_id), 0) + 1 FROM Sale");
$nextTransactionNo = (int) $nextTransactionStmt->fetchColumn();

$page_title = 'POINT OF SALE';
include '../includes/header.php';
?>

<!-- Main POS View -->
<div id="pos-main-view" class="<?php echo isset($_GET['checkout']) ? 'hidden' : 'flex'; ?> h-[calc(100vh-140px)] -m-8 font-sans">
    <!-- Main Left side: POS List -->
    <div class="flex-1 flex flex-col bg-white dark:bg-gray-900 border-r border-black dark:border-black relative">
        <!-- Search -->
        <div class="border-b border-black dark:border-black">
             <form method="GET" class="relative">
                 <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                     <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-black dark:text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                 </div>
                 <input type="text" name="q" value="<?php echo h($searchTerm); ?>" placeholder="Engine Oil 10W-40" class="w-full pl-14 pr-4 py-5 text-lg border-0 focus:ring-0 bg-transparent dark:bg-gray-800 dark:text-white placeholder-gray-400 outline-none">
             </form>
        </div>
        
        <!-- Table -->
        <div class="flex-1 overflow-auto bg-white dark:bg-gray-900 pb-4">
            <table class="w-full text-left border-collapse table-fixed">
                <colgroup>
                    <col style="width:5%">
                    <col style="width:10%">
                    <col style="width:35%">
                    <col style="width:22%">
                    <col style="width:10%">
                    <col style="width:18%">
                </colgroup>
                <thead class="sticky top-0 bg-white dark:bg-gray-800 border-b border-black dark:border-black z-10">
                    <tr>
                        <th class="py-4 px-6 text-sm font-bold uppercase tracking-wider border-b border-black dark:border-black">NO.</th>
                        <th class="py-4 px-6 text-sm font-bold uppercase tracking-wider border-b border-black dark:border-black">PCODE</th>
                        <th class="py-4 px-6 text-sm font-bold uppercase tracking-wider border-b border-black dark:border-black">PRODUCT NAME</th>
                        <th class="py-4 px-6 text-sm font-bold uppercase tracking-wider border-b border-black dark:border-black">CATEGORY</th>
                        <th class="py-4 px-6 text-sm font-bold uppercase tracking-wider border-b border-black dark:border-black">PRICE</th>
                        <th class="py-4 px-6 text-sm font-bold uppercase tracking-wider border-b border-black dark:border-black"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black dark:divide-black">
                    <?php $no = 1; foreach ($products as $product): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                            <td class="py-4 px-6 text-sm"><?php echo $no++; ?></td>
                            <td class="py-4 px-6 text-sm font-medium">P<?php echo str_pad((string)$product['product_id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td class="py-4 px-6 text-sm truncate"><?php echo h($product['product_name']); ?></td>
                            <td class="py-4 px-6 text-sm truncate"><?php echo h($product['category_name']); ?></td>
                            <td class="py-4 px-6 text-sm"><?php echo h((string)round((float)$product['price'])); ?></td>
                            <td class="py-4 px-4 text-sm">
                                <div class="flex gap-2">
                                    <form method="POST" action="<?php echo h(app_url('pages/pos.php')); ?>" class="inline m-0">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="product_id" value="<?php echo (int)$product['product_id']; ?>">
                                        <input type="hidden" name="qty" value="1">
                                        <button type="submit" name="add_to_cart" class="border border-black dark:border-black rounded px-3 py-1 text-xs font-medium hover:bg-gray-100 dark:hover:bg-gray-800 bg-transparent text-black dark:text-white">Add</button>
                                    </form>
                                    <button type="button" onclick='openSpecsModal(<?php echo h(json_encode($product["product_name"])); ?>, <?php echo h(json_encode($product["specification"] ?? "")); ?>, <?php echo h(json_encode($product["compatibility"] ?? "")); ?>, <?php echo (int)$product["product_id"]; ?>)' class="border border-black dark:border-black rounded px-3 py-1 text-xs font-medium hover:bg-gray-100 dark:hover:bg-gray-800 bg-transparent text-black dark:text-white">View</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                        <tr><td colspan="6" class="py-8 text-center text-gray-500">No products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Cart Button -->
        <div class="border-t border-black dark:border-black py-4 px-6 bg-white dark:bg-gray-900 flex items-center justify-between">
            <button type="button" onclick="openCartModal()" class="border border-black dark:border-white px-10 py-2 text-sm font-bold uppercase tracking-wide hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors active:scale-95 transition-transform duration-100 bg-white dark:bg-gray-800 text-black dark:text-white">
                Next<?php if (!empty($cart)): ?> (<?php echo array_sum(array_column($cart, 'qty')); ?>)<?php endif; ?>
            </button>
            <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo count($products); ?> product(s) in stock</span>
        </div>
    </div>
    
    <!-- Right side: Recommendations -->
    <?php if ($showRecommendations && !empty($flatRecommendations)): ?>
    <div id="recResizer" class="w-1 bg-gray-200 dark:bg-gray-700 cursor-col-resize hover:bg-gray-400 dark:hover:bg-gray-500 transition-colors flex-shrink-0 relative z-10" title="Drag to resize"></div>
    <div id="recPanel" class="flex flex-col bg-white dark:bg-gray-900 flex-shrink-0" style="width:450px;">
        <div class="p-6 border-b border-black dark:border-black">
            <h2 class="text-[15px] font-bold uppercase tracking-wide truncate">RECOMMENDATION FOR : <?php echo h($searchTerm); ?></h2>
        </div>
        <div class="py-3 px-6 border-b border-black dark:border-black">
            <span class="font-bold text-sm tracking-wide">Featured Based</span>
        </div>
        
        <div class="flex-1 overflow-auto">
            <table class="w-full text-left border-collapse table-fixed">
                <colgroup>
                    <col style="width:38%">
                    <col style="width:18%">
                    <col style="width:14%">
                    <col style="width:30%">
                </colgroup>
                <thead class="sticky top-0 bg-white dark:bg-gray-800 border-b border-black dark:border-black z-10">
                    <tr>
                        <th class="py-3 px-4 text-xs font-bold uppercase tracking-wider border-b border-black dark:border-black">Product Name</th>
                        <th class="py-3 px-4 text-xs font-bold uppercase tracking-wider border-b border-black dark:border-black">Brand</th>
                        <th class="py-3 px-4 text-xs font-bold uppercase tracking-wider border-b border-black dark:border-black text-right">Price</th>
                        <th class="py-3 px-4 text-xs font-bold uppercase tracking-wider border-b border-black dark:border-black text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($flatRecommendations)): ?>
                        <tr><td colspan="4" class="py-8 text-center text-sm text-gray-500">No recommendations available</td></tr>
                    <?php else: ?>
                        <?php foreach ($flatRecommendations as $rec): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                            <td class="py-3 px-4 text-sm truncate" title="<?php echo h($rec['alternative_name']); ?>"><?php echo h($rec['alternative_name']); ?></td>
                            <td class="py-3 px-4 text-sm truncate" title="<?php echo h($rec['alternative_brand'] ?? ''); ?>"><?php echo h($rec['alternative_brand'] ?: 'N/A'); ?></td>
                            <td class="py-3 px-4 text-sm text-right font-medium">&#8369;<?php echo h(number_format((float)($rec['price'] ?? 0), 0)); ?></td>
                            <td class="py-3 px-4 text-sm text-center">
                                <form method="POST" action="<?php echo h(app_url('pages/pos.php')); ?>" class="inline-flex items-center gap-1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo h((string)$rec['alternative_id']); ?>">
                                    <input type="hidden" name="qty" value="1">
                                    <button type="submit" name="add_to_cart" class="border border-black dark:border-gray-600 rounded px-2 py-1 text-xs font-medium hover:bg-gray-100 dark:hover:bg-gray-700 bg-transparent text-black dark:text-white whitespace-nowrap">Add</button>
                                    <button type="button" onclick="openSpecsModal('<?php echo h(addslashes($rec['alternative_name'])); ?>', '<?php echo h(addslashes($rec['specification'] ?? '')); ?>', '<?php echo h(addslashes($rec['compatibility'] ?? '')); ?>', <?php echo (int)$rec['alternative_id']; ?>)" class="border border-black dark:border-gray-600 rounded px-2 py-1 text-xs font-medium hover:bg-gray-100 dark:hover:bg-gray-700 bg-transparent text-black dark:text-white whitespace-nowrap">View</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    // Draggable recommendation panel resize
    (function() {
        var resizer = document.getElementById('recResizer');
        var panel = document.getElementById('recPanel');
        if (!resizer || !panel) return;

        var startX, startW;

        resizer.addEventListener('mousedown', function(e) {
            startX = e.clientX;
            startW = panel.offsetWidth;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        function onMove(e) {
            var dx = startX - e.clientX;
            var newW = startW + dx;
            if (newW < 280) newW = 280;
            if (newW > 700) newW = 700;
            panel.style.width = newW + 'px';
        }

        function onUp() {
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        }
    })();
</script>

<!-- Specs Modal -->
<div id="specsModal" class="hidden fixed inset-0 z-[70] items-center justify-center bg-black/30 p-4 backdrop-blur-sm">
    <div class="w-[500px] bg-white dark:bg-gray-800 shadow-2xl border border-black dark:border-black flex flex-col">
        <div class="p-8">
            <h3 class="text-sm font-bold uppercase mb-4 tracking-wider">COMPATIBILITY</h3>
            <ul class="list-disc list-inside text-sm mb-8 ml-2" id="specsCompatibility">
                <li>N/A</li>
            </ul>
            <h3 class="text-sm font-bold uppercase mb-4 tracking-wider">SPECIFICATIONS</h3>
            <ul class="list-disc list-inside text-sm ml-2" id="specsDetails">
                <li>N/A</li>
            </ul>
        </div>
        <div class="p-6 border-t border-black dark:border-black flex justify-center gap-6">
            <form id="specsAddForm" method="POST" action="<?php echo h(app_url('pages/pos.php')); ?>" class="inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" id="specsProductId" name="product_id" value="">
                <input type="hidden" name="qty" value="1">
                <button type="submit" name="add_to_cart" class="border border-black dark:border-black rounded-md px-6 py-2 text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors bg-white dark:bg-transparent text-black dark:text-white">Add & Return POS</button>
            </form>
            <button type="button" onclick="closeSpecsModal()" class="border border-black dark:border-black rounded-md px-6 py-2 text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors bg-white dark:bg-transparent text-black dark:text-white">Back</button>
        </div>
    </div>
</div>

<!-- Cart Modal -->
<div id="cartModal" class="<?php echo isset($_GET['cart']) ? 'flex' : 'hidden'; ?> fixed inset-0 z-[60] items-center justify-center bg-black/40 p-4 backdrop-blur-sm">
    <div class="w-[600px] bg-white dark:bg-gray-800 border border-black dark:border-black shadow-2xl flex flex-col">
        <div class="border-b border-black dark:border-black py-4 text-center">
            <h2 class="text-xl font-bold uppercase tracking-widest text-black dark:text-white">CART</h2>
        </div>
        <div class="p-8 flex-1 overflow-auto max-h-[50vh] space-y-8">
            <?php if (empty($cart)): ?>
                <div class="text-center text-gray-500 py-8">Cart is empty</div>
            <?php else: ?>
                <?php foreach ($cart as $productId => $item): ?>
                    <div class="flex justify-between items-center text-black dark:text-white">
                        <div>
                            <div class="text-lg font-medium"><?php echo h($item['name']); ?></div>
                            <div class="text-sm mt-1">Price: &#8369;<?php echo number_format((float)$item['price'], 2); ?></div>
                        </div>
                        <div class="flex items-center gap-6 text-xl">
                            <form method="POST" action="<?php echo h(app_url('pages/pos.php?cart=1')); ?>" class="inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo h((string)$productId); ?>">
                                <input type="hidden" name="action" value="decrease">
                                <button type="submit" name="update_cart_qty" class="w-8 h-8 flex items-center justify-center border-2 border-black dark:border-black rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 bg-transparent text-black dark:text-white pb-1">
                                    -
                                </button>
                            </form>
                            <span class="w-4 text-center text-lg font-medium"><?php echo h((string)$item['qty']); ?></span>
                            <form method="POST" action="<?php echo h(app_url('pages/pos.php?cart=1')); ?>" class="inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo h((string)$productId); ?>">
                                <input type="hidden" name="action" value="increase">
                                <button type="submit" name="update_cart_qty" class="w-8 h-8 flex items-center justify-center border-2 border-black dark:border-black rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 bg-transparent text-black dark:text-white pb-1">
                                    +
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="p-8 flex justify-between items-center text-black dark:text-white border-t border-black dark:border-black">
            <div class="text-lg font-medium">Subtotal: &#8369; <?php echo number_format($totalDue, 2); ?></div>
            <div class="flex gap-4">
                <button type="button" onclick="openCheckoutModal()" <?php echo empty($cart) ? 'disabled' : ''; ?> class="border border-black dark:border-black rounded-sm px-8 py-2 text-sm uppercase font-bold hover:bg-gray-100 dark:hover:bg-gray-700 bg-white dark:bg-transparent <?php echo empty($cart) ? 'opacity-50 cursor-not-allowed' : ''; ?>">Check out</button>
                <button type="button" onclick="closeCartModal()" class="border border-black dark:border-black rounded-sm px-8 py-2 text-sm uppercase font-bold hover:bg-gray-100 dark:hover:bg-gray-700 bg-white dark:bg-transparent">Back</button>
            </div>
        </div>
    </div>
</div>

<!-- Checkout View (In-page) -->
<div id="pos-checkout-view" class="<?php echo isset($_GET['checkout']) ? 'flex' : 'hidden'; ?> flex-col h-[calc(100vh-140px)] -m-8 font-sans bg-white dark:bg-gray-900 text-black dark:text-white border-t border-black dark:border-black overflow-auto">
    <!-- Transaction Header -->
    <div class="text-center pt-6 pb-4">
        <h2 class="text-2xl font-bold tracking-wide">TRANSACTION NO. <?php echo $nextTransactionNo; ?></h2>
        <p class="text-sm mt-1">Date: <?php echo date('F d, Y'); ?></p>
    </div>

    <!-- Items Table -->
    <div class="flex-1 px-8 overflow-auto">
        <table class="w-full text-sm border-collapse border border-black dark:border-white table-fixed">
            <colgroup>
                <col style="width:12%">
                <col style="width:38%">
                <col style="width:15%">
                <col style="width:20%">
                <col style="width:15%">
            </colgroup>
            <thead>
                <tr>
                    <th class="text-left py-3 px-4 font-bold border border-black dark:border-white">PCODE</th>
                    <th class="text-left py-3 px-4 font-bold border border-black dark:border-white">Product Name</th>
                    <th class="text-left py-3 px-4 font-bold border border-black dark:border-white">Unit Price</th>
                    <th class="text-center py-3 px-4 font-bold border border-black dark:border-white">Quantity</th>
                    <th class="text-right py-3 px-4 font-bold border border-black dark:border-white">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cart as $pid => $item):
                    $itemTotal = (float)$item['price'] * (int)$item['qty'];
                ?>
                <tr>
                    <td class="py-3 px-4 border border-black dark:border-white">P<?php echo str_pad((string)$pid, 4, '0', STR_PAD_LEFT); ?></td>
                    <td class="py-3 px-4 border border-black dark:border-white truncate"><?php echo h($item['name']); ?></td>
                    <td class="py-3 px-4 border border-black dark:border-white">&#8369;<?php echo number_format((float)$item['price'], 2); ?></td>
                    <td class="py-3 px-4 border border-black dark:border-white">
                        <div class="flex items-center justify-center gap-2">
                            <form method="POST" action="<?php echo h(app_url('pages/pos.php?checkout=1')); ?>" class="inline m-0">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo (int)$pid; ?>">
                                <input type="hidden" name="action" value="decrease">
                                <button type="submit" name="update_cart_qty" value="1" class="w-7 h-7 border border-black dark:border-white flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-700 text-xs font-bold">&#8722;</button>
                            </form>
                            <span class="w-8 text-center font-medium"><?php echo (int)$item['qty']; ?></span>
                            <form method="POST" action="<?php echo h(app_url('pages/pos.php?checkout=1')); ?>" class="inline m-0">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo (int)$pid; ?>">
                                <input type="hidden" name="action" value="increase">
                                <button type="submit" name="update_cart_qty" value="1" class="w-7 h-7 border border-black dark:border-white flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-700 text-xs font-bold">&#43;</button>
                            </form>
                        </div>
                    </td>
                    <td class="py-3 px-4 border border-black dark:border-white text-right">&#8369;<?php echo number_format($itemTotal, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Bottom Section: Payment Method + Totals + Buttons -->
    <div class="border-t border-black dark:border-black px-8 py-6">
        <div class="flex relative min-h-[120px]">
            <!-- Left: Payment Method (centered in its column) -->
            <div class="flex-1 flex flex-col items-center text-center pr-6">
                <h3 class="text-sm font-bold uppercase mb-4 tracking-wide">SELECT PAYMENT METHOD</h3>
                <div class="flex gap-4 mb-4">
                    <button type="button" id="btnCASH" onclick="setPaymentMethod('CASH')" class="border-2 border-black dark:border-white px-8 py-2 font-bold uppercase hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors bg-white dark:bg-gray-800 tracking-wide text-sm">CASH</button>
                    <button type="button" id="btnEWALLET" onclick="setPaymentMethod('GCASH')" class="border border-black dark:border-white px-8 py-2 font-bold uppercase hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors bg-white dark:bg-gray-800 tracking-wide text-sm">GCASH</button>
                </div>
                <div id="refNumberContainer" class="hidden flex-col items-center mb-2 text-left">
                    <label class="block text-sm mb-2">If paying via GCASH, enter Reference Number</label>
                    <input type="text" id="referenceNumber" class="w-64 border border-black dark:border-white px-3 py-2 bg-transparent focus:outline-none text-sm" placeholder="Enter reference number">
                    <p id="refNumberError" class="text-red-500 text-xs mt-1 hidden">Reference number is required for GCASH payments.</p>
                </div>
            </div>

            <!-- Vertical Divider: absolutely positioned to span full section height -->
            <div class="absolute top-0 bottom-0 w-px bg-black dark:bg-white" style="left:50%"></div>

            <!-- Right: Totals (left-aligned) -->
            <div class="flex-1 text-left text-base font-medium space-y-2 pl-6">
                <div>Total Amount: <?php echo number_format($totalDue, 2); ?></div>
                <div class="flex items-center gap-2">
                    <span>Amount Received:</span>
                    <input type="number" id="amountReceived" class="border border-black dark:border-white px-3 py-1 w-32 bg-transparent focus:outline-none" onkeyup="calculateChange()" oninput="hideAmountError()">
                    <p id="amountReceivedError" class="text-red-500 text-xs mt-1 hidden"></p>
                </div>
                <div>Change: <span id="changeAmount">0.00</span></div>
            </div>
        </div>

        <!-- Bottom Buttons -->
        <div class="flex justify-end gap-4 mt-6">
            <button type="button" onclick="closeCheckoutPage()" class="border border-black dark:border-white px-8 py-2 uppercase hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors font-bold bg-white dark:bg-gray-800 text-sm tracking-wide">BACK</button>
            <form method="POST" action="<?php echo h(app_url('pages/pos.php')); ?>" id="checkoutForm" onsubmit="return validateCheckout()">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="checkout" value="1">
                <input type="hidden" name="payment_method" id="selectedPaymentMethod" value="CASH">
                <input type="hidden" name="reference_number" id="hiddenRefNumber" value="">
                <button type="submit" class="border border-black dark:border-white px-8 py-2 uppercase hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors font-bold bg-white dark:bg-gray-800 text-sm tracking-wide">CONFIRM PAYMENT</button>
            </form>
        </div>
    </div>
</div>
<script>
    var totalDue = <?php echo json_encode($totalDue); ?>;
    
    function calculateChange() {
        var received = parseFloat(document.getElementById('amountReceived').value) || 0;
        var change = received - totalDue;
        document.getElementById('changeAmount').textContent = change > 0 ? change.toFixed(2) : "0.00";
    }

    function printReceipt() {
        var receiptEl = document.querySelector('.fixed.inset-0.z-\\[110\\] .w-\\[500px\\]');
        if (!receiptEl) return;
        var printWin = window.open('', '_blank', 'width=500,height=700');
        printWin.document.write('<html><head><title>Receipt</title><style>');
        printWin.document.write('body{font-family:monospace,sans-serif;margin:0;padding:20px;font-size:12px;color:#000}');
        printWin.document.write('table{width:100%;border-collapse:collapse}th,td{padding:6px 4px;text-align:left}');
        printWin.document.write('th{border-bottom:1px solid #000;font-weight:bold}td{border-bottom:1px dashed #ccc}');
        printWin.document.write('.text-center{text-align:center}.text-right{text-align:right}');
        printWin.document.write('.font-bold,.font-medium{font-weight:bold}.text-sm{font-size:12px}.text-xl{font-size:16px}');
        printWin.document.write('.mb-1,.mb-2{margin-bottom:4px}.mt-3{margin-top:8px}.pb-3{padding-bottom:6px}');
        printWin.document.write('.uppercase{text-transform:uppercase}.tracking-widest{letter-spacing:4px}');
        printWin.document.write('@media print{button{display:none !important}}');
        printWin.document.write('</style></head><body>');
        printWin.document.write(receiptEl.innerHTML);
        printWin.document.write('</body></html>');
        printWin.document.close();
        printWin.focus();
        printWin.print();
    }
    
    function setPaymentMethod(method) {
        document.getElementById('selectedPaymentMethod').value = method;
        document.getElementById('refNumberError').classList.add('hidden');
        if (method === 'CASH') {
            document.getElementById('btnCASH').classList.add('border-2');
            document.getElementById('btnCASH').classList.remove('border');
            document.getElementById('btnEWALLET').classList.remove('border-2');
            document.getElementById('btnEWALLET').classList.add('border');
            document.getElementById('refNumberContainer').classList.add('hidden');
            document.getElementById('refNumberContainer').classList.remove('flex');
        } else {
            document.getElementById('btnEWALLET').classList.add('border-2');
            document.getElementById('btnEWALLET').classList.remove('border');
            document.getElementById('btnCASH').classList.remove('border-2');
            document.getElementById('btnCASH').classList.add('border');
            document.getElementById('refNumberContainer').classList.remove('hidden');
            document.getElementById('refNumberContainer').classList.add('flex');
            document.getElementById('referenceNumber').focus();
        }
    }

    function hideAmountError() {
        var errEl = document.getElementById('amountReceivedError');
        if (errEl) errEl.classList.add('hidden');
    }

    function validateCheckout() {
        var method = document.getElementById('selectedPaymentMethod').value;

        // Validate sufficient payment for CASH
        if (method === 'CASH') {
            var received = parseFloat(document.getElementById('amountReceived').value) || 0;
            if (received < totalDue) {
                var errEl = document.getElementById('amountReceivedError');
                if (errEl) {
                    errEl.textContent = 'Insufficient amount. Please enter at least \u20B1' + totalDue.toFixed(2);
                    errEl.classList.remove('hidden');
                }
                document.getElementById('amountReceived').focus();
                return false;
            }
        }

        if (method === 'GCASH') {
            var refNum = document.getElementById('referenceNumber').value.trim();
            if (!refNum) {
                document.getElementById('refNumberError').classList.remove('hidden');
                document.getElementById('referenceNumber').focus();
                return false;
            }
            document.getElementById('hiddenRefNumber').value = refNum;
        }
        return true;
    }

    function openCartModal() {
        document.getElementById('cartModal').classList.remove('hidden');
        document.getElementById('cartModal').classList.add('flex');
    }

    function closeCartModal() {
        document.getElementById('cartModal').classList.add('hidden');
        document.getElementById('cartModal').classList.remove('flex');
        const url = new URL(window.location);
        url.searchParams.delete('cart');
        window.history.pushState({}, '', url);
    }

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
    
    function openSpecsModal(name, specification, compatibility, productId) {
        var compatItems = (compatibility && compatibility.trim()) ? compatibility.split(',').map(c => '<li>' + c.trim() + '</li>').join('') : '<li>N/A</li>';
        var specItems = (specification && specification.trim()) ? specification.split(',').map(s => '<li>' + s.trim() + '</li>').join('') : '<li>N/A</li>';
        document.getElementById('specsCompatibility').innerHTML = compatItems;
        document.getElementById('specsDetails').innerHTML = specItems;
        document.getElementById('specsProductId').value = productId || '';
        document.getElementById('specsModal').classList.remove('hidden');
        document.getElementById('specsModal').classList.add('flex');
    }
    
    function closeSpecsModal() {
        document.getElementById('specsModal').classList.add('hidden');
        document.getElementById('specsModal').classList.remove('flex');
    }
</script>

<!-- Receipt Modal -->
<?php if (isset($receiptSale) && $receiptSale): ?>
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
    <div class="w-[500px] bg-white dark:bg-gray-800 text-black dark:text-gray-100 border border-black dark:border-gray-600 shadow-2xl flex flex-col relative">
        <a href="<?php echo h(app_url('pages/pos.php')); ?>" class="absolute top-4 right-4 text-black dark:text-gray-100 hover:text-gray-600 dark:hover:text-gray-300">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </a>
        
        <div class="p-8 text-center border-b border-black dark:border-gray-600">
            <h2 class="text-xl font-medium tracking-widest uppercase mb-2">FIVE BROTHERS TRADING</h2>
            <p class="text-sm mb-1">0961-195-6139</p>
            <p class="text-sm mb-1">Mabuhay Carmona, Cavite</p>
            <p class="text-sm mb-1">Opening Hours: 9:00 AM - 3:00 PM</p>
            <p class="text-sm mt-3">Transaction No. <?php echo h((string)$receiptSale['sale_id']); ?></p>
            <p class="text-sm">Date: <?php echo date('F d, Y', strtotime($receiptSale['date'] ?? 'now')); ?></p>
        </div>
        
        <div class="p-8 flex-1 overflow-auto max-h-[40vh]">
            <table class="w-full text-sm table-fixed">
                <colgroup>
                    <col style="width:15%">
                    <col style="width:60%">
                    <col style="width:25%">
                </colgroup>
                <thead>
                    <tr class="text-left font-bold border-b border-black dark:border-gray-600">
                        <th class="pb-3">QTY</th>
                        <th class="pb-3">DESCRIPTION</th>
                        <th class="pb-3 text-right">AMOUNT</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black dark:divide-gray-600">
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
        
        <div class="p-8 border-t border-black dark:border-gray-600 bg-white dark:bg-gray-800">
            <div class="text-sm font-bold mb-3">TOTAL: <?php echo number_format((float)$receiptSale['total_amount'], 2); ?></div>
            <div class="text-sm font-bold mb-8 uppercase">PAID BY: <?php echo h($receiptSale['payment_method']); ?></div>
            <div class="text-center text-sm font-medium mb-4">Thank You :)</div>
            <div class="flex justify-center gap-4">
                <button type="button" onclick="printReceipt()" class="border-2 border-black dark:border-black rounded-md px-8 py-2 text-sm font-bold hover:bg-gray-100 dark:hover:bg-gray-700 bg-white dark:bg-transparent text-black dark:text-white uppercase tracking-wide">Print Receipt</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // AJAX Form Submission Interceptor for POS
    (function initPOS() {
        // Add Live Search Functionality (only once)
        if (!window.posSearchAttached) {
            window.posSearchAttached = true;
            document.addEventListener('input', function(e) {
                if (e.target && e.target.name === 'q' && e.target.closest('#pos-main-view')) {
                    clearTimeout(window._posSearchTimer);
                    window._posSearchTimer = setTimeout(function() {
                        if (e.target.form) {
                            e.target.form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                        }
                    }, 300);
                }
            });
        }

        // Only attach global body listener ONCE
        if (!window.posInterceptorAttached) {
            window.posInterceptorAttached = true;

            function applyMorphdom(html, activeEl, wasDarkMode) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');

                morphdom(document.body, doc.body, {
                    onBeforeElUpdated: function(fromEl, toEl) {
                        if (fromEl.id === 'cartModal' || fromEl.id === 'specsModal' ||
                            fromEl.id === 'pos-checkout-view' || fromEl.id === 'pos-main-view' ||
                            fromEl.id === 'notificationsModal' || fromEl.id === 'settingsModal') {
                            toEl.className = fromEl.className;
                        }
                        if (fromEl === activeEl && (fromEl.tagName === 'INPUT' || fromEl.tagName === 'TEXTAREA')) {
                            return false;
                        }
                        return true;
                    }
                });

                // Re-execute inline scripts (dynamic PHP values like totalDue)
                document.body.querySelectorAll('script').forEach(function(oldScript) {
                    if (oldScript.src) return;
                    var newScript = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(function(attr) { newScript.setAttribute(attr.name, attr.value); });
                    newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                });

                if (wasDarkMode) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            }

            document.body.addEventListener('submit', async function(e) {
                var form = e.target;

                // Only CONFIRM PAYMENT submits natively (needs redirect to receipt)
                if (form.id === 'checkoutForm') {
                    return;
                }

                if (!document.querySelector('#pos-main-view')) {
                    return;
                }

                e.preventDefault();

                var method = (form.getAttribute('method') || 'GET').toUpperCase();
                var submitter = e.submitter;
                var formAction = form.getAttribute('action') || window.location.href;
                var formData = new FormData(form);
                if (submitter && submitter.name) {
                    formData.append(submitter.name, submitter.value);
                }

                var wasInCartModal = !!form.closest('#cartModal');
                var wasInSpecsModal = !!form.closest('#specsModal');
                var wasInCheckoutView = !!form.closest('#pos-checkout-view');
                var wasDarkMode = document.documentElement.classList.contains('dark');
                var activeEl = document.activeElement;

                try {
                    if (method === 'GET') {
                        // Search: build URL with params and fetch directly
                        var url = new URL(formAction, window.location.origin);
                        for (var pair of formData.entries()) {
                            if (pair[1]) { url.searchParams.set(pair[0], pair[1]); }
                            else { url.searchParams.delete(pair[0]); }
                        }
                        window.history.pushState({}, '', url.toString());

                        var resp = await fetch(url.toString());
                        if (!resp.ok) { form.submit(); return; }
                        applyMorphdom(await resp.text(), activeEl, wasDarkMode);
                    } else {
                        // POST: send the action with redirect:manual so the flash message
                        // stays in session (redirect:follow would consume it silently)
                        await fetch(formAction, {
                            method: 'POST',
                            body: formData,
                            redirect: 'manual'
                        });

                        // Now GET the current page to get the updated state
                        var pageUrl = window.location.href;
                        var pageResp = await fetch(pageUrl);
                        if (!pageResp.ok) { window.location.reload(); return; }
                        applyMorphdom(await pageResp.text(), activeEl, wasDarkMode);

                        // Post-update: keep modals/views in their current state
                        if (wasInSpecsModal) {
                            closeSpecsModal();
                        }
                        if (wasInCheckoutView) {
                            var cv = document.getElementById('pos-checkout-view');
                            var mv = document.getElementById('pos-main-view');
                            if (cv) { cv.classList.remove('hidden'); cv.classList.add('flex'); }
                            if (mv) { mv.classList.add('hidden'); mv.classList.remove('flex'); }
                        }
                        if (wasInCartModal) {
                            var cm = document.getElementById('cartModal');
                            if (cm) { cm.classList.remove('hidden'); cm.classList.add('flex'); }
                        }
                    }
                } catch (err) {
                    console.error('POS AJAX error:', err);
                    window.location.reload();
                }
            });
        }
    })();
</script>

<?php include '../includes/footer.php'; ?>
