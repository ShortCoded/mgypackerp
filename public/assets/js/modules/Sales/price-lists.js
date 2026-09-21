(function (window, document) {
  'use strict';

  document.addEventListener('click', function (event) {
    const submitButton = event.target.closest('.js-price-list-submit-action[data-submit-action]');
    if (!submitButton) return;

    const form = submitButton.closest('form');
    const submitAction = form?.querySelector('[name="submit_action"]');
    if (submitAction) submitAction.value = submitButton.dataset.submitAction || 'save';
  });

  const body = document.querySelector('[data-price-list-lines]');
  const template = document.querySelector('#price-list-line-template');
  const form = document.querySelector('[data-price-list-form]');
  if (!body || !template) return;

  function field(row, suffix) {
    return row.querySelector('[name$="[' + suffix + ']"]');
  }

  function lineValues(row) {
    const product = field(row, 'product_doc_num');
    const selected = product?.selectedOptions?.[0];

    return {
      product_doc_num: String(product?.value || '').trim(),
      product_text: String(selected?.textContent || '').trim(),
      unit_price: String(field(row, 'unit_price')?.value || '').trim(),
      allowed_discount_type: String(field(row, 'allowed_discount_type')?.value || '').trim(),
      allowed_discount_value: String(field(row, 'allowed_discount_value')?.value || '0').trim()
    };
  }

  function focusProduct(row) {
    const product = field(row, 'product_doc_num');
    const select2Selection = product?.nextElementSibling?.querySelector('.select2-selection');
    (select2Selection || product)?.focus();
  }

  function createLine(line, afterRow) {
    const wrapper = document.createElement('tbody');
    wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(body.children.length));
    const row = wrapper.firstElementChild;
    const product = field(row, 'product_doc_num');
    const unitPrice = field(row, 'unit_price');
    const discountType = field(row, 'allowed_discount_type');
    const discountValue = field(row, 'allowed_discount_value');

    if (line && product) {
      const option = new Option(line.product_text || line.product_doc_num, line.product_doc_num, true, true);
      product.replaceChildren(option);
      if (unitPrice) unitPrice.value = line.unit_price || '';
      if (discountType) discountType.value = line.allowed_discount_type || '';
      if (discountValue) discountValue.value = line.allowed_discount_value || '0';
    }

    if (afterRow && afterRow.parentElement === body) {
      afterRow.insertAdjacentElement('afterend', row);
    } else {
      body.appendChild(row);
    }

    window.AppSelect2Ajax?.init(row);
    window.AppNumbers?.refresh(row);
    discountType?.dispatchEvent(new Event('change', { bubbles: true }));
    renumber();
    focusProduct(row);

    return row;
  }

  function hardDisableReadOnly() {
    if (form?.getAttribute('data-mode') !== 'view') return;

    form.querySelectorAll('fieldset input, fieldset select, fieldset textarea, fieldset button').forEach(function (control) {
      control.disabled = true;
      control.setAttribute('aria-disabled', 'true');
    });

    if (window.jQuery) {
      window.jQuery(form).find('fieldset select').prop('disabled', true).trigger('change.select2');
    }

    form.addEventListener('select2:opening', function (event) { event.preventDefault(); });
  }

  function renumber() {
    body.querySelectorAll('[data-price-list-line]').forEach(function (row, index) {
      row.querySelector('[data-line-number]').textContent = String(index + 1);
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/lines\[[^\]]+\]/, 'lines[' + index + ']');
      });
    });
  }

  function removeLine(row) {
    if (row && body.children.length > 1) {
      row.remove();
      renumber();
    }
  }

  function shortcutBlockedBySelect2(target) {
    return target?.classList?.contains('select2-search__field')
      || document.querySelector('.select2-container--open') !== null;
  }

  function isAltDuplicate(event) {
    if (event.ctrlKey || event.metaKey || event.shiftKey) return false;
    if (window.AppShortcuts && typeof window.AppShortcuts.isAltShortcut === 'function') {
      return window.AppShortcuts.isAltShortcut(event, ['KeyD'], [68], ['d']);
    }

    return event.altKey && (event.code === 'KeyD' || String(event.key || '').toLowerCase() === 'd');
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-price-list-add]')) {
      const mode = form?.getAttribute('data-mode');
      if (mode === 'view') return;

      createLine();
      return;
    }

    const duplicate = event.target.closest('[data-price-list-duplicate]');
    if (duplicate && form?.getAttribute('data-mode') !== 'view') {
      const sourceRow = duplicate.closest('[data-price-list-line]');
      if (sourceRow) createLine(lineValues(sourceRow), sourceRow);
      return;
    }

    const remove = event.target.closest('[data-price-list-remove]');
    if (remove) removeLine(remove.closest('[data-price-list-line]'));
  });

  form?.addEventListener('keydown', function (event) {
    const row = event.target.closest?.('[data-price-list-line]');
    if (!row || shortcutBlockedBySelect2(event.target)) return;

    if (isAltDuplicate(event)) {
      event.preventDefault();
      event.stopPropagation();
      createLine(lineValues(row), row);
    }
  });

  document.addEventListener('change', function (event) {
    if (!event.target.matches('[data-discount-type]')) return;
    const value = event.target.closest('tr').querySelector('[data-discount-value]');
    value.disabled = event.target.value === '';
    if (value.disabled) value.value = '0';
  });

  body.querySelectorAll('[data-discount-type]').forEach(function (field) { field.dispatchEvent(new Event('change', { bubbles: true })); });
  hardDisableReadOnly();
})(window, document);
