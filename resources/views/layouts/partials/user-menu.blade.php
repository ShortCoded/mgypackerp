@php
    $user = auth()->user();
    $name = $user?->name ?: __('layout.user');
    $email = $user?->email;
    $initials = collect(explode(' ', trim($name)))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<li class="nav-item dropdown">
    <a class="nav-link pe-0 ps-2" id="navbarDropdownUser" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <div class="avatar avatar-xl">
            @if ($user?->avatar)
                <img class="rounded-circle" src="{{ asset($user->avatar) }}" alt="{{ $name }}">
            @else
                <div class="avatar-name rounded-circle bg-primary-subtle text-primary"><span>{{ $initials ?: 'U' }}</span></div>
            @endif
        </div>
    </a>
    <div class="py-0 dropdown-menu dropdown-caret dropdown-menu-end" aria-labelledby="navbarDropdownUser">
        <div class="py-2 bg-white dark__bg-1000 rounded-2">
            <div class="px-3 py-2">
                <h6 class="mb-0 text-center">{{ $name }}</h6>
                @if ($email)
                    <p class="mb-0 fs-11 text-600">{{ $email }}</p>
                @endif
            </div>
            <div class="dropdown-divider"></div>
            @if ($user && \Illuminate\Support\Facades\Route::has('profile.show') && $user->can('profile.view'))
                <a class="dropdown-item" href="{{ route('profile.show') }}">{{ __('profile.actions.my_profile') }}</a>
            @endif
            <form method="POST" action="{{ route('lock-screen.store') }}" data-lock-screen-form>
                @csrf
                <x-forms.input type="hidden" name="return_url" value="{{ request()->getRequestUri() }}" />
                <button class="dropdown-item" id="btn_lock_screen" type="submit" title="{{ __('common.shortcuts.lock_screen') }}" data-bs-title="{{ __('common.shortcuts.lock_screen') }}">
                    {{ __('auth.lock_screen.action') }}
                </button>
            </form>
            <div class="dropdown-divider"></div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="dropdown-item" id="btn_logout" type="submit" title="{{ __('common.shortcuts.logout') }}" data-bs-title="{{ __('common.shortcuts.logout') }}">
                    {{ __('layout.logout') }}
                </button>
            </form>
        </div>
    </div>
</li>
