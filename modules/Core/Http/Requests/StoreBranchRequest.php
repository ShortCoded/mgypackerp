<?php

namespace Modules\Core\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyAccessService;
use Modules\Core\Services\DocumentNumberService;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->filled('clone_source_token')) {
            return (bool) $this->user()?->can('branches.clone');
        }

        return (bool) $this->user()?->can('branches.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'company_doc_num' => [
                'required',
                'string',
                Rule::exists('companies', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', 'string', Rule::in(Branch::types())],
            'address' => ['nullable', 'string'],
            'attendance_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:attendance_longitude'],
            'attendance_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:attendance_latitude'],
            'attendance_radius_meters' => ['sometimes', 'required', 'integer', 'min:10', 'max:10000'],
            'attendance_max_accuracy_meters' => ['sometimes', 'required', 'integer', 'min:5', 'max:5000'],
            'attendance_location_policy' => ['sometimes', 'required', 'string', Rule::in(['allow', 'warn', 'reject'])],
            'camera_url' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'hotline' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'station_halls' => ['nullable', 'array'],
            'station_halls.*.key' => ['nullable', 'string'],
            'station_halls.*.name' => ['nullable', 'string', 'max:255'],
            'branch_stores' => ['nullable', 'array'],
            'branch_stores.*.key' => ['nullable', 'string'],
            'branch_stores.*.name' => ['nullable', 'string', 'max:255'],
            'branch_stores.*.classification' => ['required_with:branch_stores.*.name', 'nullable', Rule::in(BranchStore::classifications())],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique('branches', 'doc_number')->withoutTrashed(),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $company = Company::query()
                ->active()
                ->where('doc_num', (string) $this->input('company_doc_num'))
                ->first();
            $user = $this->user();

            if ($company instanceof Company
                && $user instanceof User
                && ! app(CompanyAccessService::class)->canAccessCompany($user, $company)) {
                $validator->errors()->add('company_doc_num', __('branches.validation.company_forbidden'));

                return;
            }

            if ($company instanceof Company && Branch::query()
                ->where('company_id', $company->getKey())
                ->where('name', trim((string) $this->input('name')))
                ->exists()) {
                $validator->errors()->add('name', __('branches.validation.name_unique'));
            }

            if (! $this->canControlDocumentNumber()
                || $validator->errors()->has('doc_number')
                || ! $this->hasFilledDocumentNumber()
            ) {
                return;
            }

            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format('branches', $docNumber);

            if (Branch::query()->where('doc_num', $docNum)->exists()) {
                $validator->errors()->add('doc_number', __('branches.validation.doc_number_unique'));
            }
        });

        $validator->after(function (Validator $validator): void {
            if ((string) $this->input('type') !== Branch::TypeFactory) {
                return;
            }

            $seen = [];

            foreach ($this->stationHallNames() as $index => $name) {
                $key = mb_strtolower($name);

                if (isset($seen[$key])) {
                    $validator->errors()->add("station_halls.{$index}", __('branches.validation.station_halls_distinct'));

                    continue;
                }

                $seen[$key] = true;
            }
        });

        $validator->after(function (Validator $validator): void {
            $seen = [];

            foreach ($this->branchStoreNames() as $index => $name) {
                $key = mb_strtolower($name);

                if (isset($seen[$key])) {
                    $validator->errors()->add("branch_stores.{$index}", __('branches.validation.branch_stores_distinct'));

                    continue;
                }

                $seen[$key] = true;
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

        $data['station_halls'] = ($data['type'] ?? null) === Branch::TypeFactory
            ? array_values($this->stationHallPayload())
            : [];

        $data['branch_stores'] = array_values($this->branchStorePayload());

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('branches.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doc_number.regex' => __('branches.validation.doc_number_numeric'),
            'doc_number.unique' => __('branches.validation.doc_number_unique'),
            'company_doc_num.exists' => __('branches.validation.company_exists'),
            'name.unique' => __('branches.validation.name_unique'),
            'station_halls.*.name.max' => __('branches.validation.station_hall_name_max'),
            'branch_stores.*.name.max' => __('branches.validation.branch_store_name_max'),
            'branch_stores.*.classification.required_with' => __('branches.validation.branch_store_classification_required'),
            'branch_stores.*.classification.in' => __('branches.validation.branch_store_classification_invalid'),
        ];
    }

    private function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can('branches.document_number.control');
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }

    /**
     * @return array<int, string>
     */
    private function stationHallNames(): array
    {
        return array_values(array_map(
            fn (array $row): string => $row['name'],
            $this->stationHallPayload(),
        ));
    }

    /**
     * @return list<array{key: string|null, name: string}>
     */
    private function stationHallPayload(): array
    {
        $halls = $this->input('station_halls', []);

        if (! is_array($halls)) {
            return [];
        }

        $names = [];

        foreach ($halls as $index => $hall) {
            $row = is_array($hall) ? $hall : ['key' => null, 'name' => $hall];
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $names[(int) $index] = [
                'key' => trim((string) ($row['key'] ?? '')) ?: null,
                'name' => $name,
            ];
        }

        return array_values($names);
    }

    /**
     * @return array<int, string>
     */
    private function branchStoreNames(): array
    {
        return array_values(array_map(
            fn (array $row): string => $row['name'],
            $this->branchStorePayload(),
        ));
    }

    /**
     * @return list<array{key: string|null, name: string, classification: string|null}>
     */
    private function branchStorePayload(): array
    {
        $stores = $this->input('branch_stores', []);

        if (! is_array($stores)) {
            return [];
        }

        $names = [];

        foreach ($stores as $index => $store) {
            $row = is_array($store) ? $store : ['key' => null, 'name' => $store];
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $names[(int) $index] = [
                'key' => trim((string) ($row['key'] ?? '')) ?: null,
                'name' => $name,
                'classification' => trim((string) ($row['classification'] ?? '')) ?: null,
            ];
        }

        return array_values($names);
    }
}
