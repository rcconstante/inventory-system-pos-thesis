<?php
declare(strict_types=1);
require_once '../includes/app.php'; require_once '../includes/config.php'; require_once '../includes/domain.php';
require_login([APP_ROLE_ADMIN]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail('pages/business_settings.php');
    try {
        if (isset($_POST['save_business'])) {
            $values = [];
            foreach (['business_name' => 150, 'address' => 250, 'phone' => 50] as $key => $limit) {
                $value = $_POST[$key] ?? ''; if (!is_string($value) || strlen(trim($value)) > $limit || ($key === 'business_name' && trim($value) === '')) throw new RuntimeException('Enter valid business details.');
                $values[$key] = trim($value);
            }
            $logo = business_setting('logo');
            if (isset($_POST['remove_logo'])) $logo = '';
            $upload = $_FILES['logo'] ?? null;
            if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 2 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Upload a PNG or JPEG up to 2 MB.');
                $info = @getimagesize($upload['tmp_name']);
                if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg'], true) || $info[0] > 4096 || $info[1] > 4096) throw new RuntimeException('Use a PNG or JPEG no larger than 4096 pixels per side.');
                $logo = 'data:' . $info['mime'] . ';base64,' . base64_encode(file_get_contents($upload['tmp_name']));
            }
            $pdo->prepare('INSERT INTO Business_Settings (setting_id, business_name, address, phone, logo) VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE business_name = VALUES(business_name), address = VALUES(address), phone = VALUES(phone), logo = VALUES(logo)')->execute([$values['business_name'], $values['address'], $values['phone'], $logo]);
        } elseif (isset($_POST['save_property'])) {
            $label = $_POST['label'] ?? ''; $key = $_POST['property_key'] ?? '';
            if (!is_string($label) || trim($label) === '' || strlen($label) > 80 || !is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) throw new RuntimeException('Use a label up to 80 characters and a key with lowercase letters, numbers or underscores.');
            if (in_array($key, ['size', 'position', 'voltage', 'viscosity'], true)) throw new RuntimeException('Built-in fitment keys are reserved to preserve their validation.');
            $pdo->prepare('INSERT INTO Product_Property (property_key, label) VALUES (?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)')->execute([$key, trim($label)]);
        } else throw new RuntimeException('Unknown settings action.');
        set_flash('success', 'Settings saved.'); redirect_to('pages/business_settings.php');
    } catch (Throwable $e) { error_log('Business settings: ' . $e->getMessage()); set_flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Settings could not be saved.'); redirect_to('pages/business_settings.php'); }
}
$page_title = 'BUSINESS SETTINGS'; include '../includes/header.php';
?>
<div class="grid gap-6 xl:grid-cols-2">
<form method="POST" enctype="multipart/form-data" class="rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-6"><?php echo csrf_field(); ?><h2 class="font-bold text-lg mb-4">Business header & logo</h2>
<?php foreach (['business_name' => 'Business name / header', 'address' => 'Address', 'phone' => 'Phone'] as $key => $label): ?><label class="block mb-4"><?php echo h($label); ?><input name="<?php echo h($key); ?>" maxlength="<?php echo $key === 'address' ? 250 : ($key === 'phone' ? 50 : 150); ?>" value="<?php echo h(business_setting($key)); ?>" <?php echo $key === 'business_name' ? 'required' : ''; ?> class="mt-1 w-full rounded border border-black p-2 dark:bg-gray-700"></label><?php endforeach; ?>
<label class="block mb-3">Logo (PNG / JPEG, up to 2 MB)<input type="file" name="logo" accept="image/png,image/jpeg" class="block mt-2 w-full"></label><label class="block mb-4 text-sm"><input type="checkbox" name="remove_logo"> Restore default logo</label><button name="save_business" class="bg-black text-white rounded px-5 py-2">Save business details</button></form>
<section class="rounded border border-black dark:border-gray-600 bg-white dark:bg-gray-800 p-6"><h2 class="font-bold text-lg mb-2">Product criteria / properties</h2><p class="text-sm mb-4">Add properties for future products, such as material or capacity. Enter a value on a product only when its alternatives must match it. Existing tire size, position, voltage and viscosity checks remain active.</p>
<?php foreach (essential_fitment_fields() as $key => $label): ?><div class="border-b border-black py-2 text-sm flex justify-between gap-3"><span><?php echo h($label); ?></span><span class="text-gray-500"><?php echo h($key); ?></span></div><?php endforeach; ?>
<form method="POST" class="mt-4"><?php echo csrf_field(); ?><label class="block mb-3">Property key<input name="property_key" required maxlength="40" pattern="[a-z][a-z0-9_]{0,39}" placeholder="e.g. material" class="mt-1 w-full border border-black rounded p-2 dark:bg-gray-700"></label><label class="block mb-3">Display label<input name="label" required maxlength="80" placeholder="e.g. Material" class="mt-1 w-full border border-black rounded p-2 dark:bg-gray-700"></label><p class="mb-3 text-xs">Use an existing custom key to rename its label without changing saved product values.</p><button name="save_property" class="bg-black text-white rounded px-5 py-2">Save property</button></form></section></div>
<?php include '../includes/footer.php'; ?>
