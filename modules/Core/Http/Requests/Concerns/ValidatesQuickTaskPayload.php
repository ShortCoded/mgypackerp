<?php

namespace Modules\Core\Http\Requests\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\QuickTask;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\OperatingCompanyContextService;

trait ValidatesQuickTaskPayload
{
    /**
     * @return array<string, mixed>
     */
    protected function quickTaskRules(): array
    {
        return [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new'])],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'details' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', 'string', Rule::in(QuickTask::Statuses)],
            'priority' => ['nullable', 'string', Rule::in(QuickTask::Priorities)],
            'task_board_doc_num' => [
                'nullable',
                'string',
                Rule::exists('task_boards', 'doc_num')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'assigned_to_doc_num' => [
                'nullable',
                'string',
                Rule::exists('users', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,txt',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain',
            ],
            'attachment_file_doc_nums' => ['nullable', 'array', 'max:10'],
            'attachment_file_doc_nums.*' => ['nullable', 'string', 'max:255', 'distinct'],
        ];
    }

    protected function prepareQuickTaskForValidation(): void
    {
        foreach (['title', 'summary', 'details', 'status', 'priority', 'task_board_doc_num', 'assigned_to_doc_num'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }

        if (! $this->filled('status')) {
            $this->merge(['status' => QuickTask::StatusNew]);
        }

        if (! $this->filled('priority')) {
            $this->merge(['priority' => QuickTask::PriorityNormal]);
        }

        if ($this->has('attachment_file_doc_nums')) {
            $fileDocNums = collect($this->input('attachment_file_doc_nums'))
                ->map(fn (mixed $docNum): string => trim((string) $docNum))
                ->filter(fn (string $docNum): bool => $docNum !== '')
                ->unique()
                ->values()
                ->all();

            $this->merge(['attachment_file_doc_nums' => $fileDocNums]);
        }
    }

    /**
     * @return list<UploadedFile>
     */
    public function quickTaskAttachments(): array
    {
        $files = $this->file('attachments', []);

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return collect($files)
            ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function quickTaskAttachmentFileDocNums(): array
    {
        return collect($this->input('attachment_file_doc_nums', []))
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter(fn (string $docNum): bool => $docNum !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function validateQuickTaskAttachmentSelections(Validator $validator): void
    {
        $fileDocNums = $this->quickTaskAttachmentFileDocNums();
        $uploadedFiles = $this->quickTaskAttachments();

        if ($fileDocNums === [] && $uploadedFiles === []) {
            return;
        }

        if (! $this->user()?->can('quick_tasks.manage_attachments')) {
            $validator->errors()->add('attachments', __('quick_tasks.validation.manage_attachments_forbidden'));

            return;
        }

        if ($fileDocNums === []) {
            return;
        }

        if (! $this->user()?->can('file_manager.view')) {
            $validator->errors()->add('attachment_file_doc_nums', __('quick_tasks.validation.selected_file_unavailable'));

            return;
        }

        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);
        $filePicker = app(FilePickerService::class);

        foreach ($fileDocNums as $fileDocNum) {
            $file = $filePicker->fileForCompany($fileDocNum, $companyId);

            if (! $file instanceof ArchiveFile || ! $filePicker->isAvailableFile($file)) {
                $validator->errors()->add('attachment_file_doc_nums', __('quick_tasks.validation.selected_file_unavailable'));

                continue;
            }

            if ($filePicker->fileHiddenFromPicker($file)) {
                $validator->errors()->add('attachment_file_doc_nums', __('quick_tasks.validation.selected_file_hidden_from_picker'));
            }
        }
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

        unset($data['attachments'], $data['attachment_file_doc_nums']);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('quick_tasks.attributes.title'),
            'summary' => __('quick_tasks.attributes.summary'),
            'details' => __('quick_tasks.attributes.details'),
            'status' => __('quick_tasks.attributes.status'),
            'priority' => __('quick_tasks.attributes.priority'),
            'task_board_doc_num' => __('task_boards.singular'),
            'assigned_to_doc_num' => __('quick_tasks.attributes.assigned_to'),
            'attachments' => __('quick_tasks.attributes.attachments'),
            'attachments.*' => __('quick_tasks.attributes.attachments'),
            'attachment_file_doc_nums' => __('quick_tasks.attributes.attachments'),
            'attachment_file_doc_nums.*' => __('quick_tasks.attributes.attachments'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('quick_tasks.validation.title_required'),
            'task_board_doc_num.exists' => __('task_boards.validation.unavailable'),
            'assigned_to_doc_num.exists' => __('quick_tasks.validation.assigned_user_unavailable'),
            'attachments.max' => __('quick_tasks.validation.too_many_attachments'),
            'attachments.*.max' => __('quick_tasks.validation.attachment_too_large'),
            'attachments.*.mimes' => __('quick_tasks.validation.attachment_type'),
            'attachments.*.mimetypes' => __('quick_tasks.validation.attachment_type'),
            'attachment_file_doc_nums.max' => __('quick_tasks.validation.too_many_attachments'),
        ];
    }
}
