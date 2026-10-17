(() => {
    'use strict';
    const key = (value) => value.toLowerCase().replace(/[^a-z0-9]/g, '');
    const pickers = [];
    document.querySelectorAll('[data-catalog-picker]').forEach((root) => {
        const multiple = root.dataset.catalogPicker === 'vehicle';
        const select = root.querySelector('select');
        const controls = root.querySelector('[data-picker-controls]');
        const toggle = root.querySelector('[data-picker-toggle]');
        const panel = root.querySelector('[data-picker-panel]');
        const summary = root.querySelector('[data-picker-summary]');
        const search = root.querySelector('[data-picker-search]');
        const list = root.querySelector('[data-picker-list]');
        const status = root.querySelector('[data-picker-status]');
        const name = root.querySelector('[data-picker-new]');
        const add = root.querySelector('[data-picker-create]');
        const apply = root.querySelector('[data-picker-apply]');
        const form = root.closest('form');
        let draft = new Set();
        let generation = 0;
        let pending = false;
        const committed = () => new Set(Array.from(select.selectedOptions, (option) => option.value));

        function updateSummary() {
            const names = Array.from(select.selectedOptions).filter((option) => option.value !== '').map((option) => option.dataset.name);
            summary.textContent = names.length ? names[0] + (names.length > 1 ? ' +' + (names.length - 1) : '') : multiple ? 'Select compatible vehicles' : 'Select product type';
            summary.title = names.join(', ');
        }
        function close(restoreFocus = true) {
            generation++;
            draft = committed();
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            if (restoreFocus) toggle.focus();
        }
        function commit() {
            Array.from(select.options).forEach((option) => { option.selected = draft.has(option.value); });
            select.dispatchEvent(new Event('change', { bubbles: true }));
            updateSummary();
            close();
        }
        function choose(option, checked) {
            if (option.disabled) return;
            if (!multiple) {
                draft = new Set([option.value]);
                commit();
                return;
            }
            const universal = key(option.dataset.name) === 'universal';
            if (checked && !universal && draft.size >= 30 && !draft.has(option.value)) {
                status.textContent = 'Select up to 30 compatible vehicles.';
                render();
                return;
            }
            if (checked) {
                if (universal) draft.clear();
                else Array.from(select.options).filter((entry) => key(entry.dataset.name) === 'universal').forEach((entry) => draft.delete(entry.value));
                draft.add(option.value);
            } else draft.delete(option.value);
            render();
            document.getElementById(select.id + '_choice_' + option.index)?.focus();
        }
        function render() {
            const term = key(search.value);
            const matches = Array.from(select.options).filter((option) => key(option.dataset.name).includes(term) || (option.dataset.search || '').includes(term));
            list.replaceChildren();
            matches.forEach((option) => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-3 rounded px-2 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-800 focus-within:ring-2 focus-within:ring-blue-500';
                const input = document.createElement('input');
                input.type = multiple ? 'checkbox' : 'radio';
                if (!multiple) input.className = 'sr-only';
                input.name = select.id + '_draft';
                input.id = select.id + '_choice_' + option.index;
                input.checked = draft.has(option.value);
                input.disabled = option.disabled;
                input.addEventListener('change', () => choose(option, input.checked));
                const text = document.createElement('span');
                text.textContent = option.textContent;
                text.className = 'flex-1';
                label.append(input, text);
                if (!multiple && input.checked) {
                    const check = document.createElement('span');
                    check.textContent = '✓';
                    check.className = 'text-green-500';
                    check.setAttribute('aria-hidden', 'true');
                    label.appendChild(check);
                }
                list.appendChild(label);
            });
            if (!matches.length) {
                const empty = document.createElement('p');
                empty.className = 'px-2 py-3 text-sm text-gray-500';
                empty.textContent = 'No matches. Add a new entry below.';
                list.appendChild(empty);
            }
            if (multiple) root.querySelector('[data-picker-count]').textContent = draft.size + ' selected';
        }
        toggle.addEventListener('click', () => {
            if (!panel.hidden) { close(); return; }
            pickers.filter((picker) => picker.root !== root).forEach((picker) => picker.close(false));
            draft = committed(); search.value = ''; status.textContent = '';
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            render(); search.focus();
        });
        root.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); event.stopPropagation(); close(); } });
        document.addEventListener('click', (event) => { if (!panel.hidden && !root.contains(event.target)) close(false); });
        search.addEventListener('input', render);
        search.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
        name.addEventListener('input', () => { add.disabled = pending || !name.value.trim(); });
        name.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); if (!add.disabled) add.click(); } });
        if (multiple) {
            root.querySelector('[data-picker-cancel]').addEventListener('click', () => close());
            apply.addEventListener('click', commit);
        }
        select.addEventListener('catalog-selection-change', () => { close(false); updateSummary(); });
        form.addEventListener('reset', () => {
            close(false); status.textContent = ''; add.disabled = true;
            setTimeout(() => {
                Array.from(select.options).forEach((option) => { option.disabled = option.dataset.active === '0'; });
                draft = committed(); updateSummary();
            }, 0);
        });
        form.addEventListener('submit', (event) => {
            const applied = committed();
            if (pending || (!panel.hidden && multiple && (draft.size !== applied.size || Array.from(draft).some((id) => !applied.has(id))))) {
                event.preventDefault();
                status.textContent = pending ? 'Wait for the new entry to finish saving.' : 'Apply or cancel the compatibility changes before saving the product.';
                (apply || toggle).focus();
            }
        });
        add.addEventListener('click', async () => {
            if (pending || !name.value.trim()) return;
            const currentGeneration = generation;
            const submits = Array.from(form.querySelectorAll('[type="submit"]')).map((button) => [button, button.disabled]);
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            pending = true; add.disabled = true;
            if (apply) apply.disabled = true;
            submits.forEach(([button]) => { button.disabled = true; });
            status.textContent = 'Saving…';
            try {
                const body = new FormData();
                body.set('csrf_token', form.elements.namedItem('csrf_token').value);
                body.set(multiple ? 'vehicle_name' : 'type_name', name.value.trim());
                const response = await fetch(root.dataset.createUrl, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'The entry could not be saved.');
                const entry = multiple ? result.vehicle : result.type;
                const value = multiple ? String(entry.vehicle_id) : entry.type_name;
                const label = multiple ? entry.vehicle_name : entry.type_name;
                const activeRequest = currentGeneration === generation;
                pickers.filter((picker) => picker.multiple === multiple).forEach((picker) => {
                    let option = Array.from(picker.select.options).find((item) => item.value === value);
                    if (!option) { option = document.createElement('option'); option.value = value; picker.select.appendChild(option); }
                    option.textContent = label; option.dataset.name = label; option.dataset.active = '1'; option.disabled = false;
                    if (picker.root === root && activeRequest) choose(option, true);
                    picker.render(); picker.updateSummary();
                });
                if (activeRequest) {
                    name.value = ''; search.value = '';
                    status.textContent = multiple ? (draft.has(value) ? 'Model saved. Apply to use the updated selection.' : 'Model saved. Deselect a vehicle before selecting another.') : 'Product type saved and selected.';
                    render();
                }
            } catch (error) {
                if (currentGeneration === generation) status.textContent = error.name === 'AbortError' ? 'The request timed out. Retry to find or save the entry.' : error.message || 'Unable to save. Check your connection and retry.';
            } finally {
                clearTimeout(timeout); pending = false; add.disabled = !name.value.trim();
                if (apply) apply.disabled = false;
                submits.forEach(([button, disabled]) => { button.disabled = disabled; });
            }
        });
        pickers.push({ root, multiple, select, render, updateSummary, close });
        select.hidden = true; controls.hidden = false; draft = committed(); updateSummary(); render();
    });
})();
