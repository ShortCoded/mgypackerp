@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $isTrashed = $record?->trashed() ?? false;
    $title = match ($mode) {
        'view' => __('screen_data_visibility_rules.view'),
        'edit' => __('screen_data_visibility_rules.edit'),
        'clone' => __('screen_data_visibility_rules.clone'),
        default => __('screen_data_visibility_rules.create'),
    };
    $recordScope = old('record_scope', $record?->record_scope?->value ?? 'own_records');
    $durationUnit = old('duration_unit', $record?->duration_unit?->value);
    $isActive = (bool) old('is_active', $isClone ? false : ($record?->is_active ?? true));
    $original = [
        'user_doc_num' => (string) ($selectedUser?->doc_num ?? ''),
        'screen_key' => (string) ($record?->screen_key ?? ''),
        'record_scope' => $recordScope,
        'max_visible_records' => $record?->max_visible_records === null ? '' : (string) $record->max_visible_records,
        'duration_value' => $record?->duration_value === null ? '' : (string) $record->duration_value,
        'duration_unit' => (string) ($durationUnit ?? ''),
        'is_active' => $isActive,
        'notes' => trim((string) ($record?->notes ?? '')),
    ];
@endphp

@section('title', $title)

@section('content')
    <form class="js-screen-visibility-rule-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($original)' novalidate>
        @csrf
        @if ($method !== 'POST') @method($method) @endif
        @unless($isView)<x-forms.input type="hidden" name="submit_action" value="save" />@endunless
        @if ($cloneSourceToken)<x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />@endif

        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h5 class="mb-0">{{ $title }}</h5>
                    @if ($record?->doc_num)<div class="text-500 fs-10 mt-1" dir="ltr">{{ $record->doc_num }}</div>@endif
                </div>
                @include('modules.auth.screen-data-visibility-rules.partials.form-actions')
            </div>
            <div class="card-body js-screen-visibility-rule-form-body">
                <div class="alert alert-danger d-none js-screen-visibility-rule-alert" role="alert"><span class="js-screen-visibility-rule-alert-message"></span></div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <x-forms.label for="visibility-rule-user" :label="__('screen_data_visibility_rules.attributes.user_doc_num')" required />
                        @if ($isView)
                            <x-forms.view-field for="visibility-rule-user" :value="$selectedUser ? trim($selectedUser->name.' / '.$selectedUser->doc_num) : null" />
                        @else
                            <x-forms.select id="visibility-rule-user" name="user_doc_num" class="form-select js-screen-visibility-user" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('screen_data_visibility_rules.placeholders.user') }}" required>
                                @if ($selectedUser)<option value="{{ $selectedUser->doc_num }}" selected>{{ trim($selectedUser->name.' / '.$selectedUser->doc_num) }}</option>@endif
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="user_doc_num"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="visibility-rule-screen" :label="__('screen_data_visibility_rules.attributes.screen_key')" required />
                        @if ($isView)
                            @php
                                $selectedScreen = collect($screenOptions)->firstWhere('id', $record?->screen_key);
                            @endphp
                            <x-forms.view-field for="visibility-rule-screen" :value="$selectedScreen['text'] ?? $record?->screen_key" />
                        @else
                            <x-forms.select id="visibility-rule-screen" name="screen_key" class="form-select js-screen-visibility-screen" data-placeholder="{{ __('screen_data_visibility_rules.placeholders.screen') }}" required>
                                <option value=""></option>
                                @foreach (collect($screenOptions)->groupBy('module') as $module => $options)
                                    <optgroup label="{{ $module }}">
                                        @foreach ($options as $option)
                                            <option value="{{ $option['id'] }}" data-supported="{{ $option['supported'] ? '1' : '0' }}" data-reason="{{ $option['reason'] }}" @selected(old('screen_key', $record?->screen_key) === $option['id']) @disabled(! $option['supported'])>
                                                {{ $option['text'] }}{{ $option['supported'] ? '' : ' — '.__('screen_data_visibility_rules.help.unsupported') }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="screen_key"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="visibility-rule-scope" :label="__('screen_data_visibility_rules.attributes.record_scope')" required />
                        @if ($isView)
                            <x-forms.view-field for="visibility-rule-scope" :value="__('screen_data_visibility_rules.scopes.'.$recordScope)" />
                        @else
                            <x-forms.select id="visibility-rule-scope" name="record_scope" class="form-select" required>
                                @foreach (['own_records', 'authorized_scope'] as $scope)<option value="{{ $scope }}" @selected($recordScope === $scope)>{{ __('screen_data_visibility_rules.scopes.'.$scope) }}</option>@endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="record_scope"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="visibility-rule-maximum">{{ __('screen_data_visibility_rules.attributes.max_visible_records') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="visibility-rule-maximum" :value="$record?->max_visible_records ?? __('screen_data_visibility_rules.unlimited')" />
                        @else
                            <x-forms.input id="visibility-rule-maximum" name="max_visible_records" type="number" min="1" step="1" class="form-control" value="{{ old('max_visible_records', $record?->max_visible_records) }}" placeholder="{{ __('screen_data_visibility_rules.placeholders.maximum') }}" />
                            <div class="form-text">{{ __('screen_data_visibility_rules.help.maximum') }}</div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="max_visible_records"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="visibility-rule-duration-value">{{ __('screen_data_visibility_rules.attributes.duration_value') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="visibility-rule-duration-value" :value="$record?->duration_value ?? __('screen_data_visibility_rules.unlimited')" />
                        @else
                            <x-forms.input id="visibility-rule-duration-value" name="duration_value" type="number" min="1" step="1" class="form-control" value="{{ old('duration_value', $record?->duration_value) }}" placeholder="{{ __('screen_data_visibility_rules.placeholders.duration') }}" />
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="duration_value"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="visibility-rule-duration-unit">{{ __('screen_data_visibility_rules.attributes.duration_unit') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="visibility-rule-duration-unit" :value="$durationUnit ? __('screen_data_visibility_rules.duration_units.'.$durationUnit) : __('screen_data_visibility_rules.unlimited')" />
                        @else
                            <x-forms.select id="visibility-rule-duration-unit" name="duration_unit" class="form-select">
                                <option value=""></option>
                                @foreach (['days', 'weeks', 'months', 'years'] as $unit)<option value="{{ $unit }}" @selected($durationUnit === $unit)>{{ __('screen_data_visibility_rules.duration_units.'.$unit) }}</option>@endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="duration_unit"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label d-block" for="visibility-rule-active">{{ __('screen_data_visibility_rules.attributes.is_active') }}</label>
                        @if ($isView)
                            <span class="badge rounded-pill badge-subtle-{{ $isActive ? 'success' : 'secondary' }}">{{ __('screen_data_visibility_rules.statuses.'.($isActive ? 'active' : 'inactive')) }}</span>
                        @else
                            <x-forms.input type="hidden" name="is_active" value="0" />
                            <div class="form-check form-switch mt-2">
                                <x-forms.input id="visibility-rule-active" name="is_active" type="checkbox" class="form-check-input" value="1" :checked='$isActive' />
                                <label class="form-check-label" for="visibility-rule-active">{{ __('screen_data_visibility_rules.statuses.active') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="is_active"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="visibility-rule-notes">{{ __('screen_data_visibility_rules.attributes.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="visibility-rule-notes" as="textarea" :value="$record?->notes" rows="4" />
                        @else
                            <x-forms.textarea id="visibility-rule-notes" name="notes" class="form-control" rows="4">{{ old('notes', $record?->notes) }}</x-forms.textarea>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="notes"></div>
                    </div>

                    <div class="col-12">
                        <div class="alert alert-info mb-0">
                            <div class="fw-semibold mb-1">{{ __('screen_data_visibility_rules.preview.title') }}</div>
                            <div class="js-screen-visibility-preview"></div>
                        </div>
                    </div>
                </div>

                @if ($isEdit || $isView)
                    <x-audit-fields-row :metadata="$metadata" :show-deleted="$isView && $isTrashed" :show-restored="$isView && ! $isTrashed && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))" />
                @endif
            </div>
            <div class="card-footer">@include('modules.auth.screen-data-visibility-rules.partials.form-actions')</div>
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $screenDataVisibilityRuleMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'no' => __('common.actions.no'),
            'deleteConfirmTitle' => __('screen_data_visibility_rules.messages.delete_confirm_title'),
            'deleteConfirmText' => __('screen_data_visibility_rules.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('screen_data_visibility_rules.messages.delete_confirm_yes'),
            'restoreConfirmTitle' => __('screen_data_visibility_rules.messages.restore_confirm_title'),
            'restoreConfirmText' => __('screen_data_visibility_rules.messages.restore_confirm_text'),
            'restoreConfirmYes' => __('screen_data_visibility_rules.messages.restore_confirm_yes'),
            'previewOwn' => __('screen_data_visibility_rules.preview.live_own'),
            'previewAuthorized' => __('screen_data_visibility_rules.preview.live_authorized'),
            'previewLatest' => __('screen_data_visibility_rules.preview.latest'),
            'previewAll' => __('screen_data_visibility_rules.preview.all'),
            'previewDuring' => __('screen_data_visibility_rules.preview.during'),
            'previewAnyTime' => __('screen_data_visibility_rules.preview.any_time'),
            'durationUnits' => __('screen_data_visibility_rules.duration_units'),
        ];
    @endphp
    <script>
        window.screenDataVisibilityRuleMessages = @json($screenDataVisibilityRuleMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/screen-data-visibility-rules.js') }}"></script>
@endpush
