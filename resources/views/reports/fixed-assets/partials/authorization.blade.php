@php
    $identity = $companyPrintIdentity;
    $hasSignatory = $identity['authorized_signatory_name']
        || $identity['authorized_signatory_title']
        || $identity['authorized_signatory_signature_source'];
    $hasAuthorization = $hasSignatory || $identity['company_stamp_source'];
@endphp

@if ($hasAuthorization)
    <table class="fa-pdf-authorization">
        <tr>
            <td>
                @if ($hasSignatory)
                    <div class="fa-pdf-authorization-title">{{ __('companies.print.authorized_signatory') }}</div>
                    @if ($identity['authorized_signatory_name'])<div>{{ $identity['authorized_signatory_name'] }}</div>@endif
                    @if ($identity['authorized_signatory_title'])<div class="fa-pdf-company-detail">{{ $identity['authorized_signatory_title'] }}</div>@endif
                    @if ($identity['authorized_signatory_signature_source'])
                        <img class="fa-pdf-signature" src="{{ $identity['authorized_signatory_signature_source'] }}" alt="{{ __('companies.fields.authorized_signatory_signature') }}">
                    @endif
                @endif
            </td>
            <td>
                @if ($identity['company_stamp_source'])
                    <div class="fa-pdf-authorization-title">{{ __('companies.print.company_stamp') }}</div>
                    <img class="fa-pdf-stamp" src="{{ $identity['company_stamp_source'] }}" alt="{{ __('companies.fields.company_stamp') }}">
                @endif
            </td>
        </tr>
    </table>
@endif
