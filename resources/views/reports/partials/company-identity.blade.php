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
        <tr>
            <td><strong>{{ __('companies.print.commercial_register_number') }}:</strong> {{ $identity['commercial_register_number'] ?? '—' }}</td>
            <td><strong>{{ __('companies.print.tax_card_number') }}:</strong> {{ $identity['tax_card_number'] ?? '—' }}</td>
            <td><strong>{{ __('companies.print.vat_registration_number') }}:</strong> {{ $identity['vat_registration_number'] ?? '—' }}</td>
        </tr>
        <tr>
            <td colspan="3">{{ collect([$identity['address'] ?? null, $identity['phone'] ?? null, $identity['email'] ?? null])->filter()->join(' — ') }}</td>
        </tr>
    </table>
@endif
