<?php

namespace Modules\HR\Http\Requests\Employees;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\DocumentNumberService;
use Modules\HR\Models\HrEmployee;

class UpdateHrEmployeeRequest extends StoreHrEmployeeRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employees.edit') && $this->canSubmitDocumentRows();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $employee = $this->route('employee');
        $key = $employee instanceof HrEmployee ? $employee->getKey() : null;
        $nationalIdRule = Rule::unique('hr_employees', 'national_id')->ignore($key)->withoutTrashed();

        $rules['submit_action'] = ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])];
        $rules['national_id'] = ['nullable', 'string', 'max:60', $nationalIdRule];
        $rules['employee_code'] = ['nullable', 'string', 'max:255', Rule::unique('hr_employees', 'employee_code')->ignore($key)->withoutTrashed()];
        $rules['work_email'] = ['nullable', 'email:rfc', 'max:255', Rule::unique('hr_employees', 'work_email')->ignore($key)->withoutTrashed()];
        $rules['email'] = ['nullable', 'email:rfc', 'max:255', Rule::unique('hr_employees', 'email')->ignore($key)->withoutTrashed()];
        $rules['social_insurance_number'] = [
            Rule::requiredIf(fn (): bool => $this->input('insurance_status') === 'subject'),
            'nullable',
            'string',
            'max:60',
            Rule::unique('hr_employees', 'social_insurance_number')->ignore($key)->withoutTrashed(),
        ];

        if ($this->canControlDocumentNumberForUpdate()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique('hr_employees', 'doc_number')->ignore($key)->withoutTrashed(),
            ];
        } else {
            unset($rules['doc_number']);
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $employee = $this->route('employee');

            if ($this->canControlDocumentNumberForUpdate()
                && $employee instanceof HrEmployee
                && ! $validator->errors()->has('doc_number')
                && $this->hasFilledDocumentNumberForUpdate()
            ) {
                $docNumber = (int) $this->input('doc_number');
                $docNum = app(DocumentNumberService::class)->format('hr_employees', $docNumber);

                if (HrEmployee::query()->where('doc_num', $docNum)->whereKeyNot($employee->getKey())->exists()) {
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
        });
    }

    private function canControlDocumentNumberForUpdate(): bool
    {
        return (bool) $this->user()?->can('hr.employees.document_number.control');
    }

    private function hasFilledDocumentNumberForUpdate(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }
}
