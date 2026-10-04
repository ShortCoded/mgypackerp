@extends('layouts.app')

@section('title', __('inventory_cost_policy.title'))

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('content')
<div class="container-fluid py-3">
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="card mb-3">
        <div class="card-body">
            <h4 class="mb-1">{{ __('inventory_cost_policy.title') }}</h4>
            <p class="mb-0 text-muted">{{ __('inventory_cost_policy.help') }}</p>
        </div>
    </div>

    @can('inventory.cost_policies.manage')
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ __('inventory_cost_policy.new_version') }}</h5></div>
            <div class="card-body">
                <form novalidate method="POST" action="{{ route('admin.inventory.cost-policies.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-4">
                        <x-forms.label for="cost_policy_branch" :label="__('inventory_cost_policy.branch')" />
                        <x-forms.select id="cost_policy_branch" name="branch_doc_num" variant="ajax" :url="route('admin.inventory.cost-policies.select2.branches')" :placeholder="__('inventory_cost_policy.company_scope')">
                            <option value=""></option>
                            @if($selectedBranch)<option value="{{ $selectedBranch->doc_num }}" selected>{{ $selectedBranch->doc_num }} — {{ $selectedBranch->name }}</option>@endif
                        </x-forms.select>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="cost_policy_store" :label="__('inventory_cost_policy.store')" />
                        <x-forms.select id="cost_policy_store" name="branch_store_uuid" variant="ajax" :url="route('admin.inventory.cost-policies.select2.stores')" data-depends-on="#cost_policy_branch" data-dependent-param="branch_doc_num" :placeholder="__('common.placeholders.select')">
                            <option value=""></option>
                            @if($selectedStore)<option value="{{ $selectedStore->public_uuid }}" selected>{{ $selectedStore->branch?->name }} — {{ $selectedStore->name }}</option>@endif
                        </x-forms.select>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="cost_policy_method" :label="__('inventory_cost_policy.method')" :required="true" />
                        <x-forms.select id="cost_policy_method" name="method" variant="local" required>
                            @foreach ([\Modules\Inventory\Models\InventoryCostPolicy::MovingAverage, \Modules\Inventory\Models\InventoryCostPolicy::PeriodicWeightedAverage, \Modules\Inventory\Models\InventoryCostPolicy::Fifo, \Modules\Inventory\Models\InventoryCostPolicy::SpecificIdentification] as $method)
                                <option value="{{ $method }}" @selected(old('method', \Modules\Inventory\Models\InventoryCostPolicy::MovingAverage) === $method)>{{ __('inventory_cost_policy.methods.'.$method) }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="cost_policy_effective" :label="__('inventory_cost_policy.effective_from')" :required="true" />
                        <x-forms.date-input id="cost_policy_effective" name="effective_from" :value="old('effective_from', now()->toDateString())" required />
                    </div>
                    <div class="col-md-8">
                        <x-forms.label for="cost_policy_reason" :label="__('inventory_cost_policy.reason')" />
                        <x-forms.input id="cost_policy_reason" name="reason" :value="old('reason')" maxlength="1000" />
                    </div>
                    <div class="col-12"><div class="small text-muted">{{ __('inventory_cost_policy.branch_help') }}</div><div class="small text-muted">{{ __('inventory_cost_policy.future_note') }}</div><div class="small text-muted">{{ __('inventory_cost_policy.fifo_note') }}</div></div>
                    <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit">{{ __('inventory_cost_policy.save') }}</button></div>
                </form>
            </div>
        </div>
    @endcan

    @can('inventory.cost_policies.transition.prepare')
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ __('inventory_cost_policy.transition_title') }}</h5></div>
            <div class="card-body">
                <p class="text-muted">{{ __('inventory_cost_policy.transition_help') }}</p>
                <form novalidate method="POST" action="{{ route('admin.inventory.cost-policies.transitions.prepare') }}" class="row g-3">
                    @csrf
                    <div class="col-md-4">
                        <x-forms.label for="cost_transition_branch" :label="__('inventory_cost_policy.branch')" />
                        <x-forms.select id="cost_transition_branch" name="transition_branch_doc_num" variant="ajax" :url="route('admin.inventory.cost-policies.select2.branches')" :placeholder="__('inventory_cost_policy.company_scope')">
                            <option value=""></option>
                            @if($selectedTransitionBranch)<option value="{{ $selectedTransitionBranch->doc_num }}" selected>{{ $selectedTransitionBranch->doc_num }} — {{ $selectedTransitionBranch->name }}</option>@endif
                        </x-forms.select>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="cost_transition_store" :label="__('inventory_cost_policy.store')" />
                        <x-forms.select id="cost_transition_store" name="transition_branch_store_uuid" variant="ajax" :url="route('admin.inventory.cost-policies.select2.stores')" data-depends-on="#cost_transition_branch" data-dependent-param="branch_doc_num" :placeholder="__('common.placeholders.select')">
                            <option value=""></option>
                            @if($selectedTransitionStore)<option value="{{ $selectedTransitionStore->public_uuid }}" selected>{{ $selectedTransitionStore->branch?->name }} — {{ $selectedTransitionStore->name }}</option>@endif
                        </x-forms.select>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="cost_transition_effective" :label="__('inventory_cost_policy.effective_from')" :required="true" />
                        <x-forms.date-input id="cost_transition_effective" name="transition_effective_from" :value="old('transition_effective_from', now()->addDay()->toDateString())" required />
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="cost_transition_reason" :label="__('inventory_cost_policy.reason')" :required="true" />
                        <x-forms.input id="cost_transition_reason" name="transition_reason" :value="old('transition_reason')" maxlength="1000" required />
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="cost_transition_target" :label="__('inventory_cost_policy.target_method')" required />
                        <x-forms.select id="cost_transition_target" name="transition_target_method" variant="local" required>
                            @foreach([\Modules\Inventory\Models\InventoryCostPolicy::Fifo, \Modules\Inventory\Models\InventoryCostPolicy::SpecificIdentification] as $method)
                                <option value="{{ $method }}" @selected(old('transition_target_method', \Modules\Inventory\Models\InventoryCostPolicy::Fifo) === $method)>{{ __('inventory_cost_policy.methods.'.$method) }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end justify-content-end">
                        <button class="btn btn-outline-primary" type="submit">{{ __('inventory_cost_policy.prepare_transition') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    @if($transitions->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ __('inventory_cost_policy.transition_previews') }}</h5></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>{{ __('inventory_cost_policy.scope') }}</th><th>{{ __('inventory_cost_policy.target_method') }}</th><th>{{ __('inventory_cost_policy.effective_from') }}</th><th>{{ __('inventory_cost_policy.status') }}</th><th>{{ __('inventory_cost_policy.quantity') }}</th><th>{{ __('inventory_cost_policy.book_value') }}</th><th>{{ __('inventory_cost_policy.layers') }}</th><th>{{ __('inventory_cost_policy.actions') }}</th></tr></thead>
                    <tbody>
                    @foreach($transitions as $transition)
                        <tr>
                            <td>{{ $transition->branchStore?->name ?? $transition->branch?->name ?? __('inventory_cost_policy.company_scope') }}</td>
                            <td>{{ __('inventory_cost_policy.methods.'.$transition->target_method) }}</td>
                            <td>{{ $dates->formatDate($transition->effective_from, '') }}</td>
                            <td>{{ __('inventory_cost_policy.transition_statuses.'.$transition->status) }}</td>
                            <td>{{ $numbers->format($transition->total_quantity) }}</td>
                            <td>{{ $numbers->format($transition->total_book_value) }}</td>
                            <td>{{ $numbers->format($transition->bases_count) }}</td>
                            <td class="d-flex gap-2 flex-wrap">
                                @if($transition->status === \Modules\Inventory\Models\InventoryCostPolicyTransition::StatusPrepared && (int) $transition->prepared_by !== (int) auth()->id())
                                    @can('inventory.cost_policies.transition.approve')
                                        <form novalidate method="POST" action="{{ route('admin.inventory.cost-policies.transitions.approve', $transition) }}">@csrf<button class="btn btn-sm btn-success" type="submit">{{ __('inventory_cost_policy.approve_transition') }}</button></form>
                                    @endcan
                                @elseif($transition->status === \Modules\Inventory\Models\InventoryCostPolicyTransition::StatusApproved)
                                    @can('inventory.cost_policies.transition.activate')
                                        <form novalidate method="POST" action="{{ route('admin.inventory.cost-policies.transitions.activate', $transition) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">{{ __('inventory_cost_policy.activate_transition') }}</button></form>
                                    @endcan
                                @endif
                                @if(in_array($transition->status, [\Modules\Inventory\Models\InventoryCostPolicyTransition::StatusPrepared, \Modules\Inventory\Models\InventoryCostPolicyTransition::StatusApproved], true))
                                    @can('inventory.cost_policies.transition.cancel')
                                        <form novalidate method="POST" action="{{ route('admin.inventory.cost-policies.transitions.cancel', $transition) }}" class="d-flex gap-1">
                                            @csrf
                                            <x-forms.label :for="'transition_cancellation_reason_'.$transition->id" :label="__('inventory_cost_policy.cancellation_reason')" :required="true" class="visually-hidden" />
                                            <x-forms.input :id="'transition_cancellation_reason_'.$transition->id" class="form-control-sm" name="cancellation_reason" :placeholder="__('inventory_cost_policy.cancellation_reason')" />
                                            <button class="btn btn-sm btn-outline-danger" type="submit">{{ __('inventory_cost_policy.cancel_transition') }}</button>
                                        </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="alert alert-info">{{ __('inventory_cost_policy.analytical_note') }}</div>
    <div class="card">
        <div class="card-header"><h5 class="mb-0">{{ __('inventory_cost_policy.versions') }}</h5></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>{{ __('inventory_cost_policy.scope') }}</th><th>{{ __('inventory_cost_policy.method') }}</th><th>{{ __('inventory_cost_policy.effective_from') }}</th><th>{{ __('inventory_cost_policy.reason') }}</th></tr></thead><tbody>
            @forelse($policies as $policy)
                <tr><td>{{ $policy->branchStore?->name ?? $policy->branch?->name ?? __('inventory_cost_policy.company_scope') }}</td><td>{{ __('inventory_cost_policy.methods.'.$policy->method) }}</td><td>{{ $dates->formatDate($policy->effective_from, '') }}</td><td>{{ $policy->reason ?: '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">{{ __('inventory_cost_policy.empty') }}</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
    <div class="mt-3">{{ $policies->links() }}</div>
</div>
@endsection
