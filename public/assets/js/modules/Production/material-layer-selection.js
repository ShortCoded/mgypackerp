(function ($, document) {
  'use strict';
  $(document).on('change', '[data-layer-store-selector]', function () {
    const field = this.closest('form').querySelector('[data-layer-store-uuid]');
    if (field) {
      $(field).val(this.selectedOptions[0]?.dataset.publicUuid || '').trigger('change');
    }
  });
  $(document).on('click', '[data-add-material-layer]', function () {
    const group = this.closest('[data-material-layer-selections]');
    const rows = group.querySelector('[data-material-layer-rows]');
    const next = Number(group.dataset.nextSlice || rows.children.length);
    group.dataset.nextSlice = next + 1;
    const fragment = group.querySelector('template').content.cloneNode(true);
    fragment.querySelectorAll('[name]').forEach(function (field) { field.name = field.name.replace('__SLICE__', next); });
    rows.appendChild(fragment);
    const row = rows.lastElementChild;
    window.AppSelect2Ajax?.init(row);
    window.AppNumbers?.refresh(row);
  });
  $(document).on('click', '[data-remove-material-layer]', function () {
    const row = this.closest('[data-material-layer-row]');
    $(row).find('select.select2-hidden-accessible').each(function () { $(this).select2('destroy'); });
    row.remove();
  });
})(jQuery, document);
