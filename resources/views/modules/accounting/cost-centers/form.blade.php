@extends('layouts.app')

@php
    $record = $costCenter ?? null;
    $isView = $mode === 'view';
    $isClone = $mode === 'clone';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = match ($mode) {
        'edit' => __('cost_centers.edit'),
        'view' => __('cost_centers.view'),
        'clone' => __('cost_centers.clone'),
        default => __('cost_centers.create'),
    };
    $value = fn (string $field, mixed $default = '') => old($field, $record?->{$field} ?? $default);
    $parentDisplay = $record?->parent ? $record->parent->cost_center_code . ' / ' . $record->parent->name : null;
    $parentOption = $record?->parent && $record->parent->status === 'active' && $record->parent->is_group ? ['id' => $record->parent->doc_num, 'text' => $parentDisplay] : null;
    $showsDocumentNumberColumn = $canControlDocumentNumber || ! $isCreateLike;
    $originalCostCenterData = [
        'doc_number' => ! $isCreateLike ? (string) ($record?->doc_number ?? '') : '',
        'cost_center_code' => $mode === 'clone' ? '' : (string) ($record?->cost_center_code ?? ''),
        'name' => (string) ($record?->name ?? ''),
        'name_en' => (string) ($record?->name_en ?? ''),
        'parent_doc_num' => (string) ($record?->parent?->doc_num ?? ''),
        'linked_account_doc_nums' => collect($linkedAccountOptions)->pluck('id')->sort()->values()->all(),
        'is_group' => (bool) ($record?->is_group ?? false),
        'status' => (string) ($record?->status ?? 'active'),
        'notes' => (string) ($record?->notes ?? ''),
    ];
    $costCenterMessages = [
        'deleteConfirmTitle' => __('cost_centers.messages.delete_confirm_title'),
        'deleteConfirmText' => __('cost_centers.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('cost_centers.messages.delete_confirm_yes'),
        'restoreConfirmTitle' => __('cost_centers.messages.restore_confirm_title'),
        'restoreConfirmText' => __('cost_centers.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('cost_centers.messages.restore_confirm_yes'),
        'cancel' => __('common.actions.cancel'),
        'validationFailed' => __('common.messages.validation_failed'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'saved' => __('common.messages.saved_successfully'),
        'noChanges' => __('common.messages.no_changes'),
        'groupLabel' => __('cost_centers.attributes.is_group'),
    ];
@endphp

@section('title', $title)

@section('content')
    <form id="cost-center-form" class="js-cost-center-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalCostCenterData)' data-next-code-url="{{ route('admin.accounting.cost-centers.next-code') }}" novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3">
            @include('modules.accounting.cost-centers.partials.form-header')

            <div class="card-body js-cost-center-form-body">
                <div class="alert d-none js-cost-center-alert" role="alert">
                    <div class="js-cost-center-alert-message"></div>
                </div>
                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="doc_number">{{ __('cost_centers.attributes.doc_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="old('doc_number', ! $isCreateLike ? $record?->doc_number : '')" input-class="text-center" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" value="{{ old('doc_number', ! $isCreateLike ? $record?->doc_number : '') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field for="doc_num" :label="__('cost_centers.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-9 col-lg-4' : 'col-md-5' }}">
                        <x-forms.label for="name" :label="__('cost_centers.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="name" :value="$value('name')" />
                        @else
                            <input class="form-control" id="name" name="name" value="{{ $value('name') }}" required autofocus>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-9 col-lg-4' : 'col-md-5' }}">
                        <label class="form-label" for="name_en">{{ __('cost_centers.attributes.name_en') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="name_en" :value="$value('name_en')" />
                        @else
                            <input class="form-control" id="name_en" name="name_en" value="{{ $value('name_en') }}" dir="ltr">
                        @endif
                        <div class="invalid-feedback" data-error-for="name_en"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-4 col-lg-3' : 'col-md-3' }}">
                        <label class="form-label" for="cost_center_code">{{ __('cost_centers.attributes.cost_center_code') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="cost_center_code" :value="$isCreateLike && $mode === 'clone' ? '' : $value('cost_center_code')" input-class="text-center" />
                        @else
                            <input class="form-control" id="cost_center_code" name="cost_center_code" value="{{ $isCreateLike && $mode === 'clone' ? '' : $value('cost_center_code') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="cost_center_code"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-8 col-lg-3' : 'col-md-4' }}">
                        <label class="form-label" for="parent_doc_num">{{ __('cost_centers.attributes.parent') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="parent_doc_num" :value="$parentDisplay ?? __('cost_centers.attributes.no_parent')" />
                        @else
                            <select class="form-select js-select2-ajax" id="parent_doc_num" name="parent_doc_num" data-url="{{ route('admin.accounting.select2.cost-centers') }}?exclude={{ $record?->doc_num }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                @if ($parentOption)
                                    <option value="{{ $parentOption['id'] }}" selected>{{ $parentOption['text'] }}</option>
                                @endif
                            </select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="parent_doc_num"></div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <x-forms.label for="status" :label="__('cost_centers.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="status" :value="__('cost_centers.statuses.' . ($record?->status ?? 'active'))" />
                        @else
                            <select class="form-select" id="status" name="status" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected($value('status', 'active') === $status)>{{ __('cost_centers.statuses.' . $status) }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="linked_account_doc_nums">{{ __('cost_centers.attributes.linked_accounts') }}</label>
                        @if ($isView)
                            <div class="border rounded-2 bg-body-tertiary px-3 py-2">
                                @forelse ($linkedAccountOptions as $option)
                                    <div class="d-flex flex-wrap align-items-center gap-2 py-1">
                                        <span class="badge rounded-pill badge-subtle-{{ $option['is_stale'] ? 'warning' : 'primary' }}">{{ $option['display'] }}</span>
                                        @if ($option['parent'])
                                            <span class="text-600 fs-10">{{ __('cost_centers.attributes.parent_account') }}: {{ $option['parent'] }}</span>
                                        @endif
                                        @if ($option['is_stale'])
                                            <span class="badge rounded-pill badge-subtle-warning">{{ __('cost_centers.messages.unavailable') }}</span>
                                        @endif
                                    </div>
                                @empty
                                    <span class="text-600">—</span>
                                @endforelse
                            </div>
                        @else
                            <select class="form-select js-select2-ajax" id="linked_account_doc_nums" name="linked_account_doc_nums[]" multiple data-url="{{ route('admin.accounting.select2.accounts', ['hierarchy' => 1]) }}" data-placeholder="{{ __('cost_centers.placeholders.linked_accounts') }}" data-allow-clear="true">
                                @foreach ($linkedAccountOptions as $option)
                                    <option value="{{ $option['id'] }}" selected>{{ $option['text'] }}@if ($option['is_stale']) — {{ __('cost_centers.messages.unavailable') }}@endif</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('cost_centers.messages.linked_accounts_help') }}</div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="linked_account_doc_nums"></div>
                    </div>

                    <div class="col-12">
                        @if ($isView)
                            <div class="d-inline-flex align-items-center gap-2">
                                <span class="text-700 fs-10">{{ __('cost_centers.attributes.is_group') }}</span>
                                <span class="badge rounded-pill badge-subtle-{{ $record?->is_group ? 'success' : 'secondary' }}">
                                    {{ $record?->is_group ? __('common.actions.yes') : __('common.actions.no') }}
                                </span>
                            </div>
                        @else
                            <input type="hidden" name="is_group" value="0">
                            <div class="mb-0 form-check form-switch">
                                <input class="form-check-input" id="is_group" name="is_group" type="checkbox" value="1" @checked(old('is_group', $record?->is_group ?? false))>
                                <label class="form-check-label text-700 fs-10" for="is_group">{{ __('cost_centers.attributes.is_group') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="is_group"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('cost_centers.attributes.notes') }}</label>
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
            @include('modules.accounting.cost-centers.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        window.costCenterMessages = @json($costCenterMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Accounting/cost-centers.js') }}"></script>
@endpush
