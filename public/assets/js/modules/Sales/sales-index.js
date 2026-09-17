(function ($) {
    'use strict';
    $(function () {
        const table = $('#sales-cycle-table');
        if (!table.length) return;
        const filters = document.getElementById('sales-index-filter-form');
        const grid = $.fn.DataTable.isDataTable(table[0])
            ? table.DataTable()
            : table.DataTable(window.AppDataTables.options({
                processing: true, serverSide: true, order: [[0, 'desc']],
                ajax: {url: table.data('url'), data: data => { data.trash = $('#trash_filter').val() || 'active'; new FormData(filters).forEach((value, key) => { data[key] = value; }); }},
                columns: [
                    {data:'doc_num'}, {data:'date'}, {data:'customer', orderable:false},
                    {data:'status'}, {data:'amount', orderable:false}, {data:'actions', orderable:false, searchable:false}
                ],
                drawCallback: () => window.AppDataTables.applyFalconEnhancements(document)
            }));
        $('#trash_filter').off('change.salesIndex').on('change.salesIndex', () => grid.ajax.reload());
        $(document).off('click.salesIndex', '.js-sales-index-action').on('click.salesIndex', '.js-sales-index-action', async function (event) {
            event.preventDefault();
            event.stopPropagation();
            const button = $(this);
            if (button.prop('disabled') || button.data('processing')) return;
            const messages = window.salesIndexMessages || {};
            const requiresReason = button.attr('data-reason') === '1';
            const confirmationOptions = {
                title: button.text().trim(), icon: 'question', showCancelButton: true,
                confirmButtonText: messages.confirm, cancelButtonText: messages.cancel,
                ...(requiresReason ? {input: 'textarea', inputLabel: messages.reason, inputValidator: value => !String(value || '').trim() ? messages.reason : undefined} : {})
            };

            button.data('processing', true).prop('disabled', true);
            const confirmation = window.AppAlerts && typeof window.AppAlerts.confirm === 'function'
                ? await window.AppAlerts.confirm(confirmationOptions)
                : {isConfirmed: window.confirm(confirmationOptions.title), value: ''};
            if (!confirmation.isConfirmed) {
                button.data('processing', false).prop('disabled', false);
                return;
            }
            $('#sales-index-error').addClass('d-none').text('');
            $.ajax({url: button.data('url'), method: button.data('method') || 'POST',
                headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                data: {status: button.data('status') || undefined, reason: requiresReason ? confirmation.value : undefined}
            }).done(response => {
                window.AppAlerts.toast('success', response.message || messages.saved);
                grid.ajax.reload(null, false);
            }).fail(xhr => {
                const response = xhr.responseJSON || {};
                const validationMessage = Object.values(response.errors || {}).flat().find(Boolean);
                const message = validationMessage || response.message || messages.error;
                $('#sales-index-error').removeClass('d-none').text(message);
                window.AppAlerts.toast('error', message);
            }).always(() => button.data('processing', false).prop('disabled', false));
        });
        $(filters).off('.salesIndex')
            .on('submit.salesIndex', event => {event.preventDefault(); grid.ajax.reload();})
            .on('reset.salesIndex', () => setTimeout(() => grid.ajax.reload(), 0));
    });
})(window.jQuery);
