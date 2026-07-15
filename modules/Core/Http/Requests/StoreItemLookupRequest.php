<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\ValidatesItemUnitEquivalence;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\ItemLookupDefinition;
use Modules\Core\Services\ItemLookupRegistry;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreItemLookupRequest extends FormRequest
{
    use ValidatesItemUnitEquivalence;

    public function authorize(): bool
    {
        $definition = $this->definition();

        if ($this->filled('clone_source_token')) {
            return (bool) $this->user()?->can($definition->permission('clone'));
        }

        return (bool) $this->user()?->can($definition->permission('create'));
    }

    protected function prepareForValidation(): void
    {
        $this->prepareItemUnitEquivalenceForValidation();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $definition = $this->definition();
        $companyId = $this->companyId();
        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique($definition->table, 'name')
                    ->where('company_id', $companyId)
                    ->withoutTrashed(),
            ],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique($definition->table, 'doc_number')
                    ->where('company_id', $companyId)
                    ->withoutTrashed(),
            ];
        }

        return [
            ...$rules,
            ...$this->itemUnitEquivalenceRules(),
        ];
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

            $definition = $this->definition();
            $companyId = $this->companyId();
            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format($definition->documentKey, $docNumber);

            if ($definition->modelClass::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $docNum)
                ->exists()) {
                $validator->errors()->add('doc_number', __('item_lookups.validation.doc_number_unique'));
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

        $data['notes'] = $this->blankToNull($data['notes'] ?? null);
        $data = $this->normalizeItemUnitEquivalenceData($data);

        if (! $this->canControlDocumentNumber()) {
            unset($data['doc_number']);

            return $data;
        }

        if (! array_key_exists('doc_number', $data) || $data['doc_number'] === null || $data['doc_number'] === '') {
            unset($data['doc_number']);

            return $data;
        }

        $data['doc_number'] = (int) $data['doc_number'];

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('item_lookups.fields.name'),
            'status' => __('item_lookups.fields.status'),
            'doc_number' => __('common.fields.document_number'),
            'notes' => __('item_lookups.fields.notes'),
            ...$this->itemUnitEquivalenceAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('item_lookups.validation.name_unique'),
            'doc_number.regex' => __('item_lookups.validation.doc_number_numeric'),
            'doc_number.unique' => __('item_lookups.validation.doc_number_unique'),
            ...$this->itemUnitEquivalenceMessages(),
        ];
    }

    protected function definition(): ItemLookupDefinition
    {
        return app(ItemLookupRegistry::class)->fromRouteName($this->route()?->getName());
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

    private function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
