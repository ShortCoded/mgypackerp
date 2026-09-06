(function (window, document) {
  'use strict';

  const root = document.querySelector('[data-navigation-search]');
  const config = window.AppNavigationSearch || {};

  if (!root || !config.searchUrl) {
    return;
  }

  const input = root.querySelector('#navbar_search_input');
  const dropdown = root.querySelector('.dropdown-menu');
  const resultsRoot = root.querySelector('[data-navigation-search-results]');
  const closeButton = root.querySelector('[data-bs-dismiss="search"] button');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let debounceTimer = null;
  let abortController = null;
  let activeQuery = null;
  let results = [];
  let activeIndex = -1;
  let open = false;
  const responseCache = new Map();
  const responseCacheMilliseconds = Math.max(1000, Number(config.cacheMs || 30000));

  function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = value || '';
    return element.innerHTML;
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

  function showDropdown() {
    dropdown.classList.add('show');
    input.setAttribute('aria-expanded', 'true');
    open = true;
  }

  function closeDropdown(blurInput) {
    dropdown.classList.remove('show');
    input.setAttribute('aria-expanded', 'false');
    activeIndex = -1;
    open = false;
    syncActiveItem();

    if (blurInput) {
      input.blur();
    }
  }

  function sectionTitle(section) {
    if (section === 'recent') {
      return messages().recent || '';
    }

    return messages().results || '';
  }

  function renderState(html) {
    resultsRoot.innerHTML = html;
    results = [];
    activeIndex = -1;
    showDropdown();
  }

  function renderLoading() {
    renderState(`<div class="px-x1 py-3 text-center text-600 fs-10">
      <span class="fas fa-circle-notch fa-spin me-2"></span>${escapeHtml(messages().loading || '')}
    </div>`);
  }

  function renderHelp() {
    renderState(`<div class="px-x1 py-3 text-center">
      <span class="fas fa-search text-400 fs-6 mb-2"></span>
      <p class="mb-1 text-700 fw-semibold fs-10">${escapeHtml(messages().startTyping || '')}</p>
      <p class="mb-0 text-600 fs-11">${escapeHtml(messages().keyboardHint || '')}</p>
    </div>`);
  }

  function renderNoResults() {
    renderState(`<div class="px-x1 py-3 text-center">
      <span class="fas fa-search text-400 fs-6 mb-2"></span>
      <p class="mb-0 text-700 fw-semibold fs-10">${escapeHtml(messages().noResults || '')}</p>
    </div>`);
  }

  function renderResults(section, items) {
    results = Array.isArray(items) ? items : [];
    activeIndex = results.length ? 0 : -1;

    if (!results.length && section === 'recent') {
      renderHelp();
      return;
    }

    if (!results.length) {
      renderNoResults();
      return;
    }

    const clearButton = section === 'recent' && config.recentClearUrl
      ? `<button class="btn btn-link btn-sm p-0 fs-11" type="button" data-navigation-search-clear>${escapeHtml(messages().clearRecent || '')}</button>`
      : '';

    resultsRoot.innerHTML = `<div class="d-flex align-items-center justify-content-between px-x1 pt-2 pb-1">
        <h6 class="dropdown-header fw-medium text-uppercase fs-11 p-0">${escapeHtml(sectionTitle(section))}</h6>
        ${clearButton}
      </div>
      ${results.map(resultHtml).join('')}
      <div class="px-x1 py-2 text-600 fs-11 border-top">${escapeHtml(messages().keyboardHint || '')}</div>`;
    showDropdown();
    syncActiveItem();
  }

  function resultHtml(item, index) {
    const parentPath = item.parent_path
      ? `<div class="fs-11 text-600 text-truncate">${escapeHtml(item.parent_path)}</div>`
      : '';

    return `<button class="dropdown-item px-x1 py-2 navigation-search-item" type="button"
        data-navigation-search-item data-index="${index}">
      <div class="d-flex align-items-center min-w-0">
        <span class="${escapeHtml(item.icon || 'fas fa-circle')} text-500 me-2"></span>
        <div class="min-w-0 flex-1">
          <div class="fw-semibold text-900 title text-truncate">${escapeHtml(item.title)}</div>
          ${parentPath}
        </div>
        <span class="fas fa-level-down-alt text-400 ms-2 fs-11" title="${escapeHtml(messages().openPage || '')}"></span>
      </div>
    </button>`;
  }

  function syncActiveItem() {
    root.querySelectorAll('[data-navigation-search-item]').forEach(function (element) {
      const isActive = Number(element.getAttribute('data-index')) === activeIndex;
      element.classList.toggle('active', isActive);
      element.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
  }

  function moveActive(step) {
    if (!results.length) {
      return;
    }

    activeIndex = (activeIndex + step + results.length) % results.length;
    syncActiveItem();
  }

  function selectedItem() {
    if (!results.length) {
      return null;
    }

    if (activeIndex < 0 || activeIndex >= results.length) {
      return results[0];
    }

    return results[activeIndex];
  }

  function openItem(item) {
    if (!item || !item.url) {
      return;
    }

    request(config.recentStoreUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken
      },
      body: JSON.stringify({
        route_name: item.route_name || '',
        url: item.url
      })
    }).finally(function () {
      window.location.href = item.url;
    });
  }

  function queryUrl(query) {
    const url = new URL(config.searchUrl, window.location.origin);

    if (query) {
      url.searchParams.set('q', query);
    }

    return url.toString();
  }

  function fetchResults() {
    const query = input.value.trim();
    const cached = responseCache.get(query);

    if (cached && cached.expiresAt > Date.now()) {
      if (abortController) {
        abortController.abort();
        abortController = null;
        activeQuery = null;
      }

      renderResults(cached.section, cached.results);
      return;
    }

    if (activeQuery === query && abortController) {
      return;
    }

    if (abortController) {
      abortController.abort();
    }

    const currentController = new AbortController();
    abortController = currentController;
    activeQuery = query;
    renderLoading();

    request(queryUrl(query), { signal: currentController.signal })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Search failed');
        }

        return response.json();
      })
      .then(function (payload) {
        const data = payload.data || {};
        const section = data.section || (query ? 'results' : 'recent');
        const items = data.results || [];

        responseCache.set(query, {
          expiresAt: Date.now() + responseCacheMilliseconds,
          results: items,
          section: section
        });

        if (input.value.trim() === query) {
          renderResults(section, items);
        }
      })
      .catch(function (error) {
        if (error.name === 'AbortError') {
          return;
        }

        renderNoResults();
      })
      .finally(function () {
        if (abortController === currentController) {
          abortController = null;
          activeQuery = null;
        }
      });
  }

  function scheduleFetch() {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(fetchResults, Number(config.debounceMs || 250));
  }

  function clearRecent() {
    request(config.recentClearUrl, { method: 'DELETE' })
      .then(function () {
        responseCache.delete('');
        fetchResults();
      })
      .catch(function () {
        responseCache.delete('');
        fetchResults();
      });
  }

  input.addEventListener('focus', fetchResults);
  input.addEventListener('input', scheduleFetch);
  input.addEventListener('keydown', function (event) {
    if (event.code === 'Escape' || Number(event.keyCode || event.which || 0) === 27) {
      event.preventDefault();
      closeDropdown(true);
      return;
    }

    if (event.code === 'ArrowDown' || Number(event.keyCode || event.which || 0) === 40) {
      event.preventDefault();
      if (!open) {
        fetchResults();
      } else {
        moveActive(1);
      }
      return;
    }

    if (event.code === 'ArrowUp' || Number(event.keyCode || event.which || 0) === 38) {
      event.preventDefault();
      moveActive(-1);
      return;
    }

    if (event.code === 'Enter' || Number(event.keyCode || event.which || 0) === 13) {
      const item = selectedItem();

      if (item) {
        event.preventDefault();
        openItem(item);
      }
    }
  });

  resultsRoot.addEventListener('mouseover', function (event) {
    const item = event.target.closest('[data-navigation-search-item]');

    if (item) {
      activeIndex = Number(item.getAttribute('data-index'));
      syncActiveItem();
    }
  });

  resultsRoot.addEventListener('click', function (event) {
    const clearButton = event.target.closest('[data-navigation-search-clear]');

    if (clearButton) {
      event.preventDefault();
      clearRecent();
      return;
    }

    const itemElement = event.target.closest('[data-navigation-search-item]');

    if (!itemElement) {
      return;
    }

    event.preventDefault();
    openItem(results[Number(itemElement.getAttribute('data-index'))]);
  });

  document.addEventListener('click', function (event) {
    if (!root.contains(event.target)) {
      closeDropdown(false);
    }
  });

  if (closeButton) {
    closeButton.addEventListener('click', function () {
      closeDropdown(true);
    });
  }
})(window, document);
