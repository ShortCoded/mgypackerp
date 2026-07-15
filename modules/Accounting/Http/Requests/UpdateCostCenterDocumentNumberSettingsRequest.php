<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCostCenterDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cost_centers.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:20'],
            'padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }
}
