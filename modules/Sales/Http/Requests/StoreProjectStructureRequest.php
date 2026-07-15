<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\ProjectStructure;

class StoreProjectStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'project_structures.clone' : 'project_structures.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => trim((string) $this->input('code')),
            'parent_doc_num' => $this->nullableTrim('parent_doc_num'),
            'notes' => $this->nullableTrim('notes'),
            'sort_order' => $this->filled('sort_order') ? (int) $this->input('sort_order') : 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->companyId();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('project_structures', 'code')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'parent_doc_num' => [
                'nullable',
                'string',
                Rule::exists('project_structures', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('project_structures', 'doc_number')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateDocumentNumber($validator);
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

        if (! $this->canControlDocumentNumber() || ! array_key_exists('doc_number', $data) || $data['doc_number'] === null || $data['doc_number'] === '') {
            unset($data['doc_number']);
        } else {
            $data['doc_number'] = (int) $data['doc_number'];
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_number' => __('project_structures.attributes.doc_number'),
            'name' => __('project_structures.attributes.name'),
            'code' => __('project_structures.attributes.code'),
            'parent_doc_num' => __('project_structures.attributes.parent'),
            'status' => __('project_structures.attributes.status'),
            'notes' => __('project_structures.attributes.notes'),
            'sort_order' => __('project_structures.attributes.sort_order'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => __('project_structures.messages.code_used'),
            'doc_number.unique' => __('project_structures.messages.doc_number_unique'),
        ];
    }

    protected function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    protected function currentProjectStructure(): ?ProjectStructure
    {
        return null;
    }

    protected function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can('project_structures.document_number.control');
    }

    private function validateDocumentNumber(Validator $validator): void
    {
        if (! $this->canControlDocumentNumber()
            || $validator->errors()->has('doc_number')
            || ! $this->filled('doc_number')) {
            return;
        }

        $current = $this->currentProjectStructure();
        $docNumber = (int) $this->input('doc_number');
        $docNum = app(DocumentNumberService::class)->format('project_structures', $docNumber);

        $exists = ProjectStructure::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->when($current instanceof ProjectStructure, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            $validator->errors()->add('doc_number', __('project_structures.messages.doc_number_unique'));
        }
    }

    private function nullableTrim(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }
}
