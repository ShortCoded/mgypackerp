@php
    $branding = $branding ?? $appBranding ?? app(\Modules\Core\Services\BrandingService::class)->current();
@endphp
<a class="navbar-brand {{ $class ?? '' }}" href="{{ route('dashboard') }}">
    <div class="d-flex align-items-center {{ $innerClass ?? '' }}">
        <img class="me-2" src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }}"
            style="max-height: {{ $height ?? 80 }}px;max-width: {{ $width ?? 80 }}px">
    </div>
</a>
