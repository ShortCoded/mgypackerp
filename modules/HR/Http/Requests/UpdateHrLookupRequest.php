<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\DocumentNumberService;
use Modules\HR\Models\HrLookupModel;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class UpdateHrLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->definition()->permission('edit'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $definition = $this->definition();
        $record = $this->record();
        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique($definition->table, 'name')
                    ->ignore($record?->getKey())
                    ->withoutTrashed(),
            ],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique($definition->table, 'doc_number')
                    ->ignore($record?->getKey())
                    ->withoutTrashed(),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $record = $this->record();

            if (! $this->canControlDocumentNumber()
                || ! $record instanceof HrLookupModel
                || $validator->errors()->has('doc_number')
                || ! $this->hasFilledDocumentNumber()
            ) {
                return;
            }

            $definition = $this->definition();
            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format($definition->documentKey, $docNumber);

            if ($definition->modelClass::query()->where('doc_num', $docNum)->whereKeyNot($record->getKey())->exists()) {
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
            'name' => __('common.fields.name'),
            'doc_number' => __('common.fields.document_number'),
            'notes' => __('common.fields.notes'),
        ];
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

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->fromRouteName($this->route()?->getName());
    }

    private function record(): ?HrLookupModel
    {
        foreach ($this->route()?->parameters() ?? [] as $record) {
            if ($record instanceof HrLookupModel) {
                return $record;
            }
        }

        return null;
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
