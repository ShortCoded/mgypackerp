@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', __('chat.report.title'))

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Core/chat-report.css') }}">
@endpush

@php
    $queryString = http_build_query(request()->except('page'));
    $withFilters = static fn (string $url): string => $queryString !== '' ? $url.'?'.$queryString : $url;
    $filtersExpanded = collect($filters)->except('status')->filter(fn ($value) => filled($value))->isNotEmpty()
        || (($filters['status'] ?? 'all') !== 'all');
@endphp

@section('content')
    <x-admin.report.page
        class="chat-report-page"
        :title="__('chat.report.title')"
        :description="__('chat.report.subtitle')"
    >
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="chat-report-filters"
                :refresh-url="$withFilters(route('admin.chat.reports.index'))"
                :export-options="[
                    [
                        'permission' => 'chat.reports.export',
                        'url' => $withFilters(route('admin.chat.reports.excel')),
                        'label' => __('chat.report.actions.excel'),
                        'icon' => 'file-excel',
                    ],
                    [
                        'permission' => 'chat.reports.pdf',
                        'url' => $withFilters(route('admin.chat.reports.pdf')),
                        'label' => __('chat.report.actions.pdf'),
                        'icon' => 'file-pdf',
                        'newTab' => true,
                    ],
                    [
                        'permission' => 'chat.reports.print',
                        'url' => $withFilters(route('admin.chat.reports.print')),
                        'label' => __('chat.report.actions.print'),
                        'icon' => 'print',
                        'newTab' => true,
                    ],
                ]"
            />
        </x-slot:actions>

        <div class="alert alert-warning border-0 d-flex align-items-start gap-2 py-2" role="note">
            <span class="fas fa-user-shield mt-1" aria-hidden="true"></span>
            <span class="fs-10">{{ __('chat.report.privacy_notice') }}</span>
        </div>

        <x-admin.report.filter-panel
            id="chat-report-filters"
            :title="__('reports.filters')"
            :description="__('chat.report.subtitle')"
            :action="route('admin.chat.reports.index')"
            :expanded="$filtersExpanded"
            :reset-url="route('admin.chat.reports.index')"
            :apply-label="__('chat.report.filters.apply')"
            :reset-label="__('chat.report.filters.clear')"
        >
            <div class="col-12 col-xl-4 report-filter-field">
                <label class="form-label mb-1" for="chat-report-q">{{ __('chat.report.filters.search') }}</label>
                <x-forms.input id="chat-report-q" name="q" type="search" :value="$filters['q'] ?? ''" maxlength="200" />
            </div>
            <div class="col-12 col-md-6 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="chat-report-participant">{{ __('chat.report.filters.participant') }}</label>
                <x-forms.input id="chat-report-participant" name="participant" type="search" :value="$filters['participant'] ?? ''" maxlength="100" />
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="chat-report-from">{{ __('chat.report.filters.date_from') }}</label>
                <x-forms.date-input id="chat-report-from" name="date_from" :value="$filters['date_from'] ?? ''" />
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="chat-report-to">{{ __('chat.report.filters.date_to') }}</label>
                <x-forms.date-input id="chat-report-to" name="date_to" :value="$filters['date_to'] ?? ''" />
            </div>
            <div class="col-6 col-md-3 col-xl-1 report-filter-field">
                <label class="form-label mb-1" for="chat-report-attachments">{{ __('chat.report.filters.has_attachments') }}</label>
                <x-forms.select class="form-select" id="chat-report-attachments" name="has_attachments">
                    <option value="">{{ __('chat.report.filters.all') }}</option>
                    <option value="1" @selected((string) ($filters['has_attachments'] ?? '') === '1')>{{ __('chat.report.filters.with_attachments') }}</option>
                    <option value="0" @selected((string) ($filters['has_attachments'] ?? '') === '0')>{{ __('chat.report.filters.without_attachments') }}</option>
                </x-forms.select>
            </div>
            <div class="col-6 col-md-3 col-xl-1 report-filter-field">
                <label class="form-label mb-1" for="chat-report-status">{{ __('chat.report.filters.status') }}</label>
                <x-forms.select class="form-select" id="chat-report-status" name="status">
                    <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>{{ __('chat.report.filters.all') }}</option>
                    <option value="active" @selected(($filters['status'] ?? null) === 'active')>{{ __('chat.report.active') }}</option>
                    <option value="deleted" @selected(($filters['status'] ?? null) === 'deleted')>{{ __('chat.report.deleted') }}</option>
                </x-forms.select>
            </div>
        </x-admin.report.filter-panel>

        <div class="row g-3 mb-3 chat-report-stats" aria-label="{{ __('chat.report.title') }}">
            @foreach (['conversations' => 'comments', 'messages' => 'comment-dots', 'attachments' => 'paperclip'] as $key => $icon)
                <div class="col-12 col-sm-4">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <span class="chat-report-stat-icon"><span class="fas fa-{{ $icon }}"></span></span>
                            <div>
                                <div class="fs-7 fw-bold lh-1">{{ number_format($statistics[$key]) }}</div>
                                <div class="text-600 fs-10 mt-1">{{ __('chat.report.statistics.'.$key) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card overflow-hidden">
            @if ($conversations->isEmpty())
                <div class="card-body py-6 text-center text-600">
                    <span class="fas fa-comments fs-5 mb-3 d-block" aria-hidden="true"></span>
                    {{ __('chat.report.empty') }}
                </div>
            @else
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-hover align-middle mb-0 chat-report-table">
                        <thead class="bg-200 text-900">
                            <tr>
                                <th>{{ __('chat.report.columns.conversation') }}</th>
                                <th>{{ __('chat.report.columns.participants') }}</th>
                                <th class="text-center">{{ __('chat.report.columns.message_count') }}</th>
                                <th class="text-center">{{ __('chat.report.columns.attachment_count') }}</th>
                                <th>{{ __('chat.report.columns.last_message_at') }}</th>
                                <th>{{ __('chat.report.columns.status') }}</th>
                                <th class="text-end">{{ __('common.actions.view') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($conversations as $conversation)
                                <tr>
                                    <td>
                                        <a class="fw-semibold" href="{{ route('admin.chat.reports.show', $conversation) }}">{{ $report->title($conversation) }}</a>
                                        <div class="text-500 fs-11 font-monospace text-truncate chat-report-uuid">{{ $conversation->public_uuid }}</div>
                                        @if ($conversation->messages->first())
                                            <div class="text-600 fs-11 text-truncate chat-report-preview">
                                                {{ $conversation->messages->first()->sender?->name }}:
                                                {{ $conversation->messages->first()->body ?: __('chat.report.no_message_body') }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            @foreach ($conversation->allParticipants as $participant)
                                                <span class="badge rounded-pill {{ $participant->pivot?->deleted_at ? 'bg-secondary-subtle text-secondary' : 'bg-primary-subtle text-primary' }}">
                                                    {{ $participant->name }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="text-center fw-semibold">{{ number_format($conversation->messages_count) }}</td>
                                    <td class="text-center fw-semibold">{{ number_format($conversation->attachments_count) }}</td>
                                    <td class="text-nowrap fs-10">{{ $dates->formatDateTime($conversation->last_message_at, '—') }}</td>
                                    <td>
                                        <span class="badge rounded-pill {{ $conversation->trashed() ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' }}">
                                            {{ $conversation->trashed() ? __('chat.report.deleted') : __('chat.report.active') }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @include('modules.core.chat.reports.partials.actions', ['conversation' => $conversation])
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-lg-none chat-report-mobile-list">
                    @foreach ($conversations as $conversation)
                        <article class="chat-report-mobile-item">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="min-w-0">
                                    <a class="fw-semibold d-block text-truncate" href="{{ route('admin.chat.reports.show', $conversation) }}">{{ $report->title($conversation) }}</a>
                                    <div class="text-500 fs-11">{{ $dates->formatDateTime($conversation->last_message_at, '—') }}</div>
                                </div>
                                <span class="badge rounded-pill {{ $conversation->trashed() ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' }}">
                                    {{ $conversation->trashed() ? __('chat.report.deleted') : __('chat.report.active') }}
                                </span>
                            </div>
                            <div class="d-flex flex-wrap gap-1 my-2">
                                @foreach ($conversation->allParticipants as $participant)
                                    <span class="badge rounded-pill bg-200 text-700">{{ $participant->name }}</span>
                                @endforeach
                            </div>
                            @if ($conversation->messages->first())
                                <p class="text-600 fs-10 mb-2 chat-report-mobile-preview">
                                    {{ $conversation->messages->first()->sender?->name }}:
                                    {{ $conversation->messages->first()->body ?: __('chat.report.no_message_body') }}
                                </p>
                            @endif
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <div class="text-600 fs-11">
                                    <span class="me-2"><span class="far fa-comment me-1"></span>{{ $conversation->messages_count }}</span>
                                    <span><span class="fas fa-paperclip me-1"></span>{{ $conversation->attachments_count }}</span>
                                </div>
                                @include('modules.core.chat.reports.partials.actions', ['conversation' => $conversation])
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="card-footer bg-body-tertiary">
                    {{ $conversations->links() }}
                </div>
            @endif
        </div>
    </x-admin.report.page>
@endsection

@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Core/report-ui.js') }}"></script>
@endpush
