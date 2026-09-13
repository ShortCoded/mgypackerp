@extends('layouts.app')

@php
    use Illuminate\Support\Str;
    use Modules\Core\Services\DateFormatService;
    use Modules\Core\Services\NumericFormatService;
    use Modules\Core\Services\ProductComponentUnitOptionsService;
    use Modules\Purchases\Models\PurchaseInvoice;

    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isView = $mode === 'view';
    $isTrashed = $record?->trashed() ?? false;
    $isReadonly = $isView || $isTrashed || (! $isCreateLike && ($record?->isLockedForEditing() ?? false));
    $title = match ($mode) {
        'edit' => __('purchase_invoices.edit'),
        'view' => __('purchase_invoices.view'),
        'clone' => __('purchase_invoices.clone'),
        default => __('purchase_invoices.create'),
    };
    $dates = app(DateFormatService::class);
    $numbers = app(NumericFormatService::class);
    $unitOptions = app(ProductComponentUnitOptionsService::class);
    $dateValue = fn ($field, $default = null) => old($field, $default ? $dates->formatDate($default, '') : '');
    $plainDate = fn ($date) => $date ? $dates->formatDate($date, '') : '';
    $value = fn ($field, $default = '') => old($field, $isCreateLike && $mode !== 'clone' ? $default : ($record?->{$field} ?? $default));
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');

    $supplierOption = $record?->supplier ? [
        'id' => $record->supplier->doc_num,
        'text' => trim(implode(' / ', array_filter([$record->supplier->doc_num, $record->supplier->name, $record->supplier->phone ?: $record->supplier->mobile]))),
    ] : null;
    $periodOption = $record?->financialPeriod ? [
        'id' => $record->financialPeriod->doc_num,
        'text' => trim(implode(' / ', array_filter([$record->financialPeriod->doc_num, $record->financialPeriod->name]))),
    ] : null;
    $currencyOption = $record?->currency ? [
        'id' => $record->currency->doc_num,
        'text' => trim(implode(' / ', array_filter([$record->currency->code, $record->currency->name]))),
        'is_main' => (bool) $record->currency->is_main,
    ] : null;
    $cashboxOption = $record?->cashbox ? [
        'id' => $record->cashbox->doc_num,
        'text' => trim(implode(' / ', array_filter([$record->cashbox->doc_num, $record->cashbox->name]))),
    ] : null;
    $bankOption = $record?->bankAccount ? [
        'id' => $record->bankAccount->doc_num,
        'text' => trim(implode(' / ', array_filter([$record->bankAccount->doc_num, $record->bankAccount->bank_name, $record->bankAccount->account_name]))),
    ] : null;

    $selectedSupplier = old('supplier_doc_num', $supplierOption['id'] ?? '');
    $selectedPeriod = old('financial_period_doc_num', $periodOption['id'] ?? '');
    $selectedCurrency = old('currency_doc_num', $currencyOption['id'] ?? '');
    $selectedCashbox = old('cashbox_doc_num', $cashboxOption['id'] ?? '');
    $selectedBank = old('bank_account_doc_num', $bankOption['id'] ?? '');
    $selectedPurchaseOrder = old('purchase_order_doc_num', $record?->purchaseOrder?->doc_num ?? '');
    $selectedPaymentType = old('payment_type', $record?->payment_type ?? PurchaseInvoice::PaymentTypeCredit);
    $selectedPaymentSource = old('payment_source_type', $record?->payment_source_type ?? PurchaseInvoice::SourceCashbox);
    $selectedHeaderDiscountType = old('header_discount_type', $record?->header_discount_type ?? 'fixed');

    $lineRows = old('lines');
    if (! is_array($lineRows)) {
        $lineRows = $record?->lines?->map(function ($line) use ($isCreateLike, $mode, $unitOptions, $numbers): array {
            $product = $line->product;
            $unit = $line->unit;
            $productSnapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
            $unitLabel = trim(implode(' / ', array_filter([$unit?->doc_num ?? $productSnapshot['unit_doc_num'] ?? null, $unit?->name ?? $productSnapshot['unit_name'] ?? null])));
            $productLabel = trim(implode(' / ', array_filter([
                $product?->doc_num ?? $productSnapshot['doc_num'] ?? null,
                $product?->name ?? $productSnapshot['name'] ?? null,
                $product?->barcode ?? $productSnapshot['barcode'] ?? null,
                $unitLabel,
            ])));

            return [
                'public_id' => $isCreateLike || $mode === 'clone' ? null : $line->public_id,
                'product_doc_num' => $product?->doc_num ?? $productSnapshot['doc_num'] ?? null,
                'product_label' => $productLabel,
                'unit_doc_num' => $unit?->doc_num ?? $productSnapshot['unit_doc_num'] ?? null,
                'unit_label' => $unitLabel,
                'unit_options' => $product ? $unitOptions->options($product) : [],
                'cost_center_doc_num' => $line->costCenter?->doc_num,
                'cost_center_label' => $line->costCenter?->codeNameLabel(),
                'purchase_order_line_public_id' => $line->purchaseOrderLine?->public_id,
                'receipt_line_public_id' => $line->receiptLine?->public_id,
                'quantity' => $numbers->format($line->quantity),
                'unit_price' => $numbers->format($line->unit_price),
                'discount_type' => $line->discount_type ?: 'fixed',
                'discount_value' => $numbers->format($line->discount_value),
                'tax_rate' => $numbers->format($line->tax_rate),
                'subtotal_amount' => $numbers->format($line->subtotal_amount),
                'discount_amount' => $numbers->format($line->discount_amount),
                'tax_amount' => $numbers->format($line->tax_amount),
                'total_after_tax' => $numbers->format($line->total_after_tax),
                'notes' => $line->notes,
            ];
        })->values()->all() ?? [];
    }
    if ($lineRows === [] && ! $isReadonly) {
        $lineRows = [[
            'public_id' => null,
            'product_doc_num' => null,
            'product_label' => null,
            'unit_doc_num' => null,
            'unit_label' => null,
            'unit_options' => [],
            'cost_center_doc_num' => null,
            'cost_center_label' => null,
            'purchase_order_line_public_id' => null,
            'receipt_line_public_id' => null,
            'quantity' => null,
            'unit_price' => null,
            'discount_type' => 'fixed',
            'discount_value' => '0',
            'tax_rate' => '0',
            'subtotal_amount' => '0',
            'discount_amount' => '0',
            'tax_amount' => '0',
            'total_after_tax' => '0',
            'notes' => null,
        ]];
    }

    $scheduleRows = old('payment_schedules');
    if (! is_array($scheduleRows)) {
        $scheduleRows = $record?->paymentSchedules?->map(function ($schedule) use ($isCreateLike, $mode, $dates, $numbers): array {
            $cashboxLabel = $schedule->cashbox ? trim(implode(' / ', array_filter([$schedule->cashbox->doc_num, $schedule->cashbox->name]))) : null;
            $bankLabel = $schedule->bankAccount ? trim(implode(' / ', array_filter([$schedule->bankAccount->doc_num, $schedule->bankAccount->bank_name, $schedule->bankAccount->account_name]))) : null;

            return [
                'public_id' => $isCreateLike || $mode === 'clone' ? null : $schedule->public_id,
                'due_date' => $schedule->due_date ? $dates->formatDate($schedule->due_date, '') : null,
                'amount' => $numbers->format($schedule->amount),
                'paid_amount' => $numbers->format($schedule->paid_amount),
                'credited_amount' => $numbers->format($schedule->credited_amount),
                'outstanding_amount' => $numbers->format($schedule->outstanding_amount),
                'payment_source_type' => in_array($schedule->payment_source_type, PurchaseInvoice::scheduleSourceTypes(), true)
                    ? $schedule->payment_source_type
                    : null,
                'cashbox_doc_num' => $schedule->cashbox?->doc_num,
                'cashbox_label' => $cashboxLabel,
                'bank_account_doc_num' => $schedule->bankAccount?->doc_num,
                'bank_account_label' => $bankLabel,
                'notes' => $schedule->notes,
            ];
        })->values()->all() ?? [];
    }
    if ($scheduleRows === [] && ! $isReadonly) {
        $scheduleRows = [[
            'public_id' => null,
            'due_date' => '',
            'amount' => '',
            'payment_source_type' => PurchaseInvoice::SourceCashbox,
            'cashbox_doc_num' => null,
            'cashbox_label' => null,
            'bank_account_doc_num' => null,
            'bank_account_label' => null,
            'notes' => null,
        ]];
    }
    $readonlyScheduleTotal = $isReadonly ? (float) ($record?->paymentSchedules?->sum('amount') ?? 0) : 0;
    $readonlyScheduleDifference = $isReadonly ? (float) ($record?->total_amount ?? 0) - $readonlyScheduleTotal : 0;
    if (abs($readonlyScheduleDifference) < 0.0001) {
        $readonlyScheduleDifference = 0;
    }
@endphp

@section('title', $title)

@push('styles')
    <style>
        .purchase-invoice-lines th,
        .purchase-invoice-lines td {
            min-width: 8rem;
            vertical-align: top;
        }

        .purchase-invoice-schedules th,
        .purchase-invoice-schedules td {
            vertical-align: middle;
        }

        .purchase-invoice-schedules .purchase-invoice-actions-cell {
            width: 80px;
            min-width: 80px;
        }

        .purchase-invoice-lines .purchase-invoice-product-cell {
            min-width: 18rem;
        }

        .purchase-invoice-lines .purchase-invoice-notes-cell,
        .purchase-invoice-schedules .purchase-invoice-notes-cell {
            min-width: 14rem;
        }

        .purchase-invoice-line-actions {
            display: inline-flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: .375rem;
        }

        .purchase-invoice-line-actions .btn {
            margin: 0 !important;
            min-height: 2.25rem;
            min-width: 2.25rem;
            position: relative;
            z-index: 1;
        }

        .purchase-invoice-total-box {
            max-width: 30rem;
        }
    </style>
@endpush

@section('content')
<form class="js-purchase-invoice-form"
    action="{{ $action }}"
    method="POST"
    data-mode="{{ $mode }}"
    data-readonly="{{ $isReadonly ? '1' : '0' }}"
    data-product-url="{{ route('admin.purchases.select2.products') }}"
    data-cashbox-url="{{ route('admin.purchases.select2.cashboxes') }}"
    data-bank-url="{{ route('admin.purchases.select2.bank-accounts') }}"
    data-current-purchase-order="{{ $selectedPurchaseOrder }}"
    data-primary-focus="invoice_date"
    novalidate>
    @csrf
        <x-forms.line-item-cards />
    @if($method !== 'POST')
        @method($method)
    @endif
    <x-forms.input type="hidden" name="submit_action" value="save" />
    @if($cloneSourceToken)
        <x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />
    @endif

    <div class="card mb-3">
        <div class="card-header py-2">
            <div class="row flex-between-center g-2">
                <div class="col">
                    <h5 class="mb-0">{{ $title }}</h5>
                </div>
                <div class="col-auto">
                    @include('modules.purchases.purchase-invoices.partials.form-actions', compact('mode', 'record', 'canManageInCurrentBranch'))
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="alert d-none js-form-alert">
                <div class="js-form-alert-message"></div>
            </div>

            @if(! $isCreateLike && $record?->isLockedForEditing() && ! $isView)
                <div class="alert alert-warning">{{ __('purchase_invoices.messages.document_locked') }}</div>
            @endif
            @if($record?->isCancelled())
                <div class="alert alert-danger">{{ __('purchase_invoices.messages.cancelled_edit_forbidden') }}</div>
            @endif

            @if($mode === 'view')
                @can('purchase_invoices.print')<a class="btn btn-falcon-default btn-sm mb-2" target="_blank" href="{{ route('admin.purchases.purchase-invoices.print', [$record->doc_num, 'copy' => 'legal']) }}">{{ __('Legal copy') }}</a>@endcan
                @include('modules.purchases.procurement.document-cycle', ['record' => $record])
            @php $matchingNotes = json_decode($record->matching_notes ?? '', true) ?: []; @endphp
            @if(!empty($matchingNotes['line_variances']))
            <div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('purchase_invoices.sections.matching') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('purchase_invoices.attributes.match_state') }}</th><th>{{ __('purchase_invoices.attributes.ordered_quantity') }}</th><th>{{ __('purchase_invoices.attributes.received_quantity') }}</th><th>{{ __('purchase_invoices.attributes.invoice_quantity') }}</th><th>{{ __('purchase_invoices.attributes.quantity_variance') }}</th><th>{{ __('purchase_invoices.attributes.unit_price_variance') }}</th><th>{{ __('purchase_invoices.attributes.tax_rate_variance') }}</th></tr></thead><tbody>
            @foreach($matchingNotes['line_variances'] as $variance)
                @php $matchedLine = $record->lines->firstWhere('public_id', $variance['line']); @endphp
                <tr><td>{{ $matchedLine?->product?->name }}</td><td>{{ ($variance['match_type'] ?? 'linked') === 'unlinked' ? __('purchase_invoices.messages.unlinked_order_line') : __('purchase_invoices.messages.linked_order_line') }}</td><td>{{ $numbers->format($variance['ordered_quantity'] ?? 0) }}</td><td>{{ $numbers->format($variance['received_quantity'] ?? 0) }}</td><td>{{ $numbers->format($matchedLine?->quantity) }}</td><td>{{ $numbers->format($variance['quantity_variance']) }}</td><td>{{ $numbers->format($variance['unit_price_variance']) }}</td><td>{{ $numbers->format($variance['tax_rate_variance'] ?? 0) }}</td></tr>
            @endforeach
            </tbody></table></div></div>
            @endif

            @endif

            <ul class="nav nav-tabs" id="purchase-invoice-form-tabs" role="tablist">
                @php $invoiceTabs = $record?->exists ? ['basic', 'lines', 'assets', 'payments', 'audit'] : ['basic', 'lines', 'payments', 'audit']; @endphp
                @foreach($invoiceTabs as $tab)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link @if($loop->first) active @endif" id="purchase-invoice-{{ $tab }}-tab" data-bs-toggle="tab" data-bs-target="#purchase-invoice-{{ $tab }}" type="button" role="tab" aria-controls="purchase-invoice-{{ $tab }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                            {{ __('purchase_invoices.tabs.'.$tab) }}
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content pt-3">
                <div class="tab-pane fade show active" id="purchase-invoice-basic" role="tabpanel" aria-labelledby="purchase-invoice-basic-tab">
                    <div class="row g-3">
                        @if($canControlDocumentNumber)
                            <div class="col-md-3 col-xl-2">
                                <label class="form-label" for="doc_number">{{ __('purchase_invoices.attributes.doc_number') }}</label>
                                @if($isReadonly)
                                    <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                                @else
                                    <x-forms.input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="1" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}" />
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                            </div>
                        @elseif(! $isCreateLike)
                            <div class="col-md-3 col-xl-2">
                                <x-forms.view-field for="doc_num" :label="__('purchase_invoices.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center dt-code-value" dir="ltr" />
                            </div>
                        @endif

                        @if(! $isCreateLike)
                            <div class="col-md-3 col-xl-2">
                                <x-forms.view-field for="status" :label="__('purchase_invoices.attributes.status')" as="display">
                                    @include('modules.purchases.purchase-invoices.partials.status', ['record' => $record])
                                </x-forms.view-field>
                            </div>
                            <div class="col-md-3 col-xl-2">
                                <x-forms.view-field for="payment_status" :label="__('purchase_invoices.attributes.payment_status')" as="display">
                                    @include('modules.purchases.purchase-invoices.partials.payment-status', ['record' => $record])
                                </x-forms.view-field>
                            </div>
                        @endif

                        <div class="col-md-3">
                            <x-forms.label for="invoice_date" :label="__('purchase_invoices.attributes.invoice_date')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="invoice_date" :value="$plainDate($record?->invoice_date)" dir="ltr" input-class="date-value text-center" />
                            @else
                                <x-forms.date-input class="form-control text-center js-date-picker" id="invoice_date" name="invoice_date" type="text" value="{{ $dateValue('invoice_date', $isCreateLike && $mode !== 'clone' ? now() : $record?->invoice_date) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="invoice_date"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="financial_period_doc_num" :label="__('purchase_invoices.attributes.financial_period')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="financial_period_doc_num" :value="$periodOption['text'] ?? null" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax" id="financial_period_doc_num" name="financial_period_doc_num" data-url="{{ route('admin.select2.financial-periods') }}" data-placeholder="{{ __('purchase_invoices.placeholders.financial_period') }}" data-allow-clear="true" required>
                                    @if($periodOption || $selectedPeriod)
                                        <option value="{{ $selectedPeriod }}" selected>{{ $periodOption['text'] ?? $selectedPeriod }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="financial_period_doc_num"></div>
                        </div>

                        <div class="col-md-6">
                            <x-forms.label for="supplier_doc_num" :label="__('purchase_invoices.attributes.supplier')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="supplier_doc_num" :value="$supplierOption['text'] ?? null" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax" id="supplier_doc_num" name="supplier_doc_num" data-url="{{ route('admin.purchases.select2.suppliers') }}" data-placeholder="{{ __('purchase_invoices.placeholders.supplier') }}" data-allow-clear="true" required>
                                    @if($supplierOption || $selectedSupplier)
                                        <option value="{{ $selectedSupplier }}" selected>{{ $supplierOption['text'] ?? $selectedSupplier }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="supplier_doc_num"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="purchase_order_doc_num">{{ __('purchase_invoices.attributes.purchase_order_source') }} <span class="text-500">({{ __('purchase_invoices.attributes.optional') }})</span></label>
                            @if($isReadonly)
                                <x-forms.view-field for="purchase_order_doc_num" :value="$record?->purchaseOrder?->doc_num ?: __('common.empty_value')" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax" id="purchase_order_doc_num" name="purchase_order_doc_num" data-url="{{ route('admin.purchases.select2.purchase-orders', ['purpose' => 'invoice']) }}" data-placeholder="{{ __('Select') }}">
                                    <option value="">{{ __('Select') }}</option>
                                    @foreach($procurementPurchaseOrders as $purchaseOrder)
                                        <option value="{{ $purchaseOrder->doc_num }}"
                                            data-approved-freight="{{ $purchaseOrder->freight_amount }}"
                                            data-invoiced-freight="{{ $purchaseOrder->invoiced_freight_amount ?? 0 }}"
                                            data-supplier-doc-num="{{ $purchaseOrder->supplier?->doc_num }}"
                                            data-supplier-text="{{ collect([$purchaseOrder->supplier?->doc_num, $purchaseOrder->supplier?->name, $purchaseOrder->supplier?->phone ?: $purchaseOrder->supplier?->mobile])->filter()->join(' / ') }}"
                                            data-currency-doc-num="{{ $purchaseOrder->currency?->doc_num }}"
                                            data-currency-text="{{ collect([$purchaseOrder->currency?->code, $purchaseOrder->currency?->name])->filter()->join(' / ') }}"
                                            data-exchange-rate="{{ $purchaseOrder->exchange_rate }}"
                                            @selected($selectedPurchaseOrder === $purchaseOrder->doc_num)>{{ $purchaseOrder->doc_num }} / {{ $purchaseOrder->supplier?->name }}</option>
                                    @endforeach
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="purchase_order_doc_num"></div>
                            <div class="form-text">{{ __('purchase_invoices.messages.purchase_order_source_help') }}</div>
                            @if($mode === 'create')
                            <label class="form-label mt-2" for="source_receipts">{{ __('purchase_invoices.attributes.goods_receipt_source') }} <span class="text-500">({{ __('purchase_invoices.attributes.optional') }})</span></label><x-forms.select id="source_receipts" class="form-select js-select2-ajax" multiple data-url="{{ route('admin.purchases.select2.receipts', ['purpose' => 'invoice']) }}" data-depends-on="#purchase_order_doc_num" data-dependent-param="purchase_order" data-disable-when-dependency-empty="true" data-placeholder="{{ __('Select') }}">@foreach($eligibleReceiptLines->pluck('receipt')->filter()->unique('id') as $sourceReceipt)<option value="{{ $sourceReceipt->doc_num }}" selected>{{ $sourceReceipt->doc_num }}</option>@endforeach</x-forms.select>
                            <div class="form-text">{{ __('purchase_invoices.messages.receipt_filter_optional') }}</div>
                            <button class="btn btn-falcon-primary btn-sm mt-2" type="button" data-load-invoice-source="{{ route('admin.purchases.purchase-invoices.create') }}">{{ __('procurement.ui.load_received_lines') }}</button>
                            @endif
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="supplier_invoice_number">{{ __('purchase_invoices.attributes.supplier_invoice_number') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="supplier_invoice_number" :value="$value('supplier_invoice_number')" />
                            @else
                                <x-forms.input class="form-control" id="supplier_invoice_number" name="supplier_invoice_number" value="{{ $value('supplier_invoice_number') }}" maxlength="100" dir="ltr" autocomplete="off" />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="supplier_invoice_number"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="supplier_invoice_date">{{ __('purchase_invoices.attributes.supplier_invoice_date') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="supplier_invoice_date" :value="$plainDate($record?->supplier_invoice_date)" dir="ltr" input-class="date-value text-center" />
                            @else
                                <x-forms.date-input class="form-control text-center js-date-picker" id="supplier_invoice_date" name="supplier_invoice_date" type="text" value="{{ $dateValue('supplier_invoice_date', $record?->supplier_invoice_date) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="supplier_invoice_date"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="currency_doc_num" :label="__('purchase_invoices.attributes.currency')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="currency_doc_num" :value="$currencyOption['text'] ?? null" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax js-purchase-invoice-currency" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.purchases.select2.currencies') }}" data-placeholder="{{ __('purchase_invoices.placeholders.currency') }}" data-allow-clear="true" required>
                                    @if($currencyOption || $selectedCurrency)
                                        <option value="{{ $selectedCurrency }}" data-is-main="{{ ($currencyOption['is_main'] ?? false) ? '1' : '0' }}" selected>{{ $currencyOption['text'] ?? $selectedCurrency }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="exchange_rate" :label="__('purchase_invoices.attributes.exchange_rate')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="exchange_rate" :value="$numbers->format($value('exchange_rate', 1))" input-class="text-center" dir="ltr" />
                            @else
                                <x-forms.numeric-input class="text-center" id="exchange_rate" name="exchange_rate" :value="old('exchange_rate', $record?->exchange_rate ?? 1)" :scale="6" min="0.000001" step="0.000001" required />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="payment_type" :label="__('purchase_invoices.attributes.payment_type')" required />
                            @if($isReadonly)
                                <x-forms.view-field for="payment_type" :value="__('purchase_invoices.payment_types.'.($record?->payment_type ?? PurchaseInvoice::PaymentTypeCredit))" />
                            @else
                                <x-forms.select class="form-select js-purchase-invoice-payment-type" id="payment_type" name="payment_type" required>
                                    @foreach(PurchaseInvoice::paymentTypes() as $type)
                                        <option value="{{ $type }}" @selected($selectedPaymentType === $type)>{{ __('purchase_invoices.payment_types.'.$type) }}</option>
                                    @endforeach
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="payment_type"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="payment_source_type">{{ __('purchase_invoices.attributes.payment_source_type') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="payment_source_type" :value="$record?->payment_source_type ? __('purchase_invoices.source_types.'.$record->payment_source_type) : null" />
                            @else
                                <x-forms.select class="form-select js-purchase-invoice-source-type" id="payment_source_type" name="payment_source_type">
                                    <option value="{{ PurchaseInvoice::SourceCashbox }}" @selected($selectedPaymentSource === PurchaseInvoice::SourceCashbox)>{{ __('purchase_invoices.source_types.cashbox') }}</option>
                                    <option value="{{ PurchaseInvoice::SourceBank }}" @selected($selectedPaymentSource === PurchaseInvoice::SourceBank)>{{ __('purchase_invoices.source_types.bank') }}</option>
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="payment_source_type"></div>
                        </div>

                        <div class="col-md-3 js-purchase-invoice-header-cashbox">
                            <label class="form-label" for="cashbox_doc_num">{{ __('purchase_invoices.attributes.cashbox') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="cashbox_doc_num" :value="$cashboxOption['text'] ?? null" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax" id="cashbox_doc_num" name="cashbox_doc_num" data-url="{{ route('admin.purchases.select2.cashboxes') }}" data-placeholder="{{ __('purchase_invoices.placeholders.cashbox') }}" data-allow-clear="true">
                                    @if($cashboxOption || $selectedCashbox)
                                        <option value="{{ $selectedCashbox }}" selected>{{ $cashboxOption['text'] ?? $selectedCashbox }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="cashbox_doc_num"></div>
                        </div>

                        <div class="col-md-3 js-purchase-invoice-header-bank">
                            <label class="form-label" for="bank_account_doc_num">{{ __('purchase_invoices.attributes.bank_account') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="bank_account_doc_num" :value="$bankOption['text'] ?? null" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax" id="bank_account_doc_num" name="bank_account_doc_num" data-url="{{ route('admin.purchases.select2.bank-accounts') }}" data-placeholder="{{ __('purchase_invoices.placeholders.bank_account') }}" data-allow-clear="true">
                                    @if($bankOption || $selectedBank)
                                        <option value="{{ $selectedBank }}" selected>{{ $bankOption['text'] ?? $selectedBank }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="bank_account_doc_num"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="header_discount_type">{{ __('purchase_invoices.attributes.header_discount_type') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="header_discount_type" :value="$record?->header_discount_type ? __('purchase_invoices.discount_types.'.$record->header_discount_type) : null" />
                            @else
                                <x-forms.select class="form-select js-purchase-invoice-header-discount-type" id="header_discount_type" name="header_discount_type">
                                    <option value="fixed" @selected($selectedHeaderDiscountType === 'fixed')>{{ __('purchase_invoices.discount_types.fixed') }}</option>
                                    <option value="percentage" @selected($selectedHeaderDiscountType === 'percentage')>{{ __('purchase_invoices.discount_types.percentage') }}</option>
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="header_discount_type"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="header_discount_value">{{ __('purchase_invoices.attributes.header_discount_value') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="header_discount_value" :value="$numbers->format($value('header_discount_value', 0))" input-class="text-end" dir="ltr" />
                            @else
                                <x-forms.numeric-input class="text-end js-purchase-invoice-header-discount-value" id="header_discount_value" name="header_discount_value" :value="old('header_discount_value', $record?->header_discount_value ?? 0)" :scale="4" min="0" step="0.0001" />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="header_discount_value"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="freight_amount">{{ __('purchase_invoices.attributes.freight_amount') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="freight_amount" :value="$numbers->format($value('freight_amount', 0))" input-class="text-end" dir="ltr" />
                            @else
                                <x-forms.numeric-input class="text-end js-purchase-invoice-freight" id="freight_amount" name="freight_amount" :value="old('freight_amount', $record?->freight_amount ?? 0)" :scale="4" min="0" step="0.0001" />
                            @endif
                            <div class="form-text js-purchase-invoice-freight-match"></div>
                            <div class="invalid-feedback d-block" data-error-for="freight_amount"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="freight_tax_rate">{{ __('purchase_invoices.attributes.freight_tax_rate') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="freight_tax_rate" :value="$numbers->format($value('freight_tax_rate', 0))" input-class="text-end" dir="ltr" />
                            @else
                                <x-forms.numeric-input class="text-end js-purchase-invoice-freight-tax-rate" id="freight_tax_rate" name="freight_tax_rate" :value="old('freight_tax_rate', $record?->freight_tax_rate ?? 0)" :scale="4" min="0" max="100" step="0.0001" />
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="freight_tax_rate"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="notes">{{ __('purchase_invoices.attributes.notes') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="notes" as="textarea" :value="$value('notes')" rows="2" />
                            @else
                                <x-forms.textarea class="form-control" id="notes" name="notes" rows="2">{{ $value('notes') }}</x-forms.textarea>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="notes"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="internal_notes">{{ __('purchase_invoices.attributes.internal_notes') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="internal_notes" as="textarea" :value="$value('internal_notes')" rows="2" />
                            @else
                                <x-forms.textarea class="form-control" id="internal_notes" name="internal_notes" rows="2">{{ $value('internal_notes') }}</x-forms.textarea>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="internal_notes"></div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="purchase-invoice-lines" role="tabpanel" aria-labelledby="purchase-invoice-lines-tab">
                    <div class="row flex-between-center g-2 mb-3">
                        <div class="col">
                            <h6 class="mb-0">{{ __('purchase_invoices.sections.lines') }}</h6>
                            <div class="text-600 small mt-1">{{ __('fixed_assets.purchase_source.invoice_purchase_item_help') }}</div>
                        </div>
                        @unless($isReadonly)
                            <div class="col-auto d-flex gap-2">
                                @can('products.create')
                                    <a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.products.create', ['purchase_asset' => 1]) }}">
                                        <span class="fas fa-industry me-1"></span>{{ __('fixed_assets.purchase_source.create_purchase_asset_item') }}
                                    </a>
                                @endcan
                                <button class="btn btn-falcon-default btn-sm js-purchase-invoice-add-line" type="button">
                                    <span class="fas fa-plus me-1"></span>{{ __('purchase_invoices.actions.add_line') }}
                                </button>
                            </div>
                        @endunless
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 purchase-invoice-lines js-purchase-invoice-lines">
                            <thead class="bg-200">
                                <tr>
                                    <th>{{ __('purchase_invoices.attributes.purchase_order_line_source') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.goods_receipt_source') }}</th>
                                    <th class="purchase-invoice-product-cell">{{ __('purchase_invoices.attributes.product') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.unit') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.quantity') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.unit_price') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.line_discount_type') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.line_discount_value') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.tax_rate') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.line_subtotal') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.line_discount_amount') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.line_tax_amount') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.line_total') }}</th>
                                    <th class="purchase-invoice-notes-cell">{{ __('purchase_invoices.attributes.notes') }}</th>
                                    <th>{{ __('Attachments') }}</th>
                                    @unless($isReadonly)
                                        <th class="text-center" style="width: 80px">{{ __('common.fields.actions') }}</th>
                                    @endunless
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($lineRows as $index => $line)
                                    <tr class="js-purchase-invoice-line" data-index="{{ $index }}">
                                        @php
                                            $sourceOrderLine = $procurementPurchaseOrders->flatMap->lines->firstWhere('public_id', $line['purchase_order_line_public_id'] ?? '');
                                            $sourceReceiptLine = $eligibleReceiptLines->firstWhere('public_id', $line['receipt_line_public_id'] ?? '');
                                            $attachmentLine = $isCreateLike ? null : $record?->lines?->firstWhere('public_id', $line['public_id'] ?? null);
                                        @endphp
                                        <td @if(!$sourceOrderLine) hidden @endif class="line-card-info js-purchase-invoice-source-reference">
                                            @if($sourceOrderLine)<a href="{{ route('admin.purchases.purchase-orders.show', $sourceOrderLine->purchaseOrder->doc_num) }}">{{ $sourceOrderLine->purchaseOrder->doc_num }}</a>@endif
                                            <x-forms.input type="hidden" name="lines[{{ $index }}][purchase_order_line_public_id]" value="{{ $line['purchase_order_line_public_id'] ?? '' }}" />
                                        </td>
                                        <td @if(!$sourceReceiptLine) hidden @endif class="line-card-info js-purchase-invoice-source-reference">
                                            @if($sourceReceiptLine)<a href="{{ route('admin.purchases.goods-receipt-notes.show', $sourceReceiptLine->receipt->doc_num) }}">{{ $sourceReceiptLine->receipt->doc_num }}</a>
                                                <small>{{ __('Accepted') }}: {{ $numbers->format($sourceReceiptLine->product?->cost_as_inventory ? $sourceReceiptLine->inventory_posted_quantity : $sourceReceiptLine->accepted_quantity) }} / {{ __('Remaining to invoice') }}: {{ $numbers->format(app(\Modules\Purchases\Services\PurchaseInvoiceMatchingService::class)->remainingForReceipt($sourceReceiptLine, $record?->exists ? $record->id : null)) }}</small>
                                            @endif
                                            <x-forms.input type="hidden" name="lines[{{ $index }}][receipt_line_public_id]" value="{{ $line['receipt_line_public_id'] ?? '' }}" />
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $line['product_label'] ?? null }}</div>
                                            @else
                                                <x-forms.input type="hidden" name="lines[{{ $index }}][public_id]" value="{{ $line['public_id'] ?? '' }}" />
                                                <x-forms.select class="form-select js-select2-ajax js-purchase-invoice-product" name="lines[{{ $index }}][product_doc_num]" data-url="{{ route('admin.purchases.select2.products') }}" data-placeholder="{{ __('purchase_invoices.placeholders.product') }}" data-allow-clear="true" required>
                                                    @if(! empty($line['product_doc_num']))
                                                        <option value="{{ $line['product_doc_num'] }}" selected>{{ $line['product_label'] ?? $line['product_doc_num'] }}</option>
                                                    @endif
                                                </x-forms.select>
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $line['unit_label'] ?? null }}</div>
                                            @else
                                                <x-forms.select class="form-select js-select2-local js-purchase-invoice-unit" name="lines[{{ $index }}][unit_doc_num]" data-placeholder="{{ __('purchase_invoices.placeholders.unit') }}" required>
                                                    @foreach(($line['unit_options'] ?? []) as $option)
                                                        <option value="{{ $option['id'] }}" @selected(($line['unit_doc_num'] ?? null) === $option['id'])>{{ $option['text'] }}</option>
                                                    @endforeach
                                                    @if(! empty($line['unit_doc_num']) && collect($line['unit_options'] ?? [])->where('id', $line['unit_doc_num'])->isEmpty())
                                                        <option value="{{ $line['unit_doc_num'] }}" selected>{{ $line['unit_label'] ?? $line['unit_doc_num'] }}</option>
                                                    @endif
                                                </x-forms.select>
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_doc_num"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($line['quantity'] ?? 0) }}</div>
                                            @else
                                                <x-forms.numeric-input class="text-end js-purchase-invoice-line-number js-purchase-invoice-quantity" :name="'lines['.$index.'][quantity]'" :value="$line['quantity'] ?? ''" :scale="8" min="0.00000001" step="0.00000001" required />
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.quantity"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($line['unit_price'] ?? 0) }}</div>
                                            @else
                                                <x-forms.numeric-input class="text-end js-purchase-invoice-line-number js-purchase-invoice-unit-price" :name="'lines['.$index.'][unit_price]'" :value="$line['unit_price'] ?? ''" :scale="4" min="0" step="0.0001" required />
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_price"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ __('purchase_invoices.discount_types.'.(($line['discount_type'] ?? null) ?: 'fixed')) }}</div>
                                            @else
                                                <x-forms.select class="form-select js-purchase-invoice-discount-type" name="lines[{{ $index }}][discount_type]">
                                                    <option value="fixed" @selected(($line['discount_type'] ?? 'fixed') === 'fixed')>{{ __('purchase_invoices.discount_types.fixed') }}</option>
                                                    <option value="percentage" @selected(($line['discount_type'] ?? 'fixed') === 'percentage')>{{ __('purchase_invoices.discount_types.percentage') }}</option>
                                                </x-forms.select>
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.discount_type"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($line['discount_value'] ?? 0) }}</div>
                                            @else
                                                <x-forms.numeric-input class="text-end js-purchase-invoice-line-number js-purchase-invoice-discount-value" :name="'lines['.$index.'][discount_value]'" :value="$line['discount_value'] ?? 0" :scale="4" min="0" step="0.0001" />
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.discount_value"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($line['tax_rate'] ?? 0) }}</div>
                                            @else
                                                <x-forms.numeric-input class="text-end js-purchase-invoice-line-number js-purchase-invoice-tax-rate" :name="'lines['.$index.'][tax_rate]'" :value="$line['tax_rate'] ?? 0" :scale="4" min="0" max="100" step="0.0001" />
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.tax_rate"></div>
                                            @endif
                                        </td>
                                        <td class="text-end js-purchase-invoice-line-subtotal" dir="ltr">{{ $numbers->format($line['subtotal_amount'] ?? 0) }}</td>
                                        <td class="text-end js-purchase-invoice-line-discount" dir="ltr">{{ $numbers->format($line['discount_amount'] ?? 0) }}</td>
                                        <td class="text-end js-purchase-invoice-line-tax" dir="ltr">{{ $numbers->format($line['tax_amount'] ?? 0) }}</td>
                                        <td class="text-end js-purchase-invoice-line-total fw-semibold" dir="ltr">{{ $numbers->format($line['total_after_tax'] ?? 0) }}</td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $line['notes'] ?? null }}</div>
                                            @else
                                                <x-forms.input class="form-control" name="lines[{{ $index }}][notes]" value="{{ $line['notes'] ?? '' }}" />
                                                <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.notes"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @include('modules.purchases.procurement.line-attachments', [
                                                'attachmentLine' => $attachmentLine,
                                                'attachmentCompanyId' => $record?->company_id ?? $context['company_id'],
                                                'index' => $index,
                                                'lineAttachmentsReadonly' => $isReadonly,
                                            ])
                                        </td>
                                        @unless($isReadonly)
                                            <td class="text-center purchase-invoice-actions-cell">
                                                <div class="purchase-invoice-line-actions">
                                                <button class="btn btn-falcon-default btn-sm js-purchase-invoice-duplicate-line" @if(!empty($line['purchase_order_line_public_id'])) hidden @endif type="button" title="{{ __('purchase_invoices.js.duplicate_line_title') }}" aria-label="{{ __('purchase_invoices.js.duplicate_line_title') }}">
                                                    <span class="fas fa-copy" aria-hidden="true"></span><span class="ms-1">{{ __('purchase_invoices.actions.duplicate_line') }}</span>
                                                </button>
                                                <button class="btn btn-falcon-danger btn-sm js-purchase-invoice-remove-line" type="button" title="{{ __('purchase_invoices.js.delete_line_title') }}" aria-label="{{ __('purchase_invoices.js.delete_line_title') }}">
                                                    <span class="fas fa-trash-alt" aria-hidden="true"></span><span class="ms-1">{{ __('purchase_invoices.actions.delete_line') }}</span>
                                                </button>
                                                </div>
                                            </td>
                                        @endunless
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $isReadonly ? 15 : 16 }}" class="text-center text-600 py-4">{{ __('purchase_invoices.messages.no_lines') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="invalid-feedback d-block pt-2" data-error-for="lines"></div>

                    <div class="row justify-content-end mt-3">
                        <div class="col-md-7 col-xl-5">
                            <div class="table-responsive purchase-invoice-total-box ms-auto">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr>
                                            <th>{{ __('purchase_invoices.totals.subtotal') }}</th>
                                            <td class="text-end js-purchase-invoice-subtotal" dir="ltr">{{ $numbers->format($record?->subtotal_amount ?? 0) }}</td>
                                        </tr>
                                        <tr>
                                            <th>{{ __('purchase_invoices.totals.line_discounts') }}</th>
                                            <td class="text-end js-purchase-invoice-line-discounts" dir="ltr">{{ $numbers->format($record?->line_discount_amount ?? 0) }}</td>
                                        </tr>
                                        <tr>
                                            <th>{{ __('purchase_invoices.totals.header_discount') }}</th>
                                            <td class="text-end js-purchase-invoice-header-discount" dir="ltr">{{ $numbers->format($record?->header_discount_amount ?? 0) }}</td>
                                        </tr>
                                        <tr>
                                            <th>{{ __('Freight') }}</th>
                                            <td class="text-end js-purchase-invoice-freight-total" dir="ltr">{{ $numbers->format($record?->freight_amount ?? 0) }}</td>
                                        </tr>
                                        <tr>
                                            <th>{{ __('Freight VAT') }}</th>
                                            <td class="text-end js-purchase-invoice-freight-tax" dir="ltr">{{ $numbers->format($record?->freight_tax_amount ?? 0) }}</td>
                                        </tr>
                                        <tr>
                                            <th>{{ __('purchase_invoices.totals.taxable') }}</th>
                                            <td class="text-end js-purchase-invoice-taxable" dir="ltr">{{ $numbers->format($record?->taxable_amount ?? 0) }}</td>
                                        </tr>
                                        <tr>
                                            <th>{{ __('purchase_invoices.totals.tax') }}</th>
                                            <td class="text-end js-purchase-invoice-tax" dir="ltr">{{ $numbers->format($record?->tax_amount ?? 0) }}</td>
                                        </tr>
                                        <tr class="fw-bold">
                                            <th>{{ __('purchase_invoices.totals.net_total') }}</th>
                                            <td class="text-end js-purchase-invoice-total" dir="ltr">{{ $numbers->format($record?->total_amount ?? 0) }}</td>
                                        </tr>
                                        <tr>
                                            <th>{{ __('purchase_invoices.totals.paid') }}</th>
                                            <td class="text-end js-purchase-invoice-paid" dir="ltr">{{ $numbers->format($record?->paid_amount ?? 0) }}</td>
                                        </tr>
                                        <tr>
                                            <th>{{ __('purchase_invoices.totals.remaining') }}</th>
                                            <td class="text-end js-purchase-invoice-remaining" dir="ltr">{{ $numbers->format($record?->remaining_amount ?? 0) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                @if($record?->exists)
                    <div class="tab-pane fade" id="purchase-invoice-assets" role="tabpanel" aria-labelledby="purchase-invoice-assets-tab">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <h6 class="mb-1">{{ __('fixed_assets.purchase_source.invoice_section') }}</h6>
                                <div class="text-600 small">{{ __('fixed_assets.purchase_source.invoice_section_help') }}</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="bg-200">
                                    <tr>
                                        <th>{{ __('purchase_invoices.attributes.product') }}</th>
                                        <th>{{ __('fixed_assets.purchase_source.line_value') }}</th>
                                        <th>{{ __('fixed_assets.purchase_source.allocated') }}</th>
                                        <th>{{ __('fixed_assets.purchase_source.linked_assets') }}</th>
                                        <th class="text-end">{{ __('common.fields.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php $assetIntegration = app(\Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService::class); @endphp
                                    @forelse($record->lines as $invoiceLine)
                                        @php
                                            $lineValue = $assetIntegration->lineNetAmount($invoiceLine);
                                            $allocatedValue = $assetIntegration->allocatedAmount($invoiceLine);
                                            $assetEligible = $invoiceLine->product && !$invoiceLine->product->isService() && !$invoiceLine->product->cost_as_inventory;
                                            $assetTreatment = $invoiceLine->asset_treatment ?: ($invoiceLine->fixedAssets->isNotEmpty()
                                                ? \Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService::TreatmentNewAsset
                                                : \Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService::TreatmentNone);
                                            $targetAsset = $invoiceLine->targetFixedAsset;
                                        @endphp
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $invoiceLine->product?->name }}</div>
                                                <div class="text-600 small" dir="ltr">{{ $invoiceLine->product?->doc_num }}</div>
                                            </td>
                                            <td class="text-end" dir="ltr">{{ $numbers->format($lineValue) }}</td>
                                            <td class="text-end" dir="ltr">{{ $numbers->format($allocatedValue) }}</td>
                                            <td>
                                                @if($assetEligible && $assetTreatment === 'new_asset')
                                                    <div class="small text-600 mb-1">{{ __('fixed_assets.purchase_source.asset_cards_progress', [
                                                        'created' => $invoiceLine->fixedAssets->count(),
                                                        'expected' => $assetIntegration->suggestedAssetCardCount($invoiceLine),
                                                    ]) }}</div>
                                                @endif
                                                @forelse($invoiceLine->fixedAssets as $asset)
                                                    @can('fixed_assets.view')
                                                        <a class="d-inline-block me-2 fw-semibold" href="{{ route('admin.fixed-assets.assets.show', $asset->doc_num) }}">{{ $asset->doc_num }} · {{ $asset->asset_name }}</a>
                                                    @else
                                                        <span class="d-inline-block me-2 text-700">{{ $asset->doc_num }} · {{ $asset->asset_name }}</span>
                                                    @endcan
                                                @empty
                                                    @if($targetAsset)
                                                        @can('fixed_assets.view')
                                                            <a class="d-inline-block me-2 fw-semibold" href="{{ route('admin.fixed-assets.lifecycle.show', $targetAsset) }}">{{ $targetAsset->doc_num }} · {{ $targetAsset->asset_name }}</a>
                                                        @else
                                                            <span class="d-inline-block me-2 text-700">{{ $targetAsset->doc_num }} · {{ $targetAsset->asset_name }}</span>
                                                        @endcan
                                                        @if($invoiceLine->assetImprovementMovement)
                                                            <div class="small text-600 mt-1">{{ $invoiceLine->assetImprovementMovement->doc_num }} · {{ __('fixed_assets.cycle.'.$invoiceLine->assetImprovementMovement->movement_type) }}</div>
                                                        @endif
                                                    @else
                                                        <span class="text-600">{{ __('common.empty_value') }}</span>
                                                    @endif
                                                @endforelse
                                            </td>
                                            <td class="text-end">
                                                @if($record->isDraft() && $assetEligible && ($canManageInCurrentBranch ?? false))
                                                    <div class="js-asset-treatment-editor text-start" data-endpoint="{{ route('admin.purchases.purchase-invoices.asset-treatment', $record->doc_num) }}" data-line-public-id="{{ $invoiceLine->public_id }}">
                                                        <label class="form-label small mb-1">{{ __('fixed_assets.purchase_source.treatment') }}</label>
                                                        <x-forms.select class="form-select form-select-sm js-asset-treatment mb-2">
                                                            <option value="none" @selected($assetTreatment === 'none')>{{ __('fixed_assets.purchase_source.treatment_none') }}</option>
                                                            @can('fixed_assets.create')<option value="new_asset" @selected($assetTreatment === 'new_asset')>{{ __('fixed_assets.purchase_source.treatment_new_asset') }}</option>@endcan
                                                            @can('fixed_assets.improvement.post')<option value="capital_improvement" @selected($assetTreatment === 'capital_improvement')>{{ __('fixed_assets.purchase_source.treatment_improvement') }}</option>@endcan
                                                        </x-forms.select>
                                                        <div class="js-asset-improvement-fields {{ $assetTreatment === 'capital_improvement' ? '' : 'd-none' }}">
                                                            <x-forms.select class="form-select form-select-sm js-select2-ajax js-asset-improvement-target mb-2" data-url="{{ route('admin.fixed-assets.select2.assets', ['purchasable_improvement' => 1, 'branch_doc_num' => $record->branch?->doc_num]) }}" data-placeholder="{{ __('fixed_assets.purchase_source.select_existing_asset') }}" data-allow-clear="true">
                                                                @if($targetAsset)<option value="{{ $targetAsset->doc_num }}" selected>{{ $targetAsset->doc_num }} / {{ $targetAsset->asset_name }}</option>@endif
                                                            </x-forms.select>
                                                            <x-forms.date-input class="form-control form-control-sm js-date-picker js-asset-improvement-date mb-2" type="text" value="{{ $plainDate($invoiceLine->asset_effective_date ?: $record->invoice_date) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" placeholder="{{ __('fixed_assets.purchase_source.effective_date') }}" />
                                                        </div>
                                                        <div class="d-flex flex-wrap justify-content-end gap-1">
                                                            <button class="btn btn-falcon-default btn-sm js-save-asset-treatment" type="button"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
                                                            @if($assetTreatment === 'new_asset' && bccomp($allocatedValue, $lineValue, 4) < 0)
                                                                @can('fixed_assets.create')
                                                                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.fixed-assets.assets.create', ['purchase_invoice_line' => $invoiceLine->public_id]) }}">
                                                                        <span class="fas fa-plus me-1"></span>{{ __('fixed_assets.purchase_source.register_next_asset', [
                                                                            'current' => $invoiceLine->fixedAssets->count() + 1,
                                                                            'expected' => $assetIntegration->suggestedAssetCardCount($invoiceLine),
                                                                        ]) }}
                                                                    </a>
                                                                @endcan
                                                            @endif
                                                        </div>
                                                    </div>
                                                @elseif(!$assetEligible)
                                                    <span class="text-600 small">{{ __('fixed_assets.purchase_source.non_inventory_only') }}</span>
                                                @elseif($assetTreatment !== 'none')
                                                    <x-status-indicator :status="$assetTreatment" :label="__('fixed_assets.purchase_source.treatments.'.$assetTreatment)" tone="primary" />
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-center text-600 py-4">{{ __('purchase_invoices.messages.no_lines') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <div class="tab-pane fade" id="purchase-invoice-payments" role="tabpanel" aria-labelledby="purchase-invoice-payments-tab">
                    @php
                        $actualPayments = $record?->paymentAllocations?->pluck('paymentContext')->filter()->unique('id') ?? collect();
                    @endphp
                    @if($actualPayments->isNotEmpty())
                        <div class="card border mb-3">
                            <div class="card-header bg-light py-2"><h6 class="mb-0">{{ __('fixed_assets.purchase_source.actual_payments') }}</h6></div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>{{ __('Document number') }}</th><th>{{ __('Payment method') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th class="text-end">{{ __('common.fields.actions') }}</th></tr></thead>
                                    <tbody>
                                        @foreach($actualPayments as $payment)
                                            @php
                                                $paymentPrintUrl = route('admin.purchases.procurement.print', ['supplier-payment', $payment->doc_num]);
                                                if ($payment->payment_method === \Modules\Purchases\Models\SupplierPaymentContext::MethodCash && $payment->cashVoucher) {
                                                    $paymentPrintUrl = route('admin.finance.cash-payment-vouchers.print', $payment->cashVoucher->doc_num);
                                                } elseif ($payment->payment_method === \Modules\Purchases\Models\SupplierPaymentContext::MethodCheque && $payment->cheque) {
                                                    $paymentPrintUrl = route('admin.finance.cheques.print', $payment->cheque->doc_num);
                                                }
                                            @endphp
                                            <tr>
                                                <td><a href="{{ route('admin.purchases.supplier-payments.show', $payment->doc_num) }}">{{ $payment->doc_num }}</a></td>
                                                <td>{{ __('procurement.statuses.'.$payment->payment_method) }}</td>
                                                <td class="text-end" dir="ltr">{{ $numbers->format($payment->amount) }}</td>
                                                <td>{{ __('procurement.statuses.'.$payment->status) }}</td>
                                                <td class="text-end"><a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ $paymentPrintUrl }}"><span class="fas fa-print me-1"></span>{{ __('fixed_assets.purchase_source.print_payment_document') }}</a></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                    <div class="row flex-between-center g-2 mb-3">
                        <div class="col">
                            <h6 class="mb-0">{{ __('purchase_invoices.sections.payment_schedule') }}</h6>
                        </div>
                        @unless($isReadonly)
                            <div class="col-auto">
                                <button class="btn btn-falcon-default btn-sm js-purchase-invoice-add-schedule" type="button">
                                    <span class="fas fa-plus me-1"></span>{{ __('purchase_invoices.actions.add_payment') }}
                                </button>
                            </div>
                        @endunless
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 purchase-invoice-schedules js-purchase-invoice-schedules">
                            <thead class="bg-200">
                                <tr>
                                    <th>{{ __('purchase_invoices.attributes.due_date') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.payment_amount') }}</th>
                                    @if($isReadonly)
                                        <th>{{ __('purchase_invoices.totals.paid') }}</th>
                                        <th>{{ __('purchase_invoices.totals.credited') }}</th>
                                        <th>{{ __('purchase_invoices.totals.remaining') }}</th>
                                    @endif
                                    <th>{{ __('purchase_invoices.attributes.payment_source_type') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.cashbox') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.bank_account') }}</th>
                                    <th class="purchase-invoice-notes-cell">{{ __('purchase_invoices.attributes.notes') }}</th>
                                    @unless($isReadonly)
                                        <th class="text-center purchase-invoice-actions-cell">{{ __('common.fields.actions') }}</th>
                                    @endunless
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($scheduleRows as $index => $schedule)
                                    <tr class="js-purchase-invoice-schedule" data-index="{{ $index }}">
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext text-center date-value" dir="ltr">{{ $schedule['due_date'] ?? null }}</div>
                                            @else
                                                <x-forms.input type="hidden" name="payment_schedules[{{ $index }}][public_id]" value="{{ $schedule['public_id'] ?? '' }}" />
                                                <x-forms.date-input class="form-control form-control-sm text-center js-date-picker" name="payment_schedules[{{ $index }}][due_date]" type="text" value="{{ $schedule['due_date'] ?? '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" />
                                                <div class="invalid-feedback d-block" data-error-for="payment_schedules.{{ $index }}.due_date"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($schedule['amount'] ?? 0) }}</div>
                                            @else
                                                <x-forms.numeric-input class="form-control-sm text-end js-purchase-invoice-schedule-amount" :name="'payment_schedules['.$index.'][amount]'" :value="$schedule['amount'] ?? ''" :scale="4" min="0.0001" step="0.0001" />
                                                <div class="invalid-feedback d-block" data-error-for="payment_schedules.{{ $index }}.amount"></div>
                                            @endif
                                        </td>
                                        @if($isReadonly)
                                            <td><div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($schedule['paid_amount'] ?? 0) }}</div></td>
                                            <td><div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($schedule['credited_amount'] ?? 0) }}</div></td>
                                            <td><div class="form-control-plaintext text-end" dir="ltr">{{ $numbers->format($schedule['outstanding_amount'] ?? 0) }}</div></td>
                                        @endif
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ filled($schedule['payment_source_type'] ?? null) ? __('purchase_invoices.source_types.'.$schedule['payment_source_type']) : __('common.empty_value') }}</div>
                                            @else
                                                <x-forms.select class="form-select form-select-sm js-purchase-invoice-schedule-source" name="payment_schedules[{{ $index }}][payment_source_type]">
                                                    @foreach(PurchaseInvoice::scheduleSourceTypes() as $source)
                                                        <option value="{{ $source }}" @selected(($schedule['payment_source_type'] ?? PurchaseInvoice::SourceCashbox) === $source)>{{ __('purchase_invoices.source_types.'.$source) }}</option>
                                                    @endforeach
                                                </x-forms.select>
                                                <div class="invalid-feedback d-block" data-error-for="payment_schedules.{{ $index }}.payment_source_type"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $schedule['cashbox_label'] ?? null }}</div>
                                            @else
                                                <x-forms.select class="form-select form-select-sm js-select2-ajax js-purchase-invoice-schedule-cashbox" name="payment_schedules[{{ $index }}][cashbox_doc_num]" data-url="{{ route('admin.purchases.select2.cashboxes') }}" data-placeholder="{{ __('purchase_invoices.placeholders.cashbox') }}" data-allow-clear="true">
                                                    @if(! empty($schedule['cashbox_doc_num']))
                                                        <option value="{{ $schedule['cashbox_doc_num'] }}" selected>{{ $schedule['cashbox_label'] ?? $schedule['cashbox_doc_num'] }}</option>
                                                    @endif
                                                </x-forms.select>
                                                <div class="invalid-feedback d-block" data-error-for="payment_schedules.{{ $index }}.cashbox_doc_num"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $schedule['bank_account_label'] ?? null }}</div>
                                            @else
                                                <x-forms.select class="form-select form-select-sm js-select2-ajax js-purchase-invoice-schedule-bank" name="payment_schedules[{{ $index }}][bank_account_doc_num]" data-url="{{ route('admin.purchases.select2.bank-accounts') }}" data-placeholder="{{ __('purchase_invoices.placeholders.bank_account') }}" data-allow-clear="true">
                                                    @if(! empty($schedule['bank_account_doc_num']))
                                                        <option value="{{ $schedule['bank_account_doc_num'] }}" selected>{{ $schedule['bank_account_label'] ?? $schedule['bank_account_doc_num'] }}</option>
                                                    @endif
                                                </x-forms.select>
                                                <div class="invalid-feedback d-block" data-error-for="payment_schedules.{{ $index }}.bank_account_doc_num"></div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($isReadonly)
                                                <div class="form-control-plaintext">{{ $schedule['notes'] ?? null }}</div>
                                            @else
                                                <x-forms.input class="form-control form-control-sm" name="payment_schedules[{{ $index }}][notes]" value="{{ $schedule['notes'] ?? '' }}" />
                                                <div class="invalid-feedback d-block" data-error-for="payment_schedules.{{ $index }}.notes"></div>
                                            @endif
                                        </td>
                                        @unless($isReadonly)
                                            <td class="text-center purchase-invoice-actions-cell">
                                                <div class="purchase-invoice-line-actions">
                                                <button class="btn btn-falcon-default btn-sm js-purchase-invoice-duplicate-schedule" type="button" title="{{ __('purchase_invoices.js.duplicate_payment_title') }}" aria-label="{{ __('purchase_invoices.js.duplicate_payment_title') }}">
                                                    <span class="fas fa-copy" aria-hidden="true"></span><span class="ms-1">{{ __('purchase_invoices.actions.duplicate_payment') }}</span>
                                                </button>
                                                <button class="btn btn-falcon-danger btn-sm js-purchase-invoice-remove-schedule" type="button" title="{{ __('purchase_invoices.js.delete_payment_title') }}" aria-label="{{ __('purchase_invoices.js.delete_payment_title') }}">
                                                    <span class="fas fa-trash-alt" aria-hidden="true"></span><span class="ms-1">{{ __('purchase_invoices.actions.delete_payment') }}</span>
                                                </button>
                                                </div>
                                            </td>
                                        @endunless
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $isReadonly ? 9 : 7 }}" class="text-center text-600 py-4">{{ __('purchase_invoices.messages.no_payment_schedule') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <th class="text-nowrap text-end">{{ __('purchase_invoices.totals.schedule_total') }}</th>
                                    <th class="text-end{{ $isReadonly ? '' : ' js-purchase-invoice-schedule-total' }}" dir="ltr" style="min-width: 100px">{{ $isReadonly ? $numbers->format($readonlyScheduleTotal) : '0' }}</th>
                                    <th colspan="{{ $isReadonly ? 7 : 5 }}"></th>
                                </tr>
                                <tr>
                                    <th class="text-nowrap text-end">{{ __('purchase_invoices.totals.schedule_difference') }}</th>
                                    <th class="text-end{{ $isReadonly ? '' : ' js-purchase-invoice-schedule-difference' }}" dir="ltr" style="min-width: 100px">{{ $isReadonly ? $numbers->format($readonlyScheduleDifference) : '0' }}</th>
                                    <th colspan="{{ $isReadonly ? 7 : 5 }}"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="invalid-feedback d-block pt-2" data-error-for="payment_schedules"></div>
                </div>

                <div class="tab-pane fade" id="purchase-invoice-audit" role="tabpanel" aria-labelledby="purchase-invoice-audit-tab">
                    @if($record)
                        <x-audit-fields-row
                            :metadata="$metadata"
                            :show-deleted="$record?->trashed() ?? false"
                            :show-restored="! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                            class="mt-0"
                        />
                        <div class="row g-3 mt-0">
                            <x-forms.view-field :label="__('purchase_invoices.attributes.approved_by')" :value="$metadata['approved_by'] ?? null" class="col-md-6 col-xl-3" />
                            <x-forms.view-field :label="__('purchase_invoices.attributes.approved_at')" :value="$metadata['approved_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                            <x-forms.view-field :label="__('purchase_invoices.attributes.closed_by')" :value="$metadata['closed_by'] ?? null" class="col-md-6 col-xl-3" />
                            <x-forms.view-field :label="__('purchase_invoices.attributes.closed_at')" :value="$metadata['closed_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                            <x-forms.view-field :label="__('purchase_invoices.attributes.cancelled_by')" :value="$metadata['cancelled_by'] ?? null" class="col-md-6 col-xl-3" />
                            <x-forms.view-field :label="__('purchase_invoices.attributes.cancelled_at')" :value="$metadata['cancelled_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                            @if($record?->journalEntry)
                                <x-forms.view-field :label="__('purchase_invoices.attributes.journal_entry')" :value="$record->journalEntry->doc_num" class="col-md-6 col-xl-3" />
                            @endif
                            @if($record?->cancel_reason)
                                <x-forms.view-field :label="__('purchase_invoices.attributes.cancel_reason')" :value="$record->cancel_reason" class="col-12" />
                            @endif
                        </div>
                    @else
                        <div class="text-600">{{ __('purchase_invoices.messages.audit_available_after_save') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $record, 'attachmentsReadonly' => $isReadonly])
</form>
@endsection

@push('scripts')
    <script>
        window.purchaseInvoiceMessages = @json(__('purchase_invoices.js'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/purchase-invoices.js') }}"></script>
    <script>
        document.addEventListener('change', function (event) {
            const treatment = event.target.closest('.js-asset-treatment');
            if (!treatment) return;
            treatment.closest('.js-asset-treatment-editor')?.querySelector('.js-asset-improvement-fields')?.classList.toggle('d-none', treatment.value !== 'capital_improvement');
        });
        document.addEventListener('click', function (event) {
            const button = event.target.closest('.js-save-asset-treatment');
            if (!button) return;
            const editor = button.closest('.js-asset-treatment-editor');
            const treatment = editor.querySelector('.js-asset-treatment').value;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = editor.dataset.endpoint;
            const values = {
                _token: @json(csrf_token()),
                line_public_id: editor.dataset.linePublicId,
                asset_treatment: treatment,
                target_fixed_asset_doc_num: treatment === 'capital_improvement' ? (editor.querySelector('.js-asset-improvement-target')?.value || '') : '',
                asset_effective_date: treatment === 'capital_improvement' ? (editor.querySelector('.js-asset-improvement-date')?.value || '') : ''
            };
            Object.entries(values).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = name; input.value = value;
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        });
    </script>
@endpush
