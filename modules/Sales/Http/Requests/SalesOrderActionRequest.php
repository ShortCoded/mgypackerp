<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesOrderActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'required_if:requires_reason,1', 'max:2000'], 'requires_reason' => ['sometimes', 'boolean']];
    }
}
