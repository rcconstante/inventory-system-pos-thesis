<?php
$file = 'C:\xampp\htdocs\inventory\php-inventory\pages\cashier_dashboard.php';
$content = file_get_contents($file);

$fetch_logic = <<<'EOD'
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
EOD;

$content = str_replace(
    "\$transactionsStatement->execute();\n\$transactions = \$transactionsStatement->fetchAll(PDO::FETCH_ASSOC);",
    $fetch_logic,
    $content
);

file_put_contents($file, $content);
echo "OK\n";
