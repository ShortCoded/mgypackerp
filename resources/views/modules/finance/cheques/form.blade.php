@extends('layouts.app')

@php
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isReadonly = $mode === 'view' || (! $isCreateLike && ($isLocked ?? false));
    $title = __('cheques.'.$mode);
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $financeAmounts = app(\Modules\Finance\Services\FinanceAmountService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $value = fn($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $dateValue = fn($field) => old($field, $record?->{$field} ? $dateFormatService->formatDate($record->{$field}, '') : '');
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $selectedType = old('cheque_type', $defaultType ?? \Modules\Finance\Models\Cheque::TypeReceived);
    $selectedBankAccountDocNum = old('bank_account_doc_num', $bankAccountOption['id'] ?? '');
    $selectedCurrencyDocNum = old('currency_doc_num', $currencyOption['id'] ?? '');
    $selectedPartyDocNum = old('party_doc_num', $partyOption['id'] ?? '');
    $selectedPartyType = $value('party_type');
    $showPartyNameField = $isReadonly || ! in_array($selectedPartyType, ['customer', 'supplier'], true) || ! $selectedPartyDocNum;
    $isMainCurrencySelected = ($mainCurrencyDocNum ?? null) && $selectedCurrencyDocNum === $mainCurrencyDocNum;
    $existingLines = old('lines');
    if (! is_array($existingLines)) {
        $existingLines = $record?->lines?->map(fn($line) => [
            'account_doc_num' => $line->account?->doc_num,
            'account_label' => $line->account?->codeNameLabel(),
            'amount' => $line->amount,
            'description' => $line->description,
            'notes' => $line->notes,
        ])->values()->all() ?? [];
    }
    if ($existingLines === []) {
        $existingLines = [['account_doc_num' => null, 'account_label' => null, 'amount' => null, 'description' => null, 'notes' => null]];
    }
    $chequeAmountUnits = $financeAmounts->toUnits($value('amount', 0));
    $distributedAmountUnits = collect($existingLines)->sum(fn (array $line) => $financeAmounts->toUnits($line['amount'] ?? 0));
    $distributedAmount = $financeAmounts->fromUnits($distributedAmountUnits);
    $remainingAmount = $financeAmounts->fromUnits($chequeAmountUnits - $distributedAmountUnits);
@endphp

@section('title', $title)

@section('content')
<form class="js-finance-form js-crud-form js-cheque-form"
    action="{{ $action }}"
    method="{{ $method }}"
    data-resource="{{ $resource }}"
    data-primary-focus="cheque_number"
    data-mode="{{ $mode }}"
    data-bank-account-url="{{ route('admin.finance.select2.bank-accounts') }}"
    data-currency-url="{{ route('admin.finance.select2.holder-currencies') }}"
    data-account-url="{{ route('admin.finance.select2.accounts') }}"
    data-customer-url="{{ route('admin.finance.select2.customers') }}"
    data-supplier-url="{{ route('admin.finance.select2.suppliers') }}"
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

    <div class="card mb-3">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col">
                    <h5 class="mb-0">{{ $title }}</h5>
                </div>
                <div class="col-auto">
                    @include('modules.finance.cheques.partials.form-actions', compact('mode', 'record', 'resource', 'routePrefix'))
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="alert d-none js-form-alert">
                <div class="js-form-alert-message"></div>
            </div>

            @if(! $isCreateLike && $record?->isLockedForEditing())
                <div class="alert alert-warning">{{ __('cheques.messages.document_locked') }}</div>
            @endif

            <ul class="nav nav-tabs" id="cheque-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="cheque-basic-tab" data-bs-toggle="tab" data-bs-target="#cheque-basic" type="button" role="tab" aria-controls="cheque-basic" aria-selected="true">
                        {{ __('cheques.sections.basic') }}
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="cheque-details-tab" data-bs-toggle="tab" data-bs-target="#cheque-details" type="button" role="tab" aria-controls="cheque-details" aria-selected="false">
                        {{ __('cheques.sections.details') }}
                    </button>
                </li>
            </ul>

            <div class="tab-content border-x border-bottom p-3" id="cheque-tabs-content">
                <div class="tab-pane fade show active" id="cheque-basic" role="tabpanel" aria-labelledby="cheque-basic-tab">
                    <div class="row g-3">
                        @if(! $isCreateLike)
                            <div class="col-md-3">
                                <x-forms.view-field for="document_status" :label="__('cheques.attributes.document_status')" :value="$record?->trashed() ? __('cheques.statuses.deleted') : __('cheques.statuses.'.($record?->status ?? 'received'))" />
                            </div>
                        @endif

                        @if($canControlDocumentNumber)
                            <div class="col-md-3">
                                <label class="form-label" for="doc_number">{{ __('cheques.attributes.doc_number') }}</label>
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
                                <x-forms.view-field for="doc_num" :label="__('cheques.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                            </div>
                        @endif

                        <div class="col-md-3">
                            <x-forms.label for="cheque_type" :label="__('cheques.attributes.cheque_type')" required />
                            @if($isReadonly || (! $isCreateLike && $record))
                                <x-forms.view-field for="cheque_type_display" :value="__('cheques.types.'.$selectedType)" />
                                @unless($isReadonly)
                                    <input type="hidden" name="cheque_type" value="{{ $selectedType }}">
                                @endunless
                            @else
                                <select class="form-select js-cheque-type" id="cheque_type" name="cheque_type" required>
                                    @foreach(\Modules\Finance\Models\Cheque::types() as $type)
                                        <option value="{{ $type }}" @selected($selectedType === $type)>{{ __('cheques.types.'.$type) }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="cheque_type"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="cheque_number" :label="__('cheques.attributes.cheque_number')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="cheque_number" :value="$value('cheque_number')" dir="ltr" />
                            @else
                                <input class="form-control" id="cheque_number" name="cheque_number" value="{{ $value('cheque_number') }}" dir="ltr" required>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="cheque_number"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="cheque_date">{{ __('cheques.attributes.cheque_date') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="cheque_date" :value="$dateValue('cheque_date')" dir="ltr" input-class="date-value" />
                            @else
                                <input class="form-control text-center js-date-picker" id="cheque_date" name="cheque_date" type="text" value="{{ $dateValue('cheque_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="cheque_date"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="due_date">{{ __('cheques.attributes.due_date') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="due_date" :value="$dateValue('due_date')" dir="ltr" input-class="date-value" />
                            @else
                                <input class="form-control text-center js-date-picker" id="due_date" name="due_date" type="text" value="{{ $dateValue('due_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="due_date"></div>
                        </div>

                        <div class="w-100 d-none d-md-block"></div>

                        <div class="col-md-3">
                            <label class="form-label" for="external_bank_name">{{ __('cheques.attributes.external_bank_name') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="external_bank_name" :value="$value('external_bank_name')" />
                            @else
                                <input class="form-control" id="external_bank_name" name="external_bank_name" value="{{ $value('external_bank_name') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="external_bank_name"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="external_bank_branch">{{ __('cheques.attributes.external_bank_branch') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="external_bank_branch" :value="$value('external_bank_branch')" />
                            @else
                                <input class="form-control" id="external_bank_branch" name="external_bank_branch" value="{{ $value('external_bank_branch') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="external_bank_branch"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="party_type">{{ __('cheques.attributes.party_type') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="party_type" :value="$value('party_type') ? __('cheques.party_types.'.$value('party_type')) : null" />
                            @else
                                <select class="form-select js-cheque-party-type" id="party_type" name="party_type">
                                    <option value=""></option>
                                    @foreach(['customer', 'supplier', 'other'] as $type)
                                        <option value="{{ $type }}" @selected($value('party_type') === $type)>{{ __('cheques.party_types.'.$type) }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="party_type"></div>
                        </div>

                        @if($isReadonly)
                            <div class="col-md-3">
                                <x-forms.view-field for="party_display" :label="__('cheques.attributes.party')" :value="$partyOption['text'] ?? $value('party_name')" />
                            </div>
                        @else
                            <div @class(['col-md-3 js-cheque-party-field js-cheque-party-customer', 'd-none' => $selectedPartyType !== 'customer']) data-party-field="customer">
                                <x-forms.label for="customer_party_doc_num" :label="__('cheques.attributes.customer')" required />
                                <select class="form-select js-select2-ajax js-cheque-party-select js-cheque-customer-party" id="customer_party_doc_num" name="party_doc_num" data-url="{{ route('admin.finance.select2.customers') }}" data-placeholder="{{ __('cheques.js.select_customer') }}" data-allow-clear="true" @disabled($selectedPartyType !== 'customer')>
                                    @if(($partyOption['type'] ?? null) === 'customer')
                                        <option value="{{ $partyOption['id'] }}" data-party-name="{{ $partyOption['name'] }}" selected>{{ $partyOption['text'] }}</option>
                                    @elseif($value('party_type') === 'customer' && $selectedPartyDocNum)
                                        <option value="{{ $selectedPartyDocNum }}" selected>{{ $selectedPartyDocNum }}</option>
                                    @endif
                                </select>
                                <div class="invalid-feedback d-block" data-error-for="party_doc_num"></div>
                            </div>

                            <div @class(['col-md-3 js-cheque-party-field js-cheque-party-supplier', 'd-none' => $selectedPartyType !== 'supplier']) data-party-field="supplier">
                                <x-forms.label for="supplier_party_doc_num" :label="__('cheques.attributes.supplier')" required />
                                <select class="form-select js-select2-ajax js-cheque-party-select js-cheque-supplier-party" id="supplier_party_doc_num" name="party_doc_num" data-url="{{ route('admin.finance.select2.suppliers') }}" data-placeholder="{{ __('cheques.js.select_supplier') }}" data-allow-clear="true" @disabled($selectedPartyType !== 'supplier')>
                                    @if(($partyOption['type'] ?? null) === 'supplier')
                                        <option value="{{ $partyOption['id'] }}" data-party-name="{{ $partyOption['name'] }}" selected>{{ $partyOption['text'] }}</option>
                                    @elseif($value('party_type') === 'supplier' && $selectedPartyDocNum)
                                        <option value="{{ $selectedPartyDocNum }}" selected>{{ $selectedPartyDocNum }}</option>
                                    @endif
                                </select>
                                <div class="invalid-feedback d-block" data-error-for="party_doc_num"></div>
                            </div>
                        @endif

                        <div @class(['col-md-3 js-cheque-party-field js-cheque-party-name', 'd-none' => ! $showPartyNameField]) data-party-field="name">
                            <x-forms.label for="party_name" :label="__('cheques.attributes.party_name')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="party_name" :value="$value('party_name')" />
                            @else
                                <input class="form-control" id="party_name" name="party_name" value="{{ $value('party_name') }}" required @disabled(! $showPartyNameField)>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="party_name"></div>
                        </div>

                        <div class="w-100 d-none d-md-block"></div>

                        <div class="col-md-3">
                            <label class="form-label" for="bank_account_doc_num">{{ __('cheques.attributes.bank_account') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="bank_account_doc_num" :value="$bankAccountOption['text'] ?? null" />
                            @else
                                <select class="form-select js-select2-ajax js-cheque-bank-account" id="bank_account_doc_num" name="bank_account_doc_num" data-url="{{ route('admin.finance.select2.bank-accounts') }}" data-placeholder="{{ __('cheques.js.select_bank_account') }}" data-allow-clear="true">
                                    @if($bankAccountOption)
                                        <option value="{{ $bankAccountOption['id'] }}" data-currency-doc-num="{{ $bankAccountOption['currency_doc_num'] }}" data-currency-text="{{ $bankAccountOption['currency_text'] }}" data-currency-is-main="{{ $bankAccountOption['currency_is_main'] ? '1' : '0' }}" selected>{{ $bankAccountOption['text'] }}</option>
                                    @elseif($selectedBankAccountDocNum)
                                        <option value="{{ $selectedBankAccountDocNum }}" selected>{{ $selectedBankAccountDocNum }}</option>
                                    @endif
                                </select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="bank_account_doc_num"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="currency_doc_num" :label="__('cheques.attributes.currency')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="currency_doc_num" :value="$currencyOption['text'] ?? null" />
                            @else
                                <select class="form-select js-select2-ajax js-cheque-currency" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.finance.select2.holder-currencies') }}" data-extra-params='@json(['holder_type' => 'bank_account', 'holder' => '#bank_account_doc_num'])' data-placeholder="{{ __('cheques.js.select_currency') }}" data-allow-clear="true" required>
                                    @if($currencyOption)
                                        <option value="{{ $currencyOption['id'] }}" data-is-main="{{ $currencyOption['is_main'] ? '1' : '0' }}" selected>{{ $currencyOption['text'] }}</option>
                                    @elseif($selectedCurrencyDocNum)
                                        <option value="{{ $selectedCurrencyDocNum }}" selected>{{ $selectedCurrencyDocNum }}</option>
                                    @endif
                                </select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="exchange_rate" :label="__('cheques.attributes.exchange_rate')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="exchange_rate" :value="$numbers->format($value('exchange_rate', 1))" input-class="text-center" dir="ltr" />
                            @else
                                <x-forms.numeric-input class="text-center js-cheque-exchange-rate" id="exchange_rate" name="exchange_rate" :value="$value('exchange_rate', 1)" :scale="6" min="0.000001" step="0.000001" required :readonly="$isMainCurrencySelected" />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="amount" :label="__('cheques.attributes.amount')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="amount" :value="$numbers->format($value('amount', 0))" input-class="text-end" dir="ltr" />
                            @else
                                <x-forms.numeric-input class="text-end js-cheque-amount" id="amount" name="amount" :value="$value('amount')" :scale="4" min="0.0001" step="0.0001" required />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="amount"></div>
                        </div>

                        <div class="col-12">
                            <x-forms.label for="reason" :label="__('cheques.attributes.reason')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="reason" :value="$value('reason')" />
                            @else
                                <input class="form-control" id="reason" name="reason" value="{{ $value('reason') }}" required>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="reason"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="description">{{ __('cheques.attributes.notes') }}</label>
                            @if($isReadonly)
                                <div class="form-control-plaintext">{{ $value('description') }}</div>
                            @else
                                <textarea class="form-control" id="description" name="description" rows="2">{{ $value('description') }}</textarea>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="description"></div>
                        </div>

                        @if($record?->status === \Modules\Finance\Models\Cheque::StatusCancelled)
                            <div class="col-12">
                                <x-forms.view-field for="cancel_reason" :label="__('cheques.attributes.cancel_reason')" :value="$record->cancel_reason" />
                            </div>
                        @endif
                    </div>
                </div>

                <div class="tab-pane fade" id="cheque-details" role="tabpanel" aria-labelledby="cheque-details-tab">
                    <div class="row flex-between-center g-2 mb-3">
                        <div class="col">
                            <h6 class="mb-0">{{ __('cheques.sections.details') }}</h6>
                        </div>
                        @unless($isReadonly)
                            <div class="col-auto">
                                <button class="btn btn-falcon-default btn-sm js-cheque-add-line" type="button" title="{{ __('cheques.js.add_line_title') }}" data-bs-title="{{ __('cheques.js.add_line_title') }}">
                                    <span class="fas fa-plus me-1"></span>{{ __('cheques.actions.add_line') }}
                                </button>
                            </div>
                        @endunless
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 js-cheque-lines">
                            <thead class="bg-200">
                                <tr>
                                    <th style="width: 38%">{{ __('cheques.attributes.account') }}</th>
                                    <th style="width: 16%">{{ __('cheques.attributes.line_amount') }}</th>
                                    <th>{{ __('cheques.attributes.line_description') }}</th>
                                    <th>{{ __('cheques.attributes.line_notes') }}</th>
                                    @unless($isReadonly)
                                        <th class="text-center" style="width: 76px">{{ __('common.fields.actions') }}</th>
                                    @endunless
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($existingLines as $index => $line)
                                    <tr class="js-cheque-line" data-index="{{ $index }}">
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $line['account_label'] ?? null }}</div>
                                            @else
                                                <select class="form-select js-select2-ajax js-cheque-account" name="lines[{{ $index }}][account_doc_num]" data-url="{{ route('admin.finance.select2.accounts') }}" data-placeholder="{{ __('cheques.js.select_account') }}" data-allow-clear="true">
                                                    @if(! empty($line['account_doc_num']))
                                                        <option value="{{ $line['account_doc_num'] }}" selected>{{ $line['account_label'] ?? $line['account_doc_num'] }}</option>
                                                    @endif
                                                </select>
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.account_doc_num"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($line['amount'] ?? 0) }}</div>
                                            @else
                                                <x-forms.numeric-input class="text-end js-cheque-line-amount" :name="'lines['.$index.'][amount]'" :value="$line['amount'] ?? ''" :scale="4" min="0.0001" step="0.0001" />
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
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $line['notes'] ?? null }}</div>
                                            @else
                                                <input class="form-control" name="lines[{{ $index }}][notes]" value="{{ $line['notes'] ?? '' }}">
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.notes"></div>
                                            @endif
                                        </td>
                                        @unless($isReadonly)
                                            <td class="text-center">
                                                <button class="btn btn-link text-600 p-0 me-2 js-cheque-duplicate-line" type="button" title="{{ __('cheques.js.duplicate_line_title') }}" data-bs-title="{{ __('cheques.js.duplicate_line_title') }}">
                                                    <span class="fas fa-copy"></span>
                                                </button>
                                                <button class="btn btn-link text-danger p-0 js-cheque-remove-line" type="button" title="{{ __('cheques.js.delete_line_title') }}" data-bs-title="{{ __('cheques.js.delete_line_title') }}">
                                                    <span class="fas fa-trash-alt"></span>
                                                </button>
                                            </td>
                                        @endunless
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <th class="text-end">{{ __('cheques.attributes.total_distributed') }}</th>
                                    <th class="text-end js-cheque-total-distributed" dir="ltr">{{ $numbers->format($distributedAmount) }}</th>
                                    <th class="text-end">{{ __('cheques.attributes.remaining_amount') }}</th>
                                    <th class="text-end js-cheque-remaining" dir="ltr">{{ $numbers->format($remainingAmount) }}</th>
                                    @unless($isReadonly)
                                        <th></th>
                                    @endunless
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="pt-2">
                        <div class="invalid-feedback d-block" data-error-for="lines"></div>
                        <div class="invalid-feedback d-block" data-error-for="document"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            @include('modules.finance.cheques.partials.form-actions', compact('mode', 'record', 'resource', 'routePrefix'))
        </div>
    </div>

    @if($mode === 'view' && $record?->clearingEvents?->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">{{ __('Clearing and reversal lineage') }}</h6></div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead><tr><th>#</th><th>{{ __('Clearing date') }}</th><th>{{ __('Status') }}</th><th>{{ __('Clearing Journal') }}</th><th>{{ __('Reversal Journal') }}</th><th>{{ __('Reason') }}</th></tr></thead>
                    <tbody>
                        @foreach($record->clearingEvents as $event)
                            <tr><td>{{ $event->sequence }}</td><td>{{ $dateFormatService->formatDate($event->clearing_date, '—') }}</td><td>{{ str($event->status)->replace('_', ' ')->title() }}</td><td>{{ $event->clearingJournalEntry?->doc_num ?? '—' }}</td><td>{{ $event->reversalJournalEntry?->doc_num ?? '—' }}</td><td>{{ $event->reversal_reason }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

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
    <script>window.chequeMessages = @json(__('cheques.js'));</script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/finance-foundation.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/cheques.js') }}"></script>
@endpush
