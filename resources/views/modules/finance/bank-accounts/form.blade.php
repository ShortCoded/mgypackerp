@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = __("bank_accounts.{$mode}");
    $value = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $linkedAccount = $record?->account;
    $bankGroup = $record?->bank ?? ($linkedAccount?->is_group ? $linkedAccount : $linkedAccount?->parent);
    $bankOption = $bankGroup ? ['id' => $bankGroup->doc_num, 'text' => $bankGroup->account_code.' — '.$bankGroup->name] : null;
    $currencyOption = $record?->currency ? ['id' => $record->currency->doc_num, 'text' => $record->currency->code.' — '.$record->currency->name] : null;
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $linkedAccountDisplayName = $linkedAccount?->displayName() ?: $value('account_name');
    $originalRecordData = [
        'doc_number' => $canControlDocumentNumber && ! $isCreateLike ? $record?->doc_number : '',
        'bank_doc_num' => $bankOption['id'] ?? '',
        'currency_doc_num' => $currencyOption['id'] ?? '',
        'account_name' => $record?->account_name,
        'account_number' => $record?->account_number,
        'iban' => $record?->iban,
        'swift_code' => $record?->swift_code,
        'owner_name' => $record?->owner_name,
        'bank_branch_name' => $record?->bank_branch_name,
        'status' => $record?->status ?? 'active',
        'notes' => $record?->notes,
    ];
@endphp

@section('title', $title)

@push('styles')
    <style>
        .bank-account-form-card .bank-account-lookup-control {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
        }

        .bank-account-form-card .bank-account-lookup-control .select2-container {
            flex: 1 1 auto;
            min-width: 0;
        }

        .bank-account-form-card .bank-account-lookup-control .btn {
            flex: 0 0 auto;
            white-space: nowrap;
        }
    </style>
@endpush

@section('content')
    <form class="js-finance-form js-crud-form js-bank-account-form" action="{{ $action }}" method="{{ $method }}" data-resource="bank_accounts" data-mode="{{ $mode }}" data-original='@json($originalRecordData)' novalidate>
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if($cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3 bank-account-form-card">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ $title }}</h5></div>
                    <div class="col-auto">@include('modules.finance.partials.form-actions', ['resource'=>'bank_accounts','routePrefix'=>'admin.finance.bank-accounts'])</div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                <div class="row g-3">
                    @if($canControlDocumentNumber)
                        <div class="col-md-2">
                            <label class="form-label" for="doc_number">{{ __('bank_accounts.attributes.doc_number') }}</label>
                            @if($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-2">
                            <x-forms.view-field for="doc_num" :label="__('bank_accounts.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                        </div>
                    @endif

                    <div class="{{ $canControlDocumentNumber || ! $isCreateLike ? 'col-md-7' : 'col-md-9' }}">
                        <x-forms.label for="bank_doc_num" :label="__('bank_accounts.attributes.bank')" required />
                        @if($isView)
                            <x-forms.view-field for="bank_doc_num" :value="$bankOption['text'] ?? null" />
                        @else
                            <div class="bank-account-lookup-control">
                                <select class="form-select js-select2-ajax js-bank-select" id="bank_doc_num" name="bank_doc_num" data-url="{{ route('admin.accounting.accounts.select2', ['classification' => 'bank', 'bank_accounts' => 1, 'group' => 1]) }}" data-placeholder="{{ __('common.placeholders.select') }}">
                                    @if($bankOption)
                                        <option value="{{ $bankOption['id'] }}" selected>{{ $bankOption['text'] }}</option>
                                    @endif
                                </select>
                                @if($canCreateAccounts)
                                    <button class="btn btn-falcon-default btn-sm js-bank-inline-create" type="button" data-modal="#bank-inline-modal">
                                        <span class="fas fa-plus"></span><span class="ms-1">{{ __('bank_accounts.actions.add_bank') }}</span>
                                    </button>
                                @endif
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="bank_doc_num"></div>
                    </div>

                    <div class="col-md-3">
                        <x-forms.label for="status" :label="__('bank_accounts.attributes.status')" required />
                        <select class="form-select" id="status" name="status" @disabled($isView) required>
                            <option value="active" @selected($value('status','active') === 'active')>{{ __('finance.statuses.active') }}</option>
                            <option value="inactive" @selected($value('status') === 'inactive')>{{ __('finance.statuses.inactive') }}</option>
                        </select>
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="currency_doc_num" :label="__('bank_accounts.attributes.currency')" required />
                        @if($isView)
                            <x-forms.view-field for="currency_doc_num" :value="$currencyOption['text'] ?? null" />
                        @else
                            <select class="form-select js-select2-ajax" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('common.placeholders.select') }}">
                                @if($currencyOption)
                                    <option value="{{ $currencyOption['id'] }}" selected>{{ $currencyOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                    </div>
                    <div class="col-md-4">
                        <x-forms.label for="account_name" :label="__('bank_accounts.attributes.account_name')" required />
                        <input class="form-control" id="account_name" name="account_name" value="{{ $isView ? $linkedAccountDisplayName : $value('account_name') }}" @readonly($isView) @unless($isView) autofocus @endunless required>
                        <div class="invalid-feedback" data-error-for="account_name"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="account_number">{{ __('bank_accounts.attributes.account_number') }}</label>
                        <input class="form-control" id="account_number" name="account_number" value="{{ $value('account_number') }}" @readonly($isView)>
                        <div class="invalid-feedback" data-error-for="account_number"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="iban">{{ __('bank_accounts.attributes.iban') }}</label>
                        <input class="form-control" id="iban" name="iban" value="{{ $value('iban') }}" @readonly($isView)>
                        <div class="invalid-feedback" data-error-for="iban"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="swift_code">{{ __('bank_accounts.attributes.swift_code') }}</label>
                        <input class="form-control" id="swift_code" name="swift_code" value="{{ $value('swift_code') }}" @readonly($isView)>
                        <div class="invalid-feedback" data-error-for="swift_code"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="owner_name">{{ __('bank_accounts.attributes.owner_name') }}</label>
                        <input class="form-control" id="owner_name" name="owner_name" value="{{ $value('owner_name') }}" @readonly($isView)>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="bank_branch_name">{{ __('bank_accounts.attributes.bank_branch_name') }}</label>
                        <input class="form-control" id="bank_branch_name" name="bank_branch_name" value="{{ $value('bank_branch_name') }}" @readonly($isView)>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('bank_accounts.attributes.notes') }}</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" @readonly($isView)>{{ $value('notes') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['resource'=>'bank_accounts','routePrefix'=>'admin.finance.bank-accounts'])</div>
        </div>
    </form>

    @unless($isView)
        <div class="modal fade" id="bank-inline-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content js-bank-inline-form" action="{{ route('admin.finance.bank-accounts.bank-groups.store') }}" method="POST" data-target-select="#bank_doc_num" novalidate>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('bank_accounts.actions.add_bank') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                        <div class="mb-3">
                            <x-forms.label for="bank_inline_name" :label="__('bank_accounts.attributes.bank_name')" required />
                            <input class="form-control" id="bank_inline_name" name="name" type="text" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div>
                            <label class="form-label" for="bank_inline_notes">{{ __('bank_accounts.attributes.notes') }}</label>
                            <textarea class="form-control" id="bank_inline_notes" name="notes" rows="3"></textarea>
                            <div class="invalid-feedback" data-error-for="notes"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-falcon-default" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button type="submit" class="btn btn-primary"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endunless
@endsection

@push('scripts')
    <script>
        window.financeCrudMessages = @json(array_merge(__('finance.js'), __('bank_accounts.js')));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/finance-foundation.js') }}"></script>
@endpush
