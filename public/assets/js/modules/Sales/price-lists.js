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
  const messages = window.priceListFormMessages || {};
  const clipboardScope = {
    actor_id: String(form?.getAttribute('data-clipboard-actor-id') || ''),
    company_id: String(form?.getAttribute('data-clipboard-company-id') || '')
  };
  const clipboardKey = 'mgypack.priceLists.lineClipboard.' + clipboardScope.actor_id + '.' + clipboardScope.company_id;
  if (!body || !template) return;

  function toast(icon, title) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, title);
    }
  }

  function field(row, suffix) {
    return row.querySelector('[name$="[' + suffix + ']"]');
  }

  function appendLine(line) {
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

    body.appendChild(row);
    window.AppSelect2Ajax?.init(row);
    discountType?.dispatchEvent(new Event('change', { bubbles: true }));

    return row;
  }

  function copiedLines() {
    return Array.from(body.querySelectorAll('[data-price-list-line]')).map(function (row) {
      const product = field(row, 'product_doc_num');
      const selected = product?.selectedOptions?.[0];

      return {
        product_doc_num: String(product?.value || '').trim(),
        product_text: String(selected?.textContent || '').trim(),
        unit_price: String(field(row, 'unit_price')?.value || '').trim(),
        allowed_discount_type: String(field(row, 'allowed_discount_type')?.value || '').trim(),
        allowed_discount_value: String(field(row, 'allowed_discount_value')?.value || '0').trim()
      };
    }).filter(function (line) { return line.product_doc_num !== ''; });
  }

  function storeClipboard(lines) {
    try {
      window.sessionStorage.setItem(clipboardKey, JSON.stringify({ scope: clipboardScope, lines: lines }));
      return true;
    } catch (error) {
      return false;
    }
  }

  function loadClipboard() {
    try {
      const payload = JSON.parse(window.sessionStorage.getItem(clipboardKey) || '{}');
      const scoped = payload?.scope?.actor_id === clipboardScope.actor_id
        && payload?.scope?.company_id === clipboardScope.company_id;
      if (!scoped || !Array.isArray(payload.lines)) return [];

      return payload.lines.filter(function (line) {
        return line && typeof line === 'object'
          && typeof line.product_doc_num === 'string'
          && typeof line.product_text === 'string'
          && typeof line.unit_price === 'string'
          && typeof line.allowed_discount_type === 'string'
          && typeof line.allowed_discount_value === 'string';
      }).map(function (line) {
        return {
          product_doc_num: line.product_doc_num,
          product_text: line.product_text,
          unit_price: line.unit_price,
          allowed_discount_type: line.allowed_discount_type,
          allowed_discount_value: line.allowed_discount_value
        };
      });
    } catch (error) {
      return [];
    }
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

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-price-list-add]')) {
      const mode = form?.getAttribute('data-mode');
      if (mode === 'view') return;

      appendLine();
      renumber();
    }

    if (event.target.closest('[data-price-list-copy]')) {
      const lines = copiedLines();
      if (lines.length === 0) {
        toast('warning', messages.nothingToCopy || '');
        return;
      }

      if (storeClipboard(lines)) {
        toast('success', messages.copied || '');
      }
    }

    if (event.target.closest('[data-price-list-paste]')) {
      if (form?.getAttribute('data-mode') === 'view') return;
      const lines = loadClipboard();
      if (lines.length === 0) {
        toast('warning', messages.nothingToPaste || '');
        return;
      }

      const existing = new Set(copiedLines().map(function (line) { return line.product_doc_num; }));
      let pasted = 0;
      let skipped = 0;

      lines.forEach(function (line) {
        const productDocNum = String(line?.product_doc_num || '').trim();
        if (productDocNum === '' || existing.has(productDocNum)) {
          skipped += 1;
          return;
        }

        appendLine(line);
        existing.add(productDocNum);
        pasted += 1;
      });

      Array.from(body.querySelectorAll('[data-price-list-line]')).forEach(function (row) {
        if (body.children.length > 1 && String(field(row, 'product_doc_num')?.value || '').trim() === '') {
          row.remove();
        }
      });
      renumber();

      if (pasted > 0) toast('success', messages.pasted || '');
      if (skipped > 0) toast('warning', String(messages.duplicatesSkipped || '').replace(':count', String(skipped)));
    }
    const remove = event.target.closest('[data-price-list-remove]');
    if (remove && body.children.length > 1) {
      remove.closest('[data-price-list-line]').remove();
      renumber();
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
