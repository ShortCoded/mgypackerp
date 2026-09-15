@extends('layouts.app')

@php
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isReadonly = $mode === 'view' || (! $isCreateLike && $isLocked);
    $title = __("inventory.stock_counts.{$mode}");
    $routePrefix = 'admin.inventory.stock-counts';
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $selectedStoreId = (string) old('branch_store_id', $record?->branch_store_id ?? '');
    $selectedLocationId = (string) old('warehouse_location_id', $record?->warehouse_location_id ?? '');
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $existingLines = $lines;
@endphp

@section('title', $title)

@push('styles')
    <style>
        .stock-count-product-picker { min-width: 19rem; }
        .stock-count-unit-display { align-items: center; display: flex; min-height: calc(1.5em + .625rem + 2px); }
        .stock-count-readonly-number { min-width: 7rem; }
        .stock-count-variance-badge { min-width: 4.75rem; }
        .stock-count-product-image { max-height: 60vh; object-fit: contain; }
    </style>
@endpush

@section('content')
    <form class="js-stock-count-form" action="{{ $action }}" method="{{ $method }}"
        data-mode="{{ $mode }}"
        data-balance-url="{{ route('admin.inventory.stock-counts.balance') }}"
        data-products-url="{{ route('admin.inventory.stock-counts.select2.products') }}"
        data-product-details-url-template="{{ route('admin.inventory.stock-counts.products.details', ['product' => '__PRODUCT__']) }}"
        novalidate>
        @csrf
        @if($method !== 'POST') @method($method) @endif
        <x-forms.line-item-cards />
        <x-forms.input type="hidden" name="submit_action" value="save" />
        @if($cloneSourceToken)<x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />@endif

        <div class="card mb-3">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ $title }}</h5></div>
                    <div class="col-auto d-flex flex-wrap gap-2">
                        @if($mode === 'view' && $record && ! $record->trashed())
                            @can('inventory.stock_counts.print')<a class="btn btn-falcon-default btn-sm" href="{{ route($routePrefix.'.print', $record) }}"><span class="fas fa-print me-1"></span>{{ __('inventory.stock_counts.actions.print') }}</a>@endcan
                            @can('inventory.stock_counts.export')<a class="btn btn-falcon-default btn-sm" href="{{ route($routePrefix.'.export', $record) }}"><span class="fas fa-file-excel me-1"></span>{{ __('inventory.stock_counts.actions.export') }}</a>@endcan
                            @if($record->status === \Modules\Inventory\Models\StockCount::StatusCounted)
                                @can('inventory.stock_counts.approve')<button class="btn btn-success btn-sm js-approve-stock-count" type="button" data-url="{{ route($routePrefix.'.approve', $record) }}"><span class="fas fa-check me-1"></span>{{ __('inventory.stock_counts.actions.approve') }}</button>@endcan
                            @endif
                        @endif
                        @include('modules.finance.partials.form-actions', [
                            'resource' => 'inventory.stock_counts',
                            'routePrefix' => $routePrefix,
                            'canEditRecord' => $record?->isEditable() ?? true,
                            'canDeleteRecord' => $record?->isEditable() ?? true,
                        ])
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-danger d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                @if($record?->isApproved())<div class="alert alert-success">{{ __('inventory.stock_counts.messages.approved_locked') }}</div>@endif
                @if($record?->trashed())<div class="alert alert-danger">{{ __('inventory.stock_counts.messages.deleted_view') }}</div>@endif

                <h6 class="text-700 mb-3">{{ __('inventory.stock_counts.sections.header') }}</h6>
                <div class="row g-3">
                    @if($canControlDocumentNumber)
                        <div class="col-md-3">
                            <x-forms.label for="doc_number" :label="__('inventory.stock_counts.attributes.doc_number')" />
                            @if($isReadonly)<x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                            @else<x-forms.input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="1" value="{{ $documentNumberValue }}" />@endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-3"><x-forms.view-field for="doc_num" :label="__('inventory.stock_counts.attributes.document')" :value="$record?->doc_num" input-class="text-center" /></div>
                    @endif

                    <div class="col-md-3">
                        <x-forms.label for="count_date" :label="__('inventory.stock_counts.attributes.count_date')" required />
                        @if($isReadonly)<x-forms.view-field for="count_date" :value="$dateValue" dir="ltr" input-class="date-value text-center" />
                        @else<x-forms.date-input class="form-control text-center js-date-picker" id="count_date" name="count_date" type="text" value="{{ old('count_date', $dateValue) }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required />@endif
                        <div class="invalid-feedback d-block" data-error-for="count_date"></div>
                    </div>

                    <div class="col-md-3">
                        <x-forms.label for="branch_store_id" :label="__('inventory.stock_counts.attributes.store')" required />
                        @if($isReadonly)<x-forms.view-field for="branch_store_id" as="display" :value="$record?->branchStore?->name" />
                        @else
                            <x-forms.select class="form-select js-stock-count-store" id="branch_store_id" name="branch_store_id" required>
                                <option value="">{{ __('inventory.stock_counts.placeholders.select_store') }}</option>
                                @foreach($stores as $store)<option value="{{ $store->id }}" @selected($selectedStoreId === (string) $store->id)>{{ $store->name }}</option>@endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_store_id"></div>
                    </div>

                    <div class="col-md-3">
                        <x-forms.label for="warehouse_location_id" :label="__('inventory.stock_counts.attributes.location')" />
                        @if($isReadonly)<x-forms.view-field for="warehouse_location_id" as="display" :value="$record?->warehouseLocation ? trim($record->warehouseLocation->code.' — '.$record->warehouseLocation->name) : __('common.empty_value')" />
                        @else
                            <x-forms.select class="form-select js-stock-count-location" id="warehouse_location_id" name="warehouse_location_id">
                                <option value="">{{ __('inventory.stock_counts.placeholders.all_locations') }}</option>
                                @foreach($locations as $location)<option value="{{ $location->id }}" data-store-id="{{ $location->branch_store_id }}" @selected($selectedLocationId === (string) $location->id)>{{ trim($location->code.' — '.$location->name) }}</option>@endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="warehouse_location_id"></div>
                    </div>

                    @if($mode === 'view')
                        <div class="col-md-3"><x-forms.view-field for="status" :label="__('inventory.stock_counts.attributes.status')" :value="__('inventory.stock_counts.statuses.'.($record?->trashed() ? 'deleted' : $record?->status))" /></div>
                    @endif

                    <div class="col-12">
                        <x-forms.label for="notes" :label="__('inventory.stock_counts.attributes.notes')" />
                        <x-forms.textarea class="form-control" id="notes" name="notes" rows="2" :readonly="$isReadonly">{{ old('notes', $record?->notes) }}</x-forms.textarea>
                        <div class="invalid-feedback d-block" data-error-for="notes"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col-auto"><h6 class="mb-0">{{ __('inventory.stock_counts.sections.lines') }}</h6></div>
                    <div class="col-auto d-flex flex-wrap justify-content-end gap-2">
                        @unless($isReadonly)<button class="btn btn-falcon-default btn-sm js-stock-count-add-line" type="button"><span class="fas fa-plus me-1"></span>{{ __('inventory.stock_counts.actions.add_line') }}</button>@endunless
                        @unless($mode === 'view')
                            @include('modules.finance.partials.form-actions', ['resource' => 'inventory.stock_counts', 'routePrefix' => $routePrefix])
                        @endunless
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 js-stock-count-lines">
                        <thead class="bg-200"><tr>
                            <th style="min-width:280px">{{ __('inventory.stock_counts.attributes.product') }}</th>
                            <th style="min-width:110px">{{ __('inventory.stock_counts.attributes.unit') }}</th>
                            <th style="min-width:150px">{{ __('inventory.stock_counts.attributes.stock_status') }}</th>
                            <th style="min-width:140px">{{ __('inventory.stock_counts.attributes.batch_lot') }}</th>
                            <th class="text-center">{{ __('inventory.stock_counts.attributes.system_quantity') }}</th>
                            <th class="text-center" style="min-width:135px">{{ __('inventory.stock_counts.attributes.physical_quantity') }}</th>
                            <th class="text-center">{{ __('inventory.stock_counts.attributes.variance_quantity') }}</th>
                            <th class="text-center">{{ __('inventory.stock_counts.attributes.variance_type') }}</th>
                            <th style="min-width:180px">{{ __('inventory.stock_counts.attributes.variance_reason') }}</th>
                            <th style="min-width:160px">{{ __('inventory.stock_counts.attributes.line_notes') }}</th>
                            @unless($isReadonly)<th class="text-center" style="width:76px">{{ __('common.fields.actions') }}</th>@endunless
                        </tr></thead>
                        <tbody>
                            @forelse($existingLines as $index => $line)
                                @php
                                    $productDocNum = $line['product_doc_num'] ?? null;
                                    $productLabel = $line['product_label'] ?? $productDocNum;
                                    $variance = (string) ($line['variance_quantity'] ?? '0');
                                    $comparison = is_numeric(str_replace(',', '', $variance)) ? ((float) str_replace(',', '', $variance) <=> 0.0) : 0;
                                @endphp
                                <tr class="js-stock-count-line" data-index="{{ $index }}">
                                    <td>
                                        <x-forms.input type="hidden" name="lines[{{ $index }}][line_id]" value="{{ $line['line_id'] ?? '' }}" />
                                        <x-forms.input type="hidden" name="lines[{{ $index }}][_delete]" value="0" />
                                        @if($isReadonly)
                                            <div class="d-flex gap-1 stock-count-product-picker"><div class="form-control-plaintext flex-grow-1">{{ $productLabel }}</div><x-forms.input type="hidden" class="js-stock-count-product-value" value="{{ $productDocNum }}" /></div>
                                        @else
                                            <div class="d-flex align-items-start gap-1 stock-count-product-picker">
                                                <div class="flex-grow-1"><x-forms.select class="form-select js-select2-ajax js-stock-count-product" name="lines[{{ $index }}][product_doc_num]" data-url="{{ route($routePrefix.'.select2.products') }}" data-placeholder="{{ __('inventory.stock_counts.placeholders.select_product') }}" data-allow-clear="true" data-template="product-image">@if($productDocNum)<option value="{{ $productDocNum }}" data-unit-label="{{ $line['unit'] ?? '' }}" @if($line['imageUrl'] ?? null) data-image-url="{{ $line['imageUrl'] }}" @endif selected>{{ $productLabel }}</option>@endif</x-forms.select><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div></div>
                                                <button class="btn btn-falcon-default btn-sm js-stock-count-product-info" type="button" @disabled(! $productDocNum)><span class="fas fa-info-circle"></span></button>
                                                @if($canCreateProducts)<a class="btn btn-falcon-default btn-sm" href="{{ $productCreateUrl }}" target="_blank" rel="noopener"><span class="fas fa-plus"></span></a>@endif
                                            </div>
                                        @endif
                                    </td>
                                    <td><div class="stock-count-unit-display js-stock-count-unit">{{ $line['unit'] ?? '' }}</div></td>
                                    <td>@if($isReadonly)<div class="form-control-plaintext">{{ __('inventory.movements.stock_statuses.'.($line['stock_status'] ?? 'available')) }}</div>@else<x-forms.select class="form-select js-stock-count-status" name="lines[{{ $index }}][stock_status]">@foreach($stockStatuses as $status)<option value="{{ $status }}" @selected(($line['stock_status'] ?? 'available') === $status)>{{ __('inventory.movements.stock_statuses.'.$status) }}</option>@endforeach</x-forms.select>@endif</td>
                                    <td>@if($isReadonly)<div class="form-control-plaintext" dir="ltr">{{ $line['batch_lot'] ?: '—' }}</div>@else<x-forms.input class="form-control js-stock-count-batch" name="lines[{{ $index }}][batch_lot]" maxlength="100" value="{{ $line['batch_lot'] ?? '' }}" />@endif</td>
                                    <td class="text-center"><div class="form-control-plaintext stock-count-readonly-number js-stock-count-system" data-value="{{ str_replace(',', '', (string) ($line['system_quantity'] ?? 0)) }}" dir="ltr">{{ $line['system_quantity'] ?? 0 }}</div></td>
                                    <td class="text-center">@if($isReadonly)<div class="form-control-plaintext js-stock-count-physical" dir="ltr">{{ $line['physical_quantity'] ?? 0 }}</div>@else<x-forms.numeric-input class="text-center js-stock-count-physical" :name="'lines['.$index.'][physical_quantity]'" :value="$line['physical_quantity'] ?? ''" :scale="8" min="0" step="0.00000001" arrow-step="1" /><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.physical_quantity"></div>@endif</td>
                                    <td class="text-center"><div class="form-control-plaintext js-stock-count-variance" data-value="{{ str_replace(',', '', $variance) }}" dir="ltr">{{ $variance }}</div></td>
                                    <td class="text-center"><span class="badge rounded-pill stock-count-variance-badge js-stock-count-variance-type {{ $comparison < 0 ? 'badge-subtle-danger' : ($comparison > 0 ? 'badge-subtle-success' : 'badge-subtle-secondary') }}">{{ __('inventory.stock_counts.variance_types.'.($comparison < 0 ? 'shortage' : ($comparison > 0 ? 'surplus' : 'match'))) }}</span></td>
                                    <td>@if($isReadonly)<div class="form-control-plaintext">{{ $line['variance_reason'] ?: '—' }}</div>@else<x-forms.input class="form-control js-stock-count-reason" name="lines[{{ $index }}][variance_reason]" maxlength="100" value="{{ $line['variance_reason'] ?? '' }}" /><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.variance_reason"></div>@endif</td>
                                    <td>@if($isReadonly)<div class="form-control-plaintext">{{ $line['notes'] ?: '—' }}</div>@else<x-forms.input class="form-control js-stock-count-line-notes" name="lines[{{ $index }}][notes]" value="{{ $line['notes'] ?? '' }}" />@endif</td>
                                    @unless($isReadonly)<td class="text-center"><button class="btn btn-link text-600 p-0 me-2 js-stock-count-duplicate-line" type="button"><span class="fas fa-copy"></span></button><button class="btn btn-link text-danger p-0 js-stock-count-remove-line" type="button"><span class="fas fa-trash-alt"></span></button></td>@endunless
                                </tr>
                            @empty
                                <tr><td colspan="{{ $isReadonly ? 10 : 11 }}" class="text-center py-4">{{ __('inventory.stock_counts.empty.lines') }}</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-100 fw-semibold"><tr>
                            <td colspan="4">{{ __('inventory.stock_counts.summary.total') }}</td>
                            <td class="text-center js-stock-count-total-system" dir="ltr">{{ $numbers->format($totals['system']) }}</td>
                            <td class="text-center js-stock-count-total-physical" dir="ltr">{{ $numbers->format($totals['physical']) }}</td>
                            <td class="text-center js-stock-count-total-variance" dir="ltr">{{ $numbers->format($totals['variance']) }}</td>
                            <td colspan="{{ $isReadonly ? 3 : 4 }}"></td>
                        </tr></tfoot>
                    </table>
                </div>
                <div class="px-3 py-2"><div class="invalid-feedback d-block" data-error-for="lines"></div><div class="invalid-feedback d-block" data-error-for="document"></div></div>
            </div>
            <div class="card-footer">
                <div class="row g-3 mb-3">
                    <div class="col-md-4"><div class="border rounded-2 p-3 text-center"><div class="text-600">{{ __('inventory.stock_counts.summary.shortage') }}</div><strong class="text-danger js-stock-count-total-shortage">{{ $numbers->format($totals['shortage']) }}</strong></div></div>
                    <div class="col-md-4"><div class="border rounded-2 p-3 text-center"><div class="text-600">{{ __('inventory.stock_counts.summary.surplus') }}</div><strong class="text-success js-stock-count-total-surplus">{{ $numbers->format($totals['surplus']) }}</strong></div></div>
                    <div class="col-md-4"><div class="border rounded-2 p-3 text-center"><div class="text-600">{{ __('inventory.stock_counts.summary.net_variance') }}</div><strong class="js-stock-count-total-variance">{{ $numbers->format($totals['variance']) }}</strong></div></div>
                </div>
                <div class="d-flex flex-wrap justify-content-end gap-2">
                    @if($mode === 'view' && $record && ! $record->trashed())
                        @can('inventory.stock_counts.print')<a class="btn btn-falcon-default btn-sm" href="{{ route($routePrefix.'.print', $record) }}"><span class="fas fa-print me-1"></span>{{ __('inventory.stock_counts.actions.print') }}</a>@endcan
                        @can('inventory.stock_counts.export')<a class="btn btn-falcon-default btn-sm" href="{{ route($routePrefix.'.export', $record) }}"><span class="fas fa-file-excel me-1"></span>{{ __('inventory.stock_counts.actions.export') }}</a>@endcan
                        @if($record->status === \Modules\Inventory\Models\StockCount::StatusCounted)@can('inventory.stock_counts.approve')<button class="btn btn-success btn-sm js-approve-stock-count" type="button" data-url="{{ route($routePrefix.'.approve', $record) }}"><span class="fas fa-check me-1"></span>{{ __('inventory.stock_counts.actions.approve') }}</button>@endcan @endif
                    @endif
                    @include('modules.finance.partials.form-actions', [
                        'resource' => 'inventory.stock_counts',
                        'routePrefix' => $routePrefix,
                        'canEditRecord' => $record?->isEditable() ?? true,
                        'canDeleteRecord' => $record?->isEditable() ?? true,
                    ])
                </div>
            </div>
        </div>

        @if($mode === 'view')
            <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('inventory.stock_counts.sections.audit') }}</h6></div><div class="card-body"><x-audit-fields-row :metadata="$metadata" :show-deleted="$record?->trashed() ?? false" :show-restored="! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))" class="mt-0" /></div></div>
        @endif
    </form>

    @unless($isReadonly)
        <template id="stock-count-line-template">
            <tr class="js-stock-count-line" data-index="__INDEX__">
                <td><x-forms.input type="hidden" name="lines[__INDEX__][line_id]" value="" /><x-forms.input type="hidden" name="lines[__INDEX__][_delete]" value="0" /><div class="d-flex align-items-start gap-1 stock-count-product-picker"><div class="flex-grow-1"><x-forms.select class="form-select js-select2-ajax js-stock-count-product" name="lines[__INDEX__][product_doc_num]" data-url="{{ route($routePrefix.'.select2.products') }}" data-placeholder="{{ __('inventory.stock_counts.placeholders.select_product') }}" data-allow-clear="true" data-template="product-image"></x-forms.select><div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.product_doc_num"></div></div><button class="btn btn-falcon-default btn-sm js-stock-count-product-info" type="button" disabled><span class="fas fa-info-circle"></span></button>@if($canCreateProducts)<a class="btn btn-falcon-default btn-sm" href="{{ $productCreateUrl }}" target="_blank" rel="noopener"><span class="fas fa-plus"></span></a>@endif</div></td>
                <td><div class="stock-count-unit-display js-stock-count-unit"></div></td>
                <td><x-forms.select class="form-select js-stock-count-status" name="lines[__INDEX__][stock_status]">@foreach($stockStatuses as $status)<option value="{{ $status }}" @selected($status === 'available')>{{ __('inventory.movements.stock_statuses.'.$status) }}</option>@endforeach</x-forms.select></td>
                <td><x-forms.input class="form-control js-stock-count-batch" name="lines[__INDEX__][batch_lot]" maxlength="100" /></td>
                <td class="text-center"><div class="form-control-plaintext js-stock-count-system" data-value="0" dir="ltr">0</div></td>
                <td class="text-center"><x-forms.numeric-input class="text-center js-stock-count-physical" name="lines[__INDEX__][physical_quantity]" :scale="8" min="0" step="0.00000001" arrow-step="1" /><div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.physical_quantity"></div></td>
                <td class="text-center"><div class="form-control-plaintext js-stock-count-variance" data-value="0" dir="ltr">0</div></td>
                <td class="text-center"><span class="badge rounded-pill badge-subtle-secondary stock-count-variance-badge js-stock-count-variance-type">{{ __('inventory.stock_counts.variance_types.match') }}</span></td>
                <td><x-forms.input class="form-control js-stock-count-reason" name="lines[__INDEX__][variance_reason]" maxlength="100" /><div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.variance_reason"></div></td>
                <td><x-forms.input class="form-control js-stock-count-line-notes" name="lines[__INDEX__][notes]" /></td>
                <td class="text-center"><button class="btn btn-link text-600 p-0 me-2 js-stock-count-duplicate-line" type="button"><span class="fas fa-copy"></span></button><button class="btn btn-link text-danger p-0 js-stock-count-remove-line" type="button"><span class="fas fa-trash-alt"></span></button></td>
            </tr>
        </template>
    @endunless

    <div class="modal fade" id="stock-count-product-info-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">{{ __('inventory.stock_counts.product_modal.title') }}</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><img class="img-fluid rounded-2 stock-count-product-image js-stock-count-product-image d-none" src="" alt=""><div class="js-stock-count-product-no-image text-600 py-4 d-none">{{ __('inventory.stock_counts.product_modal.no_image') }}</div></div><dl class="row g-3 mb-0 js-stock-count-product-details"></dl></div></div></div></div>
@endsection

@push('scripts')
    @php
        $stockCountMessages = __('inventory.stock_counts.js');
        $stockCountProductLabels = [
            'doc_num' => __('products.attributes.doc_num'), 'name' => __('products.attributes.name'),
            'barcode' => __('products.attributes.barcode'), 'item_classification' => __('products.attributes.item_classification'),
            'unit' => __('products.attributes.unit'), 'category' => __('products.attributes.category'),
            'group' => __('products.attributes.group'), 'size' => __('products.attributes.size'),
            'color' => __('products.attributes.color'), 'model' => __('products.attributes.model'),
        ];
    @endphp
    <script>window.stockCountsMessages = @json($stockCountMessages); window.stockCountProductLabels = @json($stockCountProductLabels);</script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Inventory/stock-counts.js') }}"></script>
@endpush
