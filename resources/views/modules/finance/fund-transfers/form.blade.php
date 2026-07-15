@extends('layouts.app')

@php
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isReadonly = $mode === 'view' || (! $isCreateLike && ($isLocked ?? false));
    $title = __('fund_transfers.'.$mode);
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $formatAmount = fn ($amount, $scale = 4) => rtrim(rtrim(number_format((float) $amount, $scale, '.', ''), '0'), '.') ?: '0';
    $value = fn($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $dateValue = old('transfer_date', $record?->transfer_date ? $dateFormatService->formatDate($record->transfer_date, '') : $dateFormatService->formatDate(now(), ''));
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $sourceType = old('source_type', $record?->source_type ?? \Modules\Finance\Models\FundTransfer::HolderCashbox);
    $targetType = old('target_type', $record?->target_type ?? \Modules\Finance\Models\FundTransfer::HolderBankAccount);
    $sourceOption = $holderOptions['source'] ?? null;
    $targetOption = $holderOptions['target'] ?? null;
    $sourceCurrencyOption = $currencyOptions['source'] ?? null;
    $targetCurrencyOption = $currencyOptions['target'] ?? null;
@endphp

@section('title', $title)

@section('content')
<form class="js-finance-form js-crud-form js-fund-transfer-form"
    action="{{ $action }}"
    method="{{ $method }}"
    data-resource="{{ $resource }}"
    data-primary-focus="transfer_date"
    data-mode="{{ $mode }}"
    data-cashbox-url="{{ route('admin.finance.select2.cashboxes') }}"
    data-bank-account-url="{{ route('admin.finance.select2.bank-accounts') }}"
    data-currency-url="{{ route('admin.finance.select2.holder-currencies') }}"
    data-main-currency-doc-num="{{ $mainCurrencyDocNum ?? '' }}"
    novalidate>
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif
    <input type="hidden" name="submit_action" value="save">
    @if($cloneSourceToken)
        <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
    @endif
    <input type="hidden" id="source_holder_doc_num" value="{{ $sourceOption['id'] ?? '' }}">
    <input type="hidden" id="target_holder_doc_num" value="{{ $targetOption['id'] ?? '' }}">

    <div class="card mb-3">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col">
                    <h5 class="mb-0">{{ $title }}</h5>
                </div>
                <div class="col-auto">
                    @include('modules.finance.fund-transfers.partials.form-actions', compact('mode', 'record', 'resource', 'routePrefix'))
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="alert d-none js-form-alert">
                <div class="js-form-alert-message"></div>
            </div>

            @if(! $isCreateLike && $record?->isLockedForEditing())
                <div class="alert alert-warning">{{ __('fund_transfers.messages.document_locked') }}</div>
            @endif

            <div class="row g-3">
                @if(! $isCreateLike)
                    <div class="col-md-3">
                        <x-forms.view-field for="document_status" :label="__('fund_transfers.attributes.document_status')" :value="$record?->trashed() ? __('fund_transfers.statuses.deleted') : __('fund_transfers.statuses.'.($record?->status ?? 'draft'))" />
                    </div>
                @endif

                @if($canControlDocumentNumber)
                    <div class="col-md-3">
                        <label class="form-label" for="doc_number">{{ __('fund_transfers.attributes.doc_number') }}</label>
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
                        <x-forms.view-field for="doc_num" :label="__('fund_transfers.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                    </div>
                @endif

                <div class="col-md-3">
                    <x-forms.label for="transfer_date" :label="__('fund_transfers.attributes.transfer_date')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="transfer_date" :value="$dateValue" dir="ltr" input-class="date-value" />
                    @else
                        <input class="form-control text-center js-date-picker" id="transfer_date" name="transfer_date" type="text" value="{{ $dateValue }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="transfer_date"></div>
                </div>

                <div class="w-100 d-none d-md-block"></div>

                <div class="col-md-3">
                    <x-forms.label for="source_type" :label="__('fund_transfers.attributes.source_type')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="source_type" :value="__('fund_transfers.holder_types.'.$sourceType)" />
                    @else
                        <select class="form-select js-fund-transfer-holder-type" id="source_type" name="source_type" data-side="source" required>
                            @foreach(\Modules\Finance\Models\FundTransfer::holderTypes() as $type)
                                <option value="{{ $type }}" @selected($sourceType === $type)>{{ __('fund_transfers.holder_types.'.$type) }}</option>
                            @endforeach
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="source_type"></div>
                </div>

                <div @class(['col-md-3 js-fund-transfer-holder js-source-cashbox-holder', 'd-none' => $sourceType !== 'cashbox']) data-side="source" data-holder-type="cashbox">
                    <x-forms.label for="source_cashbox_doc_num" :label="__('fund_transfers.attributes.source_cashbox')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="source_cashbox_doc_num" :value="$sourceType === 'cashbox' ? ($sourceOption['text'] ?? null) : null" />
                    @else
                        <select class="form-select js-select2-ajax js-fund-transfer-holder-select js-source-cashbox" id="source_cashbox_doc_num" name="source_cashbox_doc_num" data-url="{{ route('admin.finance.select2.cashboxes') }}" data-side="source" data-holder-type="cashbox" data-placeholder="{{ __('fund_transfers.js.select_cashbox') }}" data-allow-clear="true" @disabled($sourceType !== 'cashbox')>
                            @if($sourceType === 'cashbox' && $sourceOption)
                                <option value="{{ $sourceOption['id'] }}" selected>{{ $sourceOption['text'] }}</option>
                            @endif
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="source_cashbox_doc_num"></div>
                </div>

                <div @class(['col-md-3 js-fund-transfer-holder js-source-bank-account-holder', 'd-none' => $sourceType !== 'bank_account']) data-side="source" data-holder-type="bank_account">
                    <x-forms.label for="source_bank_account_doc_num" :label="__('fund_transfers.attributes.source_bank_account')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="source_bank_account_doc_num" :value="$sourceType === 'bank_account' ? ($sourceOption['text'] ?? null) : null" />
                    @else
                        <select class="form-select js-select2-ajax js-fund-transfer-holder-select js-source-bank-account" id="source_bank_account_doc_num" name="source_bank_account_doc_num" data-url="{{ route('admin.finance.select2.bank-accounts') }}" data-side="source" data-holder-type="bank_account" data-placeholder="{{ __('fund_transfers.js.select_bank_account') }}" data-allow-clear="true" @disabled($sourceType !== 'bank_account')>
                            @if($sourceType === 'bank_account' && $sourceOption)
                                <option value="{{ $sourceOption['id'] }}" selected>{{ $sourceOption['text'] }}</option>
                            @endif
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="source_bank_account_doc_num"></div>
                </div>

                <div class="col-md-3">
                    <x-forms.label for="source_currency_doc_num" :label="__('fund_transfers.attributes.source_currency')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="source_currency_doc_num" :value="$sourceCurrencyOption['text'] ?? null" />
                    @else
                        <select class="form-select js-select2-ajax js-fund-transfer-currency js-source-currency" id="source_currency_doc_num" name="source_currency_doc_num" data-url="{{ route('admin.finance.select2.holder-currencies') }}" data-extra-params='@json(['holder_type' => '#source_type', 'holder' => '#source_holder_doc_num'])' data-side="source" data-placeholder="{{ __('fund_transfers.js.select_currency') }}" data-allow-clear="true" required>
                            @if($sourceCurrencyOption)
                                <option value="{{ $sourceCurrencyOption['id'] }}" data-is-main="{{ $sourceCurrencyOption['is_main'] ? '1' : '0' }}" selected>{{ $sourceCurrencyOption['text'] }}</option>
                            @endif
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="source_currency_doc_num"></div>
                </div>

                <div class="col-md-3">
                    <x-forms.label for="source_amount" :label="__('fund_transfers.attributes.source_amount')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="source_amount" :value="$formatAmount($value('source_amount', 0))" input-class="text-end" dir="ltr" />
                    @else
                        <input class="form-control text-end js-fund-transfer-source-amount" id="source_amount" name="source_amount" type="number" min="0.0001" step="0.0001" value="{{ $value('source_amount') }}" dir="ltr" required>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="source_amount"></div>
                </div>

                <div class="w-100 d-none d-md-block"></div>

                <div class="col-md-3">
                    <x-forms.label for="target_type" :label="__('fund_transfers.attributes.target_type')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="target_type" :value="__('fund_transfers.holder_types.'.$targetType)" />
                    @else
                        <select class="form-select js-fund-transfer-holder-type" id="target_type" name="target_type" data-side="target" required>
                            @foreach(\Modules\Finance\Models\FundTransfer::holderTypes() as $type)
                                <option value="{{ $type }}" @selected($targetType === $type)>{{ __('fund_transfers.holder_types.'.$type) }}</option>
                            @endforeach
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="target_type"></div>
                </div>

                <div @class(['col-md-3 js-fund-transfer-holder js-target-cashbox-holder', 'd-none' => $targetType !== 'cashbox']) data-side="target" data-holder-type="cashbox">
                    <x-forms.label for="target_cashbox_doc_num" :label="__('fund_transfers.attributes.target_cashbox')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="target_cashbox_doc_num" :value="$targetType === 'cashbox' ? ($targetOption['text'] ?? null) : null" />
                    @else
                        <select class="form-select js-select2-ajax js-fund-transfer-holder-select js-target-cashbox" id="target_cashbox_doc_num" name="target_cashbox_doc_num" data-url="{{ route('admin.finance.select2.cashboxes') }}" data-side="target" data-holder-type="cashbox" data-placeholder="{{ __('fund_transfers.js.select_cashbox') }}" data-allow-clear="true" @disabled($targetType !== 'cashbox')>
                            @if($targetType === 'cashbox' && $targetOption)
                                <option value="{{ $targetOption['id'] }}" selected>{{ $targetOption['text'] }}</option>
                            @endif
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="target_cashbox_doc_num"></div>
                </div>

                <div @class(['col-md-3 js-fund-transfer-holder js-target-bank-account-holder', 'd-none' => $targetType !== 'bank_account']) data-side="target" data-holder-type="bank_account">
                    <x-forms.label for="target_bank_account_doc_num" :label="__('fund_transfers.attributes.target_bank_account')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="target_bank_account_doc_num" :value="$targetType === 'bank_account' ? ($targetOption['text'] ?? null) : null" />
                    @else
                        <select class="form-select js-select2-ajax js-fund-transfer-holder-select js-target-bank-account" id="target_bank_account_doc_num" name="target_bank_account_doc_num" data-url="{{ route('admin.finance.select2.bank-accounts') }}" data-side="target" data-holder-type="bank_account" data-placeholder="{{ __('fund_transfers.js.select_bank_account') }}" data-allow-clear="true" @disabled($targetType !== 'bank_account')>
                            @if($targetType === 'bank_account' && $targetOption)
                                <option value="{{ $targetOption['id'] }}" selected>{{ $targetOption['text'] }}</option>
                            @endif
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="target_bank_account_doc_num"></div>
                </div>

                <div class="col-md-3">
                    <x-forms.label for="target_currency_doc_num" :label="__('fund_transfers.attributes.target_currency')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="target_currency_doc_num" :value="$targetCurrencyOption['text'] ?? null" />
                    @else
                        <select class="form-select js-select2-ajax js-fund-transfer-currency js-target-currency" id="target_currency_doc_num" name="target_currency_doc_num" data-url="{{ route('admin.finance.select2.holder-currencies') }}" data-extra-params='@json(['holder_type' => '#target_type', 'holder' => '#target_holder_doc_num'])' data-side="target" data-placeholder="{{ __('fund_transfers.js.select_currency') }}" data-allow-clear="true" required>
                            @if($targetCurrencyOption)
                                <option value="{{ $targetCurrencyOption['id'] }}" data-is-main="{{ $targetCurrencyOption['is_main'] ? '1' : '0' }}" selected>{{ $targetCurrencyOption['text'] }}</option>
                            @endif
                        </select>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="target_currency_doc_num"></div>
                </div>

                <div class="col-md-3">
                    <x-forms.label for="target_amount" :label="__('fund_transfers.attributes.target_amount')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="target_amount" :value="$formatAmount($value('target_amount', 0))" input-class="text-end" dir="ltr" />
                    @else
                        <input class="form-control text-end js-fund-transfer-target-amount" id="target_amount" name="target_amount" type="number" min="0.0001" step="0.0001" value="{{ $value('target_amount') }}" dir="ltr" required>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="target_amount"></div>
                </div>

                <div class="w-100 d-none d-md-block"></div>

                <div class="col-md-3">
                    <x-forms.label for="exchange_rate" :label="__('fund_transfers.attributes.exchange_rate')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="exchange_rate" :value="$formatAmount($value('exchange_rate', 1), 6)" input-class="text-center" dir="ltr" />
                    @else
                        <input class="form-control text-center js-fund-transfer-exchange-rate" id="exchange_rate" name="exchange_rate" type="number" min="0.000001" step="0.000001" value="{{ $value('exchange_rate', 1) }}" dir="ltr" required>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div>
                </div>

                <div class="col-md-9">
                    <x-forms.label for="reason" :label="__('fund_transfers.attributes.reason')" required />
                    @if($isReadonly)
                        <x-forms.view-field for="reason" :value="$value('reason')" />
                    @else
                        <input class="form-control" id="reason" name="reason" value="{{ $value('reason') }}" required>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="reason"></div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="description">{{ __('fund_transfers.attributes.notes') }}</label>
                    @if($isReadonly)
                        <div class="form-control-plaintext">{{ $value('description') }}</div>
                    @else
                        <textarea class="form-control" id="description" name="description" rows="2">{{ $value('description') }}</textarea>
                    @endif
                    <div class="invalid-feedback d-block" data-error-for="description"></div>
                    <div class="invalid-feedback d-block" data-error-for="document"></div>
                </div>

                @if($record?->isCancelled())
                    <div class="col-12">
                        <x-forms.view-field for="cancel_reason" :label="__('fund_transfers.attributes.cancel_reason')" :value="$record->cancel_reason" />
                    </div>
                @endif
            </div>
        </div>
        <div class="card-footer">
            @include('modules.finance.fund-transfers.partials.form-actions', compact('mode', 'record', 'resource', 'routePrefix'))
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
            </div>
        </div>
    @endif
</form>
@endsection

@push('scripts')
    <script>window.financeCrudMessages = @json(__('finance.js'));</script>
    <script>window.fundTransferMessages = @json(__('fund_transfers.js'));</script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/finance-foundation.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/fund-transfers.js') }}"></script>
@endpush
