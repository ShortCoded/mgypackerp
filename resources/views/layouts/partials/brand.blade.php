@php
    $branding = $branding ?? $appBranding ?? app(\Modules\Core\Services\BrandingService::class)->current();
@endphp
<a class="navbar-brand erp-navbar-brand {{ $class ?? '' }}" href="{{ route('dashboard') }}" aria-label="{{ $branding['name'] }}" title="{{ $branding['name'] }}">
    <div class="d-flex align-items-center {{ $innerClass ?? '' }}">
        <img class="erp-navbar-brand-logo" src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }}">
    </div>
</a>
