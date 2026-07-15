<?php

namespace Modules\Core\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\UserTaskAccessService;

class UpdateUserTaskRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('attachment_file_doc_nums')) {
            return;
        }

        $fileDocNums = collect($this->input('attachment_file_doc_nums'))
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter(fn (string $docNum): bool => $docNum !== '')
            ->unique()
            ->values()
            ->all();

        $this->merge(['attachment_file_doc_nums' => $fileDocNums]);
    }

    public function authorize(): bool
    {
        if ($this->usesMyBoardPermissions()) {
            return (bool) $this->user()?->can('my_board.edit');
        }

        return (bool) $this->user()?->can('tasks.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $task = $this->route('userTask');

        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', Rule::in(UserTask::Types)],
            'status' => ['required', 'string', Rule::in(UserTask::Statuses)],
            'board_list_doc_num' => [
                'nullable',
                'string',
                Rule::exists('board_lists', 'doc_num')->whereNull('deleted_at'),
            ],
            'priority' => [Rule::requiredIf(fn (): bool => $this->isTaskType()), 'nullable', 'string', Rule::in(UserTask::Priorities)],
            'color' => ['nullable', 'string', Rule::in(UserTask::Colors)],
            'assigned_to_doc_num' => [
                'nullable',
                'string',
                Rule::exists('users', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'assignee_doc_nums' => ['nullable', 'array'],
            'assignee_doc_nums.*' => [
                'string',
                Rule::exists('users', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'due_at' => ['nullable', 'string'],
        ];

        if ($this->usesTaskManagementPayload()) {
            $rules['attachment_file_doc_nums'] = ['nullable', 'array', 'max:10'];
            $rules['attachment_file_doc_nums.*'] = ['nullable', 'string', 'max:255', 'distinct'];
        } else {
            $rules['start_at'] = ['nullable', 'string'];
            $rules['completed_at'] = ['nullable', 'string'];
            $rules['board_user_doc_num'] = [
                'nullable',
                'string',
                Rule::exists('users', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ];
        }

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique('user_tasks', 'doc_number')->ignore($task?->getKey())->withoutTrashed(),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $task = $this->route('userTask');
            $dates = app(DateFormatService::class);
            $dateFields = $this->usesTaskManagementPayload()
                ? ['due_at']
                : ['start_at', 'due_at', 'completed_at'];

            foreach ($dateFields as $field) {
                $value = $this->input($field);

                if ($value !== null && trim((string) $value) !== '' && ! $dates->isValidDateTime((string) $value)) {
                    $validator->errors()->add($field, __('validation.date', ['attribute' => __("user_tasks.attributes.{$field}")]));
                }
            }

            if (! $this->usesTaskManagementPayload()) {
                $startAt = $dates->parseDateTime($this->string('start_at')->toString());
                $completedAt = $dates->parseDateTime($this->string('completed_at')->toString());

                if ($startAt !== null && $completedAt !== null && $completedAt->lt($startAt)) {
                    $validator->errors()->add('completed_at', __('user_tasks.validation.end_before_start'));
                }
            }

            if (! $this->canAssignSelectedUsers()) {
                $validator->errors()->add('assignee_doc_nums', __('user_tasks.messages.action_forbidden'));
            }

            $this->validateTaskManagementAttachmentSelections($validator);

            if (! $this->canControlDocumentNumber()
                || ! $task instanceof UserTask
                || $validator->errors()->has('doc_number')
                || ! $this->hasFilledDocumentNumber()
            ) {
                return;
            }

            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format('user_tasks', $docNumber);

            if (UserTask::query()->where('doc_num', $docNum)->whereKeyNot($task->getKey())->exists()) {
                $validator->errors()->add('doc_number', __('user_tasks.validation.doc_number_unique'));
            }
        });
    }

    /**
     * @param  string|null  $key
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        if (! $this->canControlDocumentNumber()) {
            unset($data['doc_number']);
        }

        if ($this->usesTaskManagementPayload()) {
            unset($data['start_at'], $data['completed_at'], $data['board_user_doc_num'], $data['attachment_file_doc_nums']);
        }

        if (array_key_exists('doc_number', $data) && ($data['doc_number'] === null || $data['doc_number'] === '')) {
            unset($data['doc_number']);
        } elseif (array_key_exists('doc_number', $data)) {
            $data['doc_number'] = (int) $data['doc_number'];
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('user_tasks.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doc_number.regex' => __('user_tasks.validation.doc_number_numeric'),
            'doc_number.unique' => __('user_tasks.validation.doc_number_unique'),
            'assigned_to_doc_num.exists' => __('user_tasks.messages.assigned_user_exists'),
            'assignee_doc_nums.*.exists' => __('user_tasks.messages.assigned_user_exists'),
            'board_user_doc_num.exists' => __('user_tasks.messages.assigned_user_exists'),
            'board_list_doc_num.exists' => __('user_tasks.messages.board_list_exists'),
            'attachment_file_doc_nums.max' => __('user_tasks.validation.too_many_attachments'),
        ];
    }

    /**
     * @return list<string>
     */
    public function userTaskAttachmentFileDocNums(): array
    {
        return collect($this->input('attachment_file_doc_nums', []))
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter(fn (string $docNum): bool => $docNum !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function canControlDocumentNumber(): bool
    {
        return ! $this->usesMyBoardPermissions()
            && (bool) $this->user()?->can('tasks.document_number.control');
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }

    private function canAssignSelectedUsers(): bool
    {
        $selectedDocNums = $this->selectedAssigneeDocNums();
        $task = $this->route('userTask');

        if ($selectedDocNums === [] && $task instanceof UserTask) {
            return true;
        }

        $user = $this->user();

        if ($user instanceof User && $selectedDocNums === [(string) $user->doc_num]) {
            return true;
        }

        if ($task instanceof UserTask && $selectedDocNums !== []) {
            $task->loadMissing('assignees:id,doc_num');
            $existingDocNums = $task->assignees->pluck('doc_num')->values()->all();

            if ($existingDocNums === [] && $task->assignedTo?->doc_num !== null) {
                $existingDocNums = [(string) $task->assignedTo->doc_num];
            }

            sort($existingDocNums);
            $sortedSelectedDocNums = $selectedDocNums;
            sort($sortedSelectedDocNums);

            if (array_values($existingDocNums) === $sortedSelectedDocNums) {
                return true;
            }
        }

        if ($task instanceof UserTask && $selectedDocNums === []) {
            return true;
        }

        return $user instanceof User && app(UserTaskAccessService::class)->canAssign($user);
    }

    /**
     * @return list<string>
     */
    private function selectedAssigneeDocNums(): array
    {
        $docNums = $this->input('assignee_doc_nums', []);

        if (! is_array($docNums)) {
            $docNums = [];
        }

        if ($docNums === [] && $this->filled('assigned_to_doc_num')) {
            $docNums = [$this->input('assigned_to_doc_num')];
        }

        return collect($docNums)
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isTaskType(): bool
    {
        return (string) $this->input('type', UserTask::TypeTask) === UserTask::TypeTask;
    }

    private function usesMyBoardPermissions(): bool
    {
        return $this->routeIs('admin.my-board.*')
            || $this->routeIs('admin.tools.team-board.*');
    }

    private function usesTaskManagementPayload(): bool
    {
        return $this->routeIs('admin.tools.team-board.*');
    }

    private function validateTaskManagementAttachmentSelections(Validator $validator): void
    {
        if (! $this->usesTaskManagementPayload()) {
            return;
        }

        $fileDocNums = $this->userTaskAttachmentFileDocNums();

        if ($fileDocNums === []) {
            return;
        }

        if (! $this->user()?->can('quick_tasks.manage_attachments')) {
            $validator->errors()->add('attachment_file_doc_nums', __('user_tasks.validation.manage_attachments_forbidden'));

            return;
        }

        if (! $this->user()?->can('file_manager.view')) {
            $validator->errors()->add('attachment_file_doc_nums', __('user_tasks.validation.selected_file_unavailable'));

            return;
        }

        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);
        $filePicker = app(FilePickerService::class);

        foreach ($fileDocNums as $fileDocNum) {
            $file = $filePicker->selectableFileByPublicId($fileDocNum, $companyId, FilePickerService::AcceptDocument);

            if (! $file instanceof ArchiveFile) {
                $validator->errors()->add('attachment_file_doc_nums', __('user_tasks.validation.selected_file_unavailable'));
            }
        }
    }
}
