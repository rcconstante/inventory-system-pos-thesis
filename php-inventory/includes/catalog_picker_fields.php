<?php
$isVehiclePicker = $pickerKind === 'vehicle';
$pickerField = $isVehiclePicker ? 'vehicle_ids' : 'product_type';
$pickerId = $formPrefix . $pickerField;
$pickerLabel = $isVehiclePicker ? 'Vehicle Compatibility (select multiple)' : 'Product Type (select one)';
?>
<div data-catalog-picker="<?php echo h($pickerKind); ?>" data-create-url="<?php echo h(app_url($isVehiclePicker ? 'pages/vehicle_create.php' : 'pages/product_type_create.php')); ?>">
    <label id="<?php echo h($pickerId . '_label'); ?>" for="<?php echo h($pickerId); ?>" class="mb-1 block text-sm font-medium"><?php echo h($pickerLabel); ?></label>
    <select id="<?php echo h($pickerId); ?>" name="<?php echo $isVehiclePicker ? 'vehicle_ids[]' : 'product_type'; ?>" <?php echo $isVehiclePicker ? 'multiple size="5"' : ''; ?> class="w-full rounded border bg-white px-3 py-2 dark:bg-gray-700">
        <?php if ($isVehiclePicker): ?>
            <?php foreach ($vehicleOptions as $vehicle): ?>
                <option value="<?php echo (int) $vehicle['vehicle_id']; ?>" data-active="<?php echo $vehicle['is_active'] ? '1' : '0'; ?>" data-name="<?php echo h($vehicle['vehicle_name']); ?>" data-search="<?php echo h($vehicle['search_names']); ?>" <?php echo $vehicle['is_active'] ? '' : 'disabled'; ?>><?php echo h($vehicle['vehicle_name'] . ($vehicle['is_active'] ? '' : ' (archived; existing assignments only)')); ?></option>
            <?php endforeach; ?>
        <?php else: ?>
            <option value="" data-name="Not specified">Not specified</option>
            <?php foreach ($productTypeOptions as $type): ?>
                <option value="<?php echo h($type['type_name']); ?>" data-name="<?php echo h($type['type_name']); ?>"><?php echo h($type['type_name']); ?></option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <div data-picker-controls hidden>
        <button type="button" data-picker-toggle aria-expanded="false" aria-haspopup="dialog" aria-controls="<?php echo h($pickerId . '_panel'); ?>" aria-labelledby="<?php echo h($pickerId . '_label ' . $pickerId . '_summary'); ?>" class="flex w-full items-center justify-between gap-2 rounded border px-3 py-3 text-left dark:bg-gray-900 dark:border-gray-600">
            <span id="<?php echo h($pickerId . '_summary'); ?>" data-picker-summary class="min-w-0 truncate"></span><span aria-hidden="true">⌄</span>
        </button>
        <div id="<?php echo h($pickerId . '_panel'); ?>" data-picker-panel role="dialog" aria-labelledby="<?php echo h($pickerId . '_label'); ?>" hidden class="mt-2 rounded border bg-white p-3 dark:bg-gray-900 dark:border-gray-600">
            <label for="<?php echo h($pickerId . '_search'); ?>" class="sr-only"><?php echo $isVehiclePicker ? 'Search motorcycle model' : 'Search product type'; ?></label>
            <input type="search" id="<?php echo h($pickerId . '_search'); ?>" data-picker-search placeholder="<?php echo $isVehiclePicker ? 'Search motorcycle model...' : 'Search product type...'; ?>" autocomplete="off" class="mb-3 w-full rounded-full border px-3 py-2 dark:bg-gray-900 dark:border-gray-600">
            <div data-picker-list class="max-h-48 overflow-auto" role="group" aria-label="<?php echo h($pickerLabel); ?>"></div>
            <div class="mt-3 border-t pt-3 dark:border-gray-700">
                <label for="<?php echo h($pickerId . '_new'); ?>" class="mb-2 block text-sm font-medium"><?php echo $isVehiclePicker ? 'Add New Motorcycle Model' : 'Add New Product Type'; ?></label>
                <div class="flex gap-2">
                    <input id="<?php echo h($pickerId . '_new'); ?>" data-picker-new maxlength="<?php echo $isVehiclePicker ? '100' : '50'; ?>" placeholder="<?php echo $isVehiclePicker ? 'e.g. Yamaha Aerox 155' : 'e.g. Oil Filter'; ?>" class="min-w-0 flex-1 rounded-full border px-3 py-2 dark:bg-gray-900 dark:border-gray-600">
                    <button type="button" data-picker-create disabled class="rounded-full border px-4 py-2 text-sm disabled:opacity-50">Add</button>
                </div>
                <p data-picker-status role="status" aria-live="polite" class="mt-2 text-sm"></p>
            </div>
            <?php if ($isVehiclePicker): ?>
                <div class="mt-3 flex items-center justify-between gap-3 border-t pt-3 dark:border-gray-700">
                    <span data-picker-count role="status" aria-live="polite" class="text-sm">0 selected</span>
                    <div class="flex gap-2"><button type="button" data-picker-cancel class="rounded-full border px-4 py-2 text-sm">Cancel</button><button type="button" data-picker-apply class="rounded-full bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">Apply</button></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($isVehiclePicker): ?><p class="mt-1 text-xs text-gray-500">Choose vehicles, then Apply. Universal must be selected alone. New models are saved for future products even if the selection is canceled.</p><?php endif; ?>
</div>
