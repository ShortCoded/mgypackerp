<?php

namespace Modules\HR\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingScopeAccessService;

class HrWorkforceReportService
{
    public function __construct(private readonly OperatingScopeAccessService $scope) {}

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, object> */
    public function employees(int $companyId, User $user, array $filters): LengthAwarePaginator
    {
        return $this->employeeQuery($companyId, $user, $filters)
            ->orderBy('employee.full_name')
            ->orderBy('employee.id')
            ->paginate(30)
            ->withQueryString();
    }

    /** @param array<string, mixed> $filters @return Collection<int, object> */
    public function employeeRows(int $companyId, User $user, array $filters): Collection
    {
        return $this->employeeQuery($companyId, $user, $filters)
            ->orderBy('employee.full_name')
            ->orderBy('employee.id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator<int, object>, totals: array{request_count: int, leave_days: float, paid_leave_days: float, unpaid_leave_days: float}}
     */
    public function leaveRequests(int $companyId, User $user, array $filters): array
    {
        $query = $this->leaveRequestQuery($companyId, $user, $filters);
        $totals = $this->leaveRequestTotals(clone $query);
        $leaveTypes = $this->leaveTypeMap();
        $rows = $query
            ->orderByDesc('request.submitted_at')
            ->orderByDesc('request.id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (object $row): object => $this->decorateLeaveRequest($row, $leaveTypes));

        return compact('rows', 'totals');
    }

    /** @param array<string, mixed> $filters @return Collection<int, object> */
    public function leaveRequestRows(int $companyId, User $user, array $filters): Collection
    {
        $leaveTypes = $this->leaveTypeMap();

        return $this->leaveRequestQuery($companyId, $user, $filters)
            ->orderByDesc('request.submitted_at')
            ->orderByDesc('request.id')
            ->get()
            ->map(fn (object $row): object => $this->decorateLeaveRequest($row, $leaveTypes));
    }

    /** @return Collection<int, object> */
    public function approvers(int $companyId, User $user): Collection
    {
        $query = DB::table('hr_employee_service_requests as request')
            ->join('users as approver', 'approver.id', '=', 'request.resolved_by')
            ->where('request.company_id', $companyId)
            ->whereNull('request.deleted_at')
            ->select(['approver.id', 'approver.name'])
            ->distinct()
            ->orderBy('approver.name');
        $this->applyBranchScope($query, $companyId, $user, 'request.branch_id');

        return $query->get();
    }

    /** @param array<string, mixed> $filters */
    private function employeeQuery(int $companyId, User $user, array $filters): Builder
    {
        $query = DB::table('hr_employees as employee')
            ->leftJoin('branches as branch', 'branch.id', '=', 'employee.branch_id')
            ->leftJoin('hr_departments as department', 'department.id', '=', 'employee.department_id')
            ->leftJoin('hr_sections as section', 'section.id', '=', 'employee.section_id')
            ->leftJoin('hr_jobs as job', 'job.id', '=', 'employee.job_id')
            ->leftJoin('hr_employment_types as employment_type', 'employment_type.id', '=', 'employee.employment_type_id')
            ->where('employee.company_id', $companyId)
            ->whereNull('employee.deleted_at')
            ->select([
                'employee.id', 'employee.doc_num', 'employee.employee_code', 'employee.full_name',
                'employee.hire_date', 'employee.gender', 'employee.status',
                'branch.doc_num as branch_doc_num', 'branch.name as branch_name',
                'department.doc_num as department_doc_num', 'department.name as department_name',
                'section.doc_num as section_doc_num', 'section.name as section_name',
                'job.doc_num as job_doc_num', 'job.name as job_name',
                'employment_type.doc_num as employment_type_doc_num', 'employment_type.name as employment_type_name',
            ]);
        $this->applyBranchScope($query, $companyId, $user, 'employee.branch_id');

        return $query
            ->when(isset($filters['branch_doc_num']), fn (Builder $query): Builder => $query->where('branch.doc_num', $filters['branch_doc_num']))
            ->when(isset($filters['department_doc_num']), fn (Builder $query): Builder => $query->where('department.doc_num', $filters['department_doc_num']))
            ->when(isset($filters['section_doc_num']), fn (Builder $query): Builder => $query->where('section.doc_num', $filters['section_doc_num']))
            ->when(isset($filters['job_doc_num']), fn (Builder $query): Builder => $query->where('job.doc_num', $filters['job_doc_num']))
            ->when(isset($filters['employment_type_doc_num']), fn (Builder $query): Builder => $query->where('employment_type.doc_num', $filters['employment_type_doc_num']))
            ->when(isset($filters['status']), fn (Builder $query): Builder => $query->where('employee.status', $filters['status']))
            ->when(isset($filters['gender']), fn (Builder $query): Builder => $query->where('employee.gender', $filters['gender']))
            ->when(isset($filters['hire_from']), fn (Builder $query): Builder => $query->whereDate('employee.hire_date', '>=', $filters['hire_from']))
            ->when(isset($filters['hire_to']), fn (Builder $query): Builder => $query->whereDate('employee.hire_date', '<=', $filters['hire_to']));
    }

    /** @param array<string, mixed> $filters */
    private function leaveRequestQuery(int $companyId, User $user, array $filters): Builder
    {
        $query = DB::table('hr_employee_service_requests as request')
            ->join('hr_employees as employee', 'employee.id', '=', 'request.employee_id')
            ->leftJoin('branches as branch', 'branch.id', '=', 'request.branch_id')
            ->leftJoin('hr_departments as department', 'department.id', '=', 'employee.department_id')
            ->leftJoin('users as approver', 'approver.id', '=', 'request.resolved_by')
            ->where('request.company_id', $companyId)
            ->where('employee.company_id', $companyId)
            ->whereNull('request.deleted_at')
            ->select([
                'request.id', 'request.public_uuid', 'request.request_type', 'request.requested_from',
                'request.requested_to', 'request.requested_minutes', 'request.payload', 'request.status',
                'request.submitted_at', 'request.resolved_at', 'request.resolved_by',
                'employee.doc_num as employee_doc_num', 'employee.full_name as employee_name',
                'branch.doc_num as branch_doc_num', 'branch.name as branch_name',
                'department.doc_num as department_doc_num', 'department.name as department_name',
                'approver.name as approver_name',
            ]);
        $this->applyBranchScope($query, $companyId, $user, 'request.branch_id');

        if (isset($filters['leave_type_code'])) {
            $leaveTypeId = DB::table('hr_leave_types')
                ->where('code', $filters['leave_type_code'])
                ->value('id');
            $query->where('request.payload->leave_type_id', $leaveTypeId ?? 0);
        }

        return $query
            ->when(isset($filters['employee']), function (Builder $query) use ($filters): Builder {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['employee']).'%';

                return $query->where(fn (Builder $search): Builder => $search
                    ->where('employee.doc_num', 'like', $term)
                    ->orWhere('employee.employee_code', 'like', $term)
                    ->orWhere('employee.full_name', 'like', $term));
            })
            ->when(isset($filters['branch_doc_num']), fn (Builder $query): Builder => $query->where('branch.doc_num', $filters['branch_doc_num']))
            ->when(isset($filters['department_doc_num']), fn (Builder $query): Builder => $query->where('department.doc_num', $filters['department_doc_num']))
            ->when(isset($filters['request_type']), fn (Builder $query): Builder => $query->where('request.request_type', $filters['request_type']))
            ->when(isset($filters['status']), fn (Builder $query): Builder => $query->where('request.status', $filters['status']))
            ->when(isset($filters['date_from']), fn (Builder $query): Builder => $query->whereDate('request.submitted_at', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $query): Builder => $query->whereDate('request.submitted_at', '<=', $filters['date_to']))
            ->when(isset($filters['approver_id']), fn (Builder $query): Builder => $query->where('request.resolved_by', $filters['approver_id']));
    }

    /** @return array<int, object> */
    private function leaveTypeMap(): array
    {
        return DB::table('hr_leave_types')
            ->get(['id', 'code', 'name', 'metadata'])
            ->keyBy('id')
            ->all();
    }

    /** @param array<int, object> $leaveTypes */
    private function decorateLeaveRequest(object $row, array $leaveTypes): object
    {
        $payload = $this->decodePayload($row->payload);
        $leaveType = $leaveTypes[(int) ($payload['leave_type_id'] ?? 0)] ?? null;
        $metadata = $this->decodePayload($leaveType?->metadata ?? null);
        $leaveDays = $row->request_type === 'leave' ? (float) ($payload['leave_days'] ?? 0) : 0.0;
        $payrollTreatment = $this->leavePaymentStatus($payload, $metadata);
        $isPaid = $payrollTreatment === 'paid';
        $requiresBalance = (bool) ($payload['requires_balance'] ?? $metadata['requires_balance'] ?? false);

        $row->leave_type_code = $payload['leave_type'] ?? $leaveType?->code;
        $row->leave_type_name = $payload['leave_type_name'] ?? $leaveType?->name;
        $row->leave_days = $leaveDays;
        $row->leave_is_paid = $isPaid;
        $row->balance_impact = $row->status === 'approved' && $requiresBalance ? -$leaveDays : 0.0;

        return $row;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $legacyMetadata */
    private function leavePaymentStatus(array $payload, array $legacyMetadata): ?string
    {
        if (array_key_exists('payment_status', $payload)) {
            return in_array($payload['payment_status'], ['paid', 'unpaid'], true)
                ? $payload['payment_status']
                : null;
        }

        $canonicalLeaveRequestId = (int) ($payload['canonical_leave_request_id'] ?? 0);
        if ($canonicalLeaveRequestId > 0) {
            $paymentStatus = DB::table('hr_leave_requests')
                ->where('id', $canonicalLeaveRequestId)
                ->value('payment_status');

            return in_array($paymentStatus, ['paid', 'unpaid'], true) ? $paymentStatus : null;
        }

        $legacyPaymentStatus = $legacyMetadata['payment_status']
            ?? $legacyMetadata['payroll_treatment']
            ?? null;
        if (in_array($legacyPaymentStatus, ['paid', 'unpaid'], true)) {
            return $legacyPaymentStatus;
        }

        return (bool) ($legacyMetadata['is_paid'] ?? $legacyMetadata['paid'] ?? true) ? 'paid' : 'unpaid';
    }

    /** @return array{request_count: int, leave_days: float, paid_leave_days: float, unpaid_leave_days: float} */
    private function leaveRequestTotals(Builder $query): array
    {
        $totals = ['request_count' => 0, 'leave_days' => 0.0, 'paid_leave_days' => 0.0, 'unpaid_leave_days' => 0.0];
        $leaveTypes = $this->leaveTypeMap();

        foreach ($query->orderBy('request.id')->lazyById(200, 'request.id', 'id') as $row) {
            $totals['request_count']++;
            $decorated = $this->decorateLeaveRequest($row, $leaveTypes);
            if ($decorated->request_type !== 'leave' || $decorated->status !== 'approved') {
                continue;
            }

            $totals['leave_days'] += $decorated->leave_days;
            $key = $decorated->leave_is_paid ? 'paid_leave_days' : 'unpaid_leave_days';
            $totals[$key] += $decorated->leave_days;
        }

        return $totals;
    }

    private function applyBranchScope(Builder $query, int $companyId, User $user, string $column): void
    {
        if ($this->scope->hasUnrestrictedBranchAccess($user)) {
            return;
        }

        $company = Company::query()->findOrFail($companyId);
        $branchIds = $this->scope->allowedBranchQuery($user, [(string) $company->doc_num])
            ->pluck('branches.id')
            ->all();
        $query->whereIn($column, $branchIds !== [] ? $branchIds : [0]);
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
