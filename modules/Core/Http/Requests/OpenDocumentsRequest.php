<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\OpenDocumentsService;

class OpenDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('tools.open_documents.execute');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_type' => trim((string) $this->input('document_type')),
            'from_number' => trim((string) $this->input('from_number')),
            'to_number' => trim((string) $this->input('to_number')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(app(OpenDocumentsService::class)->supportedTypeKeys())],
            'from_number' => ['required', 'integer', 'min:1'],
            'to_number' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('from_number') || $validator->errors()->has('to_number')) {
                return;
            }

            if ((int) $this->input('from_number') > (int) $this->input('to_number')) {
                $validator->errors()->add('from_number', __('open_documents.validation.from_lte_to'));
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

        return [
            'document_type' => (string) $data['document_type'],
            'from_number' => (int) $data['from_number'],
            'to_number' => (int) $data['to_number'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'document_type' => __('open_documents.fields.document_type'),
            'from_number' => __('open_documents.fields.from_number'),
            'to_number' => __('open_documents.fields.to_number'),
        ];
    }
}
