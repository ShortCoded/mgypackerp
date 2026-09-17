<!DOCTYPE html>
<html data-bs-theme="light" data-navbar-position="{{ $appNavbarPosition }}" lang="{{ app()->getLocale() }}" dir="{{ config('languages.available.' . app()->getLocale() . '.dir', 'ltr') }}" @class(['double-top-nav-layout' => $appNavbarPosition === 'double-top'])>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', $appBranding['name'])</title>

    @include('layouts.partials.styles', ['appBranding' => $appBranding, 'appPwaSettings' => $appPwaSettings])
    @stack('styles')
</head>

<body>
    <main class="main" id="top">
        <div class="container" data-layout="container">
            <script>
                var isFluid = JSON.parse(localStorage.getItem('isFluid'));
                if (isFluid) {
                    var container = document.querySelector('[data-layout]');
                    container.classList.remove('container');
                    container.classList.add('container-fluid');
                }
            </script>

            @if ($appNavbarPosition === 'double-top')
                @include('layouts.partials.navbar-double-top')
            @elseif ($appNavbarPosition === 'top')
                @include('layouts.partials.navbar-top')
            @elseif (in_array($appNavbarPosition, ['vertical', 'combo'], true))
                @include('layouts.partials.navbar-vertical')
            @endif

            @if (in_array($appNavbarPosition, ['double-top', 'top'], true))
                @include('layouts.partials.mobile-header-tools')
                @include('layouts.partials.pwa-navigation')
            @endif

            <div class="content">
                @if ($appNavbarPosition === 'vertical')
                    @include('layouts.partials.topbar')
                    @include('layouts.partials.mobile-header-tools')
                    @include('layouts.partials.pwa-navigation')
                @elseif ($appNavbarPosition === 'combo')
                    @include('layouts.partials.navbar-combo')
                    @include('layouts.partials.mobile-header-tools')
                    @include('layouts.partials.pwa-navigation')
                @endif

                @include('layouts.partials.flash')
                @include('layouts.partials.breadcrumb')

                @yield('content')

                @include('layouts.partials.footer')
            </div>
        </div>
    </main>

    @include('layouts.partials.customizer')
    @auth
        @include('layouts.partials.mobile-navigation')
        @include('layouts.partials.operating-context-modal')
    @endauth
    <div class="erp-connectivity-status alert alert-warning shadow-sm" role="status" aria-live="polite" hidden data-erp-connectivity-status data-offline-message="{{ __('pwa.connectivity.offline') }}" data-online-message="{{ __('pwa.connectivity.online') }}">
        <span class="fas fa-wifi" aria-hidden="true"></span>
        <span data-erp-connectivity-message>{{ __('pwa.connectivity.offline') }}</span>
        <button class="btn btn-warning btn-sm" type="button" data-erp-connectivity-retry>{{ __('pwa.connectivity.retry') }}</button>
    </div>
    <div class="erp-pwa-update alert alert-info shadow-sm" role="status" aria-live="polite" hidden data-erp-pwa-update>
        <span>{{ __('pwa.update.available') }}</span>
        <button class="btn btn-info btn-sm ms-2" type="button" data-erp-pwa-reload>{{ __('pwa.update.reload') }}</button>
    </div>
    @include('layouts.partials.scripts', ['appPwaSettings' => $appPwaSettings])
    @stack('scripts')
</body>

</html>
