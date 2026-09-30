@extends('layouts.app')

@section('title', $record->doc_num)

@section('content')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $dates = app(\Modules\Core\Services\DateFormatService::class);
    @endphp
@php
    $productionOrder = $record->productionOrder ?? $record->productionRun?->order;
    $salesOrder = $productionOrder?->salesOrder ?? $record->salesOrder;
    $relatedDocuments = collect([
        ['label' => __('inventory.movements.production_material_request'), 'number' => $record->productionMaterialRequest?->doc_num, 'url' => $record->productionMaterialRequest ? route('admin.production.material-requests.show', $record->productionMaterialRequest) : null, 'permission' => 'production.material_requests.view'],
        ['label' => __('Production Run'), 'number' => $record->productionRun?->run_number, 'url' => $record->productionRun ? route('admin.production.runs.show', $record->productionRun) : null, 'permission' => 'production.runs.view'],
        ['label' => __('Production Order'), 'number' => $productionOrder?->doc_num, 'url' => $productionOrder ? route('admin.production.work-orders.show', $productionOrder) : null, 'permission' => 'production.orders.view'],
        ['label' => __('Sales Requirement / Order'), 'number' => $salesOrder?->doc_num, 'url' => $salesOrder ? route('admin.sales.sales-orders.show', $salesOrder) : null, 'permission' => 'sales_orders.view'],
    ]);
@endphp
<div>
<div class="card mb-3">
    <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2"><div><a class="small" href="{{ route('admin.inventory.documents.index') }}">{{ __('inventory.movements.title') }}</a><h5 class="mb-1">{{ $record->doc_num }}</h5><span class="badge rounded-pill badge-subtle-secondary">{{ __('inventory.movements.statuses.'.$record->status) }}</span></div><div class="d-flex flex-wrap gap-2">@if($record->isUntouchedDraft())@can('inventory.documents.edit')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.inventory.documents.edit', $record) }}">{{ __('inventory.movements.actions.edit') }}</a>@endcan @can('inventory.documents.post')<button class="btn btn-primary btn-sm" type="button" data-action="post" data-url="{{ route('admin.inventory.documents.post', $record) }}">{{ __('inventory.movements.actions.post') }}</button>@endcan @else @can('inventory.documents.print')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.documents.print', $record) }}">{{ __('common.actions.print') }}</a>@endcan @endif @if($record->status === \Modules\Inventory\Models\InventoryDocument::StatusPosted)@can('inventory.documents.reverse')<form method="POST" action="{{ route('admin.inventory.documents.reverse', $record) }}">@csrf<button class="btn btn-outline-danger btn-sm" type="submit">{{ __('inventory.movements.actions.reverse') }}</button></form>@endcan @endif</div></div>
    <div class="card-body"><div class="row g-3"><div class="col-md-3"><strong>{{ __('inventory.movements.fields.type') }}:</strong> {{ __('inventory.movements.types.'.$record->document_type) }}</div><div class="col-md-3"><strong>{{ __('inventory.movements.fields.date') }}:</strong> {{ $dates->formatDate($record->document_date, '—') }}</div><div class="col-md-3"><strong>{{ __('inventory.movements.fields.source_store') }}:</strong> {{ $record->branchStore?->name ?: '—' }}</div><div class="col-md-3"><strong>{{ __('inventory.movements.fields.destination_store') }}:</strong> {{ $record->destinationBranchStore?->name ?: '—' }}</div><div class="col-md-3"><strong>{{ __('inventory.movements.fields.source_status') }}:</strong> {{ __('inventory.movements.stock_statuses.'.($record->source_stock_status ?: 'available')) }}</div><div class="col-md-3"><strong>{{ __('inventory.movements.fields.destination_status') }}:</strong> {{ __('inventory.movements.stock_statuses.'.($record->destination_stock_status ?: 'available')) }}</div><div class="col-md-6"><strong>{{ __('inventory.movements.fields.reason') }}:</strong> {{ $record->movement_reason ?: $record->purpose ?: '—' }}</div>@if($record->productionOrder)<div class="col-md-3"><strong>{{ __('inventory.movements.fields.production_order') }}:</strong> @can('production.orders.view')<a href="{{ route('admin.production.work-orders.show', $record->productionOrder) }}">{{ $record->productionOrder->doc_num }}</a>@else{{ $record->productionOrder->doc_num }}@endcan</div>@endif @if($record->productionRunBatch)<div class="col-md-3"><strong>{{ __('inventory.movements.fields.production_run_batch') }}:</strong> @can('production.runs.view')<a href="{{ route('admin.production.runs.batches.show', $record->productionRunBatch) }}">{{ $record->productionRunBatch->batch_number }}</a>@else{{ $record->productionRunBatch->batch_number }}@endcan</div>@endif @if($record->productionRun)<div class="col-md-3"><strong>{{ __('production_execution.fields.run') }}:</strong> @can('production.runs.view')<a href="{{ route('admin.production.runs.show', $record->productionRun) }}">{{ $record->productionRun->run_number }}</a>@else{{ $record->productionRun->run_number }}@endcan</div>@endif</div></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Batch / lot') }}</th></tr></thead><tbody>@foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $line->unit?->name ?: '—' }}</td><td>{{ $numbers->format($line->quantity) }}</td><td>{{ $line->batch_lot ?: '—' }}</td></tr>@endforeach</tbody></table></div>
</div>
@if($record->document_type === \Modules\Inventory\Models\InventoryDocument::TypeReceipt)
    @php
        $canPriceReceipt = $record->status === \Modules\Inventory\Models\InventoryDocument::StatusPosted
            && $record->source_document_type === null
            && $record->production_order_id === null
            && $record->production_run_id === null
            && $record->production_run_batch_id === null
            && $record->journal_entry_id === null
            && $record->lines->isNotEmpty()
            && $record->lines->every(fn ($line) => $line->unit_cost === null && $line->total_cost === null);
    @endphp
    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">{{ __('inventory.movements.receipt_pricing_title') }}</h6></div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @if($canPriceReceipt && auth()->user()?->can('inventory.documents.post') && auth()->user()?->can('inventory.documents.receive'))
                <p class="text-muted small">{{ __('inventory.movements.receipt_pricing_help') }}</p>
                <form method="POST" action="{{ route('admin.inventory.documents.price-receipt', $record) }}">
                    @csrf
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-lg-8">
                            <x-forms.label for="receipt-pricing-reference" :label="__('inventory.movements.receipt_pricing_reference')" required />
                            <x-forms.input id="receipt-pricing-reference" name="source_reference" :value="old('source_reference')" required maxlength="255" />
                        </div>
                        @if(app()->environment('local'))
                            <div class="col-12 col-lg-4 d-flex align-items-end">
                                <label class="form-check-label"><x-forms.input class="form-check-input me-2" type="checkbox" name="provisional" value="1" :checked="old('provisional')" />{{ __('inventory.movements.receipt_pricing_provisional') }}</label>
                            </div>
                        @endif
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('inventory.movements.fields.quantity') }}</th><th>{{ __('inventory.movements.fields.unit_cost') }}</th></tr></thead>
                            <tbody>
                                @foreach($record->lines as $line)
                                    <tr>
                                        <td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td>
                                        <td>{{ $numbers->format($line->quantity) }}</td>
                                        <td style="min-width: 11rem"><x-forms.numeric-input :name="'unit_costs['.$line->getKey().']'" :value="old('unit_costs.'.$line->getKey())" :scale="8" min="0.00000001" step="0.00000001" required /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">{{ __('inventory.movements.receipt_pricing_submit') }}</button>
                </form>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('inventory.movements.fields.quantity') }}</th><th>{{ __('inventory.movements.fields.unit_cost') }}</th><th>{{ __('inventory.movements.receipt_pricing_total') }}</th></tr></thead>
                        <tbody>
                            @foreach($record->lines as $line)
                                <tr>
                                    <td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td>
                                    <td>{{ $numbers->format($line->quantity) }}</td>
                                    <td>{{ $line->unit_cost === null ? '—' : $numbers->format($line->unit_cost, 8) }}</td>
                                    <td>{{ $line->total_cost === null ? '—' : $numbers->format($line->total_cost) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(data_get($record->lines->first()?->product_snapshot, 'cost_correction.source_reference'))
                    <div class="small mt-3"><strong>{{ __('inventory.movements.receipt_pricing_reference') }}:</strong> {{ data_get($record->lines->first()->product_snapshot, 'cost_correction.source_reference') }}</div>
                @endif
                @if($record->lines->contains(fn ($line) => data_get($line->product_snapshot, 'cost_correction.basis') === 'local_provisional'))
                    <div class="alert alert-warning mt-3 mb-0">{{ __('inventory.movements.receipt_pricing_provisional_notice') }}</div>
                @endif
            @endif
        </div>
    </div>
@endif
<x-related-documents :documents="$relatedDocuments" />
@if($record->lines->contains(fn ($line) => $line->reservation))
<div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Reservation and BOM requirement lineage') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Line') }}</th><th>{{ __('Reservation') }}</th><th>{{ __('BOM requirement') }}</th><th>{{ __('Run') }}</th></tr></thead><tbody>@foreach($record->lines as $line)@if($line->reservation)<tr><td>{{ $line->line_number }}</td><td>{{ $line->reservation->public_id }}</td><td>{{ $line->reservation->productionMaterialRequirement?->public_id }} · {{ __('line') }} {{ $line->reservation->productionMaterialRequirement?->line_number }}</td><td>{{ $line->productionRun?->run_number ?? $record->productionRun?->run_number }}</td></tr>@endif
@endforeach</tbody></table></div></div>
@endif
</div>
@endsection

@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
