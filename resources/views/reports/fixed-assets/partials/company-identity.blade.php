@php
    $identity = $companyPrintIdentity;
    $legalNumbers = array_filter([
        __('companies.print.commercial_register_number') => $identity['commercial_register_number'],
        __('companies.print.tax_card_number') => $identity['tax_card_number'],
        __('companies.print.vat_registration_number') => $identity['vat_registration_number'],
    ]);
@endphp

<table class="fa-pdf-company-identity">
    <tr>
        <td width="40%">
            <div class="fa-pdf-company-name">{{ $identity['legal_name'] ?: $identity['name'] }}</div>
            @if ($identity['legal_name'] && $identity['legal_name'] !== $identity['name'])
                <div class="fa-pdf-company-detail">{{ $identity['name'] }}</div>
            @endif
        </td>
        <td width="30%" class="fa-pdf-company-detail">
            @foreach ($legalNumbers as $label => $value)
                <div><strong>{{ $label }}:</strong> <span class="fa-pdf-code">{{ $value }}</span></div>
            @endforeach
        </td>
        <td width="30%" class="fa-pdf-company-detail">
            @if ($identity['address'])<div>{{ $identity['address'] }}</div>@endif
            @if ($identity['phone'])<div class="fa-pdf-code">{{ $identity['phone'] }}</div>@endif
            @if ($identity['email'])<div class="fa-pdf-code">{{ $identity['email'] }}</div>@endif
        </td>
    </tr>
</table>
