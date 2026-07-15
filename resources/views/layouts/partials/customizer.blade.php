<div class="border-0 offcanvas offcanvas-end settings-panel" id="settings-offcanvas" tabindex="-1" aria-labelledby="settings-offcanvas">
    <div class="offcanvas-header settings-panel-header justify-content-between bg-shape">
        <div class="py-1 z-1">
            <div class="mb-1 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-white me-2"><span class="fas fa-palette me-2 fs-9"></span>{{ __('layout.settings_panel') }}</h5>
                <button class="mt-0 mb-0 btn btn-primary btn-sm rounded-pill" data-theme-control="reset" style="font-size:12px" type="button">
                    <span class="fas fa-redo-alt me-1" data-fa-transform="shrink-3"></span>{{ __('layout.reset') }}
                </button>
            </div>
            <p class="mb-0 text-white opacity-75 fs-10">{{ __('layout.customizer_subtitle') }}</p>
        </div>
        <div class="z-1" data-bs-theme="dark">
            <button class="mt-0 btn-close z-1" type="button" data-bs-dismiss="offcanvas" aria-label="{{ __('layout.close') }}"></button>
        </div>
    </div>
    <div class="offcanvas-body scrollbar-overlay px-x1 h-100" id="themeController">
        <h5 class="fs-9">{{ __('layout.color_scheme') }}</h5>
        <p class="fs-10">{{ __('layout.color_scheme_help') }}</p>
        <div class="btn-group d-block w-100 btn-group-navbar-style">
            <div class="row gx-2">
                <div class="col-4">
                    <input class="btn-check" id="themeSwitcherLight" name="theme-color" type="radio" value="light" data-theme-control="theme">
                    <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherLight">
                        <span class="label-text">{{ __('layout.light') }}</span>
                    </label>
                </div>
                <div class="col-4">
                    <input class="btn-check" id="themeSwitcherDark" name="theme-color" type="radio" value="dark" data-theme-control="theme">
                    <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherDark">
                        <span class="label-text">{{ __('layout.dark') }}</span>
                    </label>
                </div>
                <div class="col-4">
                    <input class="btn-check" id="themeSwitcherAuto" name="theme-color" type="radio" value="auto" data-theme-control="theme">
                    <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherAuto">
                        <span class="label-text">{{ __('layout.auto') }}</span>
                    </label>
                </div>
            </div>
        </div>
        <hr>
        <div class="d-flex align-items-start">
            <img class="me-2" src="{{ asset('assets/img/icons/left-arrow-from-left.svg') }}" width="20" alt="">
            <div class="flex-1">
                <h5 class="fs-9">{{ __('layout.language') }}</h5>
                <p class="mb-2 fs-10">{{ __('layout.language_help') }}</p>
                <select class="form-select form-select-sm js-app-language-select"
                    aria-label="{{ __('layout.language') }}"
                    data-language-switch-url="{{ route('lang.switch', ['locale' => '__LOCALE__']) }}">
                    @foreach (config('languages.available', []) as $locale => $language)
                        <option value="{{ $locale }}" data-dir="{{ $language['dir'] ?? 'ltr' }}" @selected(app()->getLocale() === $locale)>
                            {{ $language['native'] ?? $language['name'] ?? strtoupper($locale) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <hr>
        <div class="d-flex justify-content-between">
            <div class="d-flex align-items-start">
                <img class="me-2" src="{{ asset('assets/img/icons/arrows-h.svg') }}" width="20" alt="">
                <div class="flex-1">
                    <h5 class="fs-9">{{ __('layout.fluid_layout') }}</h5>
                    <p class="mb-0 fs-10">{{ __('layout.fluid_layout_help') }}</p>
                </div>
            </div>
            <div class="form-check form-switch"><input class="form-check-input ms-0" id="mode-fluid" type="checkbox" data-theme-control="isFluid"></div>
        </div>
        <hr>
        <div class="d-flex align-items-start">
            <img class="me-2" src="{{ asset('assets/img/icons/paragraph.svg') }}" width="20" alt="">
            <div class="flex-1">
                <h5 class="fs-9 d-flex align-items-center">{{ __('layout.navigation_position') }}</h5>
                <p class="mb-2 fs-10">{{ __('layout.navigation_position_help') }}</p>
                <select class="form-select form-select-sm" aria-label="{{ __('layout.navigation_position') }}" data-theme-control="navbarPosition">
                    <option value="vertical">{{ __('layout.vertical') }}</option>
                    <option value="top">{{ __('layout.top') }}</option>
                    <option value="combo">{{ __('layout.combo') }}</option>
                    <option value="double-top">{{ __('layout.double_top') }}</option>
                </select>
            </div>
        </div>
        <hr>
        <h5 class="fs-9 d-flex align-items-center">{{ __('layout.vertical_navbar_style') }}</h5>
        <p class="mb-3 fs-10">{{ __('layout.vertical_navbar_style_help') }}</p>
        <div class="btn-group d-block w-100 btn-group-navbar-style">
            <div class="row gx-2">
                <div class="col-6"><input class="btn-check" id="navbar-style-transparent" type="radio" name="navbarStyle" value="transparent" data-theme-control="navbarStyle"><label class="btn d-block w-100 btn-navbar-style fs-10" for="navbar-style-transparent"><img class="img-fluid img-prototype" src="{{ asset('assets/img/generic/default.png') }}" alt=""><span class="label-text">{{ __('layout.transparent') }}</span></label></div>
                <div class="col-6"><input class="btn-check" id="navbar-style-inverted" type="radio" name="navbarStyle" value="inverted" data-theme-control="navbarStyle"><label class="btn d-block w-100 btn-navbar-style fs-10" for="navbar-style-inverted"><img class="img-fluid img-prototype" src="{{ asset('assets/img/generic/inverted.png') }}" alt=""><span class="label-text">{{ __('layout.inverted') }}</span></label></div>
                <div class="col-6"><input class="btn-check" id="navbar-style-card" type="radio" name="navbarStyle" value="card" data-theme-control="navbarStyle"><label class="btn d-block w-100 btn-navbar-style fs-10" for="navbar-style-card"><img class="img-fluid img-prototype" src="{{ asset('assets/img/generic/card.png') }}" alt=""><span class="label-text">{{ __('layout.card') }}</span></label></div>
                <div class="col-6"><input class="btn-check" id="navbar-style-vibrant" type="radio" name="navbarStyle" value="vibrant" data-theme-control="navbarStyle"><label class="btn d-block w-100 btn-navbar-style fs-10" for="navbar-style-vibrant"><img class="img-fluid img-prototype" src="{{ asset('assets/img/generic/vibrant.png') }}" alt=""><span class="label-text">{{ __('layout.vibrant') }}</span></label></div>
            </div>
        </div>
    </div>
</div>

<a class="card setting-toggle" href="#settings-offcanvas" data-bs-toggle="offcanvas">
    <div class="px-2 py-1 card-body d-flex align-items-center py-md-2">
        <div class="bg-primary-subtle position-relative rounded-start" style="height:34px;width:28px">
            <div class="settings-popover">
                <span class="ripple"><span class="fa-spin position-absolute all-0 d-flex flex-center"><span class="icon-spin position-absolute all-0 d-flex flex-center"><span class="fas fa-cog text-primary"></span></span></span></span>
            </div>
        </div>
        <small class="py-2 text-uppercase text-primary fw-bold bg-primary-subtle pe-2 ps-1 rounded-end">{{ __('layout.customize') }}</small>
    </div>
</a>
