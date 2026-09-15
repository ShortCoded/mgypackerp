@extends('layouts.app')

@section('title', __('chat.report.single_title', ['conversation' => $report->title($conversation)]))

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Core/chat-report.css') }}">
@endpush

@section('content')
    <div class="chat-report-page">
        <div class="d-flex flex-column flex-lg-row align-items-lg-start justify-content-between gap-3 mb-3">
            <div class="min-w-0">
                <a class="btn btn-link btn-sm px-0 mb-1" href="{{ route('admin.chat.reports.index') }}">
                    <span class="fas fa-arrow-{{ app()->isLocale('ar') ? 'right' : 'left' }} me-1"></span>{{ __('chat.report.actions.back') }}
                </a>
                <h5 class="mb-1 text-break">{{ $report->title($conversation) }}</h5>
                <div class="font-monospace text-500 fs-11 text-break">{{ $conversation->public_uuid }}</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @can('chat.reports.export')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.chat.reports.conversation.excel', $conversation) }}"><span class="fas fa-file-excel me-1"></span>{{ __('chat.report.actions.excel') }}</a>
                @endcan
                @can('chat.reports.pdf')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.chat.reports.conversation.pdf', $conversation) }}" target="_blank" rel="noopener"><span class="fas fa-file-pdf me-1"></span>{{ __('chat.report.actions.pdf') }}</a>
                @endcan
                @can('chat.reports.print')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.chat.reports.conversation.print', $conversation) }}" target="_blank" rel="noopener"><span class="fas fa-print me-1"></span>{{ __('chat.report.actions.print') }}</a>
                @endcan
            </div>
        </div>

        <div class="alert alert-warning border-0 fs-10 py-2"><span class="fas fa-user-shield me-2"></span>{{ __('chat.report.privacy_notice') }}</div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
                <div class="card h-100">
                    <div class="card-header py-2"><h6 class="mb-0">{{ __('chat.report.participants_title') }}</h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="bg-100">
                                    <tr>
                                        <th>{{ __('chat.report.columns.participants') }}</th>
                                        <th>{{ __('chat.report.columns.status') }}</th>
                                        <th>{{ __('chat.report.columns.last_read_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($conversation->allParticipants as $participant)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $participant->name }}</div>
                                                <div class="text-500 fs-11">{{ $participant->doc_num }} · {{ $participant->email }}</div>
                                            </td>
                                            <td>
                                                <span class="badge rounded-pill {{ $participant->pivot?->deleted_at ? 'bg-secondary-subtle text-secondary' : 'bg-primary-subtle text-primary' }}">
                                                    {{ $participant->pivot?->deleted_at ? __('chat.report.former_participant') : __('chat.report.current_participant') }}
                                                </span>
                                                @if ($participant->pivot?->muted_at)
                                                    <span class="badge rounded-pill bg-warning-subtle text-warning">{{ __('chat.report.muted') }}</span>
                                                @endif
                                            </td>
                                            <td class="text-nowrap fs-10">
                                                {{ $participant->pivot?->last_read_at ? \Illuminate\Support\Carbon::parse($participant->pivot->last_read_at)->format('Y-m-d H:i:s') : __('chat.report.never_read') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-4">
                <div class="card h-100">
                    <div class="card-body">
                        <dl class="row mb-0 fs-10 chat-report-meta">
                            <dt class="col-5">{{ __('chat.report.columns.type') }}</dt>
                            <dd class="col-7">{{ __('chat.report.types.'.$conversation->type) }}</dd>
                            <dt class="col-5">{{ __('chat.report.columns.created_by') }}</dt>
                            <dd class="col-7">{{ $conversation->creator?->name ?: '—' }}</dd>
                            <dt class="col-5">{{ __('chat.report.columns.created_at') }}</dt>
                            <dd class="col-7">{{ $conversation->created_at?->format('Y-m-d H:i:s') ?: '—' }}</dd>
                            <dt class="col-5">{{ __('chat.report.columns.last_message_at') }}</dt>
                            <dd class="col-7">{{ $conversation->last_message_at?->format('Y-m-d H:i:s') ?: '—' }}</dd>
                            <dt class="col-5">{{ __('chat.report.columns.message_count') }}</dt>
                            <dd class="col-7">{{ number_format($conversation->messages_count) }}</dd>
                            <dt class="col-5">{{ __('chat.report.columns.attachment_count') }}</dt>
                            <dd class="col-7">{{ number_format($conversation->attachments_count) }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="card chat-report-transcript-card">
            <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2">
                <h6 class="mb-0">{{ __('chat.report.messages_title') }}</h6>
                <span class="badge bg-primary-subtle text-primary">{{ $messages->total() }}</span>
            </div>
            <div class="card-body chat-report-transcript">
                @forelse ($messages as $message)
                    <article class="chat-report-message {{ $message->trashed() ? 'is-deleted' : '' }}">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <div>
                                <span class="fw-semibold">{{ $message->sender?->name ?: '—' }}</span>
                                <span class="text-500 fs-11">{{ $message->sender?->doc_num }}</span>
                            </div>
                            <time class="text-500 fs-11 text-nowrap">{{ $message->sent_at?->format('Y-m-d H:i:s') }}</time>
                        </div>

                        @if ($message->forwardedFromMessage || $message->forwardedFromUser)
                            <div class="chat-report-reference mb-2"><span class="fas fa-share me-1"></span>{{ __('chat.report.columns.forwarded_from') }}: {{ $message->forwardedFromMessage?->public_uuid ?: $message->forwardedFromUser?->name }}</div>
                        @endif
                        @if ($message->replyToMessage)
                            <div class="chat-report-reference mb-2"><span class="fas fa-reply me-1"></span>{{ __('chat.report.columns.reply_to') }}: {{ $message->replyToMessage->public_uuid }} · {{ \Illuminate\Support\Str::limit($message->replyToMessage->body, 120) }}</div>
                        @endif

                        <div class="chat-report-message-body text-break">
                            {!! nl2br(e($message->body ?: __('chat.report.no_message_body'))) !!}
                        </div>

                        @if ($message->attachments->isNotEmpty())
                            <div class="chat-report-attachments mt-2">
                                @foreach ($message->attachments as $attachment)
                                    <a class="chat-report-attachment" href="{{ route('admin.chat.reports.attachments.show', $attachment) }}" target="_blank" rel="noopener">
                                        <span class="fas fa-paperclip"></span>
                                        <span class="text-truncate">{{ $attachment->original_name }}</span>
                                        <small>{{ $report->formatBytes((int) $attachment->size_bytes) }}</small>
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2 text-500 fs-11">
                            <span class="font-monospace">{{ $message->public_uuid }}</span>
                            @if ($message->trashed())
                                <span class="badge bg-danger-subtle text-danger">{{ __('chat.report.deleted') }} · {{ $message->deleted_at?->format('Y-m-d H:i:s') }}</span>
                            @endif
                            @php($readBy = $report->readBy($message, $conversation))
                            <span><span class="fas fa-check-double me-1"></span>{{ $readBy ? implode('، ', $readBy) : __('chat.report.no_read_receipt') }}</span>
                        </div>
                    </article>
                @empty
                    <div class="py-5 text-center text-600">{{ __('chat.report.empty_messages') }}</div>
                @endforelse
            </div>
            @if ($messages->hasPages())
                <div class="card-footer bg-body-tertiary">{{ $messages->links() }}</div>
            @endif
        </div>
    </div>
@endsection
