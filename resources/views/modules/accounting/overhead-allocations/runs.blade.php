@extends('layouts.app')

@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

@section('title', __('overhead_allocations.runs_title'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h1 class="h4 mb-1">{{ __('overhead_allocations.runs_title') }}</h1><p class="text-600 mb-0">{{ __('overhead_allocations.policy_snapshot') }}</p></div>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.costing.overhead-allocation-rules.index') }}">{{ __('overhead_allocations.rules_title') }}</a>
    </div>

    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    @can('costing.overhead_allocation_run.create')
        <div class="card mb-3"><div class="card-header"><h2 class="h6 mb-0">{{ __('overhead_allocations.new_preview') }}</h2></div><div class="card-body">
            <form method="POST" action="{{ route('admin.costing.overhead-allocation-run.preview') }}" class="row g-3 align-items-end">
                @csrf
                <div class="col-md-6"><label class="form-label">{{ __('overhead_allocations.rule') }}</label><select class="form-select js-select2-local" name="rule_public_id" required><option value=""></option>@foreach($rules as $rule)<option value="{{ $rule->public_id }}" @selected(old('rule_public_id') === $rule->public_id)>{{ $rule->doc_num }} / {{ $rule->name }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label">{{ __('overhead_allocations.from_date') }}</label><input class="form-control" type="date" name="from_date" value="{{ old('from_date', $period->from_date->toDateString()) }}" required></div>
                <div class="col-md-2"><label class="form-label">{{ __('overhead_allocations.to_date') }}</label><input class="form-control" type="date" name="to_date" value="{{ old('to_date', $period->to_date->toDateString()) }}" required></div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">{{ __('overhead_allocations.preview') }}</button></div>
            </form>
        </div></div>
    @endcan

    @if($selectedRun)
        <div class="row g-3 mb-3">
            @foreach(['eligible_cost', 'allocatable_cost', 'allocated_cost', 'unallocated_cost'] as $metric)<div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-2"><div class="text-600 fs-11">{{ __('overhead_allocations.'.$metric) }}</div><strong dir="ltr">{{ $numbers->format($selectedRun->{$metric}) }}</strong></div></div></div>@endforeach
        </div>
        <div class="card mb-3"><div class="card-header d-flex flex-wrap justify-content-between gap-2"><div><strong dir="ltr">{{ $selectedRun->doc_num }}</strong> — {{ $selectedRun->rule->name }} <span class="badge badge-subtle-secondary">{{ __('overhead_allocations.statuses.'.$selectedRun->status) }}</span></div><div>{{ __('overhead_allocations.basis_used') }}: {{ __('overhead_allocations.basis_options.'.$selectedRun->basis_used) }}@if($selectedRun->utilization_percent !== null) · {{ __('overhead_allocations.utilization') }}: <span dir="ltr">{{ $numbers->format($selectedRun->utilization_percent) }}%</span>@endif</div></div>
            @if($selectedRun->fallback_reason)<div class="alert alert-warning m-3 mb-0">{{ $selectedRun->fallback_reason }}</div>@endif
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>{{ __('overhead_allocations.production_run') }}</th><th>{{ __('overhead_allocations.work_order') }}</th><th>{{ __('overhead_allocations.product') }}</th><th>{{ __('overhead_allocations.machine_hours') }}</th><th>{{ __('overhead_allocations.labor_hours') }}</th><th>{{ __('overhead_allocations.direct_material_cost') }}</th><th>{{ __('overhead_allocations.basis_value') }}</th><th>{{ __('overhead_allocations.allocation_percent') }}</th><th>{{ __('overhead_allocations.allocated_amount') }}</th></tr></thead><tbody>@foreach($selectedRun->lines as $line)<tr><td>{{ $line->productionRun?->run_number }}</td><td>{{ $line->productionRun?->order?->doc_num }}</td><td>{{ $line->productionRun?->product?->name }}</td><td dir="ltr">{{ $line->machine_hours }}</td><td dir="ltr">{{ $line->labor_hours }}</td><td dir="ltr">{{ $numbers->format($line->direct_material_cost) }}</td><td dir="ltr">{{ $numbers->format($line->basis_value) }}</td><td dir="ltr">{{ $numbers->format($line->allocation_percent) }}%</td><td dir="ltr">{{ $numbers->format($line->allocated_amount) }}</td></tr>@endforeach</tbody></table></div></div>
            <div class="card-footer d-flex flex-wrap gap-2">
                @if($selectedRun->status === 'draft') @can('costing.overhead_allocation_run.approve')<form method="POST" action="{{ route('admin.costing.overhead-allocation-run.approve', $selectedRun->public_id) }}">@csrf<button class="btn btn-success" type="submit">{{ __('overhead_allocations.approve') }}</button></form>@endcan @endif
                @if($selectedRun->status === 'posted') @can('costing.overhead_allocation_run.reverse')<form method="POST" action="{{ route('admin.costing.overhead-allocation-run.reverse', $selectedRun->public_id) }}" class="d-flex gap-2">@csrf<input class="form-control" name="reason" placeholder="{{ __('overhead_allocations.reversal_reason') }}" required><button class="btn btn-outline-danger" type="submit">{{ __('overhead_allocations.reverse') }}</button></form>@endcan @endif
            </div>
        </div>
        <div class="card mb-3"><div class="card-header"><h2 class="h6 mb-0">{{ __('overhead_allocations.source_details') }}</h2></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('overhead_allocations.source_entry') }}</th><th>{{ __('overhead_allocations.account') }}</th><th>{{ __('overhead_allocations.amount') }}</th></tr></thead><tbody>@foreach($selectedRun->sources as $source)<tr><td>{{ $source->journalEntryLine?->journalEntry?->doc_num }}</td><td>{{ $source->account?->codeNameLabel() }}</td><td dir="ltr">{{ $numbers->format($source->source_amount) }}</td></tr>@endforeach</tbody></table></div></div></div>
    @endif

    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>{{ __('overhead_allocations.document') }}</th><th>{{ __('overhead_allocations.rule') }}</th><th>{{ __('overhead_allocations.from_date') }}</th><th>{{ __('overhead_allocations.to_date') }}</th><th>{{ __('overhead_allocations.allocated_cost') }}</th><th>{{ __('overhead_allocations.unallocated_cost') }}</th><th>{{ __('overhead_allocations.status') }}</th><th></th></tr></thead><tbody>@forelse($runs as $run)<tr><td dir="ltr">{{ $run->doc_num }}</td><td>{{ $run->rule?->name }}</td><td>{{ $run->from_date->toDateString() }}</td><td>{{ $run->to_date->toDateString() }}</td><td dir="ltr">{{ $numbers->format($run->allocated_cost) }}</td><td dir="ltr">{{ $numbers->format($run->unallocated_cost) }}</td><td>{{ __('overhead_allocations.statuses.'.$run->status) }}</td><td><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.costing.overhead-allocation-run.index', ['run' => $run->public_id]) }}">{{ __('overhead_allocations.view') }}</a></td></tr>@empty<tr><td colspan="8" class="text-center text-600 py-4">{{ __('overhead_allocations.no_records') }}</td></tr>@endforelse</tbody></table></div></div>@if($runs->hasPages())<div class="card-footer">{{ $runs->links() }}</div>@endif</div>
@endsection
