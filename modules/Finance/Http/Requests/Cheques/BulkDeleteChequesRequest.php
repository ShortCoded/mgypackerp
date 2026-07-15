<?php

namespace Modules\Finance\Http\Requests\Cheques;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteChequesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cheques.delete');
    }

    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['required', 'string'],
        ];
    }
}
