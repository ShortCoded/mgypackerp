<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\UserTask;

class MoveUserTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('my_board.reorder')
            || (bool) $this->user()?->can('tasks.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'required_without:board_list_doc_num', 'string', Rule::in(UserTask::Statuses)],
            'board_list_doc_num' => [
                'nullable',
                'required_without:status',
                'string',
                Rule::exists('board_lists', 'doc_num')->whereNull('deleted_at'),
            ],
            'ordered_doc_nums' => ['nullable', 'array'],
            'ordered_doc_nums.*' => ['string', Rule::exists('user_tasks', 'doc_num')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('user_tasks.attributes');
    }
}
