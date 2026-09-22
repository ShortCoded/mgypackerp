<?php

use App\Http\Middleware\IdempotentDocumentSubmission;
use Illuminate\Support\Facades\Route;
use Modules\HR\Http\Controllers\EmployeeSelfServiceController;
use Modules\HR\Http\Controllers\HrAllowanceController;
use Modules\HR\Http\Controllers\HrAreaController;
use Modules\HR\Http\Controllers\HrAttendanceController;
use Modules\HR\Http\Controllers\HrAttendanceImportController;
use Modules\HR\Http\Controllers\HrAttendanceSettingsController;
use Modules\HR\Http\Controllers\HrBiometricDeviceController;
use Modules\HR\Http\Controllers\HrCityController;
use Modules\HR\Http\Controllers\HrCountryController;
use Modules\HR\Http\Controllers\HrDepartmentController;
use Modules\HR\Http\Controllers\HrDocumentTypeController;
use Modules\HR\Http\Controllers\HrEmployeeController;
use Modules\HR\Http\Controllers\HrEmploymentTaxPolicyController;
use Modules\HR\Http\Controllers\HrEmploymentTypeController;
use Modules\HR\Http\Controllers\HrFacultyController;
use Modules\HR\Http\Controllers\HrGovernorateController;
use Modules\HR\Http\Controllers\HrGradeController;
use Modules\HR\Http\Controllers\HrHiringStatusController;
use Modules\HR\Http\Controllers\HrIdentificationController;
use Modules\HR\Http\Controllers\HrInsuranceOfficeController;
use Modules\HR\Http\Controllers\HrJobController;
use Modules\HR\Http\Controllers\HrLeaveTypeController;
use Modules\HR\Http\Controllers\HrMilitaryServiceController;
use Modules\HR\Http\Controllers\HrNationalityController;
use Modules\HR\Http\Controllers\HrQualificationController;
use Modules\HR\Http\Controllers\HrReligionController;
use Modules\HR\Http\Controllers\HrRequestController;
use Modules\HR\Http\Controllers\HrSectionController;
use Modules\HR\Http\Controllers\HrSelect2InlineController;
use Modules\HR\Http\Controllers\HrShiftAssignmentController;
use Modules\HR\Http\Controllers\HrShiftController;
use Modules\HR\Http\Controllers\HrSocialInsurancePolicyController;
use Modules\HR\Http\Controllers\HrSpecializationController;
use Modules\HR\Http\Controllers\HrUniversityController;
use Modules\HR\Http\Controllers\HrWorkforceReportController;
use Modules\HR\Http\Controllers\PayrollAttendancePolicyController;
use Modules\HR\Http\Controllers\PayrollController;
use Modules\HR\Http\Controllers\PayrollCostPreviewController;
use Modules\HR\Http\Controllers\PayrollReportController;
use Modules\HR\Http\Controllers\PayslipController;
use Modules\HR\Http\Controllers\Select2\HrSelect2Controller;

Route::middleware('auth')
    ->prefix('my/hr')
    ->as('employee.hr.')
    ->controller(EmployeeSelfServiceController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('self-service.index');
        Route::get('/attendance/status', 'status')->middleware('throttle:60,1')->name('attendance.status');
        Route::post('/attendance/punch', 'punch')->middleware('throttle:12,1')->name('attendance.punch');
        Route::post('/requests', 'storeRequest')->middleware('throttle:10,1')->name('requests.store');
        Route::patch('/requests/{employeeRequest}/cancel', 'cancelRequest')->name('requests.cancel');
        Route::get('/payslips/{payslip}', [PayslipController::class, 'employeeShow'])->whereNumber('payslip')->name('payslips.show');
        Route::get('/payslips/{payslip}/print', [PayslipController::class, 'employeePrint'])->whereNumber('payslip')->name('payslips.print');
        Route::get('/payslips/{payslip}/pdf', [PayslipController::class, 'employeePdf'])->whereNumber('payslip')->name('payslips.pdf');
    });

Route::middleware('auth')
    ->prefix('admin/hr')
    ->as('admin.hr.')
    ->group(function (): void {
        Route::get('/attendance-settings', [HrAttendanceSettingsController::class, 'index'])
            ->middleware('can:hr.attendance_settings.view')
            ->name('attendance-settings.index');
        Route::patch('/attendance-settings/{branch:doc_num}', [HrAttendanceSettingsController::class, 'update'])
            ->middleware('can:hr.attendance_settings.manage')
            ->name('attendance-settings.update');
        Route::post('/attendance-settings/resolve-map-url', [HrAttendanceSettingsController::class, 'resolveMapUrl'])
            ->middleware('can:hr.attendance_settings.manage')
            ->name('attendance-settings.resolve-map-url');

        Route::get('/shift-assignments', [HrShiftAssignmentController::class, 'index'])
            ->middleware('can:hr.shift_assignments.view')
            ->name('shift-assignments.index');
        Route::post('/shift-assignments', [HrShiftAssignmentController::class, 'store'])
            ->middleware('can:hr.shift_assignments.manage')
            ->name('shift-assignments.store');
        Route::patch('/shift-assignments/{assignment}', [HrShiftAssignmentController::class, 'update'])
            ->whereNumber('assignment')
            ->middleware('can:hr.shift_assignments.manage')
            ->name('shift-assignments.update');

        Route::prefix('employee-attendance')->name('employee-attendance.')->controller(HrAttendanceController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:hr.employee_attendance.view')->name('index');
            Route::get('/export/csv', 'exportCsv')->middleware('can:hr.employee_attendance.export')->name('export.csv');
            Route::post('/manual', 'storeManual')->middleware('can:hr.employee_attendance.correct')->name('manual.store');
        });
        Route::prefix('employee-attendance/import')->name('employee-attendance.import.')->controller(HrAttendanceImportController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:hr.employee_attendance.import')->name('index');
            Route::post('/', 'store')->middleware('can:hr.employee_attendance.import')->name('store');
        });

        Route::prefix('hr-requests')->name('hr-requests.')->controller(HrRequestController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:hr.hr_requests.view')->name('index');
            Route::patch('/{employeeRequest}/review', 'review')->middleware('can:hr.hr_requests.manage')->name('review');
        });

        Route::prefix('leave-types')->name('leave-types.')->controller(HrLeaveTypeController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:hr.leave_types.view')->name('index');
            Route::post('/', 'store')->middleware('can:hr.leave_types.create')->name('store');
            Route::patch('/{leaveType}/restore', 'restore')->whereNumber('leaveType')->middleware('can:hr.leave_types.restore')->name('restore');
            Route::get('/{leaveType}', 'show')->whereNumber('leaveType')->middleware('can:hr.leave_types.view')->name('show');
            Route::patch('/{leaveType}', 'update')->whereNumber('leaveType')->middleware('can:hr.leave_types.update')->name('update');
            Route::delete('/{leaveType}', 'destroy')->whereNumber('leaveType')->middleware('can:hr.leave_types.delete')->name('destroy');
        });

        Route::get('/payroll-attendance-policies', [PayrollAttendancePolicyController::class, 'index'])
            ->middleware('can:hr.payroll_attendance_policies.view')
            ->name('payroll-attendance-policies.index');
        Route::post('/payroll-attendance-policies', [PayrollAttendancePolicyController::class, 'store'])
            ->middleware('can:hr.payroll_attendance_policies.manage')
            ->name('payroll-attendance-policies.store');

        Route::get('/payroll-preparation', [PayrollController::class, 'index'])
            ->middleware('can:hr.payroll_preparation.view')
            ->name('payroll-preparation.index');
        Route::get('/payslips/{payslip}', [PayslipController::class, 'adminShow'])
            ->whereNumber('payslip')->middleware('can:hr.payslips.view')->name('payslips.show');
        Route::get('/payslips/{payslip}/print', [PayslipController::class, 'adminPrint'])
            ->whereNumber('payslip')->middleware('can:hr.payslips.view')->name('payslips.print');
        Route::get('/payslips/{payslip}/pdf', [PayslipController::class, 'adminPdf'])
            ->whereNumber('payslip')->middleware('can:hr.payslips.view')->name('payslips.pdf');
        Route::prefix('reports')->name('reports.')->group(function (): void {
            Route::get('/employees', [HrWorkforceReportController::class, 'employees'])
                ->middleware('can:hr.employee_reports.view')->name('employees');
            Route::get('/employees/export', [HrWorkforceReportController::class, 'exportEmployees'])
                ->middleware('can:hr.employee_reports.export')->name('employees.export');
            Route::get('/attendance', [HrAttendanceController::class, 'index'])
                ->middleware('can:hr.employee_attendance.view')->name('attendance');
            Route::get('/attendance/export/csv', [HrAttendanceController::class, 'exportCsv'])
                ->middleware('can:hr.employee_attendance.export')->name('attendance.export.csv');
            Route::get('/leave-requests', [HrWorkforceReportController::class, 'leaveRequests'])
                ->middleware('can:hr.leave_reports.view')->name('leave-requests');
            Route::get('/leave-requests/export', [HrWorkforceReportController::class, 'exportLeaveRequests'])
                ->middleware('can:hr.leave_reports.export')->name('leave-requests.export');
            Route::get('/payroll', [PayrollReportController::class, 'payroll'])
                ->middleware('can:hr.payroll_reports.view')->name('payroll');
            Route::get('/payroll/export', [PayrollReportController::class, 'exportPayroll'])
                ->middleware('can:hr.payroll_reports.export')->name('payroll.export');
            Route::get('/payroll-payments', [PayrollReportController::class, 'payments'])
                ->middleware('can:hr.payroll_payment_reports.view')->name('payments');
            Route::get('/payroll-payments/export', [PayrollReportController::class, 'exportPayments'])
                ->middleware('can:hr.payroll_payment_reports.export')->name('payments.export');
        });
        Route::prefix('payroll-runs')->name('payroll-runs.')->controller(PayrollController::class)->group(function (): void {
            Route::post('/calculate', 'calculate')->middleware('can:hr.payroll_preparation.calculate')->name('calculate');
            Route::post('/{payrollRun}/review', 'review')->whereNumber('payrollRun')->middleware('can:hr.payroll_approval.review')->name('review');
            Route::post('/{payrollRun}/approve', 'approve')->whereNumber('payrollRun')->middleware('can:hr.payroll_approval.approve')->name('approve');
            Route::post('/{payrollRun}/payments', 'storePayment')->whereNumber('payrollRun')->middleware([
                'can:hr.payroll_payment.create',
                'can:cash_payment_vouchers.create',
                IdempotentDocumentSubmission::class.':required,idempotency_key',
            ])->name('payments.store');
            Route::get('/{payrollRun}/reconciliation', 'reconcile')->whereNumber('payrollRun')->middleware('can:hr.payroll_reconciliation.view')->name('reconciliation');
        });

        Route::get('/select2/lookups/{resource}', [HrSelect2Controller::class, 'lookup'])
            ->name('select2.lookups');
        Route::get('/select2/foundation/{resource}', [HrSelect2Controller::class, 'foundation'])
            ->name('select2.foundation');
        Route::get('/select2/employees', [HrSelect2Controller::class, 'employees'])
            ->name('select2.employees');
        Route::get('/payroll-runs/{payrollRun}/cost-preview', PayrollCostPreviewController::class)
            ->whereNumber('payrollRun')
            ->middleware('can:hr.payroll_preparation.view')
            ->name('payroll-runs.cost-preview');
        Route::post('/select2/inline/lookups/{resource}', [HrSelect2InlineController::class, 'storeLookup'])
            ->name('select2.inline.lookups.store');
        Route::post('/select2/inline/foundation/{resource}', [HrSelect2InlineController::class, 'storeFoundation'])
            ->name('select2.inline.foundation.store');
        Route::post('/select2/inline/companies', [HrSelect2InlineController::class, 'storeCompany'])
            ->name('select2.inline.companies.store');
        Route::post('/select2/inline/branches', [HrSelect2InlineController::class, 'storeBranch'])
            ->name('select2.inline.branches.store');

        $lookupResources = [
            ['prefix' => 'countries', 'controller' => HrCountryController::class, 'parameter' => 'country'],
            ['prefix' => 'governorates', 'controller' => HrGovernorateController::class, 'parameter' => 'governorate'],
            ['prefix' => 'cities', 'controller' => HrCityController::class, 'parameter' => 'city'],
            ['prefix' => 'areas', 'controller' => HrAreaController::class, 'parameter' => 'area'],
            ['prefix' => 'nationalities', 'controller' => HrNationalityController::class, 'parameter' => 'nationality'],
            ['prefix' => 'religions', 'controller' => HrReligionController::class, 'parameter' => 'religion'],
            ['prefix' => 'qualifications', 'controller' => HrQualificationController::class, 'parameter' => 'qualification'],
            ['prefix' => 'universities', 'controller' => HrUniversityController::class, 'parameter' => 'university'],
            ['prefix' => 'faculties', 'controller' => HrFacultyController::class, 'parameter' => 'faculty'],
            ['prefix' => 'specializations', 'controller' => HrSpecializationController::class, 'parameter' => 'specialization'],
            ['prefix' => 'military-services', 'controller' => HrMilitaryServiceController::class, 'parameter' => 'militaryService'],
            ['prefix' => 'allowances', 'controller' => HrAllowanceController::class, 'parameter' => 'allowance'],
            ['prefix' => 'hiring-statuses', 'controller' => HrHiringStatusController::class, 'parameter' => 'hiringStatus'],
            ['prefix' => 'identifications', 'controller' => HrIdentificationController::class, 'parameter' => 'identification'],
        ];

        foreach ($lookupResources as $resource) {
            $prefix = $resource['prefix'];
            $controller = $resource['controller'];
            $parameter = $resource['parameter'];

            Route::prefix($prefix)->name("{$prefix}.")->controller($controller)->group(function () use ($controller, $parameter): void {
                $permissionBase = match ($controller) {
                    HrCountryController::class => 'hr.countries',
                    HrGovernorateController::class => 'hr.governorates',
                    HrCityController::class => 'hr.cities',
                    HrAreaController::class => 'hr.areas',
                    HrNationalityController::class => 'hr.nationalities',
                    HrReligionController::class => 'hr.religions',
                    HrQualificationController::class => 'hr.qualifications',
                    HrUniversityController::class => 'hr.universities',
                    HrFacultyController::class => 'hr.faculties',
                    HrSpecializationController::class => 'hr.specializations',
                    HrMilitaryServiceController::class => 'hr.military_services',
                    HrAllowanceController::class => 'hr.allowances',
                    HrHiringStatusController::class => 'hr.hiring_statuses',
                    HrIdentificationController::class => 'hr.identifications',
                    default => 'hr.lookups',
                };

                Route::get('/', 'index')->middleware("can:{$permissionBase}.view")->name('index');
                Route::get('/data', 'data')->middleware("can:{$permissionBase}.view")->name('data');
                Route::get('/create', 'create')->middleware("can:{$permissionBase}.create")->name('create');
                Route::post('/', 'store')->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')->middleware("can:{$permissionBase}.delete")->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware("can:{$permissionBase}.document_number_settings.update")->name('document-number-settings.update');
                Route::patch('/{record}/restore', 'restore')->middleware("can:{$permissionBase}.restore")->name('restore');
                $paramKey = '{'.$parameter.':doc_num}';
                Route::get("/{$paramKey}/clone", 'clone')->middleware("can:{$permissionBase}.clone")->name('clone');
                Route::get("/{$paramKey}", 'show')->withTrashed()->middleware("can:{$permissionBase}.view")->name('show');
                Route::get("/{$paramKey}/edit", 'edit')->middleware("can:{$permissionBase}.edit")->name('edit');
                Route::put("/{$paramKey}", 'update')->middleware("can:{$permissionBase}.edit")->name('update');
                Route::delete("/{$paramKey}", 'destroy')->middleware("can:{$permissionBase}.delete")->name('destroy');
            });
        }

        $enterpriseFoundationResources = [
            ['prefix' => 'departments', 'controller' => HrDepartmentController::class, 'parameter' => 'department', 'permission' => 'hr.departments'],
            ['prefix' => 'sections', 'controller' => HrSectionController::class, 'parameter' => 'section', 'permission' => 'hr.sections'],
            ['prefix' => 'jobs', 'controller' => HrJobController::class, 'parameter' => 'job', 'permission' => 'hr.jobs'],
            ['prefix' => 'document-types', 'controller' => HrDocumentTypeController::class, 'parameter' => 'documentType', 'permission' => 'hr.document_types'],
            ['prefix' => 'shifts', 'controller' => HrShiftController::class, 'parameter' => 'shift', 'permission' => 'hr.shifts'],
            ['prefix' => 'biometric-devices', 'controller' => HrBiometricDeviceController::class, 'parameter' => 'biometricDevice', 'permission' => 'hr.biometric_devices'],
            ['prefix' => 'grades', 'controller' => HrGradeController::class, 'parameter' => 'grade', 'permission' => 'hr.grades'],
            ['prefix' => 'employment-types', 'controller' => HrEmploymentTypeController::class, 'parameter' => 'employmentType', 'permission' => 'hr.employment_types'],
            ['prefix' => 'insurance-offices', 'controller' => HrInsuranceOfficeController::class, 'parameter' => 'insuranceOffice', 'permission' => 'hr.insurance_offices'],
            ['prefix' => 'social-insurance-policies', 'controller' => HrSocialInsurancePolicyController::class, 'parameter' => 'socialInsurancePolicy', 'permission' => 'hr.social_insurance_policies'],
            ['prefix' => 'employment-tax-policies', 'controller' => HrEmploymentTaxPolicyController::class, 'parameter' => 'employmentTaxPolicy', 'permission' => 'hr.employment_tax_policies'],
        ];

        foreach ($enterpriseFoundationResources as $resource) {
            $prefix = $resource['prefix'];
            $controller = $resource['controller'];
            $parameter = $resource['parameter'];
            $permissionBase = $resource['permission'];

            Route::prefix($prefix)->name("{$prefix}.")->controller($controller)->group(function () use ($permissionBase, $parameter): void {
                Route::get('/', 'index')->middleware("can:{$permissionBase}.view")->name('index');
                Route::get('/data', 'data')->middleware("can:{$permissionBase}.view")->name('data');
                Route::get('/create', 'create')->middleware("can:{$permissionBase}.create")->name('create');
                Route::post('/', 'store')->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')->middleware("can:{$permissionBase}.delete")->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware("can:{$permissionBase}.document_number_settings.update")->name('document-number-settings.update');
                Route::patch('/{record}/restore', 'restore')->middleware("can:{$permissionBase}.restore")->name('restore');
                $paramKey = '{'.$parameter.':doc_num}';
                Route::get("/{$paramKey}/clone", 'clone')->middleware("can:{$permissionBase}.clone")->name('clone');
                Route::get("/{$paramKey}", 'show')->withTrashed()->middleware("can:{$permissionBase}.view")->name('show');
                Route::get("/{$paramKey}/edit", 'edit')->middleware("can:{$permissionBase}.edit")->name('edit');
                Route::put("/{$paramKey}", 'update')->middleware("can:{$permissionBase}.edit")->name('update');
                Route::delete("/{$paramKey}", 'destroy')->middleware("can:{$permissionBase}.delete")->name('destroy');
            });
        }

        Route::prefix('employees')->name('employees.')->controller(HrEmployeeController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:hr.employees.view')->name('index');
            Route::get('/data', 'data')->middleware('can:hr.employees.view')->name('data');
            Route::get('/create', 'create')->middleware('can:hr.employees.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:hr.employees.delete')->name('bulk-delete');
            Route::patch('/bulk-restore', 'bulkRestore')->middleware('can:hr.employees.restore')->name('bulk-restore');
            Route::patch('/bulk-status', 'bulkStatus')->middleware('can:hr.employees.edit')->name('bulk-status');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:hr.employees.document_number_settings.update')->name('document-number-settings.update');
            Route::get('/trashed/{employee}', 'showTrashed')->whereUuid('employee')->middleware(['can:hr.employees.view', 'can:hr.employees.view_trashed'])->name('trashed.show');
            Route::get('/trashed/{employee}/documents/{document:doc_num}/download', 'downloadTrashedDocument')->whereUuid('employee')->middleware(['can:hr.employees.view_trashed', 'can:hr.employees.documents.view'])->name('trashed.documents.download');
            Route::patch('/trashed/{employee}/restore', 'restore')->whereUuid('employee')->middleware('can:hr.employees.restore')->name('restore');
            Route::get('/{employee:doc_num}/clone', 'clone')->middleware('can:hr.employees.clone')->name('clone');
            Route::get('/{employee:doc_num}', 'show')->middleware('can:hr.employees.view')->name('show');
            Route::get('/{employee:doc_num}/edit', 'edit')->middleware('can:hr.employees.edit')->name('edit');
            Route::put('/{employee:doc_num}', 'update')->middleware('can:hr.employees.edit')->name('update');
            Route::delete('/{employee:doc_num}', 'destroy')->middleware('can:hr.employees.delete')->name('destroy');
            Route::post('/{employee:doc_num}/documents', 'storeDocument')->middleware('can:hr.employees.documents.manage')->name('documents.store');
            Route::get('/{employee:doc_num}/documents/{document:doc_num}/download', 'downloadDocument')->middleware('can:hr.employees.documents.view')->name('documents.download');
            Route::delete('/{employee:doc_num}/documents/{document:doc_num}', 'destroyDocument')->middleware('can:hr.employees.documents.delete')->name('documents.destroy');
        });
    });
