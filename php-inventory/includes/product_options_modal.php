<?php
declare(strict_types=1);
$compatibility = $pdo->query('SELECT v.vehicle_id AS option_id, v.vehicle_name AS option_name, v.is_active, COALESCE(used.product_count, 0) AS product_count FROM Vehicle_Compatibility v LEFT JOIN (SELECT vehicle_id, COUNT(*) AS product_count FROM Product_Vehicle GROUP BY vehicle_id) used ON used.vehicle_id = v.vehicle_id ORDER BY v.vehicle_name, v.vehicle_id')->fetchAll(PDO::FETCH_ASSOC);
$types = $pdo->query('SELECT t.type_id AS option_id, t.type_name AS option_name, 1 AS is_active, COALESCE(used.product_count, 0) AS product_count FROM Product_Type t LEFT JOIN (SELECT product_type, COUNT(*) AS product_count FROM Products GROUP BY product_type) used ON used.product_type = t.type_name ORDER BY t.type_name, t.type_id')->fetchAll(PDO::FETCH_ASSOC);
$sections = [
    ['kind' => 'vehicle', 'title' => 'Compatibility', 'description' => 'Manage motorcycle models', 'placeholder' => 'Motorcycle model', 'button' => '+ Add Model', 'max' => 100, 'options' => $compatibility],
    ['kind' => 'type', 'title' => 'Product Type', 'description' => 'Manage product types', 'placeholder' => 'Product type', 'button' => '+ Add Type', 'max' => 50, 'options' => $types],
];
$openOptions = ($_GET['open_options'] ?? '') === '1';
$optionNotice = $openOptions ? ($_SESSION['product_options_notice'] ?? null) : null;
$optionDraft = $openOptions ? ($_SESSION['product_options_draft'] ?? null) : null;
if ($openOptions) unset($_SESSION['product_options_notice'], $_SESSION['product_options_draft']);
?>
<dialog data-draft="<?php echo h(json_encode($optionDraft, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)); ?>" id="productOptionsDialog" aria-labelledby="productOptionsTitle" data-auto-open="<?php echo ($_GET['open_options'] ?? '') === '1' ? '1' : '0'; ?>" class="catalog-dialog w-full max-w-3xl rounded-lg bg-white p-0 text-black shadow-xl backdrop:bg-black/50 dark:bg-gray-800 dark:text-gray-100">
    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-6 py-4 dark:border-gray-600">
        <h2 id="productOptionsTitle" class="text-lg font-bold">Manage Product Options</h2>
        <button type="button" data-dialog-cancel class="rounded border border-black px-4 py-2 text-sm hover:bg-gray-50 dark:border-gray-500 dark:hover:bg-gray-700" aria-label="Close product options">Close</button>
    </div>
    <div class="p-4 sm:p-6">
    <?php if (is_array($optionNotice)): ?>
        <p role="status" class="mb-4 rounded border p-3 text-sm <?php echo $optionNotice['type'] === 'error' ? 'border-red-400 text-red-700 dark:text-red-300' : 'border-green-400 text-green-700 dark:text-green-300'; ?>"><?php echo h($optionNotice['message']); ?></p>
    <?php endif; ?>
<div class="grid gap-4 sm:grid-cols-2">
<?php foreach ($sections as $section): ?>
    <section aria-labelledby="<?php echo h($section['kind'] . '_heading'); ?>" class="min-w-0 rounded-lg border bg-white p-4 dark:bg-gray-800 dark:border-gray-600">
        <h2 id="<?php echo h($section['kind'] . '_heading'); ?>" class="text-lg font-semibold"><?php echo h($section['title']); ?></h2>
        <p class="mb-3 mt-1 text-sm text-gray-500 dark:text-gray-400"><?php echo h($section['description']); ?></p>
        <form method="POST" action="<?php echo h(app_url('pages/product_options.php')); ?>" data-option-form class="mb-4 space-y-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="return_search" value="<?php echo h($searchTerm); ?>"><input type="hidden" name="return_category_id" value="<?php echo $selectedCategoryId; ?>"><input type="hidden" name="return_page" value="<?php echo $paginationPage; ?>">
            <input type="hidden" name="option_kind" value="<?php echo h($section['kind']); ?>"><input type="hidden" name="action" value="add">
            <label for="<?php echo h($section['kind'] . '_new'); ?>" class="sr-only"><?php echo h($section['placeholder']); ?></label>
            <input id="<?php echo h($section['kind'] . '_new'); ?>" name="option_name" required maxlength="<?php echo $section['max']; ?>" placeholder="<?php echo h($section['placeholder']); ?>" class="w-full rounded border px-3 py-2 dark:bg-gray-800 dark:border-gray-600">
            <button class="w-full rounded border px-4 py-2 text-sm dark:border-gray-600"><?php echo h($section['button']); ?></button>
        </form>
        <ul class="max-h-64 space-y-1 overflow-auto">
        <?php if ($section['options'] === []): ?><li class="py-4 text-sm text-gray-500">No options yet. Add one above.</li><?php endif; ?>
        <?php foreach ($section['options'] as $option): ?>
            <li class="flex items-center justify-between gap-3 rounded px-1 py-2" data-option-kind="<?php echo h($section['kind']); ?>" data-option-id="<?php echo (int) $option['option_id']; ?>">
                <span class="min-w-0 break-words"><?php echo h($option['option_name']); ?><?php if (!$option['is_active']): ?><span class="ml-1 text-xs text-gray-500">(archived)</span><?php endif; ?><?php if ($option['product_count'] > 0): ?><span class="ml-1 text-xs text-gray-500">In use</span><?php endif; ?></span>
                <div class="flex shrink-0 items-center gap-2">
                    <?php if (!$option['is_active']): ?>
                        <form method="POST" action="<?php echo h(app_url('pages/product_options.php')); ?>" data-option-form><?php echo csrf_field(); ?>
            <input type="hidden" name="return_search" value="<?php echo h($searchTerm); ?>"><input type="hidden" name="return_category_id" value="<?php echo $selectedCategoryId; ?>"><input type="hidden" name="return_page" value="<?php echo $paginationPage; ?>"><input type="hidden" name="option_kind" value="vehicle"><input type="hidden" name="option_id" value="<?php echo (int) $option['option_id']; ?>"><input type="hidden" name="action" value="restore"><button class="text-xs underline" aria-label="<?php echo h('Restore ' . $option['option_name']); ?>">Restore</button></form>
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
    </div>
</dialog>
<?php foreach (['edit', 'delete'] as $mode): ?>
<dialog id="<?php echo $mode; ?>OptionDialog" aria-labelledby="<?php echo $mode; ?>OptionTitle" class="catalog-dialog w-full max-w-sm rounded-lg border bg-white p-5 text-black backdrop:bg-black/50 dark:bg-gray-800 dark:text-white dark:border-gray-600">
    <h2 id="<?php echo $mode; ?>OptionTitle" class="mb-4 text-lg font-semibold"><?php echo $mode === 'edit' ? 'Edit option' : 'Delete option'; ?></h2>
    <form method="POST" action="<?php echo h(app_url('pages/product_options.php')); ?>" data-option-form>
        <?php echo csrf_field(); ?>
            <input type="hidden" name="return_search" value="<?php echo h($searchTerm); ?>"><input type="hidden" name="return_category_id" value="<?php echo $selectedCategoryId; ?>"><input type="hidden" name="return_page" value="<?php echo $paginationPage; ?>"><input type="hidden" name="option_kind"><input type="hidden" name="option_id"><input type="hidden" name="action" value="<?php echo $mode === 'edit' ? 'update' : 'delete'; ?>">
        <?php if ($mode === 'edit'): ?><label>Name<input name="option_name" required class="mt-1 w-full rounded border px-3 py-2 dark:bg-gray-800"></label><?php else: ?><p data-delete-message class="break-words"></p><?php endif; ?>
        <div class="mt-4 flex justify-end gap-2"><button type="button" data-dialog-cancel class="rounded border px-4 py-2">Cancel</button><button class="rounded bg-black px-4 py-2 text-white dark:bg-white dark:text-black"><?php echo $mode === 'edit' ? 'Save Changes' : 'Delete'; ?></button></div>
    </form>
</dialog>
<?php endforeach; ?>
