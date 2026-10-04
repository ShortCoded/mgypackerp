@if($line->product?->tracks_serials)
    @php
        $selectedSerialLayers = \Modules\Inventory\Models\InventoryReceiptLayer::query()->with('serialIdentity')
            ->where('company_id', $record->company_id)->where('branch_store_id', $line->receipt->branch_store_id)
            ->where('product_id', $line->product_id)->whereIn('id', old('lines.'.$index.'.serial_receipt_layer_ids', $draftLine?->serial_receipt_layer_ids ?? []))->get();
    @endphp
    <x-forms.label :label="__('inventory_serial.numbers')" />
    <x-forms.select variant="ajax" :name="'lines['.$index.'][serial_receipt_layer_ids][]'" multiple
        :url="route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $line->receipt->branchStore->public_uuid, 'product_doc_num' => $line->product->doc_num, 'receipt_line_public_id' => $line->public_id])"
        data-depends-on="#return_date" data-dependent-param="document_date" :placeholder="__('inventory_serial.select_existing')">
        @foreach($selectedSerialLayers as $serialLayer)
            <option value="{{ $serialLayer->id }}" selected>{{ $serialLayer->serialIdentity?->serial_number }}</option>
        @endforeach
    </x-forms.select>
@endif
