<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\CompaniesDataTable;
use Modules\Core\Exceptions\CompanyDeleteBlockedException;
use Modules\Core\Exceptions\CompanyRestoreBlockedException;
use Modules\Core\Http\Requests\BulkDeleteCompaniesRequest;
use Modules\Core\Http\Requests\StoreCompanyRequest;
use Modules\Core\Http\Requests\UpdateCompanyDocumentNumberSettingsRequest;
use Modules\Core\Http\Requests\UpdateCompanyRequest;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyDocumentNumberSettingsService;
use Modules\Core\Services\CompanyService;
use Modules\Core\Services\SettingService;
use Throwable;

class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request, CompanyDocumentNumberSettingsService $documentNumberSettings): View
    {
        return view('modules.core.companies.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.companies.index'),
            'canCreateCompany' => $this->companies->canCreateCompany(),
            'documentNumberSettings' => $documentNumberSettings->current(),
        ]);
    }

    public function data(Request $request, CompaniesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View|RedirectResponse
    {
        if (! $this->companies->canCreateCompany()) {
            return $this->companyLimitReachedResponse();
        }

        return $this->formView('create');
    }

    public function show(Request $request, string $company): View
    {
        $company = $this->companyByDocNum($company, withTrashed: true);
        $this->abortIfTrashedCompanyIsNotViewable($request, $company);
        $this->logActivity($request, 'companies.view', $this->companyPublicProperties($company));

        return $this->formView('view', $company);
    }

    public function edit(Company $company): View
    {
        return $this->formView('edit', $company);
    }

    public function clone(Company $company): View|RedirectResponse
    {
        if (! $this->companies->canCreateCompany()) {
            return $this->companyLimitReachedResponse();
        }

        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $company->doc_num);

        return $this->formView('clone', $company, $cloneSourceToken);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $this->companies->ensureCanCreateCompany();
            $result = $this->companies->create($request->validated());
        } catch (QueryException $exception) {
            $this->throwCompanyValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $company = $result['company'];

        if ($cloneSource instanceof Company) {
            $this->logActivity($request, 'companies.clone', ActivityLogProperties::crudCloned(
                'companies',
                ActivityLogProperties::record('companies', $cloneSource->name, $cloneSource->doc_num),
                $company->name,
                $company->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        } else {
            $this->logActivity($request, 'companies.create', ActivityLogProperties::crudCreated(
                'companies',
                $company->name,
                $company->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        }

        if ($result['old_main_company'] instanceof Company && $company->is_main) {
            $this->logMainCompanyChanged($request, $result['old_main_company'], $company);
        }

        $uploadedLogo = $request->file('logo');

        if ($uploadedLogo instanceof UploadedFile && $company->logo) {
            $this->logActivity($request, 'companies.logo.upload', $this->logoProperties($company, $uploadedLogo));
        }

        $uploadedFavicon = $request->file('favicon');

        if ($uploadedFavicon instanceof UploadedFile && $company->favicon) {
            $this->logActivity($request, 'companies.favicon.upload', $this->faviconProperties($company, $uploadedFavicon));
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof Company ? __('companies.messages.cloned') : __('companies.messages.created'),
            ...$this->saveActionResponse($request, $company, 'store'),
            'data' => [
                'doc_num' => $company->doc_num,
                'doc_number' => $company->doc_number,
                'logo_url' => $this->companyLogoUrl($company),
                'favicon_url' => $this->companyFaviconUrl($company),
                'urls' => $this->companyUrls($company),
            ],
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $this->authorizeSubmitAction($request, $this->submitAction($request));

        try {
            $result = $this->companies->update($company, $request->validated());
        } catch (QueryException $exception) {
            $this->throwCompanyValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $company = $result['company'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        if ($result['changed_fields'] !== []) {
            $this->logActivity($request, 'companies.update', ActivityLogProperties::crudUpdated(
                'companies',
                $company->name,
                $company->doc_num,
                $result['changes'],
                $this->submitActionProperties($request),
            ));
        }

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'companies.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'companies',
                $company->name,
                $result['old_doc_number'],
                $result['old_doc_num'],
                $company->doc_number,
                $company->doc_num,
            ));
        }

        if (in_array('status', $result['changed_fields'], true)) {
            $this->logActivity($request, 'companies.status.changed', ActivityLogProperties::statusChanged(
                'companies',
                $company->name,
                $company->doc_num,
                $result['old_status'],
                $company->status,
            ));
        }

        if (in_array('is_main', $result['changed_fields'], true) && $company->is_main) {
            $this->logMainCompanyChanged($request, $result['old_main_company'], $company);
        }

        if (in_array('logo', $result['changed_fields'], true)) {
            $uploadedLogo = $request->file('logo');

            if ($result['new_logo']) {
                $this->logActivity($request, 'companies.logo.upload', $this->logoProperties($company, $uploadedLogo instanceof UploadedFile ? $uploadedLogo : null));
            }

            if ($result['old_logo'] && $result['old_logo'] !== $result['new_logo']) {
                $this->logActivity($request, 'companies.logo.delete', [
                    'company_doc_num' => $company->doc_num,
                    'company_name' => $company->name,
                    'extension' => pathinfo($result['old_logo'], PATHINFO_EXTENSION) ?: null,
                ]);
            }
        }

        if (in_array('favicon', $result['changed_fields'], true)) {
            $uploadedFavicon = $request->file('favicon');

            if ($result['new_favicon']) {
                $this->logActivity($request, 'companies.favicon.upload', $this->faviconProperties($company, $uploadedFavicon instanceof UploadedFile ? $uploadedFavicon : null));
            }

            if ($result['old_favicon'] && $result['old_favicon'] !== $result['new_favicon']) {
                $this->logActivity($request, 'companies.favicon.delete', [
                    'company_doc_num' => $company->doc_num,
                    'company_name' => $company->name,
                    'extension' => pathinfo($result['old_favicon'], PATHINFO_EXTENSION) ?: null,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => __('companies.messages.updated'),
            ...$this->saveActionResponse($request, $company, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_number' => $company->doc_number,
                'doc_num' => $company->doc_num,
                'logo_url' => $this->companyLogoUrl($company),
                'favicon_url' => $this->companyFaviconUrl($company),
                'urls' => $this->companyUrls($company),
            ],
        ]);
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        try {
            $this->companies->delete($company);
        } catch (DomainException $exception) {
            if ($exception instanceof CompanyDeleteBlockedException) {
                $this->logDeleteBlocked($request, $exception);
            }

            return $this->domainError($exception);
        }

        $this->logActivity($request, 'companies.delete', ActivityLogProperties::crudDeleted(
            'companies',
            $company->name,
            $company->doc_num,
        ));

        return response()->json([
            'success' => true,
            'message' => __('companies.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteCompaniesRequest $request): JsonResponse
    {
        try {
            $docNums = $request->validated()['doc_nums'];
            $deleted = $this->companies->bulkDelete($docNums);
        } catch (DomainException $exception) {
            if ($exception instanceof CompanyDeleteBlockedException) {
                $this->logDeleteBlocked($request, $exception);
            }

            return $this->domainError($exception);
        }

        $this->logActivity($request, 'companies.bulk_delete', ActivityLogProperties::bulkDeleted(
            'companies',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('companies.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function restore(Request $request, string $company): JsonResponse
    {
        $company = $this->restoreCompanyByDocNum($company);

        try {
            $company = $this->companies->restore($company);
        } catch (CompanyRestoreBlockedException $exception) {
            if ($exception->isConflict()) {
                $this->logRestoreBlocked($request, $company, $exception);
            }

            return $this->restoreError($exception);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $restoredAt = $company->restored_at?->toJSON() ?? now()->toJSON();

        $this->logActivity($request, 'companies.restore', ActivityLogProperties::crudRestored(
            'companies',
            $company->name,
            $company->doc_num,
            $this->companyRestoreProperties($request, $company, $restoredAt),
        ));

        return response()->json([
            'success' => true,
            'message' => __('companies.messages.restored'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateCompanyDocumentNumberSettingsRequest $request,
        CompanyDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $result = $documentNumberSettings->update(
            $request->validated('prefix'),
            (int) $request->validated('padding'),
        );

        $this->logActivity($request, 'companies.document_number_settings.update', ActivityLogProperties::settingsUpdated(
            'companies',
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
            'message' => __('companies.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function formView(string $mode, ?Company $company = null, ?string $cloneSourceToken = null): View
    {
        $documentNumberSettings = app(CompanyDocumentNumberSettingsService::class)->current();
        $canControlDocumentNumber = (bool) auth()->user()?->can('companies.document_number.control');
        $canControlMainCompany = (bool) auth()->user()?->can('companies.main.control');

        return view('modules.core.companies.form', [
            'mode' => $mode,
            'company' => $company,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.companies.store') : route('admin.companies.update', $company?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $documentNumberSettings['prefix'],
            'documentNumberPadding' => $documentNumberSettings['padding'],
            'canControlDocumentNumber' => $canControlDocumentNumber,
            'canControlMainCompany' => $canControlMainCompany,
            'canCreateCompany' => $this->companies->canCreateCompany(),
            'metadata' => $this->companyMetadata($company),
            'breadcrumbs' => $this->companyBreadcrumbs($mode, $company),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function companyUrls(Company $company): array
    {
        return [
            'show' => route('admin.companies.show', $company->doc_num),
            'clone' => route('admin.companies.clone', $company->doc_num),
            'edit' => route('admin.companies.edit', $company->doc_num),
            'update' => route('admin.companies.update', $company->doc_num),
            'destroy' => route('admin.companies.destroy', $company->doc_num),
            'restore' => route('admin.companies.restore', $company->doc_num),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, Company $company, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        if ($operation === 'store'
            && in_array($action, ['save_new', 'save_clone'], true)
            && ! $this->companies->canCreateCompany()
        ) {
            return [
                ...$response,
                'redirect' => route('admin.companies.index'),
            ];
        }

        $redirect = match ($action) {
            'save_view' => route('admin.companies.show', $company->doc_num),
            'save_edit' => route('admin.companies.edit', $company->doc_num),
            'save_back' => route('admin.companies.index'),
            'save_new' => $operation === 'store' ? null : route('admin.companies.create'),
            'save_clone' => route('admin.companies.clone', $company->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $company) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';
        }

        return $response;
    }

    private function companyLimitReachedResponse(): RedirectResponse
    {
        if (auth()->user()?->can('companies.view')) {
            return redirect()
                ->route('admin.companies.index')
                ->with('warning', __('companies.messages.cannot_create_more_companies'));
        }

        abort(403, __('companies.messages.max_companies_reached'));
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'companies.view',
            'save_edit' => 'companies.edit',
            'save_back' => 'companies.view',
            'save_new' => $cloning ? 'companies.clone' : 'companies.create',
            'save_clone' => 'companies.clone',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('companies.messages.action_forbidden'));
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

    private function redirectAfterStore(Request $request, Company $company): string
    {
        if ($request->user()?->can('companies.edit')) {
            return route('admin.companies.edit', $company->doc_num);
        }

        if ($request->user()?->can('companies.view')) {
            return route('admin.companies.show', $company->doc_num);
        }

        return route('admin.companies.index');
    }

    private function cloneSourceFromRequest(StoreCompanyRequest $request): ?Company
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('companies.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('companies.messages.clone_not_allowed'),
            ]);
        }

        $sourceCompany = Company::query()
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $sourceCompany instanceof Company) {
            throw ValidationException::withMessages([
                'name' => __('companies.messages.clone_not_allowed'),
            ]);
        }

        return $sourceCompany;
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'companies.clone_sources.'.$token;
    }

    /**
     * @return array<string, string|null>
     */
    private function companyMetadata(?Company $company): array
    {
        if (! $company) {
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

        $company->loadMissing(['createdBy:id,name,doc_num', 'updatedBy:id,name,doc_num', 'deletedBy:id,name,doc_num', 'restoredBy:id,name,doc_num']);
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($company->createdBy),
            'created_at' => $settings->formatDateTime($company->created_at, ''),
            'updated_by' => $this->auditUserLabel($company->updatedBy),
            'updated_at' => $settings->formatDateTime($company->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($company->deletedBy),
            'deleted_at' => $settings->formatDateTime($company->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($company->restoredBy),
            'restored_at' => $settings->formatDateTime($company->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function companyBreadcrumbs(string $mode, ?Company $company): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $company?->doc_num,
                    'url' => $company ? route('admin.companies.show', $company->doc_num) : null,
                ],
                ['label' => __('companies.titles.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $company?->doc_num,
                    'url' => $company ? route('admin.companies.show', $company->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $company?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.companies.index', $extra);
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $exception->getMessage(),
        ];

        if ($exception instanceof CompanyDeleteBlockedException && $exception->blockedRecords !== []) {
            $payload['data'] = [
                'blocked_records' => $exception->blockedRecords,
            ];
        }

        return response()->json($payload, 422);
    }

    private function restoreError(CompanyRestoreBlockedException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'errors' => [
                'restore' => [$exception->getMessage()],
            ],
            'data' => [
                'conflict_type' => $exception->conflictType,
                'conflict_fields' => $exception->conflictFields,
            ],
        ], 422);
    }

    private function throwCompanyValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();
        $map = [
            'doc_number' => ['companies_doc_number_unique_active', 'companies_doc_num_unique_active', 'companies.doc_number', 'companies.doc_num'],
            'name' => ['companies_name_unique_active', 'companies.name'],
            'email' => ['companies_email_unique_active', 'companies.email'],
            'commercial_register_number' => ['companies_commercial_register_number_unique_active'],
            'tax_card_number' => ['companies_tax_card_number_unique_active'],
            'vat_registration_number' => ['companies_vat_registration_number_unique_active'],
            'national_id' => ['companies_national_id_unique_active'],
            'is_main' => ['companies_one_active_main_unique'],
        ];

        foreach ($map as $field => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    throw ValidationException::withMessages([
                        $field => __("companies.validation.{$field}_unique"),
                    ]);
                }
            }
        }
    }

    private function logDeleteBlocked(Request $request, CompanyDeleteBlockedException $exception): void
    {
        foreach ($exception->blockedRecords as $record) {
            $this->logActivity($request, 'companies.delete_blocked', [
                'doc_num' => $record['doc_num'] ?? null,
                'company_name' => $record['company_name'] ?? null,
                'reason' => $record['reason'] ?? null,
                'related_records_count' => $record['related_records_count'] ?? null,
            ], 'blocked');
        }
    }

    private function logRestoreBlocked(Request $request, Company $company, CompanyRestoreBlockedException $exception): void
    {
        $this->logActivity($request, 'companies.restore_blocked', [
            ...$this->companyPublicProperties($company),
            'conflict_type' => $exception->conflictType,
            'conflict_fields' => $exception->conflictFields,
        ], 'blocked');
    }

    /**
     * @return array{doc_num: string|null, company_name: string, email: string|null, phone: string|null, status: string|null, is_main: bool}
     */
    private function companyPublicProperties(Company $company): array
    {
        return [
            'doc_num' => $company->doc_num,
            'company_name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'status' => $company->status,
            'is_main' => (bool) $company->is_main,
        ];
    }

    private function restoreCompanyByDocNum(string $docNum): Company
    {
        return Company::onlyTrashed()
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? Company::query()
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function companyByDocNum(string $docNum, bool $withTrashed = false): Company
    {
        $query = $withTrashed ? Company::withTrashed() : Company::query();

        return $query->where('doc_num', $docNum)->firstOrFail();
    }

    private function abortIfTrashedCompanyIsNotViewable(Request $request, Company $company): void
    {
        abort_if(
            $company->trashed() && ! $request->user()?->can('companies.view_trashed'),
            404,
            __('companies.trash.view_forbidden'),
        );
    }

    /**
     * @return array{doc_num: string|null, company_name: string, restored_by_user_doc_num: string|null, restored_at: string}
     */
    private function companyRestoreProperties(Request $request, Company $company, string $restoredAt): array
    {
        $user = $request->user();

        return [
            'doc_num' => $company->doc_num,
            'company_name' => $company->name,
            'restored_by_user_doc_num' => $user instanceof User ? $user->doc_num : null,
            'restored_at' => $restoredAt,
        ];
    }

    private function logMainCompanyChanged(Request $request, ?Company $oldMainCompany, Company $newMainCompany): void
    {
        if ($oldMainCompany instanceof Company && $oldMainCompany->doc_num === $newMainCompany->doc_num) {
            return;
        }

        $this->logActivity($request, 'companies.main.changed', [
            'old_company_doc_num' => $oldMainCompany?->doc_num,
            'old_company_name' => $oldMainCompany?->name,
            'new_company_doc_num' => $newMainCompany->doc_num,
            'new_company_name' => $newMainCompany->name,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function logoProperties(Company $company, ?UploadedFile $logo = null): array
    {
        return [
            'company_doc_num' => $company->doc_num,
            'company_name' => $company->name,
            'logo_original_name' => $logo?->getClientOriginalName(),
            'logo_extension' => $logo?->getClientOriginalExtension() ?: ($company->logo ? pathinfo($company->logo, PATHINFO_EXTENSION) : null),
            'logo_size_bytes' => $logo?->getSize(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function faviconProperties(Company $company, ?UploadedFile $favicon = null): array
    {
        return [
            'company_doc_num' => $company->doc_num,
            'company_name' => $company->name,
            'favicon_original_name' => $favicon?->getClientOriginalName(),
            'favicon_extension' => $favicon?->getClientOriginalExtension() ?: ($company->favicon ? pathinfo($company->favicon, PATHINFO_EXTENSION) : null),
            'favicon_size_bytes' => $favicon?->getSize(),
        ];
    }

    private function companyLogoUrl(Company $company): ?string
    {
        return $company->logo ? Storage::disk('public')->url($company->logo) : null;
    }

    private function companyFaviconUrl(Company $company): ?string
    {
        return $company->favicon ? Storage::disk('public')->url($company->favicon) : null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'core', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
