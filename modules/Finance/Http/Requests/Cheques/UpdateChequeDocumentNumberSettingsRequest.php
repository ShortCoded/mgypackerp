<?php

namespace Modules\Finance\Http\Requests\Cheques;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChequeDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cheques.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'document_key' => ['required', Rule::in(['received_cheques', 'issued_cheques'])],
            'prefix' => ['nullable', 'string', 'max:20'],
            'padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }

    public function attributes(): array
    {
        return [
            'document_key' => __('cheques.attributes.document_key'),
            'prefix' => __('common.document_number_settings.prefix'),
            'padding' => __('common.document_number_settings.padding'),
        ];
    }
}
