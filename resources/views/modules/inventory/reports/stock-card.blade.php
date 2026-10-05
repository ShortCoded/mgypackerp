@extends('layouts.app')
@section('title', __('inventory_correction.card.title'))
@section('content')
<x-admin.report.page :title="__('inventory_correction.card.title')" :description="$product->doc_num.' — '.$product->name">
    <x-admin.report.actions-toolbar :show-filters="false" :show-refresh="false" :export-options="[
        ['permission' => 'inventory.reports.operations.export', 'url' => route('admin.inventory.reports.stock-card.export', request()->query()), 'label' => __('reports.export_excel'), 'icon' => 'file-excel'],
        ['permission' => 'inventory.reports.operations.print', 'url' => route('admin.inventory.reports.stock-card.print', request()->query()), 'label' => __('reports.export_pdf'), 'icon' => 'file-pdf', 'newTab' => true],
    ]" />
    <form method="GET" class="card card-body mb-3">
        @foreach($filters as $name => $value) @if(!in_array($name, ['from', 'as_of', 'movement_page'], true) && filled($value))<x-forms.input type="hidden" name="{{ $name }}" value="{{ $value }}" />@endif @endforeach
        <div class="row g-3 align-items-end">
            <div class="col-md-4"><x-forms.label for="card-from" :label="__('inventory.reports.from_date')" /><x-forms.date-input id="card-from" name="from" :value="$filters['from'] ?? ''" /></div>
            <div class="col-md-4"><x-forms.label for="card-as-of" :label="__('stock_balance_inquiry.filters.as_of')" /><x-forms.date-input id="card-as-of" name="as_of" :value="$filters['as_of']" required /></div>
            <div class="col-md-4"><button class="btn btn-primary">{{ __('Apply') }}</button></div>
        </div>
    </form>
    @include('modules.inventory.reports.partials.stock-card-table')
    @if($movementPages > 1)<nav class="d-flex justify-content-center gap-3 my-3">
        @if($movementPage > 1)<a href="{{ request()->fullUrlWithQuery(['movement_page' => $movementPage - 1]) }}">{{ __('pagination.previous') }}</a>@endif
        <span>{{ $movementPage }} / {{ $movementPages }}</span>
        @if($movementPage < $movementPages)<a href="{{ request()->fullUrlWithQuery(['movement_page' => $movementPage + 1]) }}">{{ __('pagination.next') }}</a>@endif
    </nav>@endif
</x-admin.report.page>
@endsection
