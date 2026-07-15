<?php

namespace Modules\HR\Http\Requests\Foundation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\DocumentNumberService;
use Modules\HR\Models\HrFoundationModel;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class StoreHrFoundationRequest extends FormRequest
{
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
        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255', Rule::unique($definition->table, 'name')->withoutTrashed()],
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

        return $rules;
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
            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format($definition->documentKey, $docNumber);

            if ($definition->modelClass::query()->where('doc_num', $docNum)->exists()) {
                $validator->errors()->add('doc_number', __('hr.validation.doc_number_unique'));
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
            $rules[] = Rule::unique($this->definition()->table, (string) ($field['column'] ?? $field['name']))->withoutTrashed();
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
}
