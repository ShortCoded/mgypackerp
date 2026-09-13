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
  if (!body || !template) return;

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
      const wrapper = document.createElement('tbody');
      wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(body.children.length));
      const row = wrapper.firstElementChild;
      body.appendChild(row);
      window.AppSelect2Ajax?.init(row);
      renumber();
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
})(window, document);
