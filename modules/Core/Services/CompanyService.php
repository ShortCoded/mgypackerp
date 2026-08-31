<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Exceptions\CompanyDeleteBlockedException;
use Modules\Core\Exceptions\CompanyRestoreBlockedException;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;
use Modules\HR\Models\HrLookupModel;

class CompanyService
{
    /**
     * @var list<string>
     */
    private array $fillableFields = [
        'name',
        'legal_name',
        'commercial_name',
        'authorized_signatory_name',
        'authorized_signatory_title',
        'status',
        'is_main',
        'notes',
        'commercial_register_number',
        'commercial_register_office',
        'commercial_register_date',
        'commercial_register_expiry_date',
        'tax_card_number',
        'tax_file_number',
        'tax_office',
        'vat_registration_number',
        'industrial_register_number',
        'import_card_number',
        'export_card_number',
        'phone',
        'mobile',
        'hotline',
        'fax',
        'email',
        'website',
        'country_id',
        'governorate_id',
        'city_id',
        'area_id',
        'country',
        'governorate',
        'city',
        'area',
        'address',
        'postal_code',
        'map_url',
        'industry',
        'activity_type',
        'business_description',
    ];

    /**
     * @var array<string, array{column: string, model: class-string<HrLookupModel>}>
     */
    private array $locationFields = [
        'country_doc_num' => ['column' => 'country_id', 'model' => HrCountry::class],
        'governorate_doc_num' => ['column' => 'governorate_id', 'model' => HrGovernorate::class],
        'city_doc_num' => ['column' => 'city_id', 'model' => HrCity::class],
        'area_doc_num' => ['column' => 'area_id', 'model' => HrArea::class],
    ];

    /**
     * @var array<string, string>
     */
    private array $archiveFileFields = [
        'company_stamp_archive_file_doc_num' => 'company_stamp_archive_file_id',
        'authorized_signatory_signature_archive_file_doc_num' => 'authorized_signatory_signature_archive_file_id',
    ];

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
    ) {}

    public function canCreateCompany(): bool
    {
        $limit = $this->maxCompaniesLimit();

        return $limit === null || $this->nonDeletedCompaniesCount() < $limit;
    }

    public function ensureCanCreateCompany(): void
    {
        if (! $this->canCreateCompany()) {
            throw new DomainException(__('companies.messages.max_companies_reached'));
        }
    }

    /**
     * A null return value means company creation is unlimited. Null, zero, and negative
     * config values are intentionally treated as unlimited.
     */
    public function maxCompaniesLimit(): ?int
    {
        $limit = config('companies.max_companies');

        if ($limit === null) {
            return null;
        }

        $limit = (int) $limit;

        return $limit > 0 ? $limit : null;
    }

    public function nonDeletedCompaniesCount(): int
    {
        return (int) Company::withTrashed()
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{company: Company, old_main_company: Company|null}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber((int) $data['doc_number'])
                : $this->documentNumberService->next('companies', Company::class);
            $values = $this->normalizedValues($data);
            $oldMainCompany = null;

            $this->applyCreateMainRules($values);

            if (($data['logo'] ?? null) instanceof UploadedFile) {
                $values['logo'] = $this->storeLogo($data['logo']);
            }

            if (($data['favicon'] ?? null) instanceof UploadedFile) {
                $values['favicon'] = $this->storeFavicon($data['favicon']);
            }

            if (($values['is_main'] ?? false) === true && $values['status'] === 'active') {
                $oldMainCompany = Company::query()->main()->lockForUpdate()->first();
                $this->unsetAllMainCompanies();
            }

            $company = Company::query()->create([
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($company);

            return [
                'company' => $company->refresh(),
                'old_main_company' => $oldMainCompany,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{company: Company, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null, old_company_name: string, old_status: string|null, old_main: bool, old_main_company: Company|null, old_logo: string|null, new_logo: string|null, old_favicon: string|null, new_favicon: string|null}
     */
    public function update(Company $company, array $data): array
    {
        return DB::transaction(function () use ($company, $data): array {
            $oldDocNumber = $company->doc_number === null ? null : (int) $company->doc_number;
            $oldDocNum = $company->doc_num;
            $oldCompanyName = $company->name;
            $oldStatus = $company->status;
            $oldMain = (bool) $company->is_main;
            $oldMainCompany = null;
            $oldLogo = $company->logo;
            $oldFavicon = $company->favicon;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newDocNumber = $canChangeDocumentNumber ? (int) $data['doc_number'] : $oldDocNumber;
            $newDocNum = $canChangeDocumentNumber ? $this->documentNumberService->format('companies', $newDocNumber) : $oldDocNum;
            $newValues = $this->normalizedValues($data, $company);

            if ($canChangeDocumentNumber) {
                $newValues['doc_number'] = $newDocNumber;
                $newValues['doc_num'] = $newDocNum;
            }

            if (($data['logo'] ?? null) instanceof UploadedFile) {
                $newValues['logo'] = $this->storeLogo($data['logo']);
            } elseif (($data['remove_logo'] ?? false) === true) {
                $newValues['logo'] = null;
            }

            if (($data['favicon'] ?? null) instanceof UploadedFile) {
                $newValues['favicon'] = $this->storeFavicon($data['favicon']);
            } elseif (($data['remove_favicon'] ?? false) === true) {
                $newValues['favicon'] = null;
            }

            $this->applyUpdateMainRules($company, $newValues);

            $changes = $this->changedValues($company, $newValues);
            $changedFields = collect(array_keys($changes))
                ->map(fn (string $field): string => $field === 'doc_num' ? 'doc_number' : $field)
                ->unique()
                ->values()
                ->all();

            if ($changedFields === []) {
                return [
                    'company' => $company->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                    'old_company_name' => $oldCompanyName,
                    'old_status' => $oldStatus,
                    'old_main' => $oldMain,
                    'old_main_company' => null,
                    'old_logo' => $oldLogo,
                    'new_logo' => $company->logo,
                    'old_favicon' => $oldFavicon,
                    'new_favicon' => $company->favicon,
                ];
            }

            if (($newValues['is_main'] ?? false) === true && ($newValues['status'] ?? $company->status) === 'active') {
                $oldMainCompany = Company::query()
                    ->main()
                    ->whereKeyNot($company->getKey())
                    ->lockForUpdate()
                    ->first();

                $this->unsetOtherMainCompanies($company);
            }

            $this->crudAudit->saveUpdate($company, [
                ...$newValues,
            ]);

            if (array_key_exists('logo', $newValues) && $oldLogo && $oldLogo !== $company->logo) {
                $this->deleteLogo($oldLogo);
            }

            if (array_key_exists('favicon', $newValues) && $oldFavicon && $oldFavicon !== $company->favicon) {
                $this->deleteFavicon($oldFavicon);
            }

            return [
                'company' => $company->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
                'old_company_name' => $oldCompanyName,
                'old_status' => $oldStatus,
                'old_main' => $oldMain,
                'old_main_company' => $oldMainCompany,
                'old_logo' => $oldLogo,
                'new_logo' => $company->logo,
                'old_favicon' => $oldFavicon,
                'new_favicon' => $company->favicon,
            ];
        });
    }

    public function delete(Company $company): void
    {
        $this->ensureCompanyCanBeDeleted($company, single: true);

        DB::transaction(function () use ($company): void {
            $this->crudAudit->softDelete($company);
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $docNums = array_values(array_unique(array_filter($docNums, fn (string $docNum): bool => trim($docNum) !== '')));
            $companies = Company::query()
                ->whereIn('doc_num', $docNums)
                ->get();

            if ($companies->count() !== count($docNums)) {
                throw new DomainException(__('companies.messages.bulk_delete_invalid_selection'));
            }

            $this->ensureCompaniesCanBeBulkDeleted($companies);

            $deleted = 0;

            foreach ($companies as $company) {
                $this->crudAudit->softDelete($company);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(Company $company): Company
    {
        return DB::transaction(function () use ($company): Company {
            $company = Company::withTrashed()
                ->lockForUpdate()
                ->whereKey($company->getKey())
                ->firstOrFail();

            $this->ensureCompanyCanBeRestored($company);

            $this->crudAudit->restore($company, auth()->id());

            return $company->refresh();
        });
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function manualDocumentNumber(int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format('companies', $docNumber),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data, ?Company $existing = null): array
    {
        $values = [];

        foreach ($this->fillableFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $values[$field] = match ($field) {
                'name' => $this->normalizeString((string) $data[$field]),
                'status' => (string) $data[$field],
                'is_main' => (bool) $data[$field],
                'commercial_register_date', 'commercial_register_expiry_date' => $this->normalizeNullableString($data[$field] ?? null),
                'email' => $this->normalizeEmail($data[$field] ?? null),
                default => $this->normalizeNullableString($data[$field] ?? null),
            };
        }

        foreach ($this->locationFields as $requestField => $location) {
            if (! array_key_exists($requestField, $data)) {
                continue;
            }

            $values[$location['column']] = $this->resolveLocationId($location['model'], $data[$requestField] ?? null);
        }

        foreach ($this->archiveFileFields as $requestField => $column) {
            if (! array_key_exists($requestField, $data)) {
                continue;
            }

            $values[$column] = $this->resolveArchiveFileId($data[$requestField] ?? null);
        }

        if (! array_key_exists('status', $values)) {
            $values['status'] = $existing?->status ?? 'active';
        }

        if (! array_key_exists('country', $values)) {
            $values['country'] = $existing?->country ?? 'Egypt';
        }

        if (! array_key_exists('is_main', $values)) {
            $values['is_main'] = (bool) ($existing?->is_main ?? false);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function applyCreateMainRules(array &$values): void
    {
        $hasActiveMain = Company::query()->main()->exists();

        if (($values['is_main'] ?? false) === true && $values['status'] !== 'active') {
            throw new DomainException(__('companies.messages.main_requires_active'));
        }

        if (! $hasActiveMain && $values['status'] === 'active') {
            $values['is_main'] = true;

            return;
        }

        if (! $hasActiveMain && $values['status'] !== 'active') {
            throw new DomainException(__('companies.messages.main_company_required'));
        }
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function applyUpdateMainRules(Company $company, array &$newValues): void
    {
        $newStatus = (string) ($newValues['status'] ?? $company->status);
        $newMain = (bool) ($newValues['is_main'] ?? $company->is_main);

        if ($newMain && $newStatus !== 'active') {
            throw new DomainException(__('companies.messages.main_requires_active'));
        }

        if ($company->is_main && $company->status === 'active' && (! $newMain || $newStatus !== 'active')) {
            throw new DomainException(__('companies.messages.main_company_delete_blocked'));
        }

        if (! Company::query()->main()->whereKeyNot($company->getKey())->exists() && $newStatus === 'active') {
            $newValues['is_main'] = true;
        }
    }

    private function unsetOtherMainCompanies(Company $company): void
    {
        Company::query()
            ->whereKeyNot($company->getKey())
            ->where('is_main', true)
            ->update([
                'is_main' => false,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
    }

    private function unsetAllMainCompanies(): void
    {
        Company::query()
            ->where('is_main', true)
            ->update([
                'is_main' => false,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(Company $company, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $value) {
            $current = $company->{$field};

            $archiveRequestField = array_search($field, $this->archiveFileFields, true);

            if (is_string($archiveRequestField)) {
                if ((string) ($current ?? '') === (string) ($value ?? '')) {
                    continue;
                }

                $ids = array_values(array_filter([$current, $value]));
                $files = $ids === []
                    ? collect()
                    : ArchiveFile::withTrashed()->whereIn('id', $ids)->get(['id', 'doc_num'])->keyBy('id');
                $changes[$archiveRequestField] = [
                    'old' => $files->get($current)?->doc_num,
                    'new' => $files->get($value)?->doc_num,
                ];

                continue;
            }

            if ($field === 'is_main') {
                if ((bool) $current !== (bool) $value) {
                    $changes[$field] = [
                        'old' => (bool) $current,
                        'new' => (bool) $value,
                    ];
                }

                continue;
            }

            if (in_array($field, ['commercial_register_date', 'commercial_register_expiry_date'], true)) {
                $current = $current?->toDateString();
            }

            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                $changes[$field] = [
                    'old' => $this->safeChangedValue($field, $current),
                    'new' => $this->safeChangedValue($field, $value),
                ];
            }
        }

        return $changes;
    }

    private function safeChangedValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['logo', 'favicon'], true)) {
            return is_string($value) && $value !== ''
                ? pathinfo($value, PATHINFO_EXTENSION)
                : null;
        }

        return $value;
    }

    private function storeLogo(UploadedFile $logo): string
    {
        $extension = mb_strtolower($logo->getClientOriginalExtension());
        $filename = Str::uuid()->toString().($extension === '' ? '' : ".{$extension}");

        return $logo->storeAs('company-logos', $filename, 'public');
    }

    private function storeFavicon(UploadedFile $favicon): string
    {
        $extension = mb_strtolower($favicon->getClientOriginalExtension());
        $filename = Str::uuid()->toString().($extension === '' ? '' : ".{$extension}");

        return $favicon->storeAs('company-favicons', $filename, 'public');
    }

    private function deleteLogo(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function deleteFavicon(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function ensureCompanyCanBeDeleted(Company $company, bool $single = false): void
    {
        if ($company->trashed()) {
            throw new DomainException(__('companies.messages.already_deleted'));
        }

        if ($company->is_main && $company->status === 'active') {
            throw new CompanyDeleteBlockedException(
                $single ? __('companies.messages.main_company_delete_blocked') : __('companies.messages.bulk_delete_blocked', [
                    'records' => $this->blockedRecordsLabel([$this->blockedRecord($company, 'main_company', 0)]),
                ]),
                [$this->blockedRecord($company, 'main_company', 0)],
            );
        }

        $relatedCount = $this->relatedRecordsCount($company);

        if ($relatedCount > 0) {
            throw new CompanyDeleteBlockedException(
                $single ? __('companies.messages.related_data_exists') : __('companies.messages.bulk_delete_blocked', [
                    'records' => $this->blockedRecordsLabel([$this->blockedRecord($company, 'related_data', $relatedCount)]),
                ]),
                [$this->blockedRecord($company, 'related_data', $relatedCount)],
            );
        }
    }

    private function ensureCompanyCanBeRestored(Company $company): void
    {
        if (! $company->trashed()) {
            throw new CompanyRestoreBlockedException(
                __('companies.messages.restore_not_allowed'),
                'already_active',
            );
        }

        $conflictFields = $this->restoreConflictFields($company);

        if ($conflictFields !== []) {
            throw new CompanyRestoreBlockedException(
                __('companies.messages.restore_conflict'),
                'unique_data_conflict',
                $conflictFields,
            );
        }

        if ($company->is_main
            && $company->status === 'active'
            && Company::query()->main()->whereKeyNot($company->getKey())->lockForUpdate()->exists()
        ) {
            throw new CompanyRestoreBlockedException(
                __('companies.messages.restore_main_conflict'),
                'main_company_conflict',
                ['is_main'],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function restoreConflictFields(Company $company): array
    {
        $conflicts = [];

        foreach ($this->restoreUniqueFields() as $field) {
            if (! Schema::hasColumn('companies', $field)) {
                continue;
            }

            $value = $company->{$field};

            if ($value === null || $value === '') {
                continue;
            }

            if (Company::query()->where($field, $value)->whereKeyNot($company->getKey())->lockForUpdate()->exists()) {
                $conflicts[] = $field;
            }
        }

        return $conflicts;
    }

    /**
     * @return list<string>
     */
    private function restoreUniqueFields(): array
    {
        return [
            'name',
            'commercial_register_number',
            'tax_card_number',
            'vat_registration_number',
            'national_id',
            'email',
            'doc_number',
            'doc_num',
        ];
    }

    /**
     * @param  iterable<int, Company>  $companies
     */
    private function ensureCompaniesCanBeBulkDeleted(iterable $companies): void
    {
        $blockedRecords = [];

        foreach ($companies as $company) {
            try {
                $this->ensureCompanyCanBeDeleted($company);
            } catch (CompanyDeleteBlockedException $exception) {
                array_push($blockedRecords, ...$exception->blockedRecords);
            }
        }

        if ($blockedRecords !== []) {
            throw new CompanyDeleteBlockedException(
                __('companies.messages.bulk_delete_blocked', [
                    'records' => $this->blockedRecordsLabel($blockedRecords),
                ]),
                $blockedRecords,
            );
        }
    }

    private function relatedRecordsCount(Company $company): int
    {
        $count = 0;

        foreach ($this->tablesWithCompanyId() as $table) {
            try {
                $count += (int) DB::table($table)->where('company_id', $company->getKey())->count();
            } catch (\Throwable) {
                continue;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function tablesWithCompanyId(): array
    {
        try {
            $tables = Schema::getTables();
        } catch (\Throwable) {
            return [];
        }

        return collect($tables)
            ->map(fn (array $table): ?string => $table['name'] ?? $table['tablename'] ?? null)
            ->filter(fn (?string $table): bool => is_string($table) && $table !== 'companies' && Schema::hasColumn($table, 'company_id'))
            ->values()
            ->all();
    }

    /**
     * @return array{doc_num: string|null, company_name: string, reason: string, related_records_count: int}
     */
    private function blockedRecord(Company $company, string $reason, int $relatedCount): array
    {
        return [
            'doc_num' => $company->doc_num,
            'company_name' => $company->name,
            'reason' => $reason,
            'related_records_count' => $relatedCount,
        ];
    }

    /**
     * @param  list<array{doc_num: string|null, company_name: string, reason: string, related_records_count: int}>  $blockedRecords
     */
    private function blockedRecordsLabel(array $blockedRecords): string
    {
        return collect($blockedRecords)
            ->map(fn (array $record): string => trim(($record['doc_num'] ? $record['doc_num'].' - ' : '').$record['company_name']))
            ->implode(', ');
    }

    private function normalizeString(string $value): string
    {
        return trim($value);
    }

    private function normalizeEmail(mixed $email): ?string
    {
        $email = $this->normalizeNullableString($email);

        return $email === null ? null : mb_strtolower($email);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  class-string<HrLookupModel>  $model
     */
    private function resolveLocationId(string $model, mixed $docNum): ?int
    {
        $docNum = $this->normalizeNullableString($docNum);

        if ($docNum === null) {
            return null;
        }

        /** @var HrLookupModel|null $record */
        $record = $model::query()
            ->select(['id'])
            ->where('doc_num', $docNum)
            ->first();

        return $record ? (int) $record->getKey() : null;
    }

    private function resolveArchiveFileId(mixed $docNum): ?int
    {
        $docNum = $this->normalizeNullableString($docNum);

        if ($docNum === null) {
            return null;
        }

        return ArchiveFile::query()
            ->where('doc_num', $docNum)
            ->value('id');
    }
}
