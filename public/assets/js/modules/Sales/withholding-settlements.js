(() => {
    const $ = window.jQuery;
    if (!$) return;
    $(function () {
        $('[data-withholding-settlements-table]').each(function () {
            if (!$.fn.DataTable || $.fn.DataTable.isDataTable(this)) return;
            const options = {
                ajax: this.dataset.url,
                processing: true,
                serverSide: true,
                stateSave: true,
                responsive: { details: { type: 'inline', target: 0 } },
                columns: JSON.parse(this.dataset.columns),
                order: [[3, 'desc']],
                createdRow: row => $(row).addClass('btn-reveal-trigger'),
                drawCallback: () => window.AppDataTables?.applyFalconEnhancements?.(document),
            };
            $(this).DataTable(window.AppDataTables?.options ? window.AppDataTables.options(options) : options);
        });
    });
})();
