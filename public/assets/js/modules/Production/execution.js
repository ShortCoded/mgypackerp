(function ($, window, document) {
  'use strict';

  function csrf() {
    return $('meta[name="csrf-token"]').attr('content');
  }

  function notify(icon, message) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, message);
      return;
    }

    window.alert(message);
  }

  function initializeTables() {
    $('[data-server-table]').each(function () {
      const element = this;
      const $table = $(element);
      if (!$.fn.DataTable || $.fn.DataTable.isDataTable(element)) {
        return;
      }

      let columns = [];
      try {
        columns = JSON.parse($table.attr('data-columns') || '[]');
      } catch (error) {
        notify('error', 'Invalid table configuration.');
        return;
      }

      const base = {
        ajax: { url: $table.data('url') },
        processing: true,
        serverSide: true,
        stateSave: true,
        responsive: { details: { type: 'inline', target: 0 } },
        columns: columns,
        order: [[Number($table.data('order-column') || 0), String($table.data('order-direction') || 'desc')]],
        drawCallback: function () {
          if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
            window.AppDataTables.applyFalconEnhancements(document);
          }
        }
      };
      const options = window.AppDataTables && typeof window.AppDataTables.options === 'function'
        ? window.AppDataTables.options(base)
        : base;
      $table.DataTable(options);
    });
  }

  function initializeRowNavigation() {
    $('[data-server-table]')
      .off('dblclick.productionRowNavigation', 'tbody tr:not(.child)')
      .on('dblclick.productionRowNavigation', 'tbody tr:not(.child)', function (event) {
        if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, td.dt-select, td.dtr-control').length > 0) {
          return;
        }

        const link = this.querySelector('[data-row-primary-link]');
        if (link instanceof HTMLAnchorElement && link.href) {
          window.location.assign(link.href);
        }
      });
  }

  function reloadTables() {
    let reloaded = false;
    $('[data-server-table]').each(function () {
      if ($.fn.DataTable && $.fn.DataTable.isDataTable(this)) {
        $(this).DataTable().ajax.reload(null, false);
        reloaded = true;
      }
    });

    return reloaded;
  }

  $(document).on('click', '[data-action="post"], [data-action="delete"], [data-action="restore"]', function () {
    const button = this;
    const method = button.dataset.action === 'delete' ? 'DELETE' : (button.dataset.action === 'restore' ? 'PATCH' : 'POST');
    if (button.dataset.action === 'delete' && !window.confirm(button.dataset.confirm || 'Delete this record?')) {
      return;
    }
    button.disabled = true;
    $.ajax({
      url: button.dataset.url,
      method: method,
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    }).done(function (payload) {
      notify('success', payload.message || 'Done');
      if (payload.redirect_url) {
        window.location.assign(payload.redirect_url);
        return;
      }
      if (!reloadTables()) {
        window.location.reload();
      }
    }).fail(function (xhr) {
      notify('error', (xhr.responseJSON && xhr.responseJSON.message) || 'Operation failed.');
    }).always(function () {
      button.disabled = false;
    });
  });

  $(document).on('click', '[data-action="reason"]', function () {
    const button = this;
    const reason = window.prompt(button.dataset.prompt || 'Reason');
    if (!reason) {
      return;
    }

    button.disabled = true;
    $.ajax({
      url: button.dataset.url,
      method: 'POST',
      data: { [button.dataset.reasonKey || 'reason']: reason },
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    }).done(function (payload) {
      notify('success', payload.message || 'Done');
      if (!reloadTables()) {
        window.location.reload();
      }
    }).fail(function (xhr) {
      notify('error', (xhr.responseJSON && xhr.responseJSON.message) || 'Operation failed.');
    }).always(function () {
      button.disabled = false;
    });
  });

  $(document).on('click', '[data-navigate-select]', function () {
    const selector = document.querySelector(this.dataset.navigateSelect);
    if (selector && selector.value) {
      window.location.href = selector.value;
    }
  });

  function filterStageOptions() {
    const lineId = String($('[data-route-line]').val() || '');
    const $stage = $('[data-route-stage]');
    $stage.find('option[data-line-id]').each(function () {
      $(this).prop('hidden', String(this.dataset.lineId) !== lineId);
    });
    if ($stage.find('option:selected').prop('hidden')) {
      $stage.val('');
    }
  }

  $(document).on('change', '[data-route-line]', filterStageOptions);

  $(document).on('change', '[data-material-run-select]', function () {
    if (!this.value) {
      return;
    }
    const url = new URL(this.dataset.url, window.location.origin);
    url.searchParams.set('run', this.value);
    const additional = document.querySelector('[data-additional-material]');
    if (additional && additional.checked) {
      url.searchParams.set('additional', '1');
    }
    window.location.href = url.toString();
  });

  function updateAdditionalMaterialForm(resetQuantities) {
    const additional = document.querySelector('[data-additional-material]');
    if (!additional) {
      return;
    }
    const reason = document.querySelector('[data-additional-reason]');
    if (reason) {
      reason.required = additional.checked;
    }
    if (resetQuantities) {
      document.querySelectorAll('[data-planned-remaining]').forEach(function (input) {
        input.value = additional.checked ? '' : input.dataset.plannedRemaining;
      });
    }
  }

  $(document).on('change', '[data-additional-material]', function () { updateAdditionalMaterialForm(true); });

  function filterQualityCheckpoints() {
    $('[data-quality-upload]').each(function () {
      const typeId = String($(this).find('[data-quality-type]').val() || '');
      $(this).find('[data-quality-checkpoint]').each(function () {
        const visible = String(this.dataset.typeId) === typeId;
        this.hidden = !visible;
        $(this).find('[data-quality-checkpoint-input]').each(function () {
          this.disabled = !visible;
          this.required = visible && this.hasAttribute('data-quality-required');
        });
      });
    });
  }

  $(document).on('change', '[data-quality-type]', filterQualityCheckpoints);

  function updateQualityDisposition() {
    $('[data-quality-upload]').each(function () {
      const result = String($(this).find('[data-quality-overall-result]').val() || '');
      const exceptionDetails = this.querySelector('[data-quality-exception-details]');
      if (exceptionDetails) {
        exceptionDetails.hidden = result === 'passed';
      }
    });
  }

  function updateQualitySubjectFields() {
    const selector = document.querySelector('[data-quality-subject-type]');
    if (!selector) {
      return;
    }

    const selected = String(selector.value || 'production_run');
    document.querySelectorAll('[data-quality-subject-field]').forEach(function (field) {
      const isVisible = String(field.dataset.qualitySubjectField || '').split(',').includes(selected);
      field.hidden = !isVisible;
      field.querySelectorAll('select, input, textarea').forEach(function (input) {
        input.disabled = !isVisible;
      });
    });
  }

  $(document).on('change', '[data-quality-subject-type]', updateQualitySubjectFields);

  $(document).on('change', '[data-quality-overall-result]', function () {
    const form = this.closest('[data-quality-upload]');
    const disposition = form && form.querySelector('[data-quality-disposition]');
    if (disposition) {
      disposition.value = this.value === 'passed' ? 'release' : 'hold';
    }
    updateQualityDisposition();
    updateQualitySubjectFields();
  });

  $(document).on('change', '[data-quality-evidence]', function () {
    const input = this;
    const form = input.closest('[data-quality-upload]');
    const summary = form && form.querySelector('[data-quality-evidence-summary]');
    const preview = form && form.querySelector('[data-quality-evidence-preview]');
    const files = form
      ? Array.from(form.querySelectorAll('[data-quality-evidence]')).flatMap(function (field) { return Array.from(field.files || []); })
      : Array.from(input.files || []);

    if (summary) {
      summary.textContent = files.length ? `${files.length} ${input.dataset.selectedLabel || 'file(s) ready to upload'}` : (input.dataset.emptyLabel || 'No attachments selected');
    }
    if (!preview) {
      return;
    }

    preview.replaceChildren();
    files.forEach(function (file) {
      const item = document.createElement('div');
      item.className = 'quality-evidence-preview-item';
      if (file.type.startsWith('image/')) {
        const image = document.createElement('img');
        image.alt = file.name;
        image.src = URL.createObjectURL(file);
        image.addEventListener('load', function () { URL.revokeObjectURL(image.src); }, { once: true });
        item.appendChild(image);
      }
      const name = document.createElement('span');
      name.textContent = file.name;
      item.appendChild(name);
      preview.appendChild(item);
    });
  });

  function reindexLaborRows(container) {
    container.querySelectorAll('[data-labor-row]').forEach(function (row, index) {
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/labor_details\[(?:\d+|__INDEX__)\]/, `labor_details[${index}]`);
      });
    });
  }

  $(document).on('click', '[data-add-labor-row]', function () {
    const container = this.closest('[data-production-labor-planning]');
    const rows = container && container.querySelector('[data-labor-rows]');
    const template = container && container.querySelector('[data-labor-row-template]');
    if (!rows || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    rows.appendChild(template.content.cloneNode(true));
    reindexLaborRows(container);
    rows.querySelector('[data-labor-row]:last-child input')?.focus();
  });

  $(document).on('click', '[data-remove-labor-row]', function () {
    const container = this.closest('[data-production-labor-planning]');
    const rows = container && container.querySelectorAll('[data-labor-row]');
    const row = this.closest('[data-labor-row]');
    if (!container || !row || !rows) {
      return;
    }

    if (rows.length === 1) {
      row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
    } else {
      row.remove();
    }
    reindexLaborRows(container);
  });

  function reindexMaintenanceMaterialRows(container) {
    container.querySelectorAll('[data-maintenance-material-row]').forEach(function (row, index) {
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/lines\[(?:\d+|__INDEX__)\]/, `lines[${index}]`);
      });
    });
  }

  $(document).on('click', '[data-add-maintenance-material]', function () {
    const form = this.closest('[data-maintenance-material-form]');
    const rows = form && form.querySelector('[data-maintenance-material-rows]');
    const template = form && form.querySelector('[data-maintenance-material-template]');
    if (!rows || !(template instanceof HTMLTemplateElement)) {
      return;
    }
    rows.appendChild(template.content.cloneNode(true));
    reindexMaintenanceMaterialRows(form);
  });

  $(document).on('click', '[data-remove-maintenance-material]', function () {
    const form = this.closest('[data-maintenance-material-form]');
    const row = this.closest('[data-maintenance-material-row]');
    if (!form || !row) {
      return;
    }
    if (form.querySelectorAll('[data-maintenance-material-row]').length === 1) {
      row.querySelectorAll('input, select').forEach(function (field) { field.value = ''; });
    } else {
      row.remove();
    }
    reindexMaintenanceMaterialRows(form);
  });

  $(function () {
    initializeTables();
    initializeRowNavigation();
    filterStageOptions();
    updateAdditionalMaterialForm(false);
    filterQualityCheckpoints();
    updateQualityDisposition();
  });
})(window.jQuery, window, document);
