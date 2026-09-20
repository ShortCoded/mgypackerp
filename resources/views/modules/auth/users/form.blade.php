@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $title = match ($mode) {
        'edit' => __('users.edit'),
        'view' => __('users.view'),
        'clone' => __('users.titles.clone'),
        default => __('users.create'),
    };
    $userName = $isClone && $user ? __('users.defaults.clone_name', ['name' => $user->name]) : $user?->name;
    $documentNumberValue = old('doc_number', ($isEdit || $isView) ? $user?->doc_number : '');
    $assignedRoleDocNums = $assignedRoleDocNums ?? [];
    $assignedRoleOptions = $assignedRoleOptions ?? [];
    $originalUserData = [
        'name' => $userName,
        'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $user?->doc_number : '') : null,
        'username' => $isClone ? null : $user?->username,
        'email' => $isClone ? null : $user?->email,
        'phone' => $isClone ? null : $user?->phone,
        'status' => $user?->status ?? 'active',
        'notes' => $user?->notes,
        'roles' => $canManageUserRoles ? $assignedRoleDocNums : [],
    ];
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
    $phoneValue = $isClone ? '' : (string) ($user?->phone ?? '');
@endphp

@section('title', $title)

@section('content')
    <form id="user-form" class="js-user-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalUserData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        @if ($isClone && $cloneSourceToken)
            <x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />
        @endif

        <div class="card">
            @include('modules.auth.users.partials.form-header')

            <div class="card-body js-user-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-user-alert" role="alert">
                    <span class="js-user-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="user-doc-number">{{ __('common.fields.document_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="user-doc-number" as="display" :value="$documentNumberValue" input-class="text-center js-user-doc-number" />
                            @else
                                <x-forms.input id="user-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control js-user-doc-number" value="{{ $documentNumberValue }}" placeholder="{{ __('users.document_number_control.placeholder') }}" />
                            @endif
                            <div class="form-text">{{ __('users.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="user-doc-number-display"
                                as="display"
                                :label="__('common.fields.doc_number')"
                                :value="$user?->doc_number"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-9 col-lg-10' : 'col-12' }}">
                        <x-forms.label for="user-name" :label="__('common.fields.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="user-name" :value="old('name', $userName)" />
                        @else
                            <x-forms.input id="user-name" autofocus name="name" type="text" class="form-control" value="{{ old('name', $userName) }}" required />
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="user-username" :label="__('common.fields.username')" required />
                        @if ($isView)
                            <x-forms.view-field for="user-username" :value="old('username', $isClone ? '' : $user?->username)" />
                        @else
                            <x-forms.input id="user-username" name="username" type="text" class="form-control" value="{{ old('username', $isClone ? '' : $user?->username) }}" autocomplete="username" required />
                        @endif
                        <div class="invalid-feedback" data-error-for="username"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="user-email" :label="__('common.fields.email')" />
                        @if ($isView)
                            @if (filled($user?->email))
                                <x-forms.view-field for="user-email" as="display">
                                    <x-contact.email-link :email="$user?->email" />
                                </x-forms.view-field>
                            @else
                                <x-forms.view-field for="user-email" :value="$user?->email" />
                            @endif
                        @else
                            <x-forms.input id="user-email" name="email" type="email" class="form-control" value="{{ old('email', $isClone ? '' : $user?->email) }}" autocomplete="email" />
                        @endif
                        <div class="invalid-feedback" data-error-for="email"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="user-phone">{{ __('common.fields.phone') }}</label>
                        @if ($isView)
                            @if (filled($phoneValue))
                                <x-forms.view-field for="user-phone" as="display">
                                    <x-contact.phone-actions :phone="$phoneValue" />
                                </x-forms.view-field>
                            @else
                                <x-forms.view-field for="user-phone" :value="$phoneValue" />
                            @endif
                        @else
                            <x-forms.input id="user-phone" name="phone" type="text" class="form-control" value="{{ old('phone', $phoneValue) }}" autocomplete="tel" />
                        @endif
                        <div class="invalid-feedback" data-error-for="phone"></div>
                    </div>

                    <div class="col-md-4">
                        @php
                            $userStatusValue = old('status', $user?->status ?? 'active');
                        @endphp
                        @if ($isView)
                            <x-forms.view-field
                                for="user-status"
                                :label="__('common.fields.status')"
                                :value="$userStatusValue ? __('users.statuses.'.$userStatusValue) : null"
                                required
                            />
                        @else
                            <x-forms.label for="user-status" :label="__('common.fields.status')" required />
                            <x-forms.select id="user-status" name="status" class="form-select" required>
                                @foreach (['active', 'inactive', 'blocked'] as $status)
                                    <option value="{{ $status }}" @selected($userStatusValue === $status)>{{ __("users.statuses.{$status}") }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    @unless ($isView)
                        <div class="col-md-4">
                            <x-forms.label for="user-password" :label="__('users.password')" :required="! $isEdit" />
                            <x-forms.input id="user-password" name="password" type="password" class="form-control" autocomplete="new-password" :required='! $isEdit' />
                            <div class="form-text">{{ $isEdit ? __('users.password_optional_helper') : __('users.password_required_helper') }}</div>
                            <div class="invalid-feedback" data-error-for="password"></div>
                        </div>
                        <div class="col-md-4">
                            <x-forms.label for="user-password-confirmation" :label="__('users.password_confirmation')" :required="! $isEdit" />
                            <x-forms.input id="user-password-confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" :required='! $isEdit' />
                            <div class="invalid-feedback" data-error-for="password_confirmation"></div>
                        </div>
                    @endunless

                    <div class="col-12">
                        <label class="form-label" for="user-notes">{{ __('common.fields.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="user-notes" as="textarea" :value="old('notes', $user?->notes)" rows="4" />
                        @else
                            <x-forms.textarea id="user-notes" name="notes" class="form-control" rows="4">{{ old('notes', $user?->notes) }}</x-forms.textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>

                    @if ($canManageUserRoles)
                        <div class="col-12 js-select2-field">
                            <label class="form-label" for="user-roles">{{ __('common.fields.roles') }}</label>
                            @if ($isView)
                                @if ($assignedRoleOptions !== [])
                                    <x-forms.view-field for="user-roles" as="display">
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach ($assignedRoleOptions as $option)
                                                <span class="badge rounded-pill badge-subtle-primary">{{ $option['text'] }}</span>
                                            @endforeach
                                        </div>
                                    </x-forms.view-field>
                                @else
                                    <x-forms.view-field for="user-roles" />
                                @endif
                            @else
                                <x-forms.input type="hidden" name="_roles_present" value="1" />
                                <x-forms.select id="user-roles"
                                        name="roles[]"
                                        class="form-select js-select2-ajax"
                                        multiple
                                        data-url="{{ route('admin.select2.roles.assignable') }}"
                                        :data-selected-url="$isEdit && $user ? route('admin.select2.users.roles.selected', $user->doc_num) : null"
                                        data-placeholder="{{ __('users.placeholders.roles') }}"
                                        data-allow-clear="true"
                                        data-clear-all="true"
                                        data-clear-all-label="{{ __('common.actions.clear_all') }}"></x-forms.select>
                            @endif
                            <div class="form-text">{{ __('users.roles_helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="roles"></div>
                        </div>
                    @endif
                </div>

                @if ($isEdit || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($user?->trashed() ?? false)"
                        :show-restored="$isView && ! ($user?->trashed() ?? false) && (($user?->restored_at ?? null) || ($user?->restored_by ?? null))"
                    />
                @endif
            </div>

            @include('modules.auth.users.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $usersMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'deleteConfirmTitle' => __('users.messages.delete_confirm_title'),
            'deleteConfirmText' => __('users.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('users.messages.delete_confirm_yes'),
            'restore' => __('users.trash.restore'),
            'restoreConfirmTitle' => __('users.trash.restore_confirm_title'),
            'restoreConfirmText' => __('users.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('users.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.usersMessages = @json($usersMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/users.js') }}"></script>
@endpush
