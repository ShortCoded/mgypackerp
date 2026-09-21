@extends('layouts.app')

@section('title', __('overhead_allocations.rules_title'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h1 class="h4 mb-1">{{ __('overhead_allocations.rules_title') }}</h1><p class="text-600 mb-0">{{ __('overhead_allocations.policy_snapshot') }}</p></div>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.costing.overhead-allocation-run.index') }}">{{ __('overhead_allocations.runs_title') }}</a>
    </div>

    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    @can('costing.overhead_allocation_rules.create')
        <div class="card mb-3">
            <div class="card-header"><h2 class="h6 mb-0">{{ __('overhead_allocations.new_rule') }}</h2></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.costing.overhead-allocation-rules.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-6"><x-forms.label :label="__('overhead_allocations.name')" required /><x-forms.input name="name" :value="old('name')" required /></div>
                    <div class="col-md-6"><label class="form-label">{{ __('overhead_allocations.name_en') }}</label><x-forms.input name="name_en" :value="old('name_en')" /></div>
                    <div class="col-md-4"><x-forms.label :label="__('overhead_allocations.source_cost_center')" required /><x-forms.select class="form-select js-select2-ajax" id="overhead-source-cost-center" name="source_cost_center_id" data-url="{{ route('admin.costing.overhead-allocation-rules.select2.cost-centers') }}" required><option value=""></option>@if($selectedSourceCostCenter)<option value="{{ $selectedSourceCostCenter->id }}" selected>{{ $selectedSourceCostCenter->codeNameLabel() }}</option>@endif</x-forms.select></div>
                    <div class="col-md-4"><x-forms.label :label="__('overhead_allocations.source_accounts')" required /><x-forms.select class="form-select js-select2-ajax" name="source_account_ids[]" multiple data-url="{{ route('admin.costing.overhead-allocation-rules.select2.source-accounts') }}" data-depends-on="#overhead-source-cost-center" data-dependent-param="source_cost_center_id" data-disable-when-dependency-empty="true" required>@foreach($selectedSourceAccounts as $account)<option value="{{ $account->id }}" selected>{{ $account->codeNameLabel() }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-4"><label class="form-label">{{ __('overhead_allocations.target_cost_centers') }}</label><x-forms.select class="form-select js-select2-ajax" name="target_cost_center_ids[]" multiple data-url="{{ route('admin.costing.overhead-allocation-rules.select2.cost-centers') }}" :placeholder="__('overhead_allocations.all_target_centers')">@foreach($selectedTargetCostCenters as $center)<option value="{{ $center->id }}" selected>{{ $center->codeNameLabel() }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><x-forms.label :label="__('overhead_allocations.basis')" required /><x-forms.select name="basis" required>@foreach(['machine_hours', 'labor_hours', 'direct_material_cost'] as $basis)<option value="{{ $basis }}" @selected(old('basis', 'machine_hours') === $basis)>{{ __('overhead_allocations.basis_options.'.$basis) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('overhead_allocations.fallback_basis') }}</label><x-forms.select name="fallback_basis"><option value="">{{ __('overhead_allocations.no_fallback') }}</option><option value="direct_material_cost" @selected(old('fallback_basis') === 'direct_material_cost')>{{ __('overhead_allocations.basis_options.direct_material_cost') }}</option></x-forms.select></div>
                    <div class="col-md-3"><x-forms.label :label="__('overhead_allocations.cost_behavior')" required /><x-forms.select name="cost_behavior" required>@foreach(['variable', 'fixed'] as $behavior)<option value="{{ $behavior }}" @selected(old('cost_behavior', 'variable') === $behavior)>{{ __('overhead_allocations.behavior_options.'.$behavior) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('overhead_allocations.normal_capacity_hours') }}</label><x-forms.numeric-input min="0.00000001" step="0.00000001" :scale="8" name="normal_capacity_hours" :value="old('normal_capacity_hours')" /></div>
                    <div class="col-md-3"><x-forms.label :label="__('overhead_allocations.effective_from')" required /><x-forms.date-input name="effective_from" :value="old('effective_from', $period->from_date->toDateString())" required /></div>
                    <div class="col-md-3"><label class="form-label">{{ __('overhead_allocations.effective_to') }}</label><x-forms.date-input name="effective_to" :value="old('effective_to', $period->to_date->toDateString())" /></div>
                    <div class="col-md-6"><label class="form-label">{{ __('overhead_allocations.notes') }}</label><x-forms.input name="notes" :value="old('notes')" /></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('overhead_allocations.save_rule') }}</button></div>
                </form>
            </div>
        </div>
    @endcan

    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-striped mb-0">
        <thead><tr><th>{{ __('overhead_allocations.document') }}</th><th>{{ __('overhead_allocations.name') }}</th><th>{{ __('overhead_allocations.source_cost_center') }}</th><th>{{ __('overhead_allocations.basis') }}</th><th>{{ __('overhead_allocations.cost_behavior') }}</th><th>{{ __('overhead_allocations.status') }}</th></tr></thead>
        <tbody>@forelse($rules as $rule)<tr><td dir="ltr">{{ $rule->doc_num }}</td><td>{{ $rule->name }}</td><td>{{ $rule->sourceCostCenter?->codeNameLabel() }}</td><td>{{ __('overhead_allocations.basis_options.'.$rule->basis) }}</td><td>{{ __('overhead_allocations.behavior_options.'.$rule->cost_behavior) }}</td><td>{{ $rule->status }}</td></tr>@empty<tr><td colspan="6" class="text-center text-600 py-4">{{ __('overhead_allocations.no_records') }}</td></tr>@endforelse</tbody>
    </table></div></div>@if($rules->hasPages())<div class="card-footer">{{ $rules->links() }}</div>@endif</div>
@endsection
