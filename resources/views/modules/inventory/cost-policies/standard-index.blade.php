@extends('layouts.app')
@section('title', __('inventory_standard_cost.title'))
@section('content')
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="container-fluid py-3">
    <div class="card mb-3"><div class="card-body"><h4>{{ __('inventory_standard_cost.title') }}</h4><p class="text-muted mb-0">{{ __('inventory_standard_cost.help') }}</p></div></div>
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @can('inventory.cost_policies.standard.prepare')
    <div class="card mb-3"><div class="card-header">{{ __('inventory_standard_cost.prepare') }}</div><div class="card-body"><form novalidate method="POST" action="{{ route('admin.inventory.standard-costs.prepare') }}" class="row g-3">
        @csrf
        <div class="col-md-4"><x-forms.label for="standard_product" :label="__('Product')" :required="true" /><x-forms.select id="standard_product" name="product_id" variant="ajax" :url="route('admin.inventory.standard-costs.lookup', 'products')"><option value=""></option>@if($product)<option value="{{ $product->id }}" selected>{{ $product->doc_num }} — {{ $product->name }}</option>@endif</x-forms.select></div>
        @foreach(['effective_from' => 'from', 'effective_to' => 'to'] as $field => $label)<div class="col-md-4"><x-forms.label :for="'std_'.$field" :label="__('inventory_standard_cost.'.$label)" :required="true" /><x-forms.date-input :id="'std_'.$field" :name="$field" :value="old($field)" /></div>@endforeach
        @foreach(['materials', 'labor', 'overhead'] as $part)
        <div class="col-md-6"><x-forms.label :for="'std_'.$part" :label="__('inventory_standard_cost.'.$part).' — '.__('Unit Cost')" :required="true" /><x-forms.numeric-input :id="'std_'.$part" :name="$part.'_unit_cost'" :value="old($part.'_unit_cost', '0')" :scale="8" /></div>
        <div class="col-md-6"><x-forms.label :for="'std_account_'.$part" :label="__('inventory_standard_cost.'.$part).' — '.__('inventory_standard_cost.variance_account')" :required="true" /><x-forms.select :id="'std_account_'.$part" :name="$part.'_variance_account_id'" variant="ajax" :url="route('admin.inventory.standard-costs.lookup', 'variance-accounts')"><option value=""></option>@if($account = $accounts->get(old($part.'_variance_account_id')))<option value="{{ $account->id }}" selected>{{ $account->codeNameLabel() }}</option>@endif</x-forms.select></div>
        @endforeach
        <div class="col-md-6"><x-forms.label for="std_clearing" :label="__('inventory_standard_cost.clearing')" :required="true" /><x-forms.select id="std_clearing" name="counterpart_account_id" variant="ajax" :url="route('admin.inventory.standard-costs.lookup', 'clearing-accounts')"><option value=""></option>@if($account = $accounts->get(old('counterpart_account_id')))<option value="{{ $account->id }}" selected>{{ $account->codeNameLabel() }}</option>@endif</x-forms.select></div>
        <div class="col-md-6"><x-forms.label for="std_source" :label="__('inventory_standard_cost.source')" :required="true" /><x-forms.input id="std_source" name="source_reference" :value="old('source_reference')" /></div>
        <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary">{{ __('inventory_standard_cost.prepare') }}</button></div>
    </form></div></div>
    @endcan
    @can('inventory.cost_policies.standard.settle')
    <div class="card mb-3"><div class="card-header">{{ __('inventory_standard_cost.settle') }}</div><div class="card-body"><form novalidate method="POST" action="{{ route('admin.inventory.standard-costs.settle') }}" class="row g-3">
        @csrf
        <div class="col-md-4"><x-forms.label for="std_run" :label="__('Production Run')" :required="true" /><x-forms.select id="std_run" name="run_uuid" variant="ajax" :url="route('admin.inventory.standard-costs.lookup', 'runs')"><option value=""></option>@if($run)<option value="{{ $run->public_id }}" selected>{{ $run->run_number }}</option>@endif</x-forms.select></div>
        <div class="col-md-4"><x-forms.label for="std_posting" :label="__('inventory_standard_cost.posting_date')" :required="true" /><x-forms.date-input id="std_posting" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div>
        <div class="col-md-4"><x-forms.label for="std_reason" :label="__('inventory_standard_cost.reason')" :required="true" /><x-forms.input id="std_reason" name="reason" :value="old('reason')" /></div>
        <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary">{{ __('inventory_standard_cost.settle') }}</button></div>
    </form></div></div>
    @endcan
    @foreach(['versions' => $versions, 'settlements' => $settlements] as $kind => $records)
    <x-admin.report.table-card :title="__('inventory_standard_cost.'.$kind)" :table-id="'standard-'.$kind" class="mb-3">
        <thead><tr><th>{{ __('Document') }}</th><th>{{ $kind === 'versions' ? __('Product') : __('Production Run') }}</th><th>{{ __('Status') }}</th><th>{{ __('Date') }}</th></tr></thead><tbody>
        @forelse($records as $record)<tr><td><a href="{{ route('admin.inventory.standard-costs.show', ['kind' => $kind, 'uuid' => $record->public_uuid]) }}">{{ $record->doc_num }}</a></td><td>{{ $kind === 'versions' ? $record->product?->name : $record->run?->run_number }}</td><td>{{ __('inventory_standard_cost.statuses.'.$record->status) }}</td><td>{{ $dates->formatDate($kind === 'versions' ? $record->effective_from : $record->posting_date, '') }}</td></tr>@empty<tr><td colspan="4">{{ __('reports.no_data') }}</td></tr>@endforelse
        </tbody>
    </x-admin.report.table-card>
    {{ $records->links() }}
    @endforeach
</div>
@endsection
