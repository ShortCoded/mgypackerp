@extends('layouts.app')

@section('title', __('open_documents.title'))

@section('content')
    @php
        $selectedDocumentType = old('document_type', request()->query('document_type'));
        $selectedFromNumber = old('from_number', request()->integer('from_number') ?: null);
        $selectedToNumber = old('to_number', request()->integer('to_number') ?: null);
    @endphp
    <form class="js-open-documents-form" action="{{ route('admin.tools.open-documents.store') }}" method="POST" novalidate
          data-preview-url="{{ route('admin.tools.open-documents.preview') }}"
          data-preview-label="{{ __('open_documents.fields.preview') }}"
          data-confirm-label="{{ __('open_documents.actions.confirm_open') }}"
          data-open-label="{{ __('open_documents.actions.open') }}"
          data-confirm-title="{{ __('open_documents.messages.confirm') }}"
          data-confirm-yes="{{ __('open_documents.actions.open') }}"
          data-confirm-no="{{ __('common.actions.no') }}"
          data-validation-message="{{ __('common.messages.validation_failed') }}"
          data-error-message="{{ __('common.messages.unexpected_error') }}">
        @csrf

        <div class="card mb-3">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ __('open_documents.title') }}</h5>
                    </div>
                    <div class="col-auto">
                        <a class="btn btn-falcon-default" href="{{ route('dashboard') }}">
                            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
                        </a>
                    </div>
                    @if($canExecute)
                        <div class="col-auto">
                            <button class="btn btn-primary js-open-documents-submit" type="submit">
                                <span class="fas fa-unlock me-1"></span><span class="js-open-documents-action-text">{{ __('open_documents.fields.preview') }}</span>
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card-body">
                <div class="alert d-none js-open-documents-result" role="alert">
                    <div class="fw-semibold js-open-documents-result-message"></div>
                    <ul class="mb-0 mt-2 ps-3 js-open-documents-result-list"></ul>
                </div>

                <div class="row g-3">
                    <x-forms.input type="hidden" name="preview_token" value="" />
                    <div class="col-lg-4">
                        <x-forms.label for="open-document-type" :label="__('open_documents.fields.document_type')" required />
                        <x-forms.select id="open-document-type" name="document_type" class="form-select js-open-documents-type" data-placeholder="{{ __('common.placeholders.select') }}" required>
                            <option value=""></option>
                            @foreach ($documentTypes as $key => $label)
                                <option value="{{ $key }}" @selected($selectedDocumentType === $key)>{{ $label }}</option>
                            @endforeach
                        </x-forms.select>
                        <div class="invalid-feedback" data-error-for="document_type"></div>
                    </div>

                    <div class="col-md-6 col-lg-4">
                        <x-forms.label for="open-document-from-number" :label="__('open_documents.fields.from_number')" required />
                        <x-forms.input id="open-document-from-number" name="from_number" class="form-control text-center" type="number" min="1" step="1" value="{{ $selectedFromNumber }}" required />
                        <div class="invalid-feedback" data-error-for="from_number"></div>
                    </div>

                    <div class="col-md-6 col-lg-4">
                        <x-forms.label for="open-document-to-number" :label="__('open_documents.fields.to_number')" required />
                        <x-forms.input id="open-document-to-number" name="to_number" class="form-control text-center" type="number" min="1" step="1" value="{{ $selectedToNumber }}" required />
                        <div class="invalid-feedback" data-error-for="to_number"></div>
                    </div>
                    <div class="col-12">
                        <div class="js-purchase-source-period d-none mb-3">
                            <x-forms.label for="open-document-source-period" :label="__('open_documents.fields.source_period')" />
                            <x-forms.select id="open-document-source-period" name="source_period_doc_num" class="form-select js-select2-ajax"
                                data-url="{{ route('admin.select2.financial-periods', ['access_scope' => 'operating_scope', 'company_doc_num' => $activeCompanyDocNum]) }}"
                                data-placeholder="{{ __('open_documents.fields.current_period_default') }}" data-allow-clear="true">
                                <option value=""></option>
                                @if($sourcePeriod)<option value="{{ $sourcePeriod->doc_num }}" selected>{{ $sourcePeriod->name }} / {{ $sourcePeriod->doc_num }}</option>@endif
                            </x-forms.select>
                            <div class="invalid-feedback" data-error-for="source_period_doc_num"></div>
                            <p class="small text-muted mt-1 mb-0">{{ __('open_documents.messages.source_period_help') }}</p>
                        </div>
                        <x-forms.label for="open-document-reason" :label="__('open_documents.fields.reason')" />
                        <x-forms.textarea id="open-document-reason" name="reason" rows="2" maxlength="2000" data-required-types="sales_requests,sales_orders,customer_invoices,purchase_orders,purchase_requisitions,production_material_requests,purchase_receipts,purchase_invoices,inventory_movements">{{ old('reason') }}</x-forms.textarea>
                        <div class="invalid-feedback" data-error-for="reason"></div>
                    </div>
                </div>
                <section class="mt-4 d-none js-open-documents-preview" aria-live="polite">
                    <h6 class="mb-2">{{ __('open_documents.fields.preview') }}</h6>
                    <p class="small text-muted mb-2 js-open-documents-preview-context"></p>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead><tr>
                                <th>{{ __('open_documents.preview_columns.document') }}</th>
                                <th>{{ __('open_documents.preview_columns.status') }}</th>
                                <th>{{ __('open_documents.preview_columns.decision') }}</th>
                                <th>{{ __('open_documents.preview_columns.amount') }}</th>
                                <th>{{ __('open_documents.preview_columns.lines') }}</th>
                                <th>{{ __('open_documents.preview_columns.dependencies') }}</th>
                                <th>{{ __('open_documents.preview_columns.effect') }}</th>
                            </tr></thead>
                            <tbody class="js-open-documents-preview-rows"></tbody>
                        </table>
                    </div>
                    <p class="small text-muted mt-2 mb-0 js-open-documents-preview-missing"></p>
                </section>
            </div>

            <div class="card-footer bg-body-tertiary text-end">
                <a class="btn btn-falcon-default me-2" href="{{ route('dashboard') }}">{{ __('common.actions.back') }}</a>
                @if($canExecute)
                    <button class="btn btn-primary js-open-documents-submit" type="submit">
                        <span class="fas fa-unlock me-1"></span><span class="js-open-documents-action-text">{{ __('open_documents.fields.preview') }}</span>
                    </button>
                @endif
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $openDocumentsMessages = [
            'reviewCorrection' => __('open_documents.actions.review_correction'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'loading' => __('common.messages.loading'),
            'previewPeriod' => __('open_documents.messages.preview_period'),
            'previewNotFound' => __('open_documents.messages.preview_not_found'),
            'correctionSteps' => __('open_documents.correction_steps.title'),
            'correctionHelp' => __('open_documents.correction_steps.help'),
            'correctionPermission' => __('open_documents.correction_steps.permission'),
            'correctionItem' => __('open_documents.corrections.item'),
            'correctionQuantity' => __('open_documents.corrections.quantity'),
            'correctionBefore' => __('open_documents.corrections.before'),
            'correctionAfter' => __('open_documents.corrections.after'),
            'correctionLayer' => __('open_documents.corrections.layer'),
            'correctionValue' => __('open_documents.corrections.value'),
        ];
    @endphp
    <script>
        window.openDocumentsMessages = @json($openDocumentsMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Core/open-documents.js') }}"></script>
@endpush
