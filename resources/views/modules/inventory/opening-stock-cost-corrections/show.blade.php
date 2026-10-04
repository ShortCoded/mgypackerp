@extends('layouts.app')
@section('title', __('opening_stock_cost_correction.title').' — '.$record->doc_num)
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $pending = $proposals->firstWhere('status', \Modules\Inventory\Models\OpeningStockCostCorrection::StatusPending);
    $approved = $proposals->firstWhere('status', \Modules\Inventory\Models\OpeningStockCostCorrection::StatusApproved);
@endphp
@section('content')
<div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2"><h5>{{ __('opening_stock_cost_correction.title') }} — {{ $record->doc_num }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.opening-stock-cost-corrections.index') }}">{{ __('Back') }}</a></div>
    <p class="text-muted">{{ __('opening_stock_cost_correction.help') }}</p>
    <p class="mb-0">{{ __('opening_stock_cost_correction.source') }}: {{ $record->doc_num }} · {{ $dates->formatDate($record->document_date) }}</p>
</div></div>
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($pending)<div class="alert alert-info">{{ __('opening_stock_cost_correction.pending') }}</div>@endif
@if(!$pending && $record->isApproved())
@can('inventory.opening_stock_cost_corrections.prepare')
<form novalidate method="POST" action="{{ route('admin.inventory.opening-stock-cost-corrections.prepare', $record) }}" class="card mb-3">
    @csrf
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4"><x-forms.label for="opening-cost-date" :label="__('opening_stock_cost_correction.posting_date')" required /><x-forms.date-input id="opening-cost-date" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div>
            <div class="col-md-8"><x-forms.label for="opening-cost-account" :label="__('opening_stock_cost_correction.counterpart')" required /><x-forms.select id="opening-cost-account" name="counterpart_account_id" variant="ajax" :url="route('admin.inventory.opening-stock-cost-corrections.select2.accounts')" :placeholder="__('common.placeholders.select')"><option value=""></option>@if($selectedCounterpart)<option selected value="{{ $selectedCounterpart->id }}">{{ $selectedCounterpart->codeNameLabel() }}</option>@endif</x-forms.select></div>
            <div class="col-md-6"><x-forms.label for="opening-cost-reference" :label="__('opening_stock_cost_correction.reference')" required /><x-forms.input id="opening-cost-reference" name="source_reference" :value="old('source_reference')" /></div>
            <div class="col-md-6"><x-forms.label for="opening-cost-reason" :label="__('opening_stock_cost_correction.reason')" required /><x-forms.input id="opening-cost-reason" name="reason" :value="old('reason')" /></div>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('inventory.movements.fields.quantity') }}</th><th>{{ __('opening_stock_cost_correction.new_unit_cost') }}</th></tr></thead><tbody>
            @foreach($record->lines as $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td dir="ltr" class="text-nowrap">{{ $numbers->format($line->quantity) }}</td><td style="min-width:12rem"><x-forms.numeric-input :name="'unit_costs['.$line->id.']'" :value="old('unit_costs.'.$line->id, data_get($approved?->unit_costs, $line->id))" :scale="8" /></td></tr>@endforeach
        </tbody></table></div>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('opening_stock_cost_correction.prepare') }}</button>
    </div>
</form>
@endcan
@endif
<h6>{{ __('opening_stock_cost_correction.history') }}</h6>
@forelse($proposals as $proposal)
<div class="card mb-3"><div class="card-body">
    <h6>{{ __('opening_stock_cost_correction.statuses.'.$proposal->status) }} · {{ $dates->formatDate($proposal->posting_date) }}</h6>
    <p>{{ __('opening_stock_cost_correction.prepared_by') }}: {{ $proposal->preparedBy?->name }} · {{ __('opening_stock_cost_correction.reference') }}: {{ $proposal->source_reference }}</p>
    <p>{{ __('opening_stock_cost_correction.reason') }}: {{ $proposal->reason }}</p>
    <div class="table-responsive mb-3"><table class="table table-sm"><thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('inventory.movements.fields.quantity') }}</th><th>{{ __('opening_stock_cost_correction.new_unit_cost') }}</th></tr></thead><tbody>
        @foreach($proposal->source_snapshot['lines'] ?? [] as $entry)
        <tr><td>{{ $entry['product_code'] }} — {{ $entry['product_name'] }}</td><td dir="ltr">{{ $numbers->format($entry['quantity']) }}</td><td dir="ltr">{{ $numbers->format(data_get($proposal->unit_costs, $entry['line_id']), 8) }}</td></tr>@endforeach
    </tbody></table></div>
    @include('modules.inventory.documents.partials.receipt-cost-impact', ['impact' => $proposal->plan])
    @if($proposal->valueAdjustment?->journalEntry)
        <p>{{ __('Journal Entry') }}:
        @can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $proposal->valueAdjustment->journalEntry) }}">{{ $proposal->valueAdjustment->journalEntry->doc_num }}</a>@else{{ $proposal->valueAdjustment->journalEntry->doc_num }}@endcan
        · {{ __('opening_stock_cost_correction.approved_by') }}: {{ $proposal->approvedBy?->name }} · {{ $proposal->approval_reference }}</p>
    @endif
    @if($proposal->rejection_reason)<p>{{ __('opening_stock_cost_correction.rejection_reason') }}: {{ $proposal->rejection_reason }}</p>@endif
    @if($proposal->status === \Modules\Inventory\Models\OpeningStockCostCorrection::StatusPending)
    @can('inventory.opening_stock_cost_corrections.approve')
        @if((int) $proposal->prepared_by !== (int) auth()->id())
        <form novalidate method="POST" action="{{ route('admin.inventory.opening-stock-cost-corrections.approve', [$record, $proposal]) }}" class="row g-2 mb-3">
            @csrf
            <div class="col-md-8"><x-forms.label :for="'opening-cost-approval-'.$proposal->id" :label="__('opening_stock_cost_correction.approval_reference')" required /><x-forms.input :id="'opening-cost-approval-'.$proposal->id" name="approval_reference" :value="old('approval_reference')" /></div>
            <div class="col-md-4 d-flex align-items-end"><button type="submit" class="btn btn-primary btn-sm">{{ __('opening_stock_cost_correction.approve') }}</button></div>
        </form>
        @else<p class="text-muted">{{ __('opening_stock_cost_correction.independent') }}</p>@endif
        <form novalidate method="POST" action="{{ route('admin.inventory.opening-stock-cost-corrections.reject', [$record, $proposal]) }}" class="row g-2">
            @csrf
            <div class="col-md-8"><x-forms.label :for="'opening-cost-reject-'.$proposal->id" :label="__('opening_stock_cost_correction.rejection_reason')" required /><x-forms.input :id="'opening-cost-reject-'.$proposal->id" name="reason" :value="old('reason')" /></div>
            <div class="col-md-4 d-flex align-items-end"><button type="submit" class="btn btn-outline-danger btn-sm">{{ __('opening_stock_cost_correction.reject') }}</button></div>
        </form>
    @endcan
    @endif
</div></div>
@empty<p class="text-muted">{{ __('reports.no_data') }}</p>@endforelse
@endsection
