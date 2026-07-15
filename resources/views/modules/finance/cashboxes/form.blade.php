@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = __("cashboxes.{$mode}");
    $value = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $linkedAccount = $record?->account;
    $parentAccount = app(\Modules\Finance\Services\CashboxChartAccountService::class)->linkedAccountParent($linkedAccount);
    $accountOption = $parentAccount ? ['id' => $parentAccount->doc_num, 'text' => $parentAccount->codeNameLabel()] : null;
    $branch = $record?->branch ?? $defaultBranch ?? null;
    $branchOption = $branch ? ['id' => $branch->doc_num, 'text' => trim(implode(' / ', array_filter([$branch->name, $branch->doc_num, $branch->company?->name])))] : null;
    $currencyOptions = $record?->currencies?->map(fn ($row) => ['id' => $row->currency?->doc_num, 'text' => $row->currency?->code.' — '.$row->currency?->name])->filter(fn ($row) => $row['id'])->values()->all() ?? [];
    $currenciesDisplay = collect($currencyOptions)->pluck('text')->filter()->implode(', ') ?: __('cashboxes.all_currencies');
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
@endphp

@section('title', $title)

@push('styles')
    <style>
        .cashbox-form-card .cashbox-account-group-control {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
        }

        .cashbox-form-card .cashbox-account-group-control .select2-container {
            flex: 1 1 auto;
            min-width: 0;
        }

        .cashbox-form-card .cashbox-account-group-control .btn {
            flex: 0 0 auto;
            white-space: nowrap;
        }
    </style>
@endpush

@section('content')
    <form class="js-finance-form js-crud-form js-cashbox-form" action="{{ $action }}" method="{{ $method }}" data-resource="cashboxes" data-primary-focus="name" data-mode="{{ $mode }}" novalidate>
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if($cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3 cashbox-form-card">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ $title }}</h5></div>
                    <div class="col-auto">@include('modules.finance.partials.form-actions', ['resource' => 'cashboxes', 'routePrefix' => 'admin.finance.cashboxes'])</div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                <div class="row g-3">
                    @if($canControlDocumentNumber)
                        <div class="col-md-2">
                            <label class="form-label" for="doc_number">{{ __('cashboxes.attributes.doc_number') }}</label>
                            @if($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-2">
                            <x-forms.view-field for="doc_num" :label="__('cashboxes.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                        </div>
                    @endif

                    <div class="{{ $canControlDocumentNumber || ! $isCreateLike ? 'col-md-7' : 'col-md-9' }}">
                        <x-forms.label for="name" :label="__('cashboxes.attributes.name')" required />
                        <input class="form-control" id="name" name="name" value="{{ $value('name') }}" @readonly($isView) @unless($isView) autofocus @endunless required>
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-md-3">
                        <x-forms.label for="status" :label="__('cashboxes.attributes.status')" required />
                        <select class="form-select" id="status" name="status" @disabled($isView) required>
                            <option value="active" @selected($value('status', 'active') === 'active')>{{ __('finance.statuses.active') }}</option>
                            <option value="inactive" @selected($value('status') === 'inactive')>{{ __('finance.statuses.inactive') }}</option>
                        </select>
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="parent_account_doc_num" :label="__('cashboxes.attributes.account_group')" />
                        @if($isView)
                            <x-forms.view-field for="parent_account_doc_num" :value="$accountOption['text'] ?? null" />
                        @else
                            <div class="cashbox-account-group-control">
                                <select class="form-select js-select2-ajax js-cashbox-group-select" id="parent_account_doc_num" name="parent_account_doc_num" data-url="{{ route('admin.finance.select2.cashbox-parent-accounts') }}" data-placeholder="{{ __('cashboxes.placeholders.cashbox_group') }}" data-allow-clear="true">
                                    @if($accountOption)
                                        <option value="{{ $accountOption['id'] }}" selected>{{ $accountOption['text'] }}</option>
                                    @endif
                                </select>
                                @if($canCreateAccounts)
                                    <button class="btn btn-falcon-default btn-sm js-cashbox-inline-create" type="button" data-modal="#cashbox-inline-modal">
                                        <span class="fas fa-plus"></span><span class="ms-1">{{ __('cashboxes.actions.add_group') }}</span>
                                    </button>
                                @endif
                            </div>
                            <div class="form-text">{{ __('cashboxes.account_parent_help') }}</div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="parent_account_doc_num"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="branch_doc_num">{{ __('cashboxes.attributes.branch') }}</label>
                        @if($isView)
                            <x-forms.view-field for="branch_doc_num" :value="$branchOption['text'] ?? null" />
                        @else
                            <select class="form-select js-select2-ajax" id="branch_doc_num" name="branch_doc_num" data-url="{{ route('admin.finance.select2.branches') }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                @if($branchOption)
                                    <option value="{{ $branchOption['id'] }}" selected>{{ $branchOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_doc_num"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="currency_doc_nums">{{ __('cashboxes.attributes.currencies') }}</label>
                        @if($isView)
                            <x-forms.view-field for="currency_doc_nums" :value="$currenciesDisplay" />
                        @else
                            <select class="form-select js-select2-ajax" id="currency_doc_nums" name="currency_doc_nums[]" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('common.placeholders.select') }}" multiple>
                                @foreach($currencyOptions as $option)
                                    <option value="{{ $option['id'] }}" selected>{{ $option['text'] }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('cashboxes.currency_help') }}</div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="currency_doc_nums"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('cashboxes.attributes.notes') }}</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" @readonly($isView)>{{ $value('notes') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['resource' => 'cashboxes', 'routePrefix' => 'admin.finance.cashboxes'])</div>
        </div>
    </form>

    @unless($isView)
        <div class="modal fade" id="cashbox-inline-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content js-cashbox-inline-form" action="{{ route('admin.finance.cashboxes.account-groups.store') }}" method="POST" data-target-select="#parent_account_doc_num" novalidate>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('cashboxes.actions.add_group') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                        <div class="mb-3">
                            <x-forms.label for="cashbox_inline_name" :label="__('cashboxes.attributes.account_group_name')" required />
                            <input class="form-control" id="cashbox_inline_name" name="name" type="text" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div>
                            <label class="form-label" for="cashbox_inline_notes">{{ __('cashboxes.attributes.notes') }}</label>
                            <textarea class="form-control" id="cashbox_inline_notes" name="notes" rows="3"></textarea>
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
        window.financeCrudMessages = @json(__('finance.js'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/finance-foundation.js') }}"></script>
@endpush
