<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login();

$canManage = can_manage_catalog();
$canDelete = can_delete_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        set_flash('error', 'You do not have permission to manage categories.');
        redirect_to('pages/category.php');
    }

    validate_csrf_or_fail('pages/category.php');

    try {
        if (isset($_POST['add_category'])) {
            $name = trim((string) ($_POST['category_name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Category name is required.');
            }

            $duplicateStatement = $pdo->prepare('SELECT category_id FROM Category WHERE LOWER(category_name) = LOWER(:name) LIMIT 1');
            $duplicateStatement->execute(['name' => $name]);
            if ($duplicateStatement->fetch()) {
                throw new RuntimeException('That category already exists.');
            }

            $insertStatement = $pdo->prepare('INSERT INTO Category (category_name) VALUES (:name)');
            $insertStatement->execute(['name' => $name]);

            set_flash('success', 'Category created successfully.');
        } elseif (isset($_POST['delete_category'])) {
            if (!$canDelete) {
                throw new RuntimeException('You do not have permission to delete categories.');
            }
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            if ($categoryId <= 0) {
                throw new RuntimeException('Invalid category selected.');
            }

            $deleteStatement = $pdo->prepare('DELETE FROM Category WHERE category_id = :id');
            $deleteStatement->execute(['id' => $categoryId]);

            set_flash('success', 'Category deleted successfully.');
        } elseif (isset($_POST['edit_category'])) {
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $name = trim((string) ($_POST['category_name'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($categoryId <= 0 || $name === '') {
                throw new RuntimeException('A valid category and name are required.');
            }

            $duplicateStatement = $pdo->prepare(
                'SELECT category_id
                 FROM Category
                 WHERE LOWER(category_name) = LOWER(:name) AND category_id <> :id
                 LIMIT 1'
            );
            $duplicateStatement->execute([
                'name' => $name,
                'id' => $categoryId,
            ]);

            if ($duplicateStatement->fetch()) {
                throw new RuntimeException('That category name is already in use.');
            }

            $updateStatement = $pdo->prepare('UPDATE Category SET category_name = :name, is_active = :is_active WHERE category_id = :id');
            $updateStatement->execute([
                'name' => $name,
                'is_active' => $isActive,
                'id' => $categoryId,
            ]);

            set_flash('success', 'Category updated successfully.');
        }
    } catch (RuntimeException $exception) {
        set_flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        set_flash('error', 'The category action could not be completed.');
    }

    redirect_to('pages/category.php');
}

$categoriesStatement = $pdo->query(
    'SELECT c.*, COALESCE(pc.product_count, 0) AS product_count
     FROM Category c
     LEFT JOIN (
         SELECT category_id, COUNT(*) AS product_count FROM Products GROUP BY category_id
     ) pc ON pc.category_id = c.category_id
     ORDER BY c.category_id DESC'
);
$categories = $categoriesStatement->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'CATEGORY';
include '../includes/header.php';
?>

<?php if ($canManage): ?>
    <div class="mb-6 flex justify-end">
        <button type="button" onclick="toggleCategoryModal('addModal', true)" class="flex items-center gap-2 rounded-md border border-black px-4 py-2 text-sm font-medium hover:bg-gray-50">
            <span>+ Add New Category</span>
        </button>
    </div>
<?php endif; ?>

<div class="overflow-hidden rounded-lg border border-black">
    <div class="grid grid-cols-5 gap-4 border-b border-black bg-white p-4 text-sm font-medium">
        <div>NO.</div>
        <div>CATEGORY NAME</div>
        <div>CREATED AT</div>
        <div>STATUS</div>
        <div><?php echo $canManage ? 'ACTION' : 'DETAILS'; ?></div>
    </div>

    <?php if ($categories === []): ?>
        <div class="p-4 text-center text-sm text-gray-500">No categories found.</div>
    <?php else: ?>
        <?php foreach ($categories as $category): ?>
            <?php $catIsActive = (bool) ($category['is_active'] ?? true); ?>
            <div class="grid grid-cols-5 items-center gap-4 border-b border-black p-4 text-sm last:border-b-0 hover:bg-gray-50">
                <div><?php echo h(str_pad((string) $category['category_id'], 3, '0', STR_PAD_LEFT)); ?></div>
                <div><?php echo h($category['category_name']); ?></div>
                <div><?php echo h(date('F d, Y h:i A', strtotime((string) $category['created_at']))); ?></div>
                <div>
                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold <?php echo $catIsActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                        <?php echo $catIsActive ? 'Instock' : 'No stock'; ?>
                    </span>
                </div>
                <div class="flex gap-2">
                    <?php if ($canManage): ?>
                        <button type="button"
                            data-category-id="<?php echo h((string) $category['category_id']); ?>"
                            data-category-name="<?php echo h($category['category_name']); ?>"
                            data-is-active="<?php echo $catIsActive ? '1' : '0'; ?>"
                            onclick="openEditCategoryModal(this)"
                            title="Edit category"
                            class="rounded bg-blue-50 p-1.5 text-blue-700 hover:bg-blue-100">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                        </button>
                        <?php if ($canDelete): ?>
                        <button type="button"
                            data-category-id="<?php echo h((string) $category['category_id']); ?>"
                            data-category-name="<?php echo h($category['category_name']); ?>"
                            data-product-count="<?php echo h((string) $category['product_count']); ?>"
                            onclick="openDeleteCategoryModal(this)"
                            title="Delete category"
                            class="rounded bg-red-50 p-1.5 text-red-700 hover:bg-red-100">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-xs uppercase tracking-wide text-gray-500">View Only</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
    <div id="addModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 class="mb-4 text-xl font-bold">Add New Category</h2>
            <form method="POST" action="<?php echo h(app_url('pages/category.php')); ?>">
                <?php echo csrf_field(); ?>
                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Category Name</label>
                    <input type="text" name="category_name" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="toggleCategoryModal('addModal', false)" class="rounded border px-4 py-2 hover:bg-gray-50">Cancel</button>
                    <button type="submit" name="add_category" class="rounded bg-black px-4 py-2 text-white hover:bg-gray-800">Add Category</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 class="mb-4 text-xl font-bold">Edit Category</h2>
            <form method="POST" action="<?php echo h(app_url('pages/category.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Category Name</label>
                    <input type="text" name="category_name" id="edit_category_name" required class="w-full rounded border px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
                </div>
                <div class="mb-4 flex items-center gap-3 rounded border px-3 py-2">
                    <input type="checkbox" name="is_active" id="edit_category_is_active" value="1" class="h-4 w-4 rounded border-gray-300">
                    <label for="edit_category_is_active" class="text-sm font-medium cursor-pointer select-none">Category is Instock</label>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="toggleCategoryModal('editModal', false)" class="rounded border px-4 py-2 hover:bg-gray-50">Cancel</button>
                    <button type="submit" name="edit_category" class="rounded bg-black px-4 py-2 text-white hover:bg-gray-800">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php if ($canDelete): ?>
    <div id="deleteModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <div class="mb-4 flex items-start gap-3">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-red-100">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-900">Delete Category</h3>
                    <p class="mt-1 text-sm text-gray-500">Are you sure you want to delete <strong id="deleteCategoryName"></strong>? This action cannot be undone.</p>
                    <p id="deleteCategoryWarning" class="mt-2 hidden text-sm font-medium text-amber-700 bg-amber-50 rounded px-2 py-1"></p>
                </div>
            </div>
            <form method="POST" action="<?php echo h(app_url('pages/category.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="category_id" id="deleteCategoryId">
                <div class="flex justify-end gap-2 border-t pt-4">
                    <button type="button" onclick="toggleCategoryModal('deleteModal', false)" class="rounded border px-4 py-2 text-sm hover:bg-gray-50">Cancel</button>
                    <button type="submit" name="delete_category" class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">Delete</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    function toggleCategoryModal(modalId, shouldOpen) {
        var modal = document.getElementById(modalId);
        if (!modal) {
            return;
        }

        if (shouldOpen) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openEditCategoryModal(button) {
        document.getElementById('edit_category_id').value = button.dataset.categoryId || '';
        document.getElementById('edit_category_name').value = button.dataset.categoryName || '';
        document.getElementById('edit_category_is_active').checked = button.dataset.isActive === '1';
        toggleCategoryModal('editModal', true);
    }

    function openDeleteCategoryModal(button) {
        document.getElementById('deleteCategoryId').value = button.dataset.categoryId || '';
        document.getElementById('deleteCategoryName').textContent = button.dataset.categoryName || '';
        var count = parseInt(button.dataset.productCount || '0', 10);
        var warning = document.getElementById('deleteCategoryWarning');
        if (count > 0) {
            warning.textContent = count + ' product' + (count === 1 ? '' : 's') + ' in this category will become uncategorized.';
            warning.classList.remove('hidden');
        } else {
            warning.classList.add('hidden');
        }
        toggleCategoryModal('deleteModal', true);
    }
</script>

<?php include '../includes/footer.php'; ?>
