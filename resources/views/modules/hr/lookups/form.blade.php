@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $title = match ($mode) {
        'edit' => __('hr.' . $definition->translationKey . '.edit'),
        'view' => __('hr.' . $definition->translationKey . '.view'),
        'clone' => __('hr.titles.clone'),
        default => __('hr.' . $definition->translationKey . '.create'),
    };
    $recordName = $isClone && $record ? __('hr.defaults.clone_name', ['name' => $record->name]) : $record?->name;
    $documentNumberValue = old('doc_number', ($isEdit || $isView) ? $record?->doc_number : '');
    $originalRecordData = [
        'name' => $recordName,
        'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $record?->doc_number : '') : null,
        'notes' => $record?->notes,
    ];
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
@endphp

@section('title', $title)

@section('content')
    <form id="hr-lookup-form" class="js-hr-lookup-form" action="{{ $action }}" method="{{ $method }}" data-mode="{{ $mode }}" data-original='@json($originalRecordData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card">
            @include('modules.hr.lookups.partials.form-header')

            <div class="card-body js-hr-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-hr-alert" role="alert">
                    <span class="js-hr-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="hr-lookup-doc-number">{{ __('common.fields.document_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="hr-lookup-doc-number" as="display" :value="$documentNumberValue" input-class="text-center js-hr-doc-number" />
                            @else
                                <input id="hr-lookup-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control js-hr-doc-number" value="{{ $documentNumberValue }}" placeholder="{{ __('hr.document_number_control.placeholder') }}">
                            @endif
                            <div class="form-text">{{ __('hr.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="hr-lookup-doc-number-display"
                                as="display"
                                :label="__('common.fields.doc_number')"
                                :value="$record?->doc_number"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-9 col-lg-10' : 'col-12' }}">
                        <x-forms.label for="hr-lookup-name" :label="__('common.fields.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="hr-lookup-name" :value="old('name', $recordName)" />
                        @else
                            <input id="hr-lookup-name" autofocus name="name" type="text" class="form-control" value="{{ old('name', $recordName) }}" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="hr-lookup-notes">{{ __('common.fields.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="hr-lookup-notes" as="textarea" :value="old('notes', $record?->notes)" rows="4" />
                        @else
                            <textarea id="hr-lookup-notes" name="notes" class="form-control" rows="4">{{ old('notes', $record?->notes) }}</textarea>
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

            @include('modules.hr.lookups.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $hrMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'deleteConfirmTitle' => __('hr.messages.delete_confirm_title'),
            'deleteConfirmText' => __('hr.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('hr.messages.delete_confirm_yes'),
            'restore' => __('hr.trash.restore'),
            'restoreConfirmTitle' => __('hr.trash.restore_confirm_title'),
            'restoreConfirmText' => __('hr.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('hr.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.hrLookupMessages = @json($hrMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/HR/hr-lookups.js') }}"></script>
@endpush
