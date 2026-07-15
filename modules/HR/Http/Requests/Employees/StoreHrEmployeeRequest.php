<?php

namespace Modules\HR\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeBiometricMapping;

class StoreHrEmployeeRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    protected array $dateFields = [
        'birth_date',
        'hire_date',
        'start_date',
        'end_date',
    ];

    public function authorize(): bool
    {
        if ($this->filled('clone_source_token')) {
            return (bool) $this->user()?->can('hr.employees.clone');
        }

        return (bool) $this->user()?->can('hr.employees.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
            'full_name' => ['required', 'string', 'max:255'],
            'person_type' => ['required', 'string', Rule::in(['fixed_employee', 'regular_labor', 'casual_labor'])],
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'suspended', 'stopped', 'left'])],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'marital_status' => ['nullable', 'string', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'national_id' => ['nullable', 'string', 'max:60', $this->uniqueEmployeeRule('national_id')],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'branch_doc_num' => ['nullable', 'string', Rule::exists('branches', 'doc_num')->whereNull('deleted_at')],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('hr_employees', 'email')->withoutTrashed()],
            'work_email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('hr_employees', 'work_email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'alternate_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'department_doc_num' => ['nullable', 'string', Rule::exists('hr_departments', 'doc_num')->whereNull('deleted_at')],
            'section_doc_num' => ['nullable', 'string', Rule::exists('hr_sections', 'doc_num')->whereNull('deleted_at')],
            'job_doc_num' => ['nullable', 'string', Rule::exists('hr_jobs', 'doc_num')->whereNull('deleted_at')],
            'employment_type_doc_num' => ['nullable', 'string', Rule::exists('hr_employment_types', 'doc_num')->whereNull('deleted_at')],
            'default_shift_doc_num' => ['nullable', 'string', Rule::exists('hr_shifts', 'doc_num')->whereNull('deleted_at')],
            'photo_archive_file_doc_num' => ['nullable', 'string'],
            'signature_archive_file_doc_num' => ['nullable', 'string'],
            'attendance_tracking_enabled' => ['nullable', 'boolean'],
            'attendance_policy_type' => ['nullable', 'string', Rule::in(['fixed_shift', 'flexible_shift', 'scheduled_labor', 'attendance_only', 'manual_work_sessions'])],
            'allow_late_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'allow_early_leave_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'overtime_enabled' => ['nullable', 'boolean'],
            'pay_basis' => ['required', 'string', Rule::in(['monthly_salary', 'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate'])],
            'payroll_currency_doc_num' => ['required', 'string', Rule::exists('currencies', 'doc_num')->whereNull('deleted_at')],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'weekly_wage' => ['nullable', 'numeric', 'min:0'],
            'daily_wage' => ['nullable', 'numeric', 'min:0'],
            'hourly_wage' => ['nullable', 'numeric', 'min:0'],
            'shift_wage' => ['nullable', 'numeric', 'min:0'],
            'piece_rate' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', Rule::in(['cash', 'bank_transfer', 'wallet', 'other'])],
            'biometric_mappings' => ['nullable', 'array'],
            'biometric_mappings.*.id' => ['nullable', 'integer'],
            'biometric_mappings.*.device_doc_num' => ['nullable', 'string', Rule::exists('hr_biometric_devices', 'doc_num')->whereNull('deleted_at')],
            'biometric_mappings.*.biometric_code' => ['nullable', 'string', 'max:120'],
            'biometric_mappings.*.is_active' => ['nullable', 'boolean'],
            'biometric_mappings.*._delete' => ['nullable', 'boolean'],
            'biometric_mappings.*.notes' => ['nullable', 'string'],
            'documents' => ['nullable', 'array'],
            'documents.*.id' => ['nullable', 'integer'],
            'documents.*._delete' => ['nullable', 'boolean'],
            'documents.*.document_type_doc_num' => ['nullable', 'string', Rule::exists('hr_document_types', 'doc_num')->whereNull('deleted_at')],
            'documents.*.document_number_text' => ['nullable', 'string', 'max:120'],
            'documents.*.title' => ['nullable', 'string', 'max:255'],
            'documents.*.archive_file_doc_num' => ['nullable', 'string'],
            'documents.*.file_label' => ['nullable', 'string', 'max:80'],
            'documents.*.issue_date' => ['nullable', 'date_format:Y-m-d'],
            'documents.*.expires_at' => ['nullable', 'date_format:Y-m-d'],
            'documents.*.alert_before_expiry_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'documents.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'documents.*.notes' => ['nullable', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique('hr_employees', 'doc_number')->withoutTrashed(),
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $dates = [];
        $dateFormat = app(DateFormatService::class);

        foreach ($this->dateFields as $field) {
            $value = $this->input($field);

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $normalized = $dateFormat->normalizeForStorage((string) $value);

            if ($normalized !== null) {
                $dates[$field] = $normalized;
            }
        }

        if ($dates !== []) {
            $this->merge($dates);
        }

        $this->normalizeDocumentDates($dateFormat);

        foreach (['exchange_rate', 'basic_salary', 'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => str_replace(',', '', (string) $this->input($field))]);
            }
        }

        $this->merge([
            'attendance_tracking_enabled' => $this->boolean('attendance_tracking_enabled'),
            'overtime_enabled' => $this->boolean('overtime_enabled'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->canControlDocumentNumber()
                && ! $validator->errors()->has('doc_number')
                && $this->hasFilledDocumentNumber()
            ) {
                $docNumber = (int) $this->input('doc_number');
                $docNum = app(DocumentNumberService::class)->format('hr_employees', $docNumber);

                if (HrEmployee::query()->where('doc_num', $docNum)->exists()) {
                    $validator->errors()->add('doc_number', __('hr.validation.doc_number_unique'));
                }
            }

            $this->validateSelectedPhoto($validator);
            $this->validateSelectedSignature($validator);
            $this->validateCurrencyAndPayBasis($validator);
            $this->validateBiometricMappings($validator);
            $this->validateDocuments($validator);
        });
    }

    /**
     * @param  string|null  $key
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        if (! $this->canControlDocumentNumber()) {
            unset($data['doc_number']);
        }

        if (array_key_exists('doc_number', $data) && ($data['doc_number'] === null || $data['doc_number'] === '')) {
            unset($data['doc_number']);
        } elseif (array_key_exists('doc_number', $data)) {
            $data['doc_number'] = (int) $data['doc_number'];
        }

        $data['attendance_tracking_enabled'] = (bool) ($data['attendance_tracking_enabled'] ?? false);
        $data['overtime_enabled'] = (bool) ($data['overtime_enabled'] ?? false);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('hr.employees.attributes');
    }

    private function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can('hr.employees.document_number.control');
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }

    protected function companyId(): ?int
    {
        return app(OperatingCompanyContextService::class)->currentCompanyId($this);
    }

    protected function uniqueEmployeeRule(string $column): mixed
    {
        $rule = Rule::unique('hr_employees', $column)->withoutTrashed();
        $companyId = $this->companyId();

        return $companyId ? $rule->where('company_id', $companyId) : $rule;
    }

    protected function validateSelectedPhoto(Validator $validator): void
    {
        if (! $this->filled('photo_archive_file_doc_num')) {
            return;
        }

        $companyId = $this->companyId();
        $file = $companyId
            ? app(FilePickerService::class)->selectableFileByPublicId((string) $this->input('photo_archive_file_doc_num'), $companyId, FilePickerService::AcceptImage)
            : null;

        if (! $file) {
            $validator->errors()->add('photo_archive_file_doc_num', __('hr.employees.validation.selected_photo_unavailable'));
        }
    }

    protected function validateSelectedSignature(Validator $validator): void
    {
        if (! $this->filled('signature_archive_file_doc_num')) {
            return;
        }

        $companyId = $this->companyId();
        $file = $companyId
            ? app(FilePickerService::class)->selectableFileByPublicId((string) $this->input('signature_archive_file_doc_num'), $companyId, FilePickerService::AcceptImage)
            : null;

        if (! $file) {
            $validator->errors()->add('signature_archive_file_doc_num', __('hr.employees.validation.selected_signature_unavailable'));
        }
    }

    protected function validateCurrencyAndPayBasis(Validator $validator): void
    {
        $companyId = $this->companyId();
        $currencyDocNum = trim((string) $this->input('payroll_currency_doc_num'));
        $currency = $companyId && $currencyDocNum !== ''
            ? Currency::query()->forCompany($companyId)->active()->where('doc_num', $currencyDocNum)->first()
            : null;

        if (! $currency instanceof Currency) {
            $validator->errors()->add('payroll_currency_doc_num', __('hr.employees.messages.currency_unavailable'));

            return;
        }

        if ($currency->is_main && is_numeric($this->input('exchange_rate')) && abs(((float) $this->input('exchange_rate')) - 1.0) > 0.000001) {
            $validator->errors()->add('exchange_rate', __('hr.employees.messages.main_currency_exchange_rate_must_be_one'));
        }

        $payBasisField = match ((string) $this->input('pay_basis')) {
            'monthly_salary' => 'basic_salary',
            'weekly_wage' => 'weekly_wage',
            'daily_wage' => 'daily_wage',
            'hourly_wage' => 'hourly_wage',
            'shift_wage' => 'shift_wage',
            'piece_rate' => 'piece_rate',
            default => null,
        };

        if ($payBasisField && ! $this->filled($payBasisField)) {
            $validator->errors()->add($payBasisField, __('validation.required', ['attribute' => __("hr.employees.attributes.{$payBasisField}")]));
        }
    }

    protected function validateBiometricMappings(Validator $validator): void
    {
        $companyId = $this->companyId();
        $employee = $this->route('employee');
        $employeeId = $employee instanceof HrEmployee ? $employee->getKey() : null;
        $seen = [];

        foreach ((array) $this->input('biometric_mappings', []) as $index => $row) {
            if (! is_array($row) || (bool) ($row['_delete'] ?? false)) {
                continue;
            }

            $deviceDocNum = trim((string) ($row['device_doc_num'] ?? ''));
            $code = trim((string) ($row['biometric_code'] ?? ''));
            $isActive = filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if ($deviceDocNum === '' && $code === '') {
                continue;
            }

            if ($deviceDocNum === '') {
                $validator->errors()->add("biometric_mappings.{$index}.device_doc_num", __('hr.employees.validation.fingerprint_device_required'));

                continue;
            }

            if ($code === '') {
                $validator->errors()->add("biometric_mappings.{$index}.biometric_code", __('hr.employees.validation.fingerprint_code_required'));

                continue;
            }

            $duplicateKey = $deviceDocNum.'|'.$code;
            if ($isActive) {
                if (isset($seen[$duplicateKey])) {
                    $validator->errors()->add("biometric_mappings.{$index}.biometric_code", __('hr.employees.validation.biometric_duplicate'));

                    continue;
                }

                $seen[$duplicateKey] = true;
            }

            $exists = HrEmployeeBiometricMapping::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('biometric_code', $code)
                ->whereHas('device', fn ($query) => $query->where('doc_num', $deviceDocNum))
                ->when($employeeId, fn ($query) => $query->where('employee_id', '!=', $employeeId))
                ->exists();

            if ($isActive && $exists) {
                $validator->errors()->add("biometric_mappings.{$index}.biometric_code", __('hr.employees.validation.biometric_duplicate'));
            }
        }
    }

    private function normalizeDocumentDates(DateFormatService $dateFormat): void
    {
        $documents = $this->input('documents');

        if (! is_array($documents)) {
            return;
        }

        foreach ($documents as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (['issue_date', 'expires_at'] as $field) {
                $value = $row[$field] ?? null;

                if ($value === null || trim((string) $value) === '') {
                    continue;
                }

                $normalized = $dateFormat->normalizeForStorage((string) $value);

                if ($normalized !== null) {
                    $documents[$index][$field] = $normalized;
                }
            }
        }

        $this->merge(['documents' => $documents]);
    }

    protected function validateDocuments(Validator $validator): void
    {
        $companyId = $this->companyId();

        foreach ((array) $this->input('documents', []) as $index => $row) {
            if (! is_array($row) || (bool) ($row['_delete'] ?? false)) {
                continue;
            }

            if (! $this->documentRowHasContent($row)) {
                continue;
            }

            $documentTypeDocNum = trim((string) ($row['document_type_doc_num'] ?? ''));
            $archiveFileDocNum = trim((string) ($row['archive_file_doc_num'] ?? ''));
            $issueDate = trim((string) ($row['issue_date'] ?? ''));
            $expiresAt = trim((string) ($row['expires_at'] ?? ''));

            if ($documentTypeDocNum === '') {
                $validator->errors()->add("documents.{$index}.document_type_doc_num", __('validation.required', ['attribute' => __('hr.employees.documents.attributes.document_type_doc_num')]));
            }

            if ($archiveFileDocNum === '') {
                $validator->errors()->add("documents.{$index}.archive_file_doc_num", __('hr.employees.documents.validation.file_required'));
            } elseif (! $companyId || ! app(FilePickerService::class)->selectableFileByPublicId($archiveFileDocNum, $companyId, FilePickerService::AcceptDocument)) {
                $validator->errors()->add("documents.{$index}.archive_file_doc_num", __('hr.employees.validation.selected_file_type_not_allowed'));
            }

            if ($issueDate !== '' && $expiresAt !== '' && $expiresAt < $issueDate) {
                $validator->errors()->add("documents.{$index}.expires_at", __('validation.after_or_equal', [
                    'attribute' => __('hr.employees.documents.attributes.expires_at'),
                    'date' => __('hr.employees.documents.attributes.issue_date'),
                ]));
            }
        }
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
}
