@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp
<div class="chat-transcript-report" dir="{{ $direction ?? config('languages.available.'.app()->getLocale().'.dir', 'rtl') }}">
    @if (! empty($filters))
        <div class="report-filter-summary">
            @foreach (collect($filters)->filter(fn ($value) => filled($value)) as $key => $value)
                <span><strong>{{ __('chat.report.filters.'.($key === 'q' ? 'search' : $key)) }}:</strong> {{ $value }}</span>
            @endforeach
        </div>
    @endif

    @forelse ($conversations as $conversation)
        <section class="transcript-conversation">
            <h2>{{ $report->title($conversation) }}</h2>
            <table class="meta-table">
                <tr>
                    <th>{{ __('chat.report.columns.conversation_id') }}</th>
                    <td>{{ $conversation->public_uuid }}</td>
                    <th>{{ __('chat.report.columns.type') }}</th>
                    <td>{{ __('chat.report.types.'.$conversation->type) }}</td>
                </tr>
                <tr>
                    <th>{{ __('chat.report.columns.participants') }}</th>
                    <td colspan="3">
                        {{ $conversation->allParticipants->map(fn ($participant) => $participant->name.' ('.$participant->doc_num.')'.($participant->pivot?->deleted_at ? ' — '.__('chat.report.former_participant') : ''))->implode('، ') }}
                    </td>
                </tr>
                <tr>
                    <th>{{ __('chat.report.columns.created_at') }}</th>
                    <td>{{ $dates->formatDateTime($conversation->created_at, '') }}</td>
                    <th>{{ __('chat.report.columns.status') }}</th>
                    <td>{{ $conversation->trashed() ? __('chat.report.deleted') : __('chat.report.active') }}</td>
                </tr>
            </table>

            <table class="messages-table">
                <thead>
                    <tr>
                        <th class="sender-column">{{ __('chat.report.columns.sender') }}</th>
                        <th>{{ __('chat.report.columns.message') }}</th>
                        <th class="date-column">{{ __('chat.report.columns.sent_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($conversation->messages as $message)
                        <tr class="{{ $message->trashed() ? 'deleted-message' : '' }}">
                            <td>
                                <strong>{{ $message->sender?->name ?: '—' }}</strong><br>
                                <small>{{ $message->sender?->doc_num }}</small>
                            </td>
                            <td>
                                @if ($message->forwardedFromMessage || $message->forwardedFromUser)
                                    <div class="reference">{{ __('chat.report.columns.forwarded_from') }}: {{ $message->forwardedFromMessage?->public_uuid ?: $message->forwardedFromUser?->name }}</div>
                                @endif
                                @if ($message->replyToMessage)
                                    <div class="reference">{{ __('chat.report.columns.reply_to') }}: {{ $message->replyToMessage->public_uuid }}</div>
                                @endif
                                <div>{!! nl2br(e($message->body ?: __('chat.report.no_message_body'))) !!}</div>
                                @if ($message->attachments->isNotEmpty())
                                    <div class="attachments">
                                        <strong>{{ __('chat.report.columns.attachments') }}:</strong>
                                        @foreach ($message->attachments as $attachment)
                                            <div>
                                                <a href="{{ route('admin.chat.reports.attachments.show', $attachment) }}">{{ $attachment->original_name }}</a>
                                                ({{ $report->formatBytes((int) $attachment->size_bytes) }})
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @php($readBy = $report->readBy($message, $conversation))
                                <small>{{ __('chat.report.columns.read_by') }}: {{ $readBy ? implode('، ', $readBy) : __('chat.report.no_read_receipt') }}</small><br>
                                <small>{{ __('chat.report.columns.message_id') }}: {{ $message->public_uuid }}</small>
                                @if ($message->trashed())
                                    <div class="deleted-label">{{ __('chat.report.deleted') }} · {{ $dates->formatDateTime($message->deleted_at, '') }}</div>
                                @endif
                            </td>
                            <td>{{ $dates->formatDateTime($message->sent_at, '') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">{{ __('chat.report.empty_messages') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @empty
        <p>{{ __('chat.report.empty') }}</p>
    @endforelse

    <p class="attachment-note">{{ __('chat.report.attachment_link_note') }}</p>
</div>
