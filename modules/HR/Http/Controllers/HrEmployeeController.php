<?php

namespace Modules\HR\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\HR\DataTables\HrEmployeesDataTable;
use Modules\HR\Http\Requests\Employees\BulkDeleteHrEmployeesRequest;
use Modules\HR\Http\Requests\Employees\BulkRestoreHrEmployeesRequest;
use Modules\HR\Http\Requests\Employees\BulkUpdateHrEmployeesStatusRequest;
use Modules\HR\Http\Requests\Employees\StoreHrEmployeeDocumentRequest;
use Modules\HR\Http\Requests\Employees\StoreHrEmployeeRequest;
use Modules\HR\Http\Requests\Employees\UpdateHrEmployeeDocumentNumberSettingsRequest;
use Modules\HR\Http\Requests\Employees\UpdateHrEmployeeRequest;
use Modules\HR\Models\HrAllowance;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDocumentType;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeDocument;
use Modules\HR\Models\HrEmploymentType;
use Modules\HR\Models\HrHiringStatus;
use Modules\HR\Models\HrInsuranceOffice;
use Modules\HR\Models\HrJob;
use Modules\HR\Models\HrNationality;
use Modules\HR\Models\HrSection;
use Modules\HR\Models\HrShift;
use Modules\HR\Services\HrEmployeeDocumentNumberSettingsService;
use Modules\HR\Services\HrEmployeeService;
use Modules\HR\Services\HrFoundationRegistry;
use Modules\HR\Services\HrLookupRegistry;
use Modules\HR\Services\HrSocialInsuranceContributionCalculator;
use Modules\HR\Services\HrStatutoryPolicyResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class HrEmployeeController extends Controller
{
    /**
     * @var array<string, array{column: string, model: class-string, route?: string, lookup?: string, foundation?: string, create_route?: string, create_permission?: string}>
     */
    private array $selectFields = [
        'branch_doc_num' => ['column' => 'branch_id', 'model' => Branch::class, 'route' => 'admin.select2.branches', 'create_route' => 'admin.branches.create', 'create_permission' => 'branches.create'],
        'department_doc_num' => ['column' => 'department_id', 'model' => HrDepartment::class, 'foundation' => 'departments'],
        'section_doc_num' => ['column' => 'section_id', 'model' => HrSection::class, 'foundation' => 'sections'],
        'job_doc_num' => ['column' => 'job_id', 'model' => HrJob::class, 'foundation' => 'jobs'],
        'employment_type_doc_num' => ['column' => 'employment_type_id', 'model' => HrEmploymentType::class, 'foundation' => 'employment-types'],
        'nationality_doc_num' => ['column' => 'nationality_id', 'model' => HrNationality::class, 'lookup' => 'nationalities'],
        'hiring_status_doc_num' => ['column' => 'hiring_status_id', 'model' => HrHiringStatus::class, 'lookup' => 'hiring-statuses'],
        'allowance_doc_num' => ['column' => 'allowance_id', 'model' => HrAllowance::class, 'lookup' => 'allowances'],
        'default_shift_doc_num' => ['column' => 'default_shift_id', 'model' => HrShift::class, 'foundation' => 'shifts'],
        'payroll_currency_doc_num' => ['column' => 'payroll_currency_id', 'model' => Currency::class, 'route' => 'admin.select2.currencies'],
        'insurance_office_doc_num' => ['column' => 'insurance_office_id', 'model' => HrInsuranceOffice::class, 'foundation' => 'insurance-offices'],
    ];

    public function __construct(
        private readonly HrEmployeeService $employees,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly HrLookupRegistry $hrLookupRegistry,
        private readonly HrFoundationRegistry $hrFoundationRegistry,
        private readonly HrStatutoryPolicyResolver $statutoryPolicies,
        private readonly HrSocialInsuranceContributionCalculator $insuranceCalculator,
    ) {}

    public function index(Request $request, HrEmployeeDocumentNumberSettingsService $documentNumberSettings): View
    {
        $filterSelects = collect($this->selectedOptions(null))->only([
            'branch_doc_num',
            'department_doc_num',
            'section_doc_num',
            'job_doc_num',
            'employment_type_doc_num',
            'hiring_status_doc_num',
            'insurance_office_doc_num',
        ])->all();

        return view('modules.hr.employees.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.employees.index'),
            'documentNumberSettings' => $documentNumberSettings->current(),
            'filterSelects' => $filterSelects,
        ]);
    }

    public function data(Request $request, HrEmployeesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    public function show(Request $request, HrEmployee $employee): View
    {
        $this->logActivity($request, 'hr.employees.view', $this->recordPublicProperties($employee));

        return $this->formView('view', $employee);
    }

    public function showTrashed(Request $request, string $employee): View
    {
        $employee = $this->trashedRecordByPublicUuid($employee);
        $this->logActivity($request, 'hr.employees.view_trashed', $this->recordPublicProperties($employee));

        return $this->formView('view', $employee);
    }

    public function edit(HrEmployee $employee): View
    {
        return $this->formView('edit', $employee);
    }

    public function clone(HrEmployee $employee): View
    {
        $token = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($token), $employee->doc_num);

        return $this->formView('clone', $employee, $token);
    }

    public function store(StoreHrEmployeeRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'), creating: true);
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $employee = $this->employees->create($request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        $this->logActivity($request, $cloneSource instanceof HrEmployee ? 'hr.employees.clone' : 'hr.employees.create', $cloneSource instanceof HrEmployee
            ? ActivityLogProperties::crudCloned(
                'hr.employees',
                ActivityLogProperties::record('hr.employees', $cloneSource->full_name, $cloneSource->doc_num),
                $employee->full_name,
                $employee->doc_num,
                $this->submitActionProperties($request, creating: true),
            )
            : ActivityLogProperties::crudCreated('hr.employees', $employee->full_name, $employee->doc_num, $this->submitActionProperties($request, creating: true)));

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof HrEmployee ? __('hr.employees.messages.cloned') : __('hr.employees.messages.created'),
            ...$this->saveActionResponse($request, $employee, 'store'),
            'data' => [
                'doc_num' => $employee->doc_num,
                'doc_number' => $employee->doc_number,
                'urls' => $this->recordUrls($employee),
            ],
        ]);
    }

    public function update(UpdateHrEmployeeRequest $request, HrEmployee $employee): JsonResponse
    {
        $this->authorizeSubmitAction($request, $this->submitAction($request), creating: false);

        try {
            $result = $this->employees->update($employee, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        $employee = $result['employee'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $this->logActivity($request, 'hr.employees.update', ActivityLogProperties::crudUpdated(
            'hr.employees',
            $employee->full_name,
            $employee->doc_num,
            $result['changes'],
            $this->submitActionProperties($request),
        ));

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'hr.employees.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'hr.employees',
                $employee->full_name,
                $result['old_doc_number'],
                $result['old_doc_num'],
                $employee->doc_number,
                $employee->doc_num,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('hr.employees.messages.updated'),
            ...$this->saveActionResponse($request, $employee, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_number' => $employee->doc_number,
                'doc_num' => $employee->doc_num,
                'urls' => $this->recordUrls($employee),
                'document_row_ids' => $result['document_row_ids'],
            ],
        ]);
    }

    public function destroy(Request $request, HrEmployee $employee): JsonResponse
    {
        $this->employees->delete($employee);
        $this->logActivity($request, 'hr.employees.delete', ActivityLogProperties::crudDeleted(
            'hr.employees',
            $employee->full_name,
            $employee->doc_num,
        ));

        return response()->json([
            'success' => true,
            'message' => __('hr.employees.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteHrEmployeesRequest $request): JsonResponse
    {
        $docNums = $request->validated()['doc_nums'];
        $deleted = $this->employees->bulkDelete($docNums);

        $this->logActivity($request, 'hr.employees.bulk_delete', ActivityLogProperties::bulkDeleted(
            'hr.employees',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('hr.employees.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function bulkRestore(BulkRestoreHrEmployeesRequest $request): JsonResponse
    {
        $publicUuids = $request->validated()['public_uuids'];

        try {
            $restored = $this->employees->bulkRestore($publicUuids);
        } catch (DomainException|QueryException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception instanceof QueryException
                    ? __('hr.employees.messages.restore_conflict')
                    : $exception->getMessage(),
            ], 422);
        }

        $this->logActivity($request, 'hr.employees.bulk_restore', [
            'bulk' => [
                'count' => $restored,
                'public_uuids' => $publicUuids,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('hr.employees.messages.bulk_restored', ['count' => $restored]),
            'data' => [
                'restored' => $restored,
            ],
        ]);
    }

    public function bulkStatus(BulkUpdateHrEmployeesStatusRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $docNums = $validated['doc_nums'];
        $status = (string) $validated['status'];
        $updated = $this->employees->bulkUpdateStatus($docNums, $status);

        $this->logActivity($request, 'hr.employees.bulk_status', [
            'bulk' => [
                'count' => $updated,
                'doc_nums' => $docNums,
            ],
            'status' => $status,
        ]);

        return response()->json([
            'success' => true,
            'message' => $status === 'active'
                ? __('hr.employees.messages.bulk_activated', ['count' => $updated])
                : __('hr.employees.messages.bulk_deactivated', ['count' => $updated]),
            'data' => [
                'updated' => $updated,
                'status' => $status,
            ],
        ]);
    }

    public function restore(Request $request, string $employee): JsonResponse
    {
        $employee = $this->trashedRecordByPublicUuid($employee);

        try {
            $employee = $this->employees->restore($employee);
        } catch (DomainException|QueryException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception instanceof QueryException
                    ? __('hr.employees.messages.restore_conflict')
                    : $exception->getMessage(),
            ], 422);
        }

        $this->logActivity($request, 'hr.employees.restore', ActivityLogProperties::crudRestored(
            'hr.employees',
            $employee->full_name,
            $employee->doc_num,
            $this->recordRestoreProperties($request, $employee),
        ));

        return response()->json([
            'success' => true,
            'message' => __('hr.employees.messages.restored'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateHrEmployeeDocumentNumberSettingsRequest $request,
        HrEmployeeDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $result = $documentNumberSettings->update(
            $request->validated('prefix'),
            (int) $request->validated('padding'),
        );

        $this->logActivity($request, 'hr.employees.document_number_settings.update', ActivityLogProperties::settingsUpdated(
            'hr.employees',
            [
                'prefix' => [
                    'old' => $result['old']['prefix'],
                    'new' => $result['new']['prefix'],
                ],
                'padding' => [
                    'old' => $result['old']['padding'],
                    'new' => $result['new']['padding'],
                ],
            ],
        ));

        return response()->json([
            'success' => true,
            'message' => __('common.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    public function storeDocument(StoreHrEmployeeDocumentRequest $request, HrEmployee $employee): JsonResponse
    {
        $document = $this->employees->storeDocument($employee, $request->validated());
        $document->load(['documentType', 'archiveFile']);

        $this->logActivity($request, 'hr.employees.documents.upload', [
            ...$this->recordPublicProperties($employee),
            'document_doc_num' => $document->doc_num,
            'document_title' => $document->title,
            'extension' => $document->extension,
            'size' => $document->size,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('hr.employees.documents.messages.uploaded'),
            'data' => [
                'doc_num' => $document->doc_num,
                'row' => view('modules.hr.employees.partials.document-row', [
                    'employee' => $employee,
                    'document' => $document,
                    'isView' => false,
                ])->render(),
            ],
        ]);
    }

    public function downloadDocument(Request $request, HrEmployee $employee, HrEmployeeDocument $document): BinaryFileResponse
    {
        return $this->downloadEmployeeDocument($request, $employee, $document);
    }

    public function downloadTrashedDocument(Request $request, string $employee, HrEmployeeDocument $document): BinaryFileResponse
    {
        return $this->downloadEmployeeDocument($request, $this->trashedRecordByPublicUuid($employee), $document);
    }

    private function downloadEmployeeDocument(Request $request, HrEmployee $employee, HrEmployeeDocument $document): BinaryFileResponse
    {
        $this->ensureDocumentBelongsToEmployee($employee, $document);
        abort_unless((bool) $request->user()?->can('hr.employees.documents.view'), 403);

        $path = $this->employees->documentDownloadPath($document);
        $this->logActivity($request, 'hr.employees.documents.view', [
            ...$this->recordPublicProperties($employee),
            'document_doc_num' => $document->doc_num,
            'document_title' => $document->title,
        ]);

        return Response::download($path, $document->original_name);
    }

    public function destroyDocument(Request $request, HrEmployee $employee, HrEmployeeDocument $document): JsonResponse
    {
        $this->ensureDocumentBelongsToEmployee($employee, $document);
        abort_unless((bool) $request->user()?->can('hr.employees.documents.delete'), 403);

        $this->employees->deleteDocument($document);
        $this->logActivity($request, 'hr.employees.documents.delete', [
            ...$this->recordPublicProperties($employee),
            'document_doc_num' => $document->doc_num,
            'document_title' => $document->title,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('hr.employees.documents.messages.deleted'),
        ]);
    }

    private function formView(string $mode, ?HrEmployee $employee = null, ?string $cloneSourceToken = null): View
    {
        $documentNumberSettings = app(HrEmployeeDocumentNumberSettingsService::class)->current();
        $statutoryPreviewDate = now()->toDateString();
        $socialInsurancePolicy = $this->statutoryPolicies->socialInsuranceAt($statutoryPreviewDate);
        $employmentTaxPolicy = $this->statutoryPolicies->employmentTaxAt($statutoryPreviewDate);
        $contributionWage = old('insurance_contribution_wage', $employee?->insurance_contribution_wage);
        $socialInsurancePreview = $socialInsurancePolicy === null
            ? null
            : $this->insuranceCalculator->preview($socialInsurancePolicy, $contributionWage);
        $employee?->load([
            'photoArchiveFile',
            'signatureArchiveFile',
            'payrollCurrency',
            'insuranceOffice',
            'biometricMappings' => fn ($query) => $query->with('device')->latest('created_at'),
            'documents' => fn ($query) => $query->with(['documentType', 'archiveFile'])->latest('created_at'),
        ]);

        return view('modules.hr.employees.form', [
            'mode' => $mode,
            'employee' => $employee,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.hr.employees.store') : route('admin.hr.employees.update', $employee?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $documentNumberSettings['prefix'],
            'documentNumberPadding' => $documentNumberSettings['padding'],
            'canControlDocumentNumber' => (bool) auth()->user()?->can('hr.employees.document_number.control'),
            'metadata' => $this->metadata($employee),
            'breadcrumbs' => $this->breadcrumbs($mode, $employee),
            'cloneSourceToken' => $cloneSourceToken,
            'selectedOptions' => $this->selectedOptions($employee),
            'selectFields' => $this->selectFields,
            'defaults' => $this->defaults($employee),
            'documentTypeSelect' => $this->foundationSelect('document-types'),
            'biometricDeviceSelect' => $this->foundationSelect('biometric-devices'),
            'canViewDocuments' => (bool) auth()->user()?->can('hr.employees.documents.view'),
            'canManageDocuments' => (bool) auth()->user()?->can('hr.employees.documents.manage'),
            'canDeleteDocuments' => (bool) auth()->user()?->can('hr.employees.documents.delete'),
            'statutoryPreviewDate' => $statutoryPreviewDate,
            'socialInsurancePolicy' => $socialInsurancePolicy,
            'socialInsurancePreview' => $socialInsurancePreview,
            'socialInsurancePolicyEditUrl' => $socialInsurancePolicy !== null
                && auth()->user()?->can('hr.social_insurance_policies.edit')
                ? route('admin.hr.social-insurance-policies.edit', $socialInsurancePolicy->doc_num)
                : null,
            'employmentTaxPolicy' => $employmentTaxPolicy,
            'employmentTaxPolicyEditUrl' => $employmentTaxPolicy !== null
                && auth()->user()?->can('hr.employment_tax_policies.edit')
                ? route('admin.hr.employment-tax-policies.edit', $employmentTaxPolicy->doc_num)
                : null,
        ]);
    }

    /**
     * @return array<string, array{id: string, text: string, url: string, can_create: bool, create_url: string|null, inline_url: string|null}|null>
     */
    private function selectedOptions(?HrEmployee $employee): array
    {
        $selected = [];

        foreach ($this->selectFields as $field => $config) {
            $selected[$field] = null;
            $record = null;

            if ($employee && $employee->{$config['column']}) {
                $model = $config['model'];
                $record = $model::withTrashed()->whereKey($employee->{$config['column']})->first();
            } else {
                $oldDocNum = request()->old($field);

                if ($field === 'branch_doc_num' && is_string($oldDocNum) && trim($oldDocNum) !== '') {
                    $record = app(OperatingContextService::class)
                        ->allowedBranchForCurrentCompany(request(), trim($oldDocNum));
                } elseif (is_string($oldDocNum) && trim($oldDocNum) !== '') {
                    $model = $config['model'];
                    $query = $model::withTrashed()->where('doc_num', trim($oldDocNum));

                    if ($model === Currency::class) {
                        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
                        $query->when($companyId !== null, fn ($query) => $query->forCompany($companyId));
                    }

                    $record = $query->first();
                }
            }

            if (! $record) {
                continue;
            }

            $label = $record instanceof Currency
                ? trim(implode(' / ', array_filter([$record->code, $record->name])))
                : trim(implode(' / ', array_filter([$record->name ?? $record->full_name ?? null, $record->doc_num])));
            $url = $this->selectFieldUrl($field, $config);

            $canCreate = $this->selectFieldCanCreatePage($config);

            $selected[$field] = [
                'id' => (string) $record->doc_num,
                'text' => $label,
                'url' => $url,
                'can_create' => $canCreate,
                'create_url' => $canCreate ? $this->selectFieldCreateUrl($config) : null,
                'inline_url' => null,
            ];
        }

        foreach ($this->selectFields as $field => $config) {
            if ($selected[$field] !== null || ! isset($config['route']) && ! isset($config['lookup']) && ! isset($config['foundation'])) {
                continue;
            }

            $canCreate = $this->selectFieldCanCreatePage($config);

            $selected[$field] = [
                'id' => '',
                'text' => '',
                'url' => $this->selectFieldUrl($field, $config),
                'can_create' => $canCreate,
                'create_url' => $canCreate ? $this->selectFieldCreateUrl($config) : null,
                'inline_url' => null,
            ];
        }

        return $selected;
    }

    /**
     * @param  array{column: string, model: class-string, route?: string, lookup?: string, foundation?: string, create_route?: string, create_permission?: string}  $config
     */
    private function selectFieldUrl(string $field, array $config): string
    {
        if (isset($config['route'])) {
            $parameters = [];

            if ($field === 'branch_doc_num') {
                $parameters['access_scope'] = 'operating_scope';
                $companyDocNum = app(OperatingCompanyContextService::class)->currentCompany()?->doc_num;

                if (is_string($companyDocNum) && $companyDocNum !== '') {
                    $parameters['company_doc_num'] = $companyDocNum;
                }
            }

            return route($config['route'], $parameters);
        }

        if (isset($config['lookup'])) {
            return route('admin.hr.select2.lookups', $config['lookup']);
        }

        return route('admin.hr.select2.foundation', $config['foundation']);
    }

    /**
     * @param  array{column: string, model: class-string, route?: string, lookup?: string, foundation?: string, create_route?: string, create_permission?: string}  $config
     */
    private function selectFieldCanCreatePage(array $config): bool
    {
        $user = auth()->user();

        if (isset($config['create_permission'], $config['create_route'])) {
            return (bool) $user?->can($config['create_permission']) && Route::has($config['create_route']);
        }

        if (isset($config['lookup'])) {
            $definition = $this->hrLookupRegistry->get($config['lookup']);

            return (bool) $user?->can($definition->permission('create'));
        }

        if (isset($config['foundation'])) {
            $definition = $this->hrFoundationRegistry->get($config['foundation']);

            return (bool) $user?->can($definition->permission('create')) && Route::has($definition->route('create'));
        }

        return false;
    }

    /**
     * @param  array{column: string, model: class-string, route?: string, lookup?: string, foundation?: string, create_route?: string, create_permission?: string}  $config
     */
    private function selectFieldCreateUrl(array $config): ?string
    {
        if (isset($config['create_route']) && Route::has($config['create_route'])) {
            return route($config['create_route']);
        }

        if (isset($config['lookup'])) {
            $definition = $this->hrLookupRegistry->get($config['lookup']);

            return Route::has($definition->route('create')) ? route($definition->route('create')) : null;
        }

        if (isset($config['foundation'])) {
            $definition = $this->hrFoundationRegistry->get($config['foundation']);

            return Route::has($definition->route('create')) ? route($definition->route('create')) : null;
        }

        return null;
    }

    /**
     * @return array{url: string, can_create: bool, create_url: string|null, inline_url: string|null}
     */
    private function foundationSelect(string $foundation): array
    {
        $config = ['column' => 'id', 'model' => HrDocumentType::class, 'foundation' => $foundation];

        if ($foundation === 'biometric-devices') {
            $config['model'] = HrBiometricDevice::class;
        }

        $canCreate = $this->selectFieldCanCreatePage($config);
        $createUrl = $canCreate ? $this->selectFieldCreateUrl($config) : null;

        return [
            'url' => route('admin.hr.select2.foundation', $foundation),
            'can_create' => $canCreate,
            'create_url' => $createUrl,
            'inline_url' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(?HrEmployee $employee): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $mainCurrency = $companyId ? Currency::query()->forCompany($companyId)->active()->where('is_main', true)->first() : null;
        $currency = $employee?->payrollCurrency instanceof Currency ? $employee->payrollCurrency : $mainCurrency;

        return [
            'payroll_currency_option' => $currency instanceof Currency ? $this->currencyOption($currency) : null,
            'main_currency_doc_num' => $mainCurrency?->doc_num,
            'exchange_rate' => $employee?->exchange_rate ?? ($mainCurrency ? '1' : null),
        ];
    }

    /**
     * @return array{id: string, text: string, is_main: bool}
     */
    private function currencyOption(Currency $currency): array
    {
        return [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ];
    }

    private function ensureDocumentBelongsToEmployee(HrEmployee $employee, HrEmployeeDocument $document): void
    {
        abort_unless((int) $document->employee_id === (int) $employee->getKey(), 404);
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(HrEmployee $employee): array
    {
        return [
            'show' => route('admin.hr.employees.show', $employee->doc_num),
            'clone' => route('admin.hr.employees.clone', $employee->doc_num),
            'edit' => route('admin.hr.employees.edit', $employee->doc_num),
            'update' => route('admin.hr.employees.update', $employee->doc_num),
            'destroy' => route('admin.hr.employees.destroy', $employee->doc_num),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, HrEmployee $employee, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = [
            'submit_action' => $action,
        ];
        $redirect = match ($action) {
            'save_view' => route('admin.hr.employees.show', $employee->doc_num),
            'save_edit' => route('admin.hr.employees.edit', $employee->doc_num),
            'save_back' => route('admin.hr.employees.index'),
            'save_new' => $operation === 'store' ? null : route('admin.hr.employees.create'),
            'save_clone' => route('admin.hr.employees.clone', $employee->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $employee) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can('hr.employees.document_number.control')) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumber('hr_employees', HrEmployee::class);
            }
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false, bool $creating = false): void
    {
        $permission = match ($action) {
            'save_view' => 'hr.employees.view',
            'save_edit' => 'hr.employees.edit',
            'save_back' => 'hr.employees.view',
            'save_new' => $cloning ? 'hr.employees.clone' : 'hr.employees.create',
            'save_clone' => 'hr.employees.clone',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('hr.messages.action_forbidden'));
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        if (! $creating && in_array($action, ['save_new', 'save_edit'], true)) {
            return 'save';
        }

        return $action;
    }

    /**
     * @return array{submit_action?: string}
     */
    private function submitActionProperties(Request $request, bool $creating = false): array
    {
        $rawAction = $request->string('submit_action')->trim()->toString();

        if ($rawAction === '' && ! $creating) {
            return [];
        }

        return ['submit_action' => $this->submitAction($request, creating: $creating)];
    }

    private function redirectAfterStore(Request $request, HrEmployee $employee): string
    {
        if ($request->user()?->can('hr.employees.edit')) {
            return route('admin.hr.employees.edit', $employee->doc_num);
        }

        if ($request->user()?->can('hr.employees.view')) {
            return route('admin.hr.employees.show', $employee->doc_num);
        }

        return route('admin.hr.employees.index');
    }

    private function cloneSourceFromRequest(StoreHrEmployeeRequest $request): ?HrEmployee
    {
        $token = $request->string('clone_source_token')->trim()->toString();

        if ($token === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('hr.employees.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($token), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'full_name' => __('hr.messages.clone_not_allowed'),
            ]);
        }

        return HrEmployee::query()
            ->where('doc_num', $sourceDocNum)
            ->first();
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return "hr.employees.clone_sources.{$token}";
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?HrEmployee $employee): array
    {
        if (! $employee) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$employee->created_by, $employee->updated_by, $employee->deleted_by, $employee->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($employee->created_by)),
            'created_at' => $settings->formatDateTime($employee->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($employee->updated_by)),
            'updated_at' => $settings->formatDateTime($employee->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($employee->deleted_by)),
            'deleted_at' => $settings->formatDateTime($employee->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($employee->restored_by)),
            'restored_at' => $settings->formatDateTime($employee->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        return $user ? trim(implode(' / ', array_filter([$user->name, $user->doc_num]))) : null;
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function breadcrumbs(string $mode, ?HrEmployee $employee): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                ['label' => (string) $employee?->doc_num, 'url' => $employee ? route('admin.hr.employees.show', $employee->doc_num) : null],
                ['label' => __('hr.titles.clone')],
            ],
            'edit' => [
                ['label' => (string) $employee?->doc_num, 'url' => $employee ? route('admin.hr.employees.show', $employee->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $employee?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.hr.employees.index', $extra);
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();

        if (str_contains($message, '_doc_number_unique_active') || str_contains($message, '_doc_num_unique_active')) {
            throw ValidationException::withMessages(['doc_number' => __('hr.validation.doc_number_unique')]);
        }

        if (str_contains($message, '_employee_number_unique_active')) {
            throw ValidationException::withMessages(['employee_number' => __('hr.employees.validation.employee_number_unique')]);
        }

        if (str_contains($message, '_employee_code_unique_active')) {
            throw ValidationException::withMessages(['employee_code' => __('hr.employees.validation.employee_code_unique')]);
        }

        if (str_contains($message, '_national_id_unique_active')) {
            throw ValidationException::withMessages(['national_id' => __('hr.employees.validation.national_id_unique')]);
        }

        if (str_contains($message, '_work_email_unique_active')) {
            throw ValidationException::withMessages(['work_email' => __('hr.employees.validation.work_email_unique')]);
        }

        if (str_contains($message, '_email_unique_active')) {
            throw ValidationException::withMessages(['email' => __('hr.employees.validation.email_unique')]);
        }

        if (str_contains($message, '_social_insurance_number_unique_active')
            || str_contains($message, 'hr_employees.social_insurance_number')) {
            throw ValidationException::withMessages(['social_insurance_number' => __('validation.unique', [
                'attribute' => __('hr.employees.attributes.social_insurance_number'),
            ])]);
        }
    }

    private function trashedRecordByPublicUuid(string $publicUuid): HrEmployee
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return HrEmployee::onlyTrashed()
            ->where('public_uuid', $publicUuid)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId), fn ($query) => $query->whereRaw('1 = 0'))
            ->firstOrFail();
    }

    /**
     * @return array{doc_num: string|null, name: string|null}
     */
    private function recordPublicProperties(HrEmployee $employee): array
    {
        return [
            'doc_num' => $employee->doc_num,
            'name' => $employee->full_name,
        ];
    }

    /**
     * @return array{doc_num: string|null, name: string|null, restored_by_user_doc_num: string|null, restored_at: string}
     */
    private function recordRestoreProperties(Request $request, HrEmployee $employee): array
    {
        $user = $request->user();

        return [
            ...$this->recordPublicProperties($employee),
            'restored_by_user_doc_num' => $user instanceof User ? $user->doc_num : null,
            'restored_at' => $employee->restored_at?->toJSON() ?? now()->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'hr', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
