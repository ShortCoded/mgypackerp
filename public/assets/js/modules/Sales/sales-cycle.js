(function ($, window, document) {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const messages = window.salesCycleMessages || {};

  function showAlert(form, message, errors) {
    let alert = form.querySelector('.js-sales-form-alert');
    if (!alert) {
      alert = document.createElement('div');
      alert.className = 'alert alert-danger js-sales-form-alert d-none';
      alert.setAttribute('role', 'alert');
      form.prepend(alert);
    }
    form.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
    form.querySelectorAll('[data-error-for]').forEach((field) => { field.textContent = ''; });

    Object.keys(errors || {}).forEach((name) => {
      const inputName = name.replace(/\.([^.]+)/g, '[$1]');
      const input = form.querySelector('[name="' + inputName + '"]');
      if (input) input.classList.add('is-invalid');
      const error = form.querySelector('[data-error-for="' + name + '"]');
      if (error) error.textContent = Array.isArray(errors[name]) ? errors[name][0] : errors[name];
    });

    if (alert) {
      const details = Object.values(errors || {}).flat().filter(Boolean);
      alert.textContent = details.join(' · ') || message || messages.actionFailed || 'The action could not be completed.';
      alert.classList.remove('d-none');
      alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function notify(message) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast('success', message);
    }
  }

  async function submitForm(form) {
    const submitter = form.querySelector('[type="submit"]');
    if (form.dataset.submitting === '1') return;
    form.dataset.submitting = '1';
    if (submitter) submitter.disabled = true;

    try {
      const response = await fetch(form.action, {
        method: (form.method || 'POST').toUpperCase(),
        headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        showAlert(form, payload.message, payload.errors);
        return;
      }

      notify(payload.message || messages.saved || 'Saved successfully.');
      const action = form.querySelector('[name="submit_action"]')?.value;
      let url = payload.redirect || payload.data?.url || form.dataset.successUrl;
      if (action === 'save_back' && form.dataset.indexUrl) url = form.dataset.indexUrl;
      if (action === 'save' && form.dataset.createUrl && !form.querySelector('[name="_method"]')) url = form.dataset.createUrl;
      if (action === 'save' && form.querySelector('[name="_method"]')) url = window.location.href;
      if (action === 'save_edit' && payload.data?.doc_num && form.dataset.editUrl) url = form.dataset.editUrl.replace('__DOCUMENT__', encodeURIComponent(payload.data.doc_num));
      if (url) {
        window.location.assign(url);
      } else {
        window.location.reload();
      }
    } catch (error) {
      showAlert(form, messages.unexpectedError || 'Unexpected browser error.');
    } finally {
      form.dataset.submitting = '0';
      if (submitter) submitter.disabled = false;
    }
  }

  function bindForms() {
    const requestType = document.querySelector('[data-request-type]');
    const syncRequestType = () => {
      const customer = document.querySelector('[name="customer_doc_num"]');
      if (!requestType || !customer) return;
      const internal = requestType.value === 'internal';
      customer.disabled = internal; customer.required = !internal;
      customer.closest('[class*="col-"]').classList.toggle('d-none', internal);
    };
    requestType?.addEventListener('change', syncRequestType); syncRequestType();
    document.querySelectorAll('.js-sales-cycle-form, .js-sales-cycle-action').forEach((form) => {
      if (form.dataset.salesBound === '1') return;
      form.dataset.salesBound = '1';
      if (!form.querySelector('[name="_submission_token"]')) {
        const token = document.createElement('input'); token.type = 'hidden'; token.name = '_submission_token'; token.value = crypto.randomUUID(); form.append(token);
      }
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        let action = form.querySelector('[name="submit_action"]');
        if (!action) {action = document.createElement('input'); action.type = 'hidden'; action.name = 'submit_action'; form.append(action);}
        action.value = event.submitter?.dataset.submitAction || 'save';
        submitForm(form);
      });
    });
  }

  function reindex(container, prefix) { window.AppLineItemCards.reindex(container, prefix); }

  function initializeWidgets(root) {
    if (window.AppSelect2Ajax?.init) window.AppSelect2Ajax.init(root || document);
    if (window.AppDatePicker?.init) window.AppDatePicker.init(root || document);
    if (window.AppSelect2?.init) window.AppSelect2.init(root || document);
  }

  function populateUnits(row) {
    const product = row.querySelector('[name$="[product_doc_num]"]')?.value || '';
    const unit = row.querySelector('.js-sales-unit');
    if (!unit) return;
    const previous = unit.value;
    unit.innerHTML = '';
    (window.salesProductUnits?.[product] || []).forEach((option) => {
      const element = document.createElement('option');
      element.value = option.id;
      element.textContent = option.text;
      if (option.id === previous) element.selected = true;
      unit.appendChild(element);
    });
  }

  async function suggestPrice(row) {
    const form = row?.closest('form');
    const price = row?.querySelector('[name$="[unit_price]"]');
    if (!price || !messages.priceUrl || row.dataset.priceLocked === '1') return;
    const display = row.querySelector('[data-price-display]');
    const showPriceHint = (message, isError) => {
      let hint = row.querySelector('[data-price-source]');
      if (!message) {
        hint?.remove();
        return;
      }
      if (!hint) {
        hint = document.createElement('small');
        hint.dataset.priceSource = '';
        price.after(hint);
      }
      hint.className = isError ? 'd-block text-danger' : 'd-block text-muted';
      hint.textContent = message;
    };
    const resetPrice = (message, isError) => {
      price.value = '';
      delete row.dataset.suggestedPrice;
      row.dataset.maximumDiscount = '0';
      if (display) display.textContent = messages.emptyPrice || '—';
      showPriceHint(message, isError);
      calculateLineTotal(row);
    };
    const values = {
      customer_doc_num: form.querySelector('[name="customer_doc_num"]')?.value || '',
      currency_doc_num: form.querySelector('[name="currency_doc_num"]')?.value || '',
      product_doc_num: row.querySelector('[name$="[product_doc_num]"]')?.value || '',
      unit_doc_num: row.querySelector('[name$="[unit_doc_num]"]')?.value || '',
      quantity: row.querySelector('[name$="[quantity]"]')?.value || '1',
      document_date: form.querySelector('[name="quotation_date"], [name="order_date"], [name="invoice_date"]')?.value || ''
    };
    if ([values.customer_doc_num, values.currency_doc_num, values.product_doc_num, values.unit_doc_num].some(value => !value)) {
      delete row.dataset.priceLookup;
      resetPrice('', false);
      return;
    }
    const key = new URLSearchParams(values).toString();
    if (row.dataset.priceLookup === key) return;
    row.dataset.priceLookup = key;
    try {
      const response = await fetch(messages.priceUrl + '?' + key, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      const payload = await response.json();
      if (!response.ok) {
        resetPrice(payload.message || messages.unpriced || 'Unpriced', true);
        return;
      }
      const suggestion = payload.data;
      if (row.dataset.priceLookup !== key) return;
      if (!suggestion) {
        resetPrice(payload.message || messages.unpriced || 'Unpriced', true);
        return;
      }
      const sourceLabel = suggestion.scope === 'customer'
        ? (messages.customerPriceList || 'Customer price list')
        : (messages.generalPriceList || 'General price list');
      showPriceHint(sourceLabel + ' · ' + suggestion.source, false);
      price.value = suggestion.unit_price;
      row.dataset.suggestedPrice = suggestion.unit_price;
      row.dataset.maximumDiscount = suggestion.maximum_discount_amount || '0';
      if (display) display.textContent = window.AppNumbers.format(suggestion.unit_price);
      if (row.matches('.js-quotation-line')) price.dispatchEvent(new Event('input', {bubbles:true}));
      else calculateLineTotal(row);
    } catch (_) {
      resetPrice(messages.unexpectedError || 'Unexpected browser error.', true);
    }
  }

  window.AppSalesPricing = {suggest: suggestPrice};

  function calculateLineTotal(row) {
    const number = (selector) => window.AppNumbers.number(row.querySelector(selector)?.value || '0', 0);
    const total = (number('.js-sales-quantity') * number('.js-sales-price'))
      - number('.js-sales-discount') + number('.js-sales-tax');
    const output = row.querySelector('[data-sales-line-total]');
    if (output) output.textContent = window.AppNumbers.format(total);
    calculateDocumentSummary(row.closest('form'));
  }

  function calculateDocumentSummary(form) {
    if (!form?.matches('[data-sales-document-summary], [data-sales-request-form]')) return;
    const rows = Array.from(form.querySelectorAll('[data-sales-lines] [data-sales-line]'));
    const products = new Set();
    let quantity = 0;
    let subtotal = 0;
    let discounts = 0;
    let tax = 0;
    let total = 0;
    rows.forEach((row) => {
      const product = row.querySelector('[name$="[product_doc_num]"]')?.value || '';
      const rowQuantity = window.AppNumbers.number(row.querySelector('.js-sales-quantity')?.value || '0', 0);
      const rowPrice = window.AppNumbers.number(row.querySelector('.js-sales-price')?.value || '0', 0);
      const rowDiscount = window.AppNumbers.number(row.querySelector('.js-sales-discount')?.value || '0', 0);
      const rowTax = window.AppNumbers.number(row.querySelector('.js-sales-tax')?.value || '0', 0);
      if (product) products.add(product);
      quantity += rowQuantity;
      subtotal += rowQuantity * rowPrice;
      discounts += rowDiscount;
      tax += rowTax;
      total += (rowQuantity * rowPrice) - rowDiscount + rowTax;
    });
    const set = (selector, value) => {
      const element = form.querySelector(selector);
      if (element) element.textContent = value;
    };
    set('[data-sales-summary-lines]', String(rows.length));
    set('[data-sales-summary-products]', String(products.size));
    set('[data-sales-summary-quantity]', window.AppNumbers.format(quantity));
    set('[data-sales-summary-subtotal]', window.AppNumbers.format(subtotal));
    set('[data-sales-summary-discount]', window.AppNumbers.format(discounts));
    set('[data-sales-summary-taxable]', window.AppNumbers.format(subtotal - discounts));
    set('[data-sales-summary-tax]', window.AppNumbers.format(tax));
    set('[data-sales-summary-total]', window.AppNumbers.format(total));
    const currency = form.querySelector('[name="currency_doc_num"]');
    set('[data-sales-summary-currency]', currency?.selectedOptions?.[0]?.textContent?.trim() || '');
  }

  function bindOrderGrid() {
    const lines = document.querySelector('[data-sales-lines]');
    const schedules = document.querySelector('[data-sales-schedules]');
    document.querySelectorAll('[data-sales-add-line]').forEach(button => button.addEventListener('click', () => {
      const row = window.AppLineItemCards.append(lines, document.querySelector('#sales-order-line-template'), 'lines');
      initializeWidgets(row);
      calculateLineTotal(row);
    }));
    document.querySelector('[data-sales-add-schedule]')?.addEventListener('click', () => {
      const index = schedules.querySelectorAll('tr').length;
      schedules.insertAdjacentHTML('beforeend', document.querySelector('#sales-schedule-template').innerHTML.replaceAll('__INDEX__', String(index)));
      reindex(schedules, 'payment_schedules');
      initializeWidgets(schedules.lastElementChild);
    });
    if (typeof $ === 'function') {
      $(document).off('select2:select.salesProduct', '.js-sales-product').on('select2:select.salesProduct', '.js-sales-product', function (event) {
        const row = this.closest('tr'); const data = event.params.data;
        window.salesProductUnits ||= {}; window.salesProductUnits[this.value] = data.units || [];
        populateUnits(row);
        let details = row.querySelector('[data-sales-product-details]');
        if (!details) {details = document.createElement('small'); details.dataset.salesProductDetails = ''; details.className = 'text-600'; this.parentElement.append(details);}
        details.textContent = [data.productData?.color, data.productData?.model, data.productData?.size].filter(Boolean).join(' · ');
        suggestPrice(row); calculateLineTotal(row);
      });
    }
    document.addEventListener('change', (event) => {
      if (event.target.matches('.js-sales-product') && !event.target.matches('.js-select2-ajax')) populateUnits(event.target.closest('tr'));
      if (event.target.matches('.js-sales-unit, .js-sales-product:not(.js-select2-ajax)')) suggestPrice(event.target.closest('tr'));
      if (event.target.matches('[name="customer_doc_num"], [name="currency_doc_num"], [name="order_date"], [name="invoice_date"]')) {
        lines?.querySelectorAll('[data-sales-line]').forEach(suggestPrice);
        calculateDocumentSummary(event.target.closest('form'));
      }
    });
    const source = document.querySelector('[data-sales-order-source]');
    const openSource = () => {
      if (!source?.dataset.createUrl) return;
      const url = new URL(source.dataset.createUrl, window.location.origin);
      if (source.value) url.searchParams.set('source_request_doc_num', source.value);
      window.location.assign(url.toString());
    };
    if (typeof $ === 'function') {
      $(source).off('.salesOrderSource')
        .on('select2:select.salesOrderSource select2:clear.salesOrderSource', openSource);
    } else {
      source?.addEventListener('change', openSource);
    }
    document.addEventListener('input', (event) => {
      if (event.target.matches('.js-sales-quantity, .js-sales-price, .js-sales-discount, .js-sales-tax')) {
        calculateLineTotal(event.target.closest('tr'));
        if (event.target.matches('.js-sales-quantity')) suggestPrice(event.target.closest('tr'));
      }
    });
    document.addEventListener('click', (event) => {
      const duplicate = event.target.closest('[data-sales-duplicate-row]');
      if (duplicate && lines) {
        const source = duplicate.closest('tr');
        const row = window.AppLineItemCards.append(lines, document.querySelector('#sales-order-line-template'), 'lines', source);
        initializeWidgets(row);
        calculateLineTotal(row);
        return;
      }
      const button = event.target.closest('[data-sales-remove-row]');
      if (!button) return;
      const row = button.closest('tr');
      const body = row.parentElement;
      const form = row.closest('form');
      if (body === lines && lines.querySelectorAll('tr').length === 1) return;
      window.AppLineItemCards.remove(row, body === lines ? 'lines' : 'payment_schedules', body === lines ? 1 : 0);
      calculateDocumentSummary(form);
      });
    lines?.querySelectorAll('[data-sales-line]').forEach((row) => {
      calculateLineTotal(row);
      suggestPrice(row);
    });
    calculateDocumentSummary(lines?.closest('form'));
  }

  function setPaymentMethod() {
    const method = document.querySelector('[data-sales-payment-method]')?.value;
    if (!method) return;
    const referenceLabel = document.querySelector('[data-payment-reference-label]');
    if (referenceLabel) referenceLabel.textContent = method === 'cheque' ? referenceLabel.dataset.chequeLabel || 'Cheque number' : referenceLabel.dataset.bankLabel || 'Bank reference';
    document.querySelector('[data-reference-required]')?.classList.toggle('d-none', method !== 'cheque');
    document.querySelectorAll('[data-payment-source]').forEach((container) => {
      const source = container.dataset.paymentSource;
      const show = source === 'reference'
        ? ['bank', 'transfer', 'cheque'].includes(method)
        : source === 'bank'
          ? ['bank', 'transfer', 'cheque'].includes(method)
          : source === method;
      container.classList.toggle('d-none', !show);
      container.querySelectorAll('input, select').forEach((field) => {
        field.disabled = !show;
        const requiredFor = (field.dataset.requiredFor || '').split(',').filter(Boolean);
        field.required = show && requiredFor.includes(method);
      });
    });
  }

  function bindReceipt() {
    document.querySelector('[data-sales-payment-method]')?.addEventListener('change', setPaymentMethod);
    setPaymentMethod();
    document.querySelectorAll('[data-receipt-allocation-toggle]').forEach((toggle) => {
      toggle.addEventListener('change', () => {
        toggle.closest('tr').querySelectorAll('input:not([type="checkbox"])').forEach((field) => { field.disabled = !toggle.checked; });
      });
    });
    document.querySelector('#customer_doc_num')?.addEventListener('change', (event) => {
      document.querySelectorAll('[data-receipt-allocation-row]').forEach((row) => {
        row.classList.toggle('d-none', Boolean(event.target.value) && row.dataset.customer !== event.target.value);
      });
    });
  }

  function bindReturnLines() {
    document.querySelectorAll('[data-sales-return-toggle]').forEach((toggle) => {
      toggle.addEventListener('change', () => {
        toggle.closest('[data-sales-return-line]').querySelectorAll('input:not([type="checkbox"])').forEach((field) => {
          field.disabled = !toggle.checked;
        });
      });
    });
  }

  function bindInvoiceFromOrder() {
    document.querySelectorAll('[data-sales-invoice-from-order]').forEach((form) => {
      const calculate = () => {
        let total = 0;
        form.querySelectorAll('[data-invoice-source-line]').forEach((row) => {
          const sourceQuantity = window.AppNumbers.number(row.dataset.sourceQuantity || '0', 0);
          const sourceTotal = window.AppNumbers.number(row.dataset.sourceTotal || '0', 0);
          const quantity = window.AppNumbers.number(row.querySelector('.js-invoice-quantity')?.value || '0', 0);
          if (sourceQuantity > 0) total += sourceTotal * (quantity / sourceQuantity);
        });
        const schedule = form.querySelector('[data-invoice-schedule-total]');
        if (schedule) schedule.value = total.toFixed(4);
      };
      form.addEventListener('input', (event) => {
        if (event.target.matches('.js-invoice-quantity')) calculate();
      });
      calculate();
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    bindForms();
    bindOrderGrid();
    bindReceipt();
    bindReturnLines();
    bindInvoiceFromOrder();
    initializeWidgets(document);
  });
})(window.jQuery, window, document);
