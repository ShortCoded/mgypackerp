@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $isAttendanceReport = request()->routeIs('admin.hr.reports.*');
    $attendanceIndexRoute = $isAttendanceReport ? 'admin.hr.reports.attendance' : 'admin.hr.employee-attendance.index';
    $attendanceExportRoute = $isAttendanceReport ? 'admin.hr.reports.attendance.export.csv' : 'admin.hr.employee-attendance.export.csv';
    $attendanceExportPermission = $isAttendanceReport ? 'hr.attendance_report.export' : 'hr.employee_attendance.export';
    $hasFilters = $filters !== [] || $errors->any();
    $summaryCards = [
        'sessions' => $summary['session_count'],
        'employees' => $summary['employee_count'],
        'open' => $summary['open_count'],
        'worked' => $summary['worked_minutes'],
        'breaks' => $summary['break_minutes'],
        'late' => $summary['late_minutes'],
        'early' => $summary['early_leave_minutes'],
        'overtime' => $summary['overtime_minutes'],
    ];
@endphp

@section('title', __('hr_attendance.admin.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-admin.report.page :title="__('hr_attendance.admin.title')" :description="__('hr_attendance.report.description')">
            <x-slot:actions>
                @if(! $isAttendanceReport && auth()->user()?->can('hr.employee_attendance.import'))
                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.hr.employee-attendance.import.index') }}">
                        <span class="fas fa-file-import me-1" aria-hidden="true"></span>{{ __('hr_attendance.import.action') }}
                    </a>
                @endif
                <x-admin.report.actions-toolbar
                    filter-target="attendance-report-filters"
                    :refresh-url="request()->fullUrl()"
                    :export-options="[[
                        'label' => __('reports.export_csv'),
                        'url' => route($attendanceExportRoute, $filters),
                        'icon' => 'file-csv',
                        'permission' => $attendanceExportPermission,
                    ]]" />
            </x-slot:actions>

            <x-admin.report.filter-panel
                id="attendance-report-filters"
                :action="route($attendanceIndexRoute)"
                :expanded="$hasFilters"
                :reset-url="route($attendanceIndexRoute)">
                <div class="col-12 col-md-6 col-xl-3">
                    <x-forms.label for="attendance_employee" :label="__('hr_attendance.labels.employee')" />
                    <x-forms.select class="form-select-sm js-report-filter-control" id="attendance_employee" name="employee" variant="ajax" :url="route('admin.hr.select2.employees', ['identity' => 'doc_num'])" :placeholder="__('common.trash.all')">
                        <option value="">{{ __('common.trash.all') }}</option>
                        @if ($selectedFilterEmployee)<option value="{{ $selectedFilterEmployee->doc_num }}" selected>{{ $selectedFilterEmployee->full_name }} / {{ $selectedFilterEmployee->doc_num }}</option>@endif
                    </x-forms.select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <x-forms.label for="attendance_branch" :label="__('hr_attendance.report.columns.branch')" />
                    <x-forms.select class="form-select-sm js-report-filter-control" id="attendance_branch" name="branch" variant="ajax" :url="route('admin.select2.branches', ['access_scope' => 'operating_scope'])" :placeholder="__('common.trash.all')">
                        <option value="">{{ __('common.trash.all') }}</option>
                        @if ($selectedBranch)<option value="{{ $selectedBranch->doc_num }}" selected>{{ $selectedBranch->name }} / {{ $selectedBranch->doc_num }}</option>@endif
                    </x-forms.select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <x-forms.label for="date_from" :label="__('hr_attendance.labels.from')" />
                    <x-forms.date-input class="form-control form-control-sm js-report-filter-control" id="date_from" name="date_from" :value="$filters['date_from'] ?? null" />
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <x-forms.label for="date_to" :label="__('hr_attendance.labels.to')" />
                    <x-forms.date-input class="form-control form-control-sm js-report-filter-control" id="date_to" name="date_to" :value="$filters['date_to'] ?? null" />
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <x-forms.label for="attendance_status" :label="__('hr_attendance.labels.status')" />
                    <x-forms.select class="form-select-sm js-report-filter-control" id="attendance_status" name="status" variant="local">
                        <option value="">{{ __('common.trash.all') }}</option>
                        <option value="open" @selected(($filters['status'] ?? null) === 'open')>{{ __('hr_attendance.session_status.open') }}</option>
                        <option value="closed" @selected(($filters['status'] ?? null) === 'closed')>{{ __('hr_attendance.session_status.closed') }}</option>
                    </x-forms.select>
                </div>
            </x-admin.report.filter-panel>

            <div class="row g-2 mb-3" data-attendance-summary>
                @foreach ($summaryCards as $key => $value)
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="card h-100">
                            <div class="card-body py-3 text-center">
                                <div class="small text-600">{{ __('hr_attendance.report.summary.'.$key) }}</div>
                                <strong class="fs-7" dir="ltr">{{ $value }}</strong>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if(! $isAttendanceReport && auth()->user()?->can('hr.employee_attendance.correct'))
                <div class="card mb-3">
                    <div class="card-header py-2"><h6 class="mb-0">{{ __('hr_attendance.admin.manual_title') }}</h6></div>
                    <div class="card-body py-3">
                        <form method="POST" action="{{ route('admin.hr.employee-attendance.manual.store') }}" class="row g-2 align-items-end">
                            @csrf
                            <x-forms.input type="hidden" name="idempotency_key" value="{{ $manualIdempotencyKey }}" />
                            <div class="col-12 col-md-4">
                                <x-forms.label for="manual_employee" :label="__('hr_attendance.labels.employee')" :required="true" />
                                <x-forms.select id="manual_employee" name="employee_doc_num" variant="ajax" :url="route('admin.hr.select2.employees', ['identity' => 'doc_num'])" :placeholder="__('common.placeholders.select')" :allow-clear="false" required>
                                    <option value="">{{ __('common.placeholders.select') }}</option>
                                    @if ($selectedManualEmployee)<option value="{{ $selectedManualEmployee->doc_num }}" selected>{{ $selectedManualEmployee->full_name }} / {{ $selectedManualEmployee->doc_num }}</option>@endif
                                </x-forms.select>
                            </div>
                            <div class="col-6 col-md-2">
                                <x-forms.label for="manual_event_type" :label="__('hr_attendance.labels.event')" :required="true" />
                                <x-forms.select id="manual_event_type" name="event_type" variant="local" :allow-clear="false" required>
                                    @foreach (\Modules\HR\Models\HrAttendanceEvent::types() as $eventType)
                                        <option value="{{ $eventType }}">{{ __('hr_attendance.actions.'.$eventType) }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                            <div class="col-6 col-md-3">
                                <x-forms.label for="manual_occurred_at" :label="__('hr_attendance.labels.time')" :required="true" />
                                <x-forms.date-input id="manual_occurred_at" name="occurred_at" :value="$dates->formatDateTime(now())" enable-time required />
                            </div>
                            <div class="col-8 col-md-2">
                                <x-forms.label for="manual_notes" :label="__('hr_attendance.labels.reason')" :required="true" />
                                <x-forms.input class="form-control" id="manual_notes" name="notes" required />
                            </div>
                            <div class="col-4 col-md-1">
                                <button class="btn btn-primary w-100" type="submit" aria-label="{{ __('hr_attendance.admin.manual_title') }}"><span class="fas fa-plus"></span></button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            <div class="d-none d-lg-block">
                <x-admin.report.table-card :title="__('hr_attendance.report.table_title')" table-id="employee-attendance-report-table">
                    <thead>
                        <tr>
                            @foreach (['work_date', 'employee', 'branch', 'shift', 'status', 'check_in', 'check_out', 'worked_minutes', 'break_minutes', 'events', 'location_result'] as $column)
                                <th class="text-nowrap">{{ __('hr_attendance.report.columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sessions as $session)
                            <tr>
                                <td class="text-nowrap" dir="ltr">{{ $dates->formatDate($session->work_date, '—') }}</td>
                                <td class="text-nowrap"><strong>{{ $session->employee?->full_name }}</strong><div class="small text-600" dir="ltr">{{ $session->employee?->doc_num }}</div></td>
                                <td class="text-nowrap">{{ $session->assignedBranch?->name ?: __('hr_attendance.labels.no_branch') }}</td>
                                <td class="text-nowrap">{{ $session->shift?->name ?: '—' }}</td>
                                <td><span class="badge badge-subtle-{{ $session->status === 'open' ? 'success' : 'secondary' }}">{{ __('hr_attendance.session_status.'.$session->status) }}</span></td>
                                <td class="text-nowrap" dir="ltr">{{ $dates->formatDateTime($session->started_at, '—') }}</td>
                                <td class="text-nowrap" dir="ltr">{{ $dates->formatDateTime($session->ended_at, '—') }}</td>
                                <td dir="ltr">{{ $session->worked_minutes }}</td>
                                <td dir="ltr">{{ $session->total_break_minutes }}</td>
                                <td><div class="d-flex flex-wrap gap-1">@foreach ($session->events as $event)<span class="badge badge-subtle-secondary text-nowrap">{{ __('hr_attendance.actions.'.$event->event_type) }} · {{ $dates->formatDateTime($event->occurred_at, '—') }}</span>@endforeach</div></td>
                                <td><div class="d-flex flex-wrap gap-1">@foreach ($session->events->unique('geofence_status') as $event)<span class="badge badge-subtle-{{ $event->geofence_status === 'inside' ? 'success' : 'warning' }} text-nowrap">{{ __('hr_attendance.geofence.'.$event->geofence_status) }}</span>@endforeach</div></td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="text-center text-600 py-4">{{ __('hr_attendance.labels.no_sessions') }}</td></tr>
                        @endforelse
                    </tbody>
                </x-admin.report.table-card>
            </div>

            <div class="row g-2 d-lg-none" data-attendance-mobile-cards>
                @forelse ($sessions as $session)
                    <div class="col-12 col-md-6">
                        <article class="card h-100">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between gap-2">
                                    <div><h6 class="mb-1">{{ $session->employee?->full_name }}</h6><div class="small text-600">{{ $session->employee?->doc_num }} · {{ $session->assignedBranch?->name ?: __('hr_attendance.labels.no_branch') }}</div></div>
                                    <span class="badge badge-subtle-{{ $session->status === 'open' ? 'success' : 'secondary' }} align-self-start">{{ __('hr_attendance.session_status.'.$session->status) }}</span>
                                </div>
                                <div class="small text-600 mt-2" dir="ltr">{{ $dates->formatDate($session->work_date, '—') }}</div>
                                <div class="row g-2 mt-1 text-center">
                                    <div class="col-4"><div class="small text-600">{{ __('hr_attendance.labels.check_in') }}</div><strong dir="ltr">{{ $dates->formatDateTime($session->started_at, '—') }}</strong></div>
                                    <div class="col-4"><div class="small text-600">{{ __('hr_attendance.labels.check_out') }}</div><strong dir="ltr">{{ $dates->formatDateTime($session->ended_at, '—') }}</strong></div>
                                    <div class="col-4"><div class="small text-600">{{ __('hr_attendance.labels.worked') }}</div><strong dir="ltr">{{ $session->worked_minutes }}</strong></div>
                                </div>
                                <div class="mt-3 d-flex flex-wrap gap-1">@foreach ($session->events as $event)<span class="badge badge-subtle-{{ $event->geofence_status === 'inside' ? 'success' : 'warning' }}">{{ __('hr_attendance.actions.'.$event->event_type) }} · {{ $dates->formatDateTime($event->occurred_at, '—') }}</span>@endforeach</div>
                            </div>
                        </article>
                    </div>
                @empty
                    <div class="col-12"><div class="card"><div class="card-body text-center text-600 py-5">{{ __('hr_attendance.labels.no_sessions') }}</div></div></div>
                @endforelse
            </div>

            <div class="mt-3">{{ $sessions->links() }}</div>
        </x-admin.report.page>
    </div>
@endsection
