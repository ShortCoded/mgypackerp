document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-posted-invoice-correction], [data-posted-document-correction]').forEach(form => {
        const lines = form.querySelector('[data-correction-lines]');
        window.jQuery?.(form).on('select2:select', '[data-correction-product]', event => {
            const data = event.params?.data;
            const unit = event.target.closest('[data-correction-line]')?.querySelector('[data-correction-unit]');
            if (unit) {
                const options = data?.unit_options || data?.units || [];
                unit.replaceChildren(...options.map(option => new Option(option.text, option.id, false, option.id === data.unitDocNum)));
                window.jQuery?.(unit).trigger?.('change');
            }
            const row = event.target.closest('[data-correction-line]');
            const layer = row?.querySelector('[data-correction-layer]');
            if (layer) {
                const url = new URL(layer.dataset.layerUrl, window.location.origin);
                url.searchParams.set('branch_store_uuid', form.dataset.storeUuid);
                url.searchParams.set('product_doc_num', data?.id || '');
                url.searchParams.set('document_date', form.querySelector('[name="posting_date"]').value);
                url.searchParams.set('stock_status', form.dataset.stockStatus);
                const select = window.jQuery(layer);
                if (select.hasClass('select2-hidden-accessible')) { select.select2('destroy'); }
                select.removeData('select2AjaxInitialized');
                layer.replaceChildren();
                layer.dataset.url = url.toString();
                select.data('url', url.toString());
                window.AppSelect2Ajax?.init(row);
            }
            row?.querySelectorAll('[data-correction-item-metadata]').forEach(input => { input.value = ''; });
        });
        const renumber = () => lines.querySelectorAll('[data-correction-line]').forEach((row, index) => {
            row.querySelectorAll('[name]').forEach(input => {
                input.name = input.name.replace(/lines\[[^\]]+\]/, `lines[${index}]`);
            });
        });
        form.addEventListener('click', event => {
            if (event.target.closest('[data-correction-add]')) {
                const template = form.querySelector('[data-correction-template]');
                lines.append(template.content.cloneNode(true));
                renumber();
                window.AppSelect2Ajax?.init(lines.lastElementChild);
            }
            const remove = event.target.closest('[data-correction-remove]');
            if (remove && lines.children.length > 1) {
                remove.closest('[data-correction-line]').remove();
                renumber();
            }
        });
    });
});
