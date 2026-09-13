@extends('layouts.app')

@php
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isReadonly = $mode === 'view' || (! $isCreateLike && ($isLocked ?? false));
    $title = __($translationKey.'.'.$mode);
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $financeAmounts = app(\Modules\Finance\Services\FinanceAmountService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dateValue = fn () => old('voucher_date', $record?->voucher_date ? $dateFormatService->formatDate($record->voucher_date, '') : $dateFormatService->formatDate(now(), ''));
    $value = fn($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $selectedCashboxDocNum = old('cashbox_doc_num', $cashboxOption['id'] ?? '');
    $selectedCashboxAccountDocNum = $cashboxOption['account_doc_num'] ?? '';
    $selectedCurrencyDocNum = old('currency_doc_num', $currencyOption['id'] ?? '');
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
    $voucherAmountUnits = $financeAmounts->toUnits($value('amount', 0));
    $distributedAmountUnits = collect($existingLines)->sum(fn (array $line) => $financeAmounts->toUnits($line['amount'] ?? 0));
    $distributedAmount = $financeAmounts->fromUnits($distributedAmountUnits);
    $remainingAmount = $financeAmounts->fromUnits($voucherAmountUnits - $distributedAmountUnits);
@endphp

@section('title', $title)

@section('content')
@if($record?->exists) @include('modules.purchases.procurement.document-cycle', ['record' => $record]) @endif
<form class="js-finance-form js-crud-form js-cash-voucher-form"
    action="{{ $action }}"
    method="{{ $method }}"
    data-resource="{{ $resource }}"
    data-primary-focus="cashbox_doc_num"
    data-mode="{{ $mode }}"
    data-cashbox-url="{{ route('admin.finance.select2.cash-voucher-cashboxes') }}"
    data-currency-url="{{ route('admin.finance.select2.cash-voucher-currencies') }}"
    data-account-url="{{ route('admin.finance.select2.accounts') }}"
    data-main-currency-doc-num="{{ $mainCurrencyDocNum ?? '' }}"
    data-cashbox-account-doc-num="{{ $selectedCashboxAccountDocNum }}"
    novalidate>
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif
    <x-forms.input type="hidden" name="submit_action" value="save" />
    @if($cloneSourceToken)
        <x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />
    @endif
    <x-forms.input type="hidden" id="cashbox_account_doc_num_filter" class="js-cash-voucher-cashbox-account-doc-num" value="{{ $selectedCashboxAccountDocNum }}" />

    <div class="card mb-3">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col">
                    <h5 class="mb-0">{{ $title }}</h5>
                </div>
                <div class="col-auto">
                    @include('modules.finance.cash-vouchers.partials.form-actions', compact('mode', 'record', 'resource', 'routePrefix', 'translationKey'))
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="alert d-none js-form-alert">
                <div class="js-form-alert-message"></div>
            </div>

            @if(! $isCreateLike && $record?->isApproved())
                <div class="alert alert-warning">{{ __($translationKey.'.messages.approved_edit_forbidden') }}</div>
            @elseif(! $isCreateLike && $record?->isCancelled())
                <div class="alert alert-warning">{{ __($translationKey.'.messages.cancelled_edit_forbidden') }}</div>
            @endif

            <ul class="nav nav-tabs" id="cash-voucher-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="cash-voucher-basic-tab" data-bs-toggle="tab" data-bs-target="#cash-voucher-basic" type="button" role="tab" aria-controls="cash-voucher-basic" aria-selected="true">
                        {{ __($translationKey.'.sections.basic') }}
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="cash-voucher-details-tab" data-bs-toggle="tab" data-bs-target="#cash-voucher-details" type="button" role="tab" aria-controls="cash-voucher-details" aria-selected="false">
                        {{ __($translationKey.'.sections.details') }}
                    </button>
                </li>
            </ul>

            <div class="tab-content border-x border-bottom p-3" id="cash-voucher-tabs-content">
                <div class="tab-pane fade show active" id="cash-voucher-basic" role="tabpanel" aria-labelledby="cash-voucher-basic-tab">
                    <div class="row g-3">
                        <div class="col-md-4">
                            @if($canControlDocumentNumber)
                                <label class="form-label" for="doc_number">{{ __($translationKey.'.attributes.doc_number') }}</label>
                                @if($isReadonly)
                                    <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                                @else
                                    <x-forms.input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}" />
                                    <div class="form-text">{{ __('item_lookups.document_number_control.helper') }}</div>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                            @elseif(! $isCreateLike)
                                <x-forms.view-field for="doc_num" :label="__($translationKey.'.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                            @else
                                <x-forms.view-field for="doc_num_preview" as="display" :label="__($translationKey.'.attributes.doc_number')" :value="__('item_lookups.document_number_control.placeholder')" input-class="text-center text-muted" />
                            @endif
                        </div>

                        <div class="col-md-4">
                            <x-forms.label for="voucher_date" :label="__($translationKey.'.attributes.voucher_date')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="voucher_date" :value="$dateValue()" dir="ltr" input-class="date-value text-center" />
                            @else
                                <x-forms.date-input class="form-control text-center js-date-picker" id="voucher_date" name="voucher_date" type="text" value="{{ $dateValue() }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="voucher_date"></div>
                        </div>

                        <div class="col-md-4">
                            <x-forms.view-field for="document_status" :label="__($translationKey.'.attributes.document_status')" :value="$record?->trashed() ? __($translationKey.'.statuses.deleted') : __($translationKey.'.statuses.'.($record?->status ?? 'draft'))" />
                        </div>

                        <div class="col-md-6">
                            <x-forms.label for="cashbox_doc_num" :label="__($translationKey.'.attributes.cashbox')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="cashbox_doc_num" :value="$cashboxOption['text'] ?? null" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax js-cash-voucher-cashbox" id="cashbox_doc_num" name="cashbox_doc_num" data-url="{{ route('admin.finance.select2.cash-voucher-cashboxes') }}" data-placeholder="{{ __($translationKey.'.js.select_cashbox') }}" data-allow-clear="true" required>
                                    @if($cashboxOption)
                                        <option value="{{ $cashboxOption['id'] }}" data-account-doc-num="{{ $cashboxOption['account_doc_num'] }}" data-account-label="{{ $cashboxOption['account_label'] }}" selected>{{ $cashboxOption['text'] }}</option>
                                    @elseif($selectedCashboxDocNum)
                                        <option value="{{ $selectedCashboxDocNum }}" selected>{{ $selectedCashboxDocNum }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="cashbox_doc_num"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="currency_doc_num" :label="__($translationKey.'.attributes.currency')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="currency_doc_num" :value="$currencyOption['text'] ?? null" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax js-cash-voucher-currency" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.finance.select2.cash-voucher-currencies') }}" data-placeholder="{{ __($translationKey.'.js.select_currency') }}" data-allow-clear="true" data-depends-on="#cashbox_doc_num" data-dependent-param="cashbox" data-disable-when-dependency-empty="true" required :disabled="$selectedCashboxDocNum === ''">
                                    @if($currencyOption)
                                        <option value="{{ $currencyOption['id'] }}" data-is-main="{{ $currencyOption['is_main'] ? '1' : '0' }}" selected>{{ $currencyOption['text'] }}</option>
                                    @elseif($selectedCurrencyDocNum)
                                        <option value="{{ $selectedCurrencyDocNum }}" selected>{{ $selectedCurrencyDocNum }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="exchange_rate" :label="__($translationKey.'.attributes.exchange_rate')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="exchange_rate" :value="$numbers->format($value('exchange_rate', 1))" input-class="text-center" dir="ltr" />
                            @else
                                <x-forms.numeric-input class="text-center" id="exchange_rate" name="exchange_rate" :value="$value('exchange_rate', 1)" :scale="6" min="0.000001" step="0.000001" required :readonly="$isMainCurrencySelected" />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="amount" :label="__($translationKey.'.attributes.amount')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="amount" :value="$numbers->format($value('amount', 0))" input-class="text-end" dir="ltr" />
                            @else
                                <x-forms.numeric-input class="text-end js-cash-voucher-amount" id="amount" name="amount" :value="$value('amount')" :scale="4" min="0.0001" step="0.0001" required />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="amount"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="person_name" :label="__($translationKey.'.attributes.person_name')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="person_name" :value="$value('person_name')" />
                            @else
                                <x-forms.input class="form-control" id="person_name" name="person_name" value="{{ $value('person_name') }}" autocomplete="name" required />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="person_name"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="person_national_id">{{ __($translationKey.'.attributes.person_national_id') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="person_national_id" :value="$value('person_national_id')" dir="ltr" input-class="text-center" />
                            @else
                                <x-forms.input class="form-control text-center" id="person_national_id" name="person_national_id" value="{{ $value('person_national_id') }}" maxlength="50" dir="ltr" autocomplete="off" />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="person_national_id"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="person_phone">{{ __($translationKey.'.attributes.person_phone') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="person_phone" :value="$value('person_phone')" dir="ltr" input-class="text-center" />
                            @else
                                <x-forms.input class="form-control text-center" id="person_phone" name="person_phone" value="{{ $value('person_phone') }}" maxlength="50" dir="ltr" autocomplete="tel" />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="person_phone"></div>
                        </div>

                        <div class="col-12">
                            <x-forms.label for="reason" :label="__($translationKey.'.attributes.reason')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="reason" :value="$value('reason')" />
                            @else
                                <x-forms.input class="form-control" id="reason" name="reason" value="{{ $value('reason') }}" required />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="reason"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="description">{{ __($translationKey.'.attributes.description') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="description" as="textarea" :value="$value('description')" rows="3" />
                            @else
                                <x-forms.textarea class="form-control" id="description" name="description" rows="3">{{ $value('description') }}</x-forms.textarea>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="description"></div>
                        </div>

                        @if($record?->isCancelled())
                            <div class="col-12">
                                <x-forms.view-field for="cancel_reason" :label="__($translationKey.'.attributes.cancel_reason')" :value="$record->cancel_reason" />
                            </div>
                        @endif
                    </div>
                </div>

                <div class="tab-pane fade" id="cash-voucher-details" role="tabpanel" aria-labelledby="cash-voucher-details-tab">
                    <div class="row flex-between-center g-2 mb-3">
                        <div class="col">
                            <h6 class="mb-0">{{ __($translationKey.'.sections.details') }}</h6>
                        </div>
                        @unless($isReadonly)
                            <div class="col-auto">
                                <button class="btn btn-falcon-default btn-sm js-cash-voucher-add-line" type="button" title="{{ __($translationKey.'.js.add_line_title') }}" data-bs-title="{{ __($translationKey.'.js.add_line_title') }}">
                                    <span class="fas fa-plus me-1"></span>{{ __($translationKey.'.actions.add_line') }}
                                </button>
                            </div>
                        @endunless
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 js-cash-voucher-lines">
                            <thead class="bg-200">
                                <tr>
                                    <th style="width: 38%">{{ __($translationKey.'.attributes.account') }}</th>
                                    <th style="width: 16%">{{ __($translationKey.'.attributes.line_amount') }}</th>
                                    <th>{{ __($translationKey.'.attributes.line_description') }}</th>
                                    <th>{{ __($translationKey.'.attributes.line_notes') }}</th>
                                    @unless($isReadonly)
                                        <th class="text-center" style="width: 76px">{{ __('common.fields.actions') }}</th>
                                    @endunless
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($existingLines as $index => $line)
                                    <tr class="js-cash-voucher-line" data-index="{{ $index }}">
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $line['account_label'] ?? null }}</div>
                                            @else
                                                <x-forms.select class="form-select js-select2-ajax js-cash-voucher-account" name="lines[{{ $index }}][account_doc_num]" data-url="{{ route('admin.finance.select2.accounts') }}" data-placeholder="{{ __($translationKey.'.js.select_account') }}" data-allow-clear="true" :data-extra-params="json_encode(['exclude' => '#cashbox_account_doc_num_filter'])" required>
                                                    @if(! empty($line['account_doc_num']))
                                                        <option value="{{ $line['account_doc_num'] }}" selected>{{ $line['account_label'] ?? $line['account_doc_num'] }}</option>
                                                    @endif
                                                </x-forms.select>
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.account_doc_num"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($line['amount'] ?? 0) }}</div>
                                            @else
                                                <x-forms.numeric-input class="text-end js-cash-voucher-line-amount" :name="'lines['.$index.'][amount]'" :value="$line['amount'] ?? ''" :scale="4" min="0.0001" step="0.0001" required />
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.amount"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $line['description'] ?? null }}</div>
                                            @else
                                                <x-forms.input class="form-control" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" />
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.description"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $line['notes'] ?? null }}</div>
                                            @else
                                                <x-forms.input class="form-control" name="lines[{{ $index }}][notes]" value="{{ $line['notes'] ?? '' }}" />
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.notes"></div>
                                            @endif
                                        </td>
                                        @unless($isReadonly)
                                            <td class="text-center">
                                                <button class="btn btn-link text-600 p-0 me-2 js-cash-voucher-duplicate-line" type="button" title="{{ __($translationKey.'.js.duplicate_line_title') }}" data-bs-title="{{ __($translationKey.'.js.duplicate_line_title') }}">
                                                    <span class="fas fa-copy"></span>
                                                </button>
                                                <button class="btn btn-link text-danger p-0 js-cash-voucher-remove-line" type="button" title="{{ __($translationKey.'.js.delete_line_title') }}" data-bs-title="{{ __($translationKey.'.js.delete_line_title') }}">
                                                    <span class="fas fa-trash-alt"></span>
                                                </button>
                                            </td>
                                        @endunless
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <th class="text-end">{{ __($translationKey.'.attributes.total_distributed') }}</th>
                                    <th class="text-end js-cash-voucher-total-distributed" dir="ltr">{{ $numbers->format($distributedAmount) }}</th>
                                    <th class="text-end">{{ __($translationKey.'.attributes.remaining_amount') }}</th>
                                    <th class="text-end js-cash-voucher-remaining" dir="ltr">{{ $numbers->format($remainingAmount) }}</th>
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
            @include('modules.finance.cash-vouchers.partials.form-actions', compact('mode', 'record', 'resource', 'routePrefix', 'translationKey'))
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
                @if($record?->approved_at || $record?->approved_by || $record?->cancelled_at || $record?->cancelled_by)
                    <div class="row g-3 mt-0">
                        <x-forms.view-field :label="__($translationKey.'.attributes.approved_by')" :value="$metadata['approved_by'] ?? null" class="col-md-6 col-xl-3" />
                        <x-forms.view-field :label="__($translationKey.'.attributes.approved_at')" :value="$metadata['approved_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                        <x-forms.view-field :label="__($translationKey.'.attributes.cancelled_by')" :value="$metadata['cancelled_by'] ?? null" class="col-md-6 col-xl-3" />
                        <x-forms.view-field :label="__($translationKey.'.attributes.cancelled_at')" :value="$metadata['cancelled_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                    </div>
                @endif
            </div>
        </div>
    @endif
</form>
@endsection

@push('scripts')
    <script>window.financeCrudMessages = @json(__('finance.js'));</script>
    <script>window.cashVoucherMessages = @json(__($translationKey.'.js'));</script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/finance-foundation.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/cash-vouchers.js') }}"></script>
@endpush
