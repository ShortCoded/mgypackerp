@if($showCompanyIdentity ?? true)
@php
    $identity = $companyPrintIdentity ?? [];
    $hasAuthorization = ($identity['authorized_signatory_name'] ?? null)
        || ($identity['authorized_signatory_title'] ?? null)
        || ($identity['authorized_signatory_signature_source'] ?? null)
        || ($identity['company_stamp_source'] ?? null);
@endphp

@if($hasAuthorization)
    <table class="document-authorization-table">
        <tr>
            <td>
                <strong>{{ __('companies.print.authorized_signatory') }}</strong><br>
                {{ $identity['authorized_signatory_name'] ?? '' }}<br>
                {{ $identity['authorized_signatory_title'] ?? '' }}
                @if($identity['authorized_signatory_signature_source'] ?? null)
                    <br><img src="{{ $identity['authorized_signatory_signature_source'] }}" alt="{{ __('companies.print.authorized_signatory') }}">
                @endif
            </td>
            <td>
                <strong>{{ __('companies.print.company_stamp') }}</strong>
                @if($identity['company_stamp_source'] ?? null)
                    <br><img src="{{ $identity['company_stamp_source'] }}" alt="{{ __('companies.print.company_stamp') }}">
                @endif
            </td>
        </tr>
    </table>
@endif

@endif
