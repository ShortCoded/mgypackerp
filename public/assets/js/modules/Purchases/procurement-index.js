(function ($) {
    'use strict';
    $(function () {
        const element = document.getElementById('procurement-documents-table');
        if (!element) return;
        const selected = new Set();
        const fields = JSON.parse(element.dataset.fieldKeys);
        const messages = window.procurementIndexMessages;
        const bulk = document.querySelector('[data-bulk-create-order]');
        const sync = () => { if (bulk) { bulk.classList.toggle('d-none', !selected.size); bulk.querySelector('[data-selected-count]').textContent = selected.size; } };
        const table = $(element).DataTable(window.AppDataTables.options({
            processing: true, serverSide: true, responsive: true, stateSave: true,
            ajax: {url: element.dataset.url, data: data => {
                data.trash_filter = $('#trash_filter').val() || 'active';
                $('[data-procurement-filters]').serializeArray().forEach(field => {data[field.name] = field.value;});
            }},
            columns: [{data:'checkbox',orderable:false,searchable:false}, ...fields.map(field => ({data:field, name:field, orderable:!['party','source','lines_count'].includes(field), searchable:false})), {data:'actions',orderable:false,searchable:false}],
            order:[[1,'desc']], language:window.dataTableTranslations,
            drawCallback: function () {selected.clear(); $('[data-procurement-select-all]').prop('checked', false); sync();}
        }));
        $('[data-procurement-filters]').on('submit', function (event) {event.preventDefault(); table.ajax.reload();});
        $('[data-procurement-filters]').on('reset', function () {setTimeout(() => {$(this).find('.js-select2-ajax').val(null).trigger('change'); table.ajax.reload();},0);});
        $('#trash_filter').on('change', () => table.ajax.reload());
        $(element).on('change', '.js-procurement-select', function () {this.checked ? selected.add(this.value) : selected.delete(this.value); sync();});
        $('[data-procurement-select-all]').on('change', function () {$(element).find('.js-procurement-select').prop('checked', this.checked).trigger('change');});
        bulk?.addEventListener('click', () => {const url = new URL(bulk.dataset.bulkCreateOrder, location.href); selected.forEach(value => url.searchParams.append('purchase_requisition_doc_nums[]',value)); location.href = url.href;});
        $(element).on('click', '[data-procurement-action]', async function () {
            const button = this;
            const reasonField = button.dataset.reasonField;
            const result = await Swal.fire({title:messages.confirm, input:reasonField ? 'textarea' : undefined, inputLabel:reasonField ? messages.reason : undefined, showCancelButton:true, confirmButtonText:messages.apply, cancelButtonText:messages.cancel, inputValidator:reasonField ? value => !value?.trim() ? messages.reason : undefined : undefined});
            if (!result.isConfirmed) return;
            button.disabled = true;
            try {
                const response = await fetch(button.dataset.procurementAction, {method:button.dataset.method || 'POST', headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body:JSON.stringify(reasonField ? {[reasonField]:result.value} : {})});
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join('\n') || messages.error);
                table.ajax.reload(null,false);
            } catch(error) {Swal.fire({icon:'error',text:error.message});} finally {button.disabled=false;}
        });
    });
})(window.jQuery);
