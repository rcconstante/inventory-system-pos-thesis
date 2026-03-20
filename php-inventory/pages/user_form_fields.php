<?php
$formPrefix = $formPrefix ?? '';
?>
<div>
    <label class="mb-1 block text-sm font-medium dark:text-gray-200">Full Name</label>
    <input type="text" id="<?php echo h($formPrefix . 'full_name'); ?>" name="full_name" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
</div>
<div>
    <label class="mb-1 block text-sm font-medium dark:text-gray-200">Username</label>
    <input type="text" id="<?php echo h($formPrefix . 'username'); ?>" name="username" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
</div>
<div>
    <label class="mb-1 block text-sm font-medium dark:text-gray-200">Email</label>
    <input type="email" id="<?php echo h($formPrefix . 'email'); ?>" name="email" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
</div>
<div>
    <label class="mb-1 block text-sm font-medium dark:text-gray-200">Role</label>
    <select id="<?php echo h($formPrefix . 'role_id'); ?>" name="role_id" required class="w-full rounded border bg-white px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
        <?php foreach ($roles as $role): ?>
            <option value="<?php echo h((string) $role['role_id']); ?>"><?php echo h(ucfirst((string) $role['role_type'])); ?></option>
        <?php endforeach; ?>
    </select>
</div>
