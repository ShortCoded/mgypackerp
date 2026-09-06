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
        method="POST"
        data-mode="{{ $mode }}"
        data-readonly="{{ $isReadonly ? '1' : '0' }}"
        data-product-url="{{ route('admin.purchases.select2.products') }}" data-source-url="{{ route('admin.purchases.purchase-orders.create') }}" data-currency-rate-url="{{ route('admin.purchases.select2.currency-rate') }}"
        data-primary-focus="document_date"
        novalidate>
        @csrf
        <x-forms.line-item-cards />
        @if($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">

        <div class="card mb-3">
            <div class="card-header py-2">
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
                            @can('purchases.supplier_quotation_entry.create')
                            @can('purchases.prices.view')
                            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-quotation-entry.create-source', [\Modules\Purchases\Models\SupplierQuotation::SourcePurchaseOrder, $record->doc_num]) }}">{{ __('Enter supplier quotation') }}</a>
                            @endcan
                            @endcan
                            @can('purchase_invoices.create')
                            <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.purchase-invoices.create', ['purchase_order' => $record->doc_num]) }}">{{ __('Create supplier invoice') }}</a>
                            @endcan
                            @can('purchases.purchase_order_delivery_schedule.create')
                            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-order-delivery-schedule.create', $record->doc_num) }}">{{ __('Delivery schedule') }}</a>
                            @endcan
                            @can('purchases.supply_orders.create')
                            @if((float) $record->total_remaining_quantity > 0)
                                <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.supply-orders.create', ['purchase_order' => $record->doc_num]) }}">{{ __('Create Supply Order') }}</a>
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

                @unless($isReadonly)
                <div class="mb-3">
                    <label class="form-label" for="purchase_requisition_doc_nums">{{ __('procurement.ui.source_requisitions') }}</label>
                    <select id="purchase_requisition_doc_nums" class="form-select js-select2-ajax js-order-requisitions" name="purchase_requisition_doc_nums[]" multiple data-url="{{ route('admin.purchases.select2.requisitions') }}" data-placeholder="{{ __('procurement.ui.select_approved_requests') }}">
                        @foreach($sourceRequests ?? [] as $sourceRequest)<option selected value="{{ $sourceRequest->doc_num }}">{{ $sourceRequest->doc_num }}</option>@endforeach
                    </select>
                    <div class="form-text" data-source-loading aria-live="polite"></div>
                </div>
                @endunless
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
                        <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div><div class="form-text" data-rate-source></div>
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
                        <label class="form-label" for="payment_terms">{{ __('purchase_orders.attributes.payment_terms') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="payment_terms" :value="$record?->payment_terms ?: __('common.empty_value')" />
                        @else
                            <input class="form-control" id="payment_terms" name="payment_terms" value="{{ old('payment_terms', $record?->payment_terms) }}" maxlength="255" placeholder="{{ __('purchase_orders.placeholders.payment_terms_supplier_default') }}">
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
                </div>
            </div>
        </div>

        @if($mode === 'view')
            @if($record->isApproved() && ! $record->sent_at)
            @can('purchase_orders.send')
            <button type="submit" form="purchase-order-mark-sent" class="btn btn-outline-primary mb-3">{{ __('Mark as sent') }}</button>
            @endcan
            @endif
            @include('modules.purchases.procurement.document-cycle', ['record' => $record])
        @endif

        <div class="card mb-3">
            <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
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
                                <th style="width: 8rem;">{{ __('purchase_orders.attributes.discount_type') }}</th>
                                <th class="text-end" style="width: 8rem;">{{ __('purchase_orders.attributes.discount') }}</th>
                                <th class="text-end" style="width: 8rem;">{{ __('purchase_orders.attributes.tax_rate') }}</th>
                                <th class="text-end" style="width: 9rem;">{{ __('purchase_orders.attributes.line_total') }}</th>
                                <th class="text-end" style="width: 8rem;">{{ __('purchase_orders.attributes.received_quantity') }}</th>
                                <th class="text-end" style="width: 8rem;">{{ __('purchase_orders.attributes.remaining_quantity') }}</th>
                                <th style="width: 12rem;">{{ __('purchase_orders.attributes.line_notes') }}</th>
                                <th style="width: 12rem;">{{ __('Attachments') }}</th>
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
                                    $attachmentLine = $record?->lines?->firstWhere('public_id', $line['public_id'] ?? null);
                                @endphp
                                <tr class="js-purchase-order-line">
                                    <td class="text-center text-700 js-line-number">{{ $index + 1 }}</td>
                                    <td class="purchase-order-product-picker">
                                        <input type="hidden" name="lines[{{ $index }}][purchase_requisition_line_id]" value="{{ $line['purchase_requisition_line_id'] ?? '' }}">
                                        @if(filled($line['source_doc_num'] ?? null))<div class="small text-600" dir="ltr">{{ $line['source_doc_num'] }}</div>@endif
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
                                            <select class="form-select js-select2-local js-purchase-order-unit" name="lines[{{ $index }}][unit_doc_num]" data-placeholder="{{ __('purchase_orders.placeholders.unit') }}" required>
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
                                            <span>{{ __(str($line['discount_type'] ?? 'fixed')->replace('_', ' ')->title()->toString()) }}</span>
                                        @else
                                            <select class="form-select js-line-discount-type" name="lines[{{ $index }}][discount_type]">
                                                <option value="fixed" @selected(($line['discount_type'] ?? 'fixed') === 'fixed')>{{ __('purchase_orders.discount_types.fixed') }}</option>
                                                <option value="percentage" @selected(($line['discount_type'] ?? null) === 'percentage')>{{ __('purchase_orders.discount_types.percentage') }}</option>
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
                                    <td>
                                        @include('modules.purchases.procurement.line-attachments', [
                                            'attachmentLine' => $attachmentLine,
                                            'attachmentCompanyId' => $record?->company_id ?? $context['company_id'],
                                            'index' => $index,
                                            'lineAttachmentsReadonly' => $isReadonly,
                                        ])
                                    </td>
                                    @unless($isReadonly)
                                        <td class="text-center">
                                            @if(empty($line['purchase_requisition_line_id']))<button class="btn btn-falcon-default btn-sm js-purchase-order-duplicate-line" type="button" aria-label="{{ __('Duplicate line') }}"><span class="fas fa-copy"></span></button>@endif<button class="btn btn-falcon-danger btn-sm js-purchase-order-remove-line" type="button" aria-label="{{ __('common.actions.delete') }}">
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
        @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $record, 'attachmentsReadonly' => $isReadonly])
    </form>
    @if($mode === 'view' && $record && in_array($record->status, ['draft', 'submitted', 'rejected'], true))
    <section class="card mb-3"><div class="card-body">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="d-flex flex-wrap gap-2">
        @if($record->isDraft())
            @can('purchase_orders.submit')<form method="POST" action="{{ route('admin.purchases.purchase-orders.submit', $record->doc_num) }}">@csrf<button class="btn btn-primary">{{ __('Submit for approval') }}</button></form>@endcan
        @elseif($record->status === 'submitted')
            @can('purchase_orders.approve')<button type="button" class="btn btn-success js-purchase-order-row-action" data-url="{{ route('admin.purchases.purchase-orders.approve', $record->doc_num) }}" data-method="POST" data-action="approve">{{ __('Approve') }}</button>@endcan
            @can('purchase_orders.reject')<form class="d-flex flex-wrap gap-2" method="POST" action="{{ route('admin.purchases.purchase-orders.reject', $record->doc_num) }}">@csrf<input class="form-control" name="reason" required placeholder="{{ __('Rejection reason') }}" aria-label="{{ __('Rejection reason') }}"><button class="btn btn-outline-danger">{{ __('Reject') }}</button></form>@endcan
        @else
            <div class="alert alert-warning mb-0">{{ $record->rejection_reason }}</div>
        @endif
        </div>
        @if($record->submitted_at)<div class="small text-600 mt-2">{{ __('Submitted at') }}: {{ $dates->formatDateTime($record->submitted_at) }}</div>@endif
    </div></section>
    @endif
    @if($record?->isApproved() && ! $record->sent_at)
    @can('purchase_orders.send')
    <form id="purchase-order-mark-sent" method="POST" action="{{ route('admin.purchases.purchase-orders.sent', $record->doc_num) }}">@csrf</form>
    @endcan
    @endif

    <template id="purchase-order-line-template">
        <tr class="js-purchase-order-line">
            <td class="text-center text-700 js-line-number">__NUMBER__</td>
            <td class="purchase-order-product-picker">
                <input type="hidden" name="lines[__INDEX__][public_id]" value=""><input type="hidden" name="lines[__INDEX__][purchase_requisition_line_id]" value=""><div class="small text-600" data-source-label></div>
                <select class="form-select js-select2-ajax js-purchase-order-product" name="lines[__INDEX__][product_doc_num]" data-url="{{ route('admin.purchases.select2.products') }}" data-placeholder="{{ __('purchase_orders.placeholders.product') }}" data-allow-clear="true" required></select>
                <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.product_doc_num"></div>
            </td>
            <td>
                <select class="form-select js-select2-local js-purchase-order-unit" name="lines[__INDEX__][unit_doc_num]" data-placeholder="{{ __('purchase_orders.placeholders.unit') }}" required></select>
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
                <select class="form-select js-line-discount-type" name="lines[__INDEX__][discount_type]"><option value="fixed">{{ __('purchase_orders.discount_types.fixed') }}</option><option value="percentage">{{ __('purchase_orders.discount_types.percentage') }}</option></select>
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
            <td>
                @include('modules.purchases.procurement.line-attachments', [
                    'attachmentLine' => null,
                    'attachmentCompanyId' => $record?->company_id ?? $context['company_id'],
                    'index' => '__INDEX__',
                ])
            </td>
            <td class="text-center">
                <button class="btn btn-falcon-default btn-sm js-purchase-order-duplicate-line" type="button" aria-label="{{ __('Duplicate line') }}"><span class="fas fa-copy"></span></button><button class="btn btn-falcon-danger btn-sm js-purchase-order-remove-line" type="button" aria-label="{{ __('common.actions.delete') }}">
                    <span class="fas fa-trash-alt"></span>
                </button>
            </td>
        </tr>
    </template>
@endsection

@push('scripts')
    <script>
        window.purchaseOrderMessages = {{ \Illuminate\Support\Js::from([...__('purchase_orders.js'), 'source_loading' => __('procurement.ui.loading_lines'), 'source_loaded' => __('procurement.ui.lines_loaded'), 'rate_source' => __('procurement.ui.previous_rate'), 'rate_required' => __('procurement.ui.enter_rate')]) }};
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/purchase-orders.js') }}"></script>
@endpush
