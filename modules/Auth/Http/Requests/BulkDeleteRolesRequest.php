<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('roles.delete');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'string',
                Rule::exists(config('permission.table_names.roles', 'roles'), 'doc_num'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_nums' => __('auth.roles.selected'),
            'doc_nums.*' => __('auth.roles.selected'),
        ];
    }
}
