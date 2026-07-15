<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuotationDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('quotations.document_number_settings.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'prefix' => trim((string) $this->input('prefix')),
        ]);
    }

    public function rules(): array
    {
        return [
            'prefix' => ['nullable', 'string', 'max:20'],
            'padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }

    public function attributes(): array
    {
        return [
            'prefix' => __('common.document_number_settings.prefix'),
            'padding' => __('common.document_number_settings.padding'),
        ];
    }
}
