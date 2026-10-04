<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Select2ResponseService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\HrLifecycleAuditLogger;
use Modules\HR\Services\HrWorkCalendarService;

class HrWorkCalendarController extends Controller
{
    public function __construct(
        private readonly HrWorkCalendarService $calendars,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly HrLifecycleAuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $allowedBranchIds = $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
            ->pluck('branches.id')->all();
        $calendars = DB::table('hr_work_calendars')
            ->where('company_id', $company->getKey())->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('branch_id')
                ->orWhereIn('branch_id', $allowedBranchIds !== [] ? $allowedBranchIds : [0]))
            ->orderByDesc('id')->paginate(30)->withQueryString();
        $selectedId = $request->integer('calendar_id') ?: (int) ($calendars->first()?->id ?? 0);
        $selected = null;
        if ($selectedId > 0) {
            try {
                $selected = $this->calendars->calendar((int) $company->getKey(), $selectedId, $request->user());
            } catch (DomainException) {
                abort(404);
            }
        }

        $assignments = collect();
        if ($selected !== null) {
            $assignmentQuery = DB::table('hr_work_calendar_assignments as a')
                ->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
                ->where('a.calendar_id', $selected->id)->whereNull('a.deleted_at')
                ->where('e.company_id', $company->getKey());
            $assignmentColumns = ['a.id', 'a.effective_from', 'a.effective_to', 'e.doc_num', 'e.full_name'];
            if ($this->scope->hasUnrestrictedBranchAccess($request->user())) {
                $assignmentColumns[] = DB::raw('a.effective_from as visible_from');
                $assignmentColumns[] = DB::raw('a.effective_to as visible_to');
            } else {
                $branchIds = $allowedBranchIds !== [] ? $allowedBranchIds : [0];
                $assignmentQuery->leftJoin('hr_employee_organization_assignments as org', function ($join): void {
                    $join->on('org.employee_id', '=', 'e.id')
                        ->on('org.company_id', '=', 'e.company_id');
                })->where(function ($scope) use ($branchIds): void {
                    $scope->where(function ($dated) use ($branchIds): void {
                        $dated->whereIn('org.branch_id', $branchIds)
                            ->where(fn ($dates) => $dates->whereNull('a.effective_to')
                                ->orWhereColumn('org.effective_from', '<=', 'a.effective_to'))
                            ->where(fn ($dates) => $dates->whereNull('org.effective_to')
                                ->orWhereColumn('org.effective_to', '>=', 'a.effective_from'))
                            ->whereNotExists(function ($conflict): void {
                                $conflict->selectRaw('1')->from('hr_employee_organization_assignments as other_org')
                                    ->whereColumn('other_org.employee_id', 'org.employee_id')
                                    ->whereColumn('other_org.company_id', 'org.company_id')
                                    ->whereColumn('other_org.branch_id', '!=', 'org.branch_id')
                                    ->where(fn ($dates) => $dates->whereNull('org.effective_to')
                                        ->orWhereColumn('other_org.effective_from', '<=', 'org.effective_to'))
                                    ->where(fn ($dates) => $dates->whereNull('other_org.effective_to')
                                        ->orWhereColumn('other_org.effective_to', '>=', 'org.effective_from'))
                                    ->where(fn ($dates) => $dates->whereNull('a.effective_to')
                                        ->orWhereColumn('other_org.effective_from', '<=', 'a.effective_to'))
                                    ->where(fn ($dates) => $dates->whereNull('other_org.effective_to')
                                        ->orWhereColumn('other_org.effective_to', '>=', 'a.effective_from'));
                            });
                    })->orWhere(function ($legacy) use ($branchIds): void {
                        $legacy->whereNull('org.employee_id')->whereIn('e.branch_id', $branchIds);
                    });
                });
                $assignmentColumns[] = DB::raw('CASE WHEN org.effective_from > a.effective_from THEN org.effective_from ELSE a.effective_from END as visible_from');
                $assignmentColumns[] = DB::raw('CASE WHEN org.effective_to IS NOT NULL AND (a.effective_to IS NULL OR org.effective_to < a.effective_to) THEN org.effective_to ELSE a.effective_to END as visible_to');
            }
            $assignments = $assignmentQuery->orderByDesc('a.effective_from')
                ->paginate(30, $assignmentColumns, 'assignments_page')->withQueryString();
        }

        return view('modules.hr.work-calendars.index', [
            'calendars' => $calendars,
            'selected' => $selected,
            'days' => $selected === null ? collect() : DB::table('hr_work_calendar_days')
                ->where('calendar_id', $selected->id)->orderByDesc('work_date')
                ->paginate(45, ['*'], 'days_page')->withQueryString(),
            'assignments' => $assignments,
            'branches' => $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
                ->where('branches.status', 'active')->orderBy('branches.name')->get(['branches.id', 'branches.doc_num', 'branches.name']),
            'canCreateCompanyCalendar' => $this->scope->hasUnrestrictedBranchAccess($request->user()),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.work-calendars.index'),
        ]);
    }

    public function selectableEmployees(Request $request, int $calendar, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        try {
            $selected = $this->calendars->calendar($companyId, $calendar, $request->user(), true);
        } catch (DomainException) {
            abort(404);
        }

        $query = HrEmployee::query()
            ->select(['id', 'doc_num', 'full_name', 'doc_number'])
            ->where('company_id', $companyId)
            ->where('status', 'active');
        if ($selected->branch_id !== null) {
            $query->where(function ($scope) use ($selected): void {
                $scope->whereExists(fn ($history) => $history->selectRaw('1')
                    ->from('hr_employee_organization_assignments as org')
                    ->whereColumn('org.employee_id', 'hr_employees.id')
                    ->whereColumn('org.company_id', 'hr_employees.company_id')
                    ->where('org.branch_id', $selected->branch_id))
                    ->orWhere(fn ($legacy) => $legacy->where('branch_id', $selected->branch_id)
                        ->whereNotExists(fn ($history) => $history->selectRaw('1')
                            ->from('hr_employee_organization_assignments as org')
                            ->whereColumn('org.employee_id', 'hr_employees.id')));
            });
        }
        $terms = $search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, [
                'text' => ['hr_employees.doc_num', 'hr_employees.full_name'],
            ]);
        }

        return response()->json($select2->paginated(
            $query->orderBy('full_name')->orderBy('doc_number'),
            $request,
            fn (HrEmployee $employee): array => [
                'id' => (string) $employee->getKey(),
                'text' => trim($employee->full_name.' / '.$employee->doc_num),
            ],
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'branch_doc_num' => ['nullable', 'string', Rule::exists('branches', 'doc_num')
                ->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')],
        ]);
        try {
            $calendar = DB::transaction(function () use ($request, $companyId, $data): object {
                $calendar = $this->calendars->create($companyId, $data, $request->user());
                $this->audit->logStrict($request, 'hr.work_calendars.create', $companyId, [
                    'calendar_id' => $calendar->id, 'code' => $calendar->code,
                    'branch_id' => $calendar->branch_id,
                ], null, 'work-calendar:'.$calendar->id.':created');

                return $calendar;
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['calendar' => $exception->getMessage()]);
        }

        return to_route('admin.hr.work-calendars.index', ['calendar_id' => $calendar->id])
            ->with('success', __('hr_work_calendars.messages.created'));
    }

    public function storeDay(Request $request, int $calendar): RedirectResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'day_type' => ['required', Rule::in(['working', 'holiday_paid', 'holiday_unpaid', 'holiday', 'weekend', 'non_working', 'off'])],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            DB::transaction(function () use ($request, $companyId, $calendar, $data): void {
                $day = $this->calendars->addDay($companyId, $calendar, $data, $request->user());
                $this->audit->logStrict($request, 'hr.work_calendars.day.create', $companyId, [
                    'calendar_id' => $calendar, 'day_id' => $day->id,
                    'work_date' => $day->work_date, 'day_type' => $day->day_type,
                ], null, 'work-calendar-day:'.$day->id.':created');
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['day' => $exception->getMessage()]);
        }

        return to_route('admin.hr.work-calendars.index', ['calendar_id' => $calendar])
            ->with('success', __('hr_work_calendars.messages.day_created'));
    }

    public function updateDay(Request $request, int $calendar, int $day): RedirectResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $data = $request->validate([
            'expected_day_type' => ['required', Rule::in(['working', 'holiday_paid', 'holiday_unpaid', 'holiday', 'weekend', 'non_working', 'off'])],
            'day_type' => ['required', Rule::in(['working', 'holiday_paid', 'holiday_unpaid', 'holiday', 'weekend', 'non_working', 'off'])],
            'label' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        try {
            DB::transaction(function () use ($request, $companyId, $calendar, $day, $data): void {
                $change = $this->calendars->changeDay($companyId, $calendar, $day, $data, $request->user());
                $this->audit->logStrict($request, 'hr.work_calendars.day.change', $companyId, [
                    'calendar_id' => $calendar, 'day_id' => $day,
                    'work_date' => $change['before']->work_date,
                    'old_day_type' => $change['before']->day_type,
                    'new_day_type' => $change['after']->day_type,
                    'old_label' => $change['before']->label,
                    'new_label' => $change['after']->label,
                    'reason' => trim($data['reason']),
                ], null, 'work-calendar-day:'.$day.':change:'.Str::uuid());
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['day' => $exception->getMessage()]);
        }

        return to_route('admin.hr.work-calendars.index', ['calendar_id' => $calendar])
            ->with('success', __('hr_work_calendars.messages.day_changed'));
    }

    public function fillRange(Request $request, int $calendar): RedirectResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $data = $request->validate([
            'range_from' => ['required', 'date_format:Y-m-d'],
            'range_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:range_from'],
            'working_weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'working_weekdays.*' => ['required', 'integer', 'distinct', 'min:0', 'max:6'],
        ]);
        try {
            $created = DB::transaction(function () use ($request, $companyId, $calendar, $data): int {
                $workingWeekdays = array_map('intval', $data['working_weekdays']);
                $created = $this->calendars->fillRange($companyId, $calendar, $data['range_from'], $data['range_to'], $workingWeekdays, $request->user());
                $this->audit->logStrict($request, 'hr.work_calendars.range.fill', $companyId, [
                    'calendar_id' => $calendar,
                    'from' => $data['range_from'],
                    'to' => $data['range_to'],
                    'working_weekdays' => $workingWeekdays,
                    'created_days' => $created,
                ], null, 'work-calendar:'.$calendar.':fill:'.$data['range_from'].':'.$data['range_to'].':'.implode('-', $workingWeekdays));

                return $created;
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['range' => $exception->getMessage()]);
        }

        return to_route('admin.hr.work-calendars.index', ['calendar_id' => $calendar])
            ->with('success', __('hr_work_calendars.messages.range_filled', ['count' => $created]));
    }

    public function storeAssignment(Request $request, int $calendar): RedirectResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')
                ->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);
        try {
            DB::transaction(function () use ($request, $companyId, $calendar, $data): void {
                $assignment = $this->calendars->assign($companyId, $calendar, $data, $request->user());
                $employee = HrEmployee::query()->findOrFail($assignment->employee_id);
                $this->audit->logStrict($request, 'hr.work_calendars.assign', $companyId, [
                    'assignment_id' => $assignment->id, 'calendar_id' => $calendar,
                    'employee_doc_num' => $employee->doc_num,
                    'effective_from' => $assignment->effective_from,
                    'effective_to' => $assignment->effective_to,
                ], $employee, 'work-calendar-assignment:'.$assignment->id.':created');
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['assignment' => $exception->getMessage()]);
        }

        return to_route('admin.hr.work-calendars.index', ['calendar_id' => $calendar])
            ->with('success', __('hr_work_calendars.messages.assignment_created'));
    }

    public function updateAssignment(Request $request, int $calendar, int $assignment): RedirectResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $data = $request->validate([
            'expected_effective_from' => ['required', 'date_format:Y-m-d'],
            'expected_effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:expected_effective_from'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        try {
            DB::transaction(function () use ($request, $companyId, $calendar, $assignment, $data): void {
                $change = $this->calendars->changeAssignment($companyId, $calendar, $assignment, $data, $request->user());
                $this->audit->logStrict($request, 'hr.work_calendars.assignment.change', $companyId, [
                    'calendar_id' => $calendar,
                    'assignment_id' => $assignment,
                    'employee_id' => $change['before']->employee_id,
                    'old_effective_from' => $change['before']->effective_from,
                    'old_effective_to' => $change['before']->effective_to,
                    'new_effective_from' => $change['after']->effective_from,
                    'new_effective_to' => $change['after']->effective_to,
                    'reason' => trim($data['reason']),
                ], null, 'work-calendar-assignment:'.$assignment.':change:'.Str::uuid());
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['assignment' => $exception->getMessage()]);
        }

        return to_route('admin.hr.work-calendars.index', ['calendar_id' => $calendar])
            ->with('success', __('hr_work_calendars.messages.assignment_changed'));
    }
}
