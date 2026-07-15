<?php

namespace Modules\HR\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Modules\HR\Models\HrEmployee;
use Yajra\DataTables\Facades\DataTables;

class HrEmployeesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $trashFilter = $this->trashFilter($request);
        $canView = (bool) $request->user()?->can('hr.employees.view');
        $query = $this->baseQuery($trashFilter)
            ->leftJoin('companies', 'companies.id', '=', 'hr_employees.company_id')
            ->leftJoin('branches', 'branches.id', '=', 'hr_employees.branch_id')
            ->leftJoin('hr_departments', 'hr_departments.id', '=', 'hr_employees.department_id')
            ->leftJoin('hr_sections', 'hr_sections.id', '=', 'hr_employees.section_id')
            ->leftJoin('hr_jobs', 'hr_jobs.id', '=', 'hr_employees.job_id')
            ->leftJoin('hr_employment_types', 'hr_employment_types.id', '=', 'hr_employees.employment_type_id')
            ->leftJoin('currencies as payroll_currencies', 'payroll_currencies.id', '=', 'hr_employees.payroll_currency_id')
            ->leftJoin('archive_files as photo_files', 'photo_files.id', '=', 'hr_employees.photo_archive_file_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'hr_employees.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'hr_employees.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'hr_employees.deleted_by')
            ->select([
                'hr_employees.doc_number',
                'hr_employees.doc_num',
                'hr_employees.full_name',
                'hr_employees.national_id',
                'hr_employees.person_type',
                'hr_employees.department',
                'hr_employees.job_title',
                'hr_employees.status',
                'hr_employees.pay_basis',
                'hr_employees.basic_salary',
                'hr_employees.weekly_wage',
                'hr_employees.daily_wage',
                'hr_employees.hourly_wage',
                'hr_employees.shift_wage',
                'hr_employees.piece_rate',
                'hr_employees.mobile',
                'hr_employees.phone',
                'hr_employees.email',
                'hr_employees.work_email',
                'hr_employees.personal_email',
                'hr_employees.end_date',
                'hr_employees.created_at',
                'hr_employees.updated_at',
                'hr_employees.deleted_at',
                'companies.name as company_name',
                'companies.doc_num as company_doc_num',
                'branches.name as branch_name',
                'branches.doc_num as branch_doc_num',
                'hr_departments.name as department_name_lookup',
                'hr_departments.doc_num as department_doc_num',
                'hr_sections.name as section_name',
                'hr_sections.doc_num as section_doc_num',
                'hr_jobs.name as job_name',
                'hr_jobs.doc_num as job_doc_num',
                'hr_employment_types.name as employment_type_name',
                'hr_employment_types.doc_num as employment_type_doc_num',
                'payroll_currencies.code as payroll_currency_code',
                'payroll_currencies.name as payroll_currency_name',
                'payroll_currencies.doc_num as payroll_currency_doc_num',
                'photo_files.doc_num as photo_file_doc_num',
                'photo_files.mime_type as photo_file_mime_type',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
                'deleted_users.name as deleted_by_name',
            ])
            ->withCount([
                'biometricMappings as active_biometric_mappings_count' => fn (Builder $query) => $query->where('is_active', true),
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $this->searchColumns());
            })
            ->addColumn('checkbox', fn (HrEmployee $employee): string => view('modules.hr.employees.partials.checkbox', compact('employee'))->render())
            ->editColumn('doc_num', fn (HrEmployee $employee): string => $this->docNumColumn($employee, $canView))
            ->addColumn('avatar', fn (HrEmployee $employee): string => $this->avatarColumn($employee))
            ->editColumn('full_name', fn (HrEmployee $employee): string => $this->ellipsisText($employee->full_name))
            ->editColumn('national_id', fn (HrEmployee $employee): string => $this->ellipsisText($employee->national_id))
            ->editColumn('person_type', fn (HrEmployee $employee): string => $this->typeBadge((string) $employee->person_type))
            ->addColumn('branch', fn (HrEmployee $employee): string => $this->ellipsisText(trim(implode(' / ', array_filter([$employee->branch_name, $employee->branch_doc_num])))))
            ->addColumn('department', fn (HrEmployee $employee): string => $this->ellipsisText($employee->department_name_lookup ?: $employee->department))
            ->addColumn('job_section', fn (HrEmployee $employee): string => $this->ellipsisText(trim(implode(' / ', array_filter([$employee->section_name, $employee->section_doc_num])))))
            ->addColumn('job', fn (HrEmployee $employee): string => $this->ellipsisText($employee->job_name ?: $employee->job_title))
            ->addColumn('job_type', fn (HrEmployee $employee): string => $this->ellipsisText(trim(implode(' / ', array_filter([$employee->employment_type_name, $employee->employment_type_doc_num])))))
            ->editColumn('pay_basis', fn (HrEmployee $employee): string => $this->payBasisBadge((string) $employee->pay_basis))
            ->addColumn('pay_amount', fn (HrEmployee $employee): string => $this->plainText($this->formatPayAmount($employee)))
            ->addColumn('payroll_currency', fn (HrEmployee $employee): string => $this->ellipsisText(trim(implode(' / ', array_filter([$employee->payroll_currency_code, $employee->payroll_currency_name])))))
            ->editColumn('status', fn (HrEmployee $employee): string => $this->statusBadge((string) $employee->status))
            ->addColumn('biometric_indicator', fn (HrEmployee $employee): string => $this->indicatorBadge((int) $employee->active_biometric_mappings_count, 'fingerprint'))
            ->editColumn('end_date', fn (HrEmployee $employee): string => $this->plainText($employee->end_date?->format($dateFormat) ?? ''))
            ->addColumn('created_by', fn (HrEmployee $employee): string => $this->ellipsisText($employee->created_by_name))
            ->editColumn('created_at', fn (HrEmployee $employee): string => $this->plainText($employee->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (HrEmployee $employee): string => $this->ellipsisText($employee->updated_by_name))
            ->editColumn('updated_at', fn (HrEmployee $employee): string => $this->plainText($employee->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('deleted_by', fn (HrEmployee $employee): string => $this->ellipsisText($employee->deleted_by_name))
            ->editColumn('deleted_at', fn (HrEmployee $employee): string => $this->plainText($employee->deleted_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (HrEmployee $employee): string => view('modules.hr.employees.partials.actions', compact('employee'))->render())
            ->orderColumn('doc_num', 'hr_employees.doc_number $1')
            ->orderColumn('full_name', 'hr_employees.full_name $1')
            ->orderColumn('national_id', 'hr_employees.national_id $1')
            ->orderColumn('person_type', 'hr_employees.person_type $1')
            ->orderColumn('branch', 'branches.name $1')
            ->orderColumn('department', 'hr_departments.name $1')
            ->orderColumn('job_section', 'hr_sections.name $1')
            ->orderColumn('job', 'hr_jobs.name $1')
            ->orderColumn('job_type', 'hr_employment_types.name $1')
            ->orderColumn('pay_basis', 'hr_employees.pay_basis $1')
            ->orderColumn('pay_amount', 'hr_employees.basic_salary $1')
            ->orderColumn('payroll_currency', 'payroll_currencies.code $1')
            ->orderColumn('status', 'hr_employees.status $1')
            ->orderColumn('end_date', 'hr_employees.end_date $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'hr_employees.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'hr_employees.updated_at $1')
            ->orderColumn('deleted_by', 'deleted_users.name $1')
            ->orderColumn('deleted_at', 'hr_employees.deleted_at $1')
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'avatar', 'full_name', 'national_id', 'person_type', 'branch', 'department', 'job_section', 'job', 'job_type', 'pay_basis', 'payroll_currency', 'status', 'biometric_indicator', 'end_date', 'created_by', 'updated_by', 'deleted_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<HrEmployee>
     */
    private function baseQuery(string $trashFilter): Builder
    {
        $query = match ($trashFilter) {
            'trashed' => HrEmployee::onlyTrashed(),
            'all' => HrEmployee::withTrashed(),
            default => HrEmployee::query(),
        };

        return match ($trashFilter) {
            'active' => $query->where('hr_employees.status', 'active'),
            'inactive' => $query->where(function (Builder $query): void {
                $query->where('hr_employees.status', '!=', 'active')
                    ->orWhereNull('hr_employees.status');
            }),
            default => $query,
        };
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('hr.employees.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'inactive', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(HrEmployee $employee, bool $canView): string
    {
        if (! $canView) {
            return sprintf('<span class="fw-semibold text-700">%s</span>', e((string) $employee->doc_num));
        }

        return sprintf(
            '<a class="fw-semibold" href="%s">%s</a>',
            e(route('admin.hr.employees.show', $employee->doc_num)),
            e((string) $employee->doc_num),
        );
    }

    private function statusBadge(string $status): string
    {
        $color = match ($status) {
            'active' => 'success',
            'suspended', 'stopped' => 'warning',
            'terminated', 'left' => 'danger',
            default => 'secondary',
        };

        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e(__("hr.employees.statuses.{$status}")).'</span>';
    }

    private function typeBadge(string $type): string
    {
        return '<span class="badge rounded-pill badge-subtle-info">'.e(__("hr.employees.person_types.{$type}")).'</span>';
    }

    private function payBasisBadge(string $basis): string
    {
        return $basis === ''
            ? $this->plainText('')
            : '<span class="badge rounded-pill badge-subtle-primary">'.e(__("hr.employees.pay_basis.{$basis}")).'</span>';
    }

    private function avatarColumn(HrEmployee $employee): string
    {
        if ($employee->photo_file_doc_num && str_starts_with((string) $employee->photo_file_mime_type, 'image/')) {
            return sprintf(
                '<img class="rounded-circle border" src="%s" alt="%s" width="32" height="32" style="object-fit:cover;">',
                e(route('admin.file-manager.files.preview', $employee->photo_file_doc_num)),
                e((string) $employee->full_name),
            );
        }

        $initial = mb_substr(trim((string) $employee->full_name), 0, 1) ?: '?';

        return '<span class="avatar avatar-2xl"><span class="avatar-name rounded-circle bg-200 text-700"><span>'.e($initial).'</span></span></span>';
    }

    private function formatPayAmount(HrEmployee $employee): string
    {
        $value = match ((string) $employee->pay_basis) {
            'monthly_salary' => $employee->basic_salary,
            'weekly_wage' => $employee->weekly_wage,
            'daily_wage' => $employee->daily_wage,
            'hourly_wage' => $employee->hourly_wage,
            'shift_wage' => $employee->shift_wage,
            'piece_rate' => $employee->piece_rate,
            default => null,
        };

        return $value === null || $value === '' ? '' : number_format((float) $value, 2);
    }

    private function indicatorBadge(int $count, string $icon): string
    {
        $color = $count > 0 ? 'success' : 'secondary';

        return '<span class="badge rounded-pill badge-subtle-'.$color.'"><span class="fas fa-'.$icon.' me-1"></span>'.e((string) $count).'</span>';
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'hr_employees.doc_num',
                'hr_employees.full_name',
                'hr_employees.national_id',
                'hr_employees.person_type',
                'hr_employees.department',
                'hr_employees.job_title',
                'hr_employees.status',
                'hr_employees.pay_basis',
                'hr_employees.mobile',
                'hr_employees.phone',
                'hr_employees.email',
                'hr_employees.work_email',
                'hr_employees.personal_email',
                'companies.name',
                'companies.doc_num',
                'branches.name',
                'branches.doc_num',
                'hr_departments.name',
                'hr_departments.doc_num',
                'hr_sections.name',
                'hr_sections.doc_num',
                'hr_jobs.name',
                'hr_jobs.doc_num',
                'hr_employment_types.name',
                'hr_employment_types.doc_num',
                'payroll_currencies.code',
                'payroll_currencies.name',
                'payroll_currencies.doc_num',
                'created_users.name',
                'updated_users.name',
                'deleted_users.name',
            ],
            'dates' => [
                'hr_employees.created_at',
                'hr_employees.updated_at',
                'hr_employees.deleted_at',
                'hr_employees.hire_date',
                'hr_employees.end_date',
            ],
            'date_text' => [
                'hr_employees.created_at',
                'hr_employees.updated_at',
                'hr_employees.deleted_at',
                'hr_employees.hire_date',
                'hr_employees.end_date',
            ],
        ];
    }
}
