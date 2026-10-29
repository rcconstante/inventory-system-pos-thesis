<?php
declare(strict_types=1);
require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';
require_login();
if (!can_manage_catalog()) { http_response_code(403); exit('Catalog access is required.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('pages/stock_in.php');
    try {
        $id = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $number = trim((string) ($_POST['batch_number'] ?? ''));
        $cost = filter_var($_POST['cost'] ?? '', FILTER_VALIDATE_FLOAT);
        if (!$id || !$quantity || $cost === false || $cost < 0 || strlen($number) > 100) throw new RuntimeException('Enter a valid product, quantity, cost and batch number.');
        $dates = [];
        foreach (['manufacturing_date', 'expiration_date'] as $key) {
            $raw = $_POST[$key] ?? ''; $dates[$key] = normalize_date_input($raw);
            if ($raw !== '' && !$dates[$key]) throw new RuntimeException('Enter a valid date.');
        }
        if ($dates['expiration_date'] && $dates['manufacturing_date'] && $dates['expiration_date'] < $dates['manufacturing_date']) throw new RuntimeException('Expiration must not precede manufacture.');
        if ($dates['expiration_date'] && $dates['expiration_date'] < date('Y-m-d')) throw new RuntimeException('Expired stock cannot be received as sellable stock.');
        $pdo->beginTransaction();
        $product = $pdo->prepare("SELECT product_id FROM Products WHERE product_id = ? AND COALESCE(product_status, 'ACTIVE') = 'ACTIVE' FOR UPDATE");
        $product->execute([$id]);
        if (!$product->fetchColumn()) throw new RuntimeException('Choose an active product.');
        create_stock_batch($pdo, $id, $quantity, $cost, $dates['manufacturing_date'], $dates['expiration_date'], $number ?: null);
        sync_reorder_alerts_for_catalog($pdo); $pdo->commit();
        set_flash('success', 'Stock received. Inventory and batches are updated.');
        redirect_to('pages/product_batches.php?product_id=' . $id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Stock-in: ' . $e->getMessage());
        set_flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Stock could not be saved. Please try again.');
    }
}
$products = $pdo->query("SELECT product_id, display_code, product_name FROM Products WHERE COALESCE(product_status, 'ACTIVE') = 'ACTIVE' ORDER BY display_code, product_id")->fetchAll();
$page_title = 'STOCK-IN'; include '../includes/header.php';
?>
<a href="products.php" class="mb-5 inline-block underline text-sm">Back to Inventory</a>
<form method="POST" class="max-w-3xl rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-6">
<?php echo csrf_field(); ?>
<h2 class="text-lg font-bold mb-2">Receive stock</h2><p class="text-sm mb-5">Select an existing product and record its incoming batch. Product details do not change stock.</p>
<label class="block mb-4">Product<select name="product_id" required class="mt-1 w-full rounded border border-black p-2 dark:bg-gray-700"><option value="">Select product</option><?php foreach ($products as $product): ?><option value="<?php echo (int) $product['product_id']; ?>"><?php echo h(format_product_code($product) . ' — ' . $product['product_name']); ?></option><?php endforeach; ?></select></label>
<div class="grid gap-4 md:grid-cols-2">
<?php foreach (['batch_number' => ['Batch number (optional)', 'text'], 'quantity' => ['Quantity received', 'number'], 'cost' => ['Unit acquisition cost', 'number'], 'manufacturing_date' => ['Manufacturing date', 'date'], 'expiration_date' => ['Expiration date', 'date']] as $key => [$label, $type]): ?>
<label><?php echo h($label); ?><input name="<?php echo h($key); ?>" type="<?php echo h($type); ?>" <?php echo in_array($key, ['quantity', 'cost'], true) ? 'required min="' . ($key === 'quantity' ? '1" max="1000000" step="1"' : '0" step="0.01"') : ''; ?> maxlength="100" class="mt-1 w-full rounded border border-black p-2 dark:bg-gray-700"></label>
<?php endforeach; ?></div><button class="mt-6 rounded bg-black text-white px-5 py-2">Receive stock</button></form>
<?php include '../includes/footer.php'; ?>
