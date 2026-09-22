<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Http\Requests\Attendance\StoreAttendanceImportRequest;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Services\HrAttendanceImportService;

class HrAttendanceImportController extends Controller
{
    public function __construct(
        private readonly HrAttendanceImportService $imports,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request): View
    {
        $selectedDevice = null;
        $selectedDocNum = trim((string) old('biometric_device_doc_num', $request->query('device')));

        if ($selectedDocNum !== '') {
            $selectedDevice = $this->deviceForRequest($request, $selectedDocNum);
        }

        return view('modules.hr.attendance.import', [
            'selectedDevice' => $selectedDevice,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.employee-attendance.index', [
                ['label' => __('hr_attendance.import.title')],
            ]),
        ]);
    }

    public function store(StoreAttendanceImportRequest $request): RedirectResponse
    {
        $device = $this->deviceForRequest($request, (string) $request->validated('biometric_device_doc_num'));
        abort_unless($device instanceof HrBiometricDevice, 404);
        $result = $this->imports->import($device, $request->file('workbook'), $request);

        return redirect()
            ->route('admin.hr.employee-attendance.import.index', ['device' => $device->doc_num])
            ->with('success', __('hr_attendance.import.messages.imported', $result));
    }

    private function deviceForRequest(Request $request, string $docNum): ?HrBiometricDevice
    {
        $company = $this->companies->currentCompany($request);

        if ($company === null) {
            return null;
        }

        $allowedBranchIds = $this->scope->hasUnrestrictedBranchAccess($request->user())
            ? null
            : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();

        return HrBiometricDevice::query()
            ->where('company_id', $company->getKey())
            ->where('status', 'active')
            ->when($allowedBranchIds !== null, fn ($query) => $query->whereIn('branch_id', $allowedBranchIds !== [] ? $allowedBranchIds : [0]))
            ->where('doc_num', $docNum)
            ->first();
    }
}
