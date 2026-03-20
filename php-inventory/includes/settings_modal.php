<?php
$currentUser = current_user_session();
$preferences = get_preferences();
?>
<div id="settings-modal" class="hidden fixed inset-0 z-[100] items-center justify-center bg-black/50 p-4 transition-opacity">
    <div class="w-full max-w-lg bg-white dark:bg-gray-800 relative border border-black dark:border-gray-600 rounded-sm transition-colors duration-200">
        <!-- Close Button -->
        <button type="button" onclick="closeSettingsModal()" class="absolute top-4 right-4 p-1 text-black dark:text-white hover:text-gray-600 dark:hover:text-gray-300 z-10" aria-label="Close settings">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>

        <div class="p-10">
            <!-- Profile Section -->
            <div class="flex items-center gap-8 mb-12 mt-2">
                <!-- Avatar Box -->
                <div class="w-56 h-48 border border-black dark:border-gray-500 flex flex-col items-center justify-center bg-white dark:bg-gray-700 flex-shrink-0">
                    <svg width="100" height="120" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="text-black dark:text-white" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="7" r="4"></circle>
                        <ellipse cx="12" cy="18" rx="8" ry="4"></ellipse>
                    </svg>
                </div>
                <!-- User Info -->
                <div class="flex flex-col justify-center">
                    <h2 class="text-3xl font-medium text-black dark:text-white mb-1"><?php echo h($currentUser['full_name'] ?? ''); ?></h2>
                    <p class="text-sm text-black dark:text-gray-300 mb-2"><?php echo h(role_name((int) $currentUser['role_id'])); ?></p>
                    <p class="text-xs text-black dark:text-gray-400">User Name: <?php echo h($currentUser['username'] ?? ''); ?></p>
                </div>
            </div>

            <!-- Settings Options -->
            <div class="space-y-6 pl-10 mb-4">
                <!-- Light Mode -->
                <label class="flex cursor-pointer items-center gap-4 group">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-black dark:text-white"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                    <span class="text-sm text-black dark:text-white">Light Mode</span>
                    <input type="radio" name="theme_mode" value="light" class="sr-only" onchange="applyDarkMode(false)" id="theme-light">
                </label>

                <!-- Dark Mode -->
                <label class="flex cursor-pointer items-center gap-4 group">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-black dark:text-white"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                    <span class="text-sm text-black dark:text-white">Dark Mode</span>
                    <input type="radio" name="theme_mode" value="dark" class="sr-only" onchange="applyDarkMode(true)" id="theme-dark">
                </label>

                <!-- Recommendation System -->
                <form method="POST" action="<?php echo h(app_url('pages/settings.php')); ?>" id="preferences-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="settings_action" value="update_preferences">
                    <label class="flex cursor-pointer items-center gap-4 group">
                        <div class="relative flex items-center">
                            <input type="checkbox" name="show_recommendations" value="1" id="toggle-recommendations" class="sr-only peer" <?php echo !empty($preferences['show_recommendations']) ? 'checked' : ''; ?> onchange="document.getElementById('preferences-form').submit()">
                            <div class="w-10 h-6 bg-gray-200 rounded-full peer peer-checked:bg-black transition-colors border border-black"></div>
                            <div class="absolute top-1 left-1 w-4 h-4 bg-white border border-black rounded-full transition-transform peer-checked:translate-x-4"></div>
                        </div>
                        <span class="text-sm text-black">Recommendation System</span>
                    </label>
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
            document.getElementById('theme-dark').checked = true;
        } else {
            document.documentElement.classList.remove('dark');
            document.cookie = 'dark_mode=0; path=/; max-age=' + (60 * 60 * 24 * 365);
            document.getElementById('theme-light').checked = true;
        }
    }

    // Apply on load from cookie
    (function () {
        var match = document.cookie.match(/(?:^|;\s*)dark_mode=([^;]*)/);
        if (match && match[1] === '1') {
            document.documentElement.classList.add('dark');
            var darkToggle = document.getElementById('theme-dark');
            if (darkToggle) darkToggle.checked = true;
        } else {
            var lightToggle = document.getElementById('theme-light');
            if (lightToggle) lightToggle.checked = true;
        }
    })();
</script>
