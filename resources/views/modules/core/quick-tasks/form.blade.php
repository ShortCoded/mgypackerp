@extends('layouts.app')

@php
    use Modules\Core\Models\QuickTask;
    use Modules\Core\Services\AssetVersionService;
    use Modules\Core\Services\QuickTaskService;
    use Modules\Core\Services\SettingService;

    $record = $task ?? null;
    $isView = $mode === 'view';
    $isCreate = $mode === 'create';
    $isTrashed = $record?->trashed() ?? false;
    $title = match ($mode) {
        'edit' => __('quick_tasks.edit'),
        'view' => __('quick_tasks.view'),
        default => __('quick_tasks.create'),
    };
    $statusValue = old('status', $record?->status ?? QuickTask::StatusNew);
    $priorityValue = old('priority', $record?->priority ?? QuickTask::PriorityNormal);
    $assigneeOption = $selectedAssigneeOption ?? null;
    $selectedBoardDocNum = $selectedBoardDocNum ?? old('task_board_doc_num', $record?->taskBoard?->doc_num);
    $selectedBoardLabel = $record?->taskBoard ? trim(implode(' / ', array_filter([$record->taskBoard->name, $record->taskBoard->doc_num]))) : null;
    $settings = app(SettingService::class);
    $erpAsset = app(AssetVersionService::class);
    $richText = app(QuickTaskService::class);
    $detailsValue = old('details', $record?->details ?? '');
    $detailsHtml = $richText->sanitizeRichTextContent($detailsValue) ?? '';
    $editorDirection = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
    $existingAttachments = $record?->attachments ?? collect();
    $formatFileSize = static function (int $bytes): string {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    };
@endphp

@section('title', $title)

@push('styles')
    <link href="{{ $erpAsset->url('vendors/summernote/summernote-bs5.min.css') }}" rel="stylesheet">
    <link href="{{ $erpAsset->url('assets/css/modules/Core/quick-tasks.css') }}" rel="stylesheet">
@endpush

@section('content')
    <form id="quick-task-form" class="quick-task-form" action="{{ $action }}" method="POST" enctype="multipart/form-data" data-mode="{{ $mode }}" novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">

        <div class="card">
            @include('modules.core.quick-tasks.partials.form-header')

            <div class="card-body quick-task-form-body">
                <div data-form-alert></div>

                <div class="quick-task-form-section">
                    <div class="quick-task-form-section-title">
                        <h6 class="mb-0">{{ __('quick_tasks.sections.details') }}</h6>
                    </div>

                    <div class="row g-3 align-items-start">
                        @if (! $isCreate)
                            <div class="col-md-3 col-xl-2">
                                <x-forms.view-field
                                    for="doc_num"
                                    as="display"
                                    :label="__('quick_tasks.attributes.doc_num')"
                                    :value="$record?->doc_num"
                                    input-class="text-center dt-code-value"
                                    dir="ltr"
                                />
                            </div>
                        @else
                            <div class="col-md-3 col-xl-2">
                                <x-forms.view-field
                                    for="doc_num"
                                    as="display"
                                    :label="__('quick_tasks.attributes.doc_num')"
                                    :value="__('quick_tasks.placeholders.auto_number')"
                                    input-class="text-center text-600"
                                />
                            </div>
                        @endif

                        <div class="col-md-6 col-xl-7">
                            <x-forms.label for="title" :label="__('quick_tasks.attributes.title')" required />
                            @if ($isView)
                                <x-forms.view-field for="title" :value="old('title', $record?->title)" />
                            @else
                                <input class="form-control" id="title" name="title" type="text" value="{{ old('title', $record?->title) }}" maxlength="255" placeholder="{{ __('quick_tasks.placeholders.title') }}" required @disabled($isTrashed)>
                            @endif
                            <div class="invalid-feedback" data-error-for="title"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="status" :label="__('quick_tasks.attributes.status')" required />
                            @if ($isView)
                                <x-forms.view-field for="status" :value="$statusValue ? __('quick_tasks.statuses.'.$statusValue) : null" />
                            @else
                                <select class="form-select" id="status" name="status" required @disabled($isTrashed)>
                                    @foreach (QuickTask::Statuses as $status)
                                        <option value="{{ $status }}" @selected($statusValue === $status)>{{ __("quick_tasks.statuses.{$status}") }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <div class="invalid-feedback" data-error-for="status"></div>
                        </div>

                        <div class="col-md-6">
                            <x-forms.label for="task_board_doc_num" :label="__('quick_tasks.attributes.task_board')" />
                            @if ($isView)
                                <x-forms.view-field for="task_board_doc_num" :value="$selectedBoardLabel" />
                            @else
                                <select class="form-select js-select2-ajax" id="task_board_doc_num" name="task_board_doc_num" data-url="{{ route('admin.select2.task-boards') }}" data-placeholder="{{ __('quick_tasks.placeholders.task_board') }}" data-allow-clear="true" @disabled($isTrashed)>
                                    @if ($selectedBoardDocNum && $selectedBoardLabel)
                                        <option value="{{ $selectedBoardDocNum }}" selected>{{ $selectedBoardLabel }}</option>
                                    @endif
                                </select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="task_board_doc_num"></div>
                        </div>

                        <div class="col-md-6">
                            <x-forms.label for="assigned_to_doc_num" :label="__('quick_tasks.attributes.assigned_to')" />
                            @if ($isView)
                                <x-forms.view-field for="assigned_to_doc_num" :value="$assigneeOption['text'] ?? null" />
                            @else
                                <select class="form-select js-select2-ajax" id="assigned_to_doc_num" name="assigned_to_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('quick_tasks.placeholders.assigned_to') }}" data-allow-clear="true" @disabled($isTrashed)>
                                    @if ($assigneeOption)
                                        <option value="{{ $assigneeOption['id'] }}" selected>{{ $assigneeOption['text'] }}</option>
                                    @endif
                                </select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="assigned_to_doc_num"></div>
                        </div>

                        <div class="col-md-3">
                            <x-forms.label for="priority" :label="__('quick_tasks.attributes.priority')" />
                            @if ($isView)
                                <x-forms.view-field for="priority" :value="$priorityValue ? __('quick_tasks.priorities.'.$priorityValue) : null" />
                            @else
                                <select class="form-select" id="priority" name="priority" @disabled($isTrashed)>
                                    @foreach (QuickTask::Priorities as $priority)
                                        <option value="{{ $priority }}" @selected($priorityValue === $priority)>{{ __("quick_tasks.priorities.{$priority}") }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <div class="invalid-feedback" data-error-for="priority"></div>
                        </div>

                        <div class="col-md-9">
                            <x-forms.label for="summary" :label="__('quick_tasks.attributes.summary')" />
                            @if ($isView)
                                <x-forms.view-field for="summary" :value="old('summary', $record?->summary)" />
                            @else
                                <input class="form-control" id="summary" name="summary" type="text" value="{{ old('summary', $record?->summary) }}" maxlength="500" placeholder="{{ __('quick_tasks.placeholders.summary') }}" @disabled($isTrashed)>
                            @endif
                            <div class="invalid-feedback" data-error-for="summary"></div>
                        </div>

                        <div class="col-12">
                            <x-forms.label for="details" :label="__('quick_tasks.attributes.details')" />
                            @if ($isView)
                                <div id="details" class="px-3 py-2 border form-control-plaintext rounded-2 quick-task-rich-content">{!! $detailsHtml !== '' ? $detailsHtml : e(__('common.empty_value')) !!}</div>
                            @else
                                <textarea class="form-control js-quick-task-rich-editor" id="details" name="details" rows="8" data-direction="{{ $editorDirection }}" placeholder="{{ __('quick_tasks.placeholders.details') }}" @disabled($isTrashed)>{{ $detailsValue }}</textarea>
                            @endif
                            <div class="invalid-feedback" data-error-for="details"></div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 quick-task-form-section">


                    @if (! $isView && ! $isTrashed)
                        @can('quick_tasks.manage_attachments')
                            @can('file_manager.view')
                                <div class="p-3 mb-3 border quick-task-attachment-picker rounded-2 bg-body-tertiary">
                                <div class="flex-wrap gap-2 d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="fw-semibold">{{ __('quick_tasks.actions.add_attachment') }}</div>
                                        <div class="small text-600">{{ __('quick_tasks.help.attachments') }}</div>
                                    </div>
                                    <button type="button"
                                        class="btn btn-falcon-primary btn-sm js-quick-task-attachment-picker-trigger"
                                        data-file-picker
                                        data-picker-accept="document"
                                        data-picker-max="1"
                                        data-picker-title="{{ __('quick_tasks.actions.choose_files') }}"
                                        data-picker-collection="quick_task_attachments"
                                        data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                        data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                        <span class="fas fa-paperclip me-1"></span>{{ __('quick_tasks.actions.choose_files') }}
                                    </button>
                                </div>
                                <div id="quick_task_attachment_file_inputs"></div>
                                <div class="invalid-feedback d-block" data-error-for="attachments"></div>
                                <div class="invalid-feedback d-block" data-error-for="attachment_file_doc_nums"></div>
                                </div>

                                <div class="mb-3 table-responsive d-none" id="quick-task-selected-attachments-wrap">
                                    <table class="table mb-0 align-middle table-sm quick-task-attachments-table">
                                        <thead class="bg-100 text-900">
                                            <tr>
                                                <th>{{ __('quick_tasks.attributes.attachments') }}</th>
                                                <th class="white-space-nowrap">{{ __('quick_tasks.attributes.file_size') }}</th>
                                                <th class="text-end"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="quick-task-selected-attachments"></tbody>
                                    </table>
                                </div>
                            @else
                                <div class="mb-3 alert alert-subtle-warning">{{ __('quick_tasks.messages.file_manager_required') }}</div>
                            @endcan
                        @endcan
                    @endif

                    @if (! $isCreate && $existingAttachments->isNotEmpty())
                        <div class="table-responsive">
                            <table class="table mb-0 align-middle table-sm quick-task-attachments-table">
                                <thead class="bg-100 text-900">
                                    <tr>
                                        <th>{{ __('quick_tasks.attributes.attachments') }}</th>
                                        <th class="white-space-nowrap">{{ __('quick_tasks.attributes.file_size') }}</th>
                                        <th class="white-space-nowrap">{{ __('quick_tasks.attributes.uploaded_by') }}</th>
                                        <th class="white-space-nowrap">{{ __('quick_tasks.attributes.uploaded_at') }}</th>
                                        <th class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($existingAttachments as $attachment)
                                        <tr data-attachment-uuid="{{ $attachment->public_uuid }}">
                                            <td class="min-w-0">
                                                <div class="fw-semibold text-truncate" title="{{ $attachment->original_name }}">
                                                    <span class="fas fa-paperclip text-500 me-1"></span>{{ $attachment->original_name }}
                                                </div>
                                                <div class="text-600 fs-11">{{ $attachment->archiveFile?->doc_num ?? $attachment->mime_type }}</div>
                                            </td>
                                            <td class="white-space-nowrap" dir="ltr">{{ $formatFileSize((int) $attachment->size) }}</td>
                                            <td class="white-space-nowrap">{{ $attachment->uploadedBy ? trim(implode(' / ', array_filter([$attachment->uploadedBy->name, $attachment->uploadedBy->doc_num]))) : __('common.empty_value') }}</td>
                                            <td class="white-space-nowrap">{{ $settings->formatDateTime($attachment->created_at, '') }}</td>
                                            <td class="text-end white-space-nowrap">
                                                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.quick-tasks.attachments.show', $attachment->public_uuid) }}" target="_blank" rel="noopener">
                                                    <span class="fas fa-external-link-alt"></span>
                                                    <span class="d-none d-md-inline ms-1">{{ __('quick_tasks.actions.open_attachment') }}</span>
                                                </a>
                                                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.quick-tasks.attachments.download', $attachment->public_uuid) }}">
                                                    <span class="fas fa-download"></span>
                                                    <span class="d-none d-md-inline ms-1">{{ __('quick_tasks.actions.download_attachment') }}</span>
                                                </a>
                                                @can('quick_tasks.manage_attachments')
                                                    @unless ($isTrashed || $isView)
                                                        <button class="btn btn-falcon-danger btn-sm js-delete-quick-task-attachment" type="button" data-url="{{ route('admin.quick-tasks.attachments.destroy', $attachment->public_uuid) }}">
                                                            <span class="fas fa-times"></span>
                                                            <span class="visually-hidden">{{ __('quick_tasks.actions.remove_attachment') }}</span>
                                                        </button>
                                                    @endunless
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-600" id="quick-task-existing-attachments-empty">{{ __('quick_tasks.empty.attachments') }}</div>
                    @endif
                </div>

                @if (! $isCreate)
                    <div class="mb-0 quick-task-form-section">
                        <div class="quick-task-form-section-title">
                            <h6 class="mb-0">{{ __('quick_tasks.sections.audit_info') }}</h6>
                        </div>
                        <x-audit-fields-row
                            :metadata="$metadata"
                            :show-deleted="$isView && ($record?->trashed() ?? false)"
                            :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                        />
                    </div>
                @endif
            </div>

            @include('modules.core.quick-tasks.partials.form-footer')
        </div>
    </form>

    @if (! $isView)
        @can('quick_tasks.manage_attachments')
            @can('file_manager.view')
            <x-file-picker-modal />
            @endcan
        @endcan
    @endif
@endsection

@push('scripts')
    @php
        $quickTaskMessages = [
            'deleteConfirmTitle' => __('quick_tasks.messages.delete_confirm_title'),
            'deleteConfirmText' => __('quick_tasks.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('quick_tasks.messages.delete_confirm_yes'),
            'restoreConfirmTitle' => __('quick_tasks.messages.restore_confirm_title'),
            'restoreConfirmText' => __('quick_tasks.messages.restore_confirm_text'),
            'restoreConfirmYes' => __('quick_tasks.messages.restore_confirm_yes'),
            'attachmentDeleteConfirmTitle' => __('quick_tasks.messages.attachment_delete_confirm_title'),
            'attachmentDeleteConfirmText' => __('quick_tasks.messages.attachment_delete_confirm_text'),
            'attachmentDeleteConfirmYes' => __('quick_tasks.messages.attachment_delete_confirm_yes'),
            'attachmentDuplicate' => __('quick_tasks.messages.attachment_duplicate'),
            'saved' => __('common.messages.saved_successfully'),
            'noChanges' => __('common.messages.no_changes'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'cancel' => __('common.actions.cancel'),
            'no' => __('common.actions.no'),
            'confirm' => __('common.actions.confirm'),
            'yes' => __('common.actions.yes'),
            'displayMode' => __('quick_tasks.actions.display_mode'),
            'exitDisplayMode' => __('quick_tasks.actions.exit_display_mode'),
            'removeAttachment' => __('quick_tasks.actions.remove_attachment'),
        ];
    @endphp
    <script>
        window.coreQuickTasksMessages = @json($quickTaskMessages);
    </script>
    <script src="{{ $erpAsset->url('vendors/summernote/summernote-bs5.min.js') }}"></script>
    <script src="{{ $erpAsset->url('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    @if (! $isView)
        @can('quick_tasks.manage_attachments')
            @can('file_manager.view')
            <script src="{{ $erpAsset->url('assets/js/modules/Core/file-picker.js') }}"></script>
            @endcan
        @endcan
    @endif
    <script src="{{ $erpAsset->url('assets/js/modules/Core/quick-tasks.js') }}"></script>
@endpush
