<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\ValidatesSelectableArchiveImages;
use Modules\Core\Models\Company;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreCompanyRequest extends FormRequest
{
    use ValidatesSelectableArchiveImages;

    /**
     * @var list<string>
     */
    private array $dateFields = [
        'commercial_register_date',
        'commercial_register_expiry_date',
    ];

    public function authorize(): bool
    {
        if ($this->filled('clone_source_token')) {
            return (bool) $this->user()?->can('companies.clone');
        }

        return (bool) $this->user()?->can('companies.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->withoutTrashed()],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'commercial_name' => ['nullable', 'string', 'max:255'],
            'authorized_signatory_name' => ['nullable', 'string', 'max:255'],
            'authorized_signatory_title' => ['nullable', 'string', 'max:255'],
            'company_stamp_archive_file_doc_num' => ['nullable', 'string'],
            'authorized_signatory_signature_archive_file_doc_num' => ['nullable', 'string'],
            'logo' => [
                'nullable',
                'image',
                'mimes:'.implode(',', config('archive.logo.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp'])),
                'max:'.((int) config('archive.logo.max_file_size_kib', 2048)),
            ],
            'favicon' => [
                'nullable',
                'file',
                'extensions:'.implode(',', config('archive.favicon.allowed_extensions', ['ico', 'png', 'jpg', 'jpeg', 'webp'])),
                'max:'.((int) config('archive.favicon.max_file_size_kib', 1024)),
            ],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'show_company_identity_on_prints' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'commercial_register_number' => ['nullable', 'string', 'max:100', Rule::unique('companies', 'commercial_register_number')->withoutTrashed()],
            'commercial_register_office' => ['nullable', 'string', 'max:255'],
            'commercial_register_date' => ['nullable', 'date_format:Y-m-d'],
            'commercial_register_expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:commercial_register_date'],
            'tax_card_number' => ['nullable', 'string', 'max:100', Rule::unique('companies', 'tax_card_number')->withoutTrashed()],
            'tax_file_number' => ['nullable', 'string', 'max:100'],
            'tax_office' => ['nullable', 'string', 'max:255'],
            'vat_registration_number' => ['nullable', 'string', 'max:100', Rule::unique('companies', 'vat_registration_number')->withoutTrashed()],
            'industrial_register_number' => ['nullable', 'string', 'max:100'],
            'import_card_number' => ['nullable', 'string', 'max:100'],
            'export_card_number' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'hotline' => ['nullable', 'string', 'max:50'],
            'fax' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('companies', 'email')->withoutTrashed()],
            'website' => ['nullable', 'url', 'max:255'],
            'country' => ['nullable', 'string', 'max:100'],
            'governorate' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'country_doc_num' => ['nullable', 'string', Rule::exists('hr_countries', 'doc_num')->whereNull('deleted_at')],
            'governorate_doc_num' => ['nullable', 'string', Rule::exists('hr_governorates', 'doc_num')->whereNull('deleted_at')],
            'city_doc_num' => ['nullable', 'string', Rule::exists('hr_cities', 'doc_num')->whereNull('deleted_at')],
            'area_doc_num' => ['nullable', 'string', Rule::exists('hr_areas', 'doc_num')->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:1000'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'map_url' => ['nullable', 'url', 'max:1000'],
            'industry' => ['nullable', 'string', 'max:255'],
            'activity_type' => ['nullable', 'string', 'max:255'],
            'business_description' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique('companies', 'doc_number')->withoutTrashed(),
            ];
        }

        if ($this->canControlMainCompany()) {
            $rules['is_main'] = ['nullable', 'boolean'];
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
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->canControlDocumentNumber()
                || $validator->errors()->has('doc_number')
                || ! $this->hasFilledDocumentNumber()
            ) {
                return;
            }

            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format('companies', $docNumber);

            if (Company::query()->where('doc_num', $docNum)->exists()) {
                $validator->errors()->add('doc_number', __('companies.validation.doc_number_unique'));
            }
        });

        $validator->after(function (Validator $validator): void {
            $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);

            $this->validateSelectableArchiveImage(
                $validator,
                'company_stamp_archive_file_doc_num',
                $companyId,
                __('companies.validation.selected_stamp_unavailable'),
            );
            $this->validateSelectableArchiveImage(
                $validator,
                'authorized_signatory_signature_archive_file_doc_num',
                $companyId,
                __('companies.validation.selected_signature_unavailable'),
            );
        });

        $validator->after(function (Validator $validator): void {
            if (! $this->canControlMainCompany() || ! $this->boolean('is_main')) {
                return;
            }

            if ($this->string('status')->toString() !== 'active') {
                $validator->errors()->add('is_main', __('companies.validation.main_requires_active'));
            }
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

        if (! $this->canControlMainCompany()) {
            unset($data['is_main']);
        } elseif (array_key_exists('is_main', $data)) {
            $data['is_main'] = $this->boolean('is_main');
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('companies.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doc_number.regex' => __('companies.validation.doc_number_numeric'),
            'doc_number.unique' => __('companies.validation.doc_number_unique'),
            'name.unique' => __('companies.validation.name_unique'),
            'email.unique' => __('companies.validation.email_unique'),
            'logo.mimes' => __('archive.invalid_file_type'),
            'logo.max' => __('archive.logo_file_too_large', ['size' => (int) config('archive.logo.max_file_size_mib', 2)]),
            'favicon.extensions' => __('archive.invalid_file_type'),
            'favicon.max' => __('companies.validation.favicon_too_large', ['size' => (int) config('archive.favicon.max_file_size_mib', 1)]),
            'commercial_register_date.date_format' => __('companies.validation.invalid_date_format'),
            'commercial_register_expiry_date.date_format' => __('companies.validation.invalid_date_format'),
            'commercial_register_number.unique' => __('companies.validation.commercial_register_number_unique'),
            'country_doc_num.exists' => __('companies.validation.country_invalid'),
            'governorate_doc_num.exists' => __('companies.validation.governorate_invalid'),
            'city_doc_num.exists' => __('companies.validation.city_invalid'),
            'area_doc_num.exists' => __('companies.validation.area_invalid'),
            'tax_card_number.unique' => __('companies.validation.tax_card_number_unique'),
            'vat_registration_number.unique' => __('companies.validation.vat_registration_number_unique'),
        ];
    }

    private function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can('companies.document_number.control');
    }

    private function canControlMainCompany(): bool
    {
        return (bool) $this->user()?->can('companies.main.control');
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }
}
