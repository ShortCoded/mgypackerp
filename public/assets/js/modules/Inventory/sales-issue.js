(function ($, document) {
  'use strict';

  const form = document.querySelector('[data-sales-issue-form]');
  if (!form) return;
  const store = form.querySelector('[data-sales-issue-store]');
  const order = form.querySelector('[data-sales-issue-order]');
  const preview = form.querySelector('[data-sales-issue-preview]');
  const submit = form.querySelector('[data-sales-issue-submit]');
  const date = form.querySelector('[name="document_date"]');
  const labels = JSON.parse(document.querySelector('[data-sales-issue-labels]')?.textContent || '{}');
  let requestNumber = 0;
  let previousStore = store.value;
  let oldSelectionsApplied = false;

  function clearPreview() {
    preview.replaceChildren();
    submit.disabled = true;
  }

  function loadOrder() {
    const current = ++requestNumber;
    clearPreview();
    if (!store.value || !order.value) return;
    const url = String(order.dataset.detailsUrl || '').replace('__ORDER__', encodeURIComponent(order.value));
    $.getJSON(url, { branch_store_uuid: store.value, document_date: date?.value }).done(function (response) {
      if (current !== requestNumber) return;
      const data = response.data || {};
      if (!Array.isArray(data.lines) || data.lines.length === 0) return;

      const table = document.createElement('table');
      table.className = 'table table-sm table-bordered align-middle mb-0';
      const head = document.createElement('thead');
      const heading = document.createElement('tr');
      [labels.product, labels.required, labels.available, labels.status].forEach(function (value) {
        const cell = document.createElement('th');
        cell.textContent = value || '';
        heading.appendChild(cell);
      });
      head.appendChild(heading);
      table.appendChild(head);
      const body = document.createElement('tbody');
      data.lines.forEach(function (line) {
        const row = document.createElement('tr');
        [line.product, `${line.quantity} ${line.unit || ''}`, `${line.available} ${line.unit || ''}`, line.enough ? labels.enough : labels.shortage].forEach(function (value) {
          const cell = document.createElement('td');
          cell.textContent = value || '';
          row.appendChild(cell);
        });
        if (!line.enough) row.classList.add('table-warning');
        body.appendChild(row);
      });
      table.appendChild(body);
      const region = document.createElement('div');
      region.className = 'table-responsive mt-3';
      region.appendChild(table);
      preview.appendChild(region);
      if (data.requires_specific_layer) {
        data.lines.forEach(function (line, index) {
          if (line.requires_specific_layer === false) return;
          const fragment = form.querySelector('[data-sales-issue-layer-group]').content.cloneNode(true);
          const group = fragment.firstElementChild;
          group.querySelector('[data-layer-product]').textContent = `${line.product} — ${line.base_quantity_display || line.base_quantity} ${line.base_unit || ''}`;
          const configure = function (container) {
            container.querySelectorAll('[name]').forEach(function (field) {
              field.name = field.name.replace('__INDEX__', index);
              if (field.value === '__LINE_ID__') field.value = line.invoice_line_id;
            });
            container.querySelectorAll('select').forEach(function (field) {
              const layerUrl = new URL(field.dataset.url, window.location.origin);
              layerUrl.searchParams.set('branch_store_uuid', store.value);
              layerUrl.searchParams.set('product_doc_num', line.product_doc_num);
              layerUrl.searchParams.set('document_date', date?.value || '');
              field.dataset.url = layerUrl.toString();
            });
          };
          configure(group);
          configure(group.querySelector('template').content);
          const oldRows = !oldSelectionsApplied && labels.old_order === order.value && labels.old_store === store.value
            ? labels.old_layer_selections?.[line.invoice_line_id] : null;
          if (Array.isArray(oldRows) && oldRows.length) {
            const rows = group.querySelector('[data-material-layer-rows]');
            rows.replaceChildren();
            oldRows.forEach(function (selection, sliceIndex) {
              const row = group.querySelector('template').content.cloneNode(true);
              row.querySelectorAll('[name]').forEach(function (field) { field.name = field.name.replace('__SLICE__', sliceIndex); });
              row.querySelector('select').appendChild(new Option(selection.text, selection.layer_id, true, true));
              row.querySelector('input[data-numeric-input]').value = selection.quantity;
              rows.appendChild(row);
            });
            group.dataset.nextSlice = oldRows.length;
          }
          preview.appendChild(group);
          window.AppSelect2Ajax?.init(group);
          window.AppNumbers?.refresh(group);
        });
      }
      oldSelectionsApplied = true;
      submit.disabled = !data.can_issue;
    }).fail(function () {
      if (current !== requestNumber) return;
      preview.textContent = labels.load_failed || '';
    });
  }

  $(store).on('change', function () {
    if (previousStore && previousStore !== store.value) {
      $(order).val(null).trigger('change.select2');
    }
    previousStore = store.value;
    ++requestNumber;
    clearPreview();
    loadOrder();
  });
  $(order).on('change', loadOrder);
  $(date).on('change', loadOrder);
  loadOrder();
})(jQuery, document);
