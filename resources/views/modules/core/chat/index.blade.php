@extends('layouts.app')

@section('title', __('chat.title'))

@push('styles')
    <style>
        .erp-chat-page .card-chat {
            height: calc(100vh - var(--falcon-top-nav-height) - 8.75rem);
            max-height: calc(100vh - var(--falcon-top-nav-height) - 8.75rem);
        }

        .erp-chat-page .chat-content-scroll-area {
            height: 100%;
        }

        .erp-chat-page .chat-editor-area textarea.emojiarea-editor {
            max-height: 7.5rem;
            resize: none;
            background: transparent;
            border: 0;
            width: 100%;
        }

        .erp-chat-page .chat-editor-area {
            position: relative;
        }

        .erp-chat-page .chat-attachment-preview {
            pointer-events: auto;
        }

        .erp-chat-page .chat-composer-preview-stack {
            pointer-events: auto;
            z-index: 4;
        }

        .erp-chat-page .chat-reply-preview-card,
        .erp-chat-page .chat-reply-block {
            border: 1px solid var(--falcon-border-color, #d8e2ef);
            border-inline-start: .25rem solid var(--falcon-primary, #2c7be5);
            background-color: var(--falcon-emphasis-bg, #fff);
            color: var(--falcon-body-color, #344050);
        }

        .erp-chat-page .chat-reply-preview-title,
        .erp-chat-page .chat-reply-block-title {
            color: var(--falcon-emphasis-color, #0b1727);
        }

        .erp-chat-page .chat-reply-preview-snippet,
        .erp-chat-page .chat-reply-block-snippet,
        .erp-chat-page .chat-forwarded-meta {
            color: var(--falcon-secondary-color, #5e6e82);
        }

        .erp-chat-page .chat-reply-preview-close {
            color: var(--falcon-secondary-color, #5e6e82);
        }

        .erp-chat-page .chat-reply-preview-close:hover,
        .erp-chat-page .chat-reply-preview-close:focus {
            color: var(--falcon-danger, #e63757);
        }

        .erp-chat-page .chat-attachment-preview-card {
            border: 1px solid var(--falcon-border-color, #d8e2ef);
            background-color: var(--falcon-emphasis-bg, #fff);
            color: var(--falcon-body-color, #344050);
        }

        .erp-chat-page .chat-pending-attachment {
            max-width: 100%;
            border: 1px solid var(--falcon-border-color, #d8e2ef);
            background-color: var(--falcon-gray-100, #f9fafd);
            color: var(--falcon-body-color, #344050);
        }

        .erp-chat-page .chat-pending-attachment-name {
            max-width: 12rem;
        }

        .erp-chat-page .chat-message.bg-primary .chat-reply-block,
        .erp-chat-page .chat-message.bg-primary .chat-forwarded-meta {
            border-color: rgba(255, 255, 255, .32);
            border-inline-start-color: rgba(255, 255, 255, .85);
            background-color: rgba(255, 255, 255, .16);
            color: #fff;
        }

        .erp-chat-page .chat-message.bg-primary .chat-reply-block-title,
        .erp-chat-page .chat-message.bg-primary .chat-reply-block-snippet,
        .erp-chat-page .chat-message.bg-primary .chat-forwarded-meta {
            color: rgba(255, 255, 255, .92);
        }

        .erp-chat-page .chat-emoji-mart-panel {
            position: absolute;
            inset-inline-end: 0;
            bottom: 2.25rem;
            z-index: 1055;
            max-width: calc(100vw - 2rem);
        }

        .erp-chat-page .chat-emoji-picker {
            position: relative;
        }

        .erp-chat-page .chat-emoji-fallback-panel {
            position: absolute;
            inset-inline-end: 0;
            bottom: 2.25rem;
            z-index: 1055;
            width: 20rem;
            max-width: calc(100vw - 2rem);
            max-height: 18rem;
            overflow-y: auto;
            border: 1px solid var(--falcon-border-color, #d8e2ef);
            background-color: var(--falcon-emphasis-bg, #fff);
        }

        .erp-chat-page .chat-emoji-fallback-panel button {
            width: 2rem;
            height: 2rem;
            line-height: 1;
        }

        .erp-chat-page .chat-empty-state {
            min-height: 100%;
        }

        .erp-chat-page .chat-contact {
            cursor: pointer;
        }

        @media (max-width: 575.98px) {
            .erp-chat-page .card-chat {
                height: calc(100vh - var(--falcon-top-nav-height) - 4.5rem);
                max-height: calc(100vh - var(--falcon-top-nav-height) - 4.5rem);
            }
        }
    </style>
@endpush

@section('content')
    <div class="erp-chat-page">
        <div class="card card-chat overflow-hidden" data-chat-root>
            <div class="card-body d-flex p-0 h-100">
                <div class="chat-sidebar" data-chat-sidebar>
                    <div class="contacts-list scrollbar-overlay" data-chat-conversations>
                        <div class="h-100 d-flex align-items-center justify-content-center p-4 text-center text-600">
                            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>{{ __('chat.loading') }}
                        </div>
                    </div>

                    <form class="contacts-search-wrapper" data-chat-search-form>
                        <div class="form-group mb-0 position-relative d-md-none d-lg-block w-100 h-100">
                            <input class="form-control form-control-sm chat-contacts-search border-0 h-100" type="search" data-chat-search placeholder="{{ __('chat.search_conversations') }}" aria-label="{{ __('chat.search_conversations') }}">
                            <span class="fas fa-search contacts-search-icon"></span>
                        </div>
                        <button class="btn btn-sm btn-transparent d-none d-md-inline-block d-lg-none" type="submit" aria-label="{{ __('chat.search_conversations') }}">
                            <span class="fas fa-search fs-10"></span>
                        </button>
                    </form>
                </div>

                <div class="tab-content card-chat-content">
                    <div class="tab-pane card-chat-pane active" role="tabpanel">
                        <div class="chat-content-header d-none" data-chat-panel-header>
                            <div class="row flex-between-center">
                                <div class="col-6 col-sm-8 d-flex align-items-center">
                                    <a class="pe-3 text-700 d-md-none contacts-list-show" href="#!" data-chat-sidebar-show>
                                        <div class="fas fa-chevron-left"></div>
                                    </a>
                                    <div class="min-w-0">
                                        <h5 class="mb-0 text-truncate fs-9" data-chat-active-title></h5>
                                        <div class="fs-11 text-400" data-chat-active-status>{{ __('chat.online_hint') }}</div>
                                    </div>
                                </div>
                                <div class="col-auto d-flex align-items-center gap-2">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-falcon-default dropdown-toggle dropdown-caret-none" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            <span class="fas fa-ellipsis-h"></span>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end border py-2">
                                            <button class="dropdown-item" type="button" data-chat-action="mute">{{ __('chat.mute_chat') }}</button>
                                        </div>
                                    </div>
                                    <button class="btn btn-sm btn-falcon-default" type="button" data-chat-close title="{{ __('common.actions.close') }}" data-bs-title="{{ __('common.actions.close') }}"><span class="fas fa-times"></span></button>
                                </div>
                            </div>
                        </div>

                        <div class="chat-content-body" data-chat-content style="display: inherit;">
                            <div class="chat-content-scroll-area scrollbar" data-chat-messages>
                                <div class="chat-empty-state h-100 d-flex flex-column align-items-center justify-content-center text-center p-5" data-chat-empty>
                                    <div class="avatar avatar-4xl mb-3">
                                        <div class="avatar-name rounded-circle bg-primary-subtle text-primary">
                                            <span><span class="fas fa-comments"></span></span>
                                        </div>
                                    </div>
                                    <h5 class="mb-1">{{ __('chat.select_conversation') }}</h5>
                                    <p class="text-600 mb-3">{{ __('chat.text_only') }}</p>
                                    @can('chat.create')
                                        <button class="btn btn-falcon-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#chat-new-conversation-modal">
                                            <span class="fas fa-plus me-1"></span>{{ __('chat.new_conversation') }}
                                        </button>
                                    @endcan
                                </div>

                                <div class="d-none" data-chat-panel>
                                    <div class="px-3 pt-3" data-chat-alert></div>
                                    <div class="text-center py-3">
                                        <button class="btn btn-falcon-default btn-sm d-none" type="button" data-chat-load-older>
                                            {{ __('chat.load_older_messages') }}
                                        </button>
                                    </div>
                                    <div data-chat-message-list></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @can('chat.send')
                        <form class="chat-editor-area d-none" data-chat-composer novalidate enctype="multipart/form-data">
                            <div class="chat-composer-preview-stack position-absolute start-0 end-0 bottom-100 px-3 pb-2 d-none" data-chat-preview-stack>
                                <div class="chat-reply-preview chat-reply-preview-card rounded-2 shadow-sm p-2 mb-2 d-none" data-chat-reply-preview></div>
                                <div class="chat-attachment-preview d-none" data-chat-attachment-preview></div>
                            </div>
                            <input type="hidden" name="reply_to_message_id" data-chat-reply-input>
                            <textarea class="emojiarea-editor outline-none scrollbar" rows="1" name="body" data-chat-input placeholder="{{ __('chat.type_message') }}" aria-label="{{ __('chat.type_message') }}"></textarea>
                            <input class="d-none" type="file" id="chat-file-upload" data-chat-attachment-input name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx">
                            <label class="chat-file-upload cursor-pointer" for="chat-file-upload" title="{{ __('chat.attach_file') }}" data-bs-title="{{ __('chat.attach_file') }}">
                                <span class="fas fa-paperclip"></span>
                            </label>
                            <div class="dropdown chat-emoji-picker">
                                <button class="btn btn-link emoji-icon" type="button" data-chat-emoji-picker aria-expanded="false" title="{{ __('chat.emojis') }}" data-bs-title="{{ __('chat.emojis') }}">
                                    <span class="far fa-laugh-beam"></span>
                                </button>
                            </div>
                            <button class="btn btn-sm btn-send shadow-none" type="submit" data-chat-send-button>{{ __('chat.send') }}</button>
                        </form>
                    @endcan
                </div>
            </div>
        </div>
    </div>

    @can('chat.create')
        <div class="modal fade" id="chat-new-conversation-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" data-chat-new-conversation-form novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('chat.new_conversation') }}</h5>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div data-form-alert></div>
                        <label class="form-label" for="chat_user_doc_num">{{ __('chat.select_user') }}</label>
                        <select class="form-select js-select2-ajax" id="chat_user_doc_num" name="user_doc_num" data-url="{{ route('admin.select2.users', ['exclude_self' => 1]) }}" data-placeholder="{{ __('chat.search_user') }}" data-allow-clear="true"></select>
                        <div class="invalid-feedback d-block" data-error-for="user_doc_num"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button class="btn btn-primary" type="submit">{{ __('chat.new_conversation') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="chat-forward-message-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" data-chat-forward-form novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('chat.forward_message') }}</h5>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div data-form-alert></div>
                        <input type="hidden" name="message_id" data-chat-forward-message-id>
                        <label class="form-label" for="chat_forward_user_doc_num">{{ __('chat.select_forward_user') }}</label>
                        <select class="form-select js-select2-ajax" id="chat_forward_user_doc_num" name="user_doc_num" data-url="{{ route('admin.select2.users', ['exclude_self' => 1]) }}" data-placeholder="{{ __('chat.search_user') }}" data-allow-clear="true"></select>
                        <div class="invalid-feedback d-block" data-error-for="user_doc_num"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button class="btn btn-primary" type="submit">{{ __('chat.forward_message') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection

@push('scripts')
    @php
        $chatConfig = [
            'initialConversation' => filled(request()->query('conversation')) && is_string(request()->query('conversation')) ? request()->query('conversation') : null,
            'routes' => [
                'conversations' => route('admin.chat.conversations'),
                'conversation' => route('admin.chat.conversations.show', '__CONVERSATION__'),
                'storeConversation' => route('admin.chat.conversations.store'),
                'messages' => route('admin.chat.messages.poll', '__CONVERSATION__'),
                'storeMessage' => route('admin.chat.messages.store', '__CONVERSATION__'),
                'read' => route('admin.chat.messages.read', '__CONVERSATION__'),
                'mute' => route('admin.chat.conversations.mute', '__CONVERSATION__'),
                'forward' => route('admin.chat.messages.forward', '__MESSAGE__'),
            ],
            'intervals' => [
                'conversationMs' => 8000,
                'listMs' => 20000,
                'hiddenMs' => 60000,
                'jitterMinMs' => 500,
                'jitterMaxMs' => 2500,
            ],
            'messages' => [
                'noConversations' => __('chat.no_conversations'),
                'noMessages' => __('chat.no_messages'),
                'loadConversationsFailed' => __('chat.could_not_load_conversations'),
                'loadMessagesFailed' => __('chat.could_not_load_messages'),
                'sendFailed' => __('chat.could_not_send_message'),
                'unreadMessages' => __('chat.unread_messages'),
                'validationFailed' => __('common.messages.validation_failed'),
                'unexpectedError' => __('common.messages.unexpected_error'),
                'attachments' => __('chat.attachments'),
                'removeAttachment' => __('chat.remove_attachment'),
                'sent' => __('chat.sent_status'),
                'read' => __('chat.read'),
                'loading' => __('chat.loading'),
                'muteChat' => __('chat.mute_chat'),
                'unmuteChat' => __('chat.unmute_chat'),
                'reply' => __('chat.reply'),
                'replyingTo' => __('chat.replying_to'),
                'cancelReply' => __('chat.cancel_reply'),
                'attachment' => __('chat.attachment'),
                'fileTooLarge' => __('chat.file_size_too_large'),
                'fileTypeNotAllowed' => __('chat.file_type_not_allowed'),
                'attachmentSendFailed' => __('chat.attachment_send_failed'),
                'fileSelected' => __('chat.file_selected'),
                'forward' => __('chat.forward'),
                'forwardMessage' => __('chat.forward_message'),
                'chatMuted' => __('chat.chat_muted'),
                'chatUnmuted' => __('chat.chat_unmuted'),
                'messageForwarded' => __('chat.message_forwarded'),
                'cancel' => __('common.actions.cancel'),
                'online' => __('chat.online'),
                'offline' => __('chat.offline'),
            ],
        ];
    @endphp
    <script>
        window.AppChat = @json($chatConfig);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('vendors/emoji-mart/browser.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/chat.js') }}"></script>
@endpush
