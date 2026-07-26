@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $isItemUnits = $definition->key === 'item_units';
    $title = match ($mode) {
        'edit' => __($definition->translationKey . '.edit'),
        'view' => __($definition->translationKey . '.view'),
        'clone' => __('item_lookups.titles.clone'),
        default => __($definition->translationKey . '.create'),
    };
    //$recordName = $isClone && $record ? __('item_lookups.defaults.clone_name', ['name' => $record->name]) : $record?->name;
    $recordName = $record
    ? ($isClone
        ? __('item_lookups.defaults.clone_name', ['name' => $record->name])
        : $record->name)
    : "";
    $documentNumberValue = old('doc_number', ($isEdit || $isView) ? $record?->doc_number : '');
    $equivalentUnit = $isItemUnits ? $record?->equivalentUnit : null;
    $equivalentValue = old('equivalent_value', $isItemUnits ? $record?->equivalent_value : '');
    $equivalentUnitDocNum = old('equivalent_unit_doc_num', $equivalentUnit?->doc_num ?? '');
    $equivalentUnitLabel = $equivalentUnit ? trim(implode(' / ', array_filter([$equivalentUnit->doc_num, $equivalentUnit->name]))) : '';
    $equivalentUnitExtraParams = $isEdit ? ['exclude_doc_num' => '#item-lookup-current-doc-num'] : [];
    $originalRecordData = [
        'name' => $recordName,
        'status' => $record?->status ?? 'active',
        'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $record?->doc_number : '') : null,
        'notes' => $record?->notes,
    ];

    if ($isItemUnits) {
        $originalRecordData['equivalent_value'] = $record?->equivalent_value;
        $originalRecordData['equivalent_unit_doc_num'] = $equivalentUnit?->doc_num ?? '';
    }

    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
@endphp

@section('title', $title)

@section('content')
    <form id="item-lookup-form" class="js-item-lookup-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalRecordData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif
        @if ($isEdit && $record)
            <input type="hidden" id="item-lookup-current-doc-num" value="{{ $record->doc_num }}">
        @endif

        <div class="card">
            @include('modules.core.item-lookups.partials.form-header')

            <div class="card-body js-item-lookup-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-item-lookup-alert" role="alert">
                    <span class="js-item-lookup-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="item-lookup-doc-number">{{ __('common.fields.document_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="item-lookup-doc-number" as="display" :value="$documentNumberValue" input-class="text-center js-item-lookup-doc-number" />
                            @else
                                <input id="item-lookup-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control js-item-lookup-doc-number" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                            @endif
                            <div class="form-text">{{ __('item_lookups.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="item-lookup-doc-number-display"
                                as="display"
                                :label="__('common.fields.doc_number')"
                                :value="$record?->doc_number"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-6 col-lg-7' : 'col-md-7' }}">
                        <x-forms.label for="item-lookup-name" :label="__('item_lookups.fields.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="item-lookup-name" :value="old('name', $recordName)" />
                        @else
                            <input id="item-lookup-name" autofocus name="name" type="text" class="form-control" value="{{ old('name', $recordName) }}" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-md-3 col-lg-2">
                        <x-forms.label for="item-lookup-status" :label="__('item_lookups.fields.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="item-lookup-status" :value="__('item_lookups.statuses.' . ($record?->status ?? 'active'))" />
                        @else
                            <select id="item-lookup-status" name="status" class="form-select" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected(old('status', $record?->status ?? 'active') === $status)>{{ __('item_lookups.statuses.' . $status) }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    @if ($isItemUnits)
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label" for="equivalent_value">{{ __('item_units.fields.equivalent_value') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="equivalent_value" :value="$record?->equivalent_value" input-class="text-end" dir="ltr" numeric />
                            @else
                                <x-forms.numeric-input
                                    id="equivalent_value"
                                    name="equivalent_value"
                                    :value="$equivalentValue"
                                    :scale="6"
                                    :allow-negative="false"
                                    min="0.000001"
                                    step="0.000001"
                                    class="text-end"
                                />
                            @endif
                            <div class="invalid-feedback" data-error-for="equivalent_value"></div>
                        </div>

                        <div class="col-md-8 col-lg-5 js-select2-field">
                            <label class="form-label" for="equivalent_unit_doc_num">{{ __('item_units.fields.equivalent_unit') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="equivalent_unit_doc_num" :value="$equivalentUnitLabel" />
                            @else
                                <select class="form-select js-select2-ajax js-item-unit-equivalent-unit" id="equivalent_unit_doc_num" name="equivalent_unit_doc_num" data-url="{{ route('admin.select2.item-units') }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true" @if ($equivalentUnitExtraParams !== []) data-extra-params='@json($equivalentUnitExtraParams)' @endif>
                                    @if ($equivalentUnitDocNum !== '' && $equivalentUnitLabel !== '')
                                        <option value="{{ $equivalentUnitDocNum }}" selected>{{ $equivalentUnitLabel }}</option>
                                    @endif
                                </select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="equivalent_unit_doc_num"></div>
                        </div>
                    @endif

                    <div class="col-12">
                        <label class="form-label" for="item-lookup-notes">{{ __('item_lookups.fields.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="item-lookup-notes" as="textarea" :value="old('notes', $record?->notes)" rows="4" />
                        @else
                            <textarea id="item-lookup-notes" name="notes" class="form-control" rows="4">{{ old('notes', $record?->notes) }}</textarea>
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

            @include('modules.core.item-lookups.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $itemLookupMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'deleteConfirmTitle' => __('item_lookups.messages.delete_confirm_title'),
            'deleteConfirmText' => __('item_lookups.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('item_lookups.messages.delete_confirm_yes'),
            'restore' => __('item_lookups.trash.restore'),
            'restoreConfirmTitle' => __('item_lookups.trash.restore_confirm_title'),
            'restoreConfirmText' => __('item_lookups.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('item_lookups.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.itemLookupMessages = @json($itemLookupMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/item-lookups.js') }}"></script>
@endpush
