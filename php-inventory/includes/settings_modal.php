<?php
$currentUser = current_user_session();
$preferences = get_preferences();
?>
<div id="settings-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-lg rounded-xl border border-gray-200 bg-white shadow-xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <h2 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Settings</h2>
            <button type="button" onclick="closeSettingsModal()" class="rounded-md p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-900" aria-label="Close settings">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <div class="p-6 space-y-6">

            <!-- Profile Card -->
            <div class="flex items-center gap-4 rounded-xl border border-gray-200 p-4">
                <div class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-full border-2 border-gray-200 bg-gray-100">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
                </div>
                <div>
                    <div class="text-base font-bold text-gray-900"><?php echo h(strtoupper($currentUser['full_name'])); ?></div>
                    <div class="text-sm text-gray-500"><?php echo h(role_name((int) $currentUser['role_id'])); ?></div>
                </div>
            </div>

            <!-- Appearance -->
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-widest text-gray-400">Appearance</p>

                <label class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-200 px-4 py-3 hover:bg-gray-50">
                    <div class="flex items-center gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                        <span class="text-sm font-medium text-gray-800">Dark Mode</span>
                    </div>
                    <div class="relative">
                        <input type="checkbox" id="toggle-dark-mode" class="sr-only peer" onchange="applyDarkMode(this.checked)">
                        <div class="w-10 h-6 bg-gray-200 rounded-full peer peer-checked:bg-black transition-colors"></div>
                        <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
                    </div>
                </label>
            </div>

            <!-- Recommendation System -->
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-widest text-gray-400">System</p>

                <form method="POST" action="<?php echo h(app_url('pages/settings.php')); ?>" id="preferences-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="settings_action" value="update_preferences">
                    <label class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <div class="flex items-center gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            <span class="text-sm font-medium text-gray-800">Recommendation System</span>
                        </div>
                        <div class="relative">
                            <input type="checkbox" name="show_recommendations" value="1" id="toggle-recommendations" class="sr-only peer" <?php echo !empty($preferences['show_recommendations']) ? 'checked' : ''; ?> onchange="document.getElementById('preferences-form').submit()">
                            <div class="w-10 h-6 bg-gray-200 rounded-full peer peer-checked:bg-black transition-colors"></div>
                            <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
                        </div>
                    </label>
                </form>
            </div>

            <!-- Security: Change Password -->
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-widest text-gray-400">Security</p>

                <form method="POST" action="<?php echo h(app_url('pages/settings.php')); ?>" class="space-y-3 rounded-lg border border-gray-200 p-4">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="settings_action" value="update_password">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Current Password</label>
                        <input type="password" name="current_password" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">New Password</label>
                        <input type="password" name="new_password" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Confirm New Password</label>
                        <input type="password" name="confirm_password" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </div>
                    <div class="flex justify-end pt-1">
                        <button type="submit" class="rounded-lg bg-black px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Update Password</button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
    function applyDarkMode(enabled) {
        if (enabled) {
            document.documentElement.classList.add('dark');
            document.cookie = 'dark_mode=1; path=/; max-age=' + (60 * 60 * 24 * 365);
        } else {
            document.documentElement.classList.remove('dark');
            document.cookie = 'dark_mode=0; path=/; max-age=' + (60 * 60 * 24 * 365);
        }
    }

    // Apply on load from cookie
    (function () {
        var match = document.cookie.match(/(?:^|;\s*)dark_mode=([^;]*)/);
        if (match && match[1] === '1') {
            document.documentElement.classList.add('dark');
            var toggle = document.getElementById('toggle-dark-mode');
            if (toggle) toggle.checked = true;
        }
    })();
</script>
