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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function template(Request $request): StreamedResponse
    {
        abort_unless($this->companies->currentCompany($request) !== null, 409);

        return response()->streamDownload(function (): void {
            $workbook = new Spreadsheet;

            try {
                $punches = $workbook->getActiveSheet();
                $punches->setTitle('Punches');
                $punches->fromArray([['biometric_code', 'punched_at', 'punch_type']]);
                $punches->getStyle('A1:C1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $punches->getStyle('A1:C1')->getFill()->setFillType('solid')->getStartColor()->setRGB('123460');
                $punches->getColumnDimension('A')->setWidth(24);
                $punches->getColumnDimension('B')->setWidth(25);
                $punches->getColumnDimension('C')->setWidth(20);
                $punches->freezePane('A2');

                $example = $workbook->createSheet();
                $example->setTitle('Example');
                $example->fromArray([
                    ['biometric_code', 'punched_at', 'punch_type'],
                    ['1001', '2026-08-03 08:00:00', 'check_in'],
                    ['1001', '2026-08-03 16:00:00', 'check_out'],
                ]);
                $example->getStyle('A1:C1')->getFont()->setBold(true);
                $example->getColumnDimension('A')->setWidth(24);
                $example->getColumnDimension('B')->setWidth(25);
                $example->getColumnDimension('C')->setWidth(20);

                $workbook->setActiveSheetIndex(0);
                (new Xlsx($workbook))->save('php://output');
            } finally {
                $workbook->disconnectWorksheets();
            }
        }, 'biometric-attendance-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
