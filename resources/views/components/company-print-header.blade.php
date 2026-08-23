@props(['identity'])

<header {{ $attributes->class(['erp-document-company-header']) }}>
    <div class="d-flex align-items-start justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3 min-w-0">
            @if ($identity['logo_url'])
                <img class="erp-document-company-logo flex-shrink-0" src="{{ $identity['logo_url'] }}" alt="{{ $identity['name'] }}">
            @endif
            <div class="min-w-0">
                <h4 class="mb-1 text-break">{{ $identity['legal_name'] ?: $identity['name'] }}</h4>
                @if ($identity['legal_name'] && $identity['legal_name'] !== $identity['name'])
                    <div class="text-700 text-break">{{ $identity['name'] }}</div>
                @endif
            </div>
        </div>
    </div>

    @php
        $companyMetadata = [
            __('companies.print.commercial_register_number') => $identity['commercial_register_number'],
            __('companies.print.tax_card_number') => $identity['tax_card_number'],
            __('companies.print.vat_registration_number') => $identity['vat_registration_number'],
        ];
    @endphp

    @if (array_filter($companyMetadata) || $identity['address'] || $identity['phone'] || $identity['email'])
        <div class="row g-2 mt-2 small text-700">
            @foreach ($companyMetadata as $label => $value)
                @if ($value)
                    <div class="col-md-4"><span class="fw-semibold">{{ $label }}:</span> <span dir="ltr">{{ $value }}</span></div>
                @endif
            @endforeach
            @if ($identity['address'])
                <div class="col-12">{{ $identity['address'] }}</div>
            @endif
            @if ($identity['phone'])
                <div class="col-md-6" dir="ltr">{{ $identity['phone'] }}</div>
            @endif
            @if ($identity['email'])
                <div class="col-md-6" dir="ltr">{{ $identity['email'] }}</div>
            @endif
        </div>
    @endif
</header>
