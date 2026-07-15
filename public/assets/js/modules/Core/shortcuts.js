(function (window, document) {
  'use strict';

  const addRecordButtonId = 'btn_add_record';
  const logoutButtonId = 'btn_logout';
  const lockScreenButtonId = 'btn_lock_screen';
  const bulkApplyButtonId = 'bulk_action_apply';
  const fileManagerBulkApplyButtonId = 'file_manager_bulk_action_apply';
  const bulkActionsBarSelector = '#bulk_actions_bar';
  const fileManagerBulkActionsBarSelector = '#file_manager_bulk_actions_bar';
  const archiveBulkActionsBarSelector = '.js-archive-bulk-actions-bar';
  const bulkActionSelectSelector = '#bulk_action_select';
  const fileManagerBulkActionSelectSelector = '#file_manager_bulk_action_select';
  const archiveBulkActionSelectSelector = '.js-archive-bulk-action-select';
  const bulkSelectedCountSelector = '#bulk_selected_count';
  const fileManagerBulkSelectedCountSelector = '#file_manager_selected_count';
  const archiveBulkSelectedCountSelector = '.js-archive-selected-count';
  const archiveBulkApplyButtonSelector = '.js-archive-bulk-action-apply';
  const navbarSearchSelector = '#navbar_search_input';
  const dataTableSearchSelector = '.dataTables_filter input[type="search"], .dt-search input[type="search"]';
  const shortcutActionSelector = '[data-shortcut-action]';
  let clickingTargetId = null;
  let clickingShortcutAction = null;

  function isTypingTarget(target) {
    if (!target) {
      return false;
    }

    if (target.isContentEditable || (typeof target.closest === 'function' && target.closest('[contenteditable="true"]'))) {
      return true;
    }

    if (target.classList && target.classList.contains('select2-search__field')) {
      return true;
    }

    const tagName = (target.tagName || '').toLowerCase();

    return tagName === 'textarea' || tagName === 'select' || tagName === 'input';
  }

  function isExistingAndEnabled(element) {
    if (!element || element.disabled || element.getAttribute('aria-disabled') === 'true') {
      return false;
    }

    if (element.classList.contains('disabled')) {
      return false;
    }

    return true;
  }

  function isRichTypingTarget(target) {
    if (!target) {
      return false;
    }

    return !!(target.isContentEditable
      || (typeof target.closest === 'function' && target.closest('[contenteditable="true"], .select2-search__field')));
  }

  function isVisibleAndEnabled(element) {
    if (!isExistingAndEnabled(element)) {
      return false;
    }

    const style = window.getComputedStyle(element);

    return style.display !== 'none'
      && style.visibility !== 'hidden'
      && style.pointerEvents !== 'none'
      && element.getClientRects().length > 0;
  }

  function isAvailableInClosedMenu(element) {
    if (!isExistingAndEnabled(element)) {
      return false;
    }

    if (element.hidden || element.type === 'hidden') {
      return false;
    }

    if (typeof element.closest === 'function' && element.closest('[hidden], .d-none, .disabled, .tab-pane:not(.active)')) {
      return false;
    }

    return true;
  }

  function firstVisibleAndEnabled(selector) {
    const elements = document.querySelectorAll(selector);

    for (let index = 0; index < elements.length; index += 1) {
      if (isVisibleAndEnabled(elements[index])) {
        return elements[index];
      }
    }

    return null;
  }

  function focusAndSelect(element) {
    if (!isVisibleAndEnabled(element)) {
      return false;
    }

    element.focus({ preventScroll: false });

    if (typeof element.select === 'function') {
      element.select();
    }

    return true;
  }

  function shortcutTitles() {
    return window.AppShortcuts || {};
  }

  function applyDataTableSearchTitles(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const title = shortcutTitles().tableSearchTitle;

    if (!title) {
      return;
    }

    scope.querySelectorAll(dataTableSearchSelector).forEach(function (input) {
      input.setAttribute('title', title);
      input.setAttribute('data-bs-title', title);
    });
  }

  function focusNavbarSearch() {
    return focusAndSelect(firstVisibleAndEnabled(navbarSearchSelector));
  }

  function focusDataTableSearch() {
    return focusAndSelect(firstVisibleAndEnabled(dataTableSearchSelector));
  }

  function getShortcutEventInfo(event) {
    return {
      key: event.key || '',
      code: event.code || '',
      keyCode: Number(event.keyCode || event.which || 0),
      which: Number(event.which || 0)
    };
  }

  function hasUsableCode(event) {
    const code = getShortcutEventInfo(event).code;

    return code !== '' && code !== 'Unidentified';
  }

  function isPhysicalCode(event, codes) {
    const shortcutEventInfo = getShortcutEventInfo(event);

    return hasUsableCode(event) && codes.indexOf(shortcutEventInfo.code) !== -1;
  }

  function isLegacyKeyCode(event, keyCodes) {
    const shortcutEventInfo = getShortcutEventInfo(event);

    return keyCodes.indexOf(shortcutEventInfo.keyCode) !== -1
      || keyCodes.indexOf(shortcutEventInfo.which) !== -1;
  }

  function isLegacyKey(event, legacyKeys) {
    if (hasUsableCode(event) || !legacyKeys || legacyKeys.length === 0) {
      return false;
    }

    const key = String(getShortcutEventInfo(event).key || '').toLowerCase();

    return legacyKeys.indexOf(key) !== -1;
  }

  function isPhysicalShortcutKey(event, codes, keyCodes, legacyKeys) {
    return isPhysicalCode(event, codes)
      || isLegacyKeyCode(event, keyCodes)
      || isLegacyKey(event, legacyKeys);
  }

  function isAltPressed(event) {
    const shortcutEventInfo = getShortcutEventInfo(event);

    return event.altKey === true
      || shortcutEventInfo.code === 'AltLeft'
      || shortcutEventInfo.code === 'AltRight'
      || shortcutEventInfo.keyCode === 18
      || shortcutEventInfo.which === 18;
  }

  function isPlainShortcut(event, codes, keyCodes, legacyKeys) {
    return !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey && isPhysicalShortcutKey(event, codes, keyCodes, legacyKeys);
  }

  function isAltShortcut(event, codes, keyCodes, legacyKeys) {
    return isAltPressed(event) && !event.metaKey && !event.shiftKey && isPhysicalShortcutKey(event, codes, keyCodes, legacyKeys);
  }

  function isAltShiftShortcut(event, codes, keyCodes, legacyKeys) {
    return isAltPressed(event) && !event.metaKey && event.shiftKey && isPhysicalShortcutKey(event, codes, keyCodes, legacyKeys);
  }

  function isCtrlShortcut(event, codes, keyCodes, legacyKeys) {
    return event.ctrlKey && !event.altKey && !event.metaKey && !event.shiftKey && isPhysicalShortcutKey(event, codes, keyCodes, legacyKeys);
  }

  function digitFromPhysicalCode(event) {
    if (!hasUsableCode(event)) {
      return null;
    }

    const digitCodeMatch = getShortcutEventInfo(event).code.match(/^(?:Digit|Numpad)([0-9])$/);

    return digitCodeMatch ? digitCodeMatch[1] : null;
  }

  function digitFromLegacyKeyCode(event) {
    const shortcutEventInfo = getShortcutEventInfo(event);

    if (shortcutEventInfo.keyCode >= 48 && shortcutEventInfo.keyCode <= 57) {
      return String(shortcutEventInfo.keyCode - 48);
    }

    if (shortcutEventInfo.keyCode >= 96 && shortcutEventInfo.keyCode <= 105) {
      return String(shortcutEventInfo.keyCode - 96);
    }

    if (shortcutEventInfo.which >= 48 && shortcutEventInfo.which <= 57) {
      return String(shortcutEventInfo.which - 48);
    }

    if (shortcutEventInfo.which >= 96 && shortcutEventInfo.which <= 105) {
      return String(shortcutEventInfo.which - 96);
    }

    return null;
  }

  function digitFromLegacyKey(event) {
    if (hasUsableCode(event)) {
      return null;
    }

    const key = String(getShortcutEventInfo(event).key || '');

    return /^[0-9]$/.test(key) ? key : null;
  }

  function digitShortcut(event) {
    if (!isAltPressed(event) || event.metaKey || event.shiftKey) {
      return null;
    }

    return digitFromPhysicalCode(event)
      || digitFromLegacyKeyCode(event)
      || digitFromLegacyKey(event);
  }

  function isBulkActionSelectTarget(target) {
    return !!(target && typeof target.closest === 'function' && target.closest(bulkActionSelectSelector + ', ' + fileManagerBulkActionSelectSelector + ', ' + archiveBulkActionSelectSelector));
  }

  function selectedBulkRecordsCount() {
    const selectedCount = firstVisibleAndEnabled(bulkSelectedCountSelector + ', ' + fileManagerBulkSelectedCountSelector + ', ' + archiveBulkSelectedCountSelector);

    if (!selectedCount) {
      return 0;
    }

    return Number.parseInt(String(selectedCount.textContent || '').replace(/[^\d]/g, ''), 10) || 0;
  }

  function canApplyBulkAction() {
    const bulkActionsBar = firstVisibleAndEnabled(bulkActionsBarSelector + ', ' + fileManagerBulkActionsBarSelector + ', ' + archiveBulkActionsBarSelector);
    const bulkActionSelect = firstVisibleAndEnabled(bulkActionSelectSelector + ', ' + fileManagerBulkActionSelectSelector + ', ' + archiveBulkActionSelectSelector);
    const bulkApplyButton = firstVisibleAndEnabled('#' + bulkApplyButtonId + ', #' + fileManagerBulkApplyButtonId + ', ' + archiveBulkApplyButtonSelector);

    return isVisibleAndEnabled(bulkActionsBar)
      && selectedBulkRecordsCount() > 0
      && isVisibleAndEnabled(bulkApplyButton)
      && isVisibleAndEnabled(bulkActionSelect)
      && String(bulkActionSelect.value || '').trim() !== '';
  }

  function applyActiveBulkAction() {
    const genericButton = firstVisibleAndEnabled('#' + bulkApplyButtonId);
    const fileManagerButton = firstVisibleAndEnabled('#' + fileManagerBulkApplyButtonId + ', ' + archiveBulkApplyButtonSelector);

    if (canApplyBulkAction() && isVisibleAndEnabled(genericButton)) {
      return clickTarget(bulkApplyButtonId);
    }

    if (canApplyBulkAction() && isVisibleAndEnabled(fileManagerButton)) {
      if (fileManagerButton.id) {
        return clickTarget(fileManagerButton.id);
      }

      fileManagerButton.click();

      return true;
    }

    return false;
  }

  function activeArchiveBulkElements() {
    const bars = document.querySelectorAll(fileManagerBulkActionsBarSelector + ', ' + archiveBulkActionsBarSelector);

    for (let index = 0; index < bars.length; index += 1) {
      const bar = bars[index];

      if (!isVisibleAndEnabled(bar)) {
        continue;
      }

      const root = bar.closest('.file-manager-datatable-card') || document;
      const select = root.querySelector(fileManagerBulkActionSelectSelector + ', ' + archiveBulkActionSelectSelector);
      const applyButton = root.querySelector('#' + fileManagerBulkApplyButtonId + ', ' + archiveBulkApplyButtonSelector);

      if (isVisibleAndEnabled(select) && isVisibleAndEnabled(applyButton)) {
        return { select: select, applyButton: applyButton };
      }
    }

    return null;
  }

  function applyFileManagerBulkDownload() {
    const elements = activeArchiveBulkElements();

    if (!elements || selectedBulkRecordsCount() <= 0) {
      return false;
    }

    const option = elements.select.querySelector('option[value="bulk_download"]');

    if (!option) {
      return false;
    }

    elements.select.value = 'bulk_download';

    elements.applyButton.click();

    return true;
  }

  function applyFileManagerBulkDelete() {
    const elements = activeArchiveBulkElements();

    if (!elements || selectedBulkRecordsCount() <= 0) {
      return false;
    }

    const option = elements.select.querySelector('option[value="bulk_delete"]');

    if (!option) {
      return false;
    }

    elements.select.value = 'bulk_delete';

    elements.applyButton.click();

    return true;
  }

  function currentPath() {
    return window.location.pathname + window.location.search + window.location.hash;
  }

  function updateLockReturnUrl(form) {
    if (!form || typeof form.querySelector !== 'function') {
      return;
    }

    const input = form.querySelector('input[name="return_url"]');

    if (input) {
      input.value = currentPath();
    }
  }

  function clickTarget(id, options) {
    const settings = options || {};
    const target = document.getElementById(id);
    const canClick = settings.requireVisible === false
      ? isExistingAndEnabled(target)
      : isVisibleAndEnabled(target);

    if (!canClick || clickingTargetId !== null) {
      return false;
    }

    clickingTargetId = id;
    target.click();

    window.setTimeout(function () {
      clickingTargetId = null;
    }, 600);

    return true;
  }

  function shortcutActionElements(action) {
    return document.querySelectorAll(shortcutActionSelector + '[data-shortcut-action="' + action + '"]');
  }

  function shortcutTarget(action, options) {
    const settings = options || {};
    const elements = shortcutActionElements(action);
    let firstExisting = null;

    for (let index = 0; index < elements.length; index += 1) {
      if (!isExistingAndEnabled(elements[index])) {
        continue;
      }

      if (!firstExisting && isAvailableInClosedMenu(elements[index])) {
        firstExisting = elements[index];
      }

      if (isVisibleAndEnabled(elements[index])) {
        return elements[index];
      }
    }

    return settings.allowHidden === true ? firstExisting : null;
  }

  function clickShortcutAction(action, options) {
    const target = shortcutTarget(action, options);

    if (!target || clickingShortcutAction !== null) {
      return false;
    }

    clickingShortcutAction = action;
    target.click();

    window.setTimeout(function () {
      clickingShortcutAction = null;
    }, 600);

    return true;
  }

  function isCalendarEventModalOpen() {
    const modal = document.getElementById('calendarEventModal');
    const detailsModal = document.getElementById('calendarEventDetailsModal');

    return !!((modal && modal.classList.contains('show')) || (detailsModal && detailsModal.classList.contains('show')));
  }

  function clickCalendarShortcutAction(formAction) {
    if (!isCalendarEventModalOpen()) {
      return false;
    }

    const action = {
      'form.back': 'calendar.cancel',
      'form.save': 'calendar.save',
      'form.save_edit': 'calendar.edit',
      'form.edit': 'calendar.edit',
      'form.delete': 'calendar.delete'
    }[formAction] || null;

    return action ? clickShortcutAction(action) : false;
  }

  window.AppShortcuts = Object.assign({}, window.AppShortcuts || {}, {
    applyDataTableSearchTitles: applyDataTableSearchTitles,
    isAltPressed: isAltPressed,
    isAltShortcut: isAltShortcut,
    isTypingTarget: isTypingTarget,
    isVisibleAndEnabled: isVisibleAndEnabled
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      applyDataTableSearchTitles(document);
    });
  } else {
    applyDataTableSearchTitles(document);
  }

  document.addEventListener('submit', function (event) {
    if (event.target && event.target.matches('[data-lock-screen-form]')) {
      updateLockReturnUrl(event.target);
    }
  }, true);

  document.addEventListener('keydown', function (event) {
    const isGlobalSearch = isPlainShortcut(event, ['Slash', 'NumpadDivide'], [191, 111], ['/']);
    const isDataTableSearch = isAltShortcut(event, ['Slash', 'NumpadDivide'], [191, 111], ['/']);
    const isBulkApply = isCtrlShortcut(event, ['Enter', 'NumpadEnter'], [13], ['enter']);
    const isAltQ = isAltShortcut(event, ['KeyQ'], [81], ['q']);
    const isAltK = isAltShortcut(event, ['KeyK'], [75], ['k']);
    const isAltN = isAltShortcut(event, ['KeyN'], [78], ['n']);
    const isAltF = isAltShortcut(event, ['KeyF'], [70], ['f']);
    const isAltU = isAltShortcut(event, ['KeyU'], [85], ['u']);
    const isAltShiftD = isAltShiftShortcut(event, ['KeyD'], [68], ['d']);
    const isAltShiftX = isAltShiftShortcut(event, ['KeyX'], [88], ['x']);
    const shortcutDigit = digitShortcut(event);
    const formShortcutAction = shortcutDigit !== null ? {
      0: 'form.back',
      1: 'form.save',
      2: 'form.save_view',
      3: 'form.save_edit',
      4: 'form.save_back',
      6: 'form.save_clone',
      9: 'form.delete'
    }[shortcutDigit] : null;

    if (!isAltN && !isAltF && !isAltU && !isAltShiftD && !isAltShiftX && !isGlobalSearch && !isDataTableSearch && !isBulkApply && !isAltQ && !isAltK && !formShortcutAction) {
      return;
    }

    if (formShortcutAction && !isTypingTarget(event.target)) {
      if (clickCalendarShortcutAction(formShortcutAction)) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }

      let action = formShortcutAction;

      if (action === 'form.save_edit' && !shortcutTarget(action, { allowHidden: true })) {
        action = 'form.edit';
      }

      if (action === 'form.save_clone' && !shortcutTarget(action, { allowHidden: true })) {
        action = 'form.clone';
      }

      const allowHidden = action.indexOf('form.save_') === 0;

      if (clickShortcutAction(action, { allowHidden: allowHidden })) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
    }

    if (isAltK && clickCalendarShortcutAction('form.back')) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (
      (isAltN || isAltF || isAltU || isAltShiftD || isAltShiftX || isGlobalSearch || isDataTableSearch || isAltQ || isAltK || isBulkApply)
      && isTypingTarget(event.target)
      && !(isBulkApply && isBulkActionSelectTarget(event.target))
    ) {
      return;
    }

    if (isBulkApply && applyActiveBulkAction()) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isAltF && clickShortcutAction('file-manager.create-folder')) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isAltU && clickShortcutAction('file-manager.upload')) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isAltShiftD && applyFileManagerBulkDownload()) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isAltShiftX && applyFileManagerBulkDelete()) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isAltN && clickShortcutAction('calendar.create')) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isAltN && clickTarget(addRecordButtonId)) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isAltQ && clickTarget(logoutButtonId, { requireVisible: false })) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isAltK && clickTarget(lockScreenButtonId, { requireVisible: false })) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isDataTableSearch && focusDataTableSearch()) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (isGlobalSearch && focusNavbarSearch()) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);
})(window, document);
