@extends('layouts.app')

@section('title', $record->doc_num)

@section('content')
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between"><div><h5 class="mb-1">{{ $record->doc_num }}</h5><span class="badge bg-secondary">{{ str($record->status)->title() }}</span></div><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.documents.print', $record) }}">{{ __('Print') }}</a></div>
    <div class="card-body"><div class="row g-2"><div class="col-md-3"><strong>{{ __('Type') }}:</strong> {{ str($record->document_type)->replace('_', ' ')->title() }}</div><div class="col-md-3"><strong>{{ __('Date') }}:</strong> {{ $record->document_date?->toDateString() }}</div><div class="col-md-3"><strong>{{ __('Source') }}:</strong> {{ $record->branchStore?->name }}</div><div class="col-md-3"><strong>{{ __('Destination') }}:</strong> {{ $record->destinationBranchStore?->name }}</div><div class="col-md-3"><strong>{{ __('Source document') }}:</strong> {{ $record->source_doc_num }}</div>@if($record->productionOrder)<div class="col-md-3"><strong>{{ __('Production order') }}:</strong> <a href="{{ route('admin.production.work-orders.show', $record->productionOrder) }}">{{ $record->productionOrder->doc_num }}</a></div>@endif @if($record->productionRun)<div class="col-md-3"><strong>{{ __('Production run') }}:</strong> <a href="{{ route('admin.production.runs.show', $record->productionRun) }}">{{ $record->productionRun->run_number }}</a></div>@endif</div></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit cost') }}</th><th>{{ __('Total') }}</th></tr></thead><tbody>@foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $line->quantity }}</td><td>{{ $line->unit_cost }}</td><td>{{ $line->total_cost }}</td></tr>@endforeach</tbody></table></div>
</div>
@endsection
