<?php
declare(strict_types=1);
require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';
require_login([APP_ROLE_ADMIN, APP_ROLE_STAFF]);
ensure_product_lifecycle_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('pages/compatibility.php');
    try {
        if (!can_manage_catalog()) throw new RuntimeException('You cannot manage compatibility records.');
        $id = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pdo->beginTransaction();
        if (isset($_POST['save_vehicle'])) {
            if (isset($_POST['vehicle_id']) && !$id) throw new RuntimeException('Invalid vehicle record.');
            save_vehicle_option($pdo, $id ?: null, (string) ($_POST['vehicle_name'] ?? ''), (string) ($_POST['aliases'] ?? ''));
        } elseif (isset($_POST['merge_vehicle'])) {
            if (!can_delete_catalog()) throw new RuntimeException('You cannot merge vehicle records.');
            $target = filter_var($_POST['merge_target'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || !$target) throw new RuntimeException('Choose a source and destination vehicle.');
            merge_vehicle_options($pdo, $id, $target);
        } elseif (isset($_POST['archive_vehicle']) || isset($_POST['restore_vehicle'])) {
            if (!can_delete_catalog()) throw new RuntimeException('You cannot archive or restore vehicles.');
            if (!$id) throw new RuntimeException('Invalid vehicle record.');
            $statement = $pdo->prepare('SELECT vehicle_id FROM Vehicle_Compatibility WHERE vehicle_id = ? FOR UPDATE');
            $statement->execute([$id]);
            if (!$statement->fetchColumn()) throw new RuntimeException('Vehicle not found.');
            $pdo->prepare('UPDATE Vehicle_Compatibility SET is_active = ? WHERE vehicle_id = ?')->execute([isset($_POST['restore_vehicle']) ? 1 : 0, $id]);
        } else {
            throw new RuntimeException('Invalid compatibility action.');
        }
        sync_feature_matches_for_catalog($pdo);
        $pdo->commit();
        set_flash('success', 'Compatibility updated. Linked products and recommendations are up to date.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($exception instanceof RuntimeException && !$exception instanceof PDOException) {
            set_flash('error', $exception->getMessage());
        } else {
            error_log('Compatibility update failed: ' . $exception->getMessage());
            set_flash('error', 'Compatibility could not be saved. Check for duplicate names or aliases and try again.');
        }
    }
    redirect_to('pages/compatibility.php');
}
$vehicles = fetch_vehicle_options($pdo);
$aliases = [];
foreach ($pdo->query('SELECT alias_key, vehicle_id FROM Vehicle_Alias ORDER BY alias_key')->fetchAll(PDO::FETCH_ASSOC) as $alias) {
    $aliases[(int) $alias['vehicle_id']][] = $alias['alias_key'];
}
$page_title = 'VEHICLE COMPATIBILITY';
include '../includes/header.php';
?>
<a class="underline text-sm" href="<?php echo h(app_url('pages/products.php')); ?>">Back to Inventory</a>
<p class="my-4 text-sm">Use one record per vehicle model. Aliases map known spelling variants to the same vehicle. Archiving prevents new assignments and preserves existing product references. Merge a duplicate or misspelled record into the correct vehicle to move its product assignments and aliases, then remove the duplicate. Correct a product's selections in Inventory.</p>
<form method="POST" class="mb-6 grid gap-3 rounded border p-4 md:grid-cols-3 dark:border-gray-600">
    <?php echo csrf_field(); ?>
    <label>Vehicle name<input name="vehicle_name" required maxlength="100" class="mt-1 w-full rounded border p-2 dark:bg-gray-800" placeholder="Honda Click 125"></label>
    <label>Aliases (one per line)<textarea name="aliases" rows="2" maxlength="4000" class="mt-1 w-full rounded border p-2 dark:bg-gray-800" placeholder="Honda Click125"></textarea></label>
    <div class="self-end"><button name="save_vehicle" class="rounded border px-4 py-2">Add Vehicle</button></div>
</form>
<?php if ($vehicles === []): ?>
    <p class="py-6 text-center text-gray-500">No vehicles yet. Add a vehicle or Universal to begin.</p>
<?php endif; ?>
<div class="space-y-4">
<?php foreach ($vehicles as $vehicle): ?>
    <?php $id = (int) $vehicle['vehicle_id']; $vehicleAliases = array_diff($aliases[$id] ?? [], [vehicle_name_key($vehicle['vehicle_name'])]); ?>
    <details class="rounded border p-4 dark:border-gray-600">
        <summary class="cursor-pointer font-medium"><?php echo h($vehicle['vehicle_name']); ?><?php echo $vehicle['is_active'] ? '' : ' (archived)'; ?></summary>
        <form method="POST" class="mt-4 grid gap-3 md:grid-cols-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="vehicle_id" value="<?php echo $id; ?>">
            <label>Vehicle name<input name="vehicle_name" required maxlength="100" value="<?php echo h($vehicle['vehicle_name']); ?>" class="mt-1 w-full rounded border p-2 dark:bg-gray-800"></label>
            <label>Aliases (one per line)<textarea name="aliases" maxlength="4000" rows="3" class="mt-1 w-full rounded border p-2 dark:bg-gray-800"><?php echo h(implode("\n", $vehicleAliases)); ?></textarea></label>
            <div class="flex flex-wrap items-end gap-2">
                <button name="save_vehicle" class="rounded border px-4 py-2">Save Changes</button>
                <?php if (can_delete_catalog()): ?>
                    <button name="<?php echo $vehicle['is_active'] ? 'archive_vehicle' : 'restore_vehicle'; ?>" class="rounded border px-4 py-2"><?php echo $vehicle['is_active'] ? 'Archive' : 'Restore'; ?></button>
                <?php endif; ?>
            </div>
        </form>
        <?php if (can_delete_catalog()): ?>
        <form method="POST" class="mt-4 flex flex-wrap items-end gap-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="vehicle_id" value="<?php echo $id; ?>">
            <label>Merge this record into<select name="merge_target" required class="ml-2 rounded border p-2 dark:bg-gray-800"><option value="">Select correct vehicle</option>
                <?php foreach ($vehicles as $target): ?>
                    <?php if ((int) $target['vehicle_id'] === $id || !$target['is_active']) continue; ?>
                    <option value="<?php echo (int) $target['vehicle_id']; ?>"><?php echo h($target['vehicle_name']); ?></option>
                <?php endforeach; ?>
            </select></label>
            <button name="merge_vehicle" class="rounded border px-4 py-2">Move Assignments and Remove Duplicate</button>
        </form>
        <?php endif; ?>
    </details>
<?php endforeach; ?>
</div>
<?php include '../includes/footer.php'; ?>
