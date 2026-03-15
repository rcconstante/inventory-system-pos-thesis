<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login([APP_ROLE_STAFF]);

$statsStatement = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM Category) AS category_count,
        (SELECT COUNT(*) FROM Products) AS product_count,
        (SELECT COUNT(*) FROM Inventory WHERE current_stock <= min_stock_level AND min_stock_level > 0) AS critical_stock_count,
        (SELECT COUNT(*) FROM Reorder_Alert) AS active_alert_count"
);
$stats = $statsStatement->fetch(PDO::FETCH_ASSOC) ?: [
    'category_count' => 0,
    'product_count' => 0,
    'critical_stock_count' => 0,
    'active_alert_count' => 0,
];

$lowStockStatement = $pdo->query(
    "SELECT
        p.product_name,
        COALESCE(p.brand, '') AS brand,
        i.current_stock,
        i.min_stock_level
     FROM Inventory i
     INNER JOIN Products p ON p.product_id = i.product_id
     WHERE i.min_stock_level > 0 AND i.current_stock <= i.min_stock_level
     ORDER BY i.current_stock ASC, p.product_name ASC
     LIMIT 10"
);
$lowStockProducts = $lowStockStatement->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'STAFF DASHBOARD';
include '../includes/header.php';
?>

<h2 class="mb-8 text-2xl font-normal text-black">WELCOME TO FIVE BROTHERS TRADING</h2>

<div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-lg bg-gradient-to-r from-[#0AC074] to-[#00D4B4] p-6 text-white">
        <p class="text-sm font-medium uppercase tracking-wide">Categories</p>
        <p class="mt-3 text-3xl font-bold"><?php echo h((string) $stats['category_count']); ?></p>
    </div>
    <div class="rounded-lg bg-gradient-to-r from-[#0065FF] to-[#4B9FFF] p-6 text-white">
        <p class="text-sm font-medium uppercase tracking-wide">Products</p>
        <p class="mt-3 text-3xl font-bold"><?php echo h((string) $stats['product_count']); ?></p>
    </div>
    <div class="rounded-lg bg-gradient-to-r from-[#F97316] to-[#FB7185] p-6 text-white">
        <p class="text-sm font-medium uppercase tracking-wide">Critical Stocks</p>
        <p class="mt-3 text-3xl font-bold"><?php echo h((string) $stats['critical_stock_count']); ?></p>
    </div>
    <div class="rounded-lg bg-gradient-to-r from-[#4338CA] to-[#6366F1] p-6 text-white">
        <p class="text-sm font-medium uppercase tracking-wide">Active Alerts</p>
        <p class="mt-3 text-3xl font-bold"><?php echo h((string) $stats['active_alert_count']); ?></p>
    </div>
</div>

<div>
    <div class="mb-4 flex items-center justify-between">
        <h3 class="text-base font-bold">LOW STOCK WATCHLIST</h3>
        <a href="<?php echo h(app_url('pages/records.php?tab=critical_stocks')); ?>" class="text-sm font-medium text-blue-600 hover:underline">View full report</a>
    </div>

    <div class="overflow-hidden rounded-lg border border-black">
        <div class="grid grid-cols-4 gap-4 border-b border-black bg-white p-4 text-sm font-medium">
            <div>PRODUCT</div>
            <div>BRAND</div>
            <div>CURRENT STOCK</div>
            <div>MIN STOCK LEVEL</div>
        </div>

        <?php if ($lowStockProducts === []): ?>
            <div class="p-4 text-center text-sm text-gray-500">No low stock products right now.</div>
        <?php else: ?>
            <?php foreach ($lowStockProducts as $product): ?>
                <div class="grid grid-cols-4 gap-4 border-b border-black p-4 text-sm last:border-b-0">
                    <div><?php echo h($product['product_name']); ?></div>
                    <div><?php echo h($product['brand'] !== '' ? $product['brand'] : 'N/A'); ?></div>
                    <div class="font-semibold text-red-600"><?php echo h((string) $product['current_stock']); ?></div>
                    <div><?php echo h((string) $product['min_stock_level']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
