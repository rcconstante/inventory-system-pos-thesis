<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login();

$tabs = [
    'top_selling' => 'Top Selling',
    'sold_items' => 'Sold Items',
    'critical_stocks' => 'Critical Stocks',
    'cancelled_orders' => 'Cancelled Orders',
];

$defaultTab = current_role_id() === APP_ROLE_STAFF ? 'critical_stocks' : 'top_selling';
$activeTab = (string) ($_GET['tab'] ?? $defaultTab);
if (!isset($tabs[$activeTab])) {
    $activeTab = $defaultTab;
}

$dateFrom = normalize_date_input($_GET['from'] ?? '') ?? date('Y-m-01');
$dateTo = normalize_date_input($_GET['to'] ?? '') ?? date('Y-m-d');
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$queryParameters = [
    'from' => $dateFrom,
    'to' => $dateTo,
];
$salesFilters = ['DATE(s.`date`) BETWEEN :from AND :to'];

if (current_role_id() === APP_ROLE_CASHIER) {
    $salesFilters[] = 's.user_id = :user_id';
    $queryParameters['user_id'] = (int) current_user_id();
}

$records = [];

if ($activeTab === 'top_selling') {
    $statement = $pdo->prepare(
        "SELECT
            c.category_name,
            p.product_name,
            SUM(si.quantity) AS total_quantity,
            SUM(si.subtotal) AS total_amount
         FROM Sale_Item si
         INNER JOIN Sale s ON s.sale_id = si.sale_id
         INNER JOIN Products p ON p.product_id = si.product_id
         LEFT JOIN Category c ON c.category_id = p.category_id
         WHERE s.status = 'COMPLETED' AND " . implode(' AND ', $salesFilters) . "
         GROUP BY p.product_id, c.category_name, p.product_name
         ORDER BY total_quantity DESC, total_amount DESC, p.product_name ASC"
    );
    $statement->execute($queryParameters);
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
} elseif ($activeTab === 'sold_items') {
    $statement = $pdo->prepare(
        "SELECT
            s.sale_id,
            c.category_name,
            p.product_name,
            si.quantity,
            si.subtotal
         FROM Sale_Item si
         INNER JOIN Sale s ON s.sale_id = si.sale_id
         INNER JOIN Products p ON p.product_id = si.product_id
         LEFT JOIN Category c ON c.category_id = p.category_id
         WHERE s.status = 'COMPLETED' AND " . implode(' AND ', $salesFilters) . "
         ORDER BY s.`date` DESC, s.sale_id DESC"
    );
    $statement->execute($queryParameters);
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
} elseif ($activeTab === 'cancelled_orders') {
    $statement = $pdo->prepare(
        "SELECT
            s.sale_id,
            c.category_name,
            p.product_name,
            si.quantity,
            si.subtotal
         FROM Sale_Item si
         INNER JOIN Sale s ON s.sale_id = si.sale_id
         INNER JOIN Products p ON p.product_id = si.product_id
         LEFT JOIN Category c ON c.category_id = p.category_id
         WHERE s.status = 'CANCELLED' AND " . implode(' AND ', $salesFilters) . "
         ORDER BY s.`date` DESC, s.sale_id DESC"
    );
    $statement->execute($queryParameters);
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
} else {
    sync_reorder_alerts_for_catalog($pdo);

    $statement = $pdo->query(
        "SELECT
            ra.reorder_id,
            p.product_name,
            COALESCE(p.brand, '') AS brand,
            ra.current_stock,
            ra.min_stock_level,
            ra.alert_status
         FROM Reorder_Alert ra
         INNER JOIN Products p ON p.product_id = ra.product_id
         ORDER BY ra.current_stock ASC, p.product_name ASC"
    );
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'RECORDS';
include '../includes/header.php';
?>

<div class="mb-6 flex flex-wrap gap-8 border-b border-black pb-4">
    <?php foreach ($tabs as $tabKey => $label): ?>
        <a href="?tab=<?php echo h($tabKey); ?>&from=<?php echo h($dateFrom); ?>&to=<?php echo h($dateTo); ?>" class="pb-2 text-sm font-medium <?php echo $activeTab === $tabKey ? 'border-b-2 border-black' : 'text-gray-600 hover:text-black'; ?>">
            <?php echo h($label); ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (in_array($activeTab, ['top_selling', 'sold_items', 'cancelled_orders'], true)): ?>
    <form method="GET" class="mb-6 flex flex-wrap items-end gap-4">
        <input type="hidden" name="tab" value="<?php echo h($activeTab); ?>">
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">From</label>
            <input type="date" name="from" value="<?php echo h($dateFrom); ?>" class="rounded border border-black px-3 py-2 text-sm focus:outline-none">
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">To</label>
            <input type="date" name="to" value="<?php echo h($dateTo); ?>" class="rounded border border-black px-3 py-2 text-sm focus:outline-none">
        </div>
        <button type="submit" class="rounded bg-black px-4 py-2 text-sm text-white hover:bg-gray-800">Apply</button>
    </form>
<?php endif; ?>

<div class="overflow-hidden rounded-lg border border-black">
    <?php if ($activeTab === 'critical_stocks'): ?>
        <div class="grid grid-cols-5 gap-4 border-b border-black bg-white p-4 text-sm font-medium">
            <div>NO</div>
            <div>PRODUCT</div>
            <div>BRAND</div>
            <div>CURRENT STOCK</div>
            <div>MIN STOCK</div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-5 gap-4 border-b border-black bg-white p-4 text-sm font-medium">
            <div><?php echo in_array($activeTab, ['sold_items', 'cancelled_orders'], true) ? 'ORDER ID' : 'NO'; ?></div>
            <div>CATEGORY</div>
            <div>PRODUCT</div>
            <div>QUANTITY</div>
            <div>TOTAL (P)</div>
        </div>
    <?php endif; ?>

    <?php if ($records === []): ?>
        <div class="p-4 text-center text-sm text-gray-500">No records found for the selected view.</div>
    <?php else: ?>
        <?php foreach ($records as $index => $record): ?>
            <?php if ($activeTab === 'critical_stocks'): ?>
                <div class="grid grid-cols-5 gap-4 border-b border-black p-4 text-sm last:border-b-0 hover:bg-gray-50">
                    <div><?php echo h((string) ($index + 1)); ?></div>
                    <div><?php echo h($record['product_name']); ?></div>
                    <div><?php echo h($record['brand'] !== '' ? $record['brand'] : 'N/A'); ?></div>
                    <div class="font-semibold text-red-600"><?php echo h((string) $record['current_stock']); ?></div>
                    <div><?php echo h((string) $record['min_stock_level']); ?></div>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-5 gap-4 border-b border-black p-4 text-sm last:border-b-0 hover:bg-gray-50">
                    <div><?php echo isset($record['sale_id']) ? '#' . h((string) $record['sale_id']) : h((string) ($index + 1)); ?></div>
                    <div><?php echo h($record['category_name'] ?? 'Uncategorized'); ?></div>
                    <div><?php echo h($record['product_name']); ?></div>
                    <div><?php echo h((string) ($record['quantity'] ?? $record['total_quantity'] ?? 0)); ?></div>
                    <div><?php echo h(money_format_php((float) ($record['subtotal'] ?? $record['total_amount'] ?? 0))); ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
