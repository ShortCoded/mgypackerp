@extends('layouts.auth')

@section('title', __('auth.login.title'))

@section('content')
    @php($branding = app(\Modules\Core\Services\BrandingService::class)->current())
    <div class="container-fluid" data-layout="container">
        <script>
            var isFluid = JSON.parse(localStorage.getItem('isFluid'));
            if (isFluid) {
                var container = document.querySelector('[data-layout]');
                container.classList.remove('container');
                container.classList.add('container-fluid');
            }
        </script>
        <div class="row min-vh-100 bg-100">
            <div class="col-6 d-none d-lg-block position-relative">
                <div class="bg-holder" style="background-image:url({{ asset('assets/img/generic/14.jpg') }});background-position: 50% 20%;"></div>
            </div>

            <div class="py-5 mx-auto col-sm-10 col-md-6 px-sm-0 align-self-center">
                <div class="row justify-content-center g-0">
                    <div class="col-lg-9 col-xl-8 col-xxl-6">
                        <div class="card">
                            <div class="p-2 text-center card-header bg-circle-shape bg-shape">
                                <a class="font-sans-serif fw-bolder fs-5 z-1 position-relative link-light" href="{{ url('/') }}" data-bs-theme="light">
                                    {{-- <img class="me-2" src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }}" height="28"> --}}
                                    {{ $branding['name'] }}
                                </a>
                            </div>

                            <div class="p-4 card-body">
                                <div class="mb-3 row flex-between-center">
                                    <div class="col-auto">
                                        <h3>{{ __('auth.login.title') }}</h3>
                                    </div>
                                </div>

                                @if (session('status'))
                                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                                        {{ session('status') }}
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                                    </div>
                                @endif

                                @if (session('auth_error'))
                                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                        {{ session('auth_error') }}
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                                    </div>
                                @endif

                                @if ($authSessionExpired ?? false)
                                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                        {{ __('auth.session.expired') }}
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                                    </div>
                                @endif

                                <div class="alert alert-danger alert-dismissible fade show d-none js-auth-alert" role="alert">
                                    <span class="js-auth-alert-message"></span>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                                </div>

                                <form method="POST" action="{{ route('login.store') }}" class="js-auth-form" data-client-location="true" novalidate>
                                    @csrf

                                    <div class="mb-3">
                                        <label class="form-label" for="login">
                                            {{ __('auth.login.identifier') }}
                                            <span class="text-danger ms-1" aria-hidden="true">*</span>
                                            <span class="visually-hidden">{{ __('common.required') }}</span>
                                        </label>
                                        <x-forms.input id="login" name="login" type="text" class="form-control" value="{{ old('login') }}" placeholder="{{ __('auth.login_identifier_placeholder') }}" autocomplete="username" autofocus required aria-required="true" />
                                        <div class="invalid-feedback" data-error-for="login"></div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between">
                                            <label class="form-label" for="password">
                                                {{ __('auth.login.password') }}
                                                <span class="text-danger ms-1" aria-hidden="true">*</span>
                                                <span class="visually-hidden">{{ __('common.required') }}</span>
                                            </label>
                                        </div>
                                        <x-forms.input id="password" name="password" type="password" class="form-control" placeholder="{{ __('auth.password_placeholder') }}" autocomplete="current-password" required aria-required="true" />
                                        <div class="invalid-feedback" data-error-for="password"></div>
                                    </div>

                                    <div class="row flex-between-center">
                                        <div class="col-auto">
                                            <div class="mb-0 form-check">
                                                <x-forms.input id="remember" name="remember" value="1" type="checkbox" class="form-check-input" />
                                                <label class="mb-0 form-check-label" for="remember">{{ __('auth.login.remember_me') }}</label>
                                            </div>
                                        </div>

                                        <div class="col-auto">
                                            <a class="fs-10" href="{{ route('password.request') }}">{{ __('auth.login.forgot_password') }}</a>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <button class="mt-3 btn btn-primary d-block w-100" type="submit" name="submit">
                                            {{ __('auth.login.submit') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
