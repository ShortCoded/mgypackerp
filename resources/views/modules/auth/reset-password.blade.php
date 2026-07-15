@extends('layouts.auth')

@section('title', __('auth.reset_password_page_title'))

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
                                <a class="font-sans-serif fw-bolder fs-5 z-1 position-relative link-light" href="{{ route('login') }}" data-bs-theme="light">
                                    {{-- <img class="me-2" src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }}" height="28"> --}}
                                    {{ $branding['name'] }}
                                </a>
                            </div>

                            <div class="p-4 card-body">
                                <div class="mb-3 row flex-between-center">
                                    <div class="col-auto">
                                        <h3>{{ __('auth.reset_password') }}</h3>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <p class="mb-0 text-700">{{ __('auth.reset_password_subtitle') }}</p>
                                </div>

                                <div class="alert alert-danger alert-dismissible fade show d-none js-auth-alert" role="alert">
                                    <span class="js-auth-alert-message"></span>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                                </div>

                                <form method="POST" action="{{ route('password.store') }}" class="js-auth-form" novalidate>
                                    @csrf

                                    <input type="hidden" name="token" value="{{ $request->route('token') }}">

                                    <div class="mb-3">
                                        <label class="form-label" for="email">
                                            {{ __('auth.email') }}
                                            <span class="text-danger ms-1" aria-hidden="true">*</span>
                                            <span class="visually-hidden">{{ __('common.required') }}</span>
                                        </label>
                                        <input id="email" name="email" type="email" class="form-control" value="{{ old('email', $request->email) }}" placeholder="{{ __('auth.email_placeholder') }}" autocomplete="username" autofocus required aria-required="true">
                                        <div class="invalid-feedback" data-error-for="email"></div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label" for="password">
                                            {{ __('auth.password') }}
                                            <span class="text-danger ms-1" aria-hidden="true">*</span>
                                            <span class="visually-hidden">{{ __('common.required') }}</span>
                                        </label>
                                        <input id="password" name="password" type="password" class="form-control" placeholder="{{ __('auth.new_password_placeholder') }}" autocomplete="new-password" required aria-required="true">
                                        <div class="invalid-feedback" data-error-for="password"></div>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label" for="password_confirmation">
                                            {{ __('auth.password_confirmation') }}
                                            <span class="text-danger ms-1" aria-hidden="true">*</span>
                                            <span class="visually-hidden">{{ __('common.required') }}</span>
                                        </label>
                                        <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" placeholder="{{ __('auth.password_confirmation_placeholder') }}" autocomplete="new-password" required aria-required="true">
                                        <div class="invalid-feedback" data-error-for="password_confirmation"></div>
                                    </div>

                                    <div class="mb-3">
                                        <button class="mt-3 btn btn-primary d-block w-100" type="submit" name="submit">
                                            {{ __('auth.buttons.reset_password') }}
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
