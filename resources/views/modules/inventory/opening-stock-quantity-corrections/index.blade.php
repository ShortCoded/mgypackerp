@extends('layouts.app')
@section('title', __('opening_stock_quantity_correction.title'))
@section('content')
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card mb-3"><div class="card-body"><h5>{{ __('opening_stock_quantity_correction.title') }}</h5><p class="mb-0 text-muted">{{ __('opening_stock_quantity_correction.help') }}</p></div></div>
<x-admin.report.table-card :title="__('opening_stock_quantity_correction.source')" table-id="opening-stock-quantity-sources">
    <thead><tr><th>{{ __('Document Number') }}</th><th>{{ __('Date') }}</th></tr></thead>
    <tbody>@forelse($records as $record)<tr><td><a href="{{ route('admin.inventory.opening-stock-quantity-corrections.show', $record) }}">{{ $record->doc_num }}</a></td><td>{{ $dates->formatDate($record->document_date) }}</td></tr>@empty<tr><td colspan="2" class="text-center text-muted">{{ __('opening_stock_quantity_correction.empty') }}</td></tr>@endforelse</tbody>
</x-admin.report.table-card>
{{ $records->links() }}
@endsection
