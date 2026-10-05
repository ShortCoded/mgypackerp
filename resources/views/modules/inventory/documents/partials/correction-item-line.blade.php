@php($product = \Modules\Core\Models\Product::query()->where('company_id', $document->company_id)->where('doc_num', $item['product_doc_num'] ?? null)->first())
<tr data-correction-line>
    <td><x-forms.input type="hidden" name="lines[{{ $index }}][line_id]" value="{{ $item['line_id'] ?? '' }}" />
        <x-forms.select class="js-select2-ajax" name="lines[{{ $index }}][product_doc_num]" data-url="{{ route('admin.inventory.documents.select2.products') }}" data-correction-product required>
            @if($product)<option selected value="{{ $product->doc_num }}">{{ $product->doc_num }} — {{ $product->name }}</option>@endif
        </x-forms.select>
    </td>
    <td><x-forms.select name="lines[{{ $index }}][unit_doc_num]" data-correction-unit required>
        @if($product)@foreach(app(\Modules\Core\Services\ProductComponentUnitOptionsService::class)->options($product) as $option)<option value="{{ $option['id'] }}" @selected(($item['unit_doc_num'] ?? '') === $option['id'])>{{ $option['text'] }}</option>@endforeach @endif
    </x-forms.select></td>
    <td><x-forms.numeric-input name="lines[{{ $index }}][quantity]" :value="$item['quantity'] ?? ''" :scale="8" min="0.00000001" required /></td>
    <td>@if($receiptCost)<x-forms.numeric-input name="lines[{{ $index }}][unit_cost]" :value="$item['unit_cost'] ?? ''" :scale="8" min="0" />@else—@endif</td>
    <td>@if($receiptCost)<x-forms.input name="lines[{{ $index }}][serial_number]" :value="$item['serial_number'] ?? ''" :placeholder="__('inventory_serial.numbers')" data-correction-item-metadata />@else
        <x-forms.select class="js-select2-ajax" name="lines[{{ $index }}][selected_receipt_layer_id]" data-correction-layer data-layer-url="{{ route('admin.inventory.documents.select2.receipt-layers') }}" data-url="{{ route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $document->branchStore->public_uuid, 'product_doc_num' => $item['product_doc_num'] ?? '', 'document_date' => now()->toDateString(), 'stock_status' => $document->source_stock_status ?? 'available']) }}">
            @if(filled($item['selected_receipt_layer_id'] ?? null))<option selected value="{{ $item['selected_receipt_layer_id'] }}">{{ $product?->name }} — {{ __('inventory_correction.legacy_source_layer') }}</option>@endif
        </x-forms.select>@endif
        <x-forms.input name="lines[{{ $index }}][batch_lot]" :value="$item['batch_lot'] ?? ''" :placeholder="__('Batch / lot')" data-correction-item-metadata />
        <x-forms.date-input name="lines[{{ $index }}][manufacture_date]" :value="$item['manufacture_date'] ?? ''" />
        <x-forms.date-input name="lines[{{ $index }}][expiry_date]" :value="$item['expiry_date'] ?? ''" />
    </td>
    <td><button type="button" class="btn btn-link text-danger" data-correction-remove aria-label="{{ __('Remove line') }}">&times;</button></td>
</tr>
