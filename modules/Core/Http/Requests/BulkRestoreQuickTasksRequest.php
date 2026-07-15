<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkRestoreQuickTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('quick_tasks.restore');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['required', 'string', 'max:255', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_nums' => __('quick_tasks.selected'),
            'doc_nums.*' => __('quick_tasks.attributes.doc_num'),
        ];
    }
}
