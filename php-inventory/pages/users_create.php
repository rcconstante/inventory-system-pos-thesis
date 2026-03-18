<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login([APP_ROLE_ADMIN]);

$roles = fetch_role_options($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('pages/users_create.php');

    try {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $roleId   = (int) ($_POST['role_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($fullName === '' || $username === '' || $roleId <= 0) {
            throw new RuntimeException('Please complete all required fields.');
        }

        if ($password !== $confirmPassword) {
            throw new RuntimeException('Passwords do not match.');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters long.');
        }

        $duplicateStatement = $pdo->prepare(
            'SELECT user_id FROM User WHERE username = :username LIMIT 1'
        );
        $duplicateStatement->execute([
            'username' => $username,
        ]);

        if ($duplicateStatement->fetch()) {
            throw new RuntimeException('That username is already assigned to another user.');
        }

        // Generate a fake email since it's required in DB but not in mockup
        $email = $username . '@example.com';

        $insertStatement = $pdo->prepare(
            'INSERT INTO User (full_name, username, email, password, role_id, display_name)
             VALUES (:full_name, :username, :email, :password, :role_id, :display_name)'
        );
        $insertStatement->execute([
            'full_name' => $fullName,
            'username'  => $username,
            'email'     => $email,
            'password'  => password_hash($password, PASSWORD_DEFAULT),
            'role_id'   => $roleId,
            'display_name' => $displayName !== '' ? $displayName : null,
        ]);

        set_flash('success', 'User account created successfully.');
        redirect_to('pages/users.php');
    } catch (RuntimeException $exception) {
        set_flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        set_flash('error', 'The user could not be created.');
    }
}

$page_title = 'Create User Account';
include '../includes/header.php';
?>

<div class="max-w-4xl mx-auto mt-6 bg-white border border-black rounded-lg p-8 shadow-sm">
    <h2 class="text-xl font-bold mb-6 border-b border-black pb-4">Create User Account</h2>
    
    <form method="POST" action="<?php echo h(app_url('pages/users_create.php')); ?>" class="space-y-6">
        <?php echo csrf_field(); ?>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Left Column -->
            <div class="space-y-6">
                <div>
                    <label class="block text-sm font-medium mb-2">Full Name</label>
                    <input type="text" name="full_name" required placeholder="JUAN DELA CRUZ"
                           class="w-full px-4 py-3 border border-black rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Display Name</label>
                    <input type="text" name="display_name" placeholder="JUAN"
                           class="w-full px-4 py-3 border border-black rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Password</label>
                    <input type="password" name="password" required placeholder="123456789"
                           class="w-full px-4 py-3 border border-black rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <!-- Right Column -->
            <div class="space-y-6">
                <div>
                    <label class="block text-sm font-medium mb-2">Username</label>
                    <input type="text" name="username" required placeholder="JUAN@15"
                           class="w-full px-4 py-3 border border-black rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Role</label>
                    <select name="role_id" required 
                            class="w-full px-4 py-3 border border-black rounded focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none bg-[url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23000%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22m6%209%206%206%206-6%22%2F%3E%3C%2Fsvg%3E')] bg-no-repeat bg-[position:right_1rem_center]">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo h((string) $role['role_id']); ?>">
                                <?php echo h(strtoupper($role['role_type'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Confirm Password</label>
                    <input type="password" name="confirm_password" required placeholder="123456789"
                           class="w-full px-4 py-3 border border-black rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-4 mt-8 pt-6 border-t border-transparent">
            <a href="<?php echo h(app_url('pages/users.php')); ?>" 
               class="px-8 py-2 border border-black rounded text-black font-medium hover:bg-gray-50 transition-colors">
                Close
            </a>
            <button type="submit" 
                    class="px-8 py-2 border border-black rounded text-black font-medium hover:bg-gray-50 transition-colors">
                Create
            </button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>
