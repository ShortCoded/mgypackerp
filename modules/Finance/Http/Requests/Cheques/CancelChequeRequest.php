<?php

namespace Modules\Finance\Http\Requests\Cheques;

use Illuminate\Foundation\Http\FormRequest;

class CancelChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cheques.cancel');
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'cancel_reason' => __('cheques.attributes.cancel_reason'),
        ];
    }
}
