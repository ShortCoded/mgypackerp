@extends('layouts.app')

@php
    $record = $account ?? null;
    $isView = $mode === 'view';
    $isClone = $mode === 'clone';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = match ($mode) {
        'edit' => __('accounts.edit'),
        'view' => __('accounts.view'),
        'clone' => __('accounts.clone'),
        default => __('accounts.create'),
    };
    $value = fn (string $field, mixed $default = '') => old($field, $record?->{$field} ?? $default);
    $parentOption = $record?->parent ? ['id' => $record->parent->doc_num, 'text' => $record->parent->account_code.' / '.$record->parent->name] : null;
    $classificationOption = $record?->classification ? ['id' => $record->classification->code, 'text' => $record->classification->displayName()] : null;
    $accountTypeValue = old('account_type', $record?->account_type ?? 'asset');
    $statementTypeValue = old('statement_type', $record?->statement_type ?? (($accountTypeValue === 'revenue' || $accountTypeValue === 'expense') ? 'income_statement' : 'financial_position'));
    $showsDocumentNumberColumn = $canControlDocumentNumber || ! $isCreateLike;
    $originalAccountData = [
        'doc_number' => ! $isCreateLike ? (string) ($record?->doc_number ?? '') : '',
        'account_code' => $mode === 'clone' ? '' : (string) ($record?->account_code ?? ''),
        'name' => (string) ($record?->name ?? ''),
        'parent_doc_num' => (string) ($record?->parent?->doc_num ?? ''),
        'classification_code' => (string) ($record?->classification?->code ?? ''),
        'account_type' => (string) ($record?->account_type ?? ''),
        'statement_type' => (string) ($record?->statement_type ?? ''),
        'normal_balance' => (string) ($record?->normal_balance ?? ''),
        'status' => (string) ($record?->status ?? 'active'),
        'is_group' => (bool) ($record?->is_group ?? false),
        'is_postable' => (bool) ($record?->is_postable ?? true),
        'notes' => (string) ($record?->notes ?? ''),
    ];
    $accountMessages = [
        'deleteConfirmTitle' => __('accounts.messages.delete_confirm_title'),
        'deleteConfirmText' => __('accounts.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('accounts.messages.delete_confirm_yes'),
        'restoreConfirmTitle' => __('accounts.messages.restore_confirm_title'),
        'restoreConfirmText' => __('accounts.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('accounts.messages.restore_confirm_yes'),
        'cancel' => __('common.actions.cancel'),
        'validationFailed' => __('common.messages.validation_failed'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'saved' => __('common.messages.saved_successfully'),
        'noChanges' => __('common.messages.no_changes'),
    ];
    $accountDerivedDefaults = [
        'statementByType' => [
            'asset' => 'financial_position',
            'liability' => 'financial_position',
            'equity' => 'financial_position',
            'revenue' => 'income_statement',
            'expense' => 'income_statement',
        ],
        'normalBalanceByType' => [
            'asset' => 'debit',
            'expense' => 'debit',
            'liability' => 'credit',
            'equity' => 'credit',
            'revenue' => 'credit',
        ],
    ];
@endphp

@section('title', $title)

@section('content')
    <form id="account-form" class="js-account-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalAccountData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3">
            @include('modules.accounting.accounts.partials.form-header')

            <div class="card-body js-account-form-body">
                <div class="alert d-none js-account-alert" role="alert">
                    <div class="js-account-alert-message"></div>
                </div>
                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="doc_number">{{ __('accounts.attributes.doc_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="old('doc_number', ! $isCreateLike ? $record?->doc_number : '')" input-class="text-center" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" value="{{ old('doc_number', ! $isCreateLike ? $record?->doc_number : '') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field for="doc_num" :label="__('accounts.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-9 col-lg-4' : 'col-md-5' }}">
                        <x-forms.label for="name" :label="__('accounts.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="name" :value="$value('name')" />
                        @else
                            <input class="form-control" id="name" name="name" value="{{ $value('name') }}" required autofocus>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-4 col-lg-2' : 'col-md-3' }}">
                        <label class="form-label" for="account_code">{{ __('accounts.attributes.account_code') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="account_code" :value="$isCreateLike && $mode === 'clone' ? '' : $value('account_code')" />
                        @else
                            <input class="form-control" id="account_code" name="account_code" value="{{ $isCreateLike && $mode === 'clone' ? '' : $value('account_code') }}" @readonly(! $canControlAccountCode && ! $isCreateLike)>
                        @endif
                        <div class="invalid-feedback" data-error-for="account_code"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-8 col-lg-4' : 'col-md-4' }}">
                        <label class="form-label" for="parent_doc_num">{{ __('accounts.attributes.parent') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="parent_doc_num" :value="$parentOption['text'] ?? null" />
                        @else
                            <select class="form-select js-select2-ajax" id="parent_doc_num" name="parent_doc_num" data-url="{{ route('admin.accounting.select2.accounts') }}?exclude={{ $record?->doc_num }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                @if ($parentOption)
                                    <option value="{{ $parentOption['id'] }}" selected>{{ $parentOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="parent_doc_num"></div>
                    </div>

                    <input type="hidden" id="account_type" name="account_type" value="{{ $accountTypeValue }}">
                    <input type="hidden" id="statement_type" name="statement_type" value="{{ $statementTypeValue }}">

                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="classification_code">{{ __('accounts.attributes.classification') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="classification_code" :value="$classificationOption['text'] ?? null" />
                        @else
                            <select class="form-select js-select2-ajax" id="classification_code" name="classification_code" data-url="{{ route('admin.accounting.select2.account-classifications') }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                @if ($classificationOption)
                                    <option value="{{ $classificationOption['id'] }}" selected>{{ $classificationOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="classification_code"></div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <x-forms.label for="statement_type_display" :label="__('accounts.attributes.statement_type')" required />
                        @if ($isView)
                            <x-forms.view-field for="statement_type_display" :value="__('accounts.statement_types.' . ($record?->statement_type ?? $statementTypeValue))" />
                        @else
                            <select class="form-select bg-100" id="statement_type_display" disabled required>
                                @foreach (\Modules\Accounting\Models\Account::statementTypes() as $type)
                                    <option value="{{ $type }}" @selected($statementTypeValue === $type)>{{ __('accounts.statement_types.' . $type) }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('accounts.messages.statement_type_auto') }}</div>
                        @endif
                        <div class="invalid-feedback" data-error-for="account_type"></div>
                        <div class="invalid-feedback" data-error-for="statement_type"></div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <x-forms.label for="normal_balance" :label="__('accounts.attributes.normal_balance')" required />
                        @if ($isView)
                            <x-forms.view-field for="normal_balance" :value="__('accounts.normal_balances.' . ($record?->normal_balance ?? $value('normal_balance', 'debit')))" />
                        @else
                            <select class="form-select" id="normal_balance" name="normal_balance" required>
                                @foreach (\Modules\Accounting\Models\Account::normalBalances() as $balance)
                                    <option value="{{ $balance }}" @selected($value('normal_balance') === $balance)>{{ __('accounts.normal_balances.' . $balance) }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('accounts.messages.normal_balance_editable') }}</div>
                        @endif
                        <div class="invalid-feedback" data-error-for="normal_balance"></div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <x-forms.label for="status" :label="__('accounts.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="status" :value="__('accounts.statuses.' . ($record?->status ?? 'active'))" />
                        @else
                            <select class="form-select" id="status" name="status" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected($value('status', 'active') === $status)>{{ __('accounts.statuses.' . $status) }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-center gap-4 pt-1">
                            @foreach (['is_group', 'is_postable'] as $boolean)
                                @if ($isView)
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <span class="text-700 fs-10">{{ __('accounts.attributes.' . $boolean) }}</span>
                                        <span class="badge rounded-pill badge-subtle-{{ $record?->{$boolean} ? 'success' : 'secondary' }}">
                                            {{ $record?->{$boolean} ? __('common.actions.yes') : __('common.actions.no') }}
                                        </span>
                                    </div>
                                @else
                                    <input type="hidden" name="{{ $boolean }}" value="0">
                                    <div class="mb-0 form-check form-switch">
                                        <input class="form-check-input" id="{{ $boolean }}" name="{{ $boolean }}" type="checkbox" value="1" @checked(old($boolean, $record?->{$boolean} ?? ($boolean === 'is_postable')))>
                                        <label class="form-check-label text-700 fs-10" for="{{ $boolean }}">{{ __('accounts.attributes.' . $boolean) }}</label>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        @foreach (['is_group', 'is_postable'] as $boolean)
                            <div class="invalid-feedback d-block" data-error-for="{{ $boolean }}"></div>
                        @endforeach
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('accounts.attributes.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="notes" as="textarea" :value="$value('notes')" rows="4" />
                        @else
                            <textarea class="form-control" id="notes" name="notes" rows="4">{{ $value('notes') }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>
                </div>

                @if ($mode === 'edit' || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($record?->trashed() ?? false)"
                        :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>
            @include('modules.accounting.accounts.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        window.accountMessages = @json($accountMessages);
        window.accountDerivedDefaults = @json($accountDerivedDefaults);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Accounting/accounts.js') }}"></script>
@endpush
