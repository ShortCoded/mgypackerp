<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteUserTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('tasks.delete')
            || (bool) $this->user()?->can('tasks.bulk_delete');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['string', Rule::exists('user_tasks', 'doc_num')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_nums' => __('user_tasks.selected'),
            'doc_nums.*' => __('user_tasks.selected'),
        ];
    }
}
