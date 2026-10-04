@extends('layouts.app')

@section('title', __('hr_work_calendars.title'))

@php($dates = app(\Modules\Core\Services\DateFormatService::class))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="card mb-3"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><h4 class="mb-1">{{ __('hr_work_calendars.title') }}</h4><div class="text-muted small">{{ __('hr_work_calendars.help') }}</div></div>
            <a class="btn btn-falcon-default" href="{{ route('admin.hr.payroll-attendance-policies.index') }}">{{ __('hr_work_calendars.actions.payroll_policy') }}</a>
        </div></div>

        @can('hr.work_calendars.manage')
            <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('hr_work_calendars.actions.create') }}</h5></div><div class="card-body">
                <form method="POST" action="{{ route('admin.hr.work-calendars.store') }}" class="row g-3">@csrf
                    <div class="col-md-3"><x-forms.label for="calendar_code" :label="__('hr_work_calendars.fields.code')" :required="true" /><x-forms.input id="calendar_code" name="code" :value="old('code')" /></div>
                    <div class="col-md-4"><x-forms.label for="calendar_name" :label="__('hr_work_calendars.fields.name')" :required="true" /><x-forms.input id="calendar_name" name="name" :value="old('name')" /></div>
                    <div class="col-md-3"><x-forms.label for="calendar_branch" :label="__('hr_work_calendars.fields.branch')" /><x-forms.select id="calendar_branch" variant="local" name="branch_doc_num">
                        @if ($canCreateCompanyCalendar)<option value="">{{ __('hr_work_calendars.company_scope') }}</option>@endif
                        @foreach ($branches as $branch)<option value="{{ $branch->doc_num }}" @selected(old('branch_doc_num') === $branch->doc_num)>{{ $branch->name }}</option>@endforeach
                    </x-forms.select></div>
                    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">{{ __('hr_work_calendars.actions.create') }}</button></div>
                </form>
            </div></div>
        @endcan

        <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('hr_work_calendars.calendars') }}</h5></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>{{ __('hr_work_calendars.fields.code') }}</th><th>{{ __('hr_work_calendars.fields.name') }}</th><th>{{ __('hr_work_calendars.fields.branch') }}</th><th>{{ __('hr_work_calendars.fields.status') }}</th><th></th></tr></thead><tbody>
            @forelse ($calendars as $calendar)<tr @class(['table-active' => $selected?->id === $calendar->id])><td dir="ltr">{{ $calendar->code }}</td><td>{{ $calendar->name }}</td><td>{{ $calendar->branch_id === null ? __('hr_work_calendars.company_scope') : ($branches->firstWhere('id', $calendar->branch_id)?->name ?? $calendar->branch_id) }}</td><td>{{ __('hr_work_calendars.status.'.$calendar->status) }}</td><td><a class="btn btn-sm btn-falcon-default" href="{{ route('admin.hr.work-calendars.index', ['calendar_id' => $calendar->id]) }}">{{ __('hr_work_calendars.actions.open') }}</a></td></tr>
            @empty<tr><td colspan="5" class="text-center text-muted py-4">{{ __('hr_work_calendars.empty') }}</td></tr>@endforelse
        </tbody></table></div>@if ($calendars->hasPages())<div class="card-footer">{{ $calendars->links() }}</div>@endif</div>

        @if ($selected)
            @if ($selected->branch_id === null && ! $canCreateCompanyCalendar)
                <div class="alert alert-info">{{ __('hr_work_calendars.company_read_only') }}</div>
            @endif
            @can('hr.work_calendars.manage')
                @if ($selected->branch_id !== null || $canCreateCompanyCalendar)
                    <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('hr_work_calendars.actions.fill_range') }}</h5></div><div class="card-body">
                        <p class="small text-muted">{{ __('hr_work_calendars.range_help') }}</p>
                        <form method="POST" action="{{ route('admin.hr.work-calendars.days.fill-range', $selected->id) }}" class="row g-3 align-items-end">@csrf
                            <div class="col-md-3"><x-forms.label for="calendar_range_from" :label="__('hr_work_calendars.fields.range_from')" :required="true" /><x-forms.date-input id="calendar_range_from" name="range_from" :value="old('range_from')" /></div>
                            <div class="col-md-3"><x-forms.label for="calendar_range_to" :label="__('hr_work_calendars.fields.range_to')" :required="true" /><x-forms.date-input id="calendar_range_to" name="range_to" :value="old('range_to')" /></div>
                            <div class="col-12"><div class="form-label">{{ __('hr_work_calendars.fields.working_weekdays') }}</div><div class="d-flex flex-wrap gap-3">@foreach (__('hr_work_calendars.weekdays') as $value => $label)<label class="form-check-label"><x-forms.input class="form-check-input me-1" type="checkbox" name="working_weekdays[]" :value="$value" :checked="in_array((string) $value, old('working_weekdays', ['0', '1', '2', '3', '4']), true)" />{{ $label }}</label>@endforeach</div></div>
                            <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('hr_work_calendars.actions.fill_range') }}</button></div>
                        </form>
                    </div></div>
                    <div class="row g-3 mb-3">
                        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_work_calendars.actions.add_day') }}</h5></div><div class="card-body">
                            <form method="POST" action="{{ route('admin.hr.work-calendars.days.store', $selected->id) }}" class="row g-3">@csrf
                                <div class="col-md-5"><x-forms.label for="calendar_day_date" :label="__('hr_work_calendars.fields.work_date')" :required="true" /><x-forms.date-input id="calendar_day_date" name="work_date" :value="old('work_date')" /></div>
                                <div class="col-md-7"><x-forms.label for="calendar_day_type" :label="__('hr_work_calendars.fields.day_type')" :required="true" /><x-forms.select id="calendar_day_type" variant="local" name="day_type"><option value="">{{ __('common.placeholders.select') }}</option>@foreach (__('hr_work_calendars.day_types') as $value => $label)<option value="{{ $value }}" @selected(old('day_type') === $value)>{{ $label }}</option>@endforeach</x-forms.select></div>
                                <div class="col-12"><x-forms.label for="calendar_day_label" :label="__('hr_work_calendars.fields.label')" /><x-forms.input id="calendar_day_label" name="label" :value="old('label')" /></div>
                                <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('hr_work_calendars.actions.add_day') }}</button></div>
                            </form>
                        </div></div></div>
                        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_work_calendars.actions.assign') }}</h5></div><div class="card-body">
                            <form method="POST" action="{{ route('admin.hr.work-calendars.assignments.store', $selected->id) }}" class="row g-3">@csrf
                                <div class="col-12"><x-forms.label for="calendar_employee" :label="__('hr_work_calendars.fields.employee')" :required="true" /><x-forms.select variant="ajax" id="calendar_employee" name="employee_id" :url="route('admin.hr.work-calendars.select2.employees', $selected->id)" :allow-clear="false" :placeholder="__('hr_work_calendars.fields.employee')" /></div>
                                <div class="col-md-6"><x-forms.label for="calendar_assignment_from" :label="__('hr_work_calendars.fields.effective_from')" :required="true" /><x-forms.date-input id="calendar_assignment_from" name="effective_from" :value="old('effective_from')" /></div>
                                <div class="col-md-6"><x-forms.label for="calendar_assignment_to" :label="__('hr_work_calendars.fields.effective_to')" /><x-forms.date-input id="calendar_assignment_to" name="effective_to" :value="old('effective_to')" /></div>
                                <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('hr_work_calendars.actions.assign') }}</button></div>
                            </form>
                        </div></div></div>
                    </div>
                @endif
            @endcan
            <div class="row g-3">
                <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_work_calendars.days') }}</h5></div><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>{{ __('hr_work_calendars.fields.work_date') }}</th><th>{{ __('hr_work_calendars.fields.day_type') }}</th><th>{{ __('hr_work_calendars.fields.label') }}</th>@can('hr.work_calendars.manage')<th>{{ __('hr_work_calendars.actions.change_day') }}</th>@endcan</tr></thead><tbody>
                    @forelse ($days as $day)
                        <tr><td>{{ $dates->formatDate($day->work_date, '') }}</td><td>{{ __('hr_work_calendars.day_types.'.$day->day_type) }}</td><td>{{ $day->label ?: '—' }}</td>
                            @can('hr.work_calendars.manage')<td>
                                @if ($selected->branch_id !== null || $canCreateCompanyCalendar)
                                    <details><summary class="btn btn-sm btn-falcon-default">{{ __('hr_work_calendars.actions.change_day') }}</summary>
                                        <form method="POST" action="{{ route('admin.hr.work-calendars.days.update', [$selected->id, $day->id]) }}" class="d-grid gap-2 mt-2" style="min-width: 13rem">@csrf @method('PATCH')
                                            <x-forms.input type="hidden" name="expected_day_type" :value="$day->day_type" />
                                            <x-forms.select variant="local" name="day_type" :aria-label="__('hr_work_calendars.fields.day_type')">@foreach (__('hr_work_calendars.day_types') as $value => $label)<option value="{{ $value }}" @selected($day->day_type === $value)>{{ $label }}</option>@endforeach</x-forms.select>
                                            <x-forms.input name="label" :value="$day->label" :placeholder="__('hr_work_calendars.fields.label')" />
                                            <x-forms.input name="reason" :placeholder="__('hr_work_calendars.fields.reason')" />
                                            <button class="btn btn-sm btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                                        </form>
                                    </details>
                                @endif
                            </td>@endcan
                        </tr>
                    @empty<tr><td colspan="{{ auth()->user()?->can('hr.work_calendars.manage') ? 4 : 3 }}" class="text-center text-muted py-4">{{ __('hr_work_calendars.empty_days') }}</td></tr>@endforelse
                </tbody></table></div>@if ($days->hasPages())<div class="card-footer">{{ $days->links() }}</div>@endif</div></div>
                <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_work_calendars.assignments') }}</h5></div><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>{{ __('hr_work_calendars.fields.employee') }}</th><th>{{ __('hr_work_calendars.fields.effective_from') }}</th><th>{{ __('hr_work_calendars.fields.effective_to') }}</th>@can('hr.work_calendars.manage')<th>{{ __('hr_work_calendars.actions.change_assignment') }}</th>@endcan</tr></thead><tbody>
                    @forelse ($assignments as $assignment)
                        <tr><td>{{ $assignment->full_name }} <small dir="ltr">{{ $assignment->doc_num }}</small></td><td>{{ $dates->formatDate($assignment->visible_from, '') }}</td><td>{{ $dates->formatDate($assignment->visible_to, '—') }}</td>
                            @can('hr.work_calendars.manage')<td>
                                @if (($selected->branch_id !== null || $canCreateCompanyCalendar) && $assignment->effective_from === $assignment->visible_from && $assignment->effective_to === $assignment->visible_to)
                                    <details><summary class="btn btn-sm btn-falcon-default">{{ __('hr_work_calendars.actions.change_assignment') }}</summary>
                                        <form method="POST" action="{{ route('admin.hr.work-calendars.assignments.update', [$selected->id, $assignment->id]) }}" class="d-grid gap-2 mt-2" style="min-width: 14rem">@csrf @method('PATCH')
                                            <x-forms.input type="hidden" name="expected_effective_from" :value="$assignment->visible_from" />
                                            <x-forms.input type="hidden" name="expected_effective_to" :value="$assignment->visible_to" />
                                            <x-forms.label for="assignment_from_{{ $assignment->id }}" :label="__('hr_work_calendars.fields.effective_from')" /><x-forms.date-input id="assignment_from_{{ $assignment->id }}" name="effective_from" :value="$assignment->visible_from" />
                                            <x-forms.label for="assignment_to_{{ $assignment->id }}" :label="__('hr_work_calendars.fields.effective_to')" /><x-forms.date-input id="assignment_to_{{ $assignment->id }}" name="effective_to" :value="$assignment->visible_to" />
                                            <x-forms.input name="reason" :placeholder="__('hr_work_calendars.fields.reason')" />
                                            <button class="btn btn-sm btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                                        </form>
                                    </details>
                                @endif
                            </td>@endcan
                        </tr>
                    @empty<tr><td colspan="{{ auth()->user()?->can('hr.work_calendars.manage') ? 4 : 3 }}" class="text-center text-muted py-4">{{ __('hr_work_calendars.empty_assignments') }}</td></tr>@endforelse
                </tbody></table></div>@if ($assignments->hasPages())<div class="card-footer">{{ $assignments->links() }}</div>@endif</div></div>
            </div>
        @endif
    </div>
@endsection
