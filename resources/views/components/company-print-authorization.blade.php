@props(['identity'])

@php
    $hasSignatory = $identity['authorized_signatory_name']
        || $identity['authorized_signatory_title']
        || $identity['authorized_signatory_signature_url'];
    $hasAuthorization = $hasSignatory || $identity['company_stamp_url'];
@endphp

@if ($hasAuthorization)
    <footer {{ $attributes->class(['erp-document-authorization row g-4 align-items-end']) }}>
        <div class="col-6 text-center">
            <div class="fw-semibold mb-2">{{ __('companies.print.authorized_signatory') }}</div>
            @if ($identity['authorized_signatory_name'])
                <div class="fw-semibold">{{ $identity['authorized_signatory_name'] }}</div>
            @endif
            @if ($identity['authorized_signatory_title'])
                <div class="small text-700">{{ $identity['authorized_signatory_title'] }}</div>
            @endif
            @if ($identity['authorized_signatory_signature_url'])
                <img class="erp-document-signature mt-2" src="{{ $identity['authorized_signatory_signature_url'] }}" alt="{{ __('companies.fields.authorized_signatory_signature') }}">
            @endif
        </div>

        <div class="col-6 text-center">
            <div class="fw-semibold mb-2">{{ __('companies.print.company_stamp') }}</div>
            @if ($identity['company_stamp_url'])
                <img class="erp-document-stamp" src="{{ $identity['company_stamp_url'] }}" alt="{{ __('companies.fields.company_stamp') }}">
            @endif
        </div>
    </footer>
@endif
