(function ($) {
    'use strict';
    $(function () {
        const table = $('#sales-cycle-table');
        if (!table.length || $.fn.DataTable.isDataTable(table[0])) return;
        const filters = document.getElementById('sales-index-filter-form');
        const grid = table.DataTable(window.AppDataTables.options({
            processing: true, serverSide: true, order: [[0, 'desc']],
            ajax: {url: table.data('url'), data: data => { new FormData(filters).forEach((value, key) => { data[key] = value; }); }},
            columns: [
                {data:'doc_num'}, {data:'date'}, {data:'customer', orderable:false},
                {data:'status'}, {data:'amount', orderable:false}, {data:'actions', orderable:false, searchable:false}
            ],
            drawCallback: () => window.AppDataTables.applyFalconEnhancements(document)
        }));
        filters.addEventListener('submit', event => {event.preventDefault(); grid.ajax.reload();});
        filters.addEventListener('reset', () => setTimeout(() => grid.ajax.reload(), 0));
    });
})(window.jQuery);
