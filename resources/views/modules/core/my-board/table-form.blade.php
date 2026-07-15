@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isCreate = $mode === 'create';
    $isTask = $type === \Modules\Core\Models\UserTask::TypeTask;
    $isTrashed = $record?->trashed() ?? false;
    $erpAsset = app(\Modules\Core\Services\AssetVersionService::class);
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $richTextService = app(\Modules\Core\Services\UserTaskService::class);
    $dateTimeFormat = $dateFormatService->jsDateTimeFormat();
    $formatDateTime = static fn ($value): string => $dateFormatService->formatDateTime($value, '');
    $statusValue = old('status', $record?->status ?? \Modules\Core\Models\UserTask::StatusTodo);
    $priorityValue = old('priority', $record?->priority ?? \Modules\Core\Models\UserTask::PriorityNormal);
    $colorValue = old('color', $record?->color ?? 'primary');
    $boardListValue = old('board_list_doc_num', $record?->boardList?->doc_num);
    $descriptionValue = old('description', $record?->description ?? '');
    $descriptionHtml = $richTextService->sanitizeRichTextContent($descriptionValue) ?? '';
    $myBoardTableJsPath = 'assets/js/modules/Core/my-board-table.js';
    $selectedAssigneeDocNums = collect(old('assignee_doc_nums', collect($selectedAssignees)->pluck('id')->all()))
        ->map(fn ($docNum) => (string) $docNum)
        ->filter()
        ->values()
        ->all();
    $selectedAssigneeLabel = collect($selectedAssignees)->pluck('text')->filter()->implode(', ');
    $existingAttachments = $record?->attachmentUsages ?? collect();
    $canManageAttachments = $isTask && ! $isView && ! $isTrashed && ($boardConfig['can']['manageAttachments'] ?? false);
    $canUseFileManager = ($boardConfig['can']['useFileManager'] ?? false);
    $formatFileSize = static function (int $bytes): string {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    };
    $originalRecordData = [
        'title' => $record?->title ?? '',
        'description' => $record?->description ?? '',
        'type' => $type,
        'status' => $record?->status ?? \Modules\Core\Models\UserTask::StatusTodo,
        'board_list_doc_num' => $record?->boardList?->doc_num ?? '',
        'priority' => $record?->priority ?? \Modules\Core\Models\UserTask::PriorityNormal,
        'color' => $record?->color ?? 'primary',
        'assignee_doc_nums' => collect($selectedAssignees)->pluck('id')->values()->all(),
        'due_at' => $formatDateTime($record?->due_at),
        'attachment_file_doc_nums' => [],
    ];
@endphp

@section('title', $title)

@push('styles')
    <link href="{{ $erpAsset->url('vendors/summernote/summernote-bs5.min.css') }}" rel="stylesheet">
    <style>
        .js-my-board-table-form .note-editor.note-frame {
            border-color: var(--falcon-border-color);
            border-radius: .375rem;
        }

        .js-my-board-table-form .note-toolbar {
            background-color: var(--falcon-gray-100);
            border-bottom-color: var(--falcon-border-color);
        }

        .my-board-rich-content {
            min-height: 8rem;
            background-color: var(--falcon-gray-100);
        }
    </style>
@endpush

@section('content')
    <form id="my-board-table-form" class="js-my-board-table-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalRecordData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        <input type="hidden" name="type" value="{{ $type }}">

        @unless (($boardConfig['can']['assign'] ?? false) && $isTask && ! $isView)
            @foreach ($selectedAssigneeDocNums as $docNum)
                <input type="hidden" name="assignee_doc_nums[]" value="{{ $docNum }}">
            @endforeach
        @endunless

        <div class="card">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ $title }}</h5>
                    </div>
                    <div class="col-auto">
                        @include('modules.core.my-board.partials.table-form-actions', ['class' => 'my-board-table-form-actions-header'])
                    </div>
                </div>
            </div>

            <div class="card-body js-my-board-table-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-my-board-table-alert" role="alert">
                    <span class="js-my-board-table-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <div class="row g-3">
                    @if ($isEdit || $isView)
                        <div class="col-md-3 col-xl-2">
                            <x-forms.view-field
                                for="my-board-table-doc-number"
                                as="display"
                                :label="__('common.fields.document_number')"
                                :value="$record?->doc_num"
                                input-class="text-center dt-code-value"
                                dir="ltr"
                            />
                        </div>
                    @endif

                    <div class="{{ ($isEdit || $isView) ? 'col-md-5 col-xl-5' : 'col-md-5' }}">
                        <x-forms.label for="my-board-table-title" :label="__('user_tasks.attributes.title')" required />
                        @if ($isView)
                            <x-forms.view-field for="my-board-table-title" :value="old('title', $record?->title)" />
                        @else
                            <input id="my-board-table-title" autofocus name="title" type="text" class="form-control" value="{{ old('title', $record?->title) }}" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="title"></div>
                    </div>

                    <div class="{{ ($isEdit || $isView) ? 'col-md-2 col-xl-2' : 'col-md-3' }}">
                        <x-forms.label for="my-board-table-status" :label="__('user_tasks.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="my-board-table-status" :value="$statusValue ? __('user_tasks.statuses.'.$statusValue) : null" />
                        @else
                            <select id="my-board-table-status" name="status" class="form-select" required>
                                @foreach (\Modules\Core\Models\UserTask::Statuses as $status)
                                    <option value="{{ $status }}" @selected($statusValue === $status)>{{ __("user_tasks.statuses.{$status}") }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    @foreach (['due_at'] as $dateField)
                        <div class="{{ ($isEdit || $isView) ? 'col-md-2 col-xl-3' : 'col-md-4' }}">
                            <x-forms.label :for="'my-board-table-'.$dateField" :label="__('user_tasks.attributes.'.$dateField)" />
                            @if ($isView)
                                <x-forms.view-field :for="'my-board-table-'.$dateField" :value="$formatDateTime($record?->{$dateField})" dir="ltr" />
                            @else
                                <input class="form-control js-date-picker" id="my-board-table-{{ $dateField }}" name="{{ $dateField }}" type="text" value="{{ old($dateField, $formatDateTime($record?->{$dateField})) }}" data-enable-time="true" data-date-format="{{ $dateTimeFormat }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr">
                            @endif
                            <div class="invalid-feedback" data-error-for="{{ $dateField }}"></div>
                        </div>
                    @endforeach

                    <div class="col-md-4">
                        <x-forms.label for="my-board-table-list" :label="__('user_tasks.attributes.board')" />
                        @if ($isView)
                            <x-forms.view-field for="my-board-table-list" :value="$record?->boardList?->name" />
                        @else
                            <select id="my-board-table-list" name="board_list_doc_num" class="form-select">
                                <option value="">{{ __('user_tasks.placeholders.board') }}</option>
                                @foreach ($boardLists as $list)
                                    <option value="{{ $list->doc_num }}" @selected($boardListValue === $list->doc_num)>{{ $list->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="board_list_doc_num"></div>
                    </div>

                    @if ($isTask)
                        <div class="col-md-4">
                            <x-forms.label for="my-board-table-priority" :label="__('user_tasks.attributes.priority')" required />
                            @if ($isView)
                                <x-forms.view-field for="my-board-table-priority" :value="$priorityValue ? __('user_tasks.priorities.'.$priorityValue) : null" />
                            @else
                                <select id="my-board-table-priority" name="priority" class="form-select" required>
                                    @foreach (\Modules\Core\Models\UserTask::Priorities as $priority)
                                        <option value="{{ $priority }}" @selected($priorityValue === $priority)>{{ __("user_tasks.priorities.{$priority}") }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <div class="invalid-feedback" data-error-for="priority"></div>
                        </div>
                    @endif

                    <div class="{{ $isTask ? 'col-md-4' : 'col-md-8' }}">
                        <x-forms.label for="my-board-table-color" :label="__('user_tasks.attributes.color')" />
                        @if ($isView)
                            <x-forms.view-field for="my-board-table-color" :value="$colorValue ? __('user_tasks.colors.'.$colorValue) : null" />
                        @else
                            <select id="my-board-table-color" name="color" class="form-select">
                                <option value="">{{ __('common.empty_value') }}</option>
                                @foreach (\Modules\Core\Models\UserTask::Colors as $color)
                                    <option value="{{ $color }}" @selected($colorValue === $color)>{{ __("user_tasks.colors.{$color}") }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="color"></div>
                    </div>

                    <div class="col-12">
                        <x-forms.label for="my-board-table-assignees" :label="__('user_tasks.attributes.assigned_to')" />
                        @if ($isView || ! (($boardConfig['can']['assign'] ?? false) && $isTask))
                            <x-forms.view-field for="my-board-table-assignees" :value="$selectedAssigneeLabel" />
                        @else
                            <select class="form-select js-select2-ajax" id="my-board-table-assignees" name="assignee_doc_nums[]" multiple data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.assignees') }}" data-allow-clear="true">
                                @foreach ($selectedAssignees as $assignee)
                                    <option value="{{ $assignee['id'] }}" selected>{{ $assignee['text'] }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="assignee_doc_nums"></div>
                    </div>

                    <div class="col-12">
                        <x-forms.label for="my-board-table-description" :label="__('user_tasks.attributes.description')" />
                        @if ($isView)
                            <div id="my-board-table-description" class="form-control-plaintext border rounded-2 px-3 py-2 my-board-rich-content">{!! $descriptionHtml !== '' ? $descriptionHtml : e(__('common.empty_value')) !!}</div>
                        @else
                            <textarea id="my-board-table-description" name="description" class="form-control js-my-board-rich-editor" rows="8" data-direction="{{ $boardConfig['direction'] ?? 'ltr' }}" placeholder="{{ __('user_tasks.placeholders.description') }}">{{ $descriptionValue }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="description"></div>
                    </div>
                </div>

                @if ($isTask)
                    <div class="mt-4">
                        @if ($canManageAttachments)
                            @if ($canUseFileManager)
                                <div class="p-3 mb-3 border rounded-2 bg-body-tertiary">
                                    <div class="flex-wrap gap-2 d-flex align-items-center justify-content-between">
                                        <div>
                                            <div class="fw-semibold">{{ __('user_tasks.actions.add_attachment') }}</div>
                                            <div class="small text-600">{{ __('user_tasks.help.attachments') }}</div>
                                        </div>
                                        <button type="button"
                                            class="btn btn-falcon-primary btn-sm js-user-task-attachment-picker-trigger"
                                            data-file-picker
                                            data-picker-accept="document"
                                            data-picker-max="1"
                                            data-picker-title="{{ __('user_tasks.actions.choose_files') }}"
                                            data-picker-collection="user_task_attachments"
                                            data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                            data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                            <span class="fas fa-paperclip me-1"></span>{{ __('user_tasks.actions.choose_files') }}
                                        </button>
                                    </div>
                                    <div id="user_task_attachment_file_inputs"></div>
                                    <div class="invalid-feedback d-block" data-error-for="attachment_file_doc_nums"></div>
                                </div>

                                <div class="mb-3 table-responsive d-none" id="user-task-selected-attachments-wrap">
                                    <table class="table mb-0 align-middle table-sm">
                                        <thead class="bg-100 text-900">
                                            <tr>
                                                <th>{{ __('user_tasks.attributes.attachments') }}</th>
                                                <th class="white-space-nowrap">{{ __('user_tasks.attributes.file_size') }}</th>
                                                <th class="text-end"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="user-task-selected-attachments"></tbody>
                                    </table>
                                </div>
                            @else
                                <div class="mb-3 alert alert-subtle-warning">{{ __('user_tasks.messages.file_manager_required') }}</div>
                            @endif
                        @endif

                        @if (! $isCreate && $existingAttachments->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table mb-0 align-middle table-sm">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th>{{ __('user_tasks.attributes.attachments') }}</th>
                                            <th class="white-space-nowrap">{{ __('user_tasks.attributes.file_size') }}</th>
                                            <th class="white-space-nowrap">{{ __('user_tasks.attributes.uploaded_by') }}</th>
                                            <th class="white-space-nowrap">{{ __('user_tasks.attributes.uploaded_at') }}</th>
                                            <th class="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($existingAttachments as $usage)
                                            @php
                                                $file = $usage->file;
                                            @endphp
                                            @continue(! $file)
                                            <tr data-attachment-doc-num="{{ $file->doc_num }}">
                                                <td class="min-w-0">
                                                    <div class="fw-semibold text-truncate" title="{{ $file->original_name }}">
                                                        <span class="fas fa-paperclip text-500 me-1"></span>{{ $file->original_name }}
                                                    </div>
                                                    <div class="text-600 fs-11" dir="ltr">{{ $file->doc_num }}</div>
                                                </td>
                                                <td class="white-space-nowrap" dir="ltr">{{ $formatFileSize((int) $file->size_bytes) }}</td>
                                                <td class="white-space-nowrap">{{ $usage->createdBy ? trim(implode(' / ', array_filter([$usage->createdBy->name, $usage->createdBy->doc_num]))) : __('common.empty_value') }}</td>
                                                <td class="white-space-nowrap">{{ $dateFormatService->formatDateTime($usage->created_at, '') }}</td>
                                                <td class="text-end white-space-nowrap">
                                                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.tools.team-board.tasks.attachments.show', [$record->doc_num, $file->doc_num]) }}" target="_blank" rel="noopener">
                                                        <span class="fas fa-external-link-alt"></span>
                                                        <span class="d-none d-md-inline ms-1">{{ __('user_tasks.actions.open_attachment') }}</span>
                                                    </a>
                                                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.tools.team-board.tasks.attachments.download', [$record->doc_num, $file->doc_num]) }}">
                                                        <span class="fas fa-download"></span>
                                                        <span class="d-none d-md-inline ms-1">{{ __('user_tasks.actions.download_attachment') }}</span>
                                                    </a>
                                                    @if ($canManageAttachments)
                                                        <button class="btn btn-falcon-danger btn-sm js-delete-user-task-attachment" type="button" data-url="{{ route('admin.tools.team-board.tasks.attachments.destroy', [$record->doc_num, $file->doc_num]) }}">
                                                            <span class="fas fa-times"></span>
                                                            <span class="visually-hidden">{{ __('user_tasks.actions.remove_attachment') }}</span>
                                                        </button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @elseif (! $isCreate)
                            <div class="text-600">{{ __('user_tasks.empty.attachments') }}</div>
                        @endif
                    </div>
                @endif

                @if ($isEdit || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($record?->trashed() ?? false)"
                        :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>

            <div class="card-footer">
                @include('modules.core.my-board.partials.table-form-actions', ['class' => 'my-board-table-form-actions-footer'])
            </div>
        </div>
    </form>

    @if ($canManageAttachments && $canUseFileManager)
        <x-file-picker-modal />
    @endif
@endsection

@push('scripts')
    @php
        $myBoardTableMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'deleteConfirmTitle' => __('user_tasks.messages.delete_confirm_title'),
            'deleteConfirmText' => __('user_tasks.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('user_tasks.messages.delete_confirm_yes'),
            'attachmentDeleteConfirmTitle' => __('user_tasks.messages.attachment_delete_confirm_title'),
            'attachmentDeleteConfirmText' => __('user_tasks.messages.attachment_delete_confirm_text'),
            'attachmentDeleteConfirmYes' => __('user_tasks.messages.attachment_delete_confirm_yes'),
            'attachmentDuplicate' => __('user_tasks.messages.attachment_duplicate'),
            'attachmentDeleted' => __('user_tasks.messages.attachment_deleted'),
            'removeAttachment' => __('user_tasks.actions.remove_attachment'),
        ];
    @endphp
    <script>
        window.MyBoardTableConfig = @json($boardConfig);
        window.myBoardTableMessages = @json($myBoardTableMessages);
    </script>
    <script src="{{ $erpAsset->url('vendors/summernote/summernote-bs5.min.js') }}"></script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    @if ($canManageAttachments && $canUseFileManager)
        <script src="{{ $erpAsset->url('assets/js/modules/Core/file-picker.js') }}"></script>
    @endif
    <script src="{{ $erpAsset->url($myBoardTableJsPath) }}"></script>
@endpush
