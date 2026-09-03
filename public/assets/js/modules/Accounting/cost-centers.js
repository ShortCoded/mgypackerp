(function ($, window, document) {
  'use strict';

  const messages = window.costCenterMessages || {};
  const selected = new Set();
  let table = null;
  let treeVisible = false;

  function csrf() {
    return $('meta[name="csrf-token"]').attr('content');
  }

  function msg(key) {
    return messages[key] || '';
  }

  function toast(icon, title) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, title);
    }
  }

  function request(url, method, data) {
    return $.ajax({
      url: url,
      method: method,
      data: data,
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    });
  }

  function confirmDialog(title, text, confirmText) {
    if (!window.Swal) {
      return $.Deferred().resolve({ isConfirmed: false }).promise();
    }

    return Swal.fire({
      icon: 'warning',
      title: title,
      text: text,
      showCancelButton: true,
      showCloseButton: true,
      focusCancel: true,
      allowEscapeKey: true,
      confirmButtonText: confirmText,
      cancelButtonText: msg('cancel')
    });
  }

  function filterData() {
    if (window.AppReportUI && typeof window.AppReportUI.filterData === 'function') {
      return window.AppReportUI.filterData({ filterSelector: '.js-report-filters' });
    }

    const data = {};
    $('.js-report-filters').serializeArray().forEach(function (field) {
      const value = String(field.value || '').trim();
      if (value !== '') {
        data[field.name] = value;
      }
    });

    return data;
  }

  function filteredUrl(url) {
    const query = $.param(filterData());

    return url + (query ? (url.indexOf('?') === -1 ? '?' : '&') + query : '');
  }

  function refreshBulkBar() {
    $('#cost_centers_bulk_selected_count').text(selected.size);
    const $applyButton = $('#cost_centers_bulk_action_apply');
    const applyLabel = $applyButton.data('label') || '';

    $applyButton
      .prop('disabled', selected.size < 1)
      .find('span:last')
      .text(applyLabel + (selected.size > 0 ? ' (' + selected.size + ')' : ''));
    $('#cost_centers_bulk_actions_bar').toggleClass('d-none', selected.size < 1).toggleClass('d-flex', selected.size > 0);

    if (selected.size < 1) {
      $('#cost_centers_bulk_action_select').val('delete');
    }
  }

  function pageCheckboxes(api) {
    const selector = 'input.js-record-select, input.js-cost-center-row-checkbox';

    return api && typeof api.rows === 'function'
      ? $(api.rows({ page: 'current' }).nodes()).find(selector)
      : $('#cost-centers-table').find('tbody tr:not(.child) ' + selector);
  }

  function checkboxDocNum(checkbox) {
    return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
  }

  function updateSelectAllState(api) {
    const selectableDocNums = pageCheckboxes(api).map(function () {
      return checkboxDocNum(this);
    }).get().filter(function (docNum) {
      return docNum !== '';
    });
    const checkedOnPage = selectableDocNums.filter(function (docNum) {
      return selected.has(docNum);
    }).length;

    $('#cost_centers_select_all_records')
      .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
      .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
  }

  function restoreSelectionState(api) {
    pageCheckboxes(api).each(function () {
      const docNum = checkboxDocNum(this);
      $(this).prop('checked', docNum !== '' && selected.has(docNum));
    });
    updateSelectAllState(api);
    refreshBulkBar();
  }

  function clearSelection(api) {
    selected.clear();
    pageCheckboxes(api).prop('checked', false);
    updateSelectAllState(api);
    refreshBulkBar();
  }

  function trashFilterValue() {
    const value = String($('#cost_centers_trash_filter').val() || 'active');

    return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
  }

  function reloadTable() {
    if (table) {
      table.ajax.reload(null, false);
    }
  }

  function reloadTree() {
    const $tree = $('[data-cost-centers-tree]');

    if (!$tree.length || !treeVisible) {
      return;
    }

    $('[data-cost-centers-tree-list]').html('<div class="text-center text-600 py-4"><span class="fas fa-spinner fa-spin me-1"></span></div>');

    $.getJSON(filteredUrl($tree.data('url'))).done(function (payload) {
      const $treeList = $('[data-cost-centers-tree-list]');

      $treeList.html(renderTree(payload.data || []));
      initCostCenterTree($treeList);
    }).fail(function () {
      $('[data-cost-centers-tree-list]').html('<div class="text-danger p-3">' + $('<div>').text(msg('unexpectedError')).html() + '</div>');
    });
  }

  function reloadCurrentView() {
    reloadTable();
    reloadTree();
  }

  function initTable() {
    const $table = $('#cost-centers-table');

    if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
      return;
    }

    const options = window.AppDataTables && typeof window.AppDataTables.options === 'function' ? window.AppDataTables.options : function (config) { return config; };

    table = $table.DataTable(options({
      ajax: {
        url: $table.data('url') || $table.data('ajax-url'),
        data: function (data) {
          data.trash_filter = trashFilterValue();
          $.extend(data, filterData());
        }
      },
      processing: true,
      serverSide: true,
      stateSave: true,
      responsive: { details: { type: 'inline', target: 1 } },
      columns: [
        { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
        { data: 'doc_num', name: 'cost_centers.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
        { data: 'cost_center_code', name: 'cost_center_code', className: 'align-middle white-space-nowrap dt-code' },
        { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'parent', name: 'parent', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'linked_accounts', name: 'linked_accounts', orderable: false, searchable: false, defaultContent: '—', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'is_group', name: 'is_group', className: 'align-middle white-space-nowrap text-center' },
        { data: 'status', name: 'status', className: 'align-middle white-space-nowrap' },
        { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
        { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
        { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap text-end all no-colvis data-table-row-action dt-actions' }
      ],
      order: [[2, 'asc']],
      createdRow: function (row) { $(row).addClass('btn-reveal-trigger'); },
      initComplete: function () {
        restoreSelectionState(this.api());
      },
      drawCallback: function () {
        restoreSelectionState(this.api());
        if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
          window.AppDataTables.applyFalconEnhancements(document);
        }
      }
    }));

    $('#cost_centers_trash_filter').off('change.costCentersTrashFilter').on('change.costCentersTrashFilter', function () {
      clearSelection(table);
      reloadTable();
    });

    $('#cost_centers_select_all_records').off('change.costCentersSelect').on('change.costCentersSelect', function () {
      const checked = $(this).is(':checked');

      pageCheckboxes(table).each(function () {
        const docNum = checkboxDocNum(this);
        if (docNum === '') { return; }
        if (checked) { selected.add(docNum); } else { selected.delete(docNum); }
        $(this).prop('checked', checked);
      });

      updateSelectAllState(table);
      refreshBulkBar();
    });

    $table.off('change.costCentersSelect', 'input.js-record-select, input.js-cost-center-row-checkbox').on('change.costCentersSelect', 'input.js-record-select, input.js-cost-center-row-checkbox', function () {
      const docNum = checkboxDocNum(this);
      if (docNum === '') { return; }
      if ($(this).is(':checked')) { selected.add(docNum); } else { selected.delete(docNum); }
      updateSelectAllState(table);
      refreshBulkBar();
    });

    $table.off('dblclick.costCentersEditRow', 'tbody tr:not(.child)').on('dblclick.costCentersEditRow', 'tbody tr:not(.child)', function (event) {
      if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
        return;
      }

      const editLink = $(this).find('.js-edit-record').get(0);
      if (editLink) { editLink.click(); }
    });

    $('#cost_centers_bulk_action_apply').off('click.costCentersBulk').on('click.costCentersBulk', function () {
      const docNums = Array.from(selected);

      if (docNums.length === 0 || $('#cost_centers_bulk_action_select').val() !== 'delete') {
        refreshBulkBar();
        return;
      }

      confirmDialog(msg('bulkDeleteConfirmTitle'), msg('bulkDeleteConfirmText').replace(':count', docNums.length), msg('bulkDeleteConfirmYes')).then(function (result) {
        if (!result.isConfirmed) {
          return;
        }
        request($table.data('bulk-delete-url'), 'DELETE', { doc_nums: docNums }).done(function (response) {
          toast('success', response.message);
          clearSelection(table);
          reloadTable();
        }).fail(function (xhr) {
          toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
        });
      });
    });
  }

  function clearValidation($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('[data-error-for]').text('');
    $form.find('[data-form-alert]').empty();
    $form.find('.js-cost-center-alert').addClass('d-none').removeClass('alert-danger alert-warning alert-success alert-info');
    $form.find('.js-cost-center-alert-message').empty();
  }

  function showFormNotice($form, type, messagesList) {
    const $alert = $form.find('.js-cost-center-alert');
    const $message = $form.find('.js-cost-center-alert-message');
    const messagesArray = Array.isArray(messagesList) ? messagesList : [messagesList];
    const cleanMessages = messagesArray.filter(function (message) { return Boolean(message); });

    if (!$alert.length || cleanMessages.length === 0) {
      return;
    }

    const className = type === 'danger' ? 'alert-danger' : (type === 'warning' ? 'alert-warning' : (type === 'success' ? 'alert-success' : 'alert-info'));
    const content = cleanMessages.length > 1
      ? '<ul class="mb-0 ps-3">' + cleanMessages.map(function (message) { return '<li>' + $('<div>').text(message).html() + '</li>'; }).join('') + '</ul>'
      : $('<div>').text(cleanMessages[0]).html();

    $alert.removeClass('d-none alert-danger alert-warning alert-success alert-info').addClass(className);
    $message.html(content);
  }

  function renderValidation($form, xhr) {
    const errors = xhr.responseJSON && xhr.responseJSON.errors ? xhr.responseJSON.errors : {};
    const list = [];

    $.each(errors, function (field, fieldMessages) {
      const baseField = String(field || '').split('.')[0];
      const $field = $form.find('[name="' + baseField + '"], [name="' + baseField + '[]"]');
      $field.addClass('is-invalid');
      $form.find('[data-error-for="' + baseField + '"]').text(fieldMessages[0] || '');
      (fieldMessages || []).forEach(function (fieldMessage) {
        list.push(fieldMessage);
      });
    });

    showFormNotice($form, 'danger', list.length ? list : [msg('validationFailed')]);
  }

  function formFieldValue($form, name) {
    const $field = $form.find('[name="' + name + '"]');

    return $field.length ? String($field.val() || '') : '';
  }

  function normalizedValues(values) {
    const items = Array.isArray(values) ? values : (values === undefined || values === null || values === '' ? [] : [values]);

    return items.map(function (value) {
      return String(value || '').trim();
    }).filter(function (value, index, self) {
      return value !== '' && self.indexOf(value) === index;
    }).sort();
  }

  function formSnapshot($form) {
    return {
      doc_number: formFieldValue($form, 'doc_number'),
      cost_center_code: formFieldValue($form, 'cost_center_code'),
      name: formFieldValue($form, 'name'),
      name_en: formFieldValue($form, 'name_en'),
      parent_doc_num: formFieldValue($form, 'parent_doc_num'),
      linked_account_doc_nums: normalizedValues($form.find('select[name="linked_account_doc_nums[]"]').val() || []),
      is_group: $form.find('[name="is_group"]').is(':checked') ? '1' : '0',
      status: formFieldValue($form, 'status'),
      notes: formFieldValue($form, 'notes')
    };
  }

  function normalizeSnapshot(snapshot) {
    const normalized = $.extend({}, snapshot || {});

    Object.keys(normalized).forEach(function (field) {
      if (typeof normalized[field] === 'string') {
        normalized[field] = normalized[field].trim();
      } else if (Array.isArray(normalized[field])) {
        normalized[field] = normalizedValues(normalized[field]);
      }
    });

    return normalized;
  }

  function snapshotChanged($form) {
    if ($form.data('mode') !== 'edit') {
      return true;
    }

    return JSON.stringify(normalizeSnapshot($form.data('original') || {})) !== JSON.stringify(normalizeSnapshot(formSnapshot($form)));
  }

  function updateOriginalSnapshot($form, response) {
    const snapshot = formSnapshot($form);
    const data = response && response.data ? response.data : {};

    if (data.doc_number !== undefined) {
      snapshot.doc_number = String(data.doc_number || '');
    }

    $form.data('original', normalizeSnapshot(snapshot));
  }

  function clearSelect2Field($field) {
    if (!$field.length) {
      return;
    }

    $field.val(null).trigger('change');
  }

  function resetCreateForm($form) {
    $form.find('[name="doc_number"], [name="cost_center_code"], [name="name"], [name="name_en"], [name="notes"]').val('');
    clearSelect2Field($form.find('[name="parent_doc_num"]'));
    clearSelect2Field($form.find('select[name="linked_account_doc_nums[]"]'));
    $form.find('[name="is_group"]').prop('checked', false);
    $form.find('[name="status"]').val('active');
    $form.find('input[name="submit_action"]').val('save');
    $form.find('input[name="clone_source_token"]').remove();
    $form.data('mode', 'create');
    updateOriginalSnapshot($form, {});
    refreshCostCenterCode($form);
  }

  function refreshCostCenterCode($form) {
    const url = $form.data('next-code-url');

    if (!url || $form.data('mode') === 'view') {
      return;
    }

    request(url, 'GET', {
      parent_doc_num: formFieldValue($form, 'parent_doc_num')
    }).done(function (response) {
      const code = response && response.data ? response.data.cost_center_code : '';

      if (code !== undefined && code !== null) {
        $form.find('[name="cost_center_code"]').val(code).removeClass('is-invalid');
        $form.find('[data-error-for="cost_center_code"]').text('');
      }
    }).fail(function (xhr) {
      if (xhr.status === 422) {
        renderValidation($form, xhr);
        return;
      }

      toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
    });
  }

  function updateFormUrls($form, response) {
    const urls = response && response.data && response.data.urls ? response.data.urls : null;

    if (!urls) {
      return;
    }

    if (urls.update) {
      $form.attr('action', urls.update);
    }

    $form.find('[data-delete-url]').attr('data-delete-url', urls.destroy).data('delete-url', urls.destroy);

    if (urls.edit && window.history && window.location.href !== urls.edit && $form.data('mode') === 'edit') {
      window.history.replaceState({}, '', urls.edit);
    }
  }

  function renderTree(nodes) {
    if (!nodes.length) {
      return '<div class="text-center text-600 py-4">' + msg('noData') + '</div>';
    }

    return '<ul class="mb-0 treeview treeview-stripe" id="costCentersTreeView" role="tree" aria-label="' + escapeHtml(msg('title')) + '" data-options=\'{"striped":true}\'>' + renderTreeNodes(nodes, 1) + '</ul>';
  }

  function renderTreeNodes(nodes, level) {
    return nodes.map(function (node) {
      const children = Array.isArray(node.children) ? node.children : [];
      const hasChildren = children.length > 0;
      const collapseId = 'costCentersTree-' + safeTreeId(node.id || node.cost_center_code || Math.random().toString(36).slice(2));
      const expanded = level === 1;
      const hiddenClass = expanded ? ' collapse-show show' : ' collapse-hidden';
      const inactive = node.status === msg('inactive') ? ' opacity-75' : '';
      const nodeText = treeNodeText(node, hasChildren);
      const nodeLabel = treeNodeLabel(node);

      if (hasChildren) {
        return '<li class="treeview-list-item' + inactive + '" role="none">' +
          '<div class="treeview-row"></div>' +
          '<a data-cost-centers-tree-node data-cost-centers-tree-branch data-bs-toggle="collapse" href="#' + collapseId + '" role="treeitem" tabindex="-1" aria-expanded="' + (expanded ? 'true' : 'false') + '" aria-controls="' + collapseId + '" aria-level="' + level + '" aria-label="' + escapeHtml(nodeLabel) + '" title="' + branchTitle(expanded) + '">' +
          nodeText +
          '</a>' +
          '<ul class="collapse treeview-list' + hiddenClass + '" id="' + collapseId + '" role="group" data-show="' + (expanded ? 'true' : 'false') + '">' +
          renderTreeNodes(children, level + 1) +
          '</ul>' +
        '</li>';
      }

      return '<li class="treeview-list-item' + inactive + '" role="none">' +
        '<div class="treeview-row"></div>' +
        '<div class="treeview-item" data-cost-centers-tree-node role="treeitem" tabindex="-1" aria-level="' + level + '" aria-label="' + escapeHtml(nodeLabel) + '">' +
        '<div class="flex-1">' +
        nodeText +
        '</div>' +
        '</div>' +
      '</li>';
    }).join('');
  }

  function treeNodeText(node, hasChildren) {
    const icon = hasChildren ? '' : '<span class="fas fa-file-alt text-500"></span>';
    const code = $('<div>').text(node.cost_center_code || '').html();
    const name = $('<div>').text(node.name || '').html();

    return '<p class="treeview-text">' +
      icon +
      '<span class="cost-centers-tree-code" dir="ltr">' + code + '</span>' +
      (node.is_group ? '<span class="badge rounded-pill badge-subtle-info">' + $('<div>').text(msg('groupLabel')).html() + '</span>' : '') +
      '<span class="text-900">' + name + '</span>' +
    '</p>';
  }

  function treeNodeLabel(node) {
    return [node.cost_center_code || '', node.name || ''].filter(function (value) {
      return String(value).trim() !== '';
    }).join(' ');
  }

  function escapeHtml(value) {
    return $('<div>').text(value || '').html();
  }

  function branchTitle(expanded) {
    return escapeHtml(expanded ? msg('collapseBranch') : msg('expandBranch'));
  }

  function safeTreeId(value) {
    return String(value).replace(/[^A-Za-z0-9_-]/g, '-');
  }

  function initCostCenterTree($container) {
    const $tree = $container.find('.treeview');

    if (!$tree.length) {
      return;
    }

    stripeCostCenterTree($tree);

    $tree.find('.treeview-list').each(function () {
      const $list = $(this);

      $list.toggleClass('collapse-show', $list.hasClass('show')).toggleClass('collapse-hidden', !$list.hasClass('show'));
      syncTreeBranchForList($tree, $list, $list.hasClass('show'));
    });
    syncTreeFocus($tree);

    $tree.off('show.bs.collapse.costCentersTree shown.bs.collapse.costCentersTree hide.bs.collapse.costCentersTree hidden.bs.collapse.costCentersTree keydown.costCentersTree focusin.costCentersTree click.costCentersTreeNode')
      .on('show.bs.collapse.costCentersTree shown.bs.collapse.costCentersTree', '.treeview-list', function (event) {
        event.stopPropagation();
        syncTreeBranchForList($tree, $(this), true);
        stripeCostCenterTree($tree);
        syncTreeFocus($tree);
      })
      .on('hide.bs.collapse.costCentersTree hidden.bs.collapse.costCentersTree', '.treeview-list', function (event) {
        event.stopPropagation();
        syncTreeBranchForList($tree, $(this), false);
        stripeCostCenterTree($tree);
        syncTreeFocus($tree);
      })
      .on('keydown.costCentersTree', '[data-cost-centers-tree-node]', function (event) {
        handleTreeKeydown(event, $tree, $(this));
      })
      .on('focusin.costCentersTree click.costCentersTreeNode', '[data-cost-centers-tree-node]', function () {
        syncTreeFocus($tree, $(this));
      });
  }

  function setTreeExpanded($tree, expanded) {
    const activeElement = document.activeElement;
    const $activeNode = $(activeElement).closest('[data-cost-centers-tree-node]');
    const activeWasInTree = $activeNode.length > 0 && $.contains($tree.get(0), $activeNode.get(0));
    const $currentNode = $tree.find('[data-cost-centers-tree-node][tabindex="0"]').first();

    $tree.find('[data-cost-centers-tree-branch]').each(function () {
      setBranchExpanded($tree, $(this), expanded);
    });

    stripeCostCenterTree($tree);

    if (activeWasInTree) {
      focusTreeNode(nearestVisibleTreeNode($tree, $activeNode));
      return;
    }

    syncTreeFocus($tree, $currentNode);
  }

  function setBranchExpanded($tree, $node, expanded) {
    const $list = controlledTreeList($node);

    if (!$list.length) {
      return;
    }

    $list
      .removeClass('collapsing')
      .toggleClass('show collapse-show', expanded)
      .toggleClass('collapse-hidden', !expanded)
      .css('height', '')
      .attr('data-show', expanded ? 'true' : 'false');

    $node
      .attr('aria-expanded', expanded ? 'true' : 'false')
      .attr('title', expanded ? msg('collapseBranch') : msg('expandBranch'));

    syncTreeFocus($tree, $node);
  }

  function syncTreeBranchForList($tree, $list, expanded) {
    const $node = treeNodeForList($tree, $list);

    $list
      .toggleClass('collapse-show', expanded)
      .toggleClass('collapse-hidden', !expanded)
      .attr('data-show', expanded ? 'true' : 'false');

    $node
      .attr('aria-expanded', expanded ? 'true' : 'false')
      .attr('title', expanded ? msg('collapseBranch') : msg('expandBranch'));
  }

  function controlledTreeList($node) {
    const id = String($node.attr('aria-controls') || '').trim();

    return id === '' ? $() : $(document.getElementById(id));
  }

  function treeNodeForList($tree, $list) {
    const id = String($list.attr('id') || '').trim();

    return id === '' ? $() : $tree.find('[data-cost-centers-tree-node][aria-controls="' + id + '"]').first();
  }

  function visibleTreeNodes($tree) {
    return $tree.find('[data-cost-centers-tree-node]').filter(function () {
      return $(this).parentsUntil($tree, '.collapse-hidden, .treeview-list:not(.show)').length === 0;
    });
  }

  function isVisibleTreeNode($tree, $node) {
    return $node.length > 0 && visibleTreeNodes($tree).filter($node).length > 0;
  }

  function syncTreeFocus($tree, $preferredNode) {
    const $visibleNodes = visibleTreeNodes($tree);

    if (!$visibleNodes.length) {
      return;
    }

    const $currentNode = $preferredNode && isVisibleTreeNode($tree, $preferredNode)
      ? $preferredNode
      : ($visibleNodes.filter('[tabindex="0"]').first().length ? $visibleNodes.filter('[tabindex="0"]').first() : $visibleNodes.first());

    $tree.find('[data-cost-centers-tree-node]').attr('tabindex', '-1');
    $currentNode.attr('tabindex', '0');
  }

  function focusTreeNode($node) {
    const $tree = $node.closest('.treeview');

    if (!$tree.length || !$node.length) {
      return;
    }

    syncTreeFocus($tree, $node);
    $node.trigger('focus');
  }

  function nearestVisibleTreeNode($tree, $node) {
    let $candidate = $node;

    while ($candidate.length) {
      if (isVisibleTreeNode($tree, $candidate)) {
        return $candidate;
      }

      $candidate = parentTreeNode($tree, $candidate);
    }

    return visibleTreeNodes($tree).first();
  }

  function parentTreeNode($tree, $node) {
    const $parentList = $node.closest('ul.treeview-list');

    return $parentList.length ? treeNodeForList($tree, $parentList) : $();
  }

  function handleTreeKeydown(event, $tree, $node) {
    if (shouldIgnoreTreeKeydown(event)) {
      return;
    }

    const key = event.key;
    const rtl = String(document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
    const expandKey = rtl ? 'ArrowLeft' : 'ArrowRight';
    const collapseKey = rtl ? 'ArrowRight' : 'ArrowLeft';

    if (key === 'ArrowDown') {
      event.preventDefault();
      focusAdjacentTreeNode($tree, $node, 1);
      return;
    }

    if (key === 'ArrowUp') {
      event.preventDefault();
      focusAdjacentTreeNode($tree, $node, -1);
      return;
    }

    if (key === 'Home') {
      event.preventDefault();
      focusTreeNode(visibleTreeNodes($tree).first());
      return;
    }

    if (key === 'End') {
      event.preventDefault();
      focusTreeNode(visibleTreeNodes($tree).last());
      return;
    }

    if (key === 'Enter' || key === ' ') {
      event.preventDefault();
      toggleTreeNode($tree, $node);
      return;
    }

    if (key === expandKey) {
      event.preventDefault();
      expandTreeNode($tree, $node);
      return;
    }

    if (key === collapseKey) {
      event.preventDefault();
      collapseTreeNode($tree, $node);
    }
  }

  function shouldIgnoreTreeKeydown(event) {
    return $(event.target).closest('input, select, textarea, button, .dropdown-menu, .modal.show, .select2-container, .select2-search__field, [contenteditable="true"]').length > 0;
  }

  function focusAdjacentTreeNode($tree, $node, offset) {
    const $nodes = visibleTreeNodes($tree);
    const index = $nodes.index($node);
    const nextIndex = index + offset;

    if (nextIndex >= 0 && nextIndex < $nodes.length) {
      focusTreeNode($nodes.eq(nextIndex));
    }
  }

  function toggleTreeNode($tree, $node) {
    if (!$node.is('[data-cost-centers-tree-branch]')) {
      return;
    }

    setBranchExpanded($tree, $node, $node.attr('aria-expanded') !== 'true');
    stripeCostCenterTree($tree);
    focusTreeNode($node);
  }

  function expandTreeNode($tree, $node) {
    if (!$node.is('[data-cost-centers-tree-branch]')) {
      return;
    }

    if ($node.attr('aria-expanded') !== 'true') {
      setBranchExpanded($tree, $node, true);
      stripeCostCenterTree($tree);
      focusTreeNode($node);
      return;
    }

    const $nextNode = visibleTreeNodes($tree).eq(visibleTreeNodes($tree).index($node) + 1);

    if ($nextNode.length && $nextNode.closest('ul.treeview-list').attr('id') === $node.attr('aria-controls')) {
      focusTreeNode($nextNode);
    }
  }

  function collapseTreeNode($tree, $node) {
    if ($node.is('[data-cost-centers-tree-branch]') && $node.attr('aria-expanded') === 'true') {
      setBranchExpanded($tree, $node, false);
      stripeCostCenterTree($tree);
      focusTreeNode($node);
      return;
    }

    focusTreeNode(parentTreeNode($tree, $node));
  }

  function stripeCostCenterTree($tree) {
    window.setTimeout(function () {
      const $rows = $tree
        .find('> li > .treeview-row, .treeview-list.collapse-show > li > .treeview-row')
        .filter(function () {
          return $(this).parents('.collapse-hidden').length === 0;
        });

      $tree.find('.treeview-row').removeClass('treeview-row-even treeview-row-odd');
      $rows.each(function (index) {
        $(this).addClass(index % 2 === 0 ? 'treeview-row-even' : 'treeview-row-odd');
      });
    }, 0);
  }

  initTable();

  if (window.AppReportUI && typeof window.AppReportUI.init === 'function') {
    window.AppReportUI.init(document);
  }

  $(document).off('submit.costCentersReportFilters', '.js-report-filters').on('submit.costCentersReportFilters', '.js-report-filters', function (event) {
    event.preventDefault();
    reloadCurrentView();
  });

  $(document).off('change.costCentersReportFilters', '.js-report-filter-control').on('change.costCentersReportFilters', '.js-report-filter-control', function () {
    reloadCurrentView();
  });

  $(document).off('click.costCentersReportReset', '.js-report-reset').on('click.costCentersReportReset', '.js-report-reset', function () {
    const $form = $('.js-report-filters');

    if (window.AppReportUI && typeof window.AppReportUI.resetFilters === 'function') {
      window.AppReportUI.resetFilters($form);
    } else if ($form.length) {
      $form.get(0).reset();
    }

    reloadCurrentView();
  });

  $(document).off('click.costCentersReportRefresh', '.js-report-refresh').on('click.costCentersReportRefresh', '.js-report-refresh', function () {
    reloadCurrentView();
  });

  $(document).off('click.costCentersReportExport', '.js-report-export').on('click.costCentersReportExport', '.js-report-export', function (event) {
    event.preventDefault();

    const $link = $(this);
    const url = filteredUrl($link.attr('href'));

    if ($link.data('open-in-new-tab') || $link.attr('target') === '_blank') {
      window.open(url, '_blank', 'noopener');
      return;
    }

    window.location.href = url;
  });

  $(document).off('click.costCentersDelete', '.js-delete-record[data-delete-url], .js-delete-record[data-url]').on('click.costCentersDelete', '.js-delete-record[data-delete-url], .js-delete-record[data-url]', function () {
    const $button = $(this);
    const url = $button.data('delete-url') || $button.data('url');
    const docNum = String($button.data('doc-num') || '').trim();

    if (!url) {
      toast('error', msg('unexpectedError'));
      return;
    }

    confirmDialog(msg('deleteConfirmTitle'), msg('deleteConfirmText'), msg('deleteConfirmYes')).then(function (result) {
      if (!result.isConfirmed) {
        return;
      }
      request(url, 'DELETE').done(function (response) {
        toast('success', response.message);
        selected.delete(docNum);
        reloadCurrentView();
        if (!table && $button.data('redirect-url')) {
          window.location.href = $button.data('redirect-url');
        }
      }).fail(function (xhr) {
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
      });
    });
  });

  $(document).off('click.costCentersRestore', '.js-restore-record[data-restore-url], .js-restore-record[data-url]').on('click.costCentersRestore', '.js-restore-record[data-restore-url], .js-restore-record[data-url]', function () {
    const $button = $(this);
    const url = $button.data('restore-url') || $button.data('url');
    const docNum = String($button.data('doc-num') || '').trim();

    if (!url) {
      toast('error', msg('unexpectedError'));
      return;
    }

    confirmDialog(msg('restoreConfirmTitle'), msg('restoreConfirmText'), msg('restoreConfirmYes')).then(function (result) {
      if (!result.isConfirmed) {
        return;
      }
      request(url, 'PATCH').done(function (response) {
        toast('success', response.message);
        selected.delete(docNum);
        reloadCurrentView();
        if (!table && $button.data('redirect-url')) {
          window.location.href = $button.data('redirect-url');
        }
      }).fail(function (xhr) {
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
      });
    });
  });

  $(document).off('click.costCentersSubmitAction', '.js-cost-center-submit-action').on('click.costCentersSubmitAction', '.js-cost-center-submit-action', function () {
    const $button = $(this);
    const $form = $button.closest('form');

    if ($form.length) {
      $form.find('input[name="submit_action"]').val($button.data('submit-action') || 'save');
    }
  });

  $(document).off('submit.costCentersForm', '#cost-center-form').on('submit.costCentersForm', '#cost-center-form', function (event) {
    event.preventDefault();
    const $form = $(this);

    clearValidation($form);

    if (!snapshotChanged($form)) {
      showFormNotice($form, 'warning', msg('noChanges'));
      toast('info', msg('noChanges'));
      return;
    }

    request($form.attr('action'), $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST', $form.serialize())
      .done(function (response) {
        if (response && response.type === 'no_changes') {
          showFormNotice($form, 'warning', response.message || msg('noChanges'));
          toast('info', response.message || msg('noChanges'));
          return;
        }

        toast('success', (response && response.message) || msg('saved'));

        if (response && (response.redirect || response.redirect_url)) {
          window.location.href = response.redirect || response.redirect_url;
          return;
        }

        if (response && response.reset_form) {
          resetCreateForm($form);
          reloadCurrentView();
          return;
        }

        updateOriginalSnapshot($form, response);
        updateFormUrls($form, response);
      })
      .fail(function (xhr) {
        if (xhr.status === 422) {
          renderValidation($form, xhr);
          return;
        }
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
      });
  });

  $(document).off('select2:select.costCentersParentCode select2:clear.costCentersParentCode change.costCentersParentCode', '#cost-center-form [name="parent_doc_num"]').on('select2:select.costCentersParentCode select2:clear.costCentersParentCode change.costCentersParentCode', '#cost-center-form [name="parent_doc_num"]', function () {
    refreshCostCenterCode($(this).closest('form'));
  });

  $('#cost-center-form').each(function () {
    const $form = $(this);

    if ($form.data('mode') === 'create' && !formFieldValue($form, 'cost_center_code')) {
      refreshCostCenterCode($form);
    }
  });

  $(document).off('submit.costCentersDocumentSettings', '#cost-centers-document-number-settings-form').on('submit.costCentersDocumentSettings', '#cost-centers-document-number-settings-form', function (event) {
    event.preventDefault();
    const $form = $(this);
    clearValidation($form);
    request($form.attr('action'), $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST', $form.serialize())
      .done(function (response) {
        toast('success', response.message || msg('saved'));
      })
      .fail(function (xhr) {
        if (xhr.status === 422) {
          renderValidation($form, xhr);
          return;
        }
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
      });
  });

  $(document).off('input.costCentersValidation change.costCentersValidation', '#cost-center-form input, #cost-center-form select, #cost-center-form textarea').on('input.costCentersValidation change.costCentersValidation', '#cost-center-form input, #cost-center-form select, #cost-center-form textarea', function () {
    const name = String($(this).attr('name') || '').replace(/\[\]$/, '');
    $(this).removeClass('is-invalid');
    $('[data-error-for="' + name + '"]').text('');
  });

  $(document).off('click.costCentersToggleTree', '[data-cost-centers-toggle-tree]').on('click.costCentersToggleTree', '[data-cost-centers-toggle-tree]', function () {
    const $tree = $('[data-cost-centers-tree]');
    const $list = $('[data-cost-centers-list]');
    const showTree = $tree.hasClass('d-none');
    treeVisible = showTree;
    $tree.toggleClass('d-none', !showTree);
    $list.toggleClass('d-none', showTree);
    $('[data-cost-centers-tree-controls]').toggleClass('d-none', !showTree).toggleClass('d-flex', showTree);
    $(this).html('<span class="fas fa-' + (showTree ? 'list' : 'sitemap') + ' me-1"></span>' + (showTree ? msg('listView') : msg('treeView')));
    $('[data-cost-centers-panel-title]').text(showTree ? msg('treeView') : msg('title'));
    if (showTree) {
      reloadTree();
    }
  });

  $(document).off('click.costCentersTreeExpandAll', '[data-cost-centers-tree-expand-all]').on('click.costCentersTreeExpandAll', '[data-cost-centers-tree-expand-all]', function () {
    const $tree = $('#costCentersTreeView');

    if ($tree.length) {
      setTreeExpanded($tree, true);
    }
  });

  $(document).off('click.costCentersTreeCollapseAll', '[data-cost-centers-tree-collapse-all]').on('click.costCentersTreeCollapseAll', '[data-cost-centers-tree-collapse-all]', function () {
    const $tree = $('#costCentersTreeView');

    if ($tree.length) {
      setTreeExpanded($tree, false);
    }
  });
})(jQuery, window, document);
