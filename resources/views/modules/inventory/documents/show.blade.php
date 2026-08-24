@extends('layouts.app')

@section('title', $record->doc_num)

@section('content')
@php
    $productionOrder = $record->productionOrder ?? $record->productionRun?->order;
    $salesOrder = $productionOrder?->salesOrder ?? $record->salesOrder;
    $relatedDocuments = collect([
        ['label' => __('Production Run'), 'number' => $record->productionRun?->run_number, 'url' => $record->productionRun ? route('admin.production.runs.show', $record->productionRun) : null, 'permission' => 'production.runs.view'],
        ['label' => __('Production Order'), 'number' => $productionOrder?->doc_num, 'url' => $productionOrder ? route('admin.production.work-orders.show', $productionOrder) : null, 'permission' => 'production.orders.view'],
        ['label' => __('Sales Requirement / Order'), 'number' => $salesOrder?->doc_num, 'url' => $salesOrder ? route('admin.sales.sales-orders.show', $salesOrder) : null, 'permission' => 'sales_orders.view'],
        ['label' => __('Journal Entry'), 'number' => $record->journalEntry?->doc_num, 'url' => $record->journalEntry ? route('admin.accounting.journal-entries.show', $record->journalEntry) : null, 'permission' => 'journal_entries.view'],
        ['label' => __('Reversal Journal'), 'number' => $record->reversalJournalEntry?->doc_num, 'url' => $record->reversalJournalEntry ? route('admin.accounting.journal-entries.show', $record->reversalJournalEntry) : null, 'permission' => 'journal_entries.view'],
    ]);
@endphp
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between"><div><h5 class="mb-1">{{ $record->doc_num }}</h5><span class="badge bg-secondary">{{ str($record->status)->title() }}</span></div>@can('inventory.documents.print')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.documents.print', $record) }}">{{ __('Print') }}</a>@endcan</div>
    <div class="card-body"><div class="row g-2"><div class="col-md-3"><strong>{{ __('Type') }}:</strong> {{ str($record->document_type)->replace('_', ' ')->title() }}</div><div class="col-md-3"><strong>{{ __('Date') }}:</strong> {{ $record->document_date?->toDateString() }}</div><div class="col-md-3"><strong>{{ __('Source') }}:</strong> {{ $record->branchStore?->name }}</div><div class="col-md-3"><strong>{{ __('Destination') }}:</strong> {{ $record->destinationBranchStore?->name }}</div><div class="col-md-3"><strong>{{ __('Source document') }}:</strong> {{ $record->source_doc_num }}</div>@if($record->productionOrder)<div class="col-md-3"><strong>{{ __('Production order') }}:</strong> <a href="{{ route('admin.production.work-orders.show', $record->productionOrder) }}">{{ $record->productionOrder->doc_num }}</a></div>@endif @if($record->productionRun)<div class="col-md-3"><strong>{{ __('Production run') }}:</strong> <a href="{{ route('admin.production.runs.show', $record->productionRun) }}">{{ $record->productionRun->run_number }}</a></div>@endif</div></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th>@if($canViewFinancial)<th>{{ __('Unit cost') }}</th><th>{{ __('Total') }}</th>@endif</tr></thead><tbody>@foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $line->quantity }}</td>@if($canViewFinancial)<td>{{ $line->unit_cost }}</td><td>{{ $line->total_cost }}</td>@endif</tr>@endforeach</tbody></table></div>
</div>
<x-related-documents :documents="$relatedDocuments" />
@if($record->lines->contains(fn ($line) => $line->reservation))
<div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Reservation and BOM requirement lineage') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Line') }}</th><th>{{ __('Reservation') }}</th><th>{{ __('BOM requirement') }}</th><th>{{ __('Run') }}</th></tr></thead><tbody>@foreach($record->lines as $line)@if($line->reservation)<tr><td>{{ $line->line_number }}</td><td>{{ $line->reservation->public_id }}</td><td>{{ $line->reservation->productionMaterialRequirement?->public_id }} · {{ __('line') }} {{ $line->reservation->productionMaterialRequirement?->line_number }}</td><td>{{ $line->productionRun?->run_number ?? $record->productionRun?->run_number }}</td></tr>@endif@endforeach</tbody></table></div></div>
@endif
@endsection
