@extends('layouts.app')
@php
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isReadonly = $mode === 'view' || (! $isCreateLike && ($isLocked ?? false));
    $title = __("opening_balances.{$mode}");
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $dateValue = fn () => old('document_date', $record?->document_date ? $dateFormatService->formatDate($record->document_date, '') : $dateFormatService->formatDate(now(), ''));
    $formatAmount = fn ($amount) => rtrim(rtrim(number_format((float) $amount, 3, '.', ''), '0'), '.') ?: '0';
    $value = fn($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $currencyOption = $record?->currency ? ['id' => $record->currency->doc_num, 'text' => trim($record->currency->code.' — '.$record->currency->name)] : ($defaultCurrencyOption ?? null);
    $selectedCurrencyDocNum = old('currency_doc_num', $currencyOption['id'] ?? '');
    $isMainCurrencySelected = ($mainCurrencyDocNum ?? null) && $selectedCurrencyDocNum === $mainCurrencyDocNum;
    $existingLines = old('lines');
    if (! is_array($existingLines)) {
        $existingLines = $record?->lines?->map(fn($line) => [
            'account_doc_num' => $line->account?->doc_num,
            'account_label' => $line->account?->codeNameLabel(),
            'account_normal_balance' => $line->account?->normal_balance,
            'transaction_type' => ((float) $line->debit_amount) > 0 ? 'debit' : 'credit',
            'amount' => ((float) $line->debit_amount) > 0 ? $line->debit_amount : $line->credit_amount,
            'description' => $line->description,
            'debit_amount' => $line->debit_amount,
            'credit_amount' => $line->credit_amount,
        ])->values()->all() ?? [];
    }
    if ($existingLines === []) {
        $existingLines = [['account_doc_num' => null, 'account_label' => null, 'transaction_type' => 'debit', 'amount' => null, 'description' => null]];
    }
    $lineDebit = fn (array $line) => array_key_exists('debit_amount', $line) ? (float) $line['debit_amount'] : (($line['transaction_type'] ?? 'debit') === 'debit' ? (float) ($line['amount'] ?? 0) : 0.0);
    $lineCredit = fn (array $line) => array_key_exists('credit_amount', $line) ? (float) $line['credit_amount'] : (($line['transaction_type'] ?? 'debit') === 'credit' ? (float) ($line['amount'] ?? 0) : 0.0);
    $totalDebit = collect($existingLines)->sum(fn (array $line) => $lineDebit($line));
    $totalCredit = collect($existingLines)->sum(fn (array $line) => $lineCredit($line));
@endphp
@section('title', $title)
@section('content')
<form class="js-finance-form js-crud-form js-opening-balance-form" action="{{ $action }}" method="{{ $method }}" data-resource="opening_balances" data-primary-focus="description" data-mode="{{ $mode }}" data-account-url="{{ route('admin.finance.select2.accounts') }}" data-main-currency-doc-num="{{ $mainCurrencyDocNum ?? '' }}" novalidate>
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif
    <input type="hidden" name="submit_action" value="save">
    @if($cloneSourceToken)
        <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
    @endif

    <div class="card mb-3">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col">
                    <h5 class="mb-0">{{ $title }}</h5>
                </div>
                <div class="col-auto">
                    @include('modules.finance.partials.form-actions', ['resource' => 'opening_balances', 'routePrefix' => 'admin.finance.opening-balances', 'canEditRecord' => ! ($record?->isLockedForEditing() ?? false), 'canDeleteRecord' => ! ($record?->isLockedForEditing() ?? false)])
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="alert d-none js-form-alert">
                <div class="js-form-alert-message"></div>
            </div>

            @if(! $isCreateLike && $record?->isApproved())
                <div class="alert alert-warning">{{ __('opening_balances.messages.approved_edit_forbidden') }}</div>
            @elseif(! $isCreateLike && $record?->isClosed())
                <div class="alert alert-warning">{{ __('opening_balances.messages.closed_edit_forbidden') }}</div>
            @elseif(! $isCreateLike && $record?->isLockedForEditing())
                <div class="alert alert-warning">{{ __('opening_balances.messages.document_locked') }}</div>
            @endif

            <h6 class="text-700 mb-3">{{ __('opening_balances.sections.header') }}</h6>
            <div class="row g-3">
                @if($mode === 'view')
                    <div class="col-md-3">
                        <x-forms.view-field for="document_status" :label="__('opening_balances.attributes.document_status')" :value="$record?->trashed() ? __('opening_balances.statuses.deleted') : __('opening_balances.statuses.'.($record?->status ?? 'draft'))" />
                    </div>
                @endif

                @if($canControlDocumentNumber)
                    <div class="col-md-3">
                        <label class="form-label" for="doc_number">{{ __('opening_balances.attributes.doc_number') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                        @else
                            <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                            <div class="form-text">{{ __('item_lookups.document_number_control.helper') }}</div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                    </div>
                @elseif(! $isCreateLike)
                    <div class="col-md-3">
                        <x-forms.view-field for="doc_num" :label="__('opening_balances.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                    </div>
                @endif

                <div class="col-md-3">
                    <x-forms.label for="document_date" :label="__('opening_balances.attributes.document_date')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="document_date" :value="$dateValue()" dir="ltr" input-class="date-value" />
                    @else
                        <input class="form-control js-date-picker" id="document_date" name="document_date" type="text" value="{{ $dateValue() }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="document_date"></div>
                </div>

                <div class="col-md-3">
                    <x-forms.label for="currency_doc_num" :label="__('opening_balances.attributes.currency')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="currency_doc_num" :value="$currencyOption['text'] ?? null" />
                    @else
                        <select class="form-select js-select2-ajax" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('common.placeholders.select') }}" required>
                            @if($currencyOption)
                                <option value="{{ $currencyOption['id'] }}" selected>{{ $currencyOption['text'] }}</option>
                            @endif
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                </div>

                <div class="col-md-3">
                    <x-forms.label for="exchange_rate" :label="__('opening_balances.attributes.exchange_rate')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="exchange_rate" :value="$formatAmount($value('exchange_rate', 1))" input-class="text-center" dir="ltr" />
                    @else
                        <input class="form-control text-center" id="exchange_rate" name="exchange_rate" type="number" min="0.000001" step="0.000001" value="{{ $value('exchange_rate', 1) }}" dir="ltr" required @readonly($isMainCurrencySelected)>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="description">{{ __('opening_balances.attributes.description') }}</label>
                    <input class="form-control" id="description" name="description" value="{{ $value('description') }}" @readonly($isReadonly) @unless($isReadonly) autofocus @endunless>
                    <div class="invalid-feedback d-block" data-error-for="description"></div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="notes">{{ __('opening_balances.attributes.notes') }}</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" @readonly($isReadonly)>{{ $value('notes') }}</textarea>
                    <div class="invalid-feedback d-block" data-error-for="notes"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col">
                    <h6 class="mb-0">{{ __('opening_balances.sections.lines') }}</h6>
                </div>
                @unless($isReadonly)
                    <div class="col-auto">
                        <button class="btn btn-falcon-default btn-sm js-opening-balance-add-line" type="button" title="{{ __('opening_balances.js.add_line_title') }}" data-bs-title="{{ __('opening_balances.js.add_line_title') }}">
                            <span class="fas fa-plus me-1"></span>{{ __('opening_balances.actions.add_line') }}
                        </button>
                    </div>
                @endunless
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 js-opening-balance-lines">
                    <thead class="bg-200">
                        <tr>
                            <th style="width: 42%">{{ __('opening_balances.attributes.account') }}</th>
                            <th style="width: 14%">{{ __('opening_balances.attributes.transaction_type') }}</th>
                            <th style="width: 16%">{{ __('opening_balances.attributes.amount') }}</th>
                            <th>{{ __('opening_balances.attributes.line_description') }}</th>
                            @unless($isReadonly)
                                <th class="text-center" style="width: 76px">{{ __('common.fields.actions') }}</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($existingLines as $index => $line)
                            <tr class="js-opening-balance-line" data-index="{{ $index }}">
                                <td>
                                    @if($isReadonly)
                                        <div class="form-control-plaintext">{{ $line['account_label'] ?? null }}</div>
                                    @else
                                        <select class="form-select js-select2-ajax js-opening-balance-account" name="lines[{{ $index }}][account_doc_num]" data-url="{{ route('admin.finance.select2.accounts') }}" data-placeholder="{{ __('opening_balances.js.select_account') }}" required>
                                            @if(! empty($line['account_doc_num']))
                                                <option value="{{ $line['account_doc_num'] }}" data-normal-balance="{{ $line['account_normal_balance'] ?? '' }}" selected>{{ $line['account_label'] ?? $line['account_doc_num'] }}</option>
                                            @endif
                                        </select>
                                        <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.account_doc_num"></div>
                                    @endif
                                </td>
                                <td>
                                    @if($isReadonly)
                                        <div class="form-control-plaintext">{{ __('opening_balances.transaction_types.'.($line['transaction_type'] ?? 'debit')) }}</div>
                                    @else
                                        <select class="form-select js-opening-balance-type" name="lines[{{ $index }}][transaction_type]" required>
                                            <option value=""></option>
                                            <option value="debit" @selected(($line['transaction_type'] ?? 'debit') === 'debit')>{{ __('opening_balances.transaction_types.debit') }}</option>
                                            <option value="credit" @selected(($line['transaction_type'] ?? 'debit') === 'credit')>{{ __('opening_balances.transaction_types.credit') }}</option>
                                        </select>
                                        <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.transaction_type"></div>
                                    @endif
                                </td>
                                <td>
                                    @if($isReadonly)
                                        <div class="form-control-plaintext text-end" dir="ltr">{{ $formatAmount($line['amount'] ?? 0) }}</div>
                                    @else
                                        <input class="form-control text-end js-opening-balance-amount" name="lines[{{ $index }}][amount]" type="number" min="0.0001" step="0.0001" value="{{ $line['amount'] ?? '' }}" dir="ltr" required>
                                        <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.amount"></div>
                                    @endif
                                </td>
                                <td>
                                    @if($isReadonly)
                                        <div class="form-control-plaintext">{{ $line['description'] ?? null }}</div>
                                    @else
                                        <input class="form-control" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}">
                                        <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.description"></div>
                                    @endif
                                </td>
                                @unless($isReadonly)
                                    <td class="text-center">
                                        <button class="btn btn-link text-600 p-0 me-2 js-opening-balance-duplicate-line" type="button" title="{{ __('opening_balances.js.duplicate_line_title') }}" data-bs-title="{{ __('opening_balances.js.duplicate_line_title') }}">
                                            <span class="fas fa-copy"></span>
                                        </button>
                                        <button class="btn btn-link text-danger p-0 js-opening-balance-remove-line" type="button" title="{{ __('opening_balances.js.delete_line_title') }}" data-bs-title="{{ __('opening_balances.js.delete_line_title') }}">
                                            <span class="fas fa-trash-alt"></span>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-light">
                        <tr>
                            <th></th>
                            <th class="text-end">{{ __('opening_balances.attributes.total_debit') }}</th>
                            <th class="text-end js-opening-balance-total-debit" dir="ltr">{{ $formatAmount($totalDebit) }}</th>
                            <th></th>
                            @unless($isReadonly)
                                <th></th>
                            @endunless
                        </tr>
                        <tr>
                            <th></th>
                            <th class="text-end">{{ __('opening_balances.attributes.total_credit') }}</th>
                            <th class="text-end js-opening-balance-total-credit" dir="ltr">{{ $formatAmount($totalCredit) }}</th>
                            <th></th>
                            @unless($isReadonly)
                                <th></th>
                            @endunless
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="px-3 py-2">
                <div class="invalid-feedback d-block" data-error-for="lines"></div>
                <div class="invalid-feedback d-block" data-error-for="document"></div>
            </div>
        </div>
        <div class="card-footer">
            @if($mode === 'view' && $record && ! $record->approved && ! $record->is_cancelled)
                @can('opening_balances.approve')
                    <button class="btn btn-success btn-sm js-approve-opening-balance me-2" type="button" data-url="{{ route('admin.finance.opening-balances.approve', $record->doc_num) }}">
                        <span class="fas fa-check me-1"></span>{{ __('opening_balances.actions.approve') }}
                    </button>
                @endcan
            @endif
            @include('modules.finance.partials.form-actions', ['resource' => 'opening_balances', 'routePrefix' => 'admin.finance.opening-balances', 'canEditRecord' => ! ($record?->isLockedForEditing() ?? false), 'canDeleteRecord' => ! ($record?->isLockedForEditing() ?? false)])
        </div>
    </div>

    @if($mode === 'view')
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('common.sections.audit_information') }}</h6>
            </div>
            <div class="card-body">
                <x-audit-fields-row
                    :metadata="$metadata"
                    :show-deleted="$record?->trashed() ?? false"
                    :show-restored="! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    class="mt-0"
                />
                @if($record?->approved)
                    <div class="row g-3 mt-0">
                        <x-forms.view-field :label="__('opening_balances.attributes.approved_by')" :value="$metadata['approved_by'] ?? null" class="col-md-6 col-xl-3" />
                        <x-forms.view-field :label="__('opening_balances.attributes.approved_at')" :value="$metadata['approved_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                    </div>
                @endif
            </div>
        </div>
    @endif
</form>
@endsection
@push('scripts')
<script>window.financeCrudMessages=@json(__('finance.js'));</script>
<script>window.openingBalanceMessages=@json(__('opening_balances.js'));</script>
<script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('assets/js/modules/Finance/finance-foundation.js') }}"></script>
<script src="{{ asset('assets/js/modules/Finance/opening-balances.js') }}"></script>
@endpush
