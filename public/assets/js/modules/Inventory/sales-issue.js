(function ($, document) {
  'use strict';

  const form = document.querySelector('[data-sales-issue-form]');
  if (!form) return;
  const store = form.querySelector('[data-sales-issue-store]');
  const order = form.querySelector('[data-sales-issue-order]');
  const preview = form.querySelector('[data-sales-issue-preview]');
  const submit = form.querySelector('[data-sales-issue-submit]');
  const labels = JSON.parse(document.querySelector('[data-sales-issue-labels]')?.textContent || '{}');
  let requestNumber = 0;
  let previousStore = store.value;

  function clearPreview() {
    preview.replaceChildren();
    submit.disabled = true;
  }

  function loadOrder() {
    const current = ++requestNumber;
    clearPreview();
    if (!store.value || !order.value) return;
    const url = String(order.dataset.detailsUrl || '').replace('__ORDER__', encodeURIComponent(order.value));
    $.getJSON(url, { branch_store_uuid: store.value }).done(function (response) {
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
  loadOrder();
})(jQuery, document);
