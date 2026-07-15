<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customers.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:30'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
