(function ($) {
    'use strict';
    $(function () {
        const table = $('#sales-cycle-table');
        if (!table.length || $.fn.DataTable.isDataTable(table[0])) return;
        const filters = document.getElementById('sales-index-filter-form');
        const grid = table.DataTable(window.AppDataTables.options({
            processing: true, serverSide: true, order: [[0, 'desc']],
            ajax: {url: table.data('url'), data: data => { data.trash = $('#trash_filter').val() || 'active'; new FormData(filters).forEach((value, key) => { data[key] = value; }); }},
            columns: [
                {data:'doc_num'}, {data:'date'}, {data:'customer', orderable:false},
                {data:'status'}, {data:'amount', orderable:false}, {data:'actions', orderable:false, searchable:false}
            ],
            drawCallback: () => window.AppDataTables.applyFalconEnhancements(document)
        }));
        $('#trash_filter').on('change.salesIndex', () => grid.ajax.reload());
        table.on('click.salesIndex', '.js-sales-index-action', async function () {
            const button = $(this);
            if (button.prop('disabled')) return;
            const messages = window.salesIndexMessages || {};
            const requiresReason = button.attr('data-reason') === '1';
            const confirmation = await Swal.fire({
                title: button.text().trim(), icon: 'question', showCancelButton: true,
                confirmButtonText: messages.confirm, cancelButtonText: messages.cancel,
                ...(requiresReason ? {input: 'textarea', inputLabel: messages.reason, inputValidator: value => !value.trim() ? messages.reason : undefined} : {})
            });
            if (!confirmation.isConfirmed) return;
            button.prop('disabled', true);
            $('#sales-index-error').addClass('d-none').text('');
            $.ajax({url: button.data('url'), method: button.data('method') || 'POST',
                headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), Accept: 'application/json'},
                data: {status: button.data('status') || undefined, reason: requiresReason ? confirmation.value : undefined}
            }).done(() => grid.ajax.reload(null, false)).fail(xhr => {
                const response = xhr.responseJSON || {};
                $('#sales-index-error').removeClass('d-none').text(response.message || messages.error);
            }).always(() => button.prop('disabled', false));
        });
        filters.addEventListener('submit' , event => {event.preventDefault(); grid.ajax.reload();});
        filters.addEventListener('reset', () => setTimeout(() => grid.ajax.reload(), 0));
    });
})(window.jQuery);
