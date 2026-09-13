@php
    $appBranding = $appBranding ?? app(\Modules\Core\Services\BrandingService::class)->current();
    $appPwaSettings = $appPwaSettings ?? app(\Modules\Core\Services\PwaSettingsService::class)->settings();
    $erpAsset = app(\Modules\Core\Services\AssetVersionService::class);
    $isRtlLocale = config('languages.available.' . app()->getLocale() . '.dir', 'ltr') === 'rtl';
@endphp
<link rel="apple-touch-icon" sizes="180x180" href="{{ $erpAsset->url('assets/img/favicon/apple-touch-icon.png') }}">
@if ($appPwaSettings['enabled'] && $appPwaSettings['apple_touch_icon_url'])
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $appPwaSettings['apple_touch_icon_url'] }}">
@endif
<link rel="icon" type="image/png" sizes="32x32" href="{{ $appBranding['favicon_url'] }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ $appBranding['favicon_url'] }}">
<link rel="shortcut icon" type="image/x-icon" href="{{ $appBranding['favicon_url'] }}">
@if ($appPwaSettings['enabled'])
    <link rel="manifest" href="{{ route('pwa.manifest') }}">
@endif
<meta name="msapplication-TileImage" content="{{ $appBranding['favicon_url'] }}">
<meta name="theme-color" content="{{ $appPwaSettings['enabled'] ? $appPwaSettings['theme_color'] : '#ffffff' }}">

@include('layouts.partials.falcon-defaults')
<script src="{{ $erpAsset->url('assets/js/modules/Core/navbar-preference.js') }}"></script>

<script>
    localStorage.setItem('isRTL', @json($isRtlLocale));
</script>

<link href="{{ $erpAsset->url('vendors/simplebar/simplebar.min.css') }}" rel="stylesheet">
<link href="{{ $erpAsset->url('vendors/datatables.net-bs5/dataTables.bootstrap5.min.css') }}" rel="stylesheet">
<link href="{{ $erpAsset->url('vendors/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css') }}" rel="stylesheet">
<link href="{{ $erpAsset->url('vendors/datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css') }}" rel="stylesheet">
<link href="{{ $erpAsset->url('vendors/flatpickr/flatpickr.min.css') }}" rel="stylesheet">
<link href="{{ $erpAsset->url('vendors/select2/select2.min.css') }}" rel="stylesheet">
<link href="{{ $erpAsset->url('vendors/select2-bootstrap-5-theme/select2-bootstrap-5-theme.min.css') }}" rel="stylesheet">
<link href="{{ $erpAsset->url($isRtlLocale ? 'assets/css/theme-rtl.min.css' : 'assets/css/theme.min.css') }}" rel="stylesheet" id="{{ $isRtlLocale ? 'style-rtl' : 'style-default' }}">
<link href="{{ $erpAsset->url('assets/css/user.css') }}" rel="stylesheet" id="user-style">
