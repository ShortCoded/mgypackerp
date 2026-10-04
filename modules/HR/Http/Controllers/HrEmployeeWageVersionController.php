<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\HrEmployeeWageVersionService;
use Modules\HR\Services\HrLifecycleAuditLogger;

final class HrEmployeeWageVersionController extends Controller
{
    public function __construct(
        private readonly HrEmployeeWageVersionService $versions,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly HrLifecycleAuditLogger $audit,
        private readonly NumericFormatService $numbers,
    ) {}

    public function index(Request $request, HrEmployee $employee): View
    {
        $this->scopedCompanyId($request, $employee);
        $versions = DB::table('hr_employee_salary_assignments')
            ->where('employee_id', $employee->getKey())
            ->whereNull('deleted_at')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(30);

        return view('modules.hr.employees.wage-versions', compact('employee', 'versions'));
    }

    public function store(Request $request, HrEmployee $employee): RedirectResponse
    {
        $companyId = $this->scopedCompanyId($request, $employee);
        $data = $this->validated($request, withDate: true);

        try {
            DB::transaction(function () use ($request, $companyId, $employee, $data): void {
                $version = $this->versions->record(
                    $companyId, (int) $employee->getKey(), $data['effective_from'],
                    $data['pay_basis'], $data['rate'], $data['reason'], $request->user(),
                );
                $this->auditVersion($request, $employee, $companyId, $version, 'recorded');
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['rate_version' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_wage_versions.messages.saved'));
    }

    public function verifyLegacy(Request $request, HrEmployee $employee, int $assignment): RedirectResponse
    {
        $companyId = $this->scopedCompanyId($request, $employee);
        $data = $this->validated($request, withDate: false);

        try {
            DB::transaction(function () use ($request, $companyId, $employee, $assignment, $data): void {
                $version = $this->versions->verifyLegacy(
                    $companyId, (int) $employee->getKey(), $assignment,
                    $data['pay_basis'], $data['rate'], $data['reason'], $request->user(),
                );
                $this->auditVersion($request, $employee, $companyId, $version, 'verified');
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['rate_version' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_wage_versions.messages.saved'));
    }

    /** @return array<string, string> */
    private function validated(Request $request, bool $withDate): array
    {
        $request->merge(['rate' => $this->numbers->normalizeForValidation($request->input('rate'))]);
        $scale = $request->input('pay_basis') === 'monthly_salary' ? 2 : 4;
        $rules = [
            'pay_basis' => ['required', Rule::in(HrEmployeeWageVersionService::PayBases)],
            'rate' => ['required', 'numeric', 'gt:0', 'regex:/^(?:\d{1,11}|\d{0,11}\.\d{1,'.$scale.'})$/D'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
        if ($withDate) {
            $rules['effective_from'] = ['required', 'date_format:Y-m-d'];
        }

        return $request->validate($rules);
    }

    private function scopedCompanyId(Request $request, HrEmployee $employee): int
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null && (int) $employee->company_id === (int) $company->getKey(), 404);
        abort_unless($employee->branch_id !== null
            && $this->scope->canAccessBranch($request->user(), $employee->branch, $company), 403);

        return (int) $company->getKey();
    }

    private function auditVersion(Request $request, HrEmployee $employee, int $companyId, object $version, string $action): void
    {
        $this->audit->logStrict(
            $request,
            'hr.employee_wage_version.'.$action,
            $companyId,
            [
                'assignment_id' => $version->id,
                'employee_doc_num' => $employee->doc_num,
                'pay_basis' => $version->pay_basis,
                'effective_from' => $version->effective_from,
                'effective_to' => $version->effective_to,
                'reason' => $version->reason,
            ],
            $employee,
            'employee-wage-version:'.$version->id.':'.$action,
            redactSensitiveProperties: true,
        );
    }
}
