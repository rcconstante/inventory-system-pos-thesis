<?php
declare(strict_types=1);
require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';
require_login([APP_ROLE_ADMIN, APP_ROLE_STAFF]);
ensure_product_lifecycle_schema($pdo);
purge_expired_product_archives($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('pages/product_archives.php');
    try {
        if (!can_delete_catalog()) throw new RuntimeException('You cannot restore products.');
        if (!isset($_POST['restore_product'])) throw new RuntimeException('Invalid archive action.');
        $id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new RuntimeException('Choose a valid product.');
        $pdo->beginTransaction();
        restore_archived_product($pdo, $id);
        sync_reorder_alerts_for_catalog($pdo);
        sync_feature_matches_for_catalog($pdo);
        $pdo->commit();
        set_flash('success', 'Product restored to Inventory.');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Product restoration failed: ' . $exception->getMessage());
        set_flash('error', 'The product could not be restored. Please try again.');
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', $exception->getMessage());
    }
    redirect_to('pages/product_archives.php');
}
$rawSearch = $_GET['q'] ?? '';
$search = is_string($rawSearch) ? substr(trim($rawSearch), 0, 150) : '';
$where = "p.product_status = 'ARCHIVED' AND p.purge_after > NOW()";
$params = [];
if ($search !== '') {
    $where .= " AND p.product_name LIKE ? ESCAPE '='";
    $params[] = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
}
$count = $pdo->prepare('SELECT COUNT(*) FROM Products p WHERE ' . $where);
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / 20));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$statement = $pdo->prepare('SELECT p.product_id, p.product_name, p.archived_at, p.purge_after, c.category_name FROM Products p LEFT JOIN Category c ON c.category_id = p.category_id WHERE ' . $where . ' ORDER BY p.purge_after, p.product_name, p.product_id LIMIT 20 OFFSET ' . (($page - 1) * 20));
$statement->execute($params);
$archives = $statement->fetchAll(PDO::FETCH_ASSOC);
$page_title = 'ARCHIVED PRODUCTS';
include '../includes/header.php';
?>
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <a href="<?php echo h(app_url('pages/products.php')); ?>" class="rounded border border-black dark:border-gray-600 px-4 py-2 text-sm">Back to Inventory</a>
    <form method="GET" class="flex gap-2"><label for="archiveSearch" class="sr-only">Search archived products</label><input id="archiveSearch" name="q" value="<?php echo h($search); ?>" maxlength="150" placeholder="Search archived products" class="rounded border border-black dark:border-gray-600 px-3 py-2 dark:bg-gray-800"><button class="rounded border border-black dark:border-gray-600 px-3 py-2">Search</button></form>
</div>
<p class="mb-4 text-sm">Archived products are hidden from Inventory and POS. Restore a product before its deadline to bring it back. After 30 days it can no longer be restored.</p>
<div class="overflow-x-auto"><table class="w-full border-collapse text-left text-sm">
    <thead><tr><th class="border border-black dark:border-gray-600 p-3">Product</th><th class="border border-black dark:border-gray-600 p-3">Category</th><th class="border border-black dark:border-gray-600 p-3">Archived on</th><th class="border border-black dark:border-gray-600 p-3">Restore before</th><th class="border border-black dark:border-gray-600 p-3">Action</th></tr></thead>
    <tbody>
    <?php if ($archives === []): ?><tr><td colspan="5" class="border border-black dark:border-gray-600 p-8 text-center text-gray-500">No archived products found.</td></tr><?php endif; ?>
    <?php foreach ($archives as $product): ?>
        <tr>
            <td class="border border-black dark:border-gray-600 p-3"><?php echo h($product['product_name']); ?></td>
            <td class="border border-black dark:border-gray-600 p-3"><?php echo h($product['category_name'] ?? 'Uncategorized'); ?></td>
            <td class="border border-black dark:border-gray-600 p-3"><?php echo $product['archived_at'] ? h(date('M j, Y g:i A', strtotime($product['archived_at']))) : '—'; ?></td>
            <td class="border border-black dark:border-gray-600 p-3"><?php echo h(date('M j, Y g:i A', strtotime($product['purge_after']))); ?></td>
            <td class="border border-black dark:border-gray-600 p-3"><form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" value="<?php echo (int) $product['product_id']; ?>">
                <button name="restore_product" class="rounded border border-black dark:border-gray-600 px-4 py-2" aria-label="<?php echo h('Restore ' . $product['product_name']); ?>">Restore</button>
            </form></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<nav aria-label="Archive pages" class="mt-4 flex flex-wrap justify-end gap-3">
    <?php if ($page > 1): ?><a class="underline" href="?<?php echo h(http_build_query(['q' => $search, 'page' => $page - 1])); ?>">Previous</a><?php endif; ?>
    <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
    <?php if ($page < $pages): ?><a class="underline" href="?<?php echo h(http_build_query(['q' => $search, 'page' => $page + 1])); ?>">Next</a><?php endif; ?>
</nav>
<?php include '../includes/footer.php'; ?>
