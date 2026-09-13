@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $isProtectedRole = $isProtectedRole ?? false;
    $isProtectedReadonly = $isProtectedReadonly ?? false;
    $isReadonly = $isView || $isProtectedReadonly;
    $isCloneSource = $mode === 'create' && $role;
    $title = match ($mode) {
        'edit' => __('auth.roles.edit'),
        'view' => __('auth.roles.view'),
        'clone' => __('roles.titles.clone'),
        default => __('auth.roles.create'),
    };
    $roleName = ($isClone || $isCloneSource) && $role ? __('roles.defaults.clone_name', ['name' => $role->name]) : $role?->name;
    $documentNumberValue = old('doc_number', ($isEdit || $isView) ? $role?->doc_number : '');
    $originalRoleData = [
        'name' => $roleName,
        'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $role?->doc_number : '') : null,
        'notes' => $role?->notes,
        'permissions' => $assignedPermissions,
        'accessible_company_doc_nums' => $canManageOperatingScope && ! $isReadonly ? $assignedCompanyDocNums : [],
        'accessible_branch_doc_nums' => $canManageOperatingScope && ! $isReadonly ? $assignedBranchDocNums : [],
        'accessible_financial_period_doc_nums' => $canManageOperatingScope && ! $isReadonly ? $assignedFinancialPeriodDocNums : [],
    ];
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
@endphp

@section('title', $title)

@section('content')
    <form id="role-form" class="js-role-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalRoleData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        @unless ($isProtectedReadonly)
            <x-forms.input type="hidden" name="submit_action" value="save" />
        @endunless
        @if (($isClone || $isCloneSource) && $cloneSourceToken)
            <x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />
        @endif

        <div class="card">
            @include('modules.auth.roles.partials.form-header')

            <div class="card-body js-role-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-role-alert" role="alert">
                    <span class="js-role-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                @if ($isProtectedReadonly)
                    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert" data-protected-role-alert>
                        <span class="fas fa-shield-alt mt-1" aria-hidden="true"></span>
                        <div>{{ __('roles.messages.protected_readonly_warning') }}</div>
                    </div>
                @endif

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="role-doc-number">{{ __('common.fields.document_number') }}</label>
                            @if ($isReadonly)
                                <x-forms.view-field for="role-doc-number" as="display" :value="$documentNumberValue" input-class="text-center js-role-doc-number" />
                            @else
                                <x-forms.input id="role-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control js-role-doc-number" value="{{ $documentNumberValue }}" placeholder="{{ __('roles.document_number_control.placeholder') }}" />
                            @endif
                            <div class="form-text">{{ __('roles.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="role-doc-number-display"
                                as="display"
                                :label="__('common.fields.doc_number')"
                                :value="$role?->doc_number"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-9 col-lg-10' : 'col-12' }}">
                        <x-forms.label for="role-name" :label="__('auth.roles.name')" required />
                        @if ($isReadonly)
                            <x-forms.view-field for="role-name" :value="old('name', $roleName)" />
                        @else
                            <x-forms.input id="role-name" autofocus name="name" type="text" class="form-control" value="{{ old('name', $roleName) }}" required />
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="role-notes">{{ __('common.fields.notes') }}</label>
                        @if ($isReadonly)
                            <x-forms.view-field for="role-notes" as="textarea" :value="old('notes', $role?->notes)" rows="4" />
                        @else
                            <x-forms.textarea id="role-notes" name="notes" class="form-control" rows="4">{{ old('notes', $role?->notes) }}</x-forms.textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>
                </div>

                @if ($isEdit || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($role?->trashed() ?? false)"
                        :show-restored="$isView && ! ($role?->trashed() ?? false) && (($role?->restored_at ?? null) || ($role?->restored_by ?? null))"
                    />
                @endif

                <hr>

                @include('modules.auth.roles.partials.permissions')

                <hr>

                @include('modules.auth.roles.partials.company-access')
            </div>

            @include('modules.auth.roles.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $rolesMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'deleteConfirmTitle' => __('auth.roles.messages.delete_confirm_title'),
            'deleteConfirmText' => __('auth.roles.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('auth.roles.messages.delete_confirm_yes'),
            'restore' => __('roles.trash.restore'),
            'restoreConfirmTitle' => __('roles.trash.restore_confirm_title'),
            'restoreConfirmText' => __('roles.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('roles.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.rolesMessages = @json($rolesMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/roles.js') }}"></script>
@endpush
