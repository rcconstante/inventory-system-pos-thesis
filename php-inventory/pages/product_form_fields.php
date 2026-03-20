<?php
$formPrefix = $formPrefix ?? '';
?>
<div class="grid gap-4 md:grid-cols-2">
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Product Name</label>
        <input type="text" id="<?php echo h($formPrefix . 'product_name'); ?>" name="product_name" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
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
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Brand</label>
        <input type="text" id="<?php echo h($formPrefix . 'brand'); ?>" name="brand" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Price (P)</label>
        <input type="number" step="0.01" min="0" id="<?php echo h($formPrefix . 'price'); ?>" name="price" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Product Type</label>
        <input type="text" id="<?php echo h($formPrefix . 'product_type'); ?>" name="product_type" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Current Stock</label>
        <input type="number" min="0" id="<?php echo h($formPrefix . 'current_stock'); ?>" name="current_stock" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Min Stock Level</label>
        <input type="number" min="0" id="<?php echo h($formPrefix . 'min_stock_level'); ?>" name="min_stock_level" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Expiry Date (Optional)</label>
        <input type="date" id="<?php echo h($formPrefix . 'expiry_date'); ?>" name="expiry_date" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Compatibility</label>
        <input type="text" id="<?php echo h($formPrefix . 'compatibility'); ?>" name="compatibility" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
    </div>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Specification</label>
        <textarea id="<?php echo h($formPrefix . 'specification'); ?>" name="specification" rows="3" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"></textarea>
    </div>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium dark:text-gray-200">Description</label>
        <textarea id="<?php echo h($formPrefix . 'description'); ?>" name="description" rows="3" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"></textarea>
    </div>
</div>
