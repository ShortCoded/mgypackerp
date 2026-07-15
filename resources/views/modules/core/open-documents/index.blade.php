@extends('layouts.app')

@section('title', __('open_documents.title'))

@section('content')
    <form class="js-open-documents-form" action="{{ route('admin.tools.open-documents.store') }}" method="POST" novalidate
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
                    @can('tools.open_documents.execute')
                        <div class="col-auto">
                            <button class="btn btn-primary js-open-documents-submit" type="submit">
                                <span class="fas fa-unlock me-1"></span>{{ __('open_documents.actions.open') }}
                            </button>
                        </div>
                    @endcan
                </div>
            </div>

            <div class="card-body">
                <div class="alert d-none js-open-documents-result" role="alert">
                    <div class="fw-semibold js-open-documents-result-message"></div>
                    <ul class="mb-0 mt-2 ps-3 js-open-documents-result-list"></ul>
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <x-forms.label for="open-document-type" :label="__('open_documents.fields.document_type')" required />
                        <select id="open-document-type" name="document_type" class="form-select js-open-documents-type @error('document_type') is-invalid @enderror" data-placeholder="{{ __('common.placeholders.select') }}" required>
                            <option value=""></option>
                            @foreach ($documentTypes as $key => $label)
                                <option value="{{ $key }}" @selected(old('document_type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" data-error-for="document_type">@error('document_type'){{ $message }}@enderror</div>
                    </div>

                    <div class="col-md-6 col-lg-4">
                        <x-forms.label for="open-document-from-number" :label="__('open_documents.fields.from_number')" required />
                        <input id="open-document-from-number" name="from_number" class="form-control text-center @error('from_number') is-invalid @enderror" type="number" min="1" step="1" value="{{ old('from_number') }}" required>
                        <div class="invalid-feedback" data-error-for="from_number">@error('from_number'){{ $message }}@enderror</div>
                    </div>

                    <div class="col-md-6 col-lg-4">
                        <x-forms.label for="open-document-to-number" :label="__('open_documents.fields.to_number')" required />
                        <input id="open-document-to-number" name="to_number" class="form-control text-center @error('to_number') is-invalid @enderror" type="number" min="1" step="1" value="{{ old('to_number') }}" required>
                        <div class="invalid-feedback" data-error-for="to_number">@error('to_number'){{ $message }}@enderror</div>
                    </div>
                </div>
            </div>

            <div class="card-footer bg-body-tertiary text-end">
                <a class="btn btn-falcon-default me-2" href="{{ route('dashboard') }}">{{ __('common.actions.back') }}</a>
                @can('tools.open_documents.execute')
                    <button class="btn btn-primary js-open-documents-submit" type="submit">
                        <span class="fas fa-unlock me-1"></span>{{ __('open_documents.actions.open') }}
                    </button>
                @endcan
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $openDocumentsMessages = [
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'loading' => __('common.messages.loading'),
        ];
    @endphp
    <script>
        window.openDocumentsMessages = @json($openDocumentsMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/open-documents.js') }}"></script>
@endpush
