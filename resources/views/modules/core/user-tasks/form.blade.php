@extends('layouts.app')

@php
    $record = $task ?? null;
    $isView = $mode === 'view';
    $isCreate = in_array($mode, ['create', 'clone'], true);
    $assigneeOption = $selectedAssigneeOption ?? null;
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $dateTimeFormat = $dateFormatService->jsDateTimeFormat();
    $formatDateTime = static fn ($value): string => $dateFormatService->formatDateTime($value, '');
    $taskTypeValue = old('type', $record?->type ?? \Modules\Core\Models\UserTask::TypeTask);
    $taskStatusValue = old('status', $record?->status ?? \Modules\Core\Models\UserTask::StatusTodo);
    $taskPriorityValue = old('priority', $record?->priority ?? \Modules\Core\Models\UserTask::PriorityNormal);
    $taskColorValue = old('color', $record?->color ?? 'primary');
@endphp

@section('content')
    <form id="user-task-form" action="{{ $action }}" method="POST" data-mode="{{ $mode }}">
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        @if (! empty($cloneSourceToken))
            <x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />
        @endif

        @include('modules.core.user-tasks.partials.form-header')

        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('user_tasks.sections.details') }}</h6>
            </div>
            <div class="card-body">
                <div data-form-alert></div>
                <div class="row g-3">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-4">
                            <x-forms.label for="doc_number" :label="__('user_tasks.attributes.doc_number')" />
                            @if ($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="! $isCreate ? $record?->doc_number : null" />
                            @else
                                <x-forms.input class="form-control" id="doc_number" name="doc_number" inputmode="numeric" value="{{ ! $isCreate ? $record?->doc_number : '' }}" />
                            @endif
                            <div class="invalid-feedback" data-error-for="doc_number"></div>
                            <div class="form-text">{{ __('user_tasks.document_number_control.helper') }}</div>
                        </div>
                    @endif

                    <div class="col-md-8">
                        <x-forms.label for="title" :label="__('user_tasks.attributes.title')" required />
                        @if ($isView)
                            <x-forms.view-field for="title" :value="old('title', $record?->title)" />
                        @else
                            <x-forms.input class="form-control" id="title" name="title" type="text" value="{{ old('title', $record?->title) }}" required />
                        @endif
                        <div class="invalid-feedback" data-error-for="title"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="type" :label="__('user_tasks.attributes.type')" required />
                        @if ($isView)
                            <x-forms.view-field for="type" :value="$taskTypeValue ? __('user_tasks.types.'.$taskTypeValue) : null" />
                        @else
                            <x-forms.select class="form-select" id="type" name="type" required>
                                @foreach (\Modules\Core\Models\UserTask::Types as $type)
                                    <option value="{{ $type }}" @selected($taskTypeValue === $type)>{{ __("user_tasks.types.{$type}") }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="type"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="status" :label="__('user_tasks.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="status" :value="$taskStatusValue ? __('user_tasks.statuses.'.$taskStatusValue) : null" />
                        @else
                            <x-forms.select class="form-select" id="status" name="status" required>
                                @foreach (\Modules\Core\Models\UserTask::Statuses as $status)
                                    <option value="{{ $status }}" @selected($taskStatusValue === $status)>{{ __("user_tasks.statuses.{$status}") }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="priority" :label="__('user_tasks.attributes.priority')" required />
                        @if ($isView)
                            <x-forms.view-field for="priority" :value="$taskPriorityValue ? __('user_tasks.priorities.'.$taskPriorityValue) : null" />
                        @else
                            <x-forms.select class="form-select" id="priority" name="priority" required>
                                @foreach (\Modules\Core\Models\UserTask::Priorities as $priority)
                                    <option value="{{ $priority }}" @selected($taskPriorityValue === $priority)>{{ __("user_tasks.priorities.{$priority}") }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="priority"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="color" :label="__('user_tasks.attributes.color')" />
                        @if ($isView)
                            <x-forms.view-field for="color" :value="$taskColorValue ? __('user_tasks.colors.'.$taskColorValue) : null" />
                        @else
                            <x-forms.select class="form-select" id="color" name="color">
                                <option value="">{{ __('common.empty_value') }}</option>
                                @foreach (\Modules\Core\Models\UserTask::Colors as $color)
                                    <option value="{{ $color }}" @selected($taskColorValue === $color)>{{ __("user_tasks.colors.{$color}") }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="color"></div>
                    </div>

                    <div class="col-12">
                        <x-forms.label for="description" :label="__('user_tasks.attributes.description')" />
                        @if ($isView)
                            <x-forms.view-field for="description" as="textarea" :value="old('description', $record?->description)" rows="4" />
                        @else
                            <x-forms.textarea class="form-control" id="description" name="description" rows="4" placeholder="{{ __('user_tasks.placeholders.description') }}">{{ old('description', $record?->description) }}</x-forms.textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="description"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('user_tasks.sections.assignment') }}</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <x-forms.label for="assigned_to_doc_num" :label="__('user_tasks.attributes.assigned_to')" />
                        @if ($isView)
                            <x-forms.view-field for="assigned_to_doc_num" :value="$assigneeOption['text'] ?? null" />
                        @else
                            <x-forms.select class="form-select js-select2-ajax" id="assigned_to_doc_num" name="assigned_to_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.assigned_to') }}" data-allow-clear="true">
                                @if ($assigneeOption)
                                    <option value="{{ $assigneeOption['id'] }}" selected>{{ $assigneeOption['text'] }}</option>
                                @endif
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="assigned_to_doc_num"></div>
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="assigned_by" :label="__('user_tasks.attributes.assigned_by')" />
                        <x-forms.view-field for="assigned_by" :value="$record?->assignedBy ? trim(implode(' / ', array_filter([$record->assignedBy->name, $record->assignedBy->doc_num]))) : null" />
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('user_tasks.sections.timeline') }}</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach (['start_at', 'due_at', 'completed_at'] as $dateField)
                        <div class="col-md-4">
                            <x-forms.label :for="$dateField" :label="__('user_tasks.attributes.'.$dateField)" />
                            @if ($isView)
                                <x-forms.view-field :for="$dateField" :value="$formatDateTime($record?->{$dateField})" dir="ltr" />
                            @else
                                <x-forms.date-input class="form-control js-date-picker" id="{{ $dateField }}" name="{{ $dateField }}" type="text" value="{{ old($dateField, $formatDateTime($record?->{$dateField})) }}" data-enable-time="true" data-date-format="{{ $dateTimeFormat }}" dir="ltr" />
                            @endif
                            <div class="invalid-feedback" data-error-for="{{ $dateField }}"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @if (! $isCreate)
            <x-audit-fields-row
                :metadata="$metadata"
                :show-deleted="$isView && ($record?->trashed() ?? false)"
                :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
            />
        @endif

        @include('modules.core.user-tasks.partials.form-footer')
    </form>
@endsection

@push('scripts')
    @php
        $coreUserTasksMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'saved' => __('common.messages.saved_successfully'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'cancel' => __('common.actions.cancel'),
            'confirm' => __('common.actions.confirm'),
        ];
    @endphp
    <script>
        window.coreUserTasksMessages = @json($coreUserTasksMessages);
    </script>
    <script src="{{ asset('assets/js/modules/Core/user-tasks.js') }}"></script>
@endpush
