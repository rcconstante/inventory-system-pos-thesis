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
                'SELECT
                    p.product_id,
                    p.product_name,
                    p.brand,
                    p.price,
                    COALESCE(i.current_stock, 0) AS current_stock
                 FROM Products p
                 LEFT JOIN Inventory i ON i.product_id = p.product_id
                 WHERE p.product_id = :product_id
                 LIMIT 1'
            );
            $productStatement->execute(['product_id' => $productId]);
            $product = $productStatement->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new RuntimeException('The selected product was not found.');
            }

            $availableStock = (int) $product['current_stock'];
            if ($availableStock <= 0) {
                throw new RuntimeException('That product is currently out of stock.');
            }

            $existingQuantity = isset($_SESSION['cart'][$productId]['qty']) ? (int) $_SESSION['cart'][$productId]['qty'] : 0;
            $requestedTotalQuantity = $existingQuantity + $quantity;

            if ($requestedTotalQuantity > $availableStock) {
                throw new RuntimeException('Requested quantity exceeds available stock.');
            }

            $_SESSION['cart'][$productId] = [
                'name' => $product['product_name'],
                'brand' => $product['brand'] ?? '',
                'price' => (float) $product['price'],
                'qty' => $requestedTotalQuantity,
            ];

            set_flash('success', 'Product added to cart.');
        } elseif (isset($_POST['remove_cart_item'])) {
            $productId = (int) ($_POST['product_id'] ?? 0);
            unset($_SESSION['cart'][$productId]);
            set_flash('success', 'Item removed from cart.');
        } elseif (isset($_POST['checkout'])) {
            if ($_SESSION['cart'] === []) {
                throw new RuntimeException('Add at least one item to the cart before checkout.');
            }

            $paymentMethod = strtoupper((string) ($_POST['payment_method'] ?? preferred_payment_method()));
            if (!in_array($paymentMethod, ['CASH', 'GCASH', 'CARD'], true)) {
                throw new RuntimeException('Please select a valid payment method.');
            }

            $pdo->beginTransaction();

            $validatedItems = [];
            $totalAmount = 0.0;

            $inventoryStatement = $pdo->prepare(
                'SELECT
                    p.product_name,
                    p.price,
                    COALESCE(i.current_stock, 0) AS current_stock
                 FROM Products p
                 INNER JOIN Inventory i ON i.product_id = p.product_id
                 WHERE p.product_id = :product_id
                 FOR UPDATE'
            );

            foreach ($_SESSION['cart'] as $productId => $item) {
                $quantity = (int) ($item['qty'] ?? 0);
                if ($quantity <= 0) {
                    throw new RuntimeException('Cart contains an invalid quantity.');
                }

                $inventoryStatement->execute(['product_id' => (int) $productId]);
                $inventoryRow = $inventoryStatement->fetch(PDO::FETCH_ASSOC);

                if (!$inventoryRow) {
                    throw new RuntimeException('A cart item is no longer available.');
                }

                $availableStock = (int) $inventoryRow['current_stock'];
                if ($quantity > $availableStock) {
                    throw new RuntimeException('Insufficient stock for ' . $inventoryRow['product_name'] . '.');
                }

                $price = (float) $inventoryRow['price'];
                $subtotal = $price * $quantity;
                $totalAmount += $subtotal;

                $validatedItems[] = [
                    'product_id' => (int) $productId,
                    'product_name' => $inventoryRow['product_name'],
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
            $inventoryUpdateStatement = $pdo->prepare(
                'UPDATE Inventory
                 SET current_stock = current_stock - :quantity
                 WHERE product_id = :product_id'
            );

            foreach ($validatedItems as $validatedItem) {
                $saleItemStatement->execute([
                    'sale_id' => $saleId,
                    'product_id' => $validatedItem['product_id'],
                    'quantity' => $validatedItem['quantity'],
                    'selling_price' => $validatedItem['price'],
                    'subtotal' => $validatedItem['subtotal'],
                ]);

                $inventoryUpdateStatement->execute([
                    'quantity' => $validatedItem['quantity'],
                    'product_id' => $validatedItem['product_id'],
                ]);
            }

            sync_reorder_alerts_for_catalog($pdo);
            $pdo->commit();

            $_SESSION['cart'] = [];
            set_flash('success', 'Transaction completed successfully. Order ID: #' . $saleId);
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

    redirect_to('pages/pos.php');
}

$searchTerm = trim((string) ($_GET['q'] ?? ''));

$productQuery = "
    SELECT
        p.product_id,
        p.product_name,
        COALESCE(p.brand, '') AS brand,
        COALESCE(p.product_type, '') AS product_type,
        COALESCE(p.compatibility, '') AS compatibility,
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
    $productQuery .= ' AND (p.product_name LIKE :search_term OR p.brand LIKE :search_term OR p.compatibility LIKE :search_term)';
    $productParameters['search_term'] = '%' . $searchTerm . '%';
}

$productQuery .= ' ORDER BY p.product_name ASC';
$productStatement = $pdo->prepare($productQuery);
foreach ($productParameters as $parameter => $value) {
    $productStatement->bindValue(':' . $parameter, $value, PDO::PARAM_STR);
}
$productStatement->execute();
$products = $productStatement->fetchAll(PDO::FETCH_ASSOC);

$recommendations = recommendations_enabled()
    ? fetch_recommendations_for_products($pdo, array_map(static fn (array $product): int => (int) $product['product_id'], $products))
    : [];

$cart = array_filter($_SESSION['cart'], static fn (array $item): bool => (int) ($item['qty'] ?? 0) > 0);
$_SESSION['cart'] = $cart;

$totalDue = 0.0;
foreach ($cart as $item) {
    $totalDue += ((float) $item['price']) * ((int) $item['qty']);
}

$page_title = 'POINT OF SALE';
include '../includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <form method="GET" class="flex items-end gap-3">
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Search</label>
            <input type="text" name="q" value="<?php echo h($searchTerm); ?>" placeholder="Product, brand, compatibility" class="rounded border border-black px-3 py-2 text-sm w-72">
        </div>
        <button type="submit" class="rounded bg-black px-4 py-2 text-sm text-white hover:bg-gray-800">Search</button>
    </form>

    <div class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-700">
        Default payment: <span class="font-semibold"><?php echo h(preferred_payment_method()); ?></span>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 md:grid-cols-3">
    <div class="space-y-4 md:col-span-2">
        <h3 class="border-b pb-2 text-lg font-bold">Select Products</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <?php if ($products === []): ?>
                <div class="col-span-full rounded border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">
                    No in-stock products match the current filters.
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    $productId = (int) $product['product_id'];
                    $currentStock = (int) $product['current_stock'];
                    $minStockLevel = (int) $product['min_stock_level'];
                    $topRecommendation = $recommendations[$productId][0] ?? null;
                    $isLowStock = $minStockLevel > 0 && $currentStock <= $minStockLevel;
                    ?>
                    <div class="flex flex-col justify-between rounded border bg-white p-4 transition-shadow hover:shadow-lg">
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h4 class="text-sm font-medium"><?php echo h($product['product_name']); ?></h4>
                                    <div class="mt-1 text-xs text-gray-500">
                                        <?php echo h($product['category_name']); ?>
                                        <?php if ($product['brand'] !== ''): ?>
                                            <span class="mx-1">·</span><?php echo h($product['brand']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $isLowStock ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700'; ?>">
                                    <?php echo $isLowStock ? 'Low stock' : 'Ready'; ?>
                                </span>
                            </div>
                            <div class="mt-2 text-xs text-blue-600">Stock: <?php echo h((string) $currentStock); ?><?php echo $minStockLevel > 0 ? ' / Min ' . h((string) $minStockLevel) : ''; ?></div>
                            <?php if ($product['compatibility'] !== ''): ?>
                                <div class="mt-1 text-xs text-gray-500"><?php echo h($product['compatibility']); ?></div>
                            <?php endif; ?>
                            <?php if ($topRecommendation): ?>
                                <div class="mt-2 rounded bg-blue-50 px-2 py-2 text-xs text-blue-700">
                                    Alternative: <?php echo h($topRecommendation['alternative_name']); ?> (<?php echo h($topRecommendation['matched_attribute']); ?>)
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4">
                            <div class="w-full border-t pt-2 text-base font-bold">P<?php echo h(money_format_php((float) $product['price'])); ?></div>
                            <form method="POST" action="<?php echo h(app_url('pages/pos.php')); ?>" class="mt-2 flex gap-2">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo h((string) $productId); ?>">
                                <input type="number" name="qty" value="1" min="1" max="<?php echo h((string) $currentStock); ?>" class="w-16 rounded border px-2 py-1 text-center text-sm">
                                <button type="submit" name="add_to_cart" class="flex-1 rounded bg-black py-1 text-sm text-white hover:bg-gray-800">Add</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="flex h-fit flex-col rounded border bg-white shadow-sm">
        <div class="border-b bg-gray-50 p-4 font-bold">Current Order</div>
        <div class="min-h-[300px] w-full flex-1 overflow-y-auto p-4">
            <?php if ($cart === []): ?>
                <div class="my-auto p-8 text-center text-gray-400">Cart is empty</div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($cart as $productId => $item): ?>
                        <div class="flex items-start justify-between border-b pb-2 text-sm">
                            <div class="flex-1">
                                <div class="font-medium"><?php echo h($item['name']); ?></div>
                                <div class="text-xs text-gray-500">P<?php echo h(money_format_php((float) $item['price'])); ?> x <?php echo h((string) $item['qty']); ?></div>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                <span class="font-bold">P<?php echo h(money_format_php(((float) $item['price']) * ((int) $item['qty']))); ?></span>
                                <form method="POST" action="<?php echo h(app_url('pages/pos.php')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo h((string) $productId); ?>">
                                    <button type="submit" name="remove_cart_item" class="text-xs text-red-500 hover:text-red-700">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="border-t bg-gray-50 p-4">
            <div class="mb-4 flex items-center justify-between">
                <span class="text-lg font-bold">Total Due:</span>
                <span class="text-xl font-bold text-blue-600">P<?php echo h(money_format_php($totalDue)); ?></span>
            </div>

            <form method="POST" action="<?php echo h(app_url('pages/pos.php')); ?>" class="space-y-3" id="checkout-form">
                <?php echo csrf_field(); ?>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Payment Method</label>
                    <select name="payment_method" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                        <?php foreach (['CASH', 'GCASH', 'CARD'] as $paymentMethod): ?>
                            <option value="<?php echo h($paymentMethod); ?>" <?php echo preferred_payment_method() === $paymentMethod ? 'selected' : ''; ?>>
                                <?php echo h($paymentMethod); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="button" <?php echo $cart === [] ? 'disabled' : ''; ?> onclick="openCheckoutModal()" class="w-full rounded py-3 font-bold text-white transition-colors <?php echo $cart === [] ? 'cursor-not-allowed bg-gray-400' : 'bg-green-600 hover:bg-green-700'; ?>">
                    COMPLETE CHECKOUT
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Checkout Confirmation Modal -->
<div id="checkoutModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
        <div class="mb-4 flex items-start gap-3">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-green-100">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-gray-900">Complete Transaction</h3>
                <p class="mt-1 text-sm text-gray-500">Confirm checkout for <strong>P<?php echo h(money_format_php($totalDue)); ?></strong>?</p>
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t pt-4">
            <button type="button" onclick="closeCheckoutModal()" class="rounded border px-4 py-2 text-sm hover:bg-gray-50">Cancel</button>
            <button type="button" onclick="submitCheckout()" class="rounded bg-green-600 px-4 py-2 text-sm font-bold text-white hover:bg-green-700">Confirm</button>
        </div>
    </div>
</div>

<script>
    function openCheckoutModal() {
        var modal = document.getElementById('checkoutModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeCheckoutModal() {
        var modal = document.getElementById('checkoutModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function submitCheckout() {
        var form = document.getElementById('checkout-form');
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'checkout';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    }

    document.getElementById('checkoutModal').addEventListener('click', function (e) {
        if (e.target === this) closeCheckoutModal();
    });
</script>

<?php include '../includes/footer.php'; ?>
