@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = match ($mode) {
        'edit' => __('currencies.edit'),
        'view' => __('currencies.view'),
        'clone' => __('currencies.clone'),
        default => __('currencies.create'),
    };
    $recordName = $record?->name ?? '';
    $documentNumberValue = old('doc_number', ($isEdit || $isView) ? $record?->doc_number : '');
    $codeValue = old('code', $isClone ? '' : ($record?->code ?? ''));
    $originalRecordData = [
        'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $record?->doc_number : '') : null,
        'name' => $recordName,
        'code' => $isClone ? '' : ($record?->code ?? ''),
        'minor_unit_name' => $record?->minor_unit_name,
        'minor_unit_factor' => $record?->minor_unit_factor ?? 100,
        'is_main' => $isClone ? false : (bool) ($record?->is_main ?? false),
        'status' => $record?->status ?? 'active',
        'notes' => $record?->notes,
    ];
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
@endphp

@section('title', $title)

@section('content')
    <form id="currency-form" class="js-currency-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalRecordData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        @if ($isClone && $cloneSourceToken)
            <x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />
        @endif

        <div class="card">
            @include('modules.core.currencies.partials.form-header')

            <div class="card-body js-currency-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-currency-alert" role="alert">
                    <span class="js-currency-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="currency-doc-number">{{ __('common.fields.document_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="currency-doc-number" as="display" :value="$documentNumberValue" input-class="text-center js-currency-doc-number" />
                            @else
                                <x-forms.input id="currency-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control js-currency-doc-number" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}" />
                            @endif
                            <div class="form-text">{{ __('item_lookups.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="currency-doc-number-display"
                                as="display"
                                :label="__('common.fields.doc_number')"
                                :value="$record?->doc_number"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-5 col-lg-6' : 'col-md-8' }}">
                        <x-forms.label for="currency-name" :label="__('currencies.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="currency-name" :value="old('name', $recordName)" />
                        @else
                            <x-forms.input id="currency-name" autofocus name="name" type="text" class="form-control" value="{{ old('name', $recordName) }}" required />
                        @endif
                        @if ($isView)
                            <div class="mt-2 currency-main-option">
                                <span class="text-700 fs-10">{{ __('currencies.attributes.is_main') }}</span>
                                <span class="badge rounded-pill badge-subtle-{{ $record?->is_main ? 'success' : 'secondary' }}">
                                    {{ $record?->is_main ? __('common.actions.yes') : __('common.actions.no') }}
                                </span>
                            </div>
                        @else
                            <x-forms.input type="hidden" name="is_main" value="0" />
                            <div class="mt-2 mr-2 mb-0 form-check form-switch currency-main-option">
                                <x-forms.input class="form-check-input" id="currency-is-main" name="is_main" type="checkbox" value="1" :checked="old('is_main', $isClone ? false : ($record?->is_main ?? false))" />
                                <label class="form-check-label text-700 fs-10" for="currency-is-main">{{ __('currencies.attributes.is_main') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="is_main"></div>
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="currency-code" :label="__('currencies.attributes.code')" required />
                        @if ($isView)
                            <x-forms.view-field for="currency-code" :value="$codeValue" input-class="text-uppercase" />
                        @else
                            <x-forms.input id="currency-code" name="code" type="text" class="form-control text-uppercase" value="{{ $codeValue }}" maxlength="10" dir="ltr" required />
                        @endif
                        <div class="invalid-feedback" data-error-for="code"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="currency-minor-unit-name">{{ __('currencies.attributes.minor_unit_name') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="currency-minor-unit-name" :value="old('minor_unit_name', $record?->minor_unit_name)" />
                        @else
                            <x-forms.input id="currency-minor-unit-name" name="minor_unit_name" type="text" class="form-control" value="{{ old('minor_unit_name', $record?->minor_unit_name) }}" />
                        @endif
                        <div class="invalid-feedback" data-error-for="minor_unit_name"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="currency-minor-unit-factor" :label="__('currencies.attributes.minor_unit_factor')" required />
                        @if ($isView)
                            <x-forms.view-field for="currency-minor-unit-factor" :value="old('minor_unit_factor', $record?->minor_unit_factor ?? 100)" input-class="text-center" numeric dir="ltr" />
                        @else
                            <x-forms.numeric-input id="currency-minor-unit-factor" name="minor_unit_factor" :value="old('minor_unit_factor', $record?->minor_unit_factor ?? 100)" :scale="0" min="1" max="1000000" step="1" class="text-center" required />
                        @endif
                        <div class="invalid-feedback" data-error-for="minor_unit_factor"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="currency-status" :label="__('currencies.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="currency-status" :value="__('currencies.statuses.' . ($record?->status ?? 'active'))" />
                        @else
                            <x-forms.select id="currency-status" name="status" class="form-select" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected(old('status', $record?->status ?? 'active') === $status)>{{ __('currencies.statuses.' . $status) }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="currency-notes">{{ __('currencies.attributes.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="currency-notes" as="textarea" :value="old('notes', $record?->notes)" rows="4" />
                        @else
                            <x-forms.textarea id="currency-notes" name="notes" class="form-control" rows="4">{{ old('notes', $record?->notes) }}</x-forms.textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>
                </div>

                @if ($isEdit || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($record?->trashed() ?? false)"
                        :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>

            @include('modules.core.currencies.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $currencyMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'deleteConfirmTitle' => __('currencies.messages.delete_confirm_title'),
            'deleteConfirmText' => __('currencies.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('currencies.messages.delete_confirm_yes'),
            'restore' => __('currencies.trash.restore'),
            'restoreConfirmTitle' => __('currencies.trash.restore_confirm_title'),
            'restoreConfirmText' => __('currencies.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('currencies.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.currencyMessages = @json($currencyMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/currencies.js') }}"></script>
@endpush
