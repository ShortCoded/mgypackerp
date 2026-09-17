@extends('layouts.app')

@php
    $viewer = auth()->user();
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $canEditProfile = (bool) $viewer?->can('profile.edit');
    $title = $isEdit ? __('profile.edit_title') : __('profile.view_title');
    $displayName = trim((string) $user->name);
    $displayName = $displayName !== '' ? $displayName : __('layout.user');
    $initials = collect(explode(' ', trim($displayName)))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $status = trim((string) $user->status);
    $statusLabel = $status !== '' && \Illuminate\Support\Facades\Lang::has("users.statuses.{$status}")
        ? __("users.statuses.{$status}")
        : ($status !== '' ? \Illuminate\Support\Str::of($status)->headline()->toString() : '');
    $statusClass = match ($status) {
        'active' => 'success',
        'inactive' => 'warning',
        'blocked' => 'danger',
        default => 'secondary',
    };
    $emailValue = trim((string) $user->email);
    $phoneValue = trim((string) $user->phone);
    $lastLoginIp = trim((string) $user->last_login_ip);
    $usernameValue = trim((string) $user->username);
    $roles = $user->relationLoaded('roles') ? $user->roles : collect();
    $profileFields = [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'phone' => $user->phone,
        'notes' => $user->notes,
    ];
    $defaultLoginContext = $defaultLoginContext ?? [
        'company' => null,
        'branch' => null,
        'financial_period' => null,
        'has_saved' => false,
        'is_invalid' => false,
    ];
    $defaultCompany = $defaultLoginContext['company'] ?? null;
    $defaultBranch = $defaultLoginContext['branch'] ?? null;
    $defaultFinancialPeriod = $defaultLoginContext['financial_period'] ?? null;
    $defaultCompanyDocNum = $defaultCompany['id'] ?? null;
    $defaultContextFields = [
        'company_doc_num' => $defaultCompany['id'] ?? '',
        'branch_doc_num' => $defaultBranch['id'] ?? '',
        'financial_period_doc_num' => $defaultFinancialPeriod['id'] ?? '',
    ];
    $hasSavedDefaultContext = (bool) ($defaultLoginContext['has_saved'] ?? false);
    $hasInvalidDefaultContext = (bool) ($defaultLoginContext['is_invalid'] ?? false);
@endphp

@section('title', $title)

@section('content')
    <div class="card mb-3">
        <div class="card-body px-3 py-2">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="avatar avatar-2xl flex-shrink-0">
                    @if ($user->avatar)
                        <img class="rounded-circle img-thumbnail shadow-sm" src="{{ asset($user->avatar) }}" alt="{{ $displayName }}">
                    @else
                        <div class="avatar-name rounded-circle img-thumbnail shadow-sm bg-primary-subtle text-primary">
                            <span class="fs-7">{{ $initials ?: 'U' }}</span>
                        </div>
                    @endif
                </div>

                <div class="flex-1 min-w-0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h5 class="mb-0 text-truncate">{{ $displayName }}</h5>
                        @if ($statusLabel !== '')
                            <span class="badge rounded-pill badge-subtle-{{ $statusClass }}">{{ $statusLabel }}</span>
                        @endif
                    </div>
                    <div class="d-flex flex-wrap gap-2 text-600 fs-10 mt-1">
                        @if ($usernameValue !== '')
                            <span dir="ltr">{{ '@'.$usernameValue }}</span>
                        @endif
                        @if ($emailValue !== '')
                            <span dir="ltr">{{ $emailValue }}</span>
                        @endif
                        @if ($phoneValue !== '')
                            <span dir="ltr">{{ $phoneValue }}</span>
                        @endif
                        @if ($lastLoginAt !== '')
                            <span>
                                {{ __('profile.fields.last_login_at') }}
                                <span class="date-value" dir="ltr">{{ $lastLoginAt }}</span>
                            </span>
                        @endif
                    </div>
                    @if ($roles->isNotEmpty())
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @foreach ($roles->take(3) as $role)
                                <span class="badge rounded-pill badge-subtle-primary">{{ $role->name }}</span>
                            @endforeach
                            @if ($roles->count() > 3)
                                <span class="badge rounded-pill badge-subtle-secondary">+{{ $roles->count() - 3 }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="ms-auto">
                    @if ($isView && $canEditProfile)
                        <a class="btn btn-falcon-primary btn-sm px-3" href="{{ route('profile.edit') }}" data-shortcut-action="form.edit" title="{{ __('common.shortcuts.edit') }}" data-bs-title="{{ __('common.shortcuts.edit') }}">
                            <span class="fas fa-edit me-1"></span>{{ __('profile.actions.edit') }}
                        </a>
                    @endif
                    @if ($isEdit)
                        <a class="btn btn-falcon-default btn-sm px-3" href="{{ route('profile.show') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}">
                            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header bg-body-tertiary px-3 py-2">
                    <h6 class="mb-0">{{ $isEdit ? __('profile.sections.edit_information') : __('profile.sections.profile_information') }}</h6>
                </div>

                @if ($isEdit)
                    <form class="js-profile-form" action="{{ route('profile.update') }}" method="POST" data-original='@json($profileFields)' novalidate>
                        @csrf
                        @method('PUT')
                        <x-forms.input type="hidden" name="submit_action" value="save" />

                        <div class="card-body bg-body-tertiary p-3 js-profile-form-body">
                            <div class="alert alert-danger alert-dismissible fade show d-none js-profile-alert" role="alert">
                                <span class="js-profile-alert-message"></span>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                            </div>

                            <div class="row gx-2 gy-3">
                                <div class="col-md-6">
                                    <x-forms.label for="profile-name" :label="__('common.fields.name')" required />
                                    <x-forms.input id="profile-name" name="name" type="text" class="form-control" value="{{ old('name', $user->name) }}" required />
                                    <div class="invalid-feedback" data-error-for="name"></div>
                                </div>
                                <div class="col-md-6">
                                    <x-forms.label for="profile-username" :label="__('common.fields.username')" required />
                                    <x-forms.input id="profile-username" name="username" type="text" class="form-control" value="{{ old('username', $user->username) }}" autocomplete="username" required />
                                    <div class="invalid-feedback" data-error-for="username"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="profile-email">{{ __('common.fields.email') }}</label>
                                    <x-forms.input id="profile-email" name="email" type="email" class="form-control" value="{{ old('email', $user->email) }}" autocomplete="email" />
                                    <div class="invalid-feedback" data-error-for="email"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="profile-phone">{{ __('common.fields.phone') }}</label>
                                    <x-forms.input id="profile-phone" name="phone" type="text" class="form-control" value="{{ old('phone', $user->phone) }}" autocomplete="tel" />
                                    <div class="invalid-feedback" data-error-for="phone"></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="profile-notes">{{ __('common.fields.notes') }}</label>
                                    <x-forms.textarea id="profile-notes" name="notes" class="form-control" rows="2">{{ old('notes', $user->notes) }}</x-forms.textarea>
                                    <div class="invalid-feedback" data-error-for="notes"></div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer bg-body-tertiary px-3 py-2">
                            <div class="d-flex flex-wrap justify-content-end gap-2">
                                <a class="btn btn-falcon-default btn-sm" href="{{ route('profile.show') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}">
                                    <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
                                </a>
                                <button type="submit" class="btn btn-primary btn-sm js-profile-submit-action" data-submit-action="save" data-shortcut-action="form.save" title="{{ __('common.shortcuts.save') }}" data-bs-title="{{ __('common.shortcuts.save') }}">
                                    <span class="fas fa-save me-1"></span>{{ __('profile.actions.save') }}
                                </button>
                                @can('profile.view')
                                    <button type="submit" class="btn btn-falcon-primary btn-sm js-profile-submit-action" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ __('common.shortcuts.save_view') }}" data-bs-title="{{ __('common.shortcuts.save_view') }}">
                                        <span class="fas fa-eye me-1"></span>{{ __('profile.actions.save_view') }}
                                    </button>
                                @endcan
                            </div>
                        </div>
                    </form>
                @else
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush fs-10">
                            <div class="list-group-item px-3 py-2">
                                <div class="row g-2 align-items-center">
                                    <div class="col-sm-4 text-600">{{ __('common.fields.name') }}</div>
                                    <div class="col-sm-8 fw-semi-bold text-1000">{{ $user->name }}</div>
                                </div>
                            </div>
                            <div class="list-group-item px-3 py-2">
                                <div class="row g-2 align-items-center">
                                    <div class="col-sm-4 text-600">{{ __('common.fields.username') }}</div>
                                    <div class="col-sm-8 fw-semi-bold text-1000" dir="ltr">{{ $user->username }}</div>
                                </div>
                            </div>
                            <div class="list-group-item px-3 py-2">
                                <div class="row g-2 align-items-center">
                                    <div class="col-sm-4 text-600">{{ __('common.fields.email') }}</div>
                                    <div class="col-sm-8 fw-semi-bold text-1000" dir="ltr">
                                        @if ($emailValue !== '')
                                            <x-contact.email-link :email="$user->email" />
                                        @else
                                            <span class="text-500 erp-view-empty-value">{{ __('common.empty_value') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item px-3 py-2">
                                <div class="row g-2 align-items-center">
                                    <div class="col-sm-4 text-600">{{ __('common.fields.phone') }}</div>
                                    <div class="col-sm-8 fw-semi-bold text-1000" dir="ltr">
                                        @if ($phoneValue !== '')
                                            <x-contact.phone-actions :phone="$phoneValue" />
                                        @else
                                            <span class="text-500 erp-view-empty-value">{{ __('common.empty_value') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            @if ($canEditProfile)
                <div class="card mb-3">
                    <div class="card-header bg-body-tertiary px-3 py-2">
                        <h6 class="mb-0">{{ __('profile.sections.default_login_context') }}</h6>
                    </div>
                    <form class="js-profile-default-context-form" action="{{ route('profile.default-context.update') }}" method="POST" data-clear-url="{{ route('profile.default-context.destroy') }}" data-original='@json($defaultContextFields)' novalidate>
                        @csrf
                        @method('PUT')

                        <div class="card-body bg-body-tertiary p-3 js-profile-default-context-form-body">
                            @if ($hasInvalidDefaultContext)
                                <div class="alert alert-warning py-2 js-profile-default-context-invalid-alert" role="alert">
                                    {{ __('profile.messages.default_context_invalid') }}
                                </div>
                            @endif
                            <div class="alert alert-danger alert-dismissible fade show d-none js-profile-alert" role="alert">
                                <span class="js-profile-alert-message"></span>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                            </div>

                            <div class="row gx-2 gy-3">
                                <div class="col-md-4">
                                    <x-forms.label for="profile-default-company" :label="__('profile.fields.default_company')" required />
                                    <x-forms.select class="form-select js-select2-ajax" id="profile-default-company" name="company_doc_num" data-url="{{ route('admin.select2.companies', ['access_scope' => 'operating_scope']) }}" data-placeholder="{{ __('operating_context.select_company') }}" data-allow-clear="true" required>
                                        @if ($defaultCompany)
                                            <option value="{{ $defaultCompany['id'] }}" selected>{{ $defaultCompany['text'] }}</option>
                                        @endif
                                    </x-forms.select>
                                    <div class="invalid-feedback d-block" data-error-for="company_doc_num"></div>
                                </div>
                                <div class="col-md-4">
                                    <x-forms.label for="profile-default-branch" :label="__('profile.fields.default_branch')" required />
                                    <x-forms.select class="form-select js-select2-ajax" id="profile-default-branch" name="branch_doc_num" data-url="{{ route('admin.select2.branches', ['access_scope' => 'operating_scope']) }}" data-placeholder="{{ __('operating_context.select_branch') }}" data-allow-clear="true" data-depends-on="#profile-default-company" data-dependent-param="company_doc_num" data-dependent-result-field="company_doc_num" data-disable-when-dependency-empty="true" required :disabled='! $defaultCompanyDocNum'>
                                        @if ($defaultBranch)
                                            <option value="{{ $defaultBranch['id'] }}" data-dependent-value="{{ $defaultBranch['company_doc_num'] ?? $defaultCompanyDocNum }}" selected>{{ $defaultBranch['text'] }}</option>
                                        @endif
                                    </x-forms.select>
                                    <div class="invalid-feedback d-block" data-error-for="branch_doc_num"></div>
                                </div>
                                <div class="col-md-4">
                                    <x-forms.label for="profile-default-financial-period" :label="__('profile.fields.default_financial_period')" required />
                                    <x-forms.select class="form-select js-select2-ajax" id="profile-default-financial-period" name="financial_period_doc_num" data-url="{{ route('admin.select2.financial-periods', ['access_scope' => 'operating_scope']) }}" data-placeholder="{{ __('operating_context.select_financial_period') }}" data-allow-clear="true" data-depends-on="#profile-default-company" data-dependent-param="company_doc_num" data-dependent-result-field="company_doc_num" data-disable-when-dependency-empty="true" required :disabled='! $defaultCompanyDocNum'>
                                        @if ($defaultFinancialPeriod)
                                            <option value="{{ $defaultFinancialPeriod['id'] }}" data-dependent-value="{{ $defaultFinancialPeriod['company_doc_num'] ?? $defaultCompanyDocNum }}" selected>{{ $defaultFinancialPeriod['text'] }}</option>
                                        @endif
                                    </x-forms.select>
                                    <div class="invalid-feedback d-block" data-error-for="financial_period_doc_num"></div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer bg-body-tertiary px-3 py-2">
                            <div class="d-flex flex-wrap justify-content-end gap-2">
                                <button type="button" class="btn btn-falcon-default text-danger btn-sm js-profile-default-context-clear" @disabled(! $hasSavedDefaultContext)>
                                    <span class="fas fa-times me-1"></span>{{ __('profile.actions.clear_default_context') }}
                                </button>
                                <button type="submit" class="btn btn-primary btn-sm js-profile-default-context-save">
                                    <span class="fas fa-save me-1"></span>{{ __('profile.actions.save_default_context') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            @endif

            @can('profile.password.update')
                <div class="card mb-3 mb-lg-0">
                    <div class="card-header bg-body-tertiary px-3 py-2">
                        <h6 class="mb-0">{{ __('profile.sections.password_security') }}</h6>
                    </div>
                    <div class="card-body bg-body-tertiary p-3">
                        <form class="js-profile-password-form" action="{{ route('password.update') }}" method="POST" novalidate>
                            @csrf
                            @method('PUT')

                            <div class="row gx-2 gy-3 align-items-end">
                                <div class="col-md-4">
                                    <x-forms.label for="profile-current-password" :label="__('profile.fields.current_password')" required />
                                    <x-forms.input id="profile-current-password" name="current_password" type="password" :class="'form-control'.($errors->getBag('updatePassword')->has('current_password') ? ' is-invalid' : '')" autocomplete="current-password" required />
                                    @error('current_password', 'updatePassword')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-4">
                                    <x-forms.label for="profile-new-password" :label="__('profile.fields.new_password')" required />
                                    <x-forms.input id="profile-new-password" name="password" type="password" :class="'form-control'.($errors->getBag('updatePassword')->has('password') ? ' is-invalid' : '')" autocomplete="new-password" required />
                                    @error('password', 'updatePassword')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-4">
                                    <x-forms.label for="profile-password-confirmation" :label="__('profile.fields.password_confirmation')" required />
                                    <x-forms.input id="profile-password-confirmation" name="password_confirmation" type="password" :class="'form-control'.($errors->getBag('updatePassword')->has('password_confirmation') ? ' is-invalid' : '')" autocomplete="new-password" required />
                                    @error('password_confirmation', 'updatePassword')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <span class="fas fa-key me-1"></span>{{ __('profile.actions.update_password') }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header bg-body-tertiary px-3 py-2">
                    <h6 class="mb-0">{{ __('profile.sections.account_summary') }}</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush fs-10">
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="text-600">{{ __('common.fields.status') }}</span>
                                @if ($statusLabel !== '')
                                    <span class="badge rounded-pill badge-subtle-{{ $statusClass }}">{{ $statusLabel }}</span>
                                @else
                                    <span class="text-500 erp-view-empty-value">{{ __('common.empty_value') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <span class="text-600">{{ __('common.fields.created_at') }}</span>
                                <span class="fw-semi-bold text-1000 date-value" dir="ltr">{{ $createdAt }}</span>
                            </div>
                        </div>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <span class="text-600">{{ __('common.fields.updated_at') }}</span>
                                <span class="fw-semi-bold text-1000 date-value" dir="ltr">{{ $updatedAt !== '' ? $updatedAt : __('common.empty_value') }}</span>
                            </div>
                        </div>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <span class="text-600">{{ __('profile.fields.last_login_at') }}</span>
                                <span class="fw-semi-bold text-1000 date-value" dir="ltr">{{ $lastLoginAt !== '' ? $lastLoginAt : __('common.empty_value') }}</span>
                            </div>
                        </div>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <span class="text-600">{{ __('profile.fields.last_login_ip') }}</span>
                                <span class="fw-semi-bold text-1000" dir="ltr">{{ $lastLoginIp !== '' ? $lastLoginIp : __('common.empty_value') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @can('profile.sessions.view')
                <div class="card mb-3">
                    <div class="card-header bg-body-tertiary px-3 py-2">
                        <h6 class="mb-0">{{ __('profile.sections.active_sessions') }}</h6>
                    </div>
                    <div class="card-body p-0">
                        @if ($activeSessions->isNotEmpty())
                            <div class="list-group list-group-flush fs-10 overflow-auto" style="max-height: 15rem;">
                                @foreach ($activeSessions->take(3) as $session)
                                    <div class="list-group-item px-3 py-2">
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <h6 class="fs-10 mb-1 text-truncate">{{ $session['device'] }}</h6>
                                            @if ($session['presence'] !== '')
                                                <span class="badge rounded-pill badge-subtle-{{ $session['presence_class'] }}">{{ $session['presence'] }}</span>
                                            @endif
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 text-700">
                                            @if ($session['ip_address'] !== '')
                                                <span dir="ltr">{{ $session['ip_address'] }}</span>
                                            @endif
                                            @if ($session['last_seen_at'] !== '')
                                                <span class="date-value" dir="ltr">{{ $session['last_seen_at'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="p-2"></div>
                        @endif
                    </div>
                </div>
            @endcan

            @can('profile.auth_logs.view')
                <div class="card mb-0">
                    <div class="card-header bg-body-tertiary px-3 py-2">
                        <h6 class="mb-0">{{ __('profile.sections.login_activity') }}</h6>
                    </div>
                    <div class="card-body p-0">
                        @if ($authLogs->isNotEmpty())
                            <div class="list-group list-group-flush fs-10 overflow-auto" style="max-height: 18rem;">
                                @foreach ($authLogs->take(5) as $authLog)
                                    <div class="list-group-item px-3 py-2">
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <h6 class="fs-10 mb-1">{{ $authLog['activity'] }}</h6>
                                            @if ($authLog['result'] !== '')
                                                <span class="badge rounded-pill badge-subtle-{{ $authLog['result_class'] }}">{{ $authLog['result'] }}</span>
                                            @endif
                                        </div>
                                        @if ($authLog['date_time'] !== '')
                                            <div class="fs-11 text-600 date-value" dir="ltr">{{ $authLog['date_time'] }}</div>
                                        @endif
                                        <div class="d-flex flex-wrap gap-2 text-700">
                                            @if ($authLog['ip_address'] !== '')
                                                <span dir="ltr">{{ $authLog['ip_address'] }}</span>
                                            @endif
                                            @if ($authLog['device'] !== '')
                                                <span>{{ $authLog['device'] }}</span>
                                            @endif
                                        </div>
                                        @if ($authLog['location_url'] !== '')
                                            <a class="fs-11 d-inline-block mt-1" href="{{ $authLog['location_url'] }}" target="_blank" rel="noopener" aria-label="{{ $authLog['location_map_label'] }}">
                                                {{ __('auth_logs.actions.view_on_map') }}
                                            </a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="p-2"></div>
                        @endif
                    </div>
                </div>
            @endcan
        </div>
    </div>
@endsection

@if ($isEdit || $canEditProfile)
    @push('scripts')
        @php
            $profileMessages = [
                'noChanges' => __('common.messages.no_changes'),
                'validationSummary' => __('common.messages.validation_failed'),
                'unexpectedError' => __('auth.ajax.unexpected_error'),
                'close' => __('common.actions.close'),
            ];
        @endphp
        <script>
            window.profileMessages = @json($profileMessages);
        </script>
        <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
        <script src="{{ asset('assets/js/modules/Auth/profile.js') }}"></script>
    @endpush
@endif
