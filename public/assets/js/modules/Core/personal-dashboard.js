(function (window, document) {
  'use strict';

  const root = document.querySelector('[data-personal-dashboard]');
  const config = window.AppPersonalDashboard || {};

  if (!root || !config.url) {
    return;
  }

  const summaryElement = root.querySelector('[data-personal-summary]');
  const healthElement = root.querySelector('[data-personal-health]');
  const workList = root.querySelector('[data-personal-work-list]');
  const updatesList = root.querySelector('[data-personal-updates-list]');
  let timer = null;
  let inFlight = false;
  let failures = 0;

  function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
  }

  function message(template, replacements) {
    let value = String(template || '');

    Object.keys(replacements || {}).forEach(function (key) {
      value = value.replace(`:${key}`, String(replacements[key]));
    });

    return value;
  }

  function schedule() {
    window.clearTimeout(timer);
    const delay = document.hidden
      ? Number(config.hiddenIntervalMs || 120000)
      : Math.min(300000, Number(config.intervalMs || 45000) * Math.max(1, failures + 1));
    timer = window.setTimeout(refresh, delay);
  }

  function renderCards(cards) {
    (cards || []).forEach(function (card) {
      const element = root.querySelector(`[data-personal-card="${CSS.escape(String(card.key))}"]`);

      if (!element) {
        return;
      }

      const value = element.querySelector('[data-personal-card-value]');
      const meta = element.querySelector('[data-personal-card-meta]');

      if (value && value.textContent !== String(card.value)) {
        value.textContent = String(card.value);
        value.classList.add('is-updated');
        window.setTimeout(function () { value.classList.remove('is-updated'); }, 250);
      }

      if (meta) {
        meta.textContent = card.meta || '';
      }

      element.setAttribute('href', card.url || '#');
    });
  }

  function itemHtml(item, isWork) {
    const badge = isWork
      ? `<span class="badge badge-subtle-${item.severity === 'urgent' ? 'danger' : 'warning'} align-self-start">${escapeHtml(config.messages?.open || '')}</span>`
      : '';

    return `<a class="list-group-item list-group-item-action" href="${escapeHtml(item.url || '#')}">
      <div class="d-flex justify-content-between gap-2"><div><div class="fw-semibold">${escapeHtml(item.title)}</div>
      ${item.body ? `<div class="small text-700">${escapeHtml(item.body)}</div>` : ''}
      <div class="small text-600">${escapeHtml(item.meta || item.time || '')}</div></div>${badge}</div>
    </a>`;
  }

  function renderList(element, items, emptyMessage, isWork) {
    if (!element) {
      return;
    }

    element.innerHTML = items && items.length
      ? items.map(function (item) { return itemHtml(item, isWork); }).join('')
      : `<div class="p-4 text-center text-600" data-personal-empty>${escapeHtml(emptyMessage)}</div>`;
  }

  function render(data) {
    const summary = data.summary || {};
    const required = Number(summary.required_count || 0);
    const overdue = Number(summary.overdue_count || 0);
    const approvals = Number(summary.approval_count || 0);

    if (summaryElement) {
      summaryElement.textContent = required === 0 && overdue === 0 && approvals === 0
        ? (config.messages?.summaryEmpty || '')
        : message(config.messages?.summary, { required: required, overdue: overdue, approvals: approvals });
    }

    if (healthElement) {
      healthElement.textContent = '';
      healthElement.classList.remove('text-danger');
      healthElement.classList.add('d-none');
    }

    renderCards(data.cards || []);
    renderList(workList, data.work_items || [], config.messages?.emptyWork || '', true);
    renderList(updatesList, data.recent_updates || [], config.messages?.emptyUpdates || '', false);
  }

  function refresh() {
    if (inFlight) {
      schedule();
      return;
    }

    inFlight = true;
    window.fetch(config.url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Dashboard refresh failed');
      }

      return response.json();
    }).then(function (payload) {
      failures = 0;
      render(payload.data || {});
    }).catch(function () {
      failures += 1;

      if (healthElement) {
        healthElement.textContent = config.messages?.stale || '';
        healthElement.classList.add('text-danger');
        healthElement.classList.remove('d-none');
      }
    }).finally(function () {
      inFlight = false;
      schedule();
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      refresh();
    } else {
      schedule();
    }
  });
  window.addEventListener('erp:notifications-updated', refresh);
  schedule();
})(window, document);
