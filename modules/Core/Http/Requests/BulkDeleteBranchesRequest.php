<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteBranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('branches.delete');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['string', Rule::exists('branches', 'doc_num')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_nums' => __('branches.selected'),
            'doc_nums.*' => __('branches.selected'),
        ];
    }
}
