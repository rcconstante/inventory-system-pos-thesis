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

$currentUser = current_user_session();
$exportedBy = $currentUser['full_name'] !== '' ? $currentUser['full_name'] : 'System User';
$businessName = 'FIVE BROTHERS TRADING';
$businessAddress = 'Mabuhay, Carmona, Cavite';
$exportedAt = date('F d, Y h:i A');
$exportPeriodLabel = in_array($activeTab, ['top_selling', 'sold_items', 'cancelled_orders'], true)
    ? ($dateFrom === $dateTo ? date('F d, Y', strtotime($dateFrom)) : date('F d, Y', strtotime($dateFrom)) . ' to ' . date('F d, Y', strtotime($dateTo)))
    : 'As of ' . date('F d, Y');
$reportTitle = $tabs[$activeTab] . ' Report';
$reportSubtitle = in_array($activeTab, ['top_selling', 'sold_items', 'cancelled_orders'], true)
    ? 'For ' . $exportPeriodLabel
    : $exportPeriodLabel;
$reportFooterNote = 'Generated from the ' . $tabs[$activeTab] . ' module';

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

<div id="reportExportShell" style="position:absolute;left:-9999px;top:0;width:794px;background:#ffffff;color:#111827;">
    <div id="reportExportSheet" class="report-export-sheet">
        <style>
            .report-export-sheet {
                box-sizing: border-box;
                width: 794px;
                padding: 28px;
                background: #ffffff;
                color: #111827;
                font-family: Arial, sans-serif;
                font-size: 12px;
            }

            .report-export-brand {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                margin-bottom: 16px;
            }

            .report-export-logo {
                width: 56px;
                height: 56px;
                border: 1px solid #1d4ed8;
                border-radius: 16px;
                background: #eff6ff;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .report-export-business-name {
                margin: 0;
                font-size: 18px;
                font-weight: 700;
                letter-spacing: 0.03em;
                text-transform: uppercase;
                text-align: center;
            }

            .report-export-business-address {
                margin: 4px 0 0;
                font-size: 12px;
                color: #4b5563;
                text-align: center;
            }

            .report-export-titlebar {
                margin: 0 0 10px;
                padding: 12px 16px;
                border: 1.5px solid #1e3a8a;
                background: #1d5ec9;
                color: #ffffff;
                font-size: 24px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-align: center;
                text-transform: uppercase;
            }

            .report-export-subtitle {
                margin: 0 0 16px;
                font-size: 14px;
                font-weight: 700;
                text-align: center;
            }

            .report-export-meta {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 16px;
            }

            .report-export-meta td {
                width: 33.33%;
                border: 1px solid #111827;
                padding: 8px 10px;
                vertical-align: top;
            }

            .report-export-meta-label {
                display: block;
                margin-bottom: 4px;
                font-size: 10px;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #1e3a8a;
            }

            .report-export-table {
                width: 100%;
                border-collapse: collapse;
            }

            .report-export-table th,
            .report-export-table td {
                border: 1px solid #111827;
                padding: 8px 10px;
                vertical-align: top;
            }

            .report-export-table th {
                background: #eff6ff;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.03em;
                text-transform: uppercase;
                text-align: left;
            }

            .report-export-table td.numeric,
            .report-export-table th.numeric {
                text-align: right;
            }

            .report-export-empty {
                padding: 16px 10px;
                text-align: center;
                color: #4b5563;
            }

            .report-export-footer {
                display: flex;
                justify-content: space-between;
                gap: 16px;
                margin-top: 16px;
                padding-top: 10px;
                border-top: 1px solid #111827;
                font-size: 11px;
                color: #374151;
            }
        </style>

        <div class="report-export-brand">
            <div class="report-export-logo" aria-hidden="true">
                <svg width="34" height="34" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M0 24.5348C0 28.6303 3.32014 31.9505 7.41573 31.9505H32L0 1.79497V24.5348Z" fill="url(#report_logo_gradient_1)"/>
                    <path opacity="0.983161" fill-rule="evenodd" clip-rule="evenodd" d="M0 7.40946C0 3.31387 3.32014 0 7.41573 0H32C32 0 3.73034 25.7671 1.2263 28.2342C0 29.9414 1.43276 31.8648 1.43276 31.8648C1.43276 31.8648 0 31.1225 0 29.5198C0 28.7041 0 15.8841 0 7.40946Z" fill="url(#report_logo_gradient_2)"/>
                    <defs>
                        <linearGradient id="report_logo_gradient_1" x1="16" y1="1.79497" x2="16" y2="31.9505" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#4CD995" />
                            <stop offset="1" stop-color="#4CD995" stop-opacity="0" />
                        </linearGradient>
                        <linearGradient id="report_logo_gradient_2" x1="16" y1="0" x2="16" y2="31.8648" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#8E9CFF" />
                            <stop offset="1" stop-color="#8E9CFF" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <div>
                <p class="report-export-business-name"><?php echo h($businessName); ?></p>
                <p class="report-export-business-address"><?php echo h($businessAddress); ?></p>
            </div>
        </div>

        <div class="report-export-titlebar"><?php echo h($reportTitle); ?></div>
        <div class="report-export-subtitle"><?php echo h($reportSubtitle); ?></div>

        <table class="report-export-meta">
            <tr>
                <td>
                    <span class="report-export-meta-label">Printed / Exported By</span>
                    <?php echo h($exportedBy); ?>
                </td>
                <td>
                    <span class="report-export-meta-label">Business Name</span>
                    <?php echo h($businessName); ?>
                </td>
                <td>
                    <span class="report-export-meta-label">Date</span>
                    <?php echo h($exportedAt); ?>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="report-export-meta-label">Module</span>
                    <?php echo h($tabs[$activeTab]); ?>
                </td>
                <td>
                    <span class="report-export-meta-label">Covered Period</span>
                    <?php echo h($exportPeriodLabel); ?>
                </td>
                <td>
                    <span class="report-export-meta-label">Address</span>
                    <?php echo h($businessAddress); ?>
                </td>
            </tr>
        </table>

        <table class="report-export-table">
            <thead>
                <?php if ($activeTab === 'critical_stocks'): ?>
                    <tr>
                        <th style="width:52px;">No</th>
                        <th style="width:84px;">Product ID</th>
                        <th style="width:110px;">Category</th>
                        <th>Product Name</th>
                        <th style="width:110px;">Brand</th>
                        <th class="numeric" style="width:88px;">Stock On Hand</th>
                        <th class="numeric" style="width:92px;">Reorder Level</th>
                    </tr>
                <?php elseif ($activeTab === 'cancelled_orders'): ?>
                    <tr>
                        <th style="width:52px;">No</th>
                        <th style="width:84px;">Product ID</th>
                        <th style="width:110px;">Category</th>
                        <th>Product Name</th>
                        <th style="width:110px;">Brand</th>
                        <th class="numeric" style="width:80px;">Quantity</th>
                        <th style="width:110px;">Reason</th>
                    </tr>
                <?php else: ?>
                    <tr>
                        <th style="width:52px;">No</th>
                        <th style="width:84px;">Product ID</th>
                        <th style="width:110px;">Category</th>
                        <th>Product Name</th>
                        <th style="width:110px;">Brand</th>
                        <th class="numeric" style="width:80px;">Quantity</th>
                        <th class="numeric" style="width:110px;">Total Sales</th>
                    </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr>
                        <td colspan="7" class="report-export-empty">No records found for the selected view.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $index => $record): ?>
                        <tr>
                            <td><?php echo h((string) ($index + 1)); ?></td>
                            <td><?php echo h(str_pad((string) ($record['product_id'] ?? ''), 3, '0', STR_PAD_LEFT)); ?></td>
                            <td><?php echo h($record['category_name'] ?? 'Uncategorized'); ?></td>
                            <td><?php echo h($record['product_name']); ?></td>
                            <td><?php echo h(($record['brand'] ?? '') !== '' ? $record['brand'] : 'N/A'); ?></td>
                            <?php if ($activeTab === 'critical_stocks'): ?>
                                <td class="numeric"><?php echo h((string) $record['current_stock']); ?></td>
                                <td class="numeric"><?php echo h((string) $record['min_stock_level']); ?></td>
                            <?php elseif ($activeTab === 'cancelled_orders'): ?>
                                <td class="numeric"><?php echo h((string) ($record['quantity'] ?? 0)); ?></td>
                                <td>Returned</td>
                            <?php else: ?>
                                <td class="numeric"><?php echo h((string) ($record['quantity'] ?? $record['total_quantity'] ?? 0)); ?></td>
                                <td class="numeric"><?php echo h(money_format_php((float) ($record['subtotal'] ?? $record['total_amount'] ?? 0))); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="report-export-footer">
            <div><?php echo h($reportFooterNote); ?></div>
            <div>&copy; <?php echo h($businessName); ?> <?php echo date('Y'); ?></div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function printReport() {
    var content = document.getElementById('reportExportSheet');
    var printWin = window.open('', '_blank', 'width=900,height=700');
    printWin.document.write('<html><head><title>Report - <?php echo h($tabs[$activeTab]); ?></title>');
    printWin.document.write('<style>body{margin:0;background:#fff;color:#111827}img,svg{max-width:100%}*{box-sizing:border-box}</style></head><body>');
    printWin.document.write(content.innerHTML);
    printWin.document.write('</body></html>');
    printWin.document.close();
    printWin.focus();
    printWin.print();
}

function exportPDF() {
    var content = document.getElementById('reportExportSheet');
    var opt = {
        margin: 10,
        filename: '<?php echo h($activeTab); ?>_report_<?php echo date('Y-m-d'); ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(content).save();
}
</script>

<?php include '../includes/footer.php'; ?>
