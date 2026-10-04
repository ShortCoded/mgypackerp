@extends('layouts.app')
@section('title', __('inventory_periodic_cost.title'))
@section('content')
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="container-fluid py-3">
    <div class="card mb-3"><div class="card-body"><h4>{{ __('inventory_periodic_cost.title') }}</h4><p class="text-muted mb-0">{{ __('inventory_periodic_cost.help') }}</p></div></div>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @can('inventory.cost_policies.periodic.prepare')
    <div class="card mb-3"><div class="card-body"><form novalidate method="POST" action="{{ route('admin.inventory.periodic-cost-closes.prepare') }}" class="row g-3">
        @csrf
        <div class="col-md-4"><x-forms.label for="periodic_branch" :label="__('inventory_cost_policy.branch')" /><x-forms.select id="periodic_branch" name="branch_doc_num" variant="ajax" :url="route('admin.inventory.periodic-cost-closes.select2.branches')" :placeholder="__('inventory_cost_policy.company_scope')"><option value=""></option>@if($selectedBranch)<option value="{{ $selectedBranch->doc_num }}" selected>{{ $selectedBranch->name }}</option>@endif</x-forms.select></div>
        <div class="col-md-4"><x-forms.label for="periodic_store" :label="__('inventory_cost_policy.store')" /><x-forms.select id="periodic_store" name="branch_store_uuid" variant="ajax" :url="route('admin.inventory.periodic-cost-closes.select2.stores')" data-depends-on="#periodic_branch" data-dependent-param="branch_doc_num"><option value=""></option>@if($selectedStore)<option value="{{ $selectedStore->public_uuid }}" selected>{{ $selectedStore->name }}</option>@endif</x-forms.select></div>
        <div class="col-md-4"><x-forms.label for="periodic_account" :label="__('inventory_periodic_cost.clearing')" :required="true" /><x-forms.select id="periodic_account" name="counterpart_account_id" variant="ajax" :url="route('admin.inventory.periodic-cost-closes.select2.accounts')"><option value=""></option>@if($selectedAccount)<option value="{{ $selectedAccount->id }}" selected>{{ $selectedAccount->codeNameLabel() }}</option>@endif</x-forms.select></div>
        @foreach(['from_date' => 'from', 'to_date' => 'to', 'posting_date' => 'posting_date'] as $field => $label)
        <div class="col-md-4"><x-forms.label :for="'periodic_'.$field" :label="__('inventory_periodic_cost.'.$label)" :required="true" /><x-forms.date-input :id="'periodic_'.$field" :name="$field" :value="old($field, $field === 'posting_date' ? now()->toDateString() : '')" /></div>
        @endforeach
        <div class="col-12"><x-forms.label for="periodic_reason" :label="__('inventory_periodic_cost.reason')" :required="true" /><x-forms.input id="periodic_reason" name="reason" :value="old('reason')" maxlength="2000" /></div>
        <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit">{{ __('inventory_periodic_cost.prepare') }}</button></div>
    </form></div></div>
    @endcan
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('inventory_cost_policy.scope') }}</th><th>{{ __('inventory_periodic_cost.from') }}</th><th>{{ __('inventory_periodic_cost.to') }}</th><th>{{ __('inventory_periodic_cost.posting_date') }}</th><th>{{ __('inventory_cost_policy.status') }}</th></tr></thead><tbody>
        @forelse($records as $record)<tr><td><a href="{{ route('admin.inventory.periodic-cost-closes.show', $record) }}">{{ $record->doc_num }}</a></td><td>{{ $record->scopeStore?->name ?? $record->scopeBranch?->name ?? __('inventory_cost_policy.company_scope') }}</td><td>{{ $dates->formatDate($record->from_date, '') }}</td><td>{{ $dates->formatDate($record->to_date, '') }}</td><td>{{ $dates->formatDate($record->posting_date, '') }}</td><td>{{ __('inventory_periodic_cost.statuses.'.$record->status) }}</td></tr>@empty<tr><td colspan="6">{{ __('reports.no_data') }}</td></tr>@endforelse
    </tbody></table></div></div><div class="mt-3">{{ $records->links() }}</div>
</div>
@endsection
