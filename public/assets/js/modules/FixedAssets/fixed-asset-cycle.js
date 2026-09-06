(function () {
    'use strict';
    const showHashTab = () => {
        const target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
        const pane = target?.closest('.tab-pane');
        if (pane) {
            const button = document.querySelector(`[data-bs-target="#${pane.id}"]`);
            if (button && window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(button).show(); }
            else if (button) { button.click(); }
        }
    };
    window.addEventListener('hashchange', showHashTab);
    showHashTab();
    const workflow = document.querySelector('.fixed-asset-360')?.dataset.workflow;
    const modal = workflow && document.getElementById(`${workflow}-modal`);
    if (modal && window.bootstrap) { window.bootstrap.Modal.getOrCreateInstance(modal).show(); }
    document.querySelector('.js-asset-ledger-type')?.addEventListener('change', (event) => {
        document.querySelectorAll('[data-movement-type]').forEach((row) => {
            const type = event.target.value;
            row.hidden = type !== '' && !(type === 'reversal' ? row.dataset.movementType.endsWith('_reversal') : row.dataset.movementType === type);
        });
    });
    if (window.jQuery) {
        window.jQuery(document)
            .off('file-picker:selected.fixedAssetDocument', '[data-picker-target-input="#asset-document-file"]')
            .on('file-picker:selected.fixedAssetDocument', '[data-picker-target-input="#asset-document-file"]', function (event, payload) {
                const label = document.querySelector('.js-asset-document-selected');
                if (label) { label.textContent = payload.name || payload.public_id; }
                const submit = document.querySelector('.js-asset-document-submit');
                if (submit) { submit.disabled = !payload.public_id; }
            });
    }
    document.querySelectorAll('.js-addition-form').forEach((form) => {
        const input = form.querySelector('[name="amount"]');
        const output = form.querySelector('.js-addition-new-cost');
        const update = () => {
            const addition = Number(input.value.replace(/,/g, '')) || 0;
            output.textContent = (Number(form.dataset.currentCost) + addition).toLocaleString(document.documentElement.lang || 'en', {maximumFractionDigits: 4});
        };
        input.addEventListener('input', update);
        update();
    });
    document.querySelectorAll('.fixed-asset-360 form, .js-depreciation-post-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) { return; }
            if (form.classList.contains('js-depreciation-post-form') && form.dataset.confirmed !== 'true') {
                event.preventDefault();
                const submitConfirmed = () => {
                    form.dataset.confirmed = 'true';
                    form.requestSubmit();
                };
                if (window.Swal) {
                    window.Swal.fire({
                        icon: 'warning',
                        title: form.dataset.confirmTitle,
                        text: form.dataset.confirmText,
                        showCancelButton: true,
                        focusCancel: true,
                        confirmButtonText: form.dataset.confirmYes,
                        cancelButtonText: form.dataset.confirmCancel,
                        confirmButtonColor: '#00a854'
                    }).then((result) => { if (result.isConfirmed) { submitConfirmed(); } });
                } else if (window.confirm(form.dataset.confirmText)) {
                    submitConfirmed();
                }
                return;
            }
            if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
            form.dataset.submitting = 'true';
            form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
        });
    });
    document.querySelectorAll('.js-transfer-form, .js-custody-form').forEach((form) => {
        const fields = [...form.querySelectorAll('[name^="destination_"], [name="custodian_doc_num"]')];
        const initial = fields.map((field) => field.name === 'custodian_doc_num' ? form.dataset.currentCustodian : field.value);
        const update = () => {
            const action = form.querySelector('[name="custody_action"]');
            const employee = form.querySelector('[name="custodian_doc_num"]');
            if (action) {
                const returning = action.value === 'return';
                form.querySelector('.js-custody-employee').hidden = returning;
                employee.disabled = returning;
                employee.required = !returning;
                form.querySelector('button[type="submit"]').disabled = returning ? !form.dataset.currentCustodian : !employee.value || employee.value === form.dataset.currentCustodian;
                return;
            }
            const unchanged = fields.every((field, index) => field.value.trim() === String(initial[index] || '').trim());
            form.querySelector('button[type="submit"]').disabled = unchanged;
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        if (window.jQuery) { window.jQuery(form).on('select2:select select2:clear', update); }
        update();
    });
    const send = async (form) => {
        const response = await fetch(form.dataset.previewUrl, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
        const data = await response.json();
        if (!response.ok) { throw new Error(Object.values(data.errors || {}).flat().join(' / ') || data.message); }
        return data;
    };
    document.querySelectorAll('.js-disposal-form').forEach((form) => {
        const type = form.querySelector('[name="disposition_type"]');
        const settlement = form.querySelector('[name="settlement_path"]');
        const expenses = form.querySelector('[name="disposal_expenses"]');
        const clearSelect = (name) => {
            const select = form.querySelector(`[name="${name}"]`);
            if (select && window.jQuery) { window.jQuery(select).val(null).trigger('change.select2'); }
        };
        const updateFields = () => {
            const sale = type.value === 'sale';
            const hasExpenses = Number(String(expenses.value || '0').replace(/,/g, '')) > 0;
            if (!sale) {
                form.querySelector('[name="proceeds"]').value = '0';
                form.querySelector('[name="tax_rate"]').value = '0';
                settlement.value = 'direct_settlement';
                clearSelect('customer_doc_num');
                clearSelect('proceeds_account_doc_num');
            } else if (settlement.value === 'customer_invoice') {
                clearSelect('proceeds_account_doc_num');
            } else {
                form.querySelector('[name="tax_rate"]').value = '0';
                clearSelect('customer_doc_num');
            }
            form.querySelectorAll('[data-disposal-field]').forEach((field) => {
                const fieldType = field.dataset.disposalField;
                const visible = fieldType === 'expenses'
                    ? hasExpenses
                    : sale && (fieldType === 'sale' || (fieldType === 'invoice' ? settlement.value === 'customer_invoice' : settlement.value !== 'customer_invoice'));
                field.hidden = !visible;
                field.querySelectorAll('input, select').forEach((input) => {
                    const neutralValueRequired = ['proceeds', 'settlement_path', 'tax_rate'].includes(input.name);
                    input.disabled = !visible && !neutralValueRequired;
                });
            });
        };
        type.addEventListener('change', updateFields);
        settlement.addEventListener('change', updateFields);
        expenses.addEventListener('input', updateFields);
        updateFields();
        const result = form.querySelector('.js-disposal-result');
        const post = form.querySelector('.js-disposal-post');
        let revision = 0;
        const invalidate = () => { revision += 1; post.disabled = true; result.replaceChildren(); };
        form.addEventListener('input', invalidate);
        form.addEventListener('change', invalidate);
        if (window.jQuery) { window.jQuery(form).on('select2:select select2:clear', invalidate); }
        form.querySelector('.js-disposal-preview').addEventListener('click', async () => {
            post.disabled = true;
            if (!form.reportValidity()) { return; }
            const requestedRevision = revision;
            try {
                const data = await send(form);
                if (requestedRevision !== revision) { return; }
                const table = document.createElement('table'); table.className = 'table table-sm table-bordered';
                (data.display || []).forEach((item) => {
                    const tr = table.insertRow(); tr.insertCell().textContent = item.label;
                    const value = tr.insertCell(); value.dir = 'ltr'; value.textContent = item.value;
                });
                const journal = document.createElement('table'); journal.className = 'table table-sm table-bordered';
                const heading = journal.createTHead().insertRow();
                [form.dataset.accountLabel, form.dataset.debitLabel, form.dataset.creditLabel].forEach((label) => {
                    const th = document.createElement('th'); th.textContent = label; heading.append(th);
                });
                const body = journal.createTBody();
                (data.journal_preview || []).forEach((line) => {
                    const tr = body.insertRow(); tr.insertCell().textContent = line.account;
                    ['debit', 'credit'].forEach((key) => { const cell = tr.insertCell(); cell.dir = 'ltr'; cell.textContent = line[key + '_display'] || line[key]; });
                });
                result.replaceChildren(table, journal);
                if (data.has_gap) { const warning = document.createElement('p'); warning.className = 'text-danger'; warning.textContent = data.gap_message; result.append(warning); }
                post.disabled = data.has_gap;
            } catch (error) { result.textContent = error.message; }
        });
    });
    document.querySelectorAll('.js-depreciation-post-form').forEach((form) => {
        let timer, revision = 0;
        const post = form.querySelector('[type="submit"]');
        const error = form.querySelector('.js-depreciation-error');
        const refresh = () => {
            clearTimeout(timer); revision += 1;
            if (post) { post.disabled = true; }
            const requestedRevision = revision;
            timer = setTimeout(async () => {
                try {
                    const data = await send(form);
                    if (requestedRevision !== revision) { return; }
                    for (const row of data.rows) {
                        const tr = Array.from(form.querySelectorAll('[data-asset]')).find((item) => item.dataset.asset === row.asset);
                        if (!tr) { continue; }
                        tr.querySelectorAll('[data-value]').forEach((cell) => { cell.textContent = row[cell.dataset.value]; });
                    }
                    form.querySelectorAll('[data-total]').forEach((cell) => {
                        const total = data.rows.reduce((sum, row) => sum + Number(row[cell.dataset.total] || 0), 0);
                        cell.textContent = total.toLocaleString(document.documentElement.lang || 'en', {minimumFractionDigits: 2, maximumFractionDigits: 4});
                    });
                    const invalid = data.excluded.map((row) => `${row.asset}: ${row.reason}`);
                    error.textContent = invalid.join(' / '); error.classList.toggle('d-none', invalid.length === 0);
                    if (post) { post.disabled = !form.querySelector('[name="asset_doc_nums[]"]:checked') || invalid.length > 0 || data.rows.length === 0 || data.rows.some((row) => row.requires_usage_units); }
                } catch (failure) { error.textContent = failure.message; error.classList.remove('d-none'); }
            }, 250);
        };
        form.querySelectorAll('[name^="usage_units"], [name="asset_doc_nums[]"]').forEach((input) => input.addEventListener('input', refresh));
        if (form.querySelector('[name^="usage_units"]')) { refresh(); }
    });
}());
