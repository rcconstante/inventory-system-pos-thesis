(() => {
    'use strict';
    const manager = document.getElementById('productOptionsDialog');
    const opener = document.getElementById('openProductOptions');
    if (!manager || !opener) return;
    const updateReturnFields = () => {
        const url = new URL(location.href);
        document.querySelectorAll('[data-option-form]').forEach((form) => {
            ['search', 'category_id', 'page'].forEach((key) => {
                form.elements.namedItem('return_' + key).value = url.searchParams.get(key) || '';
            });
        });
    };
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const dialogs = Array.from(document.querySelectorAll('.catalog-dialog[open]'));
        if (!dialogs.length) return;
        event.preventDefault(); event.stopPropagation();
        dialogs[dialogs.length - 1].close();
    }, true);
    opener.addEventListener('click', () => {
        updateReturnFields();
        if (!manager.open) manager.showModal();
    });
    manager.addEventListener('close', () => {
        const url = new URL(location.href);
        url.searchParams.delete('open_options');
        history.replaceState(history.state, '', url);
        opener.focus();
    });
    document.querySelectorAll('[data-option-edit], [data-option-delete]').forEach((button) => {
        button.addEventListener('click', () => {
            const editing = button.hasAttribute('data-option-edit');
            const dialog = document.getElementById(editing ? 'editOptionDialog' : 'deleteOptionDialog');
            const form = dialog.querySelector('form');
            form.elements.namedItem('option_kind').value = button.dataset.kind;
            form.elements.namedItem('option_id').value = button.dataset.id;
            updateReturnFields();
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
    document.querySelectorAll('.catalog-dialog [data-dialog-cancel]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
    document.querySelectorAll('[data-option-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.pending === '1') { event.preventDefault(); return; }
            updateReturnFields();
            form.dataset.pending = '1';
            form.setAttribute('aria-busy', 'true');
            setTimeout(() => form.querySelectorAll('button').forEach((button) => { button.disabled = true; }), 0);
        });
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('[data-option-form]').forEach((form) => {
            delete form.dataset.pending;
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button').forEach((button) => { button.disabled = false; });
        });
    });
    if (manager.dataset.autoOpen === '1') {
        opener.focus(); manager.showModal(); updateReturnFields();
        let draft = null;
        try { draft = JSON.parse(manager.dataset.draft); } catch { /* Ignore invalid restored form data. */ }
        if (draft && ['type', 'vehicle'].includes(draft.kind)) {
            if (draft.action === 'add') document.getElementById(draft.kind + '_new').value = draft.name;
            if (draft.action === 'update') {
                const button = Array.from(manager.querySelectorAll('[data-option-edit]')).find((item) => item.dataset.kind === draft.kind && item.dataset.id === String(draft.id));
                if (button) {
                    button.click();
                    document.querySelector('#editOptionDialog [name="option_name"]').value = draft.name;
                    const feedback = document.createElement('p');
                    feedback.setAttribute('role', 'alert');
                    feedback.className = 'mb-3 text-sm text-red-700 dark:text-red-300';
                    feedback.textContent = manager.querySelector('[role="status"]')?.textContent || 'The option could not be saved.';
                    document.querySelector('#editOptionDialog form').prepend(feedback);
                }
            }
        }
    }
})();
