<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login([APP_ROLE_ADMIN]);

$roles = fetch_role_options($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('pages/users.php');

    try {
        if (isset($_POST['add_user']) || isset($_POST['edit_user'])) {
            $userId   = isset($_POST['edit_user']) ? (int) ($_POST['user_id'] ?? 0) : null;
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email    = trim((string) ($_POST['email'] ?? ''));
            $roleId   = (int) ($_POST['role_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            $isActive = isset($_POST['edit_user']) ? (isset($_POST['is_active']) ? 1 : 0) : 1;

            if ($fullName === '' || $username === '' || $email === '' || $roleId <= 0) {
                throw new RuntimeException('Please complete all required user fields.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }

            if ($userId === null) {
                $duplicateStatement = $pdo->prepare(
                    'SELECT user_id
                     FROM User
                     WHERE username = :username OR email = :email
                     LIMIT 1'
                );
                $duplicateStatement->execute([
                    'username' => $username,
                    'email'    => $email,
                ]);
            } else {
                $duplicateStatement = $pdo->prepare(
                    'SELECT user_id
                     FROM User
                     WHERE (username = :username OR email = :email) AND user_id <> :user_id
                     LIMIT 1'
                );
                $duplicateStatement->execute([
                    'username' => $username,
                    'email'    => $email,
                    'user_id'  => $userId,
                ]);
            }

            if ($duplicateStatement->fetch()) {
                throw new RuntimeException('That username or email address is already assigned to another user.');
            }

            if ($userId === null) {
                if (strlen($password) < 8) {
                    throw new RuntimeException('New user passwords must be at least 8 characters long.');
                }

                $insertStatement = $pdo->prepare(
                    'INSERT INTO User (full_name, username, email, password, role_id)
                     VALUES (:full_name, :username, :email, :password, :role_id)'
                );
                $insertStatement->execute([
                    'full_name' => $fullName,
                    'username'  => $username,
                    'email'     => $email,
                    'password'  => password_hash($password, PASSWORD_DEFAULT),
                    'role_id'   => $roleId,
                ]);

                set_flash('success', 'User account created successfully.');
            } else {
                if ($userId === (int) current_user_id() && $roleId !== APP_ROLE_ADMIN) {
                    throw new RuntimeException('You cannot remove your own admin access from this screen.');
                }

                $updateFields = [
                    'full_name = :full_name',
                    'username = :username',
                    'email = :email',
                    'role_id = :role_id',
                    'is_active = :is_active',
                ];
                $parameters = [
                    'full_name' => $fullName,
                    'username'  => $username,
                    'email'     => $email,
                    'role_id'   => $roleId,
                    'is_active' => $isActive,
                    'user_id'   => $userId,
                ];

                if ($password !== '') {
                    if (strlen($password) < 8) {
                        throw new RuntimeException('Updated passwords must be at least 8 characters long.');
                    }

                    $updateFields[]         = 'password = :password';
                    $parameters['password'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $updateStatement = $pdo->prepare(
                    'UPDATE User SET ' . implode(', ', $updateFields) . ' WHERE user_id = :user_id'
                );
                $updateStatement->execute($parameters);

                if ($userId === (int) current_user_id()) {
                    $updatedUser = fetch_user_by_id($pdo, $userId);
                    if ($updatedUser !== null) {
                        sync_user_session($updatedUser);
                    }
                }

                set_flash('success', 'User account updated successfully.');
            }
        } elseif (isset($_POST['delete_user'])) {
            $userId = (int) ($_POST['user_id'] ?? 0);

            if ($userId <= 0) {
                throw new RuntimeException('Invalid user selected.');
            }

            if ($userId === (int) current_user_id()) {
                throw new RuntimeException('You cannot delete your own account.');
            }

            $deleteStatement = $pdo->prepare('DELETE FROM User WHERE user_id = :user_id');
            $deleteStatement->execute(['user_id' => $userId]);

            set_flash('success', 'User account deleted successfully.');
        }
    } catch (RuntimeException $exception) {
        set_flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        set_flash('error', 'The user action could not be completed.');
    }

    redirect_to('pages/users.php');
}

$usersStatement = $pdo->query(
    'SELECT u.*, r.role_type
     FROM User u
     LEFT JOIN Role r ON u.role_id = r.role_id
     ORDER BY u.user_id ASC'
);
$users = $usersStatement->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'USERS';
include '../includes/header.php';
?>

<div class="mb-6 flex justify-end">
    <button type="button" onclick="toggleUserModal('addUserModal', true)" class="flex items-center gap-2 rounded-md border border-black px-4 py-2 text-sm font-medium hover:bg-gray-50">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></svg>
        <span>Add New User</span>
    </button>
</div>

<div class="overflow-hidden rounded-lg border border-black">
    <div class="grid grid-cols-8 gap-4 border-b border-black bg-white p-4 text-sm font-medium">
        <div>NO</div>
        <div>NAME</div>
        <div>USERNAME</div>
        <div>EMAIL</div>
        <div>ROLE</div>
        <div>DATE JOINED</div>
        <div>STATUS</div>
        <div>ACTION</div>
    </div>

    <?php if ($users === []): ?>
        <div class="p-4 text-center text-sm text-gray-500">No users found.</div>
    <?php else: ?>
        <?php foreach ($users as $user): ?>
            <?php
            $isActive = (bool) ($user['is_active'] ?? true);
            $createdAt = isset($user['created_at']) && $user['created_at'] !== null
                ? date('M d, Y', strtotime((string) $user['created_at']))
                : '—';
            ?>
            <div class="grid grid-cols-8 items-center gap-4 border-b border-black p-4 text-sm last:border-b-0 hover:bg-gray-50">
                <div><?php echo h((string) $user['user_id']); ?></div>
                <div class="truncate"><?php echo h($user['full_name']); ?></div>
                <div class="truncate"><?php echo h($user['username']); ?></div>
                <div class="truncate"><?php echo h($user['email']); ?></div>
                <div class="uppercase"><?php echo h($user['role_type']); ?></div>
                <div class="text-xs text-gray-600"><?php echo h($createdAt); ?></div>
                <div>
                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold <?php echo $isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                        <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Edit icon button -->
                    <button
                        type="button"
                        title="Edit user"
                        class="rounded p-1.5 text-blue-600 hover:bg-blue-50"
                        data-user-id="<?php echo h((string) $user['user_id']); ?>"
                        data-full-name="<?php echo h($user['full_name']); ?>"
                        data-username="<?php echo h($user['username']); ?>"
                        data-email="<?php echo h($user['email']); ?>"
                        data-role-id="<?php echo h((string) $user['role_id']); ?>"
                        data-is-active="<?php echo $isActive ? '1' : '0'; ?>"
                        onclick="openEditUserModal(this)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </button>
                    <!-- Delete icon button -->
                    <?php if ((int) $user['user_id'] !== (int) current_user_id()): ?>
                        <button
                            type="button"
                            title="Delete user"
                            class="rounded p-1.5 text-red-600 hover:bg-red-50"
                            data-user-id="<?php echo h((string) $user['user_id']); ?>"
                            data-full-name="<?php echo h($user['full_name']); ?>"
                            onclick="openDeleteUserModal(this)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
    <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
        <div class="mb-4 flex items-center justify-between border-b pb-3">
            <h2 class="text-xl font-bold">Add New User</h2>
            <button type="button" onclick="toggleUserModal('addUserModal', false)" class="text-gray-500 hover:text-black">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="<?php echo h(app_url('pages/users.php')); ?>" class="space-y-4">
            <?php echo csrf_field(); ?>
            <?php $formPrefix = ''; include __DIR__ . '/user_form_fields.php'; ?>
            <div>
                <label class="mb-1 block text-sm font-medium">Password</label>
                <input type="password" name="password" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
            </div>
            <div class="flex justify-end gap-2 border-t pt-4">
                <button type="button" onclick="toggleUserModal('addUserModal', false)" class="rounded border px-4 py-2 hover:bg-gray-50">Cancel</button>
                <button type="submit" name="add_user" class="rounded bg-black px-4 py-2 text-white hover:bg-gray-800">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
    <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
        <div class="mb-4 flex items-center justify-between border-b pb-3">
            <h2 class="text-xl font-bold">Edit User</h2>
            <button type="button" onclick="toggleUserModal('editUserModal', false)" class="text-gray-500 hover:text-black">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="<?php echo h(app_url('pages/users.php')); ?>" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="user_id" id="edit_user_id">
            <?php $formPrefix = 'edit_'; include __DIR__ . '/user_form_fields.php'; ?>
            <div>
                <label class="mb-1 block text-sm font-medium">New Password</label>
                <input type="password" name="password" class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500" placeholder="Leave blank to keep current password">
            </div>
            <div class="flex items-center gap-3 rounded border px-3 py-2">
                <input type="checkbox" name="is_active" id="edit_is_active" value="1" class="h-4 w-4 rounded border-gray-300">
                <label for="edit_is_active" class="text-sm font-medium cursor-pointer select-none">Account is Active</label>
            </div>
            <div class="flex justify-end gap-2 border-t pt-4">
                <button type="button" onclick="toggleUserModal('editUserModal', false)" class="rounded border px-4 py-2 hover:bg-gray-50">Cancel</button>
                <button type="submit" name="edit_user" class="rounded bg-black px-4 py-2 text-white hover:bg-gray-800">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteUserModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
        <div class="mb-4 flex items-start gap-3">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-red-100">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-gray-900">Delete User</h3>
                <p class="mt-1 text-sm text-gray-500">Are you sure you want to delete <strong id="delete-user-name"></strong>? This action cannot be undone.</p>
            </div>
        </div>

        <form method="POST" action="<?php echo h(app_url('pages/users.php')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="user_id" id="delete_user_id">
            <div class="flex justify-end gap-2 border-t pt-4">
                <button type="button" onclick="toggleUserModal('deleteUserModal', false)" class="rounded border px-4 py-2 text-sm hover:bg-gray-50">Cancel</button>
                <button type="submit" name="delete_user" class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleUserModal(modalId, shouldOpen) {
        var modal = document.getElementById(modalId);
        if (!modal) return;
        if (shouldOpen) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        } else {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function openEditUserModal(button) {
        document.getElementById('edit_user_id').value    = button.dataset.userId    || '';
        document.getElementById('edit_full_name').value  = button.dataset.fullName  || '';
        document.getElementById('edit_username').value   = button.dataset.username  || '';
        document.getElementById('edit_email').value      = button.dataset.email     || '';
        document.getElementById('edit_role_id').value    = button.dataset.roleId    || '';
        document.getElementById('edit_is_active').checked = button.dataset.isActive === '1';
        toggleUserModal('editUserModal', true);
    }

    function openDeleteUserModal(button) {
        document.getElementById('delete_user_id').value  = button.dataset.userId   || '';
        document.getElementById('delete-user-name').textContent = button.dataset.fullName || 'this user';
        toggleUserModal('deleteUserModal', true);
    }

    // Close modals on backdrop click
    ['addUserModal', 'editUserModal', 'deleteUserModal'].forEach(function (id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.addEventListener('click', function (e) {
            if (e.target === modal) toggleUserModal(id, false);
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
