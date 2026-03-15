<?php
$formPrefix = $formPrefix ?? '';
?>
<div>
    <label class="mb-1 block text-sm font-medium">Full Name</label>
    <input type="text" id="<?php echo h($formPrefix . 'full_name'); ?>" name="full_name" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Username</label>
    <input type="text" id="<?php echo h($formPrefix . 'username'); ?>" name="username" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Email</label>
    <input type="email" id="<?php echo h($formPrefix . 'email'); ?>" name="email" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Role</label>
    <select id="<?php echo h($formPrefix . 'role_id'); ?>" name="role_id" required class="w-full rounded border bg-white px-3 py-2">
        <?php foreach ($roles as $role): ?>
            <option value="<?php echo h((string) $role['role_id']); ?>"><?php echo h(ucfirst((string) $role['role_type'])); ?></option>
        <?php endforeach; ?>
    </select>
</div>
