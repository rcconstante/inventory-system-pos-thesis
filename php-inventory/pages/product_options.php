<?php
declare(strict_types=1);
require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';
require_login([APP_ROLE_ADMIN, APP_ROLE_STAFF]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('pages/product_options.php');
    try {
        if (!can_manage_catalog()) throw new RuntimeException('You cannot manage product options.');
        $kind = $_POST['option_kind'] ?? null;
        $action = $_POST['action'] ?? null;
        if (!in_array($kind, ['vehicle', 'type'], true) || !in_array($action, ['add', 'update', 'delete', 'restore'], true)) throw new RuntimeException('Choose a valid product option action.');
        $id = filter_var($_POST['option_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name = $_POST['option_name'] ?? '';
        if (!is_string($name)) throw new RuntimeException('Enter a valid name.');
        if ($action !== 'add' && !$id) throw new RuntimeException('Choose an existing option.');
        $pdo->beginTransaction();
        if ($action === 'delete') {
            if (!can_delete_catalog()) throw new RuntimeException('You cannot delete product options.');
            delete_product_option($pdo, $kind, $id);
        } elseif ($action === 'restore') {
            if ($kind !== 'vehicle') throw new RuntimeException('Invalid restore action.');
            $restore = $pdo->prepare('UPDATE Vehicle_Compatibility SET is_active = 1 WHERE vehicle_id = ? AND is_active = 0');
            $restore->execute([$id]);
            if ($restore->rowCount() !== 1) throw new RuntimeException('The archived entry was not found.');
        } elseif ($kind === 'vehicle') {
            save_vehicle_option($pdo, $action === 'add' ? null : $id, $name);
        } elseif ($action === 'add') {
            $existing = $pdo->prepare('SELECT type_id FROM Product_Type WHERE type_key = ?');
            $existing->execute([vehicle_name_key($name)]);
            if ($existing->fetchColumn() !== false) throw new RuntimeException('That product type already exists.');
            save_product_type_option($pdo, $name);
        } else update_product_type_option($pdo, $id, $name);
        sync_feature_matches_for_catalog($pdo);
        $pdo->commit();
        set_flash('success', $action === 'delete' ? 'Option deleted.' : ($action === 'add' ? 'Option added.' : 'Option updated. Linked products are up to date.'));
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Product option update failed: ' . $exception->getMessage());
        set_flash('error', 'The option could not be saved. Check for an existing name and try again.');
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', $exception->getMessage());
    }
    redirect_to('pages/product_options.php');
}
$compatibility = $pdo->query('SELECT v.vehicle_id AS option_id, v.vehicle_name AS option_name, v.is_active, COALESCE(used.product_count, 0) AS product_count FROM Vehicle_Compatibility v LEFT JOIN (SELECT vehicle_id, COUNT(*) AS product_count FROM Product_Vehicle GROUP BY vehicle_id) used ON used.vehicle_id = v.vehicle_id ORDER BY v.vehicle_name, v.vehicle_id')->fetchAll(PDO::FETCH_ASSOC);
$types = $pdo->query('SELECT t.type_id AS option_id, t.type_name AS option_name, 1 AS is_active, COALESCE(used.product_count, 0) AS product_count FROM Product_Type t LEFT JOIN (SELECT product_type, COUNT(*) AS product_count FROM Products GROUP BY product_type) used ON used.product_type = t.type_name ORDER BY t.type_name, t.type_id')->fetchAll(PDO::FETCH_ASSOC);
$sections = [
    ['kind' => 'vehicle', 'title' => 'Compatibility', 'description' => 'Manage motorcycle models', 'placeholder' => 'Motorcycle model', 'button' => '+ Add Model', 'max' => 100, 'options' => $compatibility],
    ['kind' => 'type', 'title' => 'Product Type', 'description' => 'Manage product types', 'placeholder' => 'Product type', 'button' => '+ Add Type', 'max' => 50, 'options' => $types],
];
$page_title = 'MANAGE PRODUCT OPTIONS';
$page_stylesheet = 'assets/product-options.css';
$page_body_class = 'product-options-page';
include '../includes/header.php';
?>
<a href="<?php echo h(app_url('pages/products.php')); ?>" class="mb-5 inline-block rounded border px-4 py-2 text-sm dark:border-gray-600">Back to Inventory</a>
<div class="grid gap-4 lg:grid-cols-2">
<?php foreach ($sections as $section): ?>
    <section aria-labelledby="<?php echo h($section['kind'] . '_heading'); ?>" class="min-w-0 rounded-lg border bg-white p-4 dark:bg-gray-900 dark:border-gray-600">
        <h2 id="<?php echo h($section['kind'] . '_heading'); ?>" class="text-lg font-semibold"><?php echo h($section['title']); ?></h2>
        <p class="mb-3 mt-1 text-sm text-gray-500 dark:text-gray-400"><?php echo h($section['description']); ?></p>
        <form method="POST" class="mb-4 space-y-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="option_kind" value="<?php echo h($section['kind']); ?>"><input type="hidden" name="action" value="add">
            <label for="<?php echo h($section['kind'] . '_new'); ?>" class="sr-only"><?php echo h($section['placeholder']); ?></label>
            <input id="<?php echo h($section['kind'] . '_new'); ?>" name="option_name" required maxlength="<?php echo $section['max']; ?>" placeholder="<?php echo h($section['placeholder']); ?>" class="w-full rounded-full border px-3 py-2 dark:bg-gray-900 dark:border-gray-600">
            <button class="w-full rounded-full border px-4 py-2 text-sm dark:border-gray-600"><?php echo h($section['button']); ?></button>
        </form>
        <ul class="max-h-96 space-y-1 overflow-auto">
        <?php if ($section['options'] === []): ?><li class="py-4 text-sm text-gray-500">No options yet. Add one above.</li><?php endif; ?>
        <?php foreach ($section['options'] as $option): ?>
            <li class="flex items-center justify-between gap-3 rounded px-1 py-2" data-option-kind="<?php echo h($section['kind']); ?>" data-option-id="<?php echo (int) $option['option_id']; ?>">
                <span class="min-w-0 break-words"><?php echo h($option['option_name']); ?><?php if (!$option['is_active']): ?><span class="ml-1 text-xs text-gray-500">(archived)</span><?php endif; ?><?php if ($option['product_count'] > 0): ?><span class="ml-1 text-xs text-gray-500">In use</span><?php endif; ?></span>
                <div class="flex shrink-0 items-center gap-2">
                    <?php if (!$option['is_active']): ?>
                        <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="option_kind" value="vehicle"><input type="hidden" name="option_id" value="<?php echo (int) $option['option_id']; ?>"><button name="action" value="restore" class="text-xs underline" aria-label="<?php echo h('Restore ' . $option['option_name']); ?>">Restore</button></form>
                    <?php endif; ?>
                    <button type="button" data-option-edit data-kind="<?php echo h($section['kind']); ?>" data-id="<?php echo (int) $option['option_id']; ?>" data-name="<?php echo h($option['option_name']); ?>" aria-label="<?php echo h('Edit ' . $option['option_name']); ?>" class="rounded p-2 hover:bg-gray-100 dark:hover:bg-gray-800"><svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m16 3 5 5-12 12-6 1 1-6Z"/><path d="m14 5 5 5"/></svg></button>
                    <button type="button" data-option-delete data-kind="<?php echo h($section['kind']); ?>" data-id="<?php echo (int) $option['option_id']; ?>" data-name="<?php echo h($option['option_name']); ?>" aria-label="<?php echo h('Delete ' . $option['option_name']); ?>" title="<?php echo $option['product_count'] > 0 ? 'Used by products; cannot delete' : 'Delete unused option'; ?>" <?php echo $option['product_count'] > 0 || !can_delete_catalog() ? 'disabled' : ''; ?> class="rounded p-2 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30"><svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg></button>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
</div>
<p class="mt-4 text-sm text-gray-500">Options used by products cannot be deleted. Editing a name also updates linked products, including archived products.</p>
<?php foreach (['edit', 'delete'] as $mode): ?>
<dialog id="<?php echo $mode; ?>OptionDialog" aria-labelledby="<?php echo $mode; ?>OptionTitle" class="w-full max-w-sm rounded-lg border bg-white p-5 text-black backdrop:bg-black/50 dark:bg-gray-900 dark:text-white dark:border-gray-600">
    <h2 id="<?php echo $mode; ?>OptionTitle" class="mb-4 text-lg font-semibold"><?php echo $mode === 'edit' ? 'Edit option' : 'Delete option'; ?></h2>
    <form method="POST">
        <?php echo csrf_field(); ?><input type="hidden" name="option_kind"><input type="hidden" name="option_id"><input type="hidden" name="action" value="<?php echo $mode === 'edit' ? 'update' : 'delete'; ?>">
        <?php if ($mode === 'edit'): ?><label>Name<input name="option_name" required class="mt-1 w-full rounded border px-3 py-2 dark:bg-gray-800"></label><?php else: ?><p data-delete-message class="break-words"></p><?php endif; ?>
        <div class="mt-4 flex justify-end gap-2"><button type="button" data-dialog-cancel class="rounded border px-4 py-2">Cancel</button><button class="rounded bg-black px-4 py-2 text-white dark:bg-white dark:text-black"><?php echo $mode === 'edit' ? 'Save Changes' : 'Delete'; ?></button></div>
    </form>
</dialog>
<?php endforeach; ?>
<script src="<?php echo h(app_url('assets/product-options.js')); ?>" defer></script>
<?php include '../includes/footer.php'; ?>
