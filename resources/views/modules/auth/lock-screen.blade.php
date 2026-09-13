@extends('layouts.auth')

@section('title', __('auth.lock_screen.title'))

@section('content')
    @php
        $branding = app(\Modules\Core\Services\BrandingService::class)->current();
        $user = auth()->user();
        $name = $user?->name ?: __('layout.user');
        $email = $user?->email;
        $initials = collect(explode(' ', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    @endphp

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
                <div class="bg-holder" style="background-image:url({{ asset('assets/img/generic/18.jpg') }});"></div>
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
                                <div class="row justify-content-center">
                                    <div class="col-auto w-100">
                                        <div class="text-center d-md-flex align-items-center text-md-start">
                                            <div class="mx-auto mb-3 avatar avatar-4xl me-md-4 mb-md-0 mx-md-0">
                                                @if ($user?->avatar)
                                                    <img class="rounded-circle" src="{{ asset($user->avatar) }}" alt="{{ $name }}">
                                                @else
                                                    <div class="avatar-name rounded-circle bg-primary-subtle text-primary">
                                                        <span>{{ $initials ?: 'U' }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="flex-1">
                                                <h4>{{ __('auth.lock_screen.greeting', ['name' => $name]) }}</h4>
                                                @if ($email)
                                                    <p class="mb-1 text-600">{{ $email }}</p>
                                                @endif
                                                <p class="mb-0">{{ __('auth.lock_screen.instructions') }}</p>
                                            </div>
                                        </div>

                                        <div class="mt-4 alert alert-danger alert-dismissible fade show d-none js-auth-alert" role="alert">
                                            <span class="js-auth-alert-message"></span>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                                        </div>

                                        <form class="mt-4 js-lock-screen-form" method="POST" action="{{ route('lock-screen.unlock') }}" novalidate>
                                            @csrf
                                            <label class="form-label" for="lock_screen_password">
                                                {{ __('auth.password') }}
                                                <span class="text-danger ms-1" aria-hidden="true">*</span>
                                                <span class="visually-hidden">{{ __('common.required') }}</span>
                                            </label>
                                            <div class="row gx-2">
                                                <div class="col">
                                                    <x-forms.input class="form-control" id="lock_screen_password" name="password" type="password" placeholder="{{ __('auth.password_placeholder') }}" autocomplete="current-password" required aria-required="true" />
                                                    <div class="invalid-feedback" data-error-for="password"></div>
                                                </div>
                                                <div class="col-4">
                                                    <button class="btn btn-primary d-block w-100" type="submit">{{ __('auth.lock_screen.unlock') }}</button>
                                                </div>
                                            </div>
                                        </form>

                                        <form class="mt-3 text-center" method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button class="p-0 btn btn-link fs-10" type="submit">{{ __('auth.lock_screen.sign_in_as_another_user') }}</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/modules/Auth/lock-screen.js') }}"></script>
@endpush
