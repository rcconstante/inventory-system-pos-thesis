(() => {
    'use strict';
    const key = (value) => value.toLowerCase().replace(/[^a-z0-9]/g, '');
    const pickers = [];
    document.querySelectorAll('[data-vehicle-picker]').forEach((root) => {
        const select = root.querySelector('select[name="vehicle_ids[]"]');
        const controls = root.querySelector('[data-vehicle-controls]');
        const search = root.querySelector('[data-vehicle-search]');
        const list = root.querySelector('[data-vehicle-list]');
        const status = root.querySelector('[data-vehicle-status]');
        const name = root.querySelector('[data-new-vehicle]');
        const add = root.querySelector('[data-create-vehicle]');
        const form = root.closest('form');
        let generation = 0;

        function choose(option, checked) {
            if (option.disabled) return;
            if (checked && key(option.dataset.name) !== 'universal' && select.selectedOptions.length >= 30 && !option.selected) {
                status.textContent = 'Select up to 30 compatible vehicles.';
                render();
                return;
            }
            if (checked) {
                const universal = key(option.dataset.name) === 'universal';
                Array.from(select.options).forEach((other) => {
                    if (universal || key(other.dataset.name) === 'universal') other.selected = false;
                });
            }
            option.selected = checked;
            render();
        }

        function render() {
            const term = key(search.value);
            const matches = Array.from(select.options).filter((option) => option.selected || key(option.dataset.name).includes(term) || (option.dataset.search || '').includes(term));
            list.replaceChildren();
            matches.forEach((option) => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2 py-1 text-sm';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.id = select.id + '_choice_' + option.value;
                checkbox.checked = option.selected;
                checkbox.disabled = option.disabled;
                checkbox.addEventListener('change', () => {
                    choose(option, checkbox.checked);
                    document.getElementById(checkbox.id)?.focus();
                });
                const text = document.createElement('span');
                text.textContent = option.textContent;
                label.append(checkbox, text);
                list.appendChild(label);
            });
            if (!matches.length) {
                const empty = document.createElement('p');
                empty.className = 'text-sm text-gray-500';
                empty.textContent = 'No matching vehicles. Add a new vehicle below.';
                list.appendChild(empty);
            }
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        search.addEventListener('input', render);
        search.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
        name.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); if (!add.disabled) add.click(); } });
        select.addEventListener('vehicle-selection-change', () => { search.value = ''; render(); });
        form.addEventListener('reset', () => {
            generation++;
            search.value = '';
            status.textContent = '';
            setTimeout(() => {
                Array.from(select.options).forEach((option) => { option.disabled = option.dataset.active === '0'; });
                render();
            }, 0);
        });
        add.addEventListener('click', async () => {
            if (!name.value.trim()) { status.textContent = 'Enter the new vehicle name.'; name.focus(); return; }
            const currentGeneration = generation;
            const submits = Array.from(form.querySelectorAll('[type="submit"]')).map((button) => [button, button.disabled]);
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            add.disabled = true;
            submits.forEach(([button]) => { button.disabled = true; });
            status.textContent = 'Saving vehicle…';
            try {
                const body = new FormData();
                body.set('csrf_token', form.elements.namedItem('csrf_token').value);
                body.set('vehicle_name', name.value.trim());
                const response = await fetch(root.dataset.createUrl, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'The vehicle could not be saved.');
                const vehicle = result.vehicle;
                pickers.forEach((picker) => {
                    let option = Array.from(picker.select.options).find((entry) => entry.value === String(vehicle.vehicle_id));
                    if (!option) {
                        option = document.createElement('option');
                        option.value = String(vehicle.vehicle_id);
                        picker.select.appendChild(option);
                    }
                    option.textContent = vehicle.vehicle_name;
                    option.dataset.name = vehicle.vehicle_name;
                    option.dataset.active = '1';
                    option.disabled = false;
                    if (picker.select === select && currentGeneration === generation) choose(option, true);
                    picker.render();
                });
                if (currentGeneration === generation) {
                    name.value = '';
                    search.value = '';
                    const selected = Array.from(select.selectedOptions).some((option) => option.value === String(vehicle.vehicle_id));
                    status.textContent = !selected ? 'Vehicle saved. Deselect a vehicle before selecting another.' : result.created ? 'Vehicle saved and selected. It is also available for future products.' : 'Existing vehicle found and selected.';
                    render();
                }
            } catch (error) {
                if (currentGeneration === generation) status.textContent = error.name === 'AbortError' ? 'The request timed out. Retry to find or save the vehicle.' : error.message || 'Unable to save. Check your connection and try again.';
            } finally {
                clearTimeout(timeout);
                add.disabled = false;
                submits.forEach(([button, disabled]) => { button.disabled = disabled; });
            }
        });
        pickers.push({ select, render });
        select.hidden = true;
        controls.hidden = false;
        render();
    });
})();
