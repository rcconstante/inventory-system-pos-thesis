(() => {
    'use strict';
    document.querySelectorAll('[data-option-edit], [data-option-delete]').forEach((button) => {
        button.addEventListener('click', () => {
            const editing = button.hasAttribute('data-option-edit');
            const dialog = document.getElementById(editing ? 'editOptionDialog' : 'deleteOptionDialog');
            const form = dialog.querySelector('form');
            form.elements.namedItem('option_kind').value = button.dataset.kind;
            form.elements.namedItem('option_id').value = button.dataset.id;
            if (editing) {
                const input = form.elements.namedItem('option_name');
                input.maxLength = button.dataset.kind === 'type' ? 50 : 100;
                input.value = button.dataset.name;
                dialog.showModal(); input.focus(); input.select();
            } else {
                dialog.querySelector('[data-delete-message]').textContent = 'Delete “' + button.dataset.name + '”?';
                dialog.showModal(); dialog.querySelector('[data-dialog-cancel]').focus();
            }
        });
    });
    document.querySelectorAll('[data-dialog-cancel]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
})();
