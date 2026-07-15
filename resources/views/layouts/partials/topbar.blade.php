@if (isset($topbarClass))
    <ul class="navbar-nav navbar-nav-icons {{ $topbarClass }} flex-row align-items-center">
        @include('layouts.partials.operating-context-indicator')
        <li class="nav-item ps-2 pe-0">
            <div class="dropdown theme-control-dropdown">
                <a class="nav-link d-flex align-items-center dropdown-toggle fa-icon-wait fs-9 pe-1 py-0" href="#" role="button" id="themeSwitchDropdownInline" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fas fa-sun fs-7" data-fa-transform="shrink-2" data-theme-dropdown-toggle-icon="light"></span>
                    <span class="fas fa-moon fs-7" data-fa-transform="shrink-3" data-theme-dropdown-toggle-icon="dark"></span>
                    <span class="fas fa-adjust fs-7" data-fa-transform="shrink-2" data-theme-dropdown-toggle-icon="auto"></span>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-caret border py-0 mt-3" aria-labelledby="themeSwitchDropdownInline">
                    <div class="bg-white dark__bg-1000 rounded-2 py-2">
                        <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="light" data-theme-control="theme"><span class="fas fa-sun"></span>{{ __('layout.light') }}<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                        <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="dark" data-theme-control="theme"><span class="fas fa-moon"></span>{{ __('layout.dark') }}<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                        <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="auto" data-theme-control="theme"><span class="fas fa-adjust"></span>{{ __('layout.auto') }}<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                    </div>
                </div>
            </div>
        </li>
        @include('layouts.partials.notifications')
        @include('layouts.partials.user-menu')
    </ul>
@else
    <nav class="navbar navbar-light navbar-glass navbar-top navbar-expand" style="display:none;">
        <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarVerticalCollapse" aria-controls="navbarVerticalCollapse" aria-expanded="false" aria-label="{{ __('layout.toggle_navigation') }}">
            <span class="navbar-toggle-icon"><span class="toggle-line"></span></span>
        </button>
        @include('layouts.partials.brand', ['class' => 'me-1 me-sm-3'])

        @include('layouts.partials.search')

        <ul class="navbar-nav navbar-nav-icons ms-auto flex-row align-items-center">
            @include('layouts.partials.operating-context-indicator')
            <li class="nav-item ps-2 pe-0">
                <div class="dropdown theme-control-dropdown">
                    <a class="nav-link d-flex align-items-center dropdown-toggle fa-icon-wait fs-9 pe-1 py-0" href="#" role="button" id="themeSwitchDropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fas fa-sun fs-7" data-fa-transform="shrink-2" data-theme-dropdown-toggle-icon="light"></span>
                        <span class="fas fa-moon fs-7" data-fa-transform="shrink-3" data-theme-dropdown-toggle-icon="dark"></span>
                        <span class="fas fa-adjust fs-7" data-fa-transform="shrink-2" data-theme-dropdown-toggle-icon="auto"></span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end dropdown-caret border py-0 mt-3" aria-labelledby="themeSwitchDropdown">
                        <div class="bg-white dark__bg-1000 rounded-2 py-2">
                            <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="light" data-theme-control="theme"><span class="fas fa-sun"></span>{{ __('layout.light') }}<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                            <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="dark" data-theme-control="theme"><span class="fas fa-moon"></span>{{ __('layout.dark') }}<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                            <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="auto" data-theme-control="theme"><span class="fas fa-adjust"></span>{{ __('layout.auto') }}<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                        </div>
                    </div>
                </div>
            </li>
            @include('layouts.partials.notifications')
            @include('layouts.partials.user-menu')
        </ul>
    </nav>
@endif
