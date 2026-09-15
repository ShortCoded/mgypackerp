(function (window, document, $) {
  'use strict';

  const root = document.querySelector('[data-chat-root]');
  const config = window.AppChat || {};

  if (!root || !config.routes) {
    return;
  }

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const conversationList = root.querySelector('[data-chat-conversations]');
  const messageList = root.querySelector('[data-chat-message-list]');
  const messagesPane = root.querySelector('[data-chat-messages]');
  const emptyState = root.querySelector('[data-chat-empty]');
  const panel = root.querySelector('[data-chat-panel]');
  const panelHeader = root.querySelector('[data-chat-panel-header]');
  const alertContainer = root.querySelector('[data-chat-alert]');
  const activeTitle = root.querySelector('[data-chat-active-title]');
  const activeStatus = root.querySelector('[data-chat-active-status]');
  const searchInput = root.querySelector('[data-chat-search]');
  const composer = root.querySelector('[data-chat-composer]');
  const bodyInput = root.querySelector('[data-chat-input]');
  const sendButton = root.querySelector('[data-chat-send-button]');
  const attachmentInput = root.querySelector('[data-chat-attachment-input]');
  const previewStack = root.querySelector('[data-chat-preview-stack]');
  const replyPreview = root.querySelector('[data-chat-reply-preview]');
  const replyInput = root.querySelector('[data-chat-reply-input]');
  const attachmentPreview = root.querySelector('[data-chat-attachment-preview]');
  const loadOlderButton = root.querySelector('[data-chat-load-older]');
  const newConversationForm = document.querySelector('[data-chat-new-conversation-form]');
  const forwardForm = document.querySelector('[data-chat-forward-form]');
  const mobileLayout = window.matchMedia('(max-width: 767.98px)');

  const state = {
    conversations: [],
    activeConversation: null,
    activeConversationId: null,
    latestMessageId: null,
    oldestMessageId: null,
    initialConversationId: config.initialConversation || null,
    initialConversationResolved: false,
    replyToMessage: null,
    selectedFiles: [],
    listTimer: null,
    messageTimer: null,
    visibleDebounceTimer: null,
    listInFlight: false,
    messageInFlight: false,
    failureCount: 0,
    conversationUnreadBaselineReady: false,
    conversationUnreadCounts: new Map(),
    pendingClientMessageId: null,
    pendingClientMessageSignature: null
  };
  const attachmentRules = {
    maxFiles: 5,
    maxBytes: 5120 * 1024,
    allowedExtensions: new Set(['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'])
  };
  const fallbackEmojis = [
    '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '🙂', '🙃', '😉', '😊', '😇', '🥰', '😍', '🤩',
    '😘', '😗', '😚', '😋', '😛', '😜', '🤪', '😎', '🥳', '😏', '😌', '😔', '😢', '😭', '😤', '😡',
    '🤔', '🤨', '😐', '😮', '😲', '😴', '🤒', '🤕', '🤧', '😷', '🤝', '👍', '👎', '👏', '🙌', '🙏',
    '💪', '👀', '💬', '💡', '📌', '📎', '✅', '☑️', '❌', '⚠️', '🔥', '⭐', '🎉', '🎯', '🚀', '❤️',
    '💙', '💚', '💛', '🧡', '💜', '📝', '📄', '📊', '📈', '📉', '🕐', '📅', '🏢', '💼', '💰', '📦'
  ];

  function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = value || '';
    return element.innerHTML;
  }

  function escapeAttribute(value) {
    return escapeHtml(value).replace(/"/g, '&quot;');
  }

  function message(key) {
    return (config.messages && config.messages[key]) || '';
  }

  function route(name, identifier) {
    return String(config.routes[name] || '')
      .replace('__CONVERSATION__', encodeURIComponent(identifier || ''))
      .replace('__MESSAGE__', encodeURIComponent(identifier || ''));
  }

  function request(url, options) {
    const headers = Object.assign({
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken
    }, (options && options.headers) || {});

    return window.fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: headers
    }, options || {}, { headers: headers }));
  }

  function jitter() {
    const intervals = config.intervals || {};
    const min = Number(intervals.jitterMinMs || 500);
    const max = Number(intervals.jitterMaxMs || 2500);

    return Math.floor(Math.random() * (max - min + 1)) + min;
  }

  function delayFor(type) {
    const intervals = config.intervals || {};
    const base = document.hidden
      ? Number(intervals.hiddenMs || 60000)
      : Number(type === 'messages' ? intervals.conversationMs || 8000 : intervals.listMs || 20000);
    const backoff = state.failureCount > 0 ? Math.min(state.failureCount * base, 300000) : base;

    return backoff + jitter();
  }

  function scheduleList(delay) {
    window.clearTimeout(state.listTimer);
    state.listTimer = window.setTimeout(fetchConversations, delay === undefined ? delayFor('list') : delay);
  }

  function scheduleMessages(delay) {
    window.clearTimeout(state.messageTimer);

    if (!state.activeConversationId) {
      return;
    }

    state.messageTimer = window.setTimeout(fetchNewMessages, delay === undefined ? delayFor('messages') : delay);
  }

  function showAlert(text) {
    if (!alertContainer || !text) {
      return;
    }

    alertContainer.innerHTML = `<div class="alert alert-danger mb-3">${escapeHtml(text)}</div>`;
  }

  function clearAlert() {
    if (alertContainer) {
      alertContainer.innerHTML = '';
    }
  }

  function showToast(icon, title) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, title);
    }
  }

  function validationMessage(payload) {
    if (!payload || !payload.errors) {
      return payload && payload.message ? payload.message : message('unexpectedError');
    }

    const errors = Object.keys(payload.errors).reduce(function (carry, key) {
      return carry.concat(payload.errors[key] || []);
    }, []);

    return errors[0] || payload.message || message('validationFailed');
  }

  function avatarHtml(initials, extraClass) {
    return `<div class="avatar-name rounded-circle ${extraClass || 'bg-primary-subtle text-primary'}"><span>${escapeHtml(initials || '?')}</span></div>`;
  }

  function presenceStatus(conversation) {
    return (conversation && conversation.user && conversation.user.presence) || {
      status: 'offline',
      label: message('offline'),
      last_seen_label: ''
    };
  }

  function renderConversations() {
    if (!conversationList) {
      return;
    }

    const term = String(searchInput?.value || '').trim().toLowerCase();
    const conversations = state.conversations.filter(function (conversation) {
      return !term || String(conversation.title || '').toLowerCase().includes(term);
    });

    if (!conversations.length) {
      conversationList.innerHTML = `<div class="p-4 text-center text-600">${escapeHtml(message('noConversations'))}</div>`;
      return;
    }

    conversationList.innerHTML = `<div class="nav nav-tabs border-0 flex-column" role="tablist" aria-orientation="vertical">${conversations.map(function (conversation) {
      const unread = Number(conversation.unread_count || 0);
      const isActive = conversation.id === state.activeConversationId;
      const latest = conversation.latest_message ? escapeHtml(conversation.latest_message) : '&nbsp;';
      const time = conversation.latest_message_time ? escapeHtml(conversation.latest_message_time) : '';
      const initials = (conversation.user && conversation.user.initials) || '?';
      const presence = presenceStatus(conversation);
      const statusClass = presence.status === 'online' ? 'status-online' : '';
      const mutedIcon = conversation.is_muted ? `<span class="fas fa-bell-slash text-400 ms-1" title="${escapeHtml(message('muteChat'))}"></span>` : '';

      return `<div class="hover-actions-trigger chat-contact nav-item ${isActive ? 'active' : ''} ${unread > 0 ? 'unread-message' : ''}" role="tab" tabindex="0" aria-selected="${isActive ? 'true' : 'false'}" data-chat-conversation="${escapeAttribute(conversation.id)}">
        <div class="d-md-none d-lg-block">
          <div class="dropdown dropdown-active-trigger dropdown-chat">
            <button class="hover-actions btn btn-link btn-sm text-400 dropdown-caret-none dropdown-toggle end-0 fs-9 mt-4 me-1 z-1 pb-2 mb-n2" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
              <span class="fas fa-cog" data-fa-transform="shrink-3 down-4"></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end border py-2 rounded-2">
              <button class="dropdown-item" type="button" data-chat-action="mute" data-chat-conversation-id="${escapeAttribute(conversation.id)}">${escapeHtml(conversation.is_muted ? message('unmuteChat') : message('muteChat'))}</button>
            </div>
          </div>
        </div>
        <div class="d-flex p-3">
          <div class="avatar avatar-xl ${statusClass}">${avatarHtml(initials)}</div>
          <div class="flex-1 chat-contact-body ms-2 d-md-none d-lg-block">
            <div class="d-flex justify-content-between">
              <h6 class="mb-0 chat-contact-title">${escapeHtml(conversation.title)}${mutedIcon}</h6>
              <span class="message-time fs-11">${time}</span>
            </div>
            <div class="min-w-0">
              <div class="chat-contact-content pe-3">${latest}</div>
              <div class="position-absolute bottom-0 end-0 hover-hide">
                ${unread > 0 ? `<span class="badge rounded-pill bg-danger" title="${escapeHtml(message('unreadMessages'))}">${unread > 99 ? '99+' : unread}</span>` : ''}
              </div>
            </div>
          </div>
        </div>
      </div>`;
    }).join('')}</div>`;
  }

  function renderHeader(conversation) {
    if (!conversation) {
      return;
    }

    const presence = presenceStatus(conversation);

    activeTitle.textContent = conversation.title || '';
    if (activeStatus) {
      activeStatus.textContent = presence.last_seen_label || presence.label || message('offline');
    }
    panelHeader?.classList.remove('d-none');
    const muteButton = root.querySelector('[data-chat-action="mute"]:not([data-chat-conversation-id])');

    if (muteButton) {
      muteButton.textContent = conversation.is_muted ? message('unmuteChat') : message('muteChat');
    }
  }

  function fileAttachmentHtml(attachment, own) {
    const name = escapeHtml(attachment.name || message('attachments'));
    const size = attachment.size_label ? `<span class="text-${own ? 'white-50' : '500'} fs-11 ms-2">${escapeHtml(attachment.size_label)}</span>` : '';
    const url = escapeAttribute(attachment.url || '#!');

    return `<a class="d-flex align-items-center gap-2 mt-2 text-decoration-none ${own ? 'text-white' : 'text-700'}" href="${url}" target="_blank" rel="noopener">
      <span class="fas fa-paperclip"></span><span class="text-truncate">${name}</span>${size}
    </a>`;
  }

  function singleImageHtml(attachment) {
    const name = escapeAttribute(attachment.name || message('attachments'));
    const url = escapeAttribute(attachment.url || '#!');

    return `<a href="${url}" target="_blank" rel="noopener">
      <img class="rounded" src="${url}" alt="${name}" width="150">
    </a>`;
  }

  function imageGalleryHtml(images) {
    return `<div class="chat-message chat-gallery">
      <div class="row mx-n1">${images.map(function (attachment) {
        const name = escapeAttribute(attachment.name || message('attachments'));
        const url = escapeAttribute(attachment.url || '#!');

        return `<div class="col-6 col-md-4 px-1" style="min-width: 50px;">
          <a href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${name}" class="img-fluid rounded mb-2"></a>
        </div>`;
      }).join('')}</div>
    </div>`;
  }

  function attachmentContentHtml(attachments, own, hasBody) {
    const images = (attachments || []).filter(function (attachment) {
      return attachment.is_image;
    });
    const files = (attachments || []).filter(function (attachment) {
      return !attachment.is_image;
    });
    const fileLinks = files.map(function (attachment) {
      return fileAttachmentHtml(attachment, own);
    }).join('');

    if (images.length > 1 && !hasBody) {
      return imageGalleryHtml(images) + fileLinks;
    }

    const imageLinks = images.map(singleImageHtml).join('');

    return imageLinks + fileLinks;
  }

  function attachmentHtml(attachment, own) {
    if (attachment.is_image) {
      return singleImageHtml(attachment);
    }

    return fileAttachmentHtml(attachment, own);
  }

  function hoverActionsHtml(side, messageId) {
    const marginClass = side === 'own' ? 'me-2' : 'ms-2';

    return `<ul class="hover-actions position-relative list-inline mb-0 text-400 ${marginClass}">
      <li class="list-inline-item"><a class="chat-option" href="#!" tabindex="-1" data-chat-reply-message="${escapeAttribute(messageId)}" title="${escapeHtml(message('reply'))}"><span class="fas fa-reply"></span></a></li>
      <li class="list-inline-item"><a class="chat-option" href="#!" tabindex="-1" data-chat-forward-message="${escapeAttribute(messageId)}" title="${escapeHtml(message('forward'))}"><span class="fas fa-share"></span></a></li>
    </ul>`;
  }

  function referenceHtml(reference) {
    if (!reference) {
      return '';
    }

    return `<div class="chat-reply-block rounded-2 p-2 mb-2">
      <div class="chat-reply-block-title fw-semibold fs-11">${escapeHtml(reference.title || '')}</div>
      <div class="chat-reply-block-snippet fs-11 text-truncate">${escapeHtml(reference.snippet || '')}</div>
    </div>`;
  }

  function messageMetaHtml(messageItem) {
    if (!messageItem.forwarded_from) {
      return '';
    }

    return `<div class="chat-forwarded-meta fs-11 mb-1">${escapeHtml(messageItem.forwarded_from.label || '')}</div>`;
  }

  function messageSnippet(messageItem) {
    const body = String(messageItem.body || '').trim();

    if (body) {
      return body.length > 120 ? body.slice(0, 117) + '...' : body;
    }

    return (messageItem.attachments || []).length ? message('attachment') : message('message');
  }

  function receiptHtml(messageItem) {
    if (!messageItem.is_own) {
      return '';
    }

    const read = messageItem.read_status === 'read';
    const icon = read ? 'fa-check-double text-info' : 'fa-check text-success';
    const label = read ? message('read') : message('sent');

    return `<span class="fas ${icon} ms-2" title="${escapeHtml(label)}" data-chat-receipt="${read ? 'read' : 'sent'}"></span>`;
  }

  function messageBubble(messageItem) {
    const own = !!messageItem.is_own;
    const body = escapeHtml(messageItem.body || '').replace(/\n/g, '<br>');
    const attachments = attachmentContentHtml(messageItem.attachments || [], own, body !== '');
    const sentAt = escapeHtml(messageItem.sent_at || '');
    const reply = messageItem.reply_to ? referenceHtml({
      title: `${message('replyingTo')} ${messageItem.reply_to.sender_name || ''}`,
      snippet: messageItem.reply_to.snippet || ''
    }) : '';
    const forwarded = messageMetaHtml(messageItem);

    if (own) {
      return `<div class="d-flex p-3" data-chat-message="${escapeHtml(messageItem.id)}" data-sent-at="${sentAt}" data-date-key="${escapeAttribute(messageItem.date_key || '')}" data-sender-name="${escapeAttribute(messageItem.sender?.name || '')}" data-message-snippet="${escapeAttribute(messageSnippet(messageItem))}">
        <div class="flex-1 d-flex justify-content-end">
          <div class="w-100 w-xxl-75">
            <div class="hover-actions-trigger d-flex flex-end-center">
              ${hoverActionsHtml('own', messageItem.id)}
              ${attachments.indexOf('chat-gallery') !== -1 && !body && !reply && !forwarded ? attachments : `<div class="bg-primary text-white p-2 rounded-2 chat-message" data-bs-theme="light">${forwarded}${reply}${body ? `<p class="mb-0">${body}</p>` : ''}${attachments}</div>`}
            </div>
            <div class="text-400 fs-11 text-end">${escapeHtml(messageItem.sent_at_label || '')}${receiptHtml(messageItem)}</div>
          </div>
        </div>
      </div>`;
    }

    const initials = (messageItem.sender && messageItem.sender.initials) || '?';

    return `<div class="d-flex p-3" data-chat-message="${escapeHtml(messageItem.id)}" data-sent-at="${sentAt}" data-date-key="${escapeAttribute(messageItem.date_key || '')}" data-sender-name="${escapeAttribute(messageItem.sender?.name || '')}" data-message-snippet="${escapeAttribute(messageSnippet(messageItem))}">
      <div class="avatar avatar-l me-2">${avatarHtml(initials)}</div>
      <div class="flex-1">
        <div class="w-xxl-75">
          <div class="hover-actions-trigger d-flex align-items-center">
            ${attachments.indexOf('chat-gallery') !== -1 && !body && !reply && !forwarded ? attachments : `<div class="chat-message bg-200 p-2 rounded-2">${forwarded}${reply}${body ? `<div>${body}</div>` : ''}${attachments}</div>`}
            ${hoverActionsHtml('received', messageItem.id)}
          </div>
          <div class="text-400 fs-11"><span>${escapeHtml(messageItem.sent_at_label || '')}</span></div>
        </div>
      </div>
    </div>`;
  }

  function dateSeparatorHtml(messageItem) {
    return `<div class="text-center fs-11 text-500 my-2" data-chat-date-separator="${escapeAttribute(messageItem.date_key || '')}"><span>${escapeHtml(messageItem.date_label || '')}</span></div>`;
  }

  function messagesHtml(messages, previousDateKey) {
    let currentDateKey = previousDateKey || null;

    return messages.map(function (messageItem) {
      const separator = messageItem.date_key && messageItem.date_key !== currentDateKey
        ? dateSeparatorHtml(messageItem)
        : '';

      currentDateKey = messageItem.date_key || currentDateKey;

      return separator + messageBubble(messageItem);
    }).join('');
  }

  function lastRenderedDateKey() {
    const items = Array.from(messageList?.querySelectorAll('[data-chat-message]') || []);
    const last = items[items.length - 1];

    if (!last) {
      return null;
    }

    return last.getAttribute('data-date-key');
  }

  function renderMessages(messages) {
    if (!messageList) {
      return;
    }

    if (!messages.length) {
      messageList.innerHTML = '<div data-chat-empty-messages></div>';
      state.latestMessageId = null;
      state.oldestMessageId = null;
      loadOlderButton?.classList.add('d-none');
      return;
    }

    messageList.innerHTML = messagesHtml(messages);
    state.oldestMessageId = messages[0].id;
    state.latestMessageId = messages[messages.length - 1].id;
    loadOlderButton?.classList.toggle('d-none', messages.length < 50);
    scrollToBottom();
  }

  function appendMessages(messages) {
    if (!messageList || !messages.length) {
      return;
    }

    if (messageList.querySelector('[data-chat-empty-messages]')) {
      messageList.innerHTML = '';
    }

    const existing = new Set(Array.from(messageList.querySelectorAll('[data-chat-message]')).map(function (item) {
      return item.getAttribute('data-chat-message');
    }));

    const fresh = messages.filter(function (messageItem) {
      return !existing.has(messageItem.id);
    });

    if (!fresh.length) {
      return;
    }

    messageList.insertAdjacentHTML('beforeend', messagesHtml(fresh, lastRenderedDateKey()));
    state.latestMessageId = fresh[fresh.length - 1].id;
    scrollToBottom();
  }

  function updateReceipts(otherLastReadAt) {
    if (!messageList || !otherLastReadAt) {
      return;
    }

    const readTime = Date.parse(otherLastReadAt);

    if (Number.isNaN(readTime)) {
      return;
    }

    messageList.querySelectorAll('[data-chat-message][data-sent-at]').forEach(function (row) {
      const receipt = row.querySelector('[data-chat-receipt]');
      const sentAt = Date.parse(row.getAttribute('data-sent-at') || '');

      if (!receipt || Number.isNaN(sentAt) || sentAt > readTime) {
        return;
      }

      receipt.classList.remove('fa-check', 'text-success');
      receipt.classList.add('fa-check-double', 'text-info');
      receipt.setAttribute('data-chat-receipt', 'read');
      receipt.setAttribute('title', message('read'));
    });
  }

  function notifyForUnreadIncreases(conversations) {
    const nextCounts = new Map();

    conversations.forEach(function (conversation) {
      const id = String(conversation.id || '');
      const unread = Number(conversation.unread_count || 0);

      if (!id) {
        return;
      }

      nextCounts.set(id, unread);

      if (id === state.activeConversationId && conversation.other_last_read_at) {
        updateReceipts(conversation.other_last_read_at);
      }

    });

    state.conversationUnreadCounts = nextCounts;

    if (!state.conversationUnreadBaselineReady) {
      state.conversationUnreadBaselineReady = true;
      return;
    }

  }

  function scrollToBottom() {
    if (messagesPane) {
      messagesPane.scrollTop = messagesPane.scrollHeight;
    }
  }

  function openPanel() {
    emptyState?.classList.add('d-none');
    panel?.classList.remove('d-none');
    composer?.classList.remove('d-none');
    root.classList.remove('contacts-list-show');

    if (!mobileLayout.matches) {
      bodyInput?.focus();
    }
  }

  function updateLocation(conversationId) {
    if (!window.history || !conversationId) {
      return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('conversation', conversationId);
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }

  function maybeOpenInitialConversation() {
    if (state.activeConversationId || state.initialConversationResolved || !state.initialConversationId || !state.conversations.length) {
      return;
    }

    const requested = state.conversations.find(function (conversation) {
      return conversation.id === state.initialConversationId;
    });

    state.initialConversationResolved = true;

    if (requested && requested.id) {
      loadConversation(requested.id, { replaceUrl: false });
    }
  }

  function fetchConversations() {
    if (state.listInFlight) {
      scheduleList();
      return;
    }

    state.listInFlight = true;

    request(route('conversations'))
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Could not load conversations');
        }

        return response.json();
      })
      .then(function (payload) {
        state.failureCount = 0;
        state.conversations = (payload.data && payload.data.conversations) || [];
        notifyForUnreadIncreases(state.conversations);
        renderConversations();
        maybeOpenInitialConversation();
      })
      .catch(function () {
        state.failureCount += 1;
        showAlert(message('loadConversationsFailed'));
      })
      .finally(function () {
        state.listInFlight = false;
        scheduleList();
      });
  }

  function loadConversation(conversationId, options) {
    clearAlert();
    clearReply();
    state.activeConversationId = conversationId;
    state.activeConversation = state.conversations.find(function (conversation) {
      return conversation.id === conversationId;
    }) || null;
    state.latestMessageId = null;
    state.oldestMessageId = null;
    openPanel();
    renderConversations();

    if (!options || options.replaceUrl !== false) {
      updateLocation(conversationId);
    }

    request(route('conversation', conversationId))
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Could not load messages');
        }

        return response.json();
      })
      .then(function (payload) {
        const data = payload.data || {};
        state.activeConversation = data.conversation || state.activeConversation;
        renderHeader(data.conversation);
        renderMessages(data.messages || []);
        updateReceipts(data.conversation?.other_last_read_at);
        markRead();
        fetchConversations();
      })
      .catch(function () {
        showAlert(message('loadMessagesFailed'));
      })
      .finally(function () {
        scheduleMessages();
      });
  }

  function fetchNewMessages() {
    if (!state.activeConversationId || state.messageInFlight) {
      scheduleMessages();
      return;
    }

    state.messageInFlight = true;

    const url = new URL(route('messages', state.activeConversationId), window.location.origin);

    if (state.latestMessageId) {
      url.searchParams.set('after', state.latestMessageId);
    }

    request(url.toString())
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Could not load messages');
        }

        return response.json();
      })
      .then(function (payload) {
        state.failureCount = 0;
        const messages = (payload.data && payload.data.messages) || [];
        appendMessages(messages);

        if (messages.length > 0) {
          markRead();
          fetchConversations();
        }
      })
      .catch(function () {
        state.failureCount += 1;
      })
      .finally(function () {
        state.messageInFlight = false;
        scheduleMessages();
      });
  }

  function closeActiveConversation() {
    window.clearTimeout(state.messageTimer);
    state.activeConversationId = null;
    state.activeConversation = null;
    state.latestMessageId = null;
    state.oldestMessageId = null;
    state.replyToMessage = null;
    panel?.classList.add('d-none');
    panelHeader?.classList.add('d-none');
    composer?.classList.add('d-none');
    emptyState?.classList.remove('d-none');
    clearReply();
    clearAttachments();
    clearAlert();
    if (messageList) {
      messageList.innerHTML = '';
    }
    renderConversations();

    if (mobileLayout.matches) {
      root.classList.add('contacts-list-show');
    }

    if (window.history) {
      const url = new URL(window.location.href);
      url.searchParams.delete('conversation');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
  }

  function chatAction(action, conversationId) {
    const targetId = conversationId || state.activeConversationId;

    if (!targetId || action !== 'mute') {
      return;
    }

    submitChatAction(action, targetId);
  }

  function submitChatAction(action, conversationId) {
    request(route(action, conversationId), { method: 'POST' })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) {
            throw payload;
          }

          return payload;
        });
      })
      .then(function (payload) {
        if (payload.data && payload.data.conversation) {
          state.activeConversation = payload.data.conversation;
          renderHeader(payload.data.conversation);
        }
        showToast('success', payload.message || message(payload.data?.muted ? 'chatMuted' : 'chatUnmuted'));
        fetchConversations();
      })
      .catch(function (payload) {
        showToast('error', validationMessage(payload));
      });
  }

  function openForwardModal(messageId) {
    if (!forwardForm || !messageId) {
      return;
    }

    const modalElement = forwardForm.closest('.modal');
    const modal = window.bootstrap && modalElement ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;
    const hiddenInput = forwardForm.querySelector('[data-chat-forward-message-id]');

    clearModalValidation(forwardForm);
    forwardForm.reset();
    if (hiddenInput) {
      hiddenInput.value = messageId;
    }
    if ($ && $.fn.select2) {
      const $select = $(forwardForm).find('.js-select2-ajax');
      const excluded = [state.activeConversation?.user?.doc_num].filter(Boolean).join(',');
      if (excluded) {
        $select.attr('data-exclude-doc-nums', excluded);
      }
      $select.val(null).trigger('change');
    }
    if (modal) {
      modal.show();
    }
  }

  function forwardMessage(event) {
    event.preventDefault();

    const form = event.currentTarget;
    const formData = new FormData(form);
    const messageId = String(formData.get('message_id') || '');

    if (!messageId) {
      return;
    }

    clearModalValidation(form);

    request(route('forward', messageId), {
      method: 'POST',
      body: formData
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) {
            throw payload;
          }

          return payload;
        });
      })
      .then(function (payload) {
        const modalElement = form.closest('.modal');
        const modal = window.bootstrap && modalElement ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;

        if (modal) {
          modal.hide();
        }

        form.reset();
        if ($ && $.fn.select2) {
          $(form).find('.js-select2-ajax').val(null).trigger('change');
        }
        showToast('success', payload.message || message('messageForwarded'));
        fetchConversations();
      })
      .catch(function (payload) {
        renderModalValidation(form, payload);
      });
  }

  function loadOlderMessages() {
    if (!state.activeConversationId || !state.oldestMessageId || state.messageInFlight) {
      return;
    }

    state.messageInFlight = true;
    loadOlderButton?.setAttribute('disabled', 'disabled');

    const url = new URL(route('messages', state.activeConversationId), window.location.origin);
    url.searchParams.set('before', state.oldestMessageId);

    request(url.toString())
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Could not load messages');
        }

        return response.json();
      })
      .then(function (payload) {
        const messages = (payload.data && payload.data.messages) || [];

        if (!messages.length) {
          loadOlderButton?.classList.add('d-none');
          return;
        }

        const previousHeight = messagesPane ? messagesPane.scrollHeight : 0;
        messageList.insertAdjacentHTML('afterbegin', messagesHtml(messages));
        state.oldestMessageId = messages[0].id;
        loadOlderButton?.classList.toggle('d-none', messages.length < 50);

        if (messagesPane) {
          messagesPane.scrollTop = messagesPane.scrollHeight - previousHeight;
        }
      })
      .catch(function () {
        showAlert(message('loadMessagesFailed'));
      })
      .finally(function () {
        state.messageInFlight = false;
        loadOlderButton?.removeAttribute('disabled');
      });
  }

  function markRead() {
    if (!state.activeConversationId) {
      return;
    }

    request(route('read', state.activeConversationId), { method: 'POST' })
      .then(function () {
        window.AppNotificationsClient?.refresh?.({ suppressSound: true });
      });
  }

  function syncPreviewStack() {
    const hasReply = !!state.replyToMessage;
    const hasAttachments = state.selectedFiles.length > 0;

    previewStack?.classList.toggle('d-none', !hasReply && !hasAttachments);
  }

  function renderReplyPreview() {
    if (!replyPreview) {
      return;
    }

    if (!state.replyToMessage) {
      replyPreview.classList.add('d-none');
      replyPreview.innerHTML = '';
      if (replyInput) {
        replyInput.value = '';
      }
      syncPreviewStack();
      return;
    }

    replyPreview.classList.remove('d-none');
    if (replyInput) {
      replyInput.value = state.replyToMessage.id || '';
    }

    replyPreview.innerHTML = `<div class="d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0">
        <div class="chat-reply-preview-title fs-11 fw-semibold">${escapeHtml(message('replyingTo'))} ${escapeHtml(state.replyToMessage.sender_name || '')}</div>
        <div class="chat-reply-preview-snippet fs-11 text-truncate">${escapeHtml(state.replyToMessage.snippet || message('attachment'))}</div>
      </div>
      <button class="btn btn-link btn-sm p-0 chat-reply-preview-close" type="button" data-chat-cancel-reply title="${escapeHtml(message('cancelReply'))}">
        <span class="fas fa-times"></span>
      </button>
    </div>`;
    syncPreviewStack();
  }

  function setReplyToMessage(messageId) {
    const row = Array.from(messageList?.querySelectorAll('[data-chat-message]') || []).find(function (item) {
      return item.getAttribute('data-chat-message') === messageId;
    });

    if (!row) {
      return;
    }

    state.replyToMessage = {
      id: messageId,
      sender_name: row.getAttribute('data-sender-name') || '',
      snippet: row.getAttribute('data-message-snippet') || message('attachment')
    };

    renderReplyPreview();
    bodyInput?.focus();
  }

  function clearReply() {
    state.replyToMessage = null;
    renderReplyPreview();
  }

  function fileSizeLabel(bytes) {
    const size = Number(bytes || 0);

    if (size >= 1048576) {
      return (size / 1048576).toFixed(size >= 10485760 ? 0 : 1) + ' MB';
    }

    if (size >= 1024) {
      return Math.ceil(size / 1024) + ' KB';
    }

    return size + ' B';
  }

  function fileExtension(file) {
    const name = String(file?.name || '');
    const parts = name.split('.');

    return parts.length > 1 ? String(parts.pop() || '').toLowerCase() : '';
  }

  function fileValidationError(file) {
    if (!file) {
      return message('fileTypeNotAllowed');
    }

    if (file.size > attachmentRules.maxBytes) {
      return message('fileTooLarge');
    }

    if (!attachmentRules.allowedExtensions.has(fileExtension(file))) {
      return message('fileTypeNotAllowed');
    }

    return '';
  }

  function validSelectedFiles(files) {
    const validFiles = [];
    let firstError = '';

    Array.from(files || []).slice(0, attachmentRules.maxFiles).forEach(function (file) {
      const error = fileValidationError(file);

      if (error) {
        firstError = firstError || error;
        return;
      }

      validFiles.push(file);
    });

    if ((files || []).length > attachmentRules.maxFiles) {
      firstError = firstError || message('validationFailed');
    }

    if (firstError) {
      showToast('error', firstError);
    }

    return validFiles;
  }

  function renderAttachmentPreview() {
    if (!attachmentPreview) {
      return;
    }

    if (!state.selectedFiles.length) {
      attachmentPreview.classList.add('d-none');
      attachmentPreview.innerHTML = '';
      syncPreviewStack();
      return;
    }

    attachmentPreview.classList.remove('d-none');
    attachmentPreview.innerHTML = `<div class="chat-attachment-preview-card rounded-2 shadow-sm p-2">
      <div class="fs-11 fw-semibold mb-2">${escapeHtml(message('fileSelected'))}</div>
      <div class="d-flex flex-wrap gap-2">${state.selectedFiles.map(function (file, index) {
      const icon = file.type && file.type.indexOf('image/') === 0 ? 'fa-image' : 'fa-paperclip';

      return `<span class="chat-pending-attachment rounded-2 d-inline-flex align-items-center gap-2 px-2 py-1">
        <span class="fas ${icon} text-primary"></span>
        <span class="min-w-0">
          <span class="chat-pending-attachment-name d-block text-truncate fs-11 fw-semibold">${escapeHtml(file.name)}</span>
          <span class="d-block fs-11 text-500">${escapeHtml(fileSizeLabel(file.size))}</span>
        </span>
        <button class="btn btn-link btn-sm p-0 text-danger" type="button" data-chat-remove-attachment="${index}" title="${escapeHtml(message('removeAttachment'))}"><span class="fas fa-times"></span></button>
      </span>`;
    }).join('')}</div></div>`;
    syncPreviewStack();
  }

  function syncAttachmentInput() {
    if (!attachmentInput || typeof window.DataTransfer === 'undefined') {
      return;
    }

    const transfer = new window.DataTransfer();
    state.selectedFiles.forEach(function (file) {
      transfer.items.add(file);
    });
    attachmentInput.files = transfer.files;
  }

  function clearAttachments() {
    state.selectedFiles = [];
    if (attachmentInput) {
      attachmentInput.value = '';
    }
    renderAttachmentPreview();
  }

  function sendMessage(event) {
    event.preventDefault();

    if (!state.activeConversationId || !bodyInput) {
      return;
    }

    const body = String(bodyInput.value || '').trim();

    if (!body && state.selectedFiles.length === 0) {
      return;
    }

    const formData = new FormData();
    const messageSignature = JSON.stringify([
      state.activeConversationId,
      body,
      state.replyToMessage?.id || null,
      state.selectedFiles.map(function (file) { return [file.name, file.size, file.lastModified]; })
    ]);

    if (!state.pendingClientMessageId || state.pendingClientMessageSignature !== messageSignature) {
      state.pendingClientMessageId = window.crypto && typeof window.crypto.randomUUID === 'function'
        ? window.crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (character) {
          const random = Math.floor(Math.random() * 16);
          const value = character === 'x' ? random : ((random & 0x3) | 0x8);
          return value.toString(16);
        });
      state.pendingClientMessageSignature = messageSignature;
    }

    formData.append('body', body);
    formData.append('client_message_id', state.pendingClientMessageId);
    if (state.replyToMessage?.id) {
      formData.append('reply_to_message_id', state.replyToMessage.id);
    }
    state.selectedFiles.forEach(function (file) {
      formData.append('attachments[]', file);
    });

    if (sendButton) {
      sendButton.disabled = true;
    }

    request(route('storeMessage', state.activeConversationId), {
      method: 'POST',
      body: formData
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) {
            throw payload;
          }

          return payload;
        });
      })
      .then(function (payload) {
        state.pendingClientMessageId = null;
        state.pendingClientMessageSignature = null;
        bodyInput.value = '';
        autoResizeComposer();
        clearReply();
        clearAttachments();
        appendMessages([payload.data.message]);
        updateReceipts(payload.data.conversation?.other_last_read_at);
        fetchConversations();
      })
      .catch(function (payload) {
        showToast('error', validationMessage(payload) || message('attachmentSendFailed') || message('sendFailed'));
      })
      .finally(function () {
        if (sendButton) {
          sendButton.disabled = false;
        }
      });
  }

  function autoResizeComposer() {
    if (!bodyInput) {
      return;
    }

    bodyInput.style.height = 'auto';
    bodyInput.style.height = Math.min(bodyInput.scrollHeight, 120) + 'px';
  }

  function insertEmoji(emoji) {
    if (!bodyInput || !emoji) {
      return;
    }

    const start = bodyInput.selectionStart || bodyInput.value.length;
    const end = bodyInput.selectionEnd || bodyInput.value.length;
    bodyInput.value = bodyInput.value.slice(0, start) + emoji + bodyInput.value.slice(end);
    bodyInput.focus();
    bodyInput.selectionStart = start + emoji.length;
    bodyInput.selectionEnd = start + emoji.length;
  }

  function clearModalValidation(form) {
    form.querySelectorAll('.is-invalid').forEach(function (field) {
      field.classList.remove('is-invalid');
    });
    form.querySelectorAll('[data-error-for]').forEach(function (element) {
      element.textContent = '';
    });
    const alert = form.querySelector('[data-form-alert]');

    if (alert) {
      alert.innerHTML = '';
    }
  }

  function renderModalValidation(form, payload) {
    const alert = form.querySelector('[data-form-alert]');
    const text = validationMessage(payload);

    if (alert && text) {
      alert.innerHTML = `<div class="alert alert-danger">${escapeHtml(text)}</div>`;
    }

    Object.keys((payload && payload.errors) || {}).forEach(function (field) {
      const input = form.querySelector(`[name="${field}"]`);
      const error = form.querySelector(`[data-error-for="${field}"]`);

      if (input) {
        input.classList.add('is-invalid');
      }

      if (error) {
        error.textContent = payload.errors[field][0] || '';
      }
    });
  }

  function storeConversation(event) {
    event.preventDefault();

    const form = event.currentTarget;
    const formData = new FormData(form);

    clearModalValidation(form);

    request(route('storeConversation'), {
      method: 'POST',
      body: formData
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) {
            throw payload;
          }

          return payload;
        });
      })
      .then(function (payload) {
        const conversation = payload.data.conversation;
        const modalElement = form.closest('.modal');
        const modal = window.bootstrap && modalElement ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;

        if (modal) {
          modal.hide();
        }

        form.reset();
        if ($ && $.fn.select2) {
          $(form).find('.js-select2-ajax').val(null).trigger('change');
        }
        fetchConversations();
        loadConversation(conversation.id);
      })
      .catch(function (payload) {
        renderModalValidation(form, payload);
      });
  }

  function initSelect2() {
    if (!$ || !$.fn.select2) {
      return;
    }

    $('.js-select2-ajax').each(function () {
      const $select = $(this);

      if ($select.data('select2')) {
        return;
      }

      $select.select2({
        theme: 'bootstrap-5',
        width: '100%',
        dir: document.documentElement.getAttribute('dir') || 'ltr',
        allowClear: String($select.data('allow-clear')) === 'true',
        placeholder: $select.data('placeholder') || '',
        dropdownParent: $select.closest('.modal').length ? $select.closest('.modal') : $(document.body),
        ajax: {
          url: $select.data('url'),
          dataType: 'json',
          delay: 250,
          data: function (params) {
            return {
              q: params.term || '',
              page: params.page || 1,
              exclude_doc_nums: $select.attr('data-exclude-doc-nums') || ''
            };
          }
        }
      });
    });
  }

  function initEmojiPicker() {
    const button = root.querySelector('[data-chat-emoji-picker]');
    const Picker = window.EmojiMart && window.EmojiMart.Picker;

    if (!button || button.dataset.chatEmojiReady === '1') {
      return;
    }

    if (!Picker) {
      initFallbackEmojiPicker(button);
      return;
    }

    let picker = null;

    try {
      picker = new Picker({
        previewPosition: 'none',
        skinTonePosition: 'none',
        perLine: 8,
        emojiSize: 20,
        emojiButtonSize: 28,
        onEmojiSelect: function (emoji) {
          insertEmoji(emoji.native);
        },
        onClickOutside: function (event) {
          if (!picker.contains(event.target) && !button.contains(event.target)) {
            picker.classList.add('d-none');
            button.setAttribute('aria-expanded', 'false');
          }
        }
      });
    } catch (error) {
      initFallbackEmojiPicker(button);
      return;
    }

    picker.classList.add('d-none', 'chat-emoji-mart-panel');
    button.parentElement.appendChild(picker);
    button.dataset.chatEmojiReady = '1';
    button.addEventListener('click', function (event) {
      event.preventDefault();
      picker.classList.toggle('d-none');
      button.setAttribute('aria-expanded', picker.classList.contains('d-none') ? 'false' : 'true');
    });
  }

  function initFallbackEmojiPicker(button) {
    const panel = document.createElement('div');

    panel.className = 'chat-emoji-fallback-panel rounded-2 shadow-sm p-2 d-none';
    panel.innerHTML = `<div class="d-flex flex-wrap gap-1">${fallbackEmojis.map(function (emoji) {
      return `<button class="btn btn-light btn-sm" type="button" data-chat-emoji="${escapeAttribute(emoji)}">${escapeHtml(emoji)}</button>`;
    }).join('')}</div>`;
    button.parentElement.appendChild(panel);
    button.dataset.chatEmojiReady = '1';
    button.addEventListener('click', function (event) {
      event.preventDefault();
      panel.classList.toggle('d-none');
      button.setAttribute('aria-expanded', panel.classList.contains('d-none') ? 'false' : 'true');
    });
    document.addEventListener('click', function (event) {
      if (!panel.contains(event.target) && !button.contains(event.target)) {
        panel.classList.add('d-none');
        button.setAttribute('aria-expanded', 'false');
      }
    });
  }

  conversationList?.addEventListener('click', function (event) {
    if (event.target.closest('.dropdown, .dropdown-menu, [data-bs-toggle="dropdown"]')) {
      return;
    }

    const item = event.target.closest('[data-chat-conversation]');

    if (!item) {
      return;
    }

    loadConversation(item.getAttribute('data-chat-conversation'));
  });

  conversationList?.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') {
      return;
    }

    const item = event.target.closest('[data-chat-conversation]');

    if (!item) {
      return;
    }

    event.preventDefault();
    loadConversation(item.getAttribute('data-chat-conversation'));
  });

  searchInput?.addEventListener('input', renderConversations);

  root.querySelector('[data-chat-search-form]')?.addEventListener('submit', function (event) {
    event.preventDefault();
    renderConversations();
  });

  composer?.addEventListener('submit', sendMessage);
  loadOlderButton?.addEventListener('click', loadOlderMessages);

  bodyInput?.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey && !mobileLayout.matches) {
      event.preventDefault();
      composer?.requestSubmit();
    }
  });

  bodyInput?.addEventListener('input', autoResizeComposer);

  attachmentInput?.addEventListener('change', function () {
    state.selectedFiles = validSelectedFiles(attachmentInput.files || []);
    syncAttachmentInput();
    renderAttachmentPreview();
  });

  attachmentPreview?.addEventListener('click', function (event) {
    const button = event.target.closest('[data-chat-remove-attachment]');

    if (!button) {
      return;
    }

    state.selectedFiles.splice(Number(button.getAttribute('data-chat-remove-attachment')), 1);
    syncAttachmentInput();
    renderAttachmentPreview();
  });

  root.addEventListener('click', function (event) {
    const actionButton = event.target.closest('[data-chat-action]');

    if (actionButton) {
      event.preventDefault();
      chatAction(actionButton.getAttribute('data-chat-action'), actionButton.getAttribute('data-chat-conversation-id'));
      return;
    }

    const closeButton = event.target.closest('[data-chat-close]');

    if (closeButton) {
      event.preventDefault();
      closeActiveConversation();
      return;
    }

    const forwardButton = event.target.closest('[data-chat-forward-message]');

    if (forwardButton) {
      event.preventDefault();
      openForwardModal(forwardButton.getAttribute('data-chat-forward-message'));
      return;
    }

    const replyButton = event.target.closest('[data-chat-reply-message]');

    if (replyButton) {
      event.preventDefault();
      setReplyToMessage(replyButton.getAttribute('data-chat-reply-message'));
      return;
    }

    const cancelReplyButton = event.target.closest('[data-chat-cancel-reply]');

    if (cancelReplyButton) {
      event.preventDefault();
      clearReply();
      return;
    }

    const emojiButton = event.target.closest('[data-chat-emoji]');

    if (emojiButton) {
      insertEmoji(emojiButton.getAttribute('data-chat-emoji'));
      return;
    }

    if (event.target.closest('.chat-option')) {
      event.preventDefault();
      return;
    }
  });

  function clearChangedModalField(event) {
    const target = event.target;

    if (!(target instanceof HTMLElement) || !target.name) {
      return;
    }

    target.classList.remove('is-invalid');
    const error = event.currentTarget.querySelector(`[data-error-for="${target.name}"]`);

    if (error) {
      error.textContent = '';
    }
  }

  newConversationForm?.addEventListener('submit', storeConversation);
  newConversationForm?.addEventListener('change', clearChangedModalField);
  forwardForm?.addEventListener('submit', forwardMessage);
  forwardForm?.addEventListener('change', clearChangedModalField);

  document.addEventListener('visibilitychange', function () {
    window.clearTimeout(state.visibleDebounceTimer);

    if (!document.hidden) {
      state.visibleDebounceTimer = window.setTimeout(function () {
        fetchConversations();
        fetchNewMessages();
      }, 1200);
      return;
    }

    scheduleList();
    scheduleMessages();
  });

  root.querySelector('[data-chat-sidebar-show]')?.addEventListener('click', function (event) {
    event.preventDefault();
    root.classList.add('contacts-list-show');
  });

  function syncMobileLayout() {
    if (!mobileLayout.matches) {
      root.classList.remove('contacts-list-show');
      return;
    }

    if (!state.activeConversationId) {
      root.classList.add('contacts-list-show');
    }
  }

  if (typeof mobileLayout.addEventListener === 'function') {
    mobileLayout.addEventListener('change', syncMobileLayout);
  } else if (typeof mobileLayout.addListener === 'function') {
    mobileLayout.addListener(syncMobileLayout);
  }

  function localizeComposerPlaceholder() {
    if (bodyInput && message('typeMessage')) {
      bodyInput.setAttribute('placeholder', message('typeMessage'));
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', localizeComposerPlaceholder, { once: true });
  } else {
    localizeComposerPlaceholder();
  }

  initSelect2();
  initEmojiPicker();
  window.AppChatNotificationContext = {
    isViewing: function (notification) {
      return Boolean(
        state.activeConversationId
        && notification?.conversation_uuid
        && String(notification.conversation_uuid) === String(state.activeConversationId)
        && !document.hidden
      );
    }
  };
  syncMobileLayout();
  fetchConversations();
})(window, document, window.jQuery);
