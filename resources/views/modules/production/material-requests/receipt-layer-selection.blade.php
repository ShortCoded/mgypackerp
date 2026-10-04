@php
    $materialCostPolicy = isset($materialRequest) ? app(\Modules\Inventory\Services\InventoryCostPolicyService::class)->resolve((int)$materialRequest->company_id, (int)$materialRequest->branch_store_id, now()->toDateString()) : null;
@endphp
@if(($selectionForBatch ?? false) || $line->product?->tracks_serials || ($materialCostPolicy['method'] ?? null) === \Modules\Inventory\Models\InventoryCostPolicy::SpecificIdentification)
@php
    $layerUrl = $layerSelectorUrl ?? route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $materialRequest->store->public_uuid, 'product_doc_num' => $line->product->doc_num, 'document_date' => now()->toDateString(), 'stock_status' => 'available', 'material_request_line_id' => $line->id]);
    $layerCompanyId = $selectionCompanyId ?? $materialRequest->company_id;
    $layerStoreId = $selectionStoreId ?? $materialRequest->branch_store_id;
    $layerPolicyId = $materialCostPolicy['policy_id'] ?? ($layerStoreId > 0 ? app(\Modules\Inventory\Services\InventoryCostPolicyService::class)->resolve((int) $layerCompanyId, (int) $layerStoreId, now()->toDateString())['policy_id'] : null);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $oldSelections = old('lines.'.$index.'.receipt_layers', [[]]);
    $selections = collect(is_array($oldSelections) ? $oldSelections : [[]])->filter(fn ($selection, $key) => is_array($selection) && is_numeric($key))->take(100);
    $selectedIds = $selections->pluck('layer_id')->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id))->all();
    $selectedLayers = \Modules\Inventory\Models\InventoryReceiptLayer::query()->with(['receiptTransaction', 'serialIdentity'])->withBookCostBasis($layerPolicyId)
        ->where('company_id', $layerCompanyId)->where('branch_store_id', $layerStoreId)
        ->where('product_id', $line->product_id)->where('stock_status', 'available')->whereIn('id', $selectedIds)->get()->keyBy('id');
    $nextSlice = $selections->isEmpty() ? 0 : ((int) $selections->keys()->max() + 1);
@endphp
<div class="mt-3" data-material-layer-selections data-line-index="{{ $index }}" data-next-slice="{{ $nextSlice }}">
    <h6>{{ __('inventory_cost_policy.selected_layer') }}</h6>
    <p class="small text-muted">{{ __(($selectionForBatch ?? false) ? 'inventory_cost_policy.batch_selection_help' : 'inventory_cost_policy.production_selection_help') }}</p>
    <div data-material-layer-rows>
        @foreach($selections as $selectionIndex => $selection)
        <div class="row g-2 mb-2" data-material-layer-row>
            <div class="col-7"><x-forms.select variant="ajax" :name="'lines['.$index.'][receipt_layers]['.$selectionIndex.'][layer_id]'" :url="$layerUrl" :placeholder="__('inventory_cost_policy.selected_layer')" :data-depends-on="$layerStoreDependency ?? null" data-dependent-param="branch_store_uuid" data-disable-when-dependency-empty="true">
                @php($selectedLayer = is_scalar($selection['layer_id'] ?? null) ? $selectedLayers->get($selection['layer_id']) : null)
                @if($selectedLayer)<option value="{{ $selectedLayer->id }}" selected>{{ $selectedLayer->receiptTransaction?->source_doc_num }} — {{ $selectedLayer->serialIdentity?->serial_number ?? $selectedLayer->batch_lot ?? __('inventory_cost_policy.no_batch') }} — {{ $numbers->format($selectedLayer->remaining_quantity) }} — {{ $numbers->format($selectedLayer->bookUnitCostForPolicy($layerPolicyId)) }}</option>@endif
            </x-forms.select></div>
            <div class="col-4"><x-forms.numeric-input :name="'lines['.$index.'][receipt_layers]['.$selectionIndex.'][quantity]'" :scale="8" :value="$selection['quantity'] ?? null" /></div>
            <div class="col-1"><button type="button" class="btn btn-link text-danger" data-remove-material-layer aria-label="{{ __('inventory_cost_policy.remove_selection') }}">×</button></div>
        </div>
        @endforeach
    </div>
    <template data-material-layer-template><div class="row g-2 mb-2" data-material-layer-row>
        <div class="col-7"><x-forms.select variant="ajax" :name="'lines['.$index.'][receipt_layers][__SLICE__][layer_id]'" :url="$layerUrl" :placeholder="__('inventory_cost_policy.selected_layer')" :data-depends-on="$layerStoreDependency ?? null" data-dependent-param="branch_store_uuid" data-disable-when-dependency-empty="true" /></div>
        <div class="col-4"><x-forms.numeric-input :name="'lines['.$index.'][receipt_layers][__SLICE__][quantity]'" :scale="8" /></div>
        <div class="col-1"><button type="button" class="btn btn-link text-danger" data-remove-material-layer aria-label="{{ __('inventory_cost_policy.remove_selection') }}">×</button></div>
    </div></template>
    <button type="button" class="btn btn-falcon-default btn-sm" data-add-material-layer>{{ __('inventory_cost_policy.add_selection') }}</button>
</div>
@once
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/material-layer-selection.js') }}"></script>@endpush
@endonce
@endif
