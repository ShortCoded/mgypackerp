@extends('layouts.app')

@section('title', __('customer_terms.edit_title', ['customer' => $customer->name]))

@push('styles')
    <link href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('vendors/summernote/summernote-bs5.min.css') }}" rel="stylesheet">
    <style>
        .customer-term-editor-card { border: 1px solid var(--falcon-border-color, #d8e2ef); transition: border-color .15s ease, box-shadow .15s ease; }
        .customer-term-editor-card:focus-within { border-color: rgba(44,123,229,.55); box-shadow: 0 0 0 .15rem rgba(44,123,229,.08); }
        .customer-term-editor-card .note-editor { border: 0 !important; margin-bottom: 0; }
        .customer-term-editor-card .note-toolbar { background: var(--falcon-gray-100, #f9fafd); border-bottom: 1px solid var(--falcon-border-color, #d8e2ef); }
        .customer-term-editor-card .note-editable { background: #fff; }
        [dir="rtl"] .customer-term-editor-card .note-editable { direction: rtl; text-align: right; }
        .customer-terms-savebar { backdrop-filter: blur(8px); background: rgba(255,255,255,.94); bottom: 0; position: sticky; z-index: 5; }
    </style>
@endpush

@section('content')
    @php
        $sections = [
            'quotation_terms' => ['quotation' => 'terms', 'icon' => 'fa-file-signature'],
            'quotation_payment_terms' => ['quotation' => 'payment_terms', 'icon' => 'fa-money-check-alt'],
            'quotation_execution_terms' => ['quotation' => 'execution_terms', 'icon' => 'fa-tasks'],
            'quotation_warranty_terms' => ['quotation' => 'warranty_terms', 'icon' => 'fa-shield-alt'],
            'quotation_delivery_terms' => ['quotation' => 'delivery_terms', 'icon' => 'fa-truck'],
            'quotation_technical_notes' => ['quotation' => 'technical_notes', 'icon' => 'fa-drafting-compass'],
        ];
        $configured = collect(array_keys($sections))->filter(fn ($field) => filled($customer->{$field}))->count();
    @endphp

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3"><div><h4 class="mb-1">{{ __('customer_terms.edit_title', ['customer' => $customer->name]) }}</h4><p class="text-600 mb-0">{{ __('customer_terms.edit_subtitle') }}</p></div><a class="btn btn-falcon-default" href="{{ route('admin.sales.customer-terms.index') }}"><span class="fas {{ config('languages.available.'.app()->getLocale().'.dir') === 'rtl' ? 'fa-arrow-right' : 'fa-arrow-left' }} me-1"></span>{{ __('common.actions.back') }}</a></div>

    <div class="card shadow-none border mb-3"><div class="card-body py-3"><div class="row g-3 align-items-center"><div class="col-md"><div class="small text-600 mb-1">{{ __('customer_terms.customer_profile') }}</div><div class="d-flex flex-wrap align-items-center gap-2"><span class="fw-bold text-900">{{ $customer->name }}</span><span class="badge badge-subtle-primary" dir="ltr">{{ $customer->doc_num }}</span></div></div><div class="col-md-5"><div class="d-flex justify-content-between small mb-1"><span>{{ __('customer_terms.setup_progress') }}</span><strong>{{ __('customer_terms.configured_of_total', ['configured' => $configured, 'total' => 6]) }}</strong></div><div class="progress" style="height:.5rem"><div class="progress-bar bg-{{ $configured === 6 ? 'success' : 'primary' }}" style="width:{{ ($configured / 6) * 100 }}%"></div></div></div></div></div></div>
    <div class="alert alert-info d-flex gap-2 align-items-start" role="status"><span class="fas fa-info-circle mt-1"></span><div><strong>{{ __('customer_terms.automatic_defaults') }}</strong><div class="small">{{ __('customer_terms.default_help') }}</div></div></div>

    <form method="POST" action="{{ route('admin.sales.customer-terms.update', $customer) }}" id="customer_terms_form">
        @csrf
        @method('PUT')
        <div class="row g-3">
            @foreach($sections as $field => $section)
                @php($hasValue = filled(old($field, $customer->{$field})))
                <div class="col-12 col-xl-6"><div class="card customer-term-editor-card h-100">
                    <div class="card-header bg-white d-flex align-items-start justify-content-between gap-2 py-3"><div class="d-flex gap-2"><span class="text-primary mt-1"><span class="fas {{ $section['icon'] }}"></span></span><div><label class="fw-semibold text-900 mb-1" for="{{ $field }}">{{ __('quotations.attributes.'.$section['quotation']) }}</label><div class="small text-600">{{ __('customer_terms.field_help.'.$field) }}</div></div></div><span class="badge badge-subtle-{{ $hasValue ? 'success' : 'secondary' }} white-space-nowrap">{{ __('customer_terms.'.($hasValue ? 'section_ready' : 'section_empty')) }}</span></div>
                    <div class="card-body p-0"><x-forms.textarea class="form-control js-customer-terms-editor" id="{{ $field }}" name="{{ $field }}" rows="6" data-direction="{{ config('languages.available.'.app()->getLocale().'.dir', 'ltr') }}">{{ old($field, $customer->{$field}) }}</x-forms.textarea></div>
                    @error($field)<div class="alert alert-danger rounded-0 border-0 mb-0 py-2">{{ $message }}</div>@enderror
                </div></div>
            @endforeach
        </div>
        <div class="customer-terms-savebar border-top mt-4 py-3 d-flex flex-wrap align-items-center justify-content-between gap-2"><span class="small text-600"><span class="fas fa-history me-1"></span>{{ __('customer_terms.unsaved_hint') }}</span><button class="btn btn-primary" type="submit"><span class="fas fa-save me-1"></span>{{ __('customer_terms.save_changes') }}</button></div>
    </form>
@endsection

@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('vendors/summernote/summernote-bs5.min.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.jQuery || !window.jQuery.fn.summernote) return;
            const icons = { bold:'fas fa-bold', italic:'fas fa-italic', underline:'fas fa-underline', eraser:'fas fa-eraser', unorderedlist:'fas fa-list-ul', orderedlist:'fas fa-list-ol', alignLeft:'fas fa-align-left', alignCenter:'fas fa-align-center', alignRight:'fas fa-align-right', alignJustify:'fas fa-align-justify', outdent:'fas fa-outdent', indent:'fas fa-indent', link:'fas fa-link', table:'fas fa-table', code:'fas fa-code', caret:'fas fa-caret-down', close:'fas fa-times', undo:'fas fa-undo', redo:'fas fa-redo' };
            window.jQuery('.js-customer-terms-editor').each(function () { const editor = window.jQuery(this); editor.summernote({ height:150, direction:editor.data('direction') || 'ltr', icons, dialogsInBody:true, toolbar:[['style',['bold','italic','underline','clear']],['para',['ul','ol','paragraph']],['insert',['link','table']],['view',['codeview']]] }); });
        });
    </script>
@endpush
