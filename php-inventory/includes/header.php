<?php
$flashMessages = pull_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : "Dashboard"; ?> - Inventory POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .custom-dark-bg { background-color: #363C52; }
    </style>
</head>
<body class="flex h-screen bg-white text-black">
    
    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col overflow-hidden">
        
        <!-- Header -->
        <div class="border-b border-gray-300 px-8 py-6 flex items-center justify-between">
            <?php if(isset($page_title)): ?>
                <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($page_title); ?></h1>
            <?php endif; ?>
            
            <button type="button" onclick="openSettingsModal()" class="p-2 hover:bg-gray-100 rounded transition-colors" title="Settings">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-settings"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-auto p-8">
            <?php if ($flashMessages !== []): ?>
                <div class="mb-6 space-y-3">
                    <?php foreach ($flashMessages as $flashMessage): ?>
                        <?php
                        $type = $flashMessage['type'] ?? 'info';
                        $classes = match ($type) {
                            'success' => 'border-green-200 bg-green-50 text-green-800',
                            'error' => 'border-red-200 bg-red-50 text-red-800',
                            'warning' => 'border-yellow-200 bg-yellow-50 text-yellow-800',
                            default => 'border-blue-200 bg-blue-50 text-blue-800',
                        };
                        ?>
                        <div class="rounded-lg border px-4 py-3 text-sm <?php echo $classes; ?>">
                            <?php echo h($flashMessage['message'] ?? ''); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php include __DIR__ . '/settings_modal.php'; ?>
