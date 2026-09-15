(() => {
    let nextId = 0;
    const reindex = (body, prefix) => Array.from(body.children).forEach((row, index) => {
        row.dataset.index = index;
        const marker = body.closest('form')?.querySelector('[data-line-card-editor]');
        if (marker) row.setAttribute('aria-label', `${marker.dataset.lineLabel} ${index + 1}`);
        const number = row.querySelector('[data-row-number]');
        if (number) number.textContent = index + 1;
        row.querySelectorAll('[name]').forEach(field => {
            field.name = field.name.replace(new RegExp('^' + prefix + '\\[[^\\]]+\\]'), prefix + '[' + index + ']');
            if (field.id && document.getElementById(field.id) !== field) field.removeAttribute('id');
        });
        row.querySelectorAll('[data-error-for]').forEach(error => {
            error.dataset.errorFor = error.dataset.errorFor.replace(new RegExp('^' + prefix + '\\.[^.]+\\.'), prefix + '.' + index + '.');
        });
    });
    const append = (body, template, prefix, source = null) => {
        body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(body.children.length)));
        const row = body.lastElementChild;
        if (source) {
            const originals = Array.from(source.querySelectorAll('[name]'));
            row.querySelectorAll('[name]').forEach(field => {
                const suffix = field.name.replace(/^[^[]+\[[^\]]+\]/, '');
                const original = originals.find(item => item.name.endsWith(suffix));
                if (!original || /\[(public_id|public_uuid|id|sales_request_line_id|quotation_revision_line_id)\]$/.test(suffix)) return;
                if (field.tagName === 'SELECT') {
                    field.replaceChildren(...Array.from(original.options).map(option => new Option(option.text, option.value, option.selected, option.selected)));
                }
                field.value = original.value;
                if (field.type === 'checkbox') field.checked = original.checked;
            });
        }
        reindex(body, prefix);
        return row;
    };
    const remove = (row, prefix, minimum = 1) => {
        const body = row.parentElement;
        if (body.children.length <= minimum) return false;
        if (window.jQuery) window.jQuery(row).find('select.select2-hidden-accessible').each(function () { window.jQuery(this).select2('destroy'); });
        row.remove(); reindex(body, prefix); return true;
    };
    window.AppLineItemCards = {append, remove, reindex};
    const initialize = () => document.querySelectorAll('[data-line-card-editor]').forEach(marker => {
        const form = marker.closest('form');
        if (!form || form.dataset.lineCardsInitialized) return;
        form.dataset.lineCardsInitialized = '1';
        form.addEventListener('click', event => {
            const button=event.target.closest('[data-submit-action]');
            const input=form.querySelector('[name="submit_action"]');
            if(button && input) input.value=button.dataset.submitAction;
        });
        const enhance = () => {
            if (marker.dataset.lineCardLayout === 'table') return;
            form.querySelectorAll('table').forEach(table => {
            if (!table.querySelector('tbody [name^="lines["], tbody [name^="items["], tbody [name^="allocations["], tbody [name^="results["], tbody [name^="payment_schedules["], tbody [name^="labor_details["]')) return;
            if (!table.querySelector('input:not([type="hidden"]):not([readonly]):not([disabled]), select:not([disabled]), textarea:not([readonly])')) return;
            const headings = Array.from(table.tHead?.rows[0]?.cells || []).map(cell => cell.textContent.trim());
            table.classList.add('line-card-repeater');
            table.setAttribute('role', 'presentation');
            table.closest('.table-responsive')?.classList.add('line-card-editor-scroll');
            Array.from(table.tBodies).forEach(body => {
                body.setAttribute('role', 'list');
                Array.from(body.rows).forEach((row, index) => {
                    if (!row.querySelector('[name]')) return;
                    const fresh = !row.dataset.lineCard;
                    row.dataset.lineCard = '1';
                    row.setAttribute('role', 'listitem');
                    row.setAttribute('aria-label', `${marker.dataset.lineLabel} ${index + 1}`);
                    Array.from(row.cells).forEach((cell, column) => {
                        const fields = Array.from(cell.querySelectorAll('input:not([type="hidden"]), select, textarea'));
                        const label = headings[column] || '';
                        if (cell.matches('.js-line-received, .js-line-remaining')) cell.hidden = true;
                        fields.forEach(field => {
                            if (!field.id || document.getElementById(field.id) !== field) field.id = `line-card-field-${++nextId}`;
                        });
                        if (column === 0 && ['#', 'م', ''].includes(label) && !fields.length) {
                            cell.classList.add('line-card-heading');
                            cell.dataset.cardTitle = `${marker.dataset.lineLabel} `;
                        } else if (label && !cell.querySelector(':scope > .line-card-field-label')) {
                            const title = document.createElement('label');
                            title.className = 'line-card-field-label';
                            title.textContent = label;
                            if (fields[0]) {
                                if (!fields[0].id) fields[0].id = `line-card-field-${++nextId}`;
                                title.htmlFor = fields[0].id;
                            }
                            cell.prepend(title);
                        }
                        const fieldLabel = cell.querySelector(':scope > .line-card-field-label');
                        if (fieldLabel && fields[0]) fieldLabel.htmlFor = fields[0].id;
                        if (fields.some(field => /\[(product_doc_num|product_id|item_id|description|specification)\]$/.test(field.name))) cell.classList.add('line-card-wide');
                        if (fields.some(field => /\[(notes|reason)\]$/.test(field.name))) cell.classList.add('line-card-full');
                        if (cell.querySelector('button') && !fields.length && !cell.querySelector('a[href]')) cell.classList.add('line-card-actions');
                        fields.forEach(field => {
                            if (label && !field.getAttribute('aria-label')) field.setAttribute('aria-label', label);
                            const key = field.name.replace(/\[([^\]]+)\]/g, '.$1');
                            if (!field.name) return;
                            let error = cell.querySelector(`[data-line-card-error="${fields.indexOf(field)}"]`) || cell.querySelector(`[data-error-for="${CSS.escape(key)}"]`);
                            if (!error) {
                                error = document.createElement('div');
                                error.className = 'invalid-feedback d-block';
                                cell.append(error);
                            }
                            if (error.dataset.errorFor && error.dataset.errorFor !== key) error.textContent = '';
                            error.dataset.lineCardError = fields.indexOf(field);
                            error.dataset.errorFor = key;
                        });
                        cell.querySelectorAll('button').forEach(button => {
                            if (!button.textContent.trim() && !button.getAttribute('aria-label')) button.setAttribute('aria-label', button.title || marker.dataset.removeLabel);
                        });
                    });
                    if (fresh && form.dataset.lineCardsReady && document.activeElement?.matches('button')) {
                        row.querySelector('select:not([disabled]), input:not([type="hidden"]):not([readonly]):not([disabled])')?.focus();
                    }
                });
            });
            });
        };
        form.addEventListener('invalid', event => {
            const field = event.target;
            const key = field.name?.replace(/\[([^\]]+)\]/g, '.$1');
            const error = field.closest('[data-line-card]')?.querySelector(`[data-error-for="${CSS.escape(key || '')}"]`);
            if (error) {
                error.textContent = marker.dataset.invalidLabel;
                field.classList.add('is-invalid');
            }
        }, true);
        form.addEventListener('input', event => {
            const field = event.target;
            if (!field.validity?.valid) return;
            const key = field.name?.replace(/\[([^\]]+)\]/g, '.$1');
            const error = field.closest('[data-line-card]')?.querySelector(`[data-error-for="${CSS.escape(key || '')}"]`);
            if (error?.textContent === marker.dataset.invalidLabel) {
                error.textContent = '';
                field.classList.remove('is-invalid');
            }
        });
        form.addEventListener('submit', event => {
            const invalid = Array.from(form.querySelectorAll('[data-line-card] input, [data-line-card] select, [data-line-card] textarea'))
                .filter(field => field.willValidate && !field.checkValidity());
            if (invalid.length) {
                event.preventDefault();
                event.stopPropagation();
                const first = invalid[0];
                const visibleSelect = first.nextElementSibling?.querySelector('.select2-selection');
                (visibleSelect || first).focus();
            }
        }, true);
        enhance();
        form.dataset.lineCardsReady = '1';
        const observer = new MutationObserver(() => { observer.disconnect(); enhance(); observer.observe(form, { childList: true, subtree: true }); });
        observer.observe(form, { childList: true, subtree: true });
    });
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', initialize) : initialize();
})();
