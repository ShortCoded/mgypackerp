<?php

namespace Modules\HR\Services;

use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Events\HrEmployeeCreated;
use Modules\HR\Models\HrAllowance;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDocumentType;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeBiometricMapping;
use Modules\HR\Models\HrEmployeeDocument;
use Modules\HR\Models\HrEmploymentType;
use Modules\HR\Models\HrHiringStatus;
use Modules\HR\Models\HrJob;
use Modules\HR\Models\HrNationality;
use Modules\HR\Models\HrSection;
use Modules\HR\Models\HrShift;

class HrEmployeeService
{
    /**
     * @var list<string>
     */
    private array $fillableFields = [
        'employee_code',
        'full_name',
        'person_type',
        'status',
        'gender',
        'birth_date',
        'marital_status',
        'national_id',
        'hire_date',
        'contract_start_date',
        'contract_end_date',
        'probation_end_date',
        'work_email',
        'email',
        'personal_email',
        'phone',
        'mobile',
        'alternate_phone',
        'address',
        'basic_salary',
        'attendance_tracking_enabled',
        'attendance_policy_type',
        'allow_late_minutes',
        'allow_early_leave_minutes',
        'overtime_enabled',
        'pay_basis',
        'exchange_rate',
        'weekly_wage',
        'daily_wage',
        'hourly_wage',
        'shift_wage',
        'piece_rate',
        'payment_method',
        'emergency_contact_name',
        'emergency_contact_phone',
        'start_date',
        'end_date',
        'notes',
    ];

    /**
     * @var array<string, array{column: string, model: class-string}>
     */
    private array $relationFields = [
        'branch_doc_num' => ['column' => 'branch_id', 'model' => Branch::class],
        'department_doc_num' => ['column' => 'department_id', 'model' => HrDepartment::class],
        'section_doc_num' => ['column' => 'section_id', 'model' => HrSection::class],
        'job_doc_num' => ['column' => 'job_id', 'model' => HrJob::class],
        'employment_type_doc_num' => ['column' => 'employment_type_id', 'model' => HrEmploymentType::class],
        'nationality_doc_num' => ['column' => 'nationality_id', 'model' => HrNationality::class],
        'hiring_status_doc_num' => ['column' => 'hiring_status_id', 'model' => HrHiringStatus::class],
        'allowance_doc_num' => ['column' => 'allowance_id', 'model' => HrAllowance::class],
        'default_shift_doc_num' => ['column' => 'default_shift_id', 'model' => HrShift::class],
        'payroll_currency_doc_num' => ['column' => 'payroll_currency_id', 'model' => Currency::class],
        'photo_archive_file_doc_num' => ['column' => 'photo_archive_file_id', 'model' => ArchiveFile::class],
        'signature_archive_file_doc_num' => ['column' => 'signature_archive_file_id', 'model' => ArchiveFile::class],
    ];

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly NumericFormatService $numericFormatter,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): HrEmployee
    {
        return DB::transaction(function () use ($data): HrEmployee {
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber((int) $data['doc_number'])
                : $this->documentNumberService->next('hr_employees', HrEmployee::class);
            $values = $this->normalizedValues($data);

            $employee = HrEmployee::query()->create([
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($employee);

            $employee = $employee->refresh();
            $this->syncBiometricMappings($employee, $data['biometric_mappings'] ?? []);
            $this->syncDocuments($employee, $data['documents'] ?? []);

            Event::dispatch(new HrEmployeeCreated($employee));

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{employee: HrEmployee, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null, old_name: string, document_row_ids: array<int|string, int>}
     */
    public function update(HrEmployee $employee, array $data): array
    {
        return DB::transaction(function () use ($employee, $data): array {
            $oldDocNumber = $employee->doc_number === null ? null : (int) $employee->doc_number;
            $oldDocNum = $employee->doc_num;
            $oldName = $employee->full_name;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newDocNumber = $canChangeDocumentNumber ? (int) $data['doc_number'] : $oldDocNumber;
            $newDocNum = $canChangeDocumentNumber ? $this->documentNumberService->format('hr_employees', $newDocNumber) : $oldDocNum;
            $newValues = $this->normalizedValues($data, $employee);

            if ($canChangeDocumentNumber) {
                $newValues['doc_number'] = $newDocNumber;
                $newValues['doc_num'] = $newDocNum;
            }

            $changes = $this->changedValues($employee, $newValues);
            $changedFields = collect($this->changedFieldNames($employee, $newValues))
                ->map(fn (string $field): string => $field === 'doc_num' ? 'doc_number' : $field)
                ->unique()
                ->values()
                ->all();

            if ($changedFields !== []) {
                $this->crudAudit->saveUpdate($employee, $newValues);
                $employee = $employee->refresh();
            }

            $mappingsChanged = $this->syncBiometricMappings($employee, $data['biometric_mappings'] ?? null);
            $documentSync = $this->syncDocuments($employee, $data['documents'] ?? null);
            $documentsChanged = $documentSync['changed'];

            if ($changedFields === [] && ! $mappingsChanged && ! $documentsChanged) {
                return [
                    'employee' => $employee->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                    'old_name' => $oldName,
                    'document_row_ids' => $documentSync['row_ids'],
                ];
            }

            return [
                'employee' => $employee->refresh(),
                'changed' => true,
                'changed_fields' => array_values(array_unique([
                    ...$changedFields,
                    ...($mappingsChanged ? ['biometric_mappings'] : []),
                    ...($documentsChanged ? ['documents'] : []),
                ])),
                'changes' => [
                    ...$changes,
                    ...($mappingsChanged ? ['biometric_mappings' => ['old' => null, 'new' => __('hr.employees.biometric.title')]] : []),
                    ...($documentsChanged ? ['documents' => ['old' => null, 'new' => __('hr.employees.documents.title')]] : []),
                ],
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
                'old_name' => $oldName,
                'document_row_ids' => $documentSync['row_ids'],
            ];
        });
    }

    public function delete(HrEmployee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            $this->crudAudit->softDelete($employee);
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $records = HrEmployee::query()
                ->where('company_id', app(OperatingCompanyContextService::class)->currentCompanyId())
                ->whereIn('doc_num', $docNums)
                ->get();
            $deleted = 0;

            foreach ($records as $employee) {
                $this->crudAudit->softDelete($employee);
                $deleted++;
            }

            return $deleted;
        });
    }

    /**
     * @param  list<string>  $publicUuids
     */
    public function bulkRestore(array $publicUuids): int
    {
        return DB::transaction(function () use ($publicUuids): int {
            $records = HrEmployee::onlyTrashed()
                ->where('company_id', app(OperatingCompanyContextService::class)->currentCompanyId())
                ->whereIn('public_uuid', $publicUuids)
                ->get();
            $restored = 0;

            foreach ($records as $employee) {
                $this->ensureEmployeeCanBeRestored($employee);
                $this->crudAudit->restore($employee, auth()->id());
                $restored++;
            }

            return $restored;
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkUpdateStatus(array $docNums, string $status): int
    {
        return DB::transaction(function () use ($docNums, $status): int {
            $records = HrEmployee::query()
                ->where('company_id', app(OperatingCompanyContextService::class)->currentCompanyId())
                ->whereIn('doc_num', $docNums)
                ->get();
            $updated = 0;

            foreach ($records as $employee) {
                if ((string) $employee->status === $status) {
                    continue;
                }

                $this->crudAudit->saveUpdate($employee, ['status' => $status]);
                $updated++;
            }

            return $updated;
        });
    }

    public function restore(HrEmployee $employee): HrEmployee
    {
        return DB::transaction(function () use ($employee): HrEmployee {
            $this->ensureEmployeeCanBeRestored($employee);

            $this->crudAudit->restore($employee, auth()->id());

            return $employee->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeDocument(HrEmployee $employee, array $data): HrEmployeeDocument
    {
        return DB::transaction(function () use ($employee, $data): HrEmployeeDocument {
            $archiveFile = $this->documentArchiveFile($employee, $data['archive_file_doc_num'] ?? null);
            $uploadedFile = $data['file'] ?? null;
            $documentNumber = $this->documentNumberService->next('hr_employee_documents', HrEmployeeDocument::class);

            if (! $archiveFile instanceof ArchiveFile && $uploadedFile instanceof UploadedFile) {
                $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
                $path = $uploadedFile->storeAs(
                    "hr/employee-documents/{$employee->doc_num}",
                    $documentNumber['doc_num'].'-'.Str::random(12).($extension !== '' ? ".{$extension}" : ''),
                    'public',
                );
            } elseif ($archiveFile instanceof ArchiveFile) {
                $extension = strtolower((string) $archiveFile->extension);
                $path = $archiveFile->path;
            } else {
                throw new DomainException(__('hr.employees.documents.validation.file_required'));
            }

            $documentTypeId = $this->resolveDocNumId(HrDocumentType::class, $data['document_type_doc_num'] ?? null);
            $originalName = $archiveFile?->original_name ?? $uploadedFile?->getClientOriginalName() ?? null;
            $mimeType = $archiveFile?->mime_type ?? $uploadedFile?->getClientMimeType();
            $size = $archiveFile?->size_bytes ?? $uploadedFile?->getSize() ?? 0;

            $document = HrEmployeeDocument::query()->create([
                'employee_id' => $employee->getKey(),
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'document_type_id' => $documentTypeId,
                'document_type' => (string) ($data['document_type'] ?? 'other'),
                'document_number_text' => $this->normalizeNullableString($data['document_number_text'] ?? null),
                'title' => $this->normalizeString((string) ($data['title'] ?? $originalName ?? __('hr.employees.documents.title'))),
                'archive_file_id' => $archiveFile?->getKey(),
                'file_path' => $path,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'extension' => $extension ?: null,
                'size' => $size ?: 0,
                'issue_date' => $this->normalizeNullableString($data['issue_date'] ?? null),
                'expires_at' => $this->normalizeNullableString($data['expires_at'] ?? null),
                'alert_before_expiry_days' => $this->normalizeNullableNumber($data['alert_before_expiry_days'] ?? null),
                'file_label' => $this->normalizeNullableString($data['file_label'] ?? null),
                'sort_order' => $this->normalizeNullableNumber($data['sort_order'] ?? null) ?? 0,
                'notes' => $this->normalizeNullableString($data['notes'] ?? null),
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($document);

            return $document->refresh();
        });
    }

    public function deleteDocument(HrEmployeeDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $this->crudAudit->softDelete($document);
        });
    }

    public function documentDownloadPath(HrEmployeeDocument $document): string
    {
        if ($document->archiveFile instanceof ArchiveFile) {
            if (! Storage::disk($document->archiveFile->disk)->exists($document->archiveFile->path)) {
                throw new DomainException(__('hr.employees.documents.messages.file_missing'));
            }

            return Storage::disk($document->archiveFile->disk)->path($document->archiveFile->path);
        }

        if (! Storage::disk('public')->exists($document->file_path)) {
            throw new DomainException(__('hr.employees.documents.messages.file_missing'));
        }

        return Storage::disk('public')->path($document->file_path);
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function manualDocumentNumber(int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format('hr_employees', $docNumber),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data, ?HrEmployee $existing = null): array
    {
        $values = [];

        foreach ($this->fillableFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $values[$field] = match ($field) {
                'full_name' => $this->normalizeString((string) $data[$field]),
                'basic_salary' => $this->normalizeNullableDecimal($data[$field] ?? null, 2),
                'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate' => $this->normalizeNullableDecimal($data[$field] ?? null, 4),
                'exchange_rate' => $this->normalizeNullableDecimal($data[$field] ?? null, 6),
                'graduation_year', 'allow_late_minutes', 'allow_early_leave_minutes' => $this->normalizeNullableNumber($data[$field] ?? null),
                'attendance_tracking_enabled', 'overtime_enabled' => (bool) ($data[$field] ?? false),
                'status', 'gender', 'marital_status', 'person_type', 'attendance_policy_type', 'pay_basis', 'payment_method' => $this->normalizeNullableString($data[$field] ?? null),
                default => $this->normalizeNullableString($data[$field] ?? null),
            };
        }

        if (array_key_exists('full_name', $values)) {
            $values['name'] = $values['full_name'];
        }

        foreach ($this->relationFields as $requestField => $relation) {
            if (! array_key_exists($requestField, $data)) {
                continue;
            }

            $values[$relation['column']] = $this->resolveDocNumId($relation['model'], $data[$requestField] ?? null);
        }

        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        if ($companyId !== null) {
            $values['company_id'] = (int) $companyId;
        } elseif ($existing?->company_id) {
            $values['company_id'] = $existing->company_id;
        }

        if (! array_key_exists('status', $values)) {
            $values['status'] = $existing?->status ?? 'active';
        }

        return $values;
    }

    /**
     * @param  class-string  $model
     */
    private function resolveDocNumId(string $model, mixed $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return $model::query()
            ->where('doc_num', $docNum)
            ->value('id');
    }

    private function documentArchiveFile(HrEmployee $employee, mixed $docNum): ?ArchiveFile
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        $companyId = $employee->company_id ?: app(OperatingCompanyContextService::class)->currentCompanyId();

        if (! $companyId) {
            return null;
        }

        return app(FilePickerService::class)->selectableFileByPublicId($docNum, (int) $companyId, FilePickerService::AcceptDocument);
    }

    private function syncBiometricMappings(HrEmployee $employee, mixed $rows): bool
    {
        if (! is_array($rows)) {
            return false;
        }

        $changed = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);
            $deviceId = $this->resolveDocNumId(HrBiometricDevice::class, $row['device_doc_num'] ?? null);
            $code = $this->normalizeNullableString($row['biometric_code'] ?? null);

            $mapping = $id
                ? $employee->biometricMappings()->withTrashed()->whereKey($id)->first()
                : null;

            if ($delete) {
                if ($mapping instanceof HrEmployeeBiometricMapping && ! $mapping->trashed()) {
                    $this->crudAudit->softDelete($mapping);
                    $changed = true;
                }

                continue;
            }

            if ($deviceId === null && $code === null) {
                continue;
            }

            $values = [
                'company_id' => $employee->company_id,
                'employee_id' => $employee->getKey(),
                'biometric_device_id' => $deviceId,
                'biometric_code' => (string) $code,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'notes' => $this->normalizeNullableString($row['notes'] ?? null),
                'updated_by' => auth()->id(),
            ];

            if ($mapping instanceof HrEmployeeBiometricMapping) {
                if ($mapping->trashed()) {
                    $this->crudAudit->restore($mapping, auth()->id());
                    $changed = true;
                }

                if ($this->changedValuesForModel($mapping, $values) !== []) {
                    $this->crudAudit->saveUpdate($mapping, $values);
                    $changed = true;
                }

                continue;
            }

            HrEmployeeBiometricMapping::query()->create([
                ...$values,
                'created_by' => auth()->id(),
            ]);
            $changed = true;
        }

        return $changed;
    }

    /**
     * @return array{changed: bool, row_ids: array<int|string, int>}
     */
    private function syncDocuments(HrEmployee $employee, mixed $rows): array
    {
        if (! is_array($rows)) {
            return ['changed' => false, 'row_ids' => []];
        }

        $changed = false;
        $rowIds = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);
            $document = $id
                ? $employee->documents()->withTrashed()->whereKey($id)->first()
                : null;

            if ($delete) {
                if ($document instanceof HrEmployeeDocument && ! $document->trashed()) {
                    $this->crudAudit->softDelete($document);
                    $changed = true;
                }

                continue;
            }

            if (! $this->documentRowHasContent($row)) {
                continue;
            }

            $values = $this->documentRowValues($employee, $row);

            if ($document instanceof HrEmployeeDocument) {
                if ($document->trashed()) {
                    $this->crudAudit->restore($document, auth()->id());
                    $changed = true;
                }

                if ($this->changedValuesForModel($document, $values) !== []) {
                    $this->crudAudit->saveUpdate($document, [
                        ...$values,
                        'updated_by' => auth()->id(),
                    ]);
                    $changed = true;
                }

                $rowIds[$index] = (int) $document->getKey();

                continue;
            }

            $documentNumber = $this->documentNumberService->next('hr_employee_documents', HrEmployeeDocument::class);
            $document = HrEmployeeDocument::query()->create([
                ...$values,
                'employee_id' => $employee->getKey(),
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);
            $this->crudAudit->clearCreationUpdateAudit($document);
            $rowIds[$index] = (int) $document->getKey();
            $changed = true;
        }

        return ['changed' => $changed, 'row_ids' => $rowIds];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function documentRowValues(HrEmployee $employee, array $row): array
    {
        $archiveFile = $this->documentArchiveFile($employee, $row['archive_file_doc_num'] ?? null);

        if (! $archiveFile instanceof ArchiveFile) {
            throw new DomainException(__('hr.employees.documents.validation.file_required'));
        }

        $documentTypeId = $this->resolveDocNumId(HrDocumentType::class, $row['document_type_doc_num'] ?? null);
        $originalName = $archiveFile->original_name;
        $title = $this->normalizeNullableString($row['title'] ?? null)
            ?? $this->normalizeNullableString($row['file_label'] ?? null)
            ?? $originalName
            ?? __('hr.employees.documents.title');

        return [
            'document_type_id' => $documentTypeId,
            'document_type' => (string) ($row['document_type'] ?? 'other'),
            'document_number_text' => $this->normalizeNullableString($row['document_number_text'] ?? null),
            'title' => $this->normalizeString((string) $title),
            'archive_file_id' => $archiveFile->getKey(),
            'file_path' => $archiveFile->path,
            'original_name' => $originalName,
            'mime_type' => $archiveFile->mime_type,
            'extension' => strtolower((string) $archiveFile->extension) ?: null,
            'size' => $archiveFile->size_bytes ?: 0,
            'issue_date' => $this->normalizeNullableString($row['issue_date'] ?? null),
            'expires_at' => $this->normalizeNullableString($row['expires_at'] ?? null),
            'alert_before_expiry_days' => $this->normalizeNullableNumber($row['alert_before_expiry_days'] ?? null),
            'file_label' => $this->normalizeNullableString($row['file_label'] ?? null),
            'sort_order' => $this->normalizeNullableNumber($row['sort_order'] ?? null) ?? 0,
            'notes' => $this->normalizeNullableString($row['notes'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function documentRowHasContent(array $row): bool
    {
        foreach (['document_type_doc_num', 'document_number_text', 'title', 'archive_file_doc_num', 'file_label', 'issue_date', 'expires_at', 'alert_before_expiry_days', 'notes'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValuesForModel(Model $model, array $values): array
    {
        $changes = [];

        foreach ($values as $field => $value) {
            if ($this->comparable($field, $model->getAttribute($field)) !== $this->comparable($field, $value)) {
                $changes[$field] = [
                    'old' => $model->getAttribute($field),
                    'new' => $value,
                ];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(HrEmployee $employee, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $newValue) {
            if ($this->comparable($field, $employee->getAttribute($field)) === $this->comparable($field, $newValue)) {
                continue;
            }

            if (str_ends_with($field, '_id')) {
                continue;
            }

            $changes[$field] = [
                'old' => $employee->getAttribute($field),
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return list<string>
     */
    private function changedFieldNames(HrEmployee $employee, array $newValues): array
    {
        $fields = [];

        foreach ($newValues as $field => $newValue) {
            if ($this->comparable($field, $employee->getAttribute($field)) !== $this->comparable($field, $newValue)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function comparable(string $field, mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (in_array($field, [
            'basic_salary',
            'exchange_rate',
            'weekly_wage',
            'daily_wage',
            'hourly_wage',
            'shift_wage',
            'piece_rate',
        ], true)) {
            return $this->numericFormatter->normalize($value) ?? '';
        }

        return trim((string) $value);
    }

    private function normalizeString(string $value): string
    {
        return trim($value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeNullableNumber(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeNullableDecimal(mixed $value, int $precision): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->numericFormatter->normalizeToScale($value, $precision);
    }

    private function ensureEmployeeCanBeRestored(HrEmployee $employee): void
    {
        if (! $employee->trashed()) {
            throw new DomainException(__('hr.messages.restore_not_allowed'));
        }

        if ($this->restoreConflictFields($employee) !== []) {
            throw new DomainException(__('hr.employees.messages.restore_conflict'));
        }
    }

    /**
     * @return list<string>
     */
    private function restoreConflictFields(HrEmployee $employee): array
    {
        $conflictFields = [];

        foreach (['doc_number', 'doc_num', 'employee_code', 'national_id', 'email', 'work_email'] as $field) {
            $value = $employee->getAttribute($field);

            if (! $this->hasComparableValue($value)) {
                continue;
            }

            if ($this->activeEmployeeExists(function (Builder $query) use ($field, $value): void {
                $query->where($field, $value);
            })) {
                $conflictFields[] = $field;
            }
        }

        return $conflictFields;
    }

    private function activeEmployeeExists(callable $constraint): bool
    {
        $query = HrEmployee::query()
            ->whereNull('deleted_at')
            ->lockForUpdate();
        $constraint($query);

        return $query->exists();
    }

    private function hasComparableValue(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }
}
