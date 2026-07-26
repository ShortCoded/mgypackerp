@extends('layouts.app')

@php
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isReadonly = $mode === 'view' || ($record?->trashed() ?? false) || (! $isCreateLike && ($isLocked ?? false));
    $title = __("inventory.opening_stock_pricings.{$mode}");
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $value = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $selectedBranchDocNum = old('branch_doc_num', $branchOption['id'] ?? '');
    $selectedHallUuid = old('branch_hall_uuid', $hallOption['id'] ?? '');
    $selectedOpeningStockDocNum = old('opening_stock_doc_num', $openingStockOption['id'] ?? '');
    $selectedCurrencyDocNum = old('currency_doc_num', $currencyOption['id'] ?? '');
    $exchangeRate = old('exchange_rate', $exchangeRateValue ?? 1);
    $existingLines = old('lines', $lines ?? []);
    if (! is_array($existingLines) || $existingLines === []) {
        $existingLines = [['public_id' => null, 'opening_stock_line_public_id' => null, 'product_label' => null, 'imageUrl' => null, 'unit' => null, 'quantity' => null, 'unit_price' => null, 'line_total' => null, 'notes' => null, 'product_data' => []]];
    }
    $routePrefix = 'admin.inventory.opening-stock-pricings';
@endphp

@section('title', $title)

@push('styles')
    <style>
        .opening-stock-pricing-product-image {
            max-height: 60vh;
            object-fit: contain;
        }

        .opening-stock-pricing-product-details dt {
            color: var(--falcon-700);
            font-weight: 600;
        }

        .opening-stock-pricing-product-picker {
            min-width: 18rem;
        }

        .opening-stock-pricing-product-action {
            line-height: 1;
            min-height: calc(1.5em + .625rem + 2px);
            min-width: calc(1.5em + .625rem + 2px);
        }

        .opening-stock-pricing-unit-display {
            align-items: center;
            display: flex;
            min-height: calc(1.5em + .625rem + 2px);
            padding-bottom: .3125rem;
            padding-top: .3125rem;
        }

        @media (max-width: 767.98px) {
            .opening-stock-pricing-product-picker {
                min-width: 14rem;
            }
        }
    </style>
@endpush

@section('content')
    <form class="js-opening-stock-pricing-form"
        action="{{ $action }}"
        method="{{ $method }}"
        data-mode="{{ $mode }}"
        data-current-doc-num="{{ $record?->doc_num }}"
        data-main-currency-doc-num="{{ $mainCurrencyDocNum }}"
        data-branches-url="{{ route('admin.inventory.select2.opening-stock-pricing-branches') }}"
        data-halls-url="{{ route('admin.inventory.select2.opening-stock-pricing-branch-halls') }}"
        data-opening-stocks-url="{{ route('admin.inventory.select2.opening-stock-pricing-documents') }}"
        data-lines-url="{{ route('admin.inventory.select2.opening-stock-pricing-lines') }}"
        data-remaining-lines-url="{{ route($routePrefix.'.remaining-lines') }}"
        data-primary-focus="document_date"
        novalidate>
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        <input type="hidden" id="current_pricing_doc_num" value="{{ $record?->doc_num }}">
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
                        @include('modules.finance.partials.form-actions', [
                            'resource' => 'inventory.opening_stock_pricings',
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

                @if(! $isCreateLike && $record?->isLockedForEditing())
                    <div class="alert alert-warning">{{ __('inventory.opening_stock_pricings.messages.closed_edit_forbidden') }}</div>
                @endif

                <h6 class="text-700 mb-3">{{ __('inventory.opening_stock_pricings.sections.header') }}</h6>
                <div class="row g-3">
                    @if($canControlDocumentNumber)
                        <div class="col-md-3">
                            <label class="form-label" for="doc_number">{{ __('inventory.opening_stock_pricings.attributes.doc_number') }}</label>
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
                            <x-forms.view-field for="doc_num" :label="__('inventory.opening_stock_pricings.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                        </div>
                    @endif

                    <div class="col-md-3">
                        <x-forms.label for="document_date" :label="__('inventory.opening_stock_pricings.attributes.document_date')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="document_date" :value="$dateValue" dir="ltr" input-class="date-value text-center" />
                        @else
                            <input class="form-control text-center js-date-picker" id="document_date" name="document_date" type="text" value="{{ old('document_date', $dateValue) }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="document_date"></div>
                    </div>

                    <div class="col-md-3">
                        <x-forms.label for="branch_doc_num" :label="__('inventory.opening_stock_pricings.attributes.branch')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="branch_doc_num" as="display" :value="$branchOption['text'] ?? null" />
                        @else
                            <select class="form-select js-select2-ajax js-opening-stock-pricing-branch" id="branch_doc_num" name="branch_doc_num" data-url="{{ route('admin.inventory.select2.opening-stock-pricing-branches') }}" data-placeholder="{{ __('inventory.opening_stock_pricings.placeholders.select_branch') }}" required>
                                @if($selectedBranchDocNum && $branchOption)
                                    <option value="{{ $branchOption['id'] }}" data-type="{{ $branchOption['type'] ?? '' }}" selected>{{ $branchOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_doc_num"></div>
                    </div>

                    <div class="col-md-3 js-opening-stock-pricing-hall-group">
                        <label class="form-label" for="branch_hall_uuid">{{ __('inventory.opening_stock_pricings.attributes.hall') }}</label>
                        @if($isReadonly)
                            <x-forms.view-field for="branch_hall_uuid" as="display" :value="$record?->branchHall?->name ?: __('common.empty_value')" />
                        @else
                            <select class="form-select js-select2-ajax js-opening-stock-pricing-hall" id="branch_hall_uuid" name="branch_hall_uuid" data-url="{{ route('admin.inventory.select2.opening-stock-pricing-branch-halls') }}" data-placeholder="{{ __('inventory.opening_stock_pricings.placeholders.select_hall') }}" data-allow-clear="true" data-extra-params='{"branch_doc_num":"#branch_doc_num"}'>
                                @if($selectedHallUuid && $hallOption)
                                    <option value="{{ $hallOption['id'] }}" selected>{{ $hallOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_hall_uuid"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="opening_stock_doc_num" :label="__('inventory.opening_stock_pricings.attributes.opening_stock')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="opening_stock_doc_num" as="display" :value="$openingStockOption['text'] ?? null" />
                        @else
                            <div class="input-group">
                                <select class="form-select js-select2-ajax js-opening-stock-pricing-opening-stock" id="opening_stock_doc_num" name="opening_stock_doc_num" data-url="{{ route('admin.inventory.select2.opening-stock-pricing-documents') }}" data-placeholder="{{ __('inventory.opening_stock_pricings.placeholders.select_opening_stock') }}" data-extra-params='{"branch_doc_num":"#branch_doc_num","branch_hall_uuid":"#branch_hall_uuid","current_pricing_doc_num":"#current_pricing_doc_num"}' required>
                                    @if($selectedOpeningStockDocNum && $openingStockOption)
                                        <option value="{{ $openingStockOption['id'] }}" selected>{{ $openingStockOption['text'] }}</option>
                                    @endif
                                </select>
                                <button class="btn btn-falcon-default js-opening-stock-pricing-add-remaining" type="button" title="{{ __('inventory.opening_stock_pricings.js.add_remaining_lines_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.add_remaining_lines_title') }}">
                                    <span class="fas fa-list-ul me-1"></span>{{ __('inventory.opening_stock_pricings.actions.add_remaining_lines') }}
                                </button>
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="opening_stock_doc_num"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="currency_doc_num" :label="__('inventory.opening_stock_pricings.attributes.currency')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="currency_doc_num" as="display" :value="$currencyOption['text'] ?? null" />
                        @else
                            <select class="form-select js-select2-ajax js-opening-stock-pricing-currency" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.inventory.select2.opening-stock-pricing-currencies') }}" data-placeholder="{{ __('inventory.opening_stock_pricings.placeholders.select_currency') }}" required>
                                @if($selectedCurrencyDocNum && $currencyOption)
                                    <option value="{{ $currencyOption['id'] }}" @if($currencyOption['is_main'] ?? false) data-is-main="1" @endif selected>{{ $currencyOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="exchange_rate" :label="__('inventory.opening_stock_pricings.attributes.exchange_rate')" required />
                        @if($isReadonly)
                            <x-forms.view-field for="exchange_rate" :value="$numbers->format($exchangeRate)" input-class="text-center" dir="ltr" />
                        @else
                            <x-forms.numeric-input class="text-center js-opening-stock-pricing-exchange-rate" id="exchange_rate" name="exchange_rate" :value="$exchangeRate" :scale="6" min="0.000001" step="0.000001" required />
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('inventory.opening_stock_pricings.attributes.notes') }}</label>
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
                        <h6 class="mb-0">{{ __('inventory.opening_stock_pricings.sections.lines') }}</h6>
                    </div>
                    @unless($isReadonly)
                        <div class="col-auto">
                            <button class="btn btn-falcon-default btn-sm js-opening-stock-pricing-add-line" type="button" title="{{ __('inventory.opening_stock_pricings.js.add_line_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.add_line_title') }}">
                                <span class="fas fa-plus me-1"></span>{{ __('inventory.opening_stock_pricings.actions.add_line') }}
                            </button>
                        </div>
                    @endunless
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 js-opening-stock-pricing-lines">
                        <thead class="bg-200">
                            <tr>
                                <th style="width: 32%">{{ __('inventory.opening_stock_pricings.attributes.product') }}</th>
                                <th style="width: 13%">{{ __('inventory.opening_stock_pricings.attributes.unit') }}</th>
                                <th class="text-center" style="width: 11%">{{ __('inventory.opening_stock_pricings.attributes.quantity') }}</th>
                                <th class="text-center" style="width: 12%">{{ __('inventory.opening_stock_pricings.attributes.unit_price') }}</th>
                                <th class="text-center" style="width: 12%">{{ __('inventory.opening_stock_pricings.attributes.line_total') }}</th>
                                <th>{{ __('inventory.opening_stock_pricings.attributes.line_notes') }}</th>
                                @unless($isReadonly)
                                    <th class="text-center" style="width: 76px">{{ __('common.fields.actions') }}</th>
                                @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($existingLines as $index => $line)
                                @php
                                    $linePublicId = $line['opening_stock_line_public_id'] ?? null;
                                    $productLabel = $line['product_label'] ?? $linePublicId;
                                    $imageUrl = $line['imageUrl'] ?? null;
                                @endphp
                                <tr class="js-opening-stock-pricing-line" data-index="{{ $index }}">
                                    <td>
                                        <input type="hidden" name="lines[{{ $index }}][public_id]" value="{{ $line['public_id'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $index }}][_delete]" value="0">
                                        @if($isReadonly)
                                            <div class="d-flex align-items-center gap-1 opening-stock-pricing-product-picker">
                                                <div class="form-control-plaintext flex-grow-1 min-w-0 text-truncate" title="{{ $productLabel }}">{{ $productLabel }}</div>
                                                <button class="btn btn-falcon-default btn-sm opening-stock-pricing-product-action js-opening-stock-pricing-product-info" type="button" data-product="{{ e(json_encode($line['product_data'] ?? [])) }}" title="{{ __('inventory.opening_stock_pricings.js.product_info_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.product_info_title') }}" @disabled(! $linePublicId)>
                                                    <span class="fas fa-info-circle"></span>
                                                    <span class="visually-hidden">{{ __('inventory.opening_stock_pricings.actions.view_product') }}</span>
                                                </button>
                                            </div>
                                        @else
                                            <div class="d-flex align-items-start gap-1 opening-stock-pricing-product-picker">
                                                <div class="flex-grow-1 min-w-0">
                                                    <select class="form-select js-select2-ajax js-opening-stock-pricing-product" name="lines[{{ $index }}][opening_stock_line_public_id]" data-url="{{ route('admin.inventory.select2.opening-stock-pricing-lines') }}" data-placeholder="{{ __('inventory.opening_stock_pricings.placeholders.select_product') }}" data-allow-clear="true" data-template="product-image" data-extra-params='{"branch_doc_num":"#branch_doc_num","opening_stock_doc_num":"#opening_stock_doc_num","current_pricing_doc_num":"#current_pricing_doc_num"}'>
                                                        @if($linePublicId)
                                                            <option value="{{ $linePublicId }}" data-unit-label="{{ $line['unit'] ?? '' }}" data-quantity="{{ $line['quantity'] ?? '' }}" @if($imageUrl) data-image-url="{{ $imageUrl }}" @endif data-product-data="{{ e(json_encode($line['product_data'] ?? [])) }}" selected>{{ $productLabel }}</option>
                                                        @endif
                                                    </select>
                                                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.opening_stock_line_public_id"></div>
                                                </div>
                                                <button class="btn btn-falcon-default btn-sm opening-stock-pricing-product-action js-opening-stock-pricing-product-info" type="button" data-product="{{ e(json_encode($line['product_data'] ?? [])) }}" title="{{ __('inventory.opening_stock_pricings.js.product_info_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.product_info_title') }}" @disabled(! $linePublicId)>
                                                    <span class="fas fa-info-circle"></span>
                                                    <span class="visually-hidden">{{ __('inventory.opening_stock_pricings.actions.view_product') }}</span>
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                    <td><div class="opening-stock-pricing-unit-display js-opening-stock-pricing-unit text-700" data-unit-display>{{ $line['unit'] ?? '' }}</div></td>
                                    <td class="text-center"><input class="form-control-plaintext text-center js-opening-stock-pricing-quantity" type="text" value="{{ $numbers->format($line['quantity'] ?? null) }}" dir="ltr" readonly></td>
                                    <td class="text-center">
                                        @if($isReadonly)
                                            <div class="form-control-plaintext text-center" dir="ltr">{{ $numbers->format($line['unit_price'] ?? 0) }}</div>
                                        @else
                                            <x-forms.numeric-input class="text-center js-opening-stock-pricing-unit-price" :name="'lines['.$index.'][unit_price]'" :value="$line['unit_price'] ?? ''" :scale="4" min="0.0001" step="0.0001" />
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_price"></div>
                                        @endif
                                    </td>
                                    <td class="text-center"><input class="form-control-plaintext text-center js-opening-stock-pricing-line-total" type="text" value="{{ $numbers->format($line['line_total'] ?? null) }}" dir="ltr" readonly></td>
                                    <td>
                                        @if($isReadonly)
                                            <div class="form-control-plaintext">{{ $line['notes'] ?? null }}</div>
                                        @else
                                            <input class="form-control js-opening-stock-pricing-line-notes" name="lines[{{ $index }}][notes]" type="text" value="{{ $line['notes'] ?? '' }}">
                                            <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.notes"></div>
                                        @endif
                                    </td>
                                    @unless($isReadonly)
                                        <td class="text-center">
                                            <button class="btn btn-link text-600 p-0 me-2 js-opening-stock-pricing-duplicate-line" type="button" title="{{ __('inventory.opening_stock_pricings.js.duplicate_line_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.duplicate_line_title') }}">
                                                <span class="fas fa-copy"></span>
                                                <span class="visually-hidden">{{ __('inventory.opening_stock_pricings.js.duplicate_line_title') }}</span>
                                            </button>
                                            <button class="btn btn-link text-danger p-0 js-opening-stock-pricing-remove-line" type="button" title="{{ __('inventory.opening_stock_pricings.js.delete_line_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.delete_line_title') }}">
                                                <span class="fas fa-trash-alt"></span>
                                                <span class="visually-hidden">{{ __('inventory.opening_stock_pricings.js.delete_line_title') }}</span>
                                            </button>
                                        </td>
                                    @endunless
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $isReadonly ? 6 : 7 }}" class="text-center text-600 py-3">{{ __('common.empty_value') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-100">
                            <tr>
                                <th colspan="4" class="text-end">{{ __('inventory.opening_stock_pricings.attributes.total_amount') }}</th>
                                <th class="text-center"><span class="js-opening-stock-pricing-total-amount" dir="ltr">{{ $numbers->format(old('total_amount', $record?->total_amount ?? 0)) }}</span></th>
                                <th colspan="{{ $isReadonly ? 1 : 2 }}"></th>
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
                @include('modules.finance.partials.form-actions', [
                    'resource' => 'inventory.opening_stock_pricings',
                    'routePrefix' => $routePrefix,
                    'canEditRecord' => ! ($record?->isLockedForEditing() ?? false),
                    'canDeleteRecord' => ! ($record?->isLockedForEditing() ?? false),
                ])
            </div>
        </div>

        @if($mode === 'view')
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">{{ __('inventory.opening_stock_pricings.sections.audit') }}</h6>
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

    @unless($isReadonly)
        <template id="opening-stock-pricing-line-template">
            <tr class="js-opening-stock-pricing-line" data-index="__INDEX__">
                <td>
                    <input type="hidden" name="lines[__INDEX__][public_id]" value="">
                    <input type="hidden" name="lines[__INDEX__][_delete]" value="0">
                    <div class="d-flex align-items-start gap-1 opening-stock-pricing-product-picker">
                        <div class="flex-grow-1 min-w-0">
                            <select class="form-select js-select2-ajax js-opening-stock-pricing-product" name="lines[__INDEX__][opening_stock_line_public_id]" data-url="{{ route('admin.inventory.select2.opening-stock-pricing-lines') }}" data-placeholder="{{ __('inventory.opening_stock_pricings.placeholders.select_product') }}" data-allow-clear="true" data-template="product-image" data-extra-params='{"branch_doc_num":"#branch_doc_num","opening_stock_doc_num":"#opening_stock_doc_num","current_pricing_doc_num":"#current_pricing_doc_num"}'></select>
                            <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.opening_stock_line_public_id"></div>
                        </div>
                        <button class="btn btn-falcon-default btn-sm opening-stock-pricing-product-action js-opening-stock-pricing-product-info" type="button" title="{{ __('inventory.opening_stock_pricings.js.product_info_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.product_info_title') }}" disabled>
                            <span class="fas fa-info-circle"></span>
                            <span class="visually-hidden">{{ __('inventory.opening_stock_pricings.actions.view_product') }}</span>
                        </button>
                    </div>
                </td>
                <td><div class="opening-stock-pricing-unit-display js-opening-stock-pricing-unit text-700" data-unit-display></div></td>
                <td class="text-center"><input class="form-control-plaintext text-center js-opening-stock-pricing-quantity" type="text" value="" dir="ltr" readonly></td>
                <td class="text-center">
                    <x-forms.numeric-input class="text-center js-opening-stock-pricing-unit-price" name="lines[__INDEX__][unit_price]" :scale="4" min="0.0001" step="0.0001" />
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.unit_price"></div>
                </td>
                <td class="text-center"><input class="form-control-plaintext text-center js-opening-stock-pricing-line-total" type="text" value="" dir="ltr" readonly></td>
                <td>
                    <input class="form-control js-opening-stock-pricing-line-notes" name="lines[__INDEX__][notes]" type="text" value="">
                    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.notes"></div>
                </td>
                <td class="text-center">
                    <button class="btn btn-link text-600 p-0 me-2 js-opening-stock-pricing-duplicate-line" type="button" title="{{ __('inventory.opening_stock_pricings.js.duplicate_line_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.duplicate_line_title') }}">
                        <span class="fas fa-copy"></span>
                        <span class="visually-hidden">{{ __('inventory.opening_stock_pricings.js.duplicate_line_title') }}</span>
                    </button>
                    <button class="btn btn-link text-danger p-0 js-opening-stock-pricing-remove-line" type="button" title="{{ __('inventory.opening_stock_pricings.js.delete_line_title') }}" data-bs-title="{{ __('inventory.opening_stock_pricings.js.delete_line_title') }}">
                        <span class="fas fa-trash-alt"></span>
                        <span class="visually-hidden">{{ __('inventory.opening_stock_pricings.js.delete_line_title') }}</span>
                    </button>
                </td>
            </tr>
        </template>
    @endunless

    <div class="modal fade" id="opening-stock-pricing-product-info-modal" tabindex="-1" aria-labelledby="opening-stock-pricing-product-info-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="opening-stock-pricing-product-info-title">{{ __('inventory.opening_stock_pricings.product_modal.title') }}</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('auth.alerts.close') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <img class="img-fluid rounded-2 shadow-sm opening-stock-pricing-product-image js-opening-stock-pricing-product-image d-none" src="" alt="">
                        <div class="js-opening-stock-pricing-product-no-image text-600 py-4 d-none">{{ __('inventory.opening_stock_pricings.product_modal.no_image') }}</div>
                    </div>
                    <dl class="row g-3 mb-0 opening-stock-pricing-product-details js-opening-stock-pricing-product-details"></dl>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @php
        $pricingMessages = __('inventory.opening_stock_pricings.js');
        $pricingMessages['unexpected_error'] = __('inventory.opening_stock_pricings.messages.unexpected_error');
        $pricingMessages['select_opening_stock_first'] = __('inventory.opening_stock_pricings.messages.select_opening_stock_first');
        $pricingMessages['no_remaining_lines'] = __('inventory.opening_stock_pricings.messages.no_remaining_lines');
        $pricingMessages['no_product_selected'] = __('inventory.opening_stock_pricings.messages.no_product_selected');
        $pricingMessages['no_image'] = __('inventory.opening_stock_pricings.messages.no_image');
        $pricingProductLabels = [
            'doc_num' => __('products.attributes.doc_num'),
            'name' => __('products.attributes.name'),
            'barcode' => __('products.attributes.barcode'),
            'unit' => __('products.attributes.unit'),
            'quantity' => __('inventory.opening_stock_pricings.attributes.quantity'),
        ];
    @endphp
    <script>
        window.openingStockPricingsMessages = @json($pricingMessages);
        window.openingStockPricingProductLabels = @json($pricingProductLabels);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Inventory/opening-stock-pricings.js') }}"></script>
@endpush
