<?php
$formPrefix = $formPrefix ?? '';
?>
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Category</label>
        <select id="<?php echo h($formPrefix . 'category_id'); ?>" name="category_id" required class="w-full rounded border bg-white px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            <option value="">Select Category</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?php echo h((string) $category['category_id']); ?>"><?php echo h($category['category_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Product Name</label>
        <input type="text" id="<?php echo h($formPrefix . 'product_name'); ?>" name="product_name" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Brand Name</label>
        <input type="text" id="<?php echo h($formPrefix . 'brand'); ?>" name="brand" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <?php $pickerKind = 'type'; include __DIR__ . '/../includes/catalog_picker_fields.php'; ?>
    </div>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Additional Details</label>
        <textarea id="<?php echo h($formPrefix . 'specification'); ?>" name="specification" rows="2" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"></textarea>
    </div>
    <div class="md:col-span-2">
        <fieldset class="rounded border p-3 dark:border-gray-600">
            <legend class="px-1 text-sm font-medium">Specifications</legend>
            <p class="mb-3 text-xs text-gray-500">Fill in the details a replacement must match. Leave other fields blank.</p>
            <div class="grid gap-3 md:grid-cols-2">
            <?php foreach (essential_fitment_fields() as $key => $label): ?>
                <label for="<?php echo h($formPrefix . 'fitment_' . $key); ?>" class="text-sm"><?php echo h($label); ?>
                <?php if ($key === 'position'): ?>
                    <select id="<?php echo h($formPrefix . 'fitment_' . $key); ?>" data-fitment-key="<?php echo h($key); ?>" name="fitment[<?php echo h($key); ?>]" class="mt-1 w-full rounded border p-2 dark:bg-gray-700"><option value="">Not specified</option><option value="front">Front</option><option value="rear">Rear</option><option value="front/rear">Front and rear</option></select>
                <?php else: ?>
                    <input id="<?php echo h($formPrefix . 'fitment_' . $key); ?>" data-fitment-key="<?php echo h($key); ?>" name="fitment[<?php echo h($key); ?>]" maxlength="100" placeholder="<?php echo h($key === 'size' ? '80/90-14' : ($key === 'voltage' ? '12V' : '')); ?>" class="mt-1 w-full rounded border p-2 dark:bg-gray-700">
                <?php endif; ?>
                </label>
            <?php endforeach; ?>
            </div>
        </fieldset>
    </div>
    <div class="md:col-span-2">
        <?php $pickerKind = 'vehicle'; include __DIR__ . '/../includes/catalog_picker_fields.php'; ?>
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Manufacturing Date</label>
        <input type="date" id="<?php echo h($formPrefix . 'manufacturing_date'); ?>" name="manufacturing_date" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Expiration Date</label>
        <input type="date" id="<?php echo h($formPrefix . 'expiry_date'); ?>" name="expiry_date" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Critical Stock (Reorder Level)</label>
        <input type="number" min="0" id="<?php echo h($formPrefix . 'min_stock_level'); ?>" name="min_stock_level" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Retail Price (&#8369;)</label>
        <input type="number" step="0.01" min="0" id="<?php echo h($formPrefix . 'retail_price'); ?>" name="retail_price" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Acquisition Cost (&#8369;)</label>
        <input type="number" step="0.01" min="0" id="<?php echo h($formPrefix . 'acquisition_cost'); ?>" name="acquisition_cost" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Selling Price (&#8369;)</label>
        <input type="number" step="0.01" min="0" id="<?php echo h($formPrefix . 'price'); ?>" name="price" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Quantity</label>
        <input type="number" min="0" id="<?php echo h($formPrefix . 'current_stock'); ?>" name="current_stock" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Description</label>
        <textarea id="<?php echo h($formPrefix . 'description'); ?>" name="description" rows="2" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"></textarea>
    </div>
</div>
