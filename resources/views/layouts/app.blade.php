<!DOCTYPE html>
<html data-bs-theme="light" lang="{{ app()->getLocale() }}" dir="{{ config('languages.available.' . app()->getLocale() . '.dir', 'ltr') }}">

<head>
    @php
        $appBranding = app(\Modules\Core\Services\BrandingService::class)->current();
        $appMenuItems = app(\Modules\Core\Services\MenuService::class)->getMenu();
        $appOperatingContext = auth()->check()
            ? app(\Modules\Core\Services\OperatingContextService::class)->current(request())
            : null;
        $appPwaSettings = app(\Modules\Core\Services\PwaSettingsService::class)->settings();
    @endphp
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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

            @include('layouts.partials.navbar-double-top')
            @include('layouts.partials.navbar-vertical')
            @include('layouts.partials.navbar-top')

            <div class="content">
                @include('layouts.partials.topbar')
                @include('layouts.partials.navbar-combo')
                <script>
                    var navbarPosition = localStorage.getItem('navbarPosition');
                    var navbarVertical = document.querySelector('.navbar-vertical');
                    var navbarTopVertical = document.querySelector('.content .navbar-top:not([data-navbar-top="combo"])');
                    var navbarTop = document.querySelector('[data-layout] > .navbar-top:not([data-double-top-nav])');
                    var navbarDoubleTop = document.querySelector('[data-double-top-nav]');
                    var navbarTopCombo = document.querySelector('.content [data-navbar-top="combo"]');

                    function removeNavbar(navbar) {
                        if (navbar) {
                            navbar.remove();
                        }
                    }

                    function showNavbar(navbar) {
                        if (navbar) {
                            navbar.removeAttribute('style');
                        }
                    }

                    if (localStorage.getItem('navbarPosition') === 'double-top') {
                        document.documentElement.classList.toggle('double-top-nav-layout');
                    }

                    if (navbarPosition === 'top') {
                        showNavbar(navbarTop);
                        removeNavbar(navbarTopVertical);
                        removeNavbar(navbarVertical);
                        removeNavbar(navbarTopCombo);
                        removeNavbar(navbarDoubleTop);
                    } else if (navbarPosition === 'combo') {
                        showNavbar(navbarVertical);
                        showNavbar(navbarTopCombo);
                        removeNavbar(navbarTop);
                        removeNavbar(navbarTopVertical);
                        removeNavbar(navbarDoubleTop);
                    } else if (navbarPosition === 'double-top') {
                        showNavbar(navbarDoubleTop);
                        removeNavbar(navbarTopVertical);
                        removeNavbar(navbarVertical);
                        removeNavbar(navbarTop);
                        removeNavbar(navbarTopCombo);
                    } else {
                        showNavbar(navbarVertical);
                        showNavbar(navbarTopVertical);
                        removeNavbar(navbarTop);
                        removeNavbar(navbarDoubleTop);
                        removeNavbar(navbarTopCombo);
                    }
                </script>

                @include('layouts.partials.flash')
                @include('layouts.partials.breadcrumb')

                @yield('content')

                @include('layouts.partials.footer')
            </div>
        </div>
    </main>

    @include('layouts.partials.customizer')
    @auth
        @include('layouts.partials.operating-context-modal')
    @endauth
    @include('layouts.partials.scripts', ['appPwaSettings' => $appPwaSettings])
    @stack('scripts')
</body>

</html>
