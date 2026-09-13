<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseProductionQualityInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.quality.close');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'close_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
