<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\ProjectStructureModel;

class StoreProjectStructureModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'project_structure_models.clone' : 'project_structure_models.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => trim((string) $this->input('code')),
            'short_name' => trim((string) $this->input('short_name')),
            'notes' => $this->nullableTrim('notes'),
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
            'code' => ['required', 'string', 'max:50', $this->activeUniqueRule('code')],
            'short_name' => ['required', 'string', 'max:50', $this->activeUniqueRule('short_name')],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('project_structure_models', 'doc_number')
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
            'doc_number' => __('project_structure_models.attributes.doc_number'),
            'name' => __('project_structure_models.attributes.name'),
            'code' => __('project_structure_models.attributes.code'),
            'short_name' => __('project_structure_models.attributes.short_name'),
            'status' => __('project_structure_models.attributes.status'),
            'notes' => __('project_structure_models.attributes.notes'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doc_number.unique' => __('project_structure_models.messages.doc_number_unique'),
            'code.unique' => __('project_structure_models.messages.code_used'),
            'short_name.unique' => __('project_structure_models.messages.short_name_used'),
        ];
    }

    protected function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    protected function currentProjectStructureModel(): ?ProjectStructureModel
    {
        return null;
    }

    protected function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can('project_structure_models.document_number.control');
    }

    protected function activeUniqueRule(string $column): Unique
    {
        $rule = Rule::unique('project_structure_models', $column)
            ->where(fn ($query) => $query->where('company_id', $this->companyId())->whereNull('deleted_at'));
        $current = $this->currentProjectStructureModel();

        return $current instanceof ProjectStructureModel ? $rule->ignore($current->getKey()) : $rule;
    }

    private function validateDocumentNumber(Validator $validator): void
    {
        if (! $this->canControlDocumentNumber()
            || $validator->errors()->has('doc_number')
            || ! $this->filled('doc_number')) {
            return;
        }

        $current = $this->currentProjectStructureModel();
        $docNumber = (int) $this->input('doc_number');
        $docNum = app(DocumentNumberService::class)->format('project_structure_models', $docNumber);

        $exists = ProjectStructureModel::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->when($current instanceof ProjectStructureModel, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            $validator->errors()->add('doc_number', __('project_structure_models.messages.doc_number_unique'));
        }
    }

    private function nullableTrim(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }
}
