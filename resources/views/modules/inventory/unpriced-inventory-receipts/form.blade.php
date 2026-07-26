@extends('layouts.app')

@php
    $isCreateLike = $mode === 'create';
    $isReadonly = $mode === 'view' || (! $isCreateLike && ($isLocked ?? false));
    $title = __("inventory.unpriced_inventory_receipts.{$mode}");
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $value = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $selectedBranchDocNum = old('branch_doc_num', $branchOption['id'] ?? '');
    $selectedHallUuid = old('branch_hall_uuid', $hallOption['id'] ?? '');
    $selectedStoreUuid = old('branch_store_uuid', $storeOption['id'] ?? '');
    $selectedSupplierDocNum = old('supplier_doc_num', $supplierOption['id'] ?? '');
    $existingLines = old('lines', $lines ?? []);
    if (! is_array($existingLines) || $existingLines === []) {
        $existingLines = [['public_id' => null, 'product_doc_num' => null, 'product_label' => null, 'unit_doc_num' => null, 'unit' => null, 'unit_options' => [], 'imageUrl' => null, 'quantity' => null, 'notes' => null]];
    }
    $routePrefix = 'admin.inventory.unpriced-inventory-receipts';
@endphp

@section('title', $title)

@push('styles')
    <style>
        .unpriced-receipt-product-image {
            max-height: 60vh;
            object-fit: contain;
        }

        .unpriced-receipt-product-details dt {
            color: var(--falcon-700);
            font-weight: 600;
        }

        .unpriced-receipt-product-picker {
            min-width: 18rem;
        }

        .unpriced-receipt-product-picker .select2-container,
        .unpriced-receipt-unit-picker .select2-container {
            min-width: 0;
        }

        .unpriced-receipt-product-action {
            line-height: 1;
            min-height: calc(1.5em + .625rem + 2px);
            min-width: calc(1.5em + .625rem + 2px);
        }

        @media (max-width: 767.98px) {
            .unpriced-receipt-product-picker {
                min-width: 14rem;
            }
        }
    </style>
@endpush

@section('content')
    <form class="js-unpriced-inventory-receipt-form"
        action="{{ $action }}"
        method="{{ $method }}"
        data-mode="{{ $mode }}"
        data-products-url="{{ route('admin.inventory.select2.unpriced-inventory-receipt-products') }}"
        data-product-details-url-template="{{ route('admin.inventory.unpriced-inventory-receipts.products.details', ['product' => '__PRODUCT__']) }}"
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
                            'resource' => 'inventory.unpriced_inventory_receipts',
                            'routePrefix' => $routePrefix,
                            'canEditRecord' => ! ($record?->isLockedForEditing() ?? false),
                            'canDeleteRecord' => ! ($record?->isLockedForEditing() ?? false),
                        ])
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert d-none js-form-alert">
                    <div class="js-form-alert-message"></div>
                </div>

                @if(! $isCreateLike && $record?->isCancelled())
                    <div class="alert alert-warning">{{ __('inventory.unpriced_inventory_receipts.messages.cancelled_edit_forbidden') }}</div>
                @elseif(! $isCreateLike && $record?->isClosed())
                    <div class="alert alert-warning">{{ __('inventory.unpriced_inventory_receipts.messages.closed_edit_forbidden') }}</div>
                @elseif(! $isCreateLike && $record?->isApproved())
                    <div class="alert alert-warning">{{ __('inventory.unpriced_inventory_receipts.messages.approved_edit_forbidden') }}</div>
                @endif

                <h6 class="text-700 mb-3">{{ __('inventory.unpriced_inventory_receipts.sections.header') }}</h6>
                <div class="row g-3">
                    @if($mode === 'view')
                        <div class="col-md-3">
                            <x-forms.view-field for="status" :label="__('inventory.unpriced_inventory_receipts.attributes.status')" :value="$record?->trashed() ? __('inventory.unpriced_inventory_receipts.statuses.deleted') : __('inventory.unpriced_inventory_receipts.statuses.'.($record?->status ?? 'draft'))" />
                        </div>
                        <div class="col-md-3">
                            <x-forms.view-field for="pricing_status" :label="__('inventory.unpriced_inventory_receipts.attributes.pricing_status')" :value="__('inventory.unpriced_inventory_receipts.pricing_statuses.'.($record?->pricing_status ?? 'unpriced'))" />
                        </div>
                    @endif

                    @if($canControlDocumentNumber)
                        <div class="col-md-3">
                            <label class="form-label" for="doc_number">{{ __('inventory.unpriced_inventory_receipts.attributes.doc_number') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="1" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                                <div class="form-text">{{ __('item_lookups.document_number_control.helper') }}</div>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-3">
                            <x-forms.view-field for="doc_num" :label="__('inventory.unpriced_inventory_receipts.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                        </div>
                    @endif

                    <div class="col-md-3">
                        <x-forms.label for="document_date" :label="__('inventory.unpriced_inventory_receipts.attributes.document_date')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="document_date" :value="$dateValue" dir="ltr" input-class="date-value text-center" />
                        @else
                            <input class="form-control text-center js-date-picker" id="document_date" name="document_date" type="text" value="{{ old('document_date', $dateValue) }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="document_date"></div>
                    </div>

                    @if($mode === 'view')
                        <div class="col-md-3">
                            <x-forms.view-field for="financial_period" :label="__('inventory.unpriced_inventory_receipts.attributes.financial_period')" :value="$financialPeriodLabel ?: __('common.empty_value')" />
                            <div class="invalid-feedback d-block" data-error-for="financial_period_id"></div>
                        </div>
                    @endif

                    <div class="col-md-3">
                        <x-forms.label for="branch_doc_num" :label="__('inventory.unpriced_inventory_receipts.attributes.branch')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="branch_doc_num" as="display" :value="$branchOption['text'] ?? __('common.empty_value')" />
                        @else
                            <select class="form-select js-select2-ajax js-unpriced-inventory-receipt-branch" id="branch_doc_num" name="branch_doc_num" data-url="{{ route('admin.inventory.select2.unpriced-inventory-receipt-branches') }}" data-placeholder="{{ __('inventory.unpriced_inventory_receipts.placeholders.select_branch') }}" data-allow-clear="true" required>
                                @if($selectedBranchDocNum && $branchOption)
                                    <option value="{{ $branchOption['id'] }}" selected>{{ $branchOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_doc_num"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="branch_hall_uuid">{{ __('inventory.unpriced_inventory_receipts.attributes.hall') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="branch_hall_uuid" as="display" :value="$hallOption['text'] ?? __('common.empty_value')" />
                        @else
                            <select class="form-select js-select2-ajax" id="branch_hall_uuid" name="branch_hall_uuid" data-url="{{ route('admin.inventory.select2.unpriced-inventory-receipt-branch-halls') }}" data-placeholder="{{ __('inventory.unpriced_inventory_receipts.placeholders.select_hall') }}" data-allow-clear="true" data-extra-params='{"branch_doc_num":"#branch_doc_num"}' data-depends-on="#branch_doc_num" data-disable-when-dependency-empty="true">
                                @if($selectedHallUuid && $hallOption)
                                    <option value="{{ $hallOption['id'] }}" selected>{{ $hallOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_hall_uuid"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="branch_store_uuid">{{ __('inventory.unpriced_inventory_receipts.attributes.store') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="branch_store_uuid" as="display" :value="$storeOption['text'] ?? __('common.empty_value')" />
                        @else
                            <select class="form-select js-select2-ajax" id="branch_store_uuid" name="branch_store_uuid" data-url="{{ route('admin.inventory.select2.unpriced-inventory-receipt-branch-stores') }}" data-placeholder="{{ __('inventory.unpriced_inventory_receipts.placeholders.select_store') }}" data-allow-clear="true" data-extra-params='{"branch_doc_num":"#branch_doc_num"}' data-depends-on="#branch_doc_num" data-disable-when-dependency-empty="true">
                                @if($selectedStoreUuid && $storeOption)
                                    <option value="{{ $storeOption['id'] }}" selected>{{ $storeOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_store_uuid"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="supplier_doc_num">{{ __('inventory.unpriced_inventory_receipts.attributes.supplier') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="supplier_doc_num" as="display" :value="$supplierOption['text'] ?? __('common.empty_value')" />
                        @else
                            <select class="form-select js-select2-ajax" id="supplier_doc_num" name="supplier_doc_num" data-url="{{ route('admin.inventory.select2.unpriced-inventory-receipt-suppliers') }}" data-placeholder="{{ __('inventory.unpriced_inventory_receipts.placeholders.select_supplier') }}" data-allow-clear="true">
                                @if($selectedSupplierDocNum && $supplierOption)
                                    <option value="{{ $supplierOption['id'] }}" selected>{{ $supplierOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="form-text">{{ __('inventory.unpriced_inventory_receipts.helpers.supplier_optional') }}</div>
                        <div class="invalid-feedback d-block" data-error-for="supplier_doc_num"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="reference_number">{{ __('inventory.unpriced_inventory_receipts.attributes.reference_number') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="reference_number" as="display" :value="$record?->reference_number ?: __('common.empty_value')" />
                        @else
                            <input class="form-control" id="reference_number" name="reference_number" type="text" value="{{ old('reference_number', $value('reference_number')) }}">
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="reference_number"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="reference_date">{{ __('inventory.unpriced_inventory_receipts.attributes.reference_date') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="reference_date" :value="$referenceDateValue ?: __('common.empty_value')" dir="ltr" input-class="date-value text-center" />
                        @else
                            <input class="form-control text-center js-date-picker" id="reference_date" name="reference_date" type="text" value="{{ old('reference_date', $referenceDateValue) }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="reference_date"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('inventory.unpriced_inventory_receipts.attributes.notes') }}</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" @readonly($isReadonly)>{{ old('notes', $value('notes')) }}</textarea>
                        <div class="invalid-feedback d-block" data-error-for="notes"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h6 class="mb-0">{{ __('inventory.unpriced_inventory_receipts.sections.lines') }}</h6>
                    </div>
                    @unless($isReadonly)
                        <div class="col-auto">
                            <button class="btn btn-falcon-default btn-sm js-unpriced-inventory-receipt-add-line" type="button" title="{{ __('inventory.unpriced_inventory_receipts.js.add_line_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.add_line_title') }}">
                                <span class="fas fa-plus me-1"></span>{{ __('inventory.unpriced_inventory_receipts.actions.add_line') }}
                            </button>
                        </div>
                    @endunless
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 js-unpriced-inventory-receipt-lines">
                        <thead class="bg-200">
                            <tr>
                                <th style="width: 34%">{{ __('inventory.unpriced_inventory_receipts.attributes.product') }}</th>
                                <th style="width: 20%">{{ __('inventory.unpriced_inventory_receipts.attributes.unit') }}</th>
                                <th class="text-center" style="width: 16%">{{ __('inventory.unpriced_inventory_receipts.attributes.quantity') }}</th>
                                <th>{{ __('inventory.unpriced_inventory_receipts.attributes.line_notes') }}</th>
                                @unless($isReadonly)
                                    <th class="text-center" style="width: 76px">{{ __('common.fields.actions') }}</th>
                                @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($existingLines as $index => $line)
                                @php
                                    $productDocNum = $line['product_doc_num'] ?? null;
                                    $productLabel = $line['product_label'] ?? $productDocNum;
                                    $unitDocNum = $line['unit_doc_num'] ?? null;
                                    $imageUrl = $line['imageUrl'] ?? null;
                                    $unitOptions = is_array($line['unit_options'] ?? null) ? $line['unit_options'] : [];
                                @endphp
                                <tr class="js-unpriced-inventory-receipt-line" data-index="{{ $index }}">
                                    <td>
                                        <input type="hidden" name="lines[{{ $index }}][public_id]" value="{{ $line['public_id'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $index }}][_delete]" value="0">
                                        @if($isReadonly)
                                            <div class="d-flex align-items-center gap-1 unpriced-receipt-product-picker">
                                                <div class="form-control-plaintext flex-grow-1 min-w-0 text-truncate" title="{{ $productLabel }}">{{ $productLabel }}</div>
                                                <button class="btn btn-falcon-default btn-sm unpriced-receipt-product-action js-unpriced-inventory-receipt-product-info" type="button" title="{{ __('inventory.unpriced_inventory_receipts.js.product_info_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.product_info_title') }}" @disabled(! $productDocNum)>
                                                    <span class="fas fa-info-circle"></span>
                                                    <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.actions.view_product') }}</span>
                                                </button>
                                                <input type="hidden" class="js-unpriced-inventory-receipt-product-value" value="{{ $productDocNum }}">
                                            </div>
                                        @else
                                            <div class="d-flex align-items-start gap-1 unpriced-receipt-product-picker">
                                                <div class="flex-grow-1 min-w-0">
                                                    <select class="form-select js-select2-ajax js-unpriced-inventory-receipt-product" name="lines[{{ $index }}][product_doc_num]" data-url="{{ route('admin.inventory.select2.unpriced-inventory-receipt-products') }}" data-placeholder="{{ __('inventory.unpriced_inventory_receipts.placeholders.select_product') }}" data-allow-clear="true" data-template="product-image">
                                                        @if($productDocNum)
                                                            <option value="{{ $productDocNum }}" data-unit-options="{{ e(json_encode($unitOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) }}" @if($imageUrl) data-image-url="{{ $imageUrl }}" @endif selected>{{ $productLabel }}</option>
                                                        @endif
                                                    </select>
                                                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div>
                                                </div>
                                                <button class="btn btn-falcon-default btn-sm unpriced-receipt-product-action js-unpriced-inventory-receipt-product-info" type="button" title="{{ __('inventory.unpriced_inventory_receipts.js.product_info_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.product_info_title') }}" @disabled(! $productDocNum)>
                                                    <span class="fas fa-info-circle"></span>
                                                    <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.actions.view_product') }}</span>
                                                </button>
                                                @if($canCreateProducts)
                                                    <a class="btn btn-falcon-default btn-sm unpriced-receipt-product-action js-unpriced-inventory-receipt-product-create" href="{{ $productCreateUrl }}" target="_blank" rel="noopener" title="{{ __('inventory.unpriced_inventory_receipts.js.product_create_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.product_create_title') }}">
                                                        <span class="fas fa-plus"></span>
                                                        <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.actions.add_product') }}</span>
                                                    </a>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="form-control-plaintext">{{ $line['unit'] ?? null }}</div>
                                        @else
                                            <div class="unpriced-receipt-unit-picker">
                                                <select class="form-select js-unpriced-inventory-receipt-unit" name="lines[{{ $index }}][unit_doc_num]" @disabled(! $productDocNum)>
                                                    <option value="">{{ __('inventory.unpriced_inventory_receipts.placeholders.select_unit') }}</option>
                                                    @foreach($unitOptions as $option)
                                                        <option value="{{ $option['id'] ?? '' }}" @selected(($option['id'] ?? null) === $unitDocNum)>{{ $option['text'] ?? $option['id'] ?? '' }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_doc_num"></div>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($isReadonly)
                                            <div class="form-control-plaintext text-center" dir="ltr">{{ $numbers->format($line['quantity'] ?? 0) }}</div>
                                        @else
                                            <x-forms.numeric-input class="text-center js-unpriced-inventory-receipt-quantity" :name="'lines['.$index.'][quantity]'" :value="$line['quantity'] ?? ''" :scale="8" min="0.00000001" step="0.00000001" />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.quantity"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="form-control-plaintext">{{ $line['notes'] ?? null }}</div>
                                        @else
                                            <input class="form-control js-unpriced-inventory-receipt-line-notes" name="lines[{{ $index }}][notes]" type="text" value="{{ $line['notes'] ?? '' }}">
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.notes"></div>
                                        @endif
                                    </td>
                                    @unless($isReadonly)
                                        <td class="text-center">
                                            <button class="btn btn-link text-600 p-0 me-2 js-unpriced-inventory-receipt-duplicate-line" type="button" title="{{ __('inventory.unpriced_inventory_receipts.js.duplicate_line_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.duplicate_line_title') }}">
                                                <span class="fas fa-copy"></span>
                                                <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.js.duplicate_line_title') }}</span>
                                            </button>
                                            <button class="btn btn-link text-danger p-0 js-unpriced-inventory-receipt-remove-line" type="button" title="{{ __('inventory.unpriced_inventory_receipts.js.delete_line_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.delete_line_title') }}">
                                                <span class="fas fa-trash-alt"></span>
                                                <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.js.delete_line_title') }}</span>
                                            </button>
                                        </td>
                                    @endunless
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $isReadonly ? 4 : 5 }}" class="text-center text-600 py-3">{{ __('common.empty_value') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2">
                    <div class="invalid-feedback d-block" data-error-for="lines"></div>
                    <div class="invalid-feedback d-block" data-error-for="document"></div>
                </div>
            </div>
            <div class="card-footer">
                @if($mode === 'view' && $record && ! $record->trashed())
                    @if(! $record->isApproved() && ! $record->isClosed() && ! $record->isCancelled())
                        @can('inventory.unpriced_inventory_receipts.approve')
                            <button class="btn btn-success btn-sm js-approve-unpriced-inventory-receipt me-2" type="button" data-url="{{ route($routePrefix.'.approve', $record->doc_num) }}">
                                <span class="fas fa-check me-1"></span>{{ __('inventory.unpriced_inventory_receipts.actions.approve') }}
                            </button>
                        @endcan
                    @endif
                    @if($record->isApproved() && ! $record->isClosed() && ! $record->isCancelled())
                        @can('inventory.unpriced_inventory_receipts.close')
                            <button class="btn btn-falcon-default btn-sm js-close-unpriced-inventory-receipt me-2" type="button" data-url="{{ route($routePrefix.'.close', $record->doc_num) }}">
                                <span class="fas fa-lock me-1"></span>{{ __('inventory.unpriced_inventory_receipts.actions.close') }}
                            </button>
                        @endcan
                    @endif
                    @if(! $record->isClosed() && ! $record->isCancelled())
                        @can('inventory.unpriced_inventory_receipts.cancel')
                            <button class="btn btn-falcon-default text-warning btn-sm js-cancel-unpriced-inventory-receipt me-2" type="button" data-url="{{ route($routePrefix.'.cancel', $record->doc_num) }}">
                                <span class="fas fa-ban me-1"></span>{{ __('inventory.unpriced_inventory_receipts.actions.cancel') }}
                            </button>
                        @endcan
                    @endif
                @endif
                @include('modules.finance.partials.form-actions', [
                    'resource' => 'inventory.unpriced_inventory_receipts',
                    'routePrefix' => $routePrefix,
                    'canEditRecord' => ! ($record?->isLockedForEditing() ?? false),
                    'canDeleteRecord' => ! ($record?->isLockedForEditing() ?? false),
                ])
            </div>
        </div>

        @if($mode === 'view')
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">{{ __('inventory.unpriced_inventory_receipts.sections.audit') }}</h6>
                </div>
                <div class="card-body">
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$record?->trashed() ?? false"
                        :show-restored="! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                        class="mt-0"
                    />
                    @if($record?->approved_at || $record?->closed_at || $record?->cancelled_at)
                        <div class="row g-3 mt-0">
                            @if($record?->approved_at)
                                <x-forms.view-field :label="__('inventory.unpriced_inventory_receipts.attributes.approved_by')" :value="$metadata['approved_by'] ?? null" class="col-md-6 col-xl-3" />
                                <x-forms.view-field :label="__('inventory.unpriced_inventory_receipts.attributes.approved_at')" :value="$metadata['approved_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                            @endif
                            @if($record?->closed_at)
                                <x-forms.view-field :label="__('inventory.unpriced_inventory_receipts.attributes.closed_by')" :value="$metadata['closed_by'] ?? null" class="col-md-6 col-xl-3" />
                                <x-forms.view-field :label="__('inventory.unpriced_inventory_receipts.attributes.closed_at')" :value="$metadata['closed_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                            @endif
                            @if($record?->cancelled_at)
                                <x-forms.view-field :label="__('inventory.unpriced_inventory_receipts.attributes.cancelled_by')" :value="$metadata['cancelled_by'] ?? null" class="col-md-6 col-xl-3" />
                                <x-forms.view-field :label="__('inventory.unpriced_inventory_receipts.attributes.cancelled_at')" :value="$metadata['cancelled_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </form>

    @unless($isReadonly)
        <template id="unpriced-inventory-receipt-line-template">
            <tr class="js-unpriced-inventory-receipt-line" data-index="__INDEX__">
                <td>
                    <input type="hidden" name="lines[__INDEX__][public_id]" value="">
                    <input type="hidden" name="lines[__INDEX__][_delete]" value="0">
                    <div class="d-flex align-items-start gap-1 unpriced-receipt-product-picker">
                        <div class="flex-grow-1 min-w-0">
                            <select class="form-select js-select2-ajax js-unpriced-inventory-receipt-product" name="lines[__INDEX__][product_doc_num]" data-url="{{ route('admin.inventory.select2.unpriced-inventory-receipt-products') }}" data-placeholder="{{ __('inventory.unpriced_inventory_receipts.placeholders.select_product') }}" data-allow-clear="true" data-template="product-image"></select>
                            <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.product_doc_num"></div>
                        </div>
                        <button class="btn btn-falcon-default btn-sm unpriced-receipt-product-action js-unpriced-inventory-receipt-product-info" type="button" title="{{ __('inventory.unpriced_inventory_receipts.js.product_info_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.product_info_title') }}" disabled>
                            <span class="fas fa-info-circle"></span>
                            <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.actions.view_product') }}</span>
                        </button>
                        @if($canCreateProducts)
                            <a class="btn btn-falcon-default btn-sm unpriced-receipt-product-action js-unpriced-inventory-receipt-product-create" href="{{ $productCreateUrl }}" target="_blank" rel="noopener" title="{{ __('inventory.unpriced_inventory_receipts.js.product_create_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.product_create_title') }}">
                                <span class="fas fa-plus"></span>
                                <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.actions.add_product') }}</span>
                            </a>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="unpriced-receipt-unit-picker">
                        <select class="form-select js-unpriced-inventory-receipt-unit" name="lines[__INDEX__][unit_doc_num]" disabled>
                            <option value="">{{ __('inventory.unpriced_inventory_receipts.placeholders.select_unit') }}</option>
                        </select>
                    </div>
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.unit_doc_num"></div>
                </td>
                <td class="text-center">
                    <x-forms.numeric-input class="text-center js-unpriced-inventory-receipt-quantity" name="lines[__INDEX__][quantity]" :scale="8" min="0.00000001" step="0.00000001" />
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.quantity"></div>
                </td>
                <td>
                    <input class="form-control js-unpriced-inventory-receipt-line-notes" name="lines[__INDEX__][notes]" type="text" value="">
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.notes"></div>
                </td>
                <td class="text-center">
                    <button class="btn btn-link text-600 p-0 me-2 js-unpriced-inventory-receipt-duplicate-line" type="button" title="{{ __('inventory.unpriced_inventory_receipts.js.duplicate_line_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.duplicate_line_title') }}">
                        <span class="fas fa-copy"></span>
                        <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.js.duplicate_line_title') }}</span>
                    </button>
                    <button class="btn btn-link text-danger p-0 js-unpriced-inventory-receipt-remove-line" type="button" title="{{ __('inventory.unpriced_inventory_receipts.js.delete_line_title') }}" data-bs-title="{{ __('inventory.unpriced_inventory_receipts.js.delete_line_title') }}">
                        <span class="fas fa-trash-alt"></span>
                        <span class="visually-hidden">{{ __('inventory.unpriced_inventory_receipts.js.delete_line_title') }}</span>
                    </button>
                </td>
            </tr>
        </template>
    @endunless

    <div class="modal fade" id="unpriced-inventory-receipt-product-info-modal" tabindex="-1" aria-labelledby="unpriced-inventory-receipt-product-info-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="unpriced-inventory-receipt-product-info-title">{{ __('inventory.unpriced_inventory_receipts.product_modal.title') }}</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('auth.alerts.close') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <img class="img-fluid rounded-2 shadow-sm unpriced-receipt-product-image js-unpriced-inventory-receipt-product-image d-none" src="" alt="">
                        <div class="js-unpriced-inventory-receipt-product-no-image text-600 py-4 d-none">{{ __('inventory.unpriced_inventory_receipts.product_modal.no_image') }}</div>
                    </div>
                    <dl class="row g-3 mb-0 unpriced-receipt-product-details js-unpriced-inventory-receipt-product-details"></dl>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @php
        $receiptMessages = __('inventory.unpriced_inventory_receipts.js');
        $receiptMessages['unexpected_error'] = __('inventory.unpriced_inventory_receipts.messages.unexpected_error');
        $receiptMessages['no_product_selected'] = __('inventory.unpriced_inventory_receipts.messages.no_product_selected');
        $receiptMessages['no_image'] = __('inventory.unpriced_inventory_receipts.messages.no_image');
        $productLabels = [
            'doc_num' => __('products.attributes.doc_num'),
            'name' => __('products.attributes.name'),
            'barcode' => __('products.attributes.barcode'),
            'item_classification' => __('products.attributes.item_classification'),
            'unit' => __('products.attributes.unit'),
            'category' => __('products.attributes.category'),
            'group' => __('products.attributes.group'),
            'size' => __('products.attributes.size'),
            'color' => __('products.attributes.color'),
            'decal' => __('products.attributes.decal'),
            'model' => __('products.attributes.model'),
            'origin_country' => __('products.attributes.origin_country'),
            'reorder_point' => __('products.attributes.reorder_point'),
            'status' => __('products.attributes.status'),
            'notes' => __('products.attributes.notes'),
        ];
    @endphp
    <script>
        window.unpricedInventoryReceiptsMessages = @json($receiptMessages);
        window.unpricedInventoryReceiptProductLabels = @json($productLabels);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Inventory/unpriced-inventory-receipts.js') }}"></script>
@endpush
