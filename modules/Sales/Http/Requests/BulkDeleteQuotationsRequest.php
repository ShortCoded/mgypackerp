<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteQuotationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('quotations.delete');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'doc_nums' => collect($this->input('doc_nums', []))
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['required', 'string', Rule::exists('quotations', 'doc_num')->whereNull('deleted_at')],
        ];
    }
}
