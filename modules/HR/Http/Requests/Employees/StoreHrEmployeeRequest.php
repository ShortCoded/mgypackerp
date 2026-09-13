<?php

namespace Modules\HR\Http\Requests\Employees;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Http\Requests\Concerns\ValidatesSelectableArchiveImages;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeBiometricMapping;
use Modules\HR\Models\HrSection;

class StoreHrEmployeeRequest extends FormRequest
{
    use NormalizesNumericInput;
    use ValidatesSelectableArchiveImages;

    /**
     * @var list<string>
     */
    protected array $dateFields = [
        'birth_date',
        'hire_date',
        'contract_start_date',
        'contract_end_date',
        'probation_end_date',
        'start_date',
        'end_date',
        'insurance_start_date',
        'insurance_end_date',
        'tax_start_date',
        'tax_end_date',
    ];

    public function authorize(): bool
    {
        if ($this->filled('clone_source_token')) {
            return (bool) $this->user()?->can('hr.employees.clone') && $this->canSubmitDocumentRows();
        }

        return (bool) $this->user()?->can('hr.employees.create') && $this->canSubmitDocumentRows();
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
            'employee_code' => ['nullable', 'string', 'max:255', Rule::unique('hr_employees', 'employee_code')->withoutTrashed()],
            'person_type' => ['required', 'string', Rule::in(['fixed_employee', 'regular_labor', 'casual_labor'])],
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'suspended', 'stopped', 'left'])],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'marital_status' => ['nullable', 'string', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'national_id' => ['nullable', 'string', 'max:60', $this->uniqueEmployeeRule('national_id')],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'contract_start_date' => ['nullable', 'date_format:Y-m-d'],
            'contract_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:contract_start_date'],
            'probation_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:hire_date'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'branch_doc_num' => ['nullable', 'string', Rule::exists('branches', 'doc_num')->where(fn ($query) => $query->where('company_id', $this->companyId())->where('status', 'active')->whereNull('deleted_at'))],
            'user_doc_num' => ['nullable', 'string', Rule::exists('users', 'doc_num')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('hr_employees', 'email')->withoutTrashed()],
            'work_email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('hr_employees', 'work_email')->withoutTrashed()],
            'personal_email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'alternate_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'department_doc_num' => ['nullable', 'string', Rule::exists('hr_departments', 'doc_num')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))],
            'section_doc_num' => ['nullable', 'string', Rule::exists('hr_sections', 'doc_num')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))],
            'job_doc_num' => ['nullable', 'string', Rule::exists('hr_jobs', 'doc_num')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))],
            'employment_type_doc_num' => ['nullable', 'string', Rule::exists('hr_employment_types', 'doc_num')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))],
            'nationality_doc_num' => ['nullable', 'string', Rule::exists('hr_nationalities', 'doc_num')->whereNull('deleted_at')],
            'hiring_status_doc_num' => ['nullable', 'string', Rule::exists('hr_hiring_statuses', 'doc_num')->whereNull('deleted_at')],
            'allowance_doc_num' => ['nullable', 'string', Rule::exists('hr_allowances', 'doc_num')->whereNull('deleted_at')],
            'default_shift_doc_num' => ['nullable', 'string', Rule::exists('hr_shifts', 'doc_num')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))],
            'photo_archive_file_doc_num' => ['nullable', 'string'],
            'signature_archive_file_doc_num' => ['nullable', 'string'],
            'attendance_tracking_enabled' => ['nullable', 'boolean'],
            'attendance_policy_type' => ['nullable', 'string', Rule::in(['fixed_shift', 'flexible_shift', 'scheduled_labor', 'attendance_only', 'manual_work_sessions'])],
            'allow_late_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'allow_early_leave_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'overtime_enabled' => ['nullable', 'boolean'],
            'insurance_status' => ['required', 'string', Rule::in(['subject', 'not_subject', 'suspended', 'ended'])],
            'social_insurance_number' => [Rule::requiredIf(fn (): bool => $this->input('insurance_status') === 'subject'), 'nullable', 'string', 'max:60', $this->uniqueEmployeeRule('social_insurance_number')],
            'insurance_office_doc_num' => [Rule::requiredIf(fn (): bool => $this->input('insurance_status') === 'subject'), 'nullable', 'string', Rule::exists('hr_insurance_offices', 'doc_num')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))],
            'insurance_start_date' => [Rule::requiredIf(fn (): bool => $this->input('insurance_status') === 'subject'), 'nullable', 'date_format:Y-m-d'],
            'insurance_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:insurance_start_date'],
            'insurance_contribution_wage' => [Rule::requiredIf(fn (): bool => $this->input('insurance_status') === 'subject'), 'nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,13}|\d{0,13}\.\d{1,2})$/D'],
            'insurance_non_coverage_reason' => ['nullable', 'string', 'max:255'],
            'insurance_notes' => ['nullable', 'string'],
            'tax_status' => ['required', 'string', Rule::in(['subject', 'not_subject', 'suspended', 'ended'])],
            'tax_start_date' => [Rule::requiredIf(fn (): bool => $this->input('tax_status') === 'subject'), 'nullable', 'date_format:Y-m-d'],
            'tax_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tax_start_date'],
            'tax_special_treatment_reason' => ['nullable', 'string', 'max:255'],
            'tax_notes' => ['nullable', 'string'],
            'pay_basis' => ['required', 'string', Rule::in(['monthly_salary', 'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate'])],
            'payroll_currency_doc_num' => ['required', 'string', Rule::exists('currencies', 'doc_num')->whereNull('deleted_at')],
            'exchange_rate' => ['required', 'numeric', 'gt:0', 'regex:/^(?:\d{1,12}|\d{0,12}\.\d{1,6})$/D'],
            'basic_salary' => ['nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,13}|\d{0,13}\.\d{1,2})$/D'],
            'weekly_wage' => ['nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,11}|\d{0,11}\.\d{1,4})$/D'],
            'daily_wage' => ['nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,11}|\d{0,11}\.\d{1,4})$/D'],
            'hourly_wage' => ['nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,11}|\d{0,11}\.\d{1,4})$/D'],
            'shift_wage' => ['nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,11}|\d{0,11}\.\d{1,4})$/D'],
            'piece_rate' => ['nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,11}|\d{0,11}\.\d{1,4})$/D'],
            'payment_method' => ['nullable', 'string', Rule::in(['cash', 'bank_transfer', 'wallet', 'other'])],
            'biometric_mappings' => ['nullable', 'array'],
            'biometric_mappings.*' => ['array:id,device_doc_num,biometric_code,is_active,_delete,notes'],
            'biometric_mappings.*.id' => ['nullable', 'integer'],
            'biometric_mappings.*.device_doc_num' => ['nullable', 'string', 'max:255'],
            'biometric_mappings.*.biometric_code' => ['nullable', 'string', 'max:120'],
            'biometric_mappings.*.is_active' => ['nullable', 'boolean'],
            'biometric_mappings.*._delete' => ['nullable', 'boolean'],
            'biometric_mappings.*.notes' => ['nullable', 'string'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['array:id,_delete,document_type_doc_num,document_number_text,title,archive_file_doc_num,file_label,issue_date,expires_at,alert_before_expiry_days,sort_order,notes'],
            'documents.*.id' => ['nullable', 'integer'],
            'documents.*._delete' => ['nullable', 'boolean'],
            'documents.*.document_type_doc_num' => ['nullable', 'string', Rule::exists('hr_document_types', 'doc_num')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))],
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
        $employee = $this->route('employee');
        $prepared = [
            'insurance_status' => $this->input('insurance_status', $employee instanceof HrEmployee ? $employee->insurance_status : 'not_subject'),
            'tax_status' => $this->input('tax_status', $employee instanceof HrEmployee ? $employee->tax_status : 'not_subject'),
        ];

        if ($this->exists('social_insurance_number')) {
            $prepared['social_insurance_number'] = $this->normalizeArabicIndicDigits($this->input('social_insurance_number'));
        }

        $this->merge($prepared);

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

        $this->normalizeNumericInput([
            'exchange_rate',
            'basic_salary',
            'weekly_wage',
            'daily_wage',
            'hourly_wage',
            'shift_wage',
            'piece_rate',
            'insurance_contribution_wage',
            'documents.*.alert_before_expiry_days',
        ]);

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
            $this->validateOrganizationDependencies($validator);
            $this->validateBiometricMappings($validator);
            $this->validateDocuments($validator);
            $this->validateNestedRowOwnership($validator);
            $this->validateUserLink($validator);
        });
    }

    protected function validateUserLink(Validator $validator, ?HrEmployee $employee = null): void
    {
        $docNum = trim((string) $this->input('user_doc_num'));

        if ($docNum === '' || $validator->errors()->has('user_doc_num')) {
            return;
        }

        $userId = User::query()->where('doc_num', $docNum)->value('id');

        if ($userId !== null && HrEmployee::withTrashed()
            ->where('user_id', $userId)
            ->when($employee instanceof HrEmployee, fn ($query) => $query->whereKeyNot($employee->getKey()))
            ->exists()) {
            $validator->errors()->add('user_doc_num', __('hr.employees.validation.user_already_linked'));
        }
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'exchange_rate.regex' => __('hr.employees.validation.exchange_rate_precision'),
            'basic_salary.regex' => __('hr.employees.validation.basic_salary_precision'),
            'weekly_wage.regex' => __('hr.employees.validation.wage_precision'),
            'daily_wage.regex' => __('hr.employees.validation.wage_precision'),
            'hourly_wage.regex' => __('hr.employees.validation.wage_precision'),
            'shift_wage.regex' => __('hr.employees.validation.wage_precision'),
            'piece_rate.regex' => __('hr.employees.validation.wage_precision'),
        ];
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
        return Rule::unique('hr_employees', $column)->withoutTrashed();
    }

    protected function canSubmitDocumentRows(): bool
    {
        foreach ((array) $this->input('documents', []) as $row) {
            if (! is_array($row) || (! $this->documentRowHasContent($row) && ! (bool) ($row['_delete'] ?? false))) {
                continue;
            }

            if ((bool) ($row['_delete'] ?? false)) {
                if (! $this->user()?->can('hr.employees.documents.delete')) {
                    return false;
                }

                continue;
            }

            if (! $this->user()?->can('hr.employees.documents.manage')) {
                return false;
            }
        }

        return true;
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
        $this->validateSelectableArchiveImage(
            $validator,
            'signature_archive_file_doc_num',
            $this->companyId(),
            __('hr.employees.validation.selected_signature_unavailable'),
        );
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

        if ($currency->is_main
            && app(NumericFormatService::class)->isValid($this->input('exchange_rate'))
            && ! app(NumericFormatService::class)->equivalent($this->input('exchange_rate'), '1')
        ) {
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
            $mappingId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;

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

            $device = HrBiometricDevice::withTrashed()
                ->where('company_id', $companyId)
                ->where('doc_num', $deviceDocNum)
                ->first();
            $existingMapping = $employeeId && $mappingId
                ? HrEmployeeBiometricMapping::withTrashed()
                    ->where('employee_id', $employeeId)
                    ->whereKey($mappingId)
                    ->first()
                : null;
            $isExistingDevice = $device instanceof HrBiometricDevice
                && $existingMapping instanceof HrEmployeeBiometricMapping
                && $existingMapping->biometric_device_id === $device->getKey();

            if (! $device instanceof HrBiometricDevice
                || (! $isExistingDevice && ($device->trashed() || $device->status !== 'active'))) {
                $validator->errors()->add("biometric_mappings.{$index}.device_doc_num", __('validation.exists', [
                    'attribute' => __('hr.employees.biometric.device'),
                ]));

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

    protected function validateNestedRowOwnership(Validator $validator): void
    {
        $employee = $this->route('employee');

        foreach (['biometric_mappings' => 'biometricMappings', 'documents' => 'documents'] as $input => $relationship) {
            foreach ((array) $this->input($input, []) as $index => $row) {
                $id = is_array($row) && isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;

                if ($id === null) {
                    continue;
                }

                if (! $employee instanceof HrEmployee || ! $employee->{$relationship}()->withTrashed()->whereKey($id)->exists()) {
                    $validator->errors()->add("{$input}.{$index}.id", __('validation.exists', [
                        'attribute' => "{$input}.{$index}.id",
                    ]));
                }
            }
        }
    }

    protected function validateOrganizationDependencies(Validator $validator): void
    {
        $departmentDocNum = $this->string('department_doc_num')->trim()->toString();
        $sectionDocNum = $this->string('section_doc_num')->trim()->toString();

        if ($departmentDocNum === '' || $sectionDocNum === '' || $validator->errors()->hasAny(['department_doc_num', 'section_doc_num'])) {
            return;
        }

        $matches = HrSection::query()
            ->where('doc_num', $sectionDocNum)
            ->whereHas('department', fn ($query) => $query->where('doc_num', $departmentDocNum))
            ->exists();

        if (! $matches) {
            $validator->errors()->add('section_doc_num', __('hr.employees.validation.section_department_mismatch'));
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

    private function normalizeArabicIndicDigits(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
