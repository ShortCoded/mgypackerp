(function ($, window, document) {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  function showAlert(form, message, errors) {
    const alert = form.querySelector('.js-sales-form-alert');
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
      alert.textContent = details.join(' · ') || message || 'The action could not be completed.';
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

      notify(payload.message || 'Saved successfully.');
      const url = payload.data?.url || form.dataset.successUrl;
      if (url) {
        window.location.assign(url);
      } else {
        window.location.reload();
      }
    } catch (error) {
      showAlert(form, error.message || 'Unexpected browser error.');
    } finally {
      if (submitter) submitter.disabled = false;
    }
  }

  function bindForms() {
    document.querySelectorAll('.js-sales-cycle-form, .js-sales-cycle-action').forEach((form) => {
      if (form.dataset.salesBound === '1') return;
      form.dataset.salesBound = '1';
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        submitForm(form);
      });
    });
  }

  function reindex(container, prefix) {
    container.querySelectorAll('tr').forEach((row, index) => {
      const number = row.querySelector('[data-row-number]');
      if (number) number.textContent = index + 1;
      row.querySelectorAll('[name]').forEach((field) => {
        field.name = field.name.replace(new RegExp('^' + prefix + '\\[[^\\]]+\\]'), prefix + '[' + index + ']');
      });
    });
  }

  function initializeWidgets(root) {
    if (window.AppDatePicker?.init) window.AppDatePicker.init(root || document);
    if (window.AppSelect2?.init) window.AppSelect2.init(root || document);
  }

  function populateUnits(row) {
    const product = row.querySelector('.js-sales-product')?.value || '';
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

  function calculateLineTotal(row) {
    const number = (selector) => Number.parseFloat(row.querySelector(selector)?.value || '0') || 0;
    const total = (number('.js-sales-quantity') * number('.js-sales-price'))
      - number('.js-sales-discount') + number('.js-sales-tax');
    const output = row.querySelector('[data-sales-line-total]');
    if (output) output.textContent = total.toFixed(2);
  }

  function bindOrderGrid() {
    const lines = document.querySelector('[data-sales-lines]');
    const schedules = document.querySelector('[data-sales-schedules]');
    document.querySelector('[data-sales-add-line]')?.addEventListener('click', () => {
      const index = lines.querySelectorAll('tr').length;
      const html = document.querySelector('#sales-order-line-template').innerHTML.replaceAll('__INDEX__', String(index));
      lines.insertAdjacentHTML('beforeend', html);
      const row = lines.lastElementChild;
      initializeWidgets(row);
      calculateLineTotal(row);
    });
    document.querySelector('[data-sales-add-schedule]')?.addEventListener('click', () => {
      const index = schedules.querySelectorAll('tr').length;
      schedules.insertAdjacentHTML('beforeend', document.querySelector('#sales-schedule-template').innerHTML.replaceAll('__INDEX__', String(index)));
      reindex(schedules, 'payment_schedules');
      initializeWidgets(schedules.lastElementChild);
    });
    document.addEventListener('change', (event) => {
      if (event.target.matches('.js-sales-product')) populateUnits(event.target.closest('tr'));
    });
    document.addEventListener('input', (event) => {
      if (event.target.matches('.js-sales-quantity, .js-sales-price, .js-sales-discount, .js-sales-tax')) {
        calculateLineTotal(event.target.closest('tr'));
      }
    });
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-sales-remove-row]');
      if (!button) return;
      const row = button.closest('tr');
      const body = row.parentElement;
      if (body === lines && lines.querySelectorAll('tr').length === 1) return;
      row.remove();
      if (body === lines) reindex(lines, 'lines');
      if (body === schedules) reindex(schedules, 'payment_schedules');
    });
    lines?.querySelectorAll('[data-sales-line]').forEach(calculateLineTotal);
  }

  function setPaymentMethod() {
    const method = document.querySelector('[data-sales-payment-method]')?.value;
    if (!method) return;
    document.querySelectorAll('[data-payment-source]').forEach((container) => {
      const source = container.dataset.paymentSource;
      const show = source === 'reference'
        ? ['bank', 'transfer', 'cheque'].includes(method)
        : source === 'bank'
          ? ['bank', 'transfer', 'cheque'].includes(method)
          : source === method;
      container.classList.toggle('d-none', !show);
      container.querySelectorAll('input, select').forEach((field) => { field.disabled = !show; });
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

  document.addEventListener('DOMContentLoaded', () => {
    bindForms();
    bindOrderGrid();
    bindReceipt();
    bindReturnLines();
    initializeWidgets(document);
  });
})(window.jQuery, window, document);
