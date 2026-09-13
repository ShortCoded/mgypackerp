@extends('layouts.app')

@php
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isReadonly = $mode === 'view' || (! $isCreateLike && ($isLocked ?? false));
    $title = __("inventory.opening_stocks.{$mode}");
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $value = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $selectedHallUuid = old('branch_hall_uuid', $hallOption['id'] ?? '');
    $selectedStoreUuid = old('branch_store_uuid', $storeOption['id'] ?? '');
    $existingLines = old('lines', $lines ?? []);
    if (! is_array($existingLines) || $existingLines === []) {
        $existingLines = [['public_id' => null, 'product_doc_num' => null, 'product_label' => null, 'imageUrl' => null, 'unit' => null, 'quantity' => null, 'notes' => null]];
    }
    $routePrefix = 'admin.inventory.opening-stocks';
@endphp

@section('title', $title)

@push('styles')
    <style>
        .opening-stock-product-image {
            max-height: 60vh;
            object-fit: contain;
        }

        .opening-stock-product-details dt {
            color: var(--falcon-700);
            font-weight: 600;
        }

        .opening-stock-product-picker {
            min-width: 18rem;
        }

        .opening-stock-product-picker .select2-container {
            min-width: 0;
        }

        .opening-stock-product-action {
            line-height: 1;
            min-height: calc(1.5em + .625rem + 2px);
            min-width: calc(1.5em + .625rem + 2px);
        }

        .opening-stock-unit-display {
            align-items: center;
            display: flex;
            min-height: calc(1.5em + .625rem + 2px);
            padding-bottom: .3125rem;
            padding-top: .3125rem;
        }

        @media (max-width: 767.98px) {
            .opening-stock-product-picker {
                min-width: 14rem;
            }
        }
    </style>
@endpush

@section('content')
    <form class="js-opening-stock-form"
        action="{{ $action }}"
        method="{{ $method }}"
        data-mode="{{ $mode }}"
        data-products-url="{{ route('admin.inventory.select2.opening-stock-products') }}"
        data-product-details-url-template="{{ route('admin.inventory.products.details', ['product' => '__PRODUCT__']) }}"
        data-primary-focus="document_date"
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
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ $title }}</h5>
                    </div>
                    <div class="col-auto">
                        @include('modules.finance.partials.form-actions', [
                            'resource' => 'inventory.opening_stocks',
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

                @if(! $isCreateLike && $record?->isApproved())
                    <div class="alert alert-warning">{{ __('inventory.opening_stocks.messages.approved_edit_forbidden') }}</div>
                @elseif(! $isCreateLike && $record?->isClosed())
                    <div class="alert alert-warning">{{ __('inventory.opening_stocks.messages.closed_edit_forbidden') }}</div>
                @endif

                <h6 class="text-700 mb-3">{{ __('inventory.opening_stocks.sections.header') }}</h6>
                <div class="row g-3">
                    @if($mode === 'view')
                        <div class="col-md-3">
                            <x-forms.view-field for="document_status" :label="__('inventory.opening_stocks.attributes.document_status')" :value="$record?->trashed() ? __('inventory.opening_stocks.statuses.deleted') : __('inventory.opening_stocks.statuses.'.($record?->status ?? 'closed'))" />
                        </div>
                        <div class="col-md-3">
                            <x-forms.view-field for="approval_status" :label="__('inventory.opening_stocks.attributes.approval_status')" :value="$record?->approved ? __('inventory.opening_stocks.statuses.approved') : __('inventory.opening_stocks.statuses.not_approved')" />
                        </div>
                    @endif

                    @if($canControlDocumentNumber)
                        <div class="col-md-3">
                            <label class="form-label" for="doc_number">{{ __('inventory.opening_stocks.attributes.doc_number') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                            @else
                                <x-forms.input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="1" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}" />
                                <div class="form-text">{{ __('item_lookups.document_number_control.helper') }}</div>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-3">
                            <x-forms.view-field for="doc_num" :label="__('inventory.opening_stocks.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                        </div>
                    @endif

                    <div class="col-md-3">
                        <x-forms.label for="document_date" :label="__('inventory.opening_stocks.attributes.document_date')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="document_date" :value="$dateValue" dir="ltr" input-class="date-value text-center" />
                        @else
                            <x-forms.date-input class="form-control text-center js-date-picker" id="document_date" name="document_date" type="text" value="{{ old('document_date', $dateValue) }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required />
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="document_date"></div>
                    </div>

                    @if($showHallSelector)
                        <div class="col-md-3">
                            <label class="form-label" for="branch_hall_uuid">{{ __('inventory.opening_stocks.attributes.hall') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="branch_hall_uuid" as="display" :value="$record?->branchHall?->name ?: __('common.empty_value')" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax" id="branch_hall_uuid" name="branch_hall_uuid" data-url="{{ route('admin.inventory.select2.branch-halls') }}" data-placeholder="{{ __('inventory.opening_stocks.placeholders.select_hall') }}" data-allow-clear="true">
                                    @if($selectedHallUuid && $hallOption)
                                        <option value="{{ $hallOption['id'] }}" selected>{{ $hallOption['text'] }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="branch_hall_uuid"></div>
                        </div>
                    @endif

                    @if($showStoreSelector)
                        <div class="col-md-3">
                            <label class="form-label" for="branch_store_uuid">{{ __('inventory.opening_stocks.attributes.store') }}</label>
                            @if($isReadonly)
                                <x-forms.view-field for="branch_store_uuid" as="display" :value="$record?->branchStore?->name ?: __('common.empty_value')" />
                            @else
                                <x-forms.select class="form-select js-select2-ajax" id="branch_store_uuid" name="branch_store_uuid" data-url="{{ route('admin.inventory.select2.branch-stores') }}" data-placeholder="{{ __('inventory.opening_stocks.placeholders.select_store') }}" data-allow-clear="true">
                                    @if($selectedStoreUuid && $storeOption)
                                        <option value="{{ $storeOption['id'] }}" selected>{{ $storeOption['text'] }}</option>
                                    @endif
                                </x-forms.select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="branch_store_uuid"></div>
                        </div>
                    @endif

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('inventory.opening_stocks.attributes.notes') }}</label>
                        <x-forms.textarea class="form-control" id="notes" name="notes" rows="2" :readonly='$isReadonly'>{{ old('notes', $value('notes')) }}</x-forms.textarea>
                        <div class="invalid-feedback d-block" data-error-for="notes"></div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info mb-0 py-2">{{ __('inventory.opening_stocks.messages.pricing_ownership') }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h6 class="mb-0">{{ __('inventory.opening_stocks.sections.lines') }}</h6>
                    </div>
                    @unless($isReadonly)
                        <div class="col-auto">
                            <button class="btn btn-falcon-default btn-sm js-opening-stock-add-line" type="button" title="{{ __('inventory.opening_stocks.js.add_line_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.add_line_title') }}">
                                <span class="fas fa-plus me-1"></span>{{ __('inventory.opening_stocks.actions.add_line') }}
                            </button>
                        </div>
                    @endunless
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 js-opening-stock-lines">
                        <thead class="bg-200">
                            <tr>
                                <th style="min-width: 280px">{{ __('inventory.opening_stocks.attributes.product') }}</th>
                                <th style="min-width: 120px">{{ __('inventory.opening_stocks.attributes.unit') }}</th>
                                <th class="text-center" style="min-width: 130px">{{ __('inventory.opening_stocks.attributes.quantity') }}</th>
                                <th style="min-width: 150px">{{ __('inventory.opening_stocks.attributes.stock_status') }}</th>
                                <th style="min-width: 150px">{{ __('inventory.opening_stocks.attributes.batch_lot') }}</th>
                                <th style="min-width: 150px">{{ __('Manufacture date') }}</th>
                                <th style="min-width: 150px">{{ __('Expiry date') }}</th>
                                <th>{{ __('inventory.opening_stocks.attributes.line_notes') }}</th>
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
                                    $imageUrl = $line['imageUrl'] ?? null;
                                @endphp
                                <tr class="js-opening-stock-line" data-index="{{ $index }}">
                                    <td>
                                        <x-forms.input type="hidden" name="lines[{{ $index }}][public_id]" value="{{ $line['public_id'] ?? '' }}" />
                                        <x-forms.input type="hidden" name="lines[{{ $index }}][_delete]" value="0" />
                                        @if($isReadonly)
                                            <div class="d-flex align-items-center gap-1 opening-stock-product-picker">
                                                <div class="form-control-plaintext flex-grow-1 min-w-0 text-truncate" title="{{ $productLabel }}">{{ $productLabel }}</div>
                                                <button class="btn btn-falcon-default btn-sm opening-stock-product-action js-opening-stock-product-info" type="button" title="{{ __('inventory.opening_stocks.js.product_info_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.product_info_title') }}" @disabled(! $productDocNum)>
                                                    <span class="fas fa-info-circle"></span>
                                                    <span class="visually-hidden">{{ __('inventory.opening_stocks.actions.view_product') }}</span>
                                                </button>
                                                <x-forms.input type="hidden" class="js-opening-stock-product-value" value="{{ $productDocNum }}" />
                                            </div>
                                        @else
                                            <div class="d-flex align-items-start gap-1 opening-stock-product-picker">
                                                <div class="flex-grow-1 min-w-0">
                                                    <x-forms.select class="form-select js-select2-ajax js-opening-stock-product" name="lines[{{ $index }}][product_doc_num]" data-url="{{ route('admin.inventory.select2.opening-stock-products') }}" data-placeholder="{{ __('inventory.opening_stocks.placeholders.select_product') }}" data-allow-clear="true" data-template="product-image">
                                                        @if($productDocNum)
                                                            <option value="{{ $productDocNum }}" data-unit-label="{{ $line['unit'] ?? '' }}" @if($imageUrl) data-image-url="{{ $imageUrl }}" @endif selected>{{ $productLabel }}</option>
                                                        @endif
                                                    </x-forms.select>
                                                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div>
                                                </div>
                                                <button class="btn btn-falcon-default btn-sm opening-stock-product-action js-opening-stock-product-info" type="button" title="{{ __('inventory.opening_stocks.js.product_info_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.product_info_title') }}" @disabled(! $productDocNum)>
                                                    <span class="fas fa-info-circle"></span>
                                                    <span class="visually-hidden">{{ __('inventory.opening_stocks.actions.view_product') }}</span>
                                                </button>
                                                @if($canCreateProducts)
                                                    <a class="btn btn-falcon-default btn-sm opening-stock-product-action js-opening-stock-product-create" href="{{ $productCreateUrl }}" target="_blank" rel="noopener" title="{{ __('inventory.opening_stocks.js.product_create_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.product_create_title') }}">
                                                        <span class="fas fa-plus"></span>
                                                        <span class="visually-hidden">{{ __('inventory.opening_stocks.actions.add_product') }}</span>
                                                    </a>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)<div class="form-control-plaintext">{{ $line['manufacture_date'] ?? null }}</div>@else<x-forms.date-input name="lines[{{ $index }}][manufacture_date]" :value="$line['manufacture_date'] ?? ''" /><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.manufacture_date"></div>@endif
                                    </td>
                                    <td>
                                        @if($isReadonly)<div class="form-control-plaintext">{{ $line['expiry_date'] ?? null }}</div>@else<x-forms.date-input name="lines[{{ $index }}][expiry_date]" :value="$line['expiry_date'] ?? ''" /><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.expiry_date"></div>@endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="form-control-plaintext">{{ $line['unit'] ?? null }}</div>
                                        @else
                                            <div class="opening-stock-unit-display js-opening-stock-unit text-700" data-unit-display aria-readonly="true">{{ $line['unit'] ?? '' }}</div>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($isReadonly)
                                            <div class="form-control-plaintext text-center" dir="ltr">{{ $numbers->format($line['quantity'] ?? 0) }}</div>
                                        @else
                                            <x-forms.numeric-input class="text-center js-opening-stock-quantity" :name="'lines['.$index.'][quantity]'" :value="$line['quantity'] ?? ''" :scale="4" min="0.0001" step="0.0001" />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.quantity"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="form-control-plaintext">{{ __(str($line['stock_status'] ?? 'available')->replace('_', ' ')->title()->toString()) }}</div>
                                        @else
                                            <x-forms.select class="form-select js-opening-stock-status" name="lines[{{ $index }}][stock_status]">
                                                @foreach(['available', 'qc_hold', 'quarantine', 'damaged'] as $status)
                                                    <option value="{{ $status }}" @selected(($line['stock_status'] ?? 'available') === $status)>{{ __(str($status)->replace('_', ' ')->title()->toString()) }}</option>
                                                @endforeach
                                            </x-forms.select>
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.stock_status"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="form-control-plaintext">{{ $line['batch_lot'] ?? null }}</div>
                                        @else
                                            <x-forms.input class="form-control js-opening-stock-batch" name="lines[{{ $index }}][batch_lot]" type="text" maxlength="100" value="{{ $line['batch_lot'] ?? '' }}" />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.batch_lot"></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="form-control-plaintext">{{ $line['notes'] ?? null }}</div>
                                        @else
                                            <x-forms.input class="form-control js-opening-stock-line-notes" name="lines[{{ $index }}][notes]" type="text" value="{{ $line['notes'] ?? '' }}" />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.notes"></div>
                                        @endif
                                    </td>
                                    @unless($isReadonly)
                                        <td class="text-center">
                                            <button class="btn btn-link text-600 p-0 me-2 js-opening-stock-duplicate-line" type="button" title="{{ __('inventory.opening_stocks.js.duplicate_line_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.duplicate_line_title') }}">
                                                <span class="fas fa-copy"></span>
                                                <span class="visually-hidden">{{ __('inventory.opening_stocks.js.duplicate_line_title') }}</span>
                                            </button>
                                            <button class="btn btn-link text-danger p-0 js-opening-stock-remove-line" type="button" title="{{ __('inventory.opening_stocks.js.delete_line_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.delete_line_title') }}">
                                                <span class="fas fa-trash-alt"></span>
                                                <span class="visually-hidden">{{ __('inventory.opening_stocks.js.delete_line_title') }}</span>
                                            </button>
                                        </td>
                                    @endunless
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $isReadonly ? 8 : 9 }}" class="text-center text-600 py-3">{{ __('common.empty_value') }}</td>
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
                @if($mode === 'view' && $record && ! $record->approved && ! $record->trashed())
                    @can('inventory.opening_stocks.approve')
                        <button class="btn btn-success btn-sm js-approve-opening-stock me-2" type="button" data-url="{{ route($routePrefix.'.approve', $record->doc_num) }}">
                            <span class="fas fa-check me-1"></span>{{ __('inventory.opening_stocks.actions.approve') }}
                        </button>
                    @endcan
                @endif
                @include('modules.finance.partials.form-actions', [
                    'resource' => 'inventory.opening_stocks',
                    'routePrefix' => $routePrefix,
                    'canEditRecord' => ! ($record?->isLockedForEditing() ?? false),
                    'canDeleteRecord' => ! ($record?->isLockedForEditing() ?? false),
                ])
            </div>
        </div>

        @if($mode === 'view')
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">{{ __('inventory.opening_stocks.sections.audit') }}</h6>
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
                            <x-forms.view-field :label="__('inventory.opening_stocks.attributes.approved_by')" :value="$metadata['approved_by'] ?? null" class="col-md-6 col-xl-3" />
                            <x-forms.view-field :label="__('inventory.opening_stocks.attributes.approved_at')" :value="$metadata['approved_at'] ?? null" dir="ltr" input-class="date-value" class="col-md-6 col-xl-3" />
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </form>

    @unless($isReadonly)
        <template id="opening-stock-line-template">
            <tr class="js-opening-stock-line" data-index="__INDEX__">
                <td>
                    <x-forms.input type="hidden" name="lines[__INDEX__][public_id]" value="" />
                    <x-forms.input type="hidden" name="lines[__INDEX__][_delete]" value="0" />
                    <div class="d-flex align-items-start gap-1 opening-stock-product-picker">
                        <div class="flex-grow-1 min-w-0">
                            <x-forms.select class="form-select js-select2-ajax js-opening-stock-product" name="lines[__INDEX__][product_doc_num]" data-url="{{ route('admin.inventory.select2.opening-stock-products') }}" data-placeholder="{{ __('inventory.opening_stocks.placeholders.select_product') }}" data-allow-clear="true" data-template="product-image"></x-forms.select>
                            <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.product_doc_num"></div>
                        </div>
                        <button class="btn btn-falcon-default btn-sm opening-stock-product-action js-opening-stock-product-info" type="button" title="{{ __('inventory.opening_stocks.js.product_info_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.product_info_title') }}" disabled>
                            <span class="fas fa-info-circle"></span>
                            <span class="visually-hidden">{{ __('inventory.opening_stocks.actions.view_product') }}</span>
                        </button>
                        @if($canCreateProducts)
                            <a class="btn btn-falcon-default btn-sm opening-stock-product-action js-opening-stock-product-create" href="{{ $productCreateUrl }}" target="_blank" rel="noopener" title="{{ __('inventory.opening_stocks.js.product_create_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.product_create_title') }}">
                                <span class="fas fa-plus"></span>
                                <span class="visually-hidden">{{ __('inventory.opening_stocks.actions.add_product') }}</span>
                            </a>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="opening-stock-unit-display js-opening-stock-unit text-700" data-unit-display aria-readonly="true"></div>
                </td>
                <td class="text-center">
                    <x-forms.numeric-input class="text-center js-opening-stock-quantity" name="lines[__INDEX__][quantity]" :scale="4" min="0.0001" step="0.0001" />
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.quantity"></div>
                </td>
                <td>
                    <x-forms.select class="form-select js-opening-stock-status" name="lines[__INDEX__][stock_status]">
                        @foreach(['available', 'qc_hold', 'quarantine', 'damaged'] as $status)
                            <option value="{{ $status }}" @selected($status === 'available')>{{ __(str($status)->replace('_', ' ')->title()->toString()) }}</option>
                        @endforeach
                    </x-forms.select>
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.stock_status"></div>
                </td>
                <td>
                    <x-forms.input class="form-control js-opening-stock-batch" name="lines[__INDEX__][batch_lot]" type="text" maxlength="100" value="" />
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.batch_lot"></div>
                </td>
                <td><x-forms.date-input name="lines[__INDEX__][manufacture_date]" /><div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.manufacture_date"></div></td>
                <td><x-forms.date-input name="lines[__INDEX__][expiry_date]" /><div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.expiry_date"></div></td>
                <td>
                    <x-forms.input class="form-control js-opening-stock-line-notes" name="lines[__INDEX__][notes]" type="text" value="" />
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.notes"></div>
                </td>
                <td class="text-center">
                    <button class="btn btn-link text-600 p-0 me-2 js-opening-stock-duplicate-line" type="button" title="{{ __('inventory.opening_stocks.js.duplicate_line_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.duplicate_line_title') }}">
                        <span class="fas fa-copy"></span>
                        <span class="visually-hidden">{{ __('inventory.opening_stocks.js.duplicate_line_title') }}</span>
                    </button>
                    <button class="btn btn-link text-danger p-0 js-opening-stock-remove-line" type="button" title="{{ __('inventory.opening_stocks.js.delete_line_title') }}" data-bs-title="{{ __('inventory.opening_stocks.js.delete_line_title') }}">
                        <span class="fas fa-trash-alt"></span>
                        <span class="visually-hidden">{{ __('inventory.opening_stocks.js.delete_line_title') }}</span>
                    </button>
                </td>
            </tr>
        </template>
    @endunless

    <div class="modal fade" id="opening-stock-product-info-modal" tabindex="-1" aria-labelledby="opening-stock-product-info-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="opening-stock-product-info-title">{{ __('inventory.opening_stocks.product_modal.title') }}</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('auth.alerts.close') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <img class="img-fluid rounded-2 shadow-sm opening-stock-product-image js-opening-stock-product-image d-none" src="" alt="">
                        <div class="js-opening-stock-product-no-image text-600 py-4 d-none">{{ __('inventory.opening_stocks.product_modal.no_image') }}</div>
                    </div>
                    <dl class="row g-3 mb-0 opening-stock-product-details js-opening-stock-product-details"></dl>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @php
        $openingStockMessages = __('inventory.opening_stocks.js');
        $openingStockMessages['unexpected_error'] = __('inventory.opening_stocks.messages.unexpected_error');
        $openingStockMessages['no_product_selected'] = __('inventory.opening_stocks.messages.no_product_selected');
        $openingStockMessages['no_image'] = __('inventory.opening_stocks.messages.no_image');
        $openingStockProductLabels = [
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
        window.openingStocksMessages = @json($openingStockMessages);
        window.openingStockProductLabels = @json($openingStockProductLabels);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Inventory/opening-stocks.js') }}"></script>
@endpush
