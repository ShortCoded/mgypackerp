@extends('layouts.app')

@inject('numbers', 'Modules\Core\Services\NumericFormatService')

@section('title', __('hr_workforce_reports.leave_requests.title'))

@section('content')
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @php($routeName = 'admin.hr.reports.leave-requests')
    @php($exportOptions = collect(['csv' => 'file-csv', 'xlsx' => 'file-excel', 'pdf' => 'file-pdf'])->map(fn ($icon, $format) => ['permission' => 'hr.leave_reports.export', 'url' => route($routeName.'.export', [...$filters, 'format' => $format]), 'label' => strtoupper($format), 'icon' => $icon, 'newTab' => $format === 'pdf'])->values()->all())
    <div class="container-fluid px-0 px-sm-3 admin-report-page">
        <x-admin.report.page :title="__('hr_workforce_reports.leave_requests.title')" :description="__('hr_workforce_reports.leave_requests.description')">
            <x-slot:actions><x-admin.report.actions-toolbar filter-target="hr-leave-request-report-filters" :refresh-url="request()->fullUrl()" :export-options="$exportOptions" /></x-slot:actions>

            <x-admin.report.filter-panel id="hr-leave-request-report-filters" :action="route($routeName)" method="GET" :expanded="collect($filters)->filter()->isNotEmpty()" :reset-url="route($routeName)">
                <div class="col-12 col-lg-3"><x-forms.label for="employee" :label="__('hr_workforce_reports.columns.employee')" /><x-forms.input id="employee" name="employee" :value="$filters['employee'] ?? null" /></div>
                <div class="col-12 col-lg-3"><x-forms.label for="branch_doc_num" :label="__('hr_workforce_reports.columns.branch')" /><x-forms.select id="branch_doc_num" name="branch_doc_num" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach($branches as $option)<option value="{{ $option->doc_num }}" @selected(($filters['branch_doc_num'] ?? null) === $option->doc_num)>{{ $option->name }}</option>@endforeach</x-forms.select></div>
                <div class="col-12 col-lg-3"><x-forms.label for="department_doc_num" :label="__('hr_workforce_reports.columns.department')" /><x-forms.select id="department_doc_num" name="department_doc_num" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach($departments as $option)<option value="{{ $option->doc_num }}" @selected(($filters['department_doc_num'] ?? null) === $option->doc_num)>{{ $option->name }}</option>@endforeach</x-forms.select></div>
                <div class="col-12 col-lg-3"><x-forms.label for="request_type" :label="__('hr_workforce_reports.columns.request_type')" /><x-forms.select id="request_type" name="request_type" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach($requestTypes as $type)<option value="{{ $type }}" @selected(($filters['request_type'] ?? null) === $type)>{{ __('hr_requests.types.'.$type) }}</option>@endforeach</x-forms.select></div>
                <div class="col-12 col-lg-3"><x-forms.label for="leave_type_code" :label="__('hr_workforce_reports.columns.leave_type')" /><x-forms.select id="leave_type_code" name="leave_type_code" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach($leaveTypes as $option)<option value="{{ $option->code }}" @selected(($filters['leave_type_code'] ?? null) === $option->code)>{{ $option->name }}</option>@endforeach</x-forms.select></div>
                <div class="col-12 col-lg-3"><x-forms.label for="status" :label="__('hr_workforce_reports.columns.status')" /><x-forms.select id="status" name="status" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach(['submitted', 'approved', 'rejected', 'cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ __('hr_requests.statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                <div class="col-12 col-lg-3"><x-forms.label for="approver_id" :label="__('hr_workforce_reports.columns.approver')" /><x-forms.select id="approver_id" name="approver_id" class="form-select"><option value="">{{ __('hr_workforce_reports.filters.all') }}</option>@foreach($approvers as $option)<option value="{{ $option->id }}" @selected((string)($filters['approver_id'] ?? '') === (string)$option->id)>{{ $option->name }}</option>@endforeach</x-forms.select></div>
                <div class="col-6 col-lg-2"><x-forms.label for="date_from" :label="__('hr_workforce_reports.filters.date_from')" /><x-forms.date-input id="date_from" name="date_from" :value="$filters['date_from'] ?? null" /></div>
                <div class="col-6 col-lg-2"><x-forms.label for="date_to" :label="__('hr_workforce_reports.filters.date_to')" /><x-forms.date-input id="date_to" name="date_to" :value="$filters['date_to'] ?? null" /></div>
            </x-admin.report.filter-panel>

            <div class="row g-2 mb-3">@foreach($report['totals'] as $key => $value)<div class="col-6 col-lg-3"><div class="card"><div class="card-body py-3 text-center"><div class="small text-muted">{{ __('hr_workforce_reports.totals.'.$key) }}</div><strong dir="ltr">{{ in_array($key, ['leave_days', 'paid_leave_days', 'unpaid_leave_days'], true) ? $numbers->format($value) : $value }}</strong></div></div></div>@endforeach</div>

            <div class="card report-table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead><tr>@foreach(['document_number', 'employee', 'request_type', 'leave_type', 'from', 'to', 'days_duration', 'balance_impact', 'status', 'approver', 'approval_date'] as $column)<th>{{ __('hr_workforce_reports.columns.'.$column) }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse($report['rows'] as $row)
                        <tr><td dir="ltr">{{ $row->public_uuid }}</td><td>{{ $row->employee_name }}</td><td>{{ __('hr_requests.types.'.$row->request_type) }}</td><td>{{ $row->leave_type_name ?: '—' }}</td><td dir="ltr">{{ $dates->formatDate($row->requested_from, '—') }}</td><td dir="ltr">{{ $dates->formatDate($row->requested_to, '—') }}</td><td dir="ltr">{{ $row->request_type === 'leave' ? $numbers->format($row->leave_days) : ($row->requested_minutes === null ? '—' : __('hr_workforce_reports.units.minutes_value', ['value' => $row->requested_minutes])) }}</td><td dir="ltr">{{ $numbers->format($row->balance_impact) }}</td><td>{{ __('hr_requests.statuses.'.$row->status) }}</td><td>{{ $row->approver_name ?: '—' }}</td><td dir="ltr">{{ $dates->formatDateTime($row->resolved_at, '—') }}</td></tr>
                    @empty<tr><td colspan="11" class="text-center text-muted py-4">{{ __('hr_workforce_reports.empty') }}</td></tr>@endforelse
                </tbody>
            </table></div>@if($report['rows']->hasPages())<div class="card-footer">{{ $report['rows']->links() }}</div>@endif</div>
        </x-admin.report.page>
    </div>
@endsection
