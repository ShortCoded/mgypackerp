@extends('layouts.app')

@php
    $isCreateLike = $mode === 'create';
    $isReadonly = $mode === 'view' || (! $isCreateLike && ($record?->isLockedForEditing() ?? false));
    $title = __("purchase_orders.{$mode}");
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $routePrefix = 'admin.purchases.purchase-orders';
    $value = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $dateValue = fn ($field, $default = null) => old($field, $dates->formatDate($default, ''));
    $plainDate = fn ($date) => $dates->formatDate($date, __('common.empty_value'));
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $selectedSupplier = old('supplier_doc_num', $supplierOption['id'] ?? '');
    $selectedCurrency = old('currency_doc_num', $currencyOption['id'] ?? '');
    $selectedStore = old('branch_store_uuid', $storeOption['id'] ?? '');
    $lineRows = old('lines', $lines ?? []);
    if (! is_array($lineRows) || $lineRows === []) {
        $lineRows = [[]];
    }
@endphp

@section('title', $title)

@push('styles')
    <style>
        .purchase-order-product-picker {
            min-width: 18rem;
        }

        .purchase-order-line-image {
            width: 2rem;
            height: 2rem;
            object-fit: cover;
        }

        .purchase-order-total-box {
            max-width: 24rem;
        }

        @media (max-width: 767.98px) {
            .purchase-order-product-picker {
                min-width: 14rem;
            }
        }
    </style>
@endpush

@section('content')
    <form class="js-purchase-order-form"
        action="{{ $action }}"
        method="{{ $method }}"
        data-mode="{{ $mode }}"
        data-readonly="{{ $isReadonly ? '1' : '0' }}"
        data-product-url="{{ route('admin.purchases.select2.products') }}"
        data-primary-focus="document_date"
        novalidate>
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">

        <div class="card mb-3">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ $title }}</h5>
                    </div>
                <div class="col-auto">
                    @include('modules.finance.partials.form-actions', [
                            'resource' => 'purchase_orders',
                            'routePrefix' => $routePrefix,
                            'canEditRecord' => ! ($record?->isLockedForEditing() ?? false),
                            'canDeleteRecord' => $record?->isDeletable() ?? false,
                    ])
                    @if($mode === 'view' && $record?->isApproved())
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            @can('purchases.purchase_order_delivery_schedule.create')
                            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-order-delivery-schedule.create', $record->doc_num) }}">{{ __('Delivery schedule') }}</a>
                            @endcan
                            @can('purchases.goods_receipt_notes.create')
                            @if((float) $record->total_remaining_quantity > 0)
                                <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.goods-receipt-notes.create', $record->doc_num) }}">{{ __('Receive') }}</a>
                            @endif
                            @endcan
                            @can('purchases.purchase_order_change_requests.create')
                            @if(! $record->hasReceipts())
                                <a class="btn btn-falcon-warning btn-sm" href="{{ route('admin.purchases.purchase-order-change-requests.create', $record->doc_num) }}">{{ __('Request change') }}</a>
                            @endif
                            @endcan
                        </div>
                    @endif
                </div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert d-none js-form-alert">
                    <div class="js-form-alert-message"></div>
                </div>

                @if(! $isCreateLike && $record?->isLockedForEditing() && $mode !== 'view')
                    <div class="alert alert-warning">{{ __('purchase_orders.messages.document_locked') }}</div>
                @endif

                <h6 class="text-700 mb-3">{{ __('purchase_orders.sections.header') }}</h6>
                <div class="row g-3">
                    @if($canControlDocumentNumber)
                        <div class="col-md-3 col-xl-2">
                            <label class="form-label" for="doc_number">{{ __('purchase_orders.attributes.doc_number') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="1" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-3 col-xl-2">
                            <x-forms.view-field for="doc_num" :label="__('purchase_orders.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center dt-code-value" dir="ltr" />
                        </div>
                    @endif

                    @if(! $isCreateLike)
                        <div class="col-md-3 col-xl-2">
                            <x-forms.view-field for="status" :label="__('purchase_orders.attributes.status')" as="display">
                                @include('modules.purchases.purchase-orders.partials.status', ['record' => $record])
                            </x-forms.view-field>
                        </div>
                    @endif

                    <div class="col-md-3">
                        <x-forms.label for="document_date" :label="__('purchase_orders.attributes.document_date')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="document_date" :value="$plainDate($record?->document_date)" dir="ltr" input-class="date-value text-center" />
                        @else
                            <input class="form-control text-center js-date-picker" id="document_date" name="document_date" type="text" value="{{ $dateValue('document_date', $isCreateLike ? now() : $record?->document_date) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="document_date"></div>
                    </div>

                    <div class="col-md-5">
                        <x-forms.label for="supplier_doc_num" :label="__('purchase_orders.attributes.supplier')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="supplier_doc_num" :value="$supplierOption['text'] ?? __('common.empty_value')" />
                        @else
                            <select class="form-select js-select2-ajax" id="supplier_doc_num" name="supplier_doc_num" data-url="{{ route('admin.purchases.select2.suppliers') }}" data-placeholder="{{ __('purchase_orders.placeholders.supplier') }}" data-allow-clear="true" required>
                                @if($selectedSupplier)
                                    <option value="{{ $selectedSupplier }}" selected>{{ $supplierOption['text'] ?? $selectedSupplier }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="supplier_doc_num"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="branch_store_uuid" :label="__('purchase_orders.attributes.branch_store')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="branch_store_uuid" :value="$storeOption['text'] ?? __('common.empty_value')" />
                        @else
                            <select class="form-select js-select2-ajax" id="branch_store_uuid" name="branch_store_uuid" data-url="{{ route('admin.purchases.select2.branch-stores') }}" data-placeholder="{{ __('purchase_orders.placeholders.branch_store') }}" data-allow-clear="true" required>
                                @if($selectedStore)
                                    <option value="{{ $selectedStore }}" selected>{{ $storeOption['text'] ?? $selectedStore }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_store_uuid"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="currency_doc_num" :label="__('purchase_orders.attributes.currency')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="currency_doc_num" :value="$currencyOption['text'] ?? __('common.empty_value')" />
                        @else
                            <select class="form-select js-select2-ajax js-purchase-order-currency" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.purchases.select2.currencies') }}" data-placeholder="{{ __('purchase_orders.placeholders.currency') }}" data-allow-clear="true" required>
                                @if($selectedCurrency)
                                    <option value="{{ $selectedCurrency }}" data-is-main="{{ ($currencyOption['is_main'] ?? false) ? '1' : '0' }}" selected>{{ $currencyOption['text'] ?? $selectedCurrency }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                    </div>

                    <div class="col-md-2">
                        <x-forms.label for="exchange_rate" :label="__('purchase_orders.attributes.exchange_rate')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="exchange_rate" :value="$numbers->format($record?->exchange_rate ?? 1)" dir="ltr" input-class="text-center" />
                        @else
                            <x-forms.numeric-input class="text-center" id="exchange_rate" name="exchange_rate" :value="old('exchange_rate', $record?->exchange_rate ?? 1)" :scale="6" min="0.000001" step="0.000001" required />
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="freight_amount">{{ __('Freight') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="freight_amount" :value="$numbers->format($record?->freight_amount ?? 0)" dir="ltr" input-class="text-end" />
                        @else
                            <x-forms.numeric-input class="text-end" id="freight_amount" name="freight_amount" :value="old('freight_amount', $record?->freight_amount ?? 0)" :scale="4" min="0" step="0.0001" />
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="freight_amount"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="expected_delivery_date">{{ __('purchase_orders.attributes.expected_delivery_date') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="expected_delivery_date" :value="$plainDate($record?->expected_delivery_date)" dir="ltr" input-class="date-value text-center" />
                        @else
                            <input class="form-control text-center js-date-picker" id="expected_delivery_date" name="expected_delivery_date" type="text" value="{{ $dateValue('expected_delivery_date', $record?->expected_delivery_date) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr">
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="expected_delivery_date"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="supplier_reference">{{ __('purchase_orders.attributes.supplier_reference') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="supplier_reference" :value="$record?->supplier_reference ?: __('common.empty_value')" />
                        @else
                            <input class="form-control" id="supplier_reference" name="supplier_reference" value="{{ $value('supplier_reference') }}" maxlength="120" autocomplete="off">
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="supplier_reference"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="payment_terms">{{ __('Payment terms snapshot') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="payment_terms" :value="$record?->payment_terms ?: __('common.empty_value')" />
                        @else
                            <input class="form-control" id="payment_terms" name="payment_terms" value="{{ old('payment_terms', $record?->payment_terms) }}" maxlength="255" placeholder="{{ __('Defaults from Supplier when blank') }}">
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="payment_terms"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('purchase_orders.attributes.notes') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="notes" as="textarea" :value="$record?->notes ?: __('common.empty_value')" />
                        @else
                            <textarea class="form-control" id="notes" name="notes" rows="2">{{ $value('notes') }}</textarea>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="notes"></div>
                    </div>
                    @if($isCreateLike && ! $record?->purchase_requisition_id && auth()->user()?->can('purchases.direct_procurement.override'))
                        <div class="col-md-4">
                            <div class="form-check mt-4">
                                <input class="form-check-input" id="direct_procurement_override" name="direct_procurement_override" type="checkbox" value="1" @checked(old('direct_procurement_override'))>
                                <label class="form-check-label" for="direct_procurement_override">{{ __('Authorized direct procurement override') }}</label>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="direct_procurement_reason">{{ __('Direct procurement reason') }}</label>
                            <input class="form-control" id="direct_procurement_reason" name="direct_procurement_reason" value="{{ old('direct_procurement_reason') }}">
                            <div class="invalid-feedback d-block" data-error-for="direct_procurement_reason"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        @if($mode === 'view')
            <div class="card mb-3">
                <div class="card-header py-2"><h6 class="mb-0">{{ __('Document lineage') }}</h6></div>
                <div class="card-body py-2 d-flex flex-wrap gap-2">
                    @can('purchases.purchase_requisitions.view')
                        @if($record->requisition)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-requisitions.show', $record->requisition->doc_num) }}">{{ __('Purchase Requisition') }}: <span dir="ltr">{{ $record->requisition->doc_num }}</span></a>@endif
                    @endcan
                    @can('purchases.request_for_quotations.view')
                        @if($record->requestForQuotation)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.request-for-quotations.show', $record->requestForQuotation->doc_num) }}">{{ __('RFQ') }}: <span dir="ltr">{{ $record->requestForQuotation->doc_num }}</span></a>@endif
                    @endcan
                    @can('purchases.supplier_quotation_entry.view')
                        @if($record->supplierQuotation)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-quotation-entry.show', $record->supplierQuotation->doc_num) }}">{{ __('Supplier Quotation') }}: <span dir="ltr">{{ $record->supplierQuotation->doc_num }}</span></a>@endif
                    @endcan
                    @can('purchases.supplier_selection.view')
                        @if($record->supplierSelection)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-selection.show', $record->supplierSelection->doc_num) }}">{{ __('Supplier Selection') }}: <span dir="ltr">{{ $record->supplierSelection->doc_num }}</span></a>@endif
                    @endcan
                    @can('purchases.goods_receipt_notes.view')
                        @foreach($record->receipts as $receipt)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.goods-receipt-notes.show', $receipt->doc_num) }}">{{ __('GRN') }}: <span dir="ltr">{{ $receipt->doc_num }}</span></a>@endforeach
                    @endcan
                    @can('purchase_invoices.view')
                        @foreach($record->purchaseInvoices as $invoice)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-invoices.show', $invoice->doc_num) }}">{{ __('Purchase Invoice') }}: <span dir="ltr">{{ $invoice->doc_num }}</span></a>@endforeach
                    @endcan
                    @can('purchases.purchase_returns.view')
                        @foreach($record->purchaseReturns as $return)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-returns.show', $return->doc_num) }}">{{ __('Purchase Return') }}: <span dir="ltr">{{ $return->doc_num }}</span></a>@endforeach
                    @endcan
                    @can('supplier_payments.view')
                        @foreach($record->supplierPayments as $payment)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-payments.show', $payment->doc_num) }}">{{ __('Supplier Payment') }}: <span dir="ltr">{{ $payment->doc_num }}</span></a>@endforeach
                    @endcan
                </div>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h6 class="mb-0">{{ __('purchase_orders.sections.lines') }}</h6>
                @unless($isReadonly)
                    <button class="btn btn-falcon-primary btn-sm js-purchase-order-add-line" type="button">
                        <span class="fas fa-plus me-1"></span>{{ __('purchase_orders.actions.add_line') }}
                    </button>
                @endunless
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle purchase-order-lines-table">
                        <thead class="bg-100 text-900">
                            <tr>
                                <th style="width: 3rem;">#</th>
                                <th>{{ __('purchase_orders.attributes.product') }}</th>
                                <th style="width: 13rem;">{{ __('purchase_orders.attributes.unit') }}</th>
                                <th class="text-end" style="width: 9rem;">{{ __('purchase_orders.attributes.ordered_quantity') }}</th>
                                <th class="text-end" style="width: 9rem;">{{ __('purchase_orders.attributes.unit_price') }}</th>
                                <th style="width: 8rem;">{{ __('Discount type') }}</th>
                                <th class="text-end" style="width: 8rem;">{{ __('Discount') }}</th>
                                <th class="text-end" style="width: 8rem;">{{ __('Tax %') }}</th>
                                <th class="text-end" style="width: 9rem;">{{ __('purchase_orders.attributes.line_total') }}</th>
                                <th class="text-end" style="width: 8rem;">{{ __('purchase_orders.attributes.received_quantity') }}</th>
                                <th class="text-end" style="width: 8rem;">{{ __('purchase_orders.attributes.remaining_quantity') }}</th>
                                <th style="width: 12rem;">{{ __('purchase_orders.attributes.line_notes') }}</th>
                                @unless($isReadonly)
                                    <th class="text-center" style="width: 4rem;"></th>
                                @endunless
                            </tr>
                        </thead>
                        <tbody class="js-purchase-order-lines">
                            @foreach($lineRows as $index => $line)
                                @php
                                    $productDocNum = $line['product_doc_num'] ?? null;
                                    $productText = $line['product_text'] ?? $line['product_label'] ?? $productDocNum;
                                    $unitDocNum = $line['unit_doc_num'] ?? null;
                                    $unitText = $line['unit_text'] ?? $line['unit'] ?? $unitDocNum;
                                    $unitOptions = $line['unit_options'] ?? [];
                                    $imageUrl = $line['product_image_url'] ?? $line['imageUrl'] ?? null;
                                @endphp
                                <tr class="js-purchase-order-line">
                                    <td class="text-center text-700 js-line-number">{{ $index + 1 }}</td>
                                    <td class="purchase-order-product-picker">
                                        <input type="hidden" name="lines[{{ $index }}][public_id]" value="{{ $line['public_id'] ?? '' }}">
                                        @if($isReadonly)
                                            <div class="d-flex align-items-center gap-2">
                                                @if($imageUrl)
                                                    <img class="rounded purchase-order-line-image" src="{{ $imageUrl }}" alt="">
                                                @endif
                                                <span>{{ $productText ?: __('common.empty_value') }}</span>
                                            </div>
                                        @else
                                            <select class="form-select js-select2-ajax js-purchase-order-product" name="lines[{{ $index }}][product_doc_num]" data-url="{{ route('admin.purchases.select2.products') }}" data-placeholder="{{ __('purchase_orders.placeholders.product') }}" data-allow-clear="true" required>
                                                @if($productDocNum)
                                                    <option value="{{ $productDocNum }}" data-unit-options='@json($unitOptions)' data-image-url="{{ $imageUrl }}" selected>{{ $productText ?: $productDocNum }}</option>
                                                @endif
                                            </select>
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <span>{{ $unitText ?: __('common.empty_value') }}</span>
                                        @else
                                            <select class="form-select js-purchase-order-unit" name="lines[{{ $index }}][unit_doc_num]" data-placeholder="{{ __('purchase_orders.placeholders.unit') }}" required>
                                                @if($unitDocNum)
                                                    <option value="{{ $unitDocNum }}" selected>{{ $unitText ?: $unitDocNum }}</option>
                                                @endif
                                            </select>
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_doc_num"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="text-end" dir="ltr">{{ $numbers->format($line['ordered_quantity'] ?? 0) }}</div>
                                        @else
                                            <x-forms.numeric-input class="text-end js-line-quantity" :name="'lines['.$index.'][ordered_quantity]'" :value="$line['ordered_quantity'] ?? ''" :scale="8" min="0.00000001" step="0.00000001" required />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.ordered_quantity"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="text-end" dir="ltr">{{ $numbers->format($line['unit_price'] ?? 0) }}</div>
                                        @else
                                            <x-forms.numeric-input class="text-end js-line-unit-price" :name="'lines['.$index.'][unit_price]'" :value="$line['unit_price'] ?? ''" :scale="4" min="0" step="0.0001" required />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_price"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <span>{{ str($line['discount_type'] ?? 'fixed')->title() }}</span>
                                        @else
                                            <select class="form-select js-line-discount-type" name="lines[{{ $index }}][discount_type]">
                                                <option value="fixed" @selected(($line['discount_type'] ?? 'fixed') === 'fixed')>{{ __('Fixed') }}</option>
                                                <option value="percentage" @selected(($line['discount_type'] ?? null) === 'percentage')>{{ __('Percentage') }}</option>
                                            </select>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="text-end" dir="ltr">{{ $numbers->format($line['discount_amount'] ?? 0) }}</div>
                                        @else
                                            <x-forms.numeric-input class="text-end js-line-discount-value" :name="'lines['.$index.'][discount_value]'" :value="$line['discount_value'] ?? 0" :scale="4" min="0" step="0.0001" />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.discount_value"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="text-end" dir="ltr">{{ $numbers->format($line['tax_rate'] ?? 0) }}</div>
                                        @else
                                            <x-forms.numeric-input class="text-end js-line-tax-rate" :name="'lines['.$index.'][tax_rate]'" :value="$line['tax_rate'] ?? 0" :scale="4" min="0" max="100" step="0.0001" />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.tax_rate"></div>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold js-line-total" dir="ltr">{{ $numbers->format($line['line_total'] ?? 0) }}</td>
                                    <td class="text-end text-700 js-line-received" dir="ltr">{{ $numbers->format($line['received_quantity'] ?? 0) }}</td>
                                    <td class="text-end text-700 js-line-remaining" dir="ltr">{{ $numbers->format($line['remaining_quantity'] ?? 0) }}</td>
                                    <td>
                                        @if($isReadonly)
                                            <span>{{ $line['notes'] ?? __('common.empty_value') }}</span>
                                        @else
                                            <input class="form-control" name="lines[{{ $index }}][notes]" value="{{ $line['notes'] ?? '' }}">
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.notes"></div>
                                        @endif
                                    </td>
                                    @unless($isReadonly)
                                        <td class="text-center">
                                            <button class="btn btn-falcon-danger btn-sm js-purchase-order-remove-line" type="button" aria-label="{{ __('common.actions.delete') }}">
                                                <span class="fas fa-trash-alt"></span>
                                            </button>
                                        </td>
                                    @endunless
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="invalid-feedback d-block px-3 py-2" data-error-for="lines"></div>
            </div>
        </div>

        <div class="row g-3 align-items-start">
            <div class="col-lg-6">
                @if(! $isCreateLike && $record?->cancel_reason)
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="text-danger">{{ __('purchase_orders.attributes.cancel_reason') }}</h6>
                            <p class="mb-0">{{ $record->cancel_reason }}</p>
                        </div>
                    </div>
                @endif

                @if($mode === 'view')
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="text-700 mb-3">{{ __('purchase_orders.tabs.audit') }}</h6>
                            <dl class="row mb-0">
                                @foreach(['created', 'updated', 'approved', 'closed', 'cancelled'] as $key)
                                    <dt class="col-sm-4">{{ __('purchase_orders.audit.'.$key) }}</dt>
                                    <dd class="col-sm-8">{{ trim(implode(' / ', array_filter([$metadata[$key.'_by'] ?? null, $metadata[$key.'_at'] ?? null]))) ?: __('common.empty_value') }}</dd>
                                @endforeach
                            </dl>
                        </div>
                    </div>
                @endif
            </div>
            <div class="col-lg-6">
                <div class="card purchase-order-total-box ms-lg-auto">
                    <div class="card-body">
                        <h6 class="text-700 mb-3">{{ __('purchase_orders.sections.totals') }}</h6>
                        <div class="d-flex justify-content-between mb-2">
                            <span>{{ __('purchase_orders.totals.ordered_quantity') }}</span>
                            <strong class="js-total-ordered" dir="ltr">{{ $numbers->format($record?->total_ordered_quantity ?? 0) }}</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>{{ __('purchase_orders.totals.received_quantity') }}</span>
                            <strong class="js-total-received" dir="ltr">{{ $numbers->format($record?->total_received_quantity ?? 0) }}</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>{{ __('purchase_orders.totals.remaining_quantity') }}</span>
                            <strong class="js-total-remaining" dir="ltr">{{ $numbers->format($record?->total_remaining_quantity ?? 0) }}</strong>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between fs-8">
                            <span>{{ __('purchase_orders.totals.net_total') }}</span>
                            <strong class="js-total-amount" dir="ltr">{{ $numbers->format($record?->total_amount ?? 0) }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <template id="purchase-order-line-template">
        <tr class="js-purchase-order-line">
            <td class="text-center text-700 js-line-number">__NUMBER__</td>
            <td class="purchase-order-product-picker">
                <input type="hidden" name="lines[__INDEX__][public_id]" value="">
                <select class="form-select js-select2-ajax js-purchase-order-product" name="lines[__INDEX__][product_doc_num]" data-url="{{ route('admin.purchases.select2.products') }}" data-placeholder="{{ __('purchase_orders.placeholders.product') }}" data-allow-clear="true" required></select>
                <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.product_doc_num"></div>
            </td>
            <td>
                <select class="form-select js-purchase-order-unit" name="lines[__INDEX__][unit_doc_num]" data-placeholder="{{ __('purchase_orders.placeholders.unit') }}" required></select>
                <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.unit_doc_num"></div>
            </td>
            <td>
                <x-forms.numeric-input class="text-end js-line-quantity" name="lines[__INDEX__][ordered_quantity]" :scale="8" min="0.00000001" step="0.00000001" required />
                <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.ordered_quantity"></div>
            </td>
            <td>
                <x-forms.numeric-input class="text-end js-line-unit-price" name="lines[__INDEX__][unit_price]" :scale="4" min="0" step="0.0001" required />
                <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.unit_price"></div>
            </td>
            <td>
                <select class="form-select js-line-discount-type" name="lines[__INDEX__][discount_type]"><option value="fixed">{{ __('Fixed') }}</option><option value="percentage">{{ __('Percentage') }}</option></select>
            </td>
            <td>
                <x-forms.numeric-input class="text-end js-line-discount-value" name="lines[__INDEX__][discount_value]" :scale="4" min="0" step="0.0001" value="0" />
                <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.discount_value"></div>
            </td>
            <td>
                <x-forms.numeric-input class="text-end js-line-tax-rate" name="lines[__INDEX__][tax_rate]" :scale="4" min="0" max="100" step="0.0001" value="0" />
                <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.tax_rate"></div>
            </td>
            <td class="text-end fw-semibold js-line-total">0</td>
            <td class="text-end text-700 js-line-received">0</td>
            <td class="text-end text-700 js-line-remaining">0</td>
            <td>
                <input class="form-control" name="lines[__INDEX__][notes]">
                <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.notes"></div>
            </td>
            <td class="text-center">
                <button class="btn btn-falcon-danger btn-sm js-purchase-order-remove-line" type="button" aria-label="{{ __('common.actions.delete') }}">
                    <span class="fas fa-trash-alt"></span>
                </button>
            </td>
        </tr>
    </template>
@endsection

@push('scripts')
    <script>
        window.purchaseOrderMessages = @json(__('purchase_orders.js'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Purchases/purchase-orders.js') }}"></script>
@endpush
