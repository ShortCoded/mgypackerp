@extends('layouts.app')

@php
    $isEdit = $record !== null;
    $currentType = old('document_type', $record?->document_type);
    $currentSourceStoreId = old('branch_store_id', $record?->branch_store_id);
    $currentDestinationStoreId = old('destination_branch_store_id', $record?->destination_branch_store_id);
    $recordLines = $record?->lines?->map(fn ($line) => [
        'product_id' => (string) $line->product_id,
        'product_text' => trim(($line->product?->doc_num ?? '').' — '.($line->product?->name ?? '')),
        'quantity' => $line->quantity,
        'batch_lot' => $line->batch_lot,
        'unit_cost' => $line->unit_cost,
        'manufacture_date' => $line->manufacture_date?->toDateString(),
        'expiry_date' => $line->expiry_date?->toDateString(),
        'notes' => $line->notes,
    ])->values()->all() ?? [];
    $initialLines = old('lines', $recordLines);
@endphp

@section('title', $isEdit ? __('inventory.movements.edit') : __('inventory.movements.create'))

@section('content')
    <form class="production-mobile-workflow" method="POST" action="{{ $isEdit ? route('admin.inventory.documents.update', $record) : route('admin.inventory.documents.store') }}" data-inventory-movement-form novalidate>
        @csrf
        @if($isEdit) @method('PUT') @endif
        <x-forms.line-item-cards />

        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="card mb-3">
            <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                <div>
                    <a class="small" href="{{ route('admin.inventory.documents.index') }}">{{ __('inventory.movements.title') }}</a>
                    <h5 class="mb-0">{{ $isEdit ? __('inventory.movements.edit_document', ['document' => $record->doc_num]) : __('inventory.movements.create') }}</h5>
                </div>
                <span class="badge rounded-pill badge-subtle-info">{{ __('inventory.movements.posting_notice') }}</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="inventory-document-type">{{ __('inventory.movements.fields.type') }}</label>
                        <x-forms.select variant="local" id="inventory-document-type" name="document_type" :placeholder="__('inventory.movements.placeholders.select_type')" :allow-clear="false" data-movement-type required>
                            <option value=""></option>
                            @foreach($allowedDocumentTypes as $type)
                                <option value="{{ $type }}" @selected($currentType === $type)>{{ __('inventory.movements.types.'.$type) }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3" data-source-store-field>
                        <label class="form-label" for="inventory-source-store" data-source-store-label>{{ __('inventory.movements.fields.source_store') }}</label>
                        <x-forms.select variant="ajax" id="inventory-source-store" name="branch_store_id" :url="route('admin.inventory.documents.select2.stores')" :placeholder="__('inventory.movements.placeholders.select_store')" required>
                            @if($record?->branchStore && (string) $currentSourceStoreId === (string) $record->branch_store_id)<option value="{{ $record->branch_store_id }}" selected>{{ $record->branchStore->name }}</option>@endif
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3" data-destination-store-field>
                        <label class="form-label" for="inventory-destination-store">{{ __('inventory.movements.fields.destination_store') }}</label>
                        <x-forms.select variant="ajax" id="inventory-destination-store" name="destination_branch_store_id" :url="route('admin.inventory.documents.select2.stores')" :placeholder="__('inventory.movements.placeholders.select_destination_store')">
                            @if($record?->destinationBranchStore && (string) $currentDestinationStoreId === (string) $record->destination_branch_store_id)<option value="{{ $record->destination_branch_store_id }}" selected>{{ $record->destinationBranchStore->name }}</option>@endif
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="inventory-document-date">{{ __('inventory.movements.fields.date') }}</label>
                        <x-forms.date-input id="inventory-document-date" name="document_date" :value="old('document_date', $record?->document_date?->toDateString() ?? now()->toDateString())" required />
                    </div>
                    <div class="col-12 col-md-6 col-xl-3" data-source-status-field>
                        <label class="form-label" for="inventory-source-status">{{ __('inventory.movements.fields.source_status') }}</label>
                        <x-forms.select variant="local" id="inventory-source-status" name="source_stock_status" :allow-clear="false">
                            @foreach($stockStatuses as $status)<option value="{{ $status }}" @selected(old('source_stock_status', $record?->source_stock_status ?? 'available') === $status)>{{ __('inventory.movements.stock_statuses.'.$status) }}</option>@endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3" data-destination-status-field>
                        <label class="form-label" for="inventory-destination-status">{{ __('inventory.movements.fields.destination_status') }}</label>
                        <x-forms.select variant="local" id="inventory-destination-status" name="destination_stock_status" :allow-clear="false">
                            @foreach($stockStatuses as $status)<option value="{{ $status }}" @selected(old('destination_stock_status', $record?->destination_stock_status ?? 'available') === $status)>{{ __('inventory.movements.stock_statuses.'.$status) }}</option>@endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="inventory-movement-reason">{{ __('inventory.movements.fields.reason') }}</label>
                        <x-forms.input id="inventory-movement-reason" name="movement_reason" :value="old('movement_reason', $record?->movement_reason ?? $record?->purpose)" maxlength="255" required />
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="inventory-movement-notes">{{ __('inventory.movements.fields.notes') }}</label>
                        <x-forms.textarea id="inventory-movement-notes" name="notes" rows="2">{{ old('notes', $record?->notes) }}</x-forms.textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h5 class="mb-0">{{ __('inventory.movements.fields.lines') }}</h5>
                <button class="btn btn-falcon-primary btn-sm" type="button" data-add-inventory-line><span class="fas fa-plus me-1"></span>{{ __('inventory.movements.actions.add_line') }}</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0" data-inventory-lines>
                        <thead class="bg-100 text-900"><tr><th style="min-width:280px">{{ __('inventory.movements.fields.product') }}</th><th style="min-width:130px">{{ __('inventory.movements.fields.quantity') }}</th><th style="min-width:160px">{{ __('inventory.movements.fields.batch_lot') }}</th><th style="min-width:155px" data-cost-heading>{{ __('inventory.movements.fields.unit_cost') }}</th><th style="min-width:155px">{{ __('inventory.movements.fields.manufacture_date') }}</th><th style="min-width:155px">{{ __('inventory.movements.fields.expiry_date') }}</th><th style="min-width:220px">{{ __('inventory.movements.fields.notes') }}</th><th></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mobile-action-row">
            <a class="btn btn-falcon-default" href="{{ route('admin.inventory.documents.index') }}">{{ __('common.actions.cancel') }}</a>
            <button class="btn btn-falcon-primary" type="submit" name="submit_action" value="save_and_edit"><span class="fas fa-save me-1"></span>{{ __('inventory.movements.actions.save_draft') }}</button>
            <button class="btn btn-primary" type="submit" name="submit_action" value="post_and_view"><span class="fas fa-check me-1"></span>{{ __('inventory.movements.actions.post_and_view') }}</button>
        </div>
    </form>

    <template id="inventory-line-template">
        <tr data-inventory-line>
            <td><x-forms.select variant="ajax" class="form-select-sm" name="lines[__INDEX__][product_id]" :url="route('admin.inventory.documents.select2.products')" :placeholder="__('inventory.movements.placeholders.select_product')" data-template="product-image" required></x-forms.select></td>
            <td><x-forms.input class="form-control-sm" type="number" step="0.00000001" min="0.00000001" name="lines[__INDEX__][quantity]" required /></td>
            <td><x-forms.input class="form-control-sm" name="lines[__INDEX__][batch_lot]" maxlength="100" /></td>
            <td data-cost-cell><x-forms.input class="form-control-sm" type="number" step="0.00000001" min="0.00000001" name="lines[__INDEX__][unit_cost]" data-unit-cost /></td>
            <td><x-forms.date-input class="form-control-sm" name="lines[__INDEX__][manufacture_date]" /></td>
            <td><x-forms.date-input class="form-control-sm" name="lines[__INDEX__][expiry_date]" /></td>
            <td><x-forms.input class="form-control-sm" name="lines[__INDEX__][notes]" /></td>
            <td class="text-center"><button class="btn btn-link text-danger p-0" type="button" data-remove-inventory-line title="{{ __('inventory.movements.actions.remove_line') }}"><span class="fas fa-trash-alt"></span></button></td>
        </tr>
    </template>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
@push('scripts')
    <script>
        window.inventoryMovementUi = @json([
            'store' => __('inventory.movements.fields.store'),
            'sourceStore' => __('inventory.movements.fields.source_store'),
        ]);
        window.inventoryMovementLines = @json($initialLines);
    </script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Inventory/inventory-movements.js') }}"></script>
@endpush
