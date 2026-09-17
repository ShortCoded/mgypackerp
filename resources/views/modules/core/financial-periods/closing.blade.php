@extends('layouts.app')

@php
    $dateFormat = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $statusClasses = ['pass' => 'success', 'warning' => 'warning', 'blocker' => 'danger'];
    $operationEntryDocNum = session('financial_period_operation_entry');
@endphp

@section('title', __('financial_periods.closing.title'))

@section('content')
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h5 class="mb-1">{{ __('financial_periods.closing.title') }}</h5>
                <p class="text-700 mb-0">{{ __('financial_periods.closing.description') }}</p>
            </div>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.financial-periods.index') }}">
                <span class="fas fa-list me-1"></span>{{ __('financial_periods.closing.periods_list') }}
            </a>
        </div>
        <div class="card-body">
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="GET" action="{{ route('admin.financial-periods.closing') }}" class="row g-3 align-items-end">
                <div class="col-lg-8">
                    <label class="form-label" for="period">{{ __('financial_periods.closing.select_period') }}</label>
                    <x-forms.select id="period" name="period" required>
                        @forelse ($periods as $period)
                            <option value="{{ $period->doc_num }}" @selected($selectedPeriod?->is($period))>
                                {{ $period->doc_num }} — {{ $period->name }} — {{ $period->is_closed ? __('financial_periods.statuses.closed') : __('financial_periods.statuses.open') }}
                            </option>
                        @empty
                            <option value="">{{ __('financial_periods.closing.no_periods') }}</option>
                        @endforelse
                    </x-forms.select>
                </div>
                <div class="col-lg-4">
                    <button class="btn btn-primary w-100" type="submit" @disabled($periods->isEmpty())>
                        <span class="fas fa-search me-1"></span>{{ __('financial_periods.closing.preview_action') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if ($selectedPeriod && $preview)
        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">{{ __('financial_periods.closing.scope_title') }}</h6></div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">{{ __('financial_periods.closing.company') }}</dt>
                            <dd class="col-sm-8">{{ $company?->name ?? '—' }}</dd>
                            <dt class="col-sm-4">{{ __('financial_periods.singular') }}</dt>
                            <dd class="col-sm-8">{{ $selectedPeriod->doc_num }} — {{ $selectedPeriod->name }}</dd>
                            <dt class="col-sm-4">{{ __('financial_periods.closing.date_range') }}</dt>
                            <dd class="col-sm-8" dir="ltr">{{ $dateFormat->formatDate($selectedPeriod->from_date, '') }} — {{ $dateFormat->formatDate($selectedPeriod->to_date, '') }}</dd>
                            <dt class="col-sm-4">{{ __('financial_periods.attributes.is_closed') }}</dt>
                            <dd class="col-sm-8">
                                <span class="badge bg-{{ $selectedPeriod->is_closed ? 'secondary' : 'success' }}">
                                    {{ $selectedPeriod->is_closed ? __('financial_periods.statuses.closed') : __('financial_periods.statuses.open') }}
                                </span>
                            </dd>
                        </dl>
                        <div class="alert alert-info mt-3 mb-0">{{ __('financial_periods.closing.company_scope_notice') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">{{ __('financial_periods.closing.carry_forward_title') }}</h6></div>
                    <div class="card-body">
                        @if ($preview['next_period'])
                            <p class="mb-2">{{ __('financial_periods.closing.next_period', ['period' => $preview['next_period']->doc_num.' — '.$preview['next_period']->name]) }}</p>
                        @else
                            <p class="mb-2 text-warning">{{ __('financial_periods.closing.no_next_period') }}</p>
                        @endif
                        <p class="text-700 mb-0">{{ __('financial_periods.closing.history_derived_opening') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h6 class="mb-0">{{ __('financial_periods.closing.preflight_title') }}</h6>
                <span class="badge bg-{{ $preview['has_blockers'] ? 'danger' : 'success' }}">
                    {{ $preview['has_blockers'] ? __('financial_periods.closing.blocked') : __('financial_periods.closing.ready') }}
                </span>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @foreach ($preview['checks'] as $check)
                        <div class="list-group-item d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <div>{{ $check['message'] }}</div>
                                @if (($check['details'] ?? []) !== [])
                                    <ul class="small text-700 mb-0 mt-2 ps-3">
                                        @foreach ($check['details'] as $detail)
                                            <li>
                                                <span>{{ $detail['label'] }}</span>
                                                @isset($detail['count'])
                                                    <span class="badge bg-200 text-900 ms-1">{{ $detail['count'] }}</span>
                                                @endisset
                                                @if (($detail['url'] ?? null) && (! ($detail['permission'] ?? null) || auth()->user()?->can($detail['permission'])))
                                                    <a class="ms-1" href="{{ $detail['url'] }}">{{ __('financial_periods.closing.review_documents') }}</a>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                @if ($check['key'] === 'draft_journals' && ($check['count'] ?? 0) > 0 && auth()->user()?->can('journal_entries.view'))
                                    <a href="{{ route('admin.accounting.journal-entries.index', ['status' => \Modules\Accounting\Models\JournalEntry::StatusDraft]) }}">
                                        {{ __('financial_periods.closing.review_documents') }}
                                    </a>
                                @endif
                                <span class="badge bg-{{ $statusClasses[$check['status']] }}">{{ __('financial_periods.closing.statuses.'.$check['status']) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><h6 class="mb-0">{{ __('financial_periods.closing.preview_title') }}</h6><div class="d-flex flex-wrap gap-2">@can('reports.trial_balance.view')<a class="btn btn-sm btn-falcon-default" href="{{ route('admin.accounting.reports.trial-balance', ['run' => 1, 'from_date' => $selectedPeriod->from_date->toDateString(), 'to_date' => $selectedPeriod->to_date->toDateString()]) }}">{{ __('financial_periods.closing.open_trial_balance') }}</a>@endcan @can('reports.financial_statements.view')<a class="btn btn-sm btn-falcon-default" href="{{ route('admin.accounting.reports.financial-statements', ['run' => 1, 'from_date' => $selectedPeriod->from_date->toDateString(), 'to_date' => $selectedPeriod->to_date->toDateString()]) }}">{{ __('financial_periods.closing.open_financial_statements') }}</a>@endcan</div></div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-3"><strong>{{ __('financial_periods.closing.trial_debit') }}:</strong> {{ $numbers->format($preview['trial_balance']['debit']) }}</div>
                    <div class="col-md-3"><strong>{{ __('financial_periods.closing.trial_credit') }}:</strong> {{ $numbers->format($preview['trial_balance']['credit']) }}</div>
                    <div class="col-md-3"><strong>{{ __('financial_periods.closing.trial_difference') }}:</strong> {{ $numbers->format($preview['trial_balance']['difference']) }}</div>
                    <div class="col-md-3"><strong>{{ __('financial_periods.closing.period_result') }}:</strong> {{ $numbers->format($preview['closing_plan']['period_result'] ?? '0.0000') }}</div>
                </div>

                @if ($preview['closing_plan'] && $preview['closing_plan']['lines'] !== [])
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead><tr><th>{{ __('financial_periods.closing.account') }}</th><th>{{ __('financial_periods.closing.description_column') }}</th><th class="text-end">{{ __('financial_periods.closing.debit') }}</th><th class="text-end">{{ __('financial_periods.closing.credit') }}</th></tr></thead>
                            <tbody>
                                @foreach ($preview['closing_plan']['lines'] as $line)
                                    <tr>
                                        <td>{{ $line['account_label'] }}</td>
                                        <td>{{ $line['description'] }}</td>
                                        <td class="text-end">{{ $numbers->format($line['debit_amount']) }}</td>
                                        <td class="text-end">{{ $numbers->format($line['credit_amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot><tr class="fw-bold"><td colspan="2">{{ __('financial_periods.closing.total') }}</td><td class="text-end">{{ $numbers->format($preview['closing_plan']['total_debit']) }}</td><td class="text-end">{{ $numbers->format($preview['closing_plan']['total_credit']) }}</td></tr></tfoot>
                        </table>
                    </div>
                @else
                    <div class="alert alert-secondary mb-0">{{ __('financial_periods.closing.no_closing_lines') }}</div>
                @endif
            </div>
        </div>

        @if ($preview['existing_closing_entry'] || $operationEntryDocNum)
            <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>{{ __('financial_periods.closing.operation_result') }}</span>
                @can('journal_entries.view')
                    @php($resultEntryDocNum = $operationEntryDocNum ?: $preview['existing_closing_entry']?->doc_num)
                    @if ($resultEntryDocNum)
                        <a class="btn btn-sm btn-falcon-success" href="{{ route('admin.accounting.journal-entries.show', $resultEntryDocNum) }}">{{ $resultEntryDocNum }}</a>
                    @endif
                @endcan
            </div>
        @endif

        <div class="card">
            <div class="card-header"><h6 class="mb-0">{{ __('financial_periods.closing.execution_title') }}</h6></div>
            <div class="card-body">
                @if (! $selectedPeriod->is_closed)
                    @can('financial_periods.close')
                        <form method="POST" action="{{ route('admin.financial-periods.close', $selectedPeriod->doc_num) }}" onsubmit="return confirm(@js(__('financial_periods.messages.close_confirm_text'))) ">
                            @csrf
                            <x-forms.input type="hidden" name="return_to" value="closing" />
                            <div class="mb-3">
                                <label class="form-label" for="operation_note">{{ __('financial_periods.closing.operation_note') }}</label>
                                <x-forms.textarea id="operation_note" name="operation_note" rows="2" maxlength="1000">{{ old('operation_note') }}</x-forms.textarea>
                            </div>
                            <div class="form-check mb-3">
                                <x-forms.input class="form-check-input" id="confirm_result_transfer" name="confirm_result_transfer" type="checkbox" value="1" required />
                                <label class="form-check-label" for="confirm_result_transfer">{{ __('financial_periods.closing.confirm_result_transfer') }}</label>
                            </div>
                            <button class="btn btn-warning" type="submit" @disabled($preview['has_blockers'])>
                                <span class="fas fa-lock me-1"></span>{{ __('financial_periods.actions.close') }}
                            </button>
                        </form>
                    @else
                        <div class="alert alert-warning mb-0">{{ __('financial_periods.closing.no_close_permission') }}</div>
                    @endcan
                @else
                    @can('financial_periods.reopen')
                        <form method="POST" action="{{ route('admin.financial-periods.reopen', $selectedPeriod->doc_num) }}" onsubmit="return confirm(@js(__('financial_periods.messages.reopen_confirm_text'))) ">
                            @csrf
                            <x-forms.input type="hidden" name="return_to" value="closing" />
                            <div class="mb-3">
                                <label class="form-label" for="operation_note">{{ __('financial_periods.closing.operation_note') }}</label>
                                <x-forms.textarea id="operation_note" name="operation_note" rows="2" maxlength="1000">{{ old('operation_note') }}</x-forms.textarea>
                            </div>
                            <button class="btn btn-falcon-warning" type="submit">
                                <span class="fas fa-lock-open me-1"></span>{{ __('financial_periods.actions.reopen') }}
                            </button>
                        </form>
                    @else
                        <div class="alert alert-warning mb-0">{{ __('financial_periods.closing.no_reopen_permission') }}</div>
                    @endcan
                @endif
            </div>
        </div>
    @endif
@endsection
