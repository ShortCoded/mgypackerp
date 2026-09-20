@extends('layouts.app')

@section('title', __('hr_workforce_reports.employees.title'))

@section('content')
    @php($routeName = 'admin.hr.reports.employees')
    @php($exportOptions = collect(['csv' => 'file-csv', 'xlsx' => 'file-excel', 'pdf' => 'file-pdf'])->map(fn ($icon, $format) => ['permission' => 'hr.employee_reports.export', 'url' => route($routeName.'.export', [...$filters, 'format' => $format]), 'label' => strtoupper($format), 'icon' => $icon, 'newTab' => $format === 'pdf'])->values()->all())
    <div class="container-fluid px-0 px-sm-3 admin-report-page">
        <x-admin.report.page :title="__('hr_workforce_reports.employees.title')" :description="__('hr_workforce_reports.employees.description')">
            <x-slot:actions>
                <x-admin.report.actions-toolbar filter-target="hr-employee-report-filters" :refresh-url="request()->fullUrl()" :export-options="$exportOptions" />
            </x-slot:actions>

            <x-admin.report.filter-panel id="hr-employee-report-filters" :action="route($routeName)" method="GET" :expanded="collect($filters)->filter()->isNotEmpty()" :reset-url="route($routeName)">
                <div class="col-12 col-lg-3">
                    <x-forms.label for="branch_doc_num" :label="__('hr_workforce_reports.columns.branch')" />
                    <x-forms.select id="branch_doc_num" name="branch_doc_num" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach($branches as $option)<option value="{{ $option->doc_num }}" @selected(($filters['branch_doc_num'] ?? null) === $option->doc_num)>{{ $option->name }}</option>@endforeach</x-forms.select>
                </div>
                @foreach(['department_doc_num' => ['department', $departments], 'section_doc_num' => ['section', $sections], 'job_doc_num' => ['job', $jobs], 'employment_type_doc_num' => ['employment_type', $employmentTypes]] as $name => [$label, $options])
                    <div class="col-12 col-md-6 col-lg-3"><x-forms.label :for="$name" :label="__('hr_workforce_reports.columns.'.$label)" /><x-forms.select :id="$name" :name="$name" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach($options as $option)<option value="{{ $option->doc_num }}" @selected(($filters[$name] ?? null) === $option->doc_num)>{{ $option->name }}</option>@endforeach</x-forms.select></div>
                @endforeach
                <div class="col-6 col-lg-2"><x-forms.label for="status" :label="__('hr_workforce_reports.columns.status')" /><x-forms.select id="status" name="status" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach(['active', 'inactive', 'suspended', 'stopped', 'terminated', 'left'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ __('hr.employees.statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                <div class="col-6 col-lg-2"><x-forms.label for="gender" :label="__('hr_workforce_reports.columns.gender')" /><x-forms.select id="gender" name="gender" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach(['male', 'female'] as $gender)<option value="{{ $gender }}" @selected(($filters['gender'] ?? null) === $gender)>{{ __('hr.employees.genders.'.$gender) }}</option>@endforeach</x-forms.select></div>
                <div class="col-6 col-lg-2"><x-forms.label for="hire_from" :label="__('hr_workforce_reports.filters.hire_from')" /><x-forms.date-input id="hire_from" name="hire_from" :value="$filters['hire_from'] ?? null" /></div>
                <div class="col-6 col-lg-2"><x-forms.label for="hire_to" :label="__('hr_workforce_reports.filters.hire_to')" /><x-forms.date-input id="hire_to" name="hire_to" :value="$filters['hire_to'] ?? null" /></div>
            </x-admin.report.filter-panel>

            <div class="card report-table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead><tr>@foreach(['employee_code', 'employee', 'branch', 'department', 'section', 'job', 'employment_type', 'hire_date', 'gender', 'status'] as $column)<th>{{ __('hr_workforce_reports.columns.'.$column) }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse($report as $row)
                        <tr>
                            <td dir="ltr">{{ $row->employee_code ?: $row->doc_num }}</td><td>{{ $row->full_name }}</td><td>{{ $row->branch_name ?: '—' }}</td><td>{{ $row->department_name ?: '—' }}</td><td>{{ $row->section_name ?: '—' }}</td><td>{{ $row->job_name ?: '—' }}</td><td>{{ $row->employment_type_name ?: '—' }}</td><td dir="ltr">{{ $row->hire_date ?: '—' }}</td><td>{{ $row->gender ? __('hr.employees.genders.'.$row->gender) : '—' }}</td><td>{{ __('hr.employees.statuses.'.$row->status) }}</td>
                        </tr>
                    @empty<tr><td colspan="10" class="text-center text-muted py-4">{{ __('hr_workforce_reports.empty') }}</td></tr>@endforelse
                </tbody>
            </table></div>@if($report->hasPages())<div class="card-footer">{{ $report->links() }}</div>@endif</div>
        </x-admin.report.page>
    </div>
@endsection
