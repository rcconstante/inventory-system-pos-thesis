<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login([APP_ROLE_ADMIN, APP_ROLE_CASHIER]);

$tabs = [
    'top_selling' => 'Top Selling',
    'sold_items' => 'Sold Items',
    'critical_stocks' => 'Critical Stocks',
    'cancelled_orders' => 'Returned Orders',
];

$defaultTab = 'top_selling';
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
            p.product_id,
            c.category_name,
            p.product_name,
            p.brand,
            SUM(si.quantity) AS total_quantity,
            SUM(si.subtotal) AS total_amount
         FROM Sale_Item si
         INNER JOIN Sale s ON s.sale_id = si.sale_id
         INNER JOIN Products p ON p.product_id = si.product_id
         LEFT JOIN Category c ON c.category_id = p.category_id
         WHERE s.status = 'COMPLETED' AND " . implode(' AND ', $salesFilters) . "
         GROUP BY p.product_id, c.category_name, p.product_name, p.brand
         ORDER BY total_quantity DESC, total_amount DESC, p.product_name ASC"
    );
    $statement->execute($queryParameters);
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
} elseif ($activeTab === 'sold_items') {
    $statement = $pdo->prepare(
        "SELECT
            s.sale_id,
            p.product_id,
            c.category_name,
            p.product_name,
            p.brand,
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
            p.product_id,
            c.category_name,
            p.product_name,
            p.brand,
            si.quantity
         FROM Sale_Item si
         INNER JOIN Sale s ON s.sale_id = si.sale_id
         INNER JOIN Products p ON p.product_id = si.product_id
         LEFT JOIN Category c ON c.category_id = p.category_id
         WHERE s.status = 'RETURNED' AND " . implode(' AND ', $salesFilters) . "
         ORDER BY s.`date` DESC, s.sale_id DESC"
    );
    $statement->execute($queryParameters);
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
} else {
    sync_reorder_alerts_for_catalog($pdo);

    $statement = $pdo->query(
        "SELECT
            ra.reorder_id,
            p.product_id,
            c.category_name,
            p.product_name,
            COALESCE(p.brand, '') AS brand,
            ra.current_stock,
            ra.min_stock_level,
            ra.alert_status
         FROM Reorder_Alert ra
         INNER JOIN Products p ON p.product_id = ra.product_id
         LEFT JOIN Category c ON c.category_id = p.category_id
         ORDER BY ra.current_stock ASC, p.product_name ASC"
    );
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'REPORTS';
include '../includes/header.php';
?>

<div class="mb-6 flex flex-wrap gap-8 border-b border-black dark:border-gray-600 pb-4">
    <?php foreach ($tabs as $tabKey => $label): ?>
        <a href="?tab=<?php echo h($tabKey); ?>&from=<?php echo h($dateFrom); ?>&to=<?php echo h($dateTo); ?>" class="pb-2 text-sm font-medium <?php echo $activeTab === $tabKey ? 'border-b-2 border-black dark:border-gray-100 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:text-black dark:hover:text-gray-200'; ?>">
            <?php echo h($label); ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (in_array($activeTab, ['top_selling', 'sold_items', 'cancelled_orders'], true)): ?>
    <form method="GET" class="mb-6 flex flex-wrap items-end gap-4">
        <input type="hidden" name="tab" value="<?php echo h($activeTab); ?>">
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">From</label>
            <input type="date" name="from" value="<?php echo h($dateFrom); ?>" class="rounded border border-black dark:border-gray-600 px-3 py-2 text-sm focus:outline-none dark:bg-gray-800 dark:text-gray-100">
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">To</label>
            <input type="date" name="to" value="<?php echo h($dateTo); ?>" class="rounded border border-black dark:border-gray-600 px-3 py-2 text-sm focus:outline-none dark:bg-gray-800 dark:text-gray-100">
        </div>
        <button type="submit" class="rounded bg-black dark:bg-gray-600 px-4 py-2 text-sm text-white hover:bg-gray-800 dark:hover:bg-gray-500">Apply</button>
    </form>
<?php endif; ?>

<!-- Print / PDF Buttons -->
<div class="mb-4 flex gap-3">
    <button type="button" onclick="printReport()" class="rounded border border-black dark:border-gray-600 px-4 py-2 text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-gray-100">Print</button>
    <button type="button" onclick="exportPDF()" class="rounded border border-black dark:border-gray-600 px-4 py-2 text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-gray-100">Export PDF</button>
</div>

<div id="reportContent" class="overflow-hidden rounded-lg border border-black dark:border-gray-600">
    <?php if ($activeTab === 'critical_stocks'): ?>
        <div class="grid grid-cols-7 gap-4 border-b border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-4 text-xs font-medium uppercase dark:text-gray-300">
            <div>NO</div>
            <div>PRODUCT ID</div>
            <div>CATEGORY</div>
            <div>PRODUCT NAME</div>
            <div>BRAND</div>
            <div>STOCK ON HAND</div>
            <div>REORDER LEVEL</div>
        </div>
    <?php elseif ($activeTab === 'cancelled_orders'): ?>
        <div class="grid grid-cols-7 gap-4 border-b border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-4 text-xs font-medium uppercase dark:text-gray-300">
            <div>NO</div>
            <div>PRODUCT ID</div>
            <div>CATEGORY</div>
            <div>PRODUCT NAME</div>
            <div>BRAND</div>
            <div>QUANTITY</div>
            <div>REASON</div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-7 gap-4 border-b border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-4 text-xs font-medium uppercase dark:text-gray-300">
            <div>NO</div>
            <div>PRODUCT ID</div>
            <div>CATEGORY</div>
            <div>PRODUCT NAME</div>
            <div>BRAND</div>
            <div>QUANTITY</div>
            <div>TOTAL SALES</div>
        </div>
    <?php endif; ?>

    <?php if ($records === []): ?>
        <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">No records found for the selected view.</div>
    <?php else: ?>
        <?php foreach ($records as $index => $record): ?>
            <?php if ($activeTab === 'critical_stocks'): ?>
                <div class="grid grid-cols-7 items-center gap-4 border-b border-black dark:border-gray-600 p-4 text-sm last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">
                    <div><?php echo h((string) ($index + 1)); ?></div>
                    <div><?php echo h(str_pad((string) ($record['product_id'] ?? ''), 3, '0', STR_PAD_LEFT)); ?></div>
                    <div><?php echo h($record['category_name'] ?? 'Uncategorized'); ?></div>
                    <div><?php echo h($record['product_name']); ?></div>
                    <div><?php echo h($record['brand'] !== '' ? $record['brand'] : 'N/A'); ?></div>
                    <div class="font-semibold text-red-600"><?php echo h((string) $record['current_stock']); ?></div>
                    <div><?php echo h((string) $record['min_stock_level']); ?></div>
                </div>
            <?php elseif ($activeTab === 'cancelled_orders'): ?>
                <div class="grid grid-cols-7 items-center gap-4 border-b border-black dark:border-gray-600 p-4 text-sm last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">
                    <div><?php echo h((string) ($index + 1)); ?></div>
                    <div><?php echo h(str_pad((string) ($record['product_id'] ?? ''), 3, '0', STR_PAD_LEFT)); ?></div>
                    <div><?php echo h($record['category_name'] ?? 'Uncategorized'); ?></div>
                    <div><?php echo h($record['product_name']); ?></div>
                    <div><?php echo h($record['brand'] !== '' ? $record['brand'] : 'N/A'); ?></div>
                    <div><?php echo h((string) ($record['quantity'] ?? 0)); ?></div>
                    <div>Returned</div>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-7 items-center gap-4 border-b border-black dark:border-gray-600 p-4 text-sm last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-100">
                    <div><?php echo h((string) ($index + 1)); ?></div>
                    <div><?php echo h(str_pad((string) ($record['product_id'] ?? ''), 3, '0', STR_PAD_LEFT)); ?></div>
                    <div><?php echo h($record['category_name'] ?? 'Uncategorized'); ?></div>
                    <div><?php echo h($record['product_name']); ?></div>
                    <div><?php echo h($record['brand'] !== '' ? $record['brand'] : 'N/A'); ?></div>
                    <div><?php echo h((string) ($record['quantity'] ?? $record['total_quantity'] ?? 0)); ?></div>
                    <div><?php echo h(money_format_php((float) ($record['subtotal'] ?? $record['total_amount'] ?? 0))); ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function printReport() {
    var content = document.getElementById('reportContent');
    var printWin = window.open('', '_blank', 'width=900,height=700');
    printWin.document.write('<html><head><title>Report - <?php echo h($tabs[$activeTab]); ?></title>');
    printWin.document.write('<style>body{font-family:sans-serif;margin:20px;font-size:12px}h2{margin-bottom:10px}');
    printWin.document.write('.grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;padding:8px;border-bottom:1px solid #ccc}');
    printWin.document.write('.grid div{overflow:hidden;text-overflow:ellipsis}');
    printWin.document.write('.text-xs{font-size:10px;font-weight:bold;text-transform:uppercase}');
    printWin.document.write('.text-sm{font-size:12px}.font-semibold{font-weight:600}.text-red-600{color:#dc2626}');
    printWin.document.write('@media print{body{margin:0}}</style></head><body>');
    printWin.document.write('<h2><?php echo h($tabs[$activeTab]); ?> Report</h2>');
    printWin.document.write('<p style="margin-bottom:12px;font-size:11px">Period: <?php echo h($dateFrom); ?> to <?php echo h($dateTo); ?></p>');
    printWin.document.write(content.innerHTML);
    printWin.document.write('</body></html>');
    printWin.document.close();
    printWin.focus();
    printWin.print();
}

function exportPDF() {
    var content = document.getElementById('reportContent');
    var opt = {
        margin: 10,
        filename: '<?php echo h($activeTab); ?>_report_<?php echo date('Y-m-d'); ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };
    html2pdf().set(opt).from(content).save();
}
</script>

<?php include '../includes/footer.php'; ?>
