<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login([APP_ROLE_ADMIN]);

$summaryStatement = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN DATE(`date`) = CURDATE() AND status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) AS daily_sales
     FROM Sale"
);
$summary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: ['daily_sales' => 0];

$countStatement = $pdo->query("SELECT COUNT(*) FROM Sale WHERE DATE(`date`) = CURDATE()");
$totalTransactions = (int) $countStatement->fetchColumn();

// Fast Moving Products
$fastMovingStatement = $pdo->query(
    "SELECT p.product_name, SUM(si.quantity) as total_sold
     FROM Sale_Item si
     JOIN Products p ON si.product_id = p.product_id
     JOIN Sale s ON si.sale_id = s.sale_id
     WHERE s.status = 'COMPLETED'
     GROUP BY p.product_id
     ORDER BY total_sold DESC
     LIMIT 4"
);
$fastMovingProducts = $fastMovingStatement->fetchAll(PDO::FETCH_ASSOC);

// Slow Moving Products
$slowMovingStatement = $pdo->query(
    "SELECT p.product_name, COALESCE(SUM(si.quantity), 0) as total_sold
     FROM Products p
     LEFT JOIN Sale_Item si ON p.product_id = si.product_id
     LEFT JOIN Sale s ON si.sale_id = s.sale_id AND s.status = 'COMPLETED'
     GROUP BY p.product_id
     ORDER BY total_sold ASC, p.product_name ASC
     LIMIT 4"
);
$slowMovingProducts = $slowMovingStatement->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'DASHBOARD';
include '../includes/header.php';
?>

<div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2">
    <div class="flex flex-col items-center justify-center rounded-lg bg-[#8E9CFF] p-8 text-black text-center shadow-sm">
        <p class="text-lg font-normal mb-2">Daily Transaction (Today)</p>
        <p class="text-3xl font-bold"><?php echo h((string) $totalTransactions); ?></p>
    </div>

    <div class="flex flex-col items-center justify-center rounded-lg bg-[#4CD995] p-8 text-black text-center shadow-sm">
        <p class="text-lg font-normal mb-2">Daily Sales (Today)</p>
        <p class="text-3xl font-bold"><?php echo h(money_format_php((float) $summary['daily_sales'])); ?></p>
    </div>
</div>

<div class="overflow-hidden border border-black dark:border-gray-600 bg-white dark:bg-gray-800">
    <div class="grid grid-cols-2 divide-x divide-black dark:divide-gray-600">
        <!-- Fast Moving Products -->
        <div class="p-6">
            <h3 class="mb-6 text-center text-lg font-bold dark:text-white">FAST MOVING PRODUCTS</h3>
            <?php if ($fastMovingProducts === []): ?>
                <p class="text-center text-gray-500 dark:text-gray-400">No data available.</p>
            <?php else: ?>
                <ul class="space-y-4 pl-8 list-none">
                    <?php foreach ($fastMovingProducts as $index => $product): ?>
                        <li class="text-base text-black dark:text-white">
                            <?php echo ($index + 1) . '. ' . h($product['product_name']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Slow Moving Products -->
        <div class="p-6">
            <h3 class="mb-6 text-center text-lg font-bold dark:text-white">SLOW MOVING PRODUCTS</h3>
            <?php if ($slowMovingProducts === []): ?>
                <p class="text-center text-gray-500 dark:text-gray-400">No data available.</p>
            <?php else: ?>
                <ul class="space-y-4 pl-8 list-none">
                    <?php foreach ($slowMovingProducts as $index => $product): ?>
                        <li class="text-base text-black dark:text-white">
                            <?php echo ($index + 1) . '. ' . h($product['product_name']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
