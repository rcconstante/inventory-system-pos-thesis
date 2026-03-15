<?php
// includes/footer.php
?>
        </div>
    </div>
    <script>
        function openSettingsModal() {
            var modal = document.getElementById('settings-modal');
            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeSettingsModal() {
            var modal = document.getElementById('settings-modal');
            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSettingsModal();
            }
        });

        document.addEventListener('click', function (event) {
            var modal = document.getElementById('settings-modal');
            if (!modal) {
                return;
            }

            if (event.target === modal) {
                closeSettingsModal();
            }
        });
    </script>
</body>
</html>
