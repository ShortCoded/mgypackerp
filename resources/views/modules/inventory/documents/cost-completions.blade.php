@extends('layouts.app')
@section('title', __('inventory.movements.receipt_completion_title'))
@section('content')
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="container-fluid py-3">
    <div class="card mb-3"><div class="card-body"><h4>{{ __('inventory.movements.receipt_completion_title') }}</h4><p class="mb-0 text-muted">{{ __('inventory.movements.receipt_completion_help') }}</p></div></div>
    <x-admin.report.table-card :title="__('inventory.movements.receipt_pricing_title')" table-id="receipt-cost-completions">
        <thead><tr><th>{{ __('Document Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($records as $record)<tr><td><a href="{{ route('admin.inventory.cost-completions.show', $record) }}">{{ $record->doc_num }}</a></td><td>{{ $dates->formatDate($record->document_date) }}</td><td>{{ $record->costProposals->first()?->status ? __('inventory.movements.receipt_completion_states.'.$record->costProposals->first()->status) : __('inventory.movements.receipt_completion_states.unpriced') }}</td></tr>@empty<tr><td colspan="3" class="text-muted text-center">{{ __('No data available') }}</td></tr>@endforelse</tbody>
    </x-admin.report.table-card>
    {{ $records->links() }}
</div>
@endsection
