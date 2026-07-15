@extends('layouts.app')

@php
    use Modules\Core\Services\AssetVersionService;
    use Modules\Core\Models\TaskBoard;

    $record = $board ?? null;
    $isView = $mode === 'view';
    $isCreate = $mode === 'create';
    $isTrashed = (bool) ($record?->trashed() ?? false);
    $title = match ($mode) {
        'edit' => __('task_boards.edit'),
        'view' => __('task_boards.view'),
        default => __('task_boards.create'),
    };
    $selectedUsers = $selectedUserOptions ?? [];
    $selectedRoles = $selectedRoleOptions ?? [];
    $selectedUserLabel = collect($selectedUsers)->pluck('text')->filter()->implode(', ');
    $selectedRoleLabel = collect($selectedRoles)->pluck('text')->filter()->implode(', ');
    $displayUrl = $record ? $record->displayUrl() : '';
    $userDisplayUrl = $record ? $record->userDisplayUrl() : '';
    $canManagePublicSettings = auth()->user()?->can('task_boards.public_settings');
    $displayTheme = old('display_theme', $record?->display_theme ?? TaskBoard::DisplayThemeLight);
    $displayTheme = in_array($displayTheme, TaskBoard::DisplayThemes, true) ? $displayTheme : TaskBoard::DisplayThemeLight;
    $erpAsset = app(AssetVersionService::class);
@endphp

@section('title', $title)

@section('content')
    <form id="task-board-form" class="task-board-form" action="{{ $action }}" method="POST" data-mode="{{ $mode }}" novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">

        <div class="card">
            @include('modules.core.task-boards.partials.form-header')

            <div class="card-body">
                <div data-form-alert></div>

                <div class="border-bottom pb-2 mb-3">
                    <h6 class="mb-0">{{ __('task_boards.sections.basic_data') }}</h6>
                </div>

                <div class="row g-3 align-items-start">
                    @if (! $isCreate)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="doc_num"
                                :label="__('task_boards.attributes.doc_num')"
                                :value="$record?->doc_num"
                                dir="ltr"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $isCreate ? 'col-md-8 col-lg-7' : 'col-md-6 col-lg-7' }}">
                        <x-forms.label for="name" :label="__('task_boards.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="name" :value="old('name', $record?->name)" />
                        @else
                            <input class="form-control" id="name" name="name" type="text" value="{{ old('name', $record?->name) }}" maxlength="255" placeholder="{{ __('task_boards.placeholders.name') }}" required autofocus>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-md-4 col-lg-3">
                        @if ($isView)
                            <x-forms.view-field
                                for="is_active"
                                :label="__('task_boards.attributes.operational_status')"
                                :value="$record?->is_active ? __('task_boards.statuses.active') : __('task_boards.statuses.inactive')"
                            />
                        @else
                            <input name="is_active" type="hidden" value="0">
                            <label class="form-label d-block" for="is_active">{{ __('task_boards.attributes.operational_status') }}</label>
                            <div class="form-check form-switch mb-0 pt-1">
                                <input class="form-check-input" id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $record?->is_active ?? true))>
                                <label class="form-check-label" for="is_active">{{ __('task_boards.statuses.active') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback" data-error-for="is_active"></div>
                    </div>

                    <div class="col-12">
                        <x-forms.label for="description" :label="__('task_boards.attributes.description')" />
                        @if ($isView)
                            <x-forms.view-field for="description" as="textarea" :value="old('description', $record?->description)" rows="4" />
                        @else
                            <textarea class="form-control" id="description" name="description" rows="4" placeholder="{{ __('task_boards.placeholders.description') }}">{{ old('description', $record?->description) }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="description"></div>
                    </div>
                </div>

                <div class="border-bottom pb-2 mt-4 mb-3">
                    <h6 class="mb-0">{{ __('task_boards.sections.assignment') }}</h6>
                </div>

                <div class="row g-3 align-items-start">
                    <div class="col-md-6">
                        <x-forms.label for="user_doc_nums" :label="__('task_boards.attributes.users')" />
                        @if ($isView)
                            <x-forms.view-field for="user_doc_nums" :value="$selectedUserLabel" />
                        @else
                            <select class="form-select js-select2-ajax" id="user_doc_nums" name="user_doc_nums[]" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('task_boards.placeholders.users') }}" data-allow-clear="true" multiple>
                                @foreach ($selectedUsers as $option)
                                    <option value="{{ $option['id'] }}" selected>{{ $option['text'] }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="user_doc_nums"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="role_doc_nums" :label="__('task_boards.attributes.user_groups')" />
                        @if ($isView)
                            <x-forms.view-field for="role_doc_nums" :value="$selectedRoleLabel" />
                        @else
                            <select class="form-select js-select2-ajax" id="role_doc_nums" name="role_doc_nums[]" data-url="{{ route('admin.select2.roles') }}" data-placeholder="{{ __('task_boards.placeholders.user_groups') }}" data-allow-clear="true" multiple>
                                @foreach ($selectedRoles as $option)
                                    <option value="{{ $option['id'] }}" selected>{{ $option['text'] }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="role_doc_nums"></div>
                    </div>
                </div>

                <div class="border-bottom pb-2 mt-4 mb-3">
                    <h6 class="mb-0">{{ __('task_boards.sections.display_settings') }}</h6>
                </div>

                <div class="row g-3 align-items-start">
                    @if (! $isCreate)
                        <div class="col-12">
                            <x-forms.label for="display_url" :label="__('task_boards.attributes.display_url')" />
                            <div class="input-group">
                                <input class="form-control" id="display_url" type="text" value="{{ $displayUrl }}" readonly dir="ltr">
                                @if (! $isTrashed)
                                    @can('task_boards.display')
                                        <button class="btn btn-falcon-default js-copy-task-board-url" type="button" data-task-board-public-link="display" data-display-url="{{ $displayUrl }}">
                                            <span class="fas fa-copy me-1"></span>{{ __('task_boards.actions.copy_url') }}
                                        </button>
                                        <a class="btn btn-falcon-info" href="{{ $displayUrl }}" target="_blank" rel="noopener" data-task-board-public-link="display">
                                            <span class="fas fa-external-link-alt me-1"></span>{{ __('task_boards.actions.open_display') }}
                                        </a>
                                    @endcan
                                @endif
                            </div>
                        </div>

                        <div class="col-12">
                            <x-forms.label for="user_display_url" :label="__('task_boards.attributes.user_display_url')" />
                            <div class="input-group">
                                <input class="form-control" id="user_display_url" type="text" value="{{ $userDisplayUrl }}" readonly dir="ltr">
                                @if (! $isTrashed)
                                    @can('task_boards.display')
                                        <button class="btn btn-falcon-default js-copy-task-board-url" type="button" data-task-board-public-link="user-display" data-display-url="{{ $userDisplayUrl }}">
                                            <span class="fas fa-copy me-1"></span>{{ __('task_boards.actions.copy_user_display_url') }}
                                        </button>
                                        <a class="btn btn-falcon-info" href="{{ $userDisplayUrl }}" target="_blank" rel="noopener" data-task-board-public-link="user-display">
                                            <span class="fas fa-external-link-alt me-1"></span>{{ __('task_boards.actions.user_display') }}
                                        </a>
                                    @endcan
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="col-md-4">
                        @if ($isView || ! $canManagePublicSettings)
                            <x-forms.view-field
                                for="is_public"
                                :label="__('task_boards.attributes.public_status')"
                                :value="$record?->is_public ? __('task_boards.statuses.public') : __('task_boards.statuses.private')"
                            />
                        @else
                            <input name="is_public" type="hidden" value="0">
                            <label class="form-label d-block" for="is_public">{{ __('task_boards.attributes.public_status') }}</label>
                            <div class="form-check form-switch mb-0 pt-1">
                                <input class="form-check-input" id="is_public" name="is_public" type="checkbox" value="1" @checked(old('is_public', $record?->is_public ?? false))>
                                <label class="form-check-label" for="is_public">{{ __('task_boards.statuses.public') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback" data-error-for="is_public"></div>
                    </div>

                    <div class="col-md-4">
                        @if ($isView || ! $canManagePublicSettings)
                            <x-forms.view-field
                                for="requires_password"
                                :label="__('task_boards.attributes.access_code_status')"
                                :value="$record?->requires_password ? __('task_boards.statuses.requires_access_code') : __('task_boards.statuses.no_access_code_required')"
                            />
                        @else
                            <input name="requires_password" type="hidden" value="0">
                            <label class="form-label d-block" for="requires_password">{{ __('task_boards.attributes.access_code_status') }}</label>
                            <div class="form-check form-switch mb-0 pt-1">
                                <input class="form-check-input" id="requires_password" name="requires_password" type="checkbox" value="1" @checked(old('requires_password', $record?->requires_password ?? false))>
                                <label class="form-check-label" for="requires_password">{{ __('task_boards.statuses.requires_access_code') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback" data-error-for="requires_password"></div>
                    </div>

                    <div class="col-md-4">
                        @if ($isView || ! $canManagePublicSettings)
                            <x-forms.view-field
                                for="display_theme"
                                :label="__('task_boards.attributes.display_theme')"
                                :value="__('task_boards.display_themes.' . $displayTheme)"
                            />
                        @else
                            <x-forms.label for="display_theme" :label="__('task_boards.attributes.display_theme')" />
                            <select class="form-select" id="display_theme" name="display_theme">
                                @foreach (TaskBoard::DisplayThemes as $theme)
                                    <option value="{{ $theme }}" @selected($displayTheme === $theme)>{{ __('task_boards.display_themes.' . $theme) }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="display_theme"></div>
                    </div>

                    @if (! $isView && $canManagePublicSettings)
                        <div class="col-md-4">
                            <x-forms.label for="access_code" :label="__('task_boards.attributes.access_code')" />
                            <input class="form-control" id="access_code" name="access_code" type="password" value="" maxlength="100" placeholder="{{ __('task_boards.placeholders.access_code') }}" autocomplete="new-password">
                            <div class="invalid-feedback" data-error-for="access_code"></div>
                        </div>

                        @if (! $isCreate && $record?->public_password_hash)
                            <div class="col-md-8 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" id="clear_access_code" name="clear_access_code" type="checkbox" value="1">
                                    <label class="form-check-label" for="clear_access_code">{{ __('common.actions.clear') }} {{ __('task_boards.attributes.access_code') }}</label>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                @if (! $isCreate)
                    <div class="border-bottom pb-2 mt-4 mb-3">
                        <h6 class="mb-0">{{ __('task_boards.sections.audit_info') }}</h6>
                    </div>

                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && $isTrashed"
                        :show-restored="$isView && ! $isTrashed && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>

            @include('modules.core.task-boards.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $taskBoardMessages = [
            'deleteConfirmTitle' => __('task_boards.messages.delete_confirm_title'),
            'deleteConfirmText' => __('task_boards.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('task_boards.messages.delete_confirm_yes'),
            'regenerateConfirmTitle' => __('task_boards.messages.regenerate_confirm_title'),
            'regenerateConfirmText' => __('task_boards.messages.regenerate_confirm_text'),
            'regenerateConfirmYes' => __('task_boards.messages.regenerate_confirm_yes'),
            'restoreConfirmTitle' => __('task_boards.messages.restore_confirm_title'),
            'restoreConfirmText' => __('task_boards.messages.restore_confirm_text'),
            'restoreConfirmYes' => __('task_boards.messages.restore_confirm_yes'),
            'deleted' => __('task_boards.messages.deleted'),
            'saved' => __('common.messages.saved_successfully'),
            'noChanges' => __('common.messages.no_changes'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'urlCopied' => __('task_boards.messages.url_copied'),
            'copyFailed' => __('task_boards.messages.copy_failed'),
            'cancel' => __('common.actions.cancel'),
            'confirm' => __('common.actions.confirm'),
        ];
    @endphp
    <script>
        window.coreTaskBoardsMessages = @json($taskBoardMessages);
    </script>
    <script src="{{ $erpAsset->url('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/task-boards.js') }}"></script>
@endpush
