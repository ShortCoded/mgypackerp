<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteCompaniesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('companies.delete');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['string', Rule::exists('companies', 'doc_num')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_nums' => __('companies.selected'),
            'doc_nums.*' => __('companies.selected'),
        ];
    }
}
