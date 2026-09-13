@extends('layouts.app')

@php
    $isCreate = $mode === 'create';
    $isReadonly = $mode === 'view' || ($record?->status === \Modules\Accounting\Models\JournalEntry::StatusPosted) || ($record?->trashed() ?? false);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $lines = old('lines');
    if (! is_array($lines)) {
        $lines = $record?->lines?->map(fn ($line) => [
            'account_doc_num' => $line->account?->doc_num,
            'account_label' => $line->account?->codeNameLabel(),
            'debit_amount' => $line->debit_amount,
            'credit_amount' => $line->credit_amount,
            'description' => $line->description,
            'cost_center_doc_num' => $line->costCenter?->doc_num,
            'cost_center_label' => $line->costCenter?->codeNameLabel(),
            'customer_doc_num' => $line->customer?->doc_num,
            'customer_label' => $line->customer ? trim($line->customer->doc_num.' / '.$line->customer->name) : null,
            'supplier_doc_num' => $line->supplier?->doc_num,
            'supplier_label' => $line->supplier ? trim($line->supplier->doc_num.' / '.$line->supplier->name) : null,
            'employee_doc_num' => $line->employee?->doc_num,
            'employee_label' => $line->employee ? trim($line->employee->doc_num.' / '.$line->employee->full_name) : null,
        ])->values()->all() ?? [];
    }
    if ($lines === []) {
        $lines = [
            ['debit_amount' => '', 'credit_amount' => '0'],
            ['debit_amount' => '0', 'credit_amount' => ''],
        ];
    }
    $totalDebit = collect($lines)->reduce(fn (string $total, array $line): string => bcadd($total, (string) ($line['debit_amount'] ?? 0), 4), '0.0000');
    $totalCredit = collect($lines)->reduce(fn (string $total, array $line): string => bcadd($total, (string) ($line['credit_amount'] ?? 0), 4), '0.0000');
    $totalDifference = bcsub($totalDebit, $totalCredit, 4);
    $title = $isCreate ? __('journal_entries.create') : __('journal_entries.document_title', ['doc_num' => $record?->doc_num]);
@endphp

@section('title', $title)

@section('content')
    <form class="js-journal-entry-form" action="{{ $action }}" method="POST"
        data-accounts-url="{{ route('admin.accounting.journal-entries.select2.accounts') }}"
        data-cost-centers-url="{{ route('admin.accounting.journal-entries.select2.cost-centers') }}"
        data-customers-url="{{ route('admin.accounting.journal-entries.select2.customers') }}"
        data-suppliers-url="{{ route('admin.accounting.journal-entries.select2.suppliers') }}"
        data-employees-url="{{ route('admin.accounting.journal-entries.select2.employees') }}"
        novalidate>
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif

        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h5 class="mb-1">{{ $title }}</h5>
                    @if($record)
                        <span class="badge rounded-pill badge-subtle-{{ $record->status === \Modules\Accounting\Models\JournalEntry::StatusPosted ? 'success' : 'info' }}">{{ __('journal_entries.statuses.'.$record->status) }}</span>
                        <span class="badge rounded-pill badge-subtle-secondary">{{ $record->is_system_generated ? __('journal_entries.system_generated') : __('journal_entries.manual') }}</span>
                    @endif
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.accounting.journal-entries.index') }}">{{ __('common.actions.back') }}</a>
                    @unless($isReadonly)
                        <button class="btn btn-falcon-primary btn-sm" type="submit"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
                    @endunless
                    @if($mode === 'view' && $record && $record->status === \Modules\Accounting\Models\JournalEntry::StatusDraft && ! $record->is_system_generated && ! $record->trashed())
                        @can('journal_entries.post')
                            <button class="btn btn-falcon-success btn-sm js-journal-entry-post" type="button" data-url="{{ route('admin.accounting.journal-entries.post', $record->doc_num) }}"><span class="fas fa-check me-1"></span>{{ __('journal_entries.actions.post') }}</button>
                        @endcan
                    @endif
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-danger d-none js-journal-entry-alert" role="alert"></div>
                <div class="invalid-feedback d-block" data-error-for="document"></div>
                <div class="row g-3">
                    @if($record)
                        <div class="col-md-3">
                            <label class="form-label">{{ __('journal_entries.attributes.doc_num') }}</label>
                            <div class="form-control-plaintext fw-semibold" dir="ltr">{{ $record->doc_num }}</div>
                        </div>
                    @endif
                    <div class="col-md-3">
                        <label class="form-label" for="entry_date">{{ __('journal_entries.attributes.entry_date') }}</label>
                        <x-forms.date-input id="entry_date" name="entry_date" :value="old('entry_date', $record?->entry_date?->format('Y-m-d') ?? now()->format('Y-m-d'))" :readonly='$isReadonly' required />
                        <div class="invalid-feedback d-block" data-error-for="entry_date"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="reference_no">{{ __('journal_entries.attributes.reference_no') }}</label>
                        <x-forms.input class="form-control" id="reference_no" name="reference_no" value="{{ old('reference_no', $record?->reference_no) }}" :readonly='$isReadonly' dir="ltr" />
                        <div class="invalid-feedback d-block" data-error-for="reference_no"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('journal_entries.attributes.branch') }}</label>
                        <div class="form-control-plaintext">{{ $record?->branch?->name ?? data_get($operatingContext, 'branch.label', '—') }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('journal_entries.attributes.financial_period') }}</label>
                        <div class="form-control-plaintext">{{ $record?->financialPeriod?->name ?? data_get($operatingContext, 'financial_period.label', '—') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="description">{{ __('journal_entries.attributes.description') }}</label>
                        <x-forms.input class="form-control" id="description" name="description" value="{{ old('description', $record?->description) }}" :readonly='$isReadonly' />
                        <div class="invalid-feedback d-block" data-error-for="description"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="notes">{{ __('journal_entries.attributes.notes') }}</label>
                        <x-forms.input class="form-control" id="notes" name="notes" value="{{ old('notes', $record?->notes) }}" :readonly='$isReadonly' />
                        <div class="invalid-feedback d-block" data-error-for="notes"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h6 class="mb-0">{{ __('journal_entries.sections.lines') }}</h6>
                @unless($isReadonly)
                    <button class="btn btn-falcon-default btn-sm js-journal-entry-add-line" type="button"><span class="fas fa-plus me-1"></span>{{ __('journal_entries.actions.add_line') }}</button>
                @endunless
            </div>
            <div class="card-body p-0">
                <div class="erp-datatable-scroll">
                    <table class="table table-sm table-hover align-middle mb-0 erp-datatable-wide js-journal-entry-lines">
                        <thead class="bg-100">
                            <tr>
                                <th class="dt-code">{{ __('journal_entries.attributes.account') }}</th>
                                <th class="dt-number">{{ __('journal_entries.attributes.debit') }}</th>
                                <th class="dt-number">{{ __('journal_entries.attributes.credit') }}</th>
                                <th class="dt-text">{{ __('journal_entries.attributes.line_description') }}</th>
                                <th class="dt-code">{{ __('journal_entries.attributes.cost_center') }}</th>
                                <th class="dt-code">{{ __('journal_entries.attributes.customer') }}</th>
                                <th class="dt-code">{{ __('journal_entries.attributes.supplier') }}</th>
                                <th class="dt-code">{{ __('journal_entries.attributes.employee') }}</th>
                                @unless($isReadonly)<th class="dt-actions"></th>@endunless
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($lines as $index => $line)
                                @include('modules.accounting.journal-entries.partials.line', ['index' => $index, 'line' => $line, 'readonly' => $isReadonly])
                            @endforeach
                        </tbody>
                        <tfoot class="bg-light fw-semibold">
                            <tr>
                                <td class="text-end">{{ __('journal_entries.attributes.totals') }}</td>
                                <td class="text-end js-journal-entry-total-debit" dir="ltr">{{ $numbers->format($totalDebit) }}</td>
                                <td class="text-end js-journal-entry-total-credit" dir="ltr">{{ $numbers->format($totalCredit) }}</td>
                                <td colspan="{{ $isReadonly ? 5 : 6 }}"><span class="js-journal-entry-difference @if($isReadonly) {{ bccomp($totalDifference, '0', 4) === 0 ? 'text-success' : 'text-danger' }} @endif">@if($isReadonly) {{ __('journal_entries.js.difference') }}: {{ $numbers->format($totalDifference) }} @endif</span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="invalid-feedback d-block px-3 pb-3" data-error-for="lines"></div>
            </div>
        </div>

        @if($record)
            <div class="card">
                <div class="card-header"><h6 class="mb-0">{{ __('journal_entries.sections.audit') }}</h6></div>
                <div class="card-body">
                    <dl class="row mb-0 fs-10">
                        <dt class="col-sm-2">{{ __('common.fields.created_by') }}</dt><dd class="col-sm-4">{{ $record->createdBy?->name ?? '—' }}</dd>
                        <dt class="col-sm-2">{{ __('common.fields.created_at') }}</dt><dd class="col-sm-4">{{ $dates->formatDateTime($record->created_at, '—') }}</dd>
                        <dt class="col-sm-2">{{ __('common.fields.updated_by') }}</dt><dd class="col-sm-4">{{ $record->updatedBy?->name ?? '—' }}</dd>
                        <dt class="col-sm-2">{{ __('journal_entries.attributes.posted_by') }}</dt><dd class="col-sm-4">{{ $record->postedBy?->name ?? '—' }} @if($record->posted_at) / {{ $dates->formatDateTime($record->posted_at, '') }} @endif</dd>
                    </dl>
                </div>
            </div>
        @endif
    </form>
@endsection

@push('scripts')
    <script>window.journalEntryMessages = @json(__('journal_entries.js'));</script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Accounting/journal-entries.js') }}"></script>
@endpush
