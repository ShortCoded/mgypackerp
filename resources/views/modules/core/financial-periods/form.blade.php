@extends('layouts.app')

@php
    $record = $financialPeriod ?? null;
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isCreate = in_array($mode, ['create', 'clone'], true);
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $dateValue = fn (string $field) => old($field, $record?->{$field} ? $dateFormatService->formatDate($record->{$field}, '') : '');
    $title = match ($mode) {
        'edit' => __('financial_periods.edit'),
        'view' => __('financial_periods.view'),
        'clone' => __('financial_periods.titles.clone'),
        default => __('financial_periods.create'),
    };
    $fieldValue = fn (string $field, mixed $default = '') => old($field, $record?->{$field} ?? $default);
    $documentNumberValue = old('doc_number', ! $isCreate ? $record?->doc_number : '');
    $statusValue = old('is_closed', $record?->is_closed ? '1' : '0');
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
@endphp

@section('title', $title)

@section('content')
    <form id="financial-period-form" action="{{ $action }}" method="POST" data-mode="{{ $mode }}" novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if (! empty($cloneSourceToken))
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3">
            @include('modules.core.financial-periods.partials.form-header')

            <div class="card-body">
                <div data-form-alert></div>

                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="doc_number">{{ __('financial_periods.attributes.doc_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" inputmode="numeric" value="{{ $documentNumberValue }}">
                            @endif
                            <div class="form-text">{{ __('financial_periods.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="doc_number_display"
                                as="display"
                                :label="__('financial_periods.attributes.doc_num')"
                                :value="$record?->doc_num"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-6 col-lg-7' : 'col-md-8' }}">
                        <x-forms.label for="name" :label="__('financial_periods.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="name" :value="$fieldValue('name')" />
                        @else
                            <input class="form-control" id="name" name="name" type="text" value="{{ $fieldValue('name') }}" required autofocus>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-3' : 'col-md-4' }}">
                        <x-forms.label for="is_closed" :label="__('financial_periods.attributes.is_closed')" required />
                        @if ($isView)
                            <x-forms.view-field
                                for="is_closed"
                                :value="$record?->is_closed ? __('financial_periods.statuses.closed') : __('financial_periods.statuses.open')"
                            />
                        @else
                            <select class="form-select" id="is_closed" name="is_closed" required>
                                <option value="0" @selected((string) $statusValue === '0')>{{ __('financial_periods.statuses.open') }}</option>
                                <option value="1" @selected((string) $statusValue === '1')>{{ __('financial_periods.statuses.closed') }}</option>
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="is_closed"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="from_date" :label="__('financial_periods.attributes.from_date')" required />
                        @if ($isView)
                            <x-forms.view-field for="from_date" :value="$dateValue('from_date')" dir="ltr" input-class="date-value" />
                        @else
                            <input class="form-control js-date-picker" id="from_date" name="from_date" type="text" value="{{ $dateValue('from_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="from_date"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="to_date" :label="__('financial_periods.attributes.to_date')" required />
                        @if ($isView)
                            <x-forms.view-field for="to_date" :value="$dateValue('to_date')" dir="ltr" input-class="date-value" />
                        @else
                            <input class="form-control js-date-picker" id="to_date" name="to_date" type="text" value="{{ $dateValue('to_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="to_date"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('financial_periods.attributes.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="notes" as="textarea" :value="old('notes', $record?->notes)" rows="4" />
                        @else
                            <textarea class="form-control" id="notes" name="notes" rows="4">{{ old('notes', $record?->notes) }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>
                </div>

                @if (! $isCreate)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($record?->trashed() ?? false)"
                        :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>

            @include('modules.core.financial-periods.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $coreFinancialPeriodsMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'saved' => __('common.messages.saved_successfully'),
            'validationFailed' => __('common.messages.validation_failed'),
            'validationSummaryTitle' => __('financial_periods.validation.summary_title'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'cancel' => __('common.actions.cancel'),
            'confirm' => __('common.actions.confirm'),
        ];
    @endphp
    <script>
        window.coreFinancialPeriodsMessages = @json($coreFinancialPeriodsMessages);
    </script>
    <script src="{{ asset('assets/js/modules/Core/financial-periods.js') }}"></script>
@endpush
