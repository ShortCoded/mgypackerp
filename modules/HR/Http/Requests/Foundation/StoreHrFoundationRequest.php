<?php

namespace Modules\HR\Http\Requests\Foundation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrFoundationModel;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class StoreHrFoundationRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        $definition = $this->definition();

        if ($this->filled('clone_source_token')) {
            return (bool) $this->user()?->can($definition->permission('clone'));
        }

        return (bool) $this->user()?->can($definition->permission('create'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $definition = $this->definition();
        $nameUnique = Rule::unique($definition->table, 'name')->withoutTrashed();

        if ($definition->companyScoped) {
            $nameUnique->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId());
        }

        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255', $nameUnique],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique($definition->table, 'doc_number')->withoutTrashed(),
            ];
        }

        foreach ($definition->fields as $field) {
            $rules[(string) $field['name']] = $this->rulesForField($field);

            if (($field['type'] ?? null) === 'weekdays') {
                $rules[(string) $field['name'].'.*'] = ['string', Rule::in($field['options'] ?? [])];
            }
        }

        if ($definition->hasTaxBrackets) {
            $rules = [
                ...$rules,
                'tax_brackets' => ['required', 'array', 'min:1'],
                'tax_brackets.*.public_uuid' => ['nullable', 'uuid'],
                'tax_brackets.*.from_amount' => ['required', 'numeric', 'min:0', 'regex:/^(?:\d{1,13}|\d{0,13}\.\d{1,2})$/D'],
                'tax_brackets.*.to_amount' => ['nullable', 'numeric', 'gt:tax_brackets.*.from_amount', 'regex:/^(?:\d{1,13}|\d{0,13}\.\d{1,2})$/D'],
                'tax_brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^(?:\d{1,3}|\d{0,3}\.\d{1,4})$/D'],
                'tax_brackets.*.notes' => ['nullable', 'string'],
            ];
        }

        if ($definition->hasInsuranceComponents) {
            $rules = [
                ...$rules,
                'insurance_components' => ['required', 'array', 'min:1'],
                'insurance_components.*.public_uuid' => ['nullable', 'uuid', 'distinct'],
                'insurance_components.*.name' => ['required', 'string', 'max:255'],
                'insurance_components.*.employee_rate' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^(?:\d{1,3}|\d{0,3}\.\d{1,4})$/D'],
                'insurance_components.*.employer_rate' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^(?:\d{1,3}|\d{0,3}\.\d{1,4})$/D'],
                'insurance_components.*.calculation_basis' => ['required', 'string', Rule::in(['contribution_wage'])],
                'insurance_components.*.is_active' => ['required', 'boolean'],
                'insurance_components.*.notes' => ['nullable', 'string'],
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $numericFields = [];

        foreach ($this->definition()->fields as $field) {
            if (in_array($field['type'] ?? null, ['number', 'decimal'], true)) {
                $numericFields[] = (string) $field['name'];
            }
        }

        if ($this->definition()->hasTaxBrackets) {
            $numericFields = [
                ...$numericFields,
                'tax_brackets.*.from_amount',
                'tax_brackets.*.to_amount',
                'tax_brackets.*.rate',
            ];
        }

        if ($this->definition()->hasInsuranceComponents) {
            $numericFields = [
                ...$numericFields,
                'insurance_components.*.employee_rate',
                'insurance_components.*.employer_rate',
            ];
        }

        $this->normalizeNumericInput($numericFields);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator): mixed => $this->validateDocumentNumber($validator),
            fn (Validator $validator): mixed => $this->validateInsuranceComponents($validator),
            fn (Validator $validator): mixed => $this->validateTaxBrackets($validator),
            fn (Validator $validator): mixed => $this->validatePolicyPeriod($validator),
        ];
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

        foreach ($this->definition()->fields as $field) {
            $name = (string) $field['name'];

            if (($field['type'] ?? null) === 'checkbox') {
                $data[$name] = $this->boolean($name);
            }

            if (($field['type'] ?? null) === 'weekdays') {
                $data[$name] = array_values((array) ($data[$name] ?? []));
            }
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('hr.foundation.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('hr.validation.name_unique'),
            'doc_number.regex' => __('hr.validation.doc_number_numeric'),
            'doc_number.unique' => __('hr.validation.doc_number_unique'),
        ];
    }

    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->fromRouteName($this->route()?->getName());
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<mixed>
     */
    private function rulesForField(array $field): array
    {
        $rules = $field['rules'] ?? ['nullable', 'string'];

        if (($field['unique'] ?? false) === true) {
            $unique = Rule::unique($this->definition()->table, (string) ($field['column'] ?? $field['name']))->withoutTrashed();

            if ($this->definition()->companyScoped) {
                $unique->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId());
            }

            $rules[] = $unique;
        }

        if (($field['type'] ?? null) !== 'relation') {
            return $rules;
        }

        /** @var class-string<HrFoundationModel> $model */
        $model = $field['model'];
        $table = (new $model)->getTable();

        $exists = Rule::exists($table, 'doc_num')->whereNull('deleted_at');

        if (($field['active_only'] ?? false) === true) {
            $exists = $exists->where('status', 'active');
        }

        if (($field['company_scoped'] ?? false) === true) {
            $exists = $exists->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId());
        }

        if (($field['postable_only'] ?? false) === true) {
            $exists = $exists->where('is_group', false);
        }

        return [
            ...$rules,
            $exists,
        ];
    }

    private function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can($this->definition()->permission('document_number.control'));
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }

    private function validateTaxBrackets(Validator $validator): void
    {
        if (! $this->definition()->hasTaxBrackets || $validator->errors()->has('tax_brackets')) {
            return;
        }

        $rows = array_values((array) $this->input('tax_brackets', []));
        $previousUpper = null;

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! is_numeric($row['from_amount'] ?? null)) {
                continue;
            }

            $lower = (string) $row['from_amount'];
            $upper = is_numeric($row['to_amount'] ?? null) ? (string) $row['to_amount'] : null;

            if ($index === 0 && bccomp($lower, '0', 2) !== 0) {
                $validator->errors()->add("tax_brackets.{$index}.from_amount", __('hr.validation.tax_bracket_must_start_at_zero'));
            }

            if ($upper !== null && bccomp($upper, $lower, 2) <= 0) {
                $validator->errors()->add("tax_brackets.{$index}.to_amount", __('hr.validation.tax_bracket_reversed'));
            }

            if ($previousUpper === null && $index > 0) {
                $validator->errors()->add("tax_brackets.{$index}.from_amount", __('hr.validation.tax_bracket_after_open_ended'));
            } elseif ($previousUpper !== null && bccomp($lower, $previousUpper, 2) < 0) {
                $validator->errors()->add("tax_brackets.{$index}.from_amount", __('hr.validation.tax_bracket_overlap'));
            } elseif ($previousUpper !== null && bccomp($lower, $previousUpper, 2) > 0) {
                $validator->errors()->add("tax_brackets.{$index}.from_amount", __('hr.validation.tax_bracket_gap'));
            }

            if ($upper === null && $index !== array_key_last($rows)) {
                $validator->errors()->add("tax_brackets.{$index}.to_amount", __('hr.validation.tax_bracket_open_ended_final'));
            }

            $previousUpper = $upper;
        }
    }

    private function validateDocumentNumber(Validator $validator): void
    {
        if (! $this->canControlDocumentNumber()
            || $validator->errors()->has('doc_number')
            || ! $this->hasFilledDocumentNumber()
        ) {
            return;
        }

        $definition = $this->definition();
        $docNumber = (int) $this->input('doc_number');
        $docNum = app(DocumentNumberService::class)->format($definition->documentKey, $docNumber);

        if ($definition->modelClass::query()->where('doc_num', $docNum)->exists()) {
            $validator->errors()->add('doc_number', __('hr.validation.doc_number_unique'));
        }
    }

    private function validateInsuranceComponents(Validator $validator): void
    {
        if (! $this->definition()->hasInsuranceComponents || $validator->errors()->has('insurance_components')) {
            return;
        }

        $employeeTotal = '0.0000';
        $employerTotal = '0.0000';

        foreach ((array) $this->input('insurance_components', []) as $row) {
            if (! is_array($row) || ! filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOL)) {
                continue;
            }

            if (is_numeric($row['employee_rate'] ?? null)) {
                $employeeTotal = bcadd($employeeTotal, (string) $row['employee_rate'], 4);
            }
            if (is_numeric($row['employer_rate'] ?? null)) {
                $employerTotal = bcadd($employerTotal, (string) $row['employer_rate'], 4);
            }
        }

        if (bccomp($employeeTotal, '100', 4) > 0) {
            $validator->errors()->add('insurance_components', __('hr.validation.insurance_employee_total_max'));
        }
        if (bccomp($employerTotal, '100', 4) > 0) {
            $validator->errors()->add('insurance_components', __('hr.validation.insurance_employer_total_max'));
        }
        if (bccomp(bcadd($employeeTotal, $employerTotal, 4), '100', 4) > 0) {
            $validator->errors()->add('insurance_components', __('hr.validation.insurance_combined_total_max'));
        }
    }

    private function validatePolicyPeriod(Validator $validator): void
    {
        $definition = $this->definition();

        if (! in_array($definition->key, ['social-insurance-policies', 'employment-tax-policies'], true)
            || $this->input('status') !== 'active'
            || $validator->errors()->hasAny(['effective_from', 'effective_to'])) {
            return;
        }

        $effectiveFrom = $this->string('effective_from')->toString();
        $effectiveTo = $this->string('effective_to')->toString();
        $query = $definition->modelClass::query()
            ->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId())
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $effectiveFrom));

        if ($effectiveTo !== '') {
            $query->whereDate('effective_from', '<=', $effectiveTo);
        }

        if ($query->exists()) {
            $validator->errors()->add('effective_from', __('hr.validation.policy_period_overlap'));
        }
    }
}
