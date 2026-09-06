@if($showCompanyIdentity ?? true)
@php($identity = $companyPrintIdentity ?? [])

@if(array_filter([
    $identity['commercial_register_number'] ?? null,
    $identity['tax_card_number'] ?? null,
    $identity['vat_registration_number'] ?? null,
    $identity['address'] ?? null,
    $identity['phone'] ?? null,
    $identity['email'] ?? null,
]))
    <table class="document-identity-table">
        @php($registrationFields = array_filter([
            __('companies.print.commercial_register_number') => $identity['commercial_register_number'] ?? null,
            __('companies.print.tax_card_number') => $identity['tax_card_number'] ?? null,
            __('companies.print.vat_registration_number') => $identity['vat_registration_number'] ?? null,
        ]))
        @if($registrationFields)
            <tr>@foreach($registrationFields as $label => $value)<td><strong>{{ $label }}:</strong> {{ $value }}</td>@endforeach</tr>
        @endif
        <tr>
            <td colspan="{{ max(1, count($registrationFields)) }}">{{ collect([$identity['address'] ?? null, $identity['phone'] ?? null, $identity['email'] ?? null])->filter()->join(' — ') }}</td>
        </tr>
    </table>
@endif

@endif
