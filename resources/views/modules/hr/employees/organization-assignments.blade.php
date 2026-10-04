@extends('layouts.app')

@section('title', __('hr_organization_assignments.title'))

@section('content')
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    <div class="container-fluid px-0 px-sm-3">
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-0">{{ __('hr_organization_assignments.title') }}</h5>
                    <div class="text-muted small">{{ $employee->full_name }} / <span dir="ltr">{{ $employee->doc_num }}</span></div>
                </div>
                <a href="{{ route('admin.hr.employees.show', $employee->doc_num) }}" class="btn btn-falcon-default btn-sm">{{ __('common.actions.back') }}</a>
            </div>
            @if (session('success'))<div class="card-body pb-0"><div class="alert alert-success mb-0">{{ session('success') }}</div></div>@endif
            @if ($errors->any())<div class="card-body pb-0"><div class="alert alert-danger mb-0"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
            @can('hr.employees.edit')
                <div class="card-body">
                    @if ($assignments->total() === 0)
                        <h6>{{ __('hr_organization_assignments.initial') }}</h6>
                        <p class="text-muted small">{{ __('hr_organization_assignments.empty') }}</p>
                        <form method="POST" action="{{ route('admin.hr.employees.organization-assignments.initial', $employee->doc_num) }}" class="row g-3" novalidate>
                            @csrf
                            <div class="col-md-3">
                                <label class="form-label">{{ __('hr_organization_assignments.labels.branch') }}</label>
                                <div class="form-control bg-body-tertiary">{{ $employee->branch?->name ?: '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="initial_effective_from">{{ __('hr_organization_assignments.labels.effective_from') }}</label>
                                <x-forms.date-input id="initial_effective_from" name="effective_from" :value="old('effective_from', $employee->hire_date?->toDateString())" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="initial_cost_center">{{ __('hr_organization_assignments.labels.cost_center') }}</label>
                                <x-forms.select variant="ajax" id="initial_cost_center" name="cost_center_doc_num" :url="route('admin.hr.select2.organization-cost-centers')" :placeholder="__('hr_organization_assignments.labels.cost_center')" />
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="initial_reason">{{ __('hr_organization_assignments.labels.reason') }}</label>
                                <x-forms.textarea id="initial_reason" name="reason" rows="2">{{ old('reason') }}</x-forms.textarea>
                            </div>
                            <div class="col-12"><button type="submit" class="btn btn-primary">{{ __('hr_organization_assignments.initial') }}</button></div>
                        </form>
                    @else
                        <h6>{{ __('hr_organization_assignments.transfer') }}</h6>
                        <form method="POST" action="{{ route('admin.hr.employees.organization-assignments.transfer', $employee->doc_num) }}" class="row g-3" novalidate>
                            @csrf
                            <div class="col-md-4">
                                <label class="form-label" for="transfer_branch">{{ __('hr_organization_assignments.labels.branch') }}</label>
                                <x-forms.select variant="ajax" id="transfer_branch" name="branch_doc_num" :url="route('admin.select2.branches')" :allow-clear="false" :placeholder="__('hr_organization_assignments.labels.branch')" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="transfer_department">{{ __('hr_organization_assignments.labels.department') }}</label>
                                <x-forms.select variant="ajax" id="transfer_department" name="department_doc_num" :url="route('admin.hr.select2.foundation', 'departments')" :placeholder="__('hr_organization_assignments.labels.department')" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="transfer_cost_center">{{ __('hr_organization_assignments.labels.cost_center') }}</label>
                                <x-forms.select variant="ajax" id="transfer_cost_center" name="cost_center_doc_num" :url="route('admin.hr.select2.organization-cost-centers')" :placeholder="__('hr_organization_assignments.labels.cost_center')" />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="transfer_effective_from">{{ __('hr_organization_assignments.labels.effective_from') }}</label>
                                <x-forms.date-input id="transfer_effective_from" name="effective_from" :value="old('effective_from', now()->toDateString())" />
                            </div>
                            <div class="col-md-9">
                                <label class="form-label" for="transfer_reason">{{ __('hr_organization_assignments.labels.reason') }}</label>
                                <x-forms.textarea id="transfer_reason" name="reason" rows="2">{{ old('reason') }}</x-forms.textarea>
                            </div>
                            <div class="col-12"><button type="submit" class="btn btn-primary">{{ __('hr_organization_assignments.transfer') }}</button></div>
                        </form>
                    @endif
                </div>
            @endcan
        </div>

        <div class="card">
            <div class="card-header"><h6 class="mb-0">{{ __('hr_organization_assignments.history') }}</h6></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('hr_organization_assignments.labels.effective_from') }}</th>
                        <th>{{ __('hr_organization_assignments.labels.effective_to') }}</th>
                        <th>{{ __('hr_organization_assignments.labels.branch') }}</th>
                        <th>{{ __('hr_organization_assignments.labels.department') }}</th>
                        <th>{{ __('hr_organization_assignments.labels.cost_center') }}</th>
                        <th>{{ __('hr_organization_assignments.labels.source') }}</th>
                        <th>{{ __('hr_organization_assignments.labels.reason') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($assignments as $assignment)
                            <tr>
                                <td dir="ltr">{{ $dates->formatDate($assignment->effective_from, '') }}</td>
                                <td dir="ltr">{{ $dates->formatDate($assignment->effective_to, '—') }}</td>
                                <td>{{ $assignment->branch_name }} / <span dir="ltr">{{ $assignment->branch_doc_num }}</span></td>
                                <td>{{ $assignment->department_name ?: '—' }}</td>
                                <td>{{ $assignment->cost_center_name ?: '—' }}</td>
                                <td>{{ __('hr_organization_assignments.sources.'.$assignment->source_type) }}</td>
                                <td>{{ $assignment->reason ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">{{ __('hr_organization_assignments.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($assignments->hasPages())<div class="card-footer">{{ $assignments->links() }}</div>@endif
        </div>
    </div>
@endsection
