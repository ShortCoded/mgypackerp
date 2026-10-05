@extends('layouts.app')
@section('title', __('inventory_correction.title'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
@php($receiptCost = in_array($document->document_type, [\Modules\Inventory\Models\InventoryDocument::TypeReceipt, \Modules\Inventory\Models\InventoryDocument::TypeReturn, \Modules\Inventory\Models\InventoryDocument::TypeAdjustmentIn], true))
<div class="card mb-3"><div class="card-header d-flex justify-content-between gap-2"><h5>{{ __('inventory_correction.title') }} — {{ $document->doc_num }}</h5><a href="{{ route('admin.inventory.documents.show', $document) }}">{{ __('common.actions.back') }}</a></div><div class="card-body">
<p>{{ __('inventory_correction.intro') }}</p><p>{{ __('inventory_correction.target_period') }}: {{ $target?->name }}</p>
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@if($steps)
<div class="alert alert-warning">{{ __('inventory_correction.dependencies') }}</div>
<div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>{{ __('Document') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Value') }}</th><th>{{ __('inventory_correction.action') }}</th></tr></thead><tbody>
@foreach($steps as $index => $step)<tr><td>{{ $index + 1 }}</td><td><a href="{{ $step['source_url'] }}">{{ $step['document'] }}</a></td><td>{{ $numbers->format($step['quantity_out']) }}</td><td>{{ $numbers->format($step['value_out']) }}</td><td>@if($step['correction_url'])<a href="{{ $step['correction_url'] }}">{{ __('inventory_correction.title') }}</a>@else{{ __('inventory_correction.source_owned') }}@endif</td></tr>@endforeach
</tbody></table></div>
@elseif($legacy_repair !== null)
<p>{{ __('inventory_correction.legacy_intro') }}</p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('inventory_correction.legacy_misplaced') }}</th><th>{{ __('inventory_correction.legacy_exchanged') }}</th><th>{{ __('inventory_correction.legacy_value') }}</th></tr></thead><tbody><tr><td>{{ $numbers->format($legacy_repair['misplaced_quantity']) }}</td><td>{{ $numbers->format($legacy_repair['exchanged_quantity']) }}</td><td>{{ $numbers->format($legacy_repair['known_value_change']) }}</td></tr></tbody></table></div>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('inventory_correction.legacy_source_layer') }}</th><th>{{ __('inventory_correction.legacy_foreign_layers') }}</th></tr></thead><tbody>@foreach($legacy_repair['lines'] as $line)<tr><td>{{ $document->lines->firstWhere('product_id', $line['product_id'])?->product?->name }}</td><td>{{ $numbers->format($line['quantity']) }}</td><td dir="ltr">{{ $line['own_layer_id'] }}</td><td dir="ltr">{{ implode(', ', array_unique(array_column($line['foreign_allocations'], 'inventory_receipt_layer_id'))) ?: '—' }}</td></tr>@endforeach</tbody></table></div>
@foreach($legacy_repair['affected_documents'] as $affected)<p>{{ __('inventory_correction.legacy_affected') }}: @can('inventory.documents.view')<a href="{{ route('admin.inventory.documents.show', $affected['id']) }}">{{ $affected['number'] }}</a>@else{{ $affected['number'] }}@endcan</p>@endforeach
@include('modules.inventory.documents.partials.legacy-allocation-review', ['repair' => $legacy_repair])
@if(bccomp($legacy_repair['misplaced_quantity'], '0', 8) > 0)
@can('inventory.documents.correct_prepare')
<form method="POST" action="{{ route('admin.inventory.documents.corrections.store', $document) }}" novalidate>@csrf
<x-forms.input type="hidden" name="source_fingerprint" :value="$source_fingerprint" /><x-forms.input type="hidden" name="operation" value="repair_lineage" />
<div class="row g-3 mb-3"><div class="col-md-4"><x-forms.label for="posting_date" :label="__('inventory_correction.posting_date')" required /><x-forms.date-input id="posting_date" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div><div class="col-md-8"><x-forms.label for="reason" :label="__('inventory_correction.reason')" required /><x-forms.textarea id="reason" name="reason">{{ old('reason') }}</x-forms.textarea></div></div><button class="btn btn-primary">{{ __('inventory_correction.prepare') }}</button></form>
@endcan
@else<p>{{ __('inventory_correction.legacy_consistent') }}</p>@endif
@elseif($document->status === \Modules\Inventory\Models\InventoryDocument::StatusPosted)
@can('inventory.documents.correct_prepare')
@if($item_correction_supported)
<form method="POST" action="{{ route('admin.inventory.documents.corrections.store', $document) }}" class="border rounded p-3 mb-4" data-posted-document-correction data-store-uuid="{{ $document->branchStore->public_uuid }}" data-stock-status="{{ $document->source_stock_status ?? 'available' }}" novalidate>@csrf
    <h6>{{ __('inventory_correction.replace_items') }}</h6><p>{{ __('inventory_correction.items_review') }}</p>
    <x-forms.input type="hidden" name="operation" value="replace_items" /><x-forms.input type="hidden" name="source_fingerprint" value="{{ $source_fingerprint }}" />
    <div class="row g-3 mb-3"><div class="col-md-4"><x-forms.label for="item-posting-date" :label="__('inventory_correction.posting_date')" required /><x-forms.date-input id="item-posting-date" name="posting_date" :value="old('posting_date', now()->toDateString())" required /></div><div class="col-md-8"><x-forms.label for="item-reason" :label="__('inventory_correction.reason')" required /><x-forms.textarea id="item-reason" name="reason">{{ old('reason') }}</x-forms.textarea></div></div>
    <button type="button" class="btn btn-falcon-default mb-2" data-correction-add>{{ __('inventory.movements.actions.add_line') }}</button>
    <div class="table-responsive"><table class="table"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Unit') }}</th><th>{{ __('inventory_correction.item_quantity') }}</th><th>{{ __('inventory_correction.base_cost') }}</th><th>{{ __('inventory_serial.numbers') }} / {{ __('Batch / lot') }}</th><th></th></tr></thead><tbody data-correction-lines>
    @php($items = old('operation') === 'replace_items' ? old('lines', []) : $document->lines->map(fn ($line) => ['line_id' => $line->id, 'product_doc_num' => $line->product->doc_num, 'unit_doc_num' => $line->transactionUnit?->doc_num ?? $line->unit?->doc_num, 'quantity' => $line->transaction_quantity, 'unit_cost' => $line->unit_cost, 'selected_receipt_layer_id' => $line->selected_receipt_layer_id, 'serial_number' => $line->serialIdentity?->serial_number, 'batch_lot' => $line->batch_lot, 'manufacture_date' => $line->manufacture_date?->toDateString(), 'expiry_date' => $line->expiry_date?->toDateString()])->all())
    @foreach($items as $index => $item)@include('modules.inventory.documents.partials.correction-item-line')@endforeach
    </tbody></table></div>
    <template data-correction-template>@include('modules.inventory.documents.partials.correction-item-line', ['index' => '__INDEX__', 'item' => []])</template>
    <button class="btn btn-primary">{{ __('inventory_correction.prepare') }}</button>
</form>
@endif
<form method="POST" action="{{ route('admin.inventory.documents.corrections.store', $document) }}" novalidate>@csrf
<x-forms.input type="hidden" name="source_fingerprint" :value="$source_fingerprint" />
<div class="row g-3 mb-3"><div class="col-md-4"><x-forms.label for="operation" :label="__('inventory_correction.action')" /><x-forms.select id="operation" name="operation">@unless($reverse_only)<option value="replace">{{ __('inventory_correction.replace') }}</option>@endunless<option value="reverse">{{ __('inventory_correction.reverse') }}</option></x-forms.select></div><div class="col-md-4"><x-forms.label for="posting_date" :label="__('inventory_correction.posting_date')" required /><x-forms.date-input id="posting_date" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div><div class="col-md-4"><x-forms.label for="reason" :label="__('inventory_correction.reason')" required /><x-forms.textarea id="reason" name="reason">{{ old('reason') }}</x-forms.textarea></div></div>
@if($reverse_only)<p>{{ __('inventory_correction.sales_delivery_replacement') }}</p>@else
<p>{{ __('inventory_correction.review_payload') }}</p>
<div class="table-responsive"><table class="table"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('inventory_correction.quantity') }}</th><th>{{ __('inventory_correction.cost') }}</th></tr></thead><tbody>
@foreach($document->lines as $index => $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $numbers->format($line->quantity) }}</td><td><x-forms.input type="hidden" :name="'lines['.$index.'][line_id]'" :value="$line->id" /><x-forms.numeric-input :id="'quantity_'.$line->id" :name="'lines['.$index.'][quantity]'" :value="old('lines.'.$index.'.quantity', $line->quantity)" :scale="8" /></td><td>@if($receiptCost)<x-forms.numeric-input :id="'cost_'.$line->id" :name="'lines['.$index.'][unit_cost]'" :value="old('lines.'.$index.'.unit_cost', $line->unit_cost)" :scale="8" />@else—@endif</td></tr>@endforeach
</tbody></table></div>@endif<button class="btn btn-primary">{{ __('inventory_correction.prepare') }}</button>
</form>@endcan
@endif
</div></div>
<div class="card"><div class="card-header">{{ __('inventory_correction.history') }}</div><div class="card-body">
@foreach($history as $proposal)<div class="border rounded p-3 mb-3"><h6>#{{ $proposal->id }} — {{ __('inventory_correction.'.$proposal->operation) }} — {{ __('inventory_correction.'.$proposal->status) }}</h6><p>{{ $dates->formatDate($proposal->posting_date) }} — {{ $proposal->reason }}</p><p>{{ __('inventory_correction.preparer') }}: {{ $proposal->preparer?->name }}</p>
@if($proposal->operation === 'replace_items')
<h6>{{ __('inventory_correction.before') }}</h6>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('inventory_correction.cost') }}</th></tr></thead><tbody>
@foreach($proposal->source_snapshot['lines'] as $original)<tr><td>{{ $original['product_snapshot']['name'] ?? $document->lines->firstWhere('id', $original['id'])?->product?->name }}</td><td>{{ $numbers->format($original['quantity'], 8) }} {{ $document->lines->firstWhere('id', $original['id'])?->unit?->name }}</td><td>{{ $original['unit_cost'] === null ? '—' : $numbers->format($original['unit_cost'], 8) }}</td></tr>@endforeach
</tbody></table></div><h6>{{ __('inventory_correction.after') }}</h6>
@endif
@if($proposal->replacement_payload)<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('inventory_correction.cost') }}</th></tr></thead><tbody>@foreach($proposal->replacement_payload as $row)<tr><td>{{ $row['evidence']['product']['name'] ?? $document->lines->firstWhere('id', $row['line_id'])?->product?->name }}</td><td>{{ $numbers->format($row['quantity']) }}</td><td>{{ $row['unit_cost'] === null ? '—' : $numbers->format($row['unit_cost']) }}</td></tr>@endforeach</tbody></table></div>@endif
@if($proposal->status === 'prepared')
@can('inventory.documents.correct_approve')
@if((int) $proposal->prepared_by !== (int) auth()->id())<form method="POST" action="{{ route('admin.inventory.documents.corrections.approve', [$document, $proposal->id]) }}" novalidate>@csrf<x-forms.label :for="'approval_reason_'.$proposal->id" :label="__('inventory_correction.approval_reason')" required /><x-forms.textarea :id="'approval_reason_'.$proposal->id" name="approval_reason"></x-forms.textarea><button class="btn btn-success mt-2">{{ __('inventory_correction.approve') }}</button></form>@endif
<form method="POST" action="{{ route('admin.inventory.documents.corrections.reject', [$document, $proposal->id]) }}" novalidate>@csrf<button class="btn btn-outline-danger mt-2">{{ __('inventory_correction.reject') }}</button></form>
@endcan
@elseif($proposal->status === 'approved')<p>{{ __('inventory_correction.approver') }}: {{ $proposal->approver?->name }} — {{ $dates->formatDateTime($proposal->approved_at) }} — {{ $proposal->approval_reason }}</p>@if($proposal->replacementDocument)<a href="{{ route('admin.inventory.documents.show', $proposal->replacementDocument) }}">{{ __('inventory_correction.replacement') }} — {{ $proposal->replacementDocument->doc_num }}</a>@endif
@endif</div>@endforeach
{{ $history->links() }}
</div></div>
@endsection
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Core/posted-invoice-correction.js') }}"></script>@endpush
