<?php

namespace Modules\Core\Http\Requests\Currencies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'currencies.clone' : 'currencies.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => strtoupper(trim((string) $this->input('code'))),
            'minor_unit_name' => trim((string) $this->input('minor_unit_name')),
            'is_main' => $this->boolean('is_main'),
        ]);
    }

    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);

        return [
            'doc_number' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('currencies', 'doc_number')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:255'],
            // 'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z]{2,10}$/'],
            'code' => ['required', 'string', 'max:10'],
            'minor_unit_name' => ['nullable', 'string', 'max:255'],
            'minor_unit_factor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'is_main' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);

            if (Currency::query()->forCompany($companyId)->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', __('currencies.messages.code_used'));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('currencies.attributes.doc_number'),
            'name' => __('currencies.attributes.name'),
            'code' => __('currencies.attributes.code'),
            'minor_unit_name' => __('currencies.attributes.minor_unit_name'),
            'minor_unit_factor' => __('currencies.attributes.minor_unit_factor'),
            'is_main' => __('currencies.attributes.is_main'),
            'status' => __('currencies.attributes.status'),
            'notes' => __('currencies.attributes.notes'),
        ];
    }
}
