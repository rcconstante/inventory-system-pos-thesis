<div id="notificationsModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl relative">
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-xl font-medium uppercase">Notifications</h2>
            <button type="button" onclick="toggleNotificationsModal(false)" class="text-black hover:text-gray-700">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        
        <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
            <?php if ($notificationCount === 0): ?>
                <div class="text-center text-gray-500 py-4">No new notifications.</div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="border-2 border-black p-3">
                        <div class="text-base text-black">
                            <span class="font-medium mr-1 <?php echo h($notification['title_color'] ?? ''); ?>">
                                <?php echo $notification['emoji'] . ' ' . h($notification['title']); ?>:
                            </span><br>
                            <?php echo $notification['message']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function toggleNotificationsModal(shouldOpen) {
        var modal = document.getElementById('notificationsModal');
        if (shouldOpen) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        } else {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }
</script>
