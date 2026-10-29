<?php
declare(strict_types=1);
require_once '../includes/app.php'; require_once '../includes/config.php'; require_once '../includes/domain.php';
require_login([APP_ROLE_ADMIN, APP_ROLE_STAFF]);
$rows = $pdo->query("SELECT p.product_id, p.display_code, p.product_name, COALESCE(c.category_name, '') AS category_name, p.price, COALESCE(i.current_stock, 0) AS stock FROM Products p LEFT JOIN Category c ON c.category_id = p.category_id LEFT JOIN Inventory i ON i.product_id = p.product_id WHERE COALESCE(p.product_status, 'ACTIVE') = 'ACTIVE' ORDER BY p.display_code, p.product_id")->fetchAll();
if (($_GET['download'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="inventory-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, ['Code', 'Product', 'Category', 'Stock', 'Price'], ',', '"', '');
    foreach ($rows as $row) {
        $values = [format_product_code($row), $row['product_name'], $row['category_name'], $row['stock'], $row['price']];
        $values = array_map(static fn($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'" . $value : $value, $values);
        fputcsv($out, $values, ',', '"', '');
    } fclose($out); exit;
}
$page_title = 'INVENTORY LIST'; include '../includes/header.php';
?>
<div class="inventory-tools flex gap-3 mb-5"><a href="records.php" class="border border-black px-4 py-2 rounded">Reports</a><button onclick="window.print()" class="border border-black px-4 py-2 rounded">Print / Save PDF</button><a href="?download=csv" class="border border-black px-4 py-2 rounded">Download CSV</a></div>
<section id="inventory-print"><h2 class="text-lg font-bold"><?php echo h(business_setting('business_name')); ?> · Inventory</h2><p class="text-sm mb-4">As of <?php echo h(date('Y-m-d H:i')); ?></p><table class="w-full border-collapse"><thead><tr><?php foreach (['Code', 'Product', 'Category', 'Stock', 'Price'] as $label): ?><th class="border border-black p-2 text-left"><?php echo h($label); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ([format_product_code($row), $row['product_name'], $row['category_name'], $row['stock'], number_format((float) $row['price'], 2)] as $value): ?><td class="border border-black p-2"><?php echo h((string) $value); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table><?php if (!$rows): ?><p>No active products.</p><?php endif; ?></section>
<style>@media print {
@page {size: A4 portrait; margin: 12mm}
html,body {display:block!important;height:auto!important;min-height:0!important;overflow:visible!important;background:white!important;color:black!important}
.app-sidebar,.app-header,.inventory-tools,#settings-modal,body > script {display:none!important}
.app-main,.app-content {display:block!important;position:static!important;height:auto!important;max-height:none!important;overflow:visible!important;width:auto!important;padding:0!important;margin:0!important}
.app-content > :not(#inventory-print) {display:none!important}
#inventory-print {display:block!important;position:static!important;width:100%;color:black;background:white}
#inventory-print table {width:100%;font-size:10pt;border-collapse:collapse;table-layout:auto}
#inventory-print th,#inventory-print td {border:1px solid black;padding:5px;overflow-wrap:anywhere}
thead {display:table-header-group} tr {break-inside:avoid} h2 {font-size:14pt}
}</style>
<?php include '../includes/footer.php'; ?>
