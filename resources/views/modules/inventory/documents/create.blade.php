@extends('layouts.app')

@php
    $isEdit = $record !== null && ! ($isClone ?? false);
    $mode = ($isClone ?? false) ? 'clone' : ($isEdit ? 'edit' : 'create');
    $currentType = old('document_type', $record?->document_type);
    $currentSourceStoreUuid = old('branch_store_uuid', $record?->branchStore?->public_uuid);
    $currentDestinationStoreUuid = old('destination_branch_store_uuid', $record?->destinationBranchStore?->public_uuid);
    $recordLines = $record?->lines?->map(fn ($line) => [
        'product_doc_num' => $line->product?->doc_num,
        'product_text' => trim(($line->product?->doc_num ?? '').' — '.($line->product?->name ?? '')),
        'quantity' => $line->quantity,
        'batch_lot' => $line->batch_lot,
        'manufacture_date' => $line->manufacture_date?->toDateString(),
        'expiry_date' => $line->expiry_date?->toDateString(),
        'notes' => $line->notes,
    ])->values()->all() ?? [];
    $initialLines = old('lines', $recordLines ?: [[]]);
    $title = $isEdit
        ? __('inventory.movements.edit_document', ['document' => $record->doc_num])
        : __('inventory.movements.'.$mode);
@endphp

@section('title', $title)

@section('content')
    <form method="POST" action="{{ $isEdit ? route('admin.inventory.documents.update', $record) : route('admin.inventory.documents.store') }}" data-inventory-movement-form novalidate>
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif
        <x-forms.line-item-cards :line-label="__('inventory.movements.line')" />
        <x-forms.input type="hidden" name="submit_action" value="save" />

        <div class="card mb-3">
            <div class="card-header py-2">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ $title }}</h5>
                        @if($isClone ?? false)
                            <span class="badge badge-subtle-info mt-1">{{ __('inventory.movements.copy_of', ['document' => $record->doc_num]) }}</span>
                        @endif
                    </div>
                    <div class="col-auto">
                        @include('modules.inventory.documents.partials.form-actions', compact('mode', 'record'))
                    </div>
                </div>
            </div>

            <div class="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <h6 class="text-700 mb-3">{{ __('inventory.movements.header_data') }}</h6>
                <div class="row g-3 align-items-start">
                    <div class="col-md-6 col-xl-3">
                        <x-forms.label for="inventory-document-type" :label="__('inventory.movements.fields.type')" required />
                        <x-forms.select variant="local" id="inventory-document-type" name="document_type" :placeholder="__('inventory.movements.placeholders.select_type')" :allow-clear="false" data-movement-type required>
                            <option value=""></option>
                            @foreach($allowedDocumentTypes as $type)
                                <option value="{{ $type }}" @selected($currentType === $type)>{{ __('inventory.movements.types.'.$type) }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="document_type"></div>
                    </div>
                    <div class="col-md-6 col-xl-3" data-source-store-field>
                        <x-forms.label for="inventory-source-store" :label="__('inventory.movements.fields.source_store')" required data-source-store-label />
                        <x-forms.select variant="ajax" id="inventory-source-store" name="branch_store_uuid" :url="route('admin.inventory.documents.select2.stores')" :placeholder="__('inventory.movements.placeholders.select_store')" required>
                            @if($record?->branchStore && $currentSourceStoreUuid === $record->branchStore->public_uuid)
                                <option value="{{ $record->branchStore->public_uuid }}" selected>{{ $record->branchStore->name }}</option>
                            @endif
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="branch_store_uuid"></div>
                    </div>
                    <div class="col-md-6 col-xl-3" data-destination-store-field>
                        <x-forms.label for="inventory-destination-store" :label="__('inventory.movements.fields.destination_store')" required />
                        <x-forms.select variant="ajax" id="inventory-destination-store" name="destination_branch_store_uuid" :url="route('admin.inventory.documents.select2.stores', ['scope' => 'destination'])" :placeholder="__('inventory.movements.placeholders.select_destination_store')">
                            @if($record?->destinationBranchStore && $currentDestinationStoreUuid === $record->destinationBranchStore->public_uuid)
                                <option value="{{ $record->destinationBranchStore->public_uuid }}" selected>{{ $record->destinationBranchStore->branch?->name }} — {{ $record->destinationBranchStore->name }}</option>
                            @endif
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="destination_branch_store_uuid"></div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <x-forms.label for="inventory-document-date" :label="__('inventory.movements.fields.date')" required />
                        <x-forms.date-input id="inventory-document-date" name="document_date" :value="old('document_date', $record?->document_date?->toDateString() ?? now()->toDateString())" required />
                        <div class="invalid-feedback d-block" data-error-for="document_date"></div>
                    </div>
                    <div class="col-md-6 col-xl-3" data-source-status-field>
                        <x-forms.label for="inventory-source-status" :label="__('inventory.movements.fields.source_status')" />
                        <x-forms.select variant="local" id="inventory-source-status" name="source_stock_status" :allow-clear="false">
                            @foreach($stockStatuses as $status)
                                <option value="{{ $status }}" @selected(old('source_stock_status', $record?->source_stock_status ?? 'available') === $status)>{{ __('inventory.movements.stock_statuses.'.$status) }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="source_stock_status"></div>
                    </div>
                    <div class="col-md-6 col-xl-3" data-destination-status-field>
                        <x-forms.label for="inventory-destination-status" :label="__('inventory.movements.fields.destination_status')" />
                        <x-forms.select variant="local" id="inventory-destination-status" name="destination_stock_status" :allow-clear="false">
                            @foreach($stockStatuses as $status)
                                <option value="{{ $status }}" @selected(old('destination_stock_status', $record?->destination_stock_status ?? 'available') === $status)>{{ __('inventory.movements.stock_statuses.'.$status) }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback d-block" data-error-for="destination_stock_status"></div>
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="inventory-movement-reason" :label="__('inventory.movements.fields.reason')" required />
                        <x-forms.input id="inventory-movement-reason" name="movement_reason" :value="old('movement_reason', $record?->movement_reason ?? $record?->purpose)" maxlength="255" required />
                        <div class="invalid-feedback d-block" data-error-for="movement_reason"></div>
                    </div>
                    <div class="col-12">
                        <x-forms.label for="inventory-movement-notes" :label="__('inventory.movements.fields.notes')" />
                        <x-forms.input id="inventory-movement-notes" name="notes" :value="old('notes', $record?->notes)" maxlength="5000" />
                        <div class="invalid-feedback d-block" data-error-for="notes"></div>
                    </div>
                </div>

                <div class="border-top mt-4 pt-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h6 class="text-700 mb-0">{{ __('inventory.movements.fields.lines') }}</h6>
                        <button class="btn btn-falcon-default btn-sm" type="button" data-add-inventory-line title="{{ __('inventory.movements.add_line_shortcut') }}" data-bs-title="{{ __('inventory.movements.add_line_shortcut') }}">
                            <span class="fas fa-plus me-1"></span>{{ __('inventory.movements.actions.add_line') }}
                        </button>
                    </div>
                    <div class="table-responsive" role="region" aria-label="{{ __('inventory.movements.fields.lines') }}" tabindex="0">
                        <table class="table table-sm table-hover align-middle mb-0 erp-entry-lines-table erp-entry-lines-table-wide" data-inventory-lines>
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-center erp-entry-line-number">#</th>
                                    <th class="erp-entry-line-item">{{ __('inventory.movements.fields.product') }}</th>
                                    <th class="erp-entry-line-quantity">{{ __('inventory.movements.fields.quantity') }}</th>
                                    <th class="erp-entry-line-batch">{{ __('inventory.movements.fields.batch_lot') }}</th>
                                    <th class="erp-entry-line-date">{{ __('inventory.movements.fields.manufacture_date') }}</th>
                                    <th class="erp-entry-line-date">{{ __('inventory.movements.fields.expiry_date') }}</th>
                                    <th class="erp-entry-line-text">{{ __('inventory.movements.fields.notes') }}</th>
                                    <th class="erp-entry-line-actions">{{ __('common.fields.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                        <button class="btn btn-falcon-default btn-sm" type="button" data-add-inventory-line title="{{ __('inventory.movements.add_line_shortcut') }}" data-bs-title="{{ __('inventory.movements.add_line_shortcut') }}">
                            <span class="fas fa-plus me-1"></span>{{ __('inventory.movements.actions.add_line') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                @include('modules.inventory.documents.partials.form-actions', compact('mode', 'record'))
            </div>
        </div>
    </form>

    <template id="inventory-line-template">
        <tr data-inventory-line>
            <td class="text-center erp-entry-line-number" data-row-number></td>
            <td class="erp-entry-line-item">
                <x-forms.select variant="ajax" name="lines[__INDEX__][product_doc_num]" :url="route('admin.inventory.documents.select2.products')" :placeholder="__('inventory.movements.placeholders.select_product')" data-template="product-image" required></x-forms.select>
            </td>
            <td class="erp-entry-line-quantity">
                <x-forms.numeric-input :scale="8" step="0.00000001" arrow-step="1" min="0.00000001" name="lines[__INDEX__][quantity]" required />
            </td>
            <td class="erp-entry-line-batch">
                <x-forms.input name="lines[__INDEX__][batch_lot]" maxlength="100" />
            </td>
            <td class="erp-entry-line-date">
                <x-forms.date-input name="lines[__INDEX__][manufacture_date]" />
            </td>
            <td class="erp-entry-line-date">
                <x-forms.date-input name="lines[__INDEX__][expiry_date]" />
            </td>
            <td class="erp-entry-line-text">
                <x-forms.input name="lines[__INDEX__][notes]" maxlength="2000" />
            </td>
            <td class="erp-entry-line-actions">
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-link text-600 p-0" type="button" data-duplicate-inventory-line title="{{ __('inventory.movements.duplicate_line_shortcut') }}" data-bs-title="{{ __('inventory.movements.duplicate_line_shortcut') }}" aria-label="{{ __('inventory.movements.actions.duplicate_line') }}">
                        <span class="fas fa-copy"></span>
                    </button>
                    <button class="btn btn-link text-danger p-0" type="button" data-remove-inventory-line title="{{ __('inventory.movements.delete_line_shortcut') }}" data-bs-title="{{ __('inventory.movements.delete_line_shortcut') }}" aria-label="{{ __('inventory.movements.actions.remove_line') }}">
                        <span class="fas fa-trash-alt"></span>
                    </button>
                </div>
            </td>
        </tr>
    </template>

    <script type="application/json" data-inventory-movement-ui>{!! \Illuminate\Support\Js::encode([
        'store' => __('inventory.movements.fields.store'),
        'sourceStore' => __('inventory.movements.fields.source_store'),
    ]) !!}</script>
    <script type="application/json" data-inventory-movement-lines>{!! \Illuminate\Support\Js::encode($initialLines) !!}</script>
@endsection

@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Inventory/inventory-movements.js') }}"></script>
@endpush
