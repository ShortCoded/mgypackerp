(function (window, document, $) {
  'use strict';

  const config = window.AppOperatingContext || {};
  const modalElement = document.querySelector('[data-operating-context-modal]');

  if (!modalElement || !config.selectUrl || !window.bootstrap) {
    return;
  }

  const form = modalElement.querySelector('#operating-context-form');
  const companySelect = modalElement.querySelector('[name="company_doc_num"]');
  const branchSelect = modalElement.querySelector('[name="branch_doc_num"]');
  const periodSelect = modalElement.querySelector('[name="financial_period_doc_num"]');
  const alertElement = modalElement.querySelector('[data-operating-context-alert]');
  const cancelButton = modalElement.querySelector('[data-operating-context-cancel]');
  const dismissButton = modalElement.querySelector('[data-operating-context-dismiss]');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let modal = null;
  let requiredMode = !!(config.current && config.current.requires_selection);
  let optionsRequestId = 0;
  let suppressCompanyOptionsLoad = false;

  if (!form || !companySelect || !branchSelect || !periodSelect) {
    return;
  }

  function messages() {
    return config.messages || {};
  }

  function request(url, options) {
    return window.fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken
      }
    }, options || {}));
  }

  function optionsUrl(companyDocNum) {
    if (!config.optionsUrl) {
      return '';
    }

    const url = new URL(config.optionsUrl, window.location.origin);

    if (companyDocNum) {
      url.searchParams.set('company_doc_num', companyDocNum);
    }

    return url.toString();
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }

    return String(value).replace(/["\\]/g, '\\$&');
  }

  function modalInstance() {
    if (!modal) {
      modal = window.bootstrap.Modal.getOrCreateInstance(modalElement, {
        backdrop: requiredMode ? 'static' : true,
        keyboard: !requiredMode
      });
    }

    return modal;
  }

  function initializeSelect2Ajax() {
    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
      window.AppSelect2Ajax.init(modalElement);
    }
  }

  function isSelect2Ready(select) {
    return !!($ && $.fn && $.fn.select2 && $(select).data('select2'));
  }

  function triggerSelectChange(select, eventName) {
    if ($) {
      $(select).trigger(eventName || 'change.select2');
      return;
    }

    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function setSelectDisabled(select, disabled) {
    const isDisabled = !!disabled;

    if ($) {
      $(select).prop('disabled', isDisabled);
      triggerSelectChange(select, 'change.select2');
      return;
    }

    select.disabled = isDisabled;
  }

  function closeSelect2(select) {
    if (!isSelect2Ready(select)) {
      return;
    }

    try {
      $(select).select2('close');
    } catch (error) {
      // Select2 can throw if a dropdown is already mid-destroy/open; closing is best-effort only.
    }
  }

  function clearAjaxSelect(select) {
    closeSelect2(select);

    if ($) {
      $(select).val(null).find('option').remove().end().trigger('change');
      return;
    }

    select.innerHTML = '';
    select.value = '';
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function setSelectedOption(select, item, dependentValue) {
    if (!item) {
      clearAjaxSelect(select);
      return;
    }

    ensureSelectedOption(select, item, dependentValue);
  }

  function syncOperatingContextModalSelects(clearDependents) {
    initializeSelect2Ajax();

    const hasCompany = !!(companySelect.value || '');

    if (clearDependents) {
      clearAjaxSelect(branchSelect);
      clearAjaxSelect(periodSelect);
    }

    setSelectDisabled(branchSelect, !hasCompany);
    setSelectDisabled(periodSelect, !hasCompany);

    return hasCompany;
  }

  function selectedValue(item) {
    return item ? String(item.doc_num || item.id || '') : '';
  }

  function selectedText(item) {
    return item ? String(item.label || item.text || item.name || item.doc_num || item.id || '') : '';
  }

  function ensureSelectedOption(select, item, dependentValue) {
    const value = selectedValue(item);

    if (!value) {
      return;
    }

    const text = selectedText(item) || value;
    let option = Array.from(select.options).find(function (candidate) {
      return String(candidate.value) === value;
    });

    if (!option) {
      option = new Option(text, value, true, true);
      select.appendChild(option);
    }

    option.textContent = text;
    option.selected = true;

    if (dependentValue) {
      option.setAttribute('data-dependent-value', String(dependentValue));
    }

    if ($) {
      $(select).val(value).trigger('change.select2');
      return;
    }

    select.value = value;
  }

  function applyOptions(payload, applyCompany) {
    const data = payload && payload.data ? payload.data : payload;
    const autoSelect = data && data.auto_select ? data.auto_select : {};

    if (applyCompany) {
      suppressCompanyOptionsLoad = true;
      setSelectedOption(companySelect, autoSelect.company || null);
      suppressCompanyOptionsLoad = false;
    }

    const companyDocNum = selectedValue(autoSelect.company) || companySelect.value || '';
    const hasCompany = !!companyDocNum;

    setSelectDisabled(branchSelect, !hasCompany);
    setSelectDisabled(periodSelect, !hasCompany);

    if (!hasCompany) {
      clearAjaxSelect(branchSelect);
      clearAjaxSelect(periodSelect);

      if (requiredMode && !form.querySelector('.is-invalid')) {
        showAlert(messages().required || '');
      }

      return;
    }

    setSelectedOption(branchSelect, autoSelect.branch || null, companyDocNum);
    setSelectedOption(periodSelect, autoSelect.financial_period || null, companyDocNum);

    if (requiredMode && !form.querySelector('.is-invalid')) {
      showAlert(messages().required || '');
    }
  }

  function loadOptions(companyDocNum, applyCompany) {
    const url = optionsUrl(companyDocNum);

    if (!url) {
      return Promise.resolve();
    }

    const requestId = ++optionsRequestId;

    return request(url)
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Could not load operating context options');
        }

        return response.json();
      })
      .then(function (payload) {
        if (requestId !== optionsRequestId) {
          return;
        }

        applyOptions(payload, applyCompany);
      })
      .catch(function () {
        showAlert(messages().unexpectedError || '');
      });
  }

  function focusCompanySelect() {
    window.setTimeout(function () {
      if (isSelect2Ready(companySelect) && !companySelect.disabled) {
        const selection = $(companySelect).next('.select2').find('.select2-selection').get(0);

        if (selection) {
          selection.focus({ preventScroll: true });
          return;
        }
      }

      companySelect.focus({ preventScroll: true });
    }, 120);
  }

  function setRequiredMode(required) {
    requiredMode = !!required;
    cancelButton.classList.toggle('d-none', requiredMode);
    dismissButton.classList.toggle('d-none', requiredMode);

    if (modal && !modalElement.classList.contains('show')) {
      modal.dispose();
      modal = null;
    }
  }

  function showModal(required) {
    setRequiredMode(required);
    initializeSelect2Ajax();

    if (requiredMode) {
      showAlert(messages().required || '');
      loadOptions('', true);
    } else {
      showAlert('');
      syncOperatingContextModalSelects(false);
    }

    modalInstance().show();
  }

  function showAlert(message) {
    if (!message) {
      alertElement.classList.add('d-none');
      alertElement.textContent = '';
      return;
    }

    alertElement.textContent = message;
    alertElement.classList.remove('d-none');
  }

  function clearErrors() {
    form.querySelectorAll('.is-invalid').forEach(function (field) {
      field.classList.remove('is-invalid');
    });

    form.querySelectorAll('[data-error-for]').forEach(function (field) {
      field.textContent = '';
    });

    showAlert('');
  }

  function clearFieldError(field) {
    const name = field.getAttribute('name');

    field.classList.remove('is-invalid');

    if (name) {
      const feedback = form.querySelector('[data-error-for="' + cssEscape(name) + '"]');

      if (feedback) {
        feedback.textContent = '';
      }
    }

    if (!form.querySelector('.is-invalid')) {
      showAlert('');
    }
  }

  function renderErrors(errors) {
    clearErrors();

    Object.keys(errors || {}).forEach(function (field) {
      const input = form.querySelector('[name="' + cssEscape(field) + '"]');
      const feedback = form.querySelector('[data-error-for="' + cssEscape(field) + '"]');
      const message = Array.isArray(errors[field]) ? errors[field][0] : errors[field];

      if (input) {
        input.classList.add('is-invalid');
      }

      if (feedback) {
        feedback.textContent = message || '';
      }
    });

    showAlert(messages().validationFailed || messages().unexpectedError || '');
  }

  function updateHeader(current) {
    const companyName = current && current.company ? current.company.name : (messages().notSelected || '');
    const branchName = current && current.branch ? current.branch.name : (messages().notSelected || '');
    const periodName = current && current.financial_period ? current.financial_period.name : (messages().notSelected || '');
    const missing = !!(current && current.requires_selection);

    document.querySelectorAll('[data-operating-context-company]').forEach(function (element) {
      element.textContent = companyName;
    });

    document.querySelectorAll('[data-operating-context-branch]').forEach(function (element) {
      element.textContent = branchName;
    });

    document.querySelectorAll('[data-operating-context-period]').forEach(function (element) {
      element.textContent = periodName;
    });

    document.querySelectorAll('[data-operating-context-trigger]').forEach(function (element) {
      element.classList.toggle('is-missing', missing);
    });
  }

  function syncSelectedOptionsFromCurrent(current) {
    if (!current) {
      return;
    }

    const companyDocNum = selectedValue(current.company);

    ensureSelectedOption(companySelect, current.company);
    ensureSelectedOption(branchSelect, current.branch, companyDocNum);
    ensureSelectedOption(periodSelect, current.financial_period, companyDocNum);
    syncOperatingContextModalSelects(false);
  }

  function handleCompanyChange() {
    if (suppressCompanyOptionsLoad) {
      return;
    }

    syncOperatingContextModalSelects(true);

    const companyDocNum = companySelect.value || '';

    if (companyDocNum) {
      loadOptions(companyDocNum, false);
    }
  }

  function reloadCurrentPageAfterModalHide() {
    if (modalElement.classList.contains('show')) {
      modalElement.addEventListener('hidden.bs.modal', function () {
        window.location.reload();
      }, { once: true });
      modalInstance().hide();
      return;
    }

    window.location.reload();
  }

  function submit(event) {
    event.preventDefault();
    clearErrors();

    request(config.selectUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken
      },
      body: JSON.stringify({
        company_doc_num: companySelect.value || '',
        branch_doc_num: branchSelect.value || '',
        financial_period_doc_num: periodSelect.value || ''
      })
    })
      .then(function (response) {
        if (response.status === 422) {
          return response.json().then(function (payload) {
            renderErrors(payload.errors || {});
            throw new Error('Validation failed');
          });
        }

        if (!response.ok) {
          throw new Error('Could not save operating context');
        }

        return response.json();
      })
      .then(function (payload) {
        const current = payload.data && payload.data.current ? payload.data.current : null;

        if (current) {
          config.current = current;
          syncSelectedOptionsFromCurrent(current);
          updateHeader(current);
        }

        setRequiredMode(false);

        if (payload.reload === true) {
          reloadCurrentPageAfterModalHide();
          return;
        }

        modalInstance().hide();
      })
      .catch(function (error) {
        if (error.message === 'Validation failed') {
          return;
        }

        showAlert(messages().unexpectedError || '');
      });
  }

  form.addEventListener('submit', submit);

  if ($) {
    $(modalElement).off('.operatingContext');
    $(companySelect).off('change.operatingContext').on('change.operatingContext', handleCompanyChange);
    $(modalElement).on('change.operatingContext select2:clear.operatingContext select2:select.operatingContext', 'select', function () {
      clearFieldError(this);
    });
  } else {
    [companySelect, branchSelect, periodSelect].forEach(function (select) {
      select.addEventListener('change', function () {
        clearFieldError(select);
      });
    });

    companySelect.addEventListener('change', handleCompanyChange);
  }

  modalElement.addEventListener('shown.bs.modal', function () {
    initializeSelect2Ajax();
    syncOperatingContextModalSelects(false);
    focusCompanySelect();
  });

  document.addEventListener('click', function (event) {
    const trigger = event.target.closest('[data-operating-context-trigger]');

    if (!trigger) {
      return;
    }

    event.preventDefault();
    showModal(false);
  });

  initializeSelect2Ajax();
  syncOperatingContextModalSelects(false);
  updateHeader(config.current || {});

  if (config.current && config.current.requires_selection) {
    window.setTimeout(function () {
      showModal(true);
    }, 250);
  }
})(window, document, window.jQuery);
