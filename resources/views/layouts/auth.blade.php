<!DOCTYPE html>
<html data-bs-theme="light" lang="{{ app()->getLocale() }}"
    dir="{{ config('languages.available.' . app()->getLocale() . '.dir', 'ltr') }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', $authBranding['name'])</title>

    <link rel="icon" type="image/png" href="{{ $authBranding['favicon_url'] }}" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/img/favicon/favicon.svg') }}" />
    <link rel="shortcut icon" href="{{ $authBranding['favicon_url'] }}" />
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $authPwaSettings['enabled'] && $authPwaSettings['apple_touch_icon_url'] ? $authPwaSettings['apple_touch_icon_url'] : asset('assets/img/favicon/apple-touch-icon.png') }}" />
    <meta name="apple-mobile-web-app-title" content="{{ $authBranding['name'] }}" />
    @if ($authPwaSettings['enabled'])
        <link rel="manifest" href="{{ route('pwa.manifest') }}" />
    @endif
    <meta name="msapplication-TileImage" content="{{ asset('assets/img/favicon/web-app-manifest-192x192.png') }}">
    <meta name="theme-color" content="{{ $authPwaSettings['enabled'] ? $authPwaSettings['theme_color'] : '#ffffff' }}">

    @php
        $isRtlLocale = config('languages.available.' . app()->getLocale() . '.dir', 'ltr') === 'rtl';
    @endphp
    <script>
        localStorage.setItem('isRTL', @json($isRtlLocale));
    </script>
    @include('layouts.partials.falcon-defaults')
    <script src="{{ asset('vendors/simplebar/simplebar.min.js') }}"></script>
    <link href="{{ asset('vendors/simplebar/simplebar.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendors/select2/select2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendors/select2-bootstrap-5-theme/select2-bootstrap-5-theme.min.css') }}" rel="stylesheet">
    <link href="{{ asset($isRtlLocale ? 'assets/css/theme-rtl.min.css' : 'assets/css/theme.min.css') }}" rel="stylesheet" id="{{ $isRtlLocale ? 'style-rtl' : 'style-default' }}">
    <link href="{{ asset('assets/css/user.css') }}" rel="stylesheet" id="user-style">
</head>

<body>
    <main class="main" id="top">
        @yield('content')
    </main>

    <div class="border-0 offcanvas offcanvas-end settings-panel" id="settings-offcanvas" tabindex="-1"
        aria-labelledby="settings-offcanvas">
        <div class="offcanvas-header settings-panel-header justify-content-between bg-shape">
            <div class="py-1 z-1">
                <div class="mb-1 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-white me-2">
                        <span class="fas fa-palette me-2 fs-9"></span>{{ __('auth.customizer.settings') }}
                    </h5>
                    <button class="mt-0 mb-0 btn btn-primary btn-sm rounded-pill" data-theme-control="reset"
                        style="font-size:12px">
                        <span class="fas fa-redo-alt me-1" data-fa-transform="shrink-3"></span>{{ __('auth.customizer.reset') }}
                    </button>
                </div>
                <p class="mb-0 text-white opacity-75 fs-10">{{ __('auth.customizer.subtitle') }}</p>
            </div>
            <div class="z-1" data-bs-theme="dark">
                <button class="mt-0 btn-close z-1" type="button" data-bs-dismiss="offcanvas"
                    aria-label="{{ __('auth.alerts.close') }}"></button>
            </div>
        </div>
        <div class="offcanvas-body scrollbar-overlay px-x1 h-100" id="themeController">
            <h5 class="fs-9">{{ __('auth.customizer.color_scheme') }}</h5>
            <p class="fs-10">{{ __('auth.customizer.color_scheme_help') }}</p>
            <div class="btn-group d-block w-100 btn-group-navbar-style">
                <div class="row gx-2">
                    <div class="col-4">
                        <input class="btn-check" id="themeSwitcherLight" name="theme-color" type="radio"
                            value="light" data-theme-control="theme">
                        <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherLight">
                            <span class="label-text">{{ __('auth.customizer.light') }}</span>
                        </label>
                    </div>
                    <div class="col-4">
                        <input class="btn-check" id="themeSwitcherDark" name="theme-color" type="radio"
                            value="dark" data-theme-control="theme">
                        <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherDark">
                            <span class="label-text">{{ __('auth.customizer.dark') }}</span>
                        </label>
                    </div>
                    <div class="col-4">
                        <input class="btn-check" id="themeSwitcherAuto" name="theme-color" type="radio"
                            value="auto" data-theme-control="theme">
                        <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherAuto">
                            <span class="label-text">{{ __('auth.customizer.auto') }}</span>
                        </label>
                    </div>
                </div>
            </div>
            <hr>
            <div class="d-flex align-items-start">
                <img class="me-2" src="{{ asset('assets/img/icons/left-arrow-from-left.svg') }}" width="20"
                    alt="">
                <div class="flex-1">
                    <h5 class="fs-9">{{ __('auth.customizer.language') }}</h5>
                    <p class="mb-2 fs-10">{{ __('auth.customizer.language_help') }}</p>
                    <select class="form-select form-select-sm js-auth-language-select"
                        aria-label="{{ __('auth.customizer.language') }}"
                        data-language-switch-url="{{ route('lang.switch', ['locale' => '__LOCALE__']) }}">
                        @foreach (config('languages.available', []) as $locale => $language)
                            <option value="{{ $locale }}" data-dir="{{ $language['dir'] ?? 'ltr' }}"
                                @selected(app()->getLocale() === $locale)>
                                {{ $language['native'] ?? $language['name'] ?? strtoupper($locale) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <hr>
        </div>
    </div>

    <a class="card setting-toggle" href="#settings-offcanvas" data-bs-toggle="offcanvas">
        <div class="px-2 py-1 card-body d-flex align-items-center py-md-2">
            <div class="bg-primary-subtle position-relative rounded-start" style="height:34px;width:28px">
                <div class="settings-popover">
                    <span class="ripple">
                        <span class="fa-spin position-absolute all-0 d-flex flex-center">
                            <span class="icon-spin position-absolute all-0 d-flex flex-center">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M19.7369 12.3941L19.1989 12.1065C18.4459 11.7041 18.0843 10.8487 18.0843 9.99495C18.0843 9.14118 18.4459 8.28582 19.1989 7.88336L19.7369 7.59581C19.9474 7.47484 20.0316 7.23291 19.9474 7.03131C19.4842 5.57973 18.6843 4.28943 17.6738 3.20075C17.5053 3.03946 17.2527 2.99914 17.0422 3.12011L16.393 3.46714C15.6883 3.84379 14.8377 3.74529 14.1476 3.3427C14.0988 3.31422 14.0496 3.28621 14.0002 3.25868C13.2568 2.84453 12.7055 2.10629 12.7055 1.25525V0.70081C12.7055 0.499202 12.5371 0.297594 12.2845 0.257272C10.7266 -0.105622 9.16879 -0.0653007 7.69516 0.257272C7.44254 0.297594 7.31623 0.499202 7.31623 0.70081V1.23474C7.31623 2.09575 6.74999 2.8362 5.99824 3.25599C5.95774 3.27861 5.91747 3.30159 5.87744 3.32493C5.15643 3.74527 4.26453 3.85902 3.53534 3.45302L2.93743 3.12011C2.72691 2.99914 2.47429 3.03946 2.30587 3.20075C1.29538 4.28943 0.495411 5.57973 0.0322686 7.03131C-0.051939 7.23291 0.0322686 7.47484 0.242788 7.59581L0.784376 7.8853C1.54166 8.29007 1.92694 9.13627 1.92694 9.99495C1.92694 10.8536 1.54166 11.6998 0.784375 12.1046L0.242788 12.3941C0.0322686 12.515 -0.051939 12.757 0.0322686 12.9586C0.495411 14.4102 1.29538 15.7005 2.30587 16.7891C2.47429 16.9504 2.72691 16.9907 2.93743 16.8698L3.58669 16.5227C4.29133 16.1461 5.14131 16.2457 5.8331 16.6455C5.88713 16.6767 5.94159 16.7074 5.99648 16.7375C6.75162 17.1511 7.31623 17.8941 7.31623 18.7552V19.2891C7.31623 19.4425 7.41373 19.5959 7.55309 19.696C7.64066 19.7589 7.74815 19.7843 7.85406 19.8046C9.35884 20.0925 10.8609 20.0456 12.2845 19.7729C12.5371 19.6923 12.7055 19.4907 12.7055 19.2891V18.7346C12.7055 17.8836 13.2568 17.1454 14.0002 16.7312C14.0496 16.7037 14.0988 16.6757 14.1476 16.6472C14.8377 16.2446 15.6883 16.1461 16.393 16.5227L17.0422 16.8698C17.2527 16.9907 17.5053 16.9504 17.6738 16.7891C18.7264 15.7005 19.4842 14.4102 19.9895 12.9586C20.0316 12.757 19.9474 12.515 19.7369 12.3941ZM10.0109 13.2005C8.1162 13.2005 6.64257 11.7893 6.64257 9.97478C6.64257 8.20063 8.1162 6.74905 10.0109 6.74905C11.8634 6.74905 13.3792 8.20063 13.3792 9.97478C13.3792 11.7893 11.8634 13.2005 10.0109 13.2005Z"
                                        fill="#2A7BE4"></path>
                                </svg>
                            </span>
                        </span>
                    </span>
                </div>
            </div>
            <small class="py-2 text-uppercase text-primary fw-bold bg-primary-subtle pe-2 ps-1 rounded-end">
                {{ __('auth.customizer.customize') }}
            </small>
        </div>
    </a>

    @php
        $authMessages = [
            'fallbackError' => __('auth.ajax.unexpected_error'),
            'validationSummary' => __('auth.login.validation_summary'),
            'tooManyAttempts' => __('auth.ajax.too_many_attempts'),
            'forbidden' => __('auth.ajax.forbidden'),
            'unauthenticated' => __('auth.ajax.unauthenticated'),
            'sessionExpiredTryAgain' => __('auth.ajax.session_expired_try_again'),
            'locked' => __('auth.lock_screen.locked'),
            'close' => __('auth.alerts.close'),
        ];
    @endphp
    <script>
        window.authMessages = @json($authMessages);
        window.authCsrf = {
            refreshUrl: @json(route('auth.csrf-token'))
        };
    </script>
    <script src="{{ asset('vendors/jquery/jquery.min.js') }}"></script>
    <script src="{{ asset('vendors/popper/popper.min.js') }}"></script>
    <script src="{{ asset('vendors/bootstrap/bootstrap.min.js') }}"></script>
    <script src="{{ asset('vendors/anchorjs/anchor.min.js') }}"></script>
    <script src="{{ asset('vendors/is/is.min.js') }}"></script>
    <script defer src="{{ asset('vendors/fontawesome/all.min.js') }}"></script>
    <script src="{{ asset('vendors/lodash/lodash.min.js') }}"></script>
    <script src="{{ asset('vendors/list.js/list.min.js') }}"></script>
    <script src="{{ asset('vendors/select2/select2.full.min.js') }}"></script>
    <script src="{{ asset('assets/js/theme.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/client-context.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/helpers.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/ajax.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/page-cache-guard.js') }}"></script>
    @stack('scripts')
</body>

</html>
