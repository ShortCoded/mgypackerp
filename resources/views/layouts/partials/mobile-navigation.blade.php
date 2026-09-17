<aside class="offcanvas offcanvas-start erp-mobile-navigation" id="erpMobileNavigation" tabindex="-1" aria-labelledby="erpMobileNavigationLabel" data-erp-mobile-navigation>
    <div class="offcanvas-header border-bottom">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <img class="erp-mobile-navigation-logo" src="{{ $appBranding['logo_url'] }}" alt="">
            <div class="min-w-0">
                <h5 class="offcanvas-title text-truncate mb-0" id="erpMobileNavigationLabel">{{ $appBranding['name'] }}</h5>
                <div class="small text-600">{{ __('layout.main_navigation') }}</div>
            </div>
        </div>
        <button class="btn-close" type="button" data-bs-dismiss="offcanvas" aria-label="{{ __('layout.close') }}"></button>
    </div>

    <div class="offcanvas-body p-0">
        <div class="erp-mobile-navigation-tools border-bottom p-3">
            <button class="btn btn-falcon-default w-100 d-flex align-items-center gap-2" type="button" data-navigation-search-open>
                <span class="fas fa-search" aria-hidden="true"></span>
                <span>{{ __('navigation_search.search_pages') }}</span>
            </button>

            <button class="btn btn-falcon-default w-100 d-flex align-items-center gap-2" type="button" data-erp-open-settings>
                <span class="fas fa-sliders-h" aria-hidden="true"></span>
                <span>{{ __('layout.settings_panel') }}</span>
            </button>

            <div>
                <label class="form-label small fw-semibold" for="erp-mobile-language">{{ __('layout.language') }}</label>
                <x-forms.select class="form-select form-select-sm js-app-language-select" id="erp-mobile-language" aria-label="{{ __('layout.language') }}" data-language-switch-url="{{ route('lang.switch', ['locale' => '__LOCALE__']) }}">
                    @foreach (config('languages.available', []) as $locale => $language)
                        <option value="{{ $locale }}" data-dir="{{ $language['dir'] ?? 'ltr' }}" @selected(app()->getLocale() === $locale)>
                            {{ $language['native'] ?? $language['name'] ?? strtoupper($locale) }}
                        </option>
                    @endforeach
                </x-forms.select>
            </div>

            <fieldset>
                <legend class="form-label small fw-semibold mb-2">{{ __('layout.color_scheme') }}</legend>
                <div class="btn-group w-100" role="group" aria-label="{{ __('layout.color_scheme') }}">
                    <button class="btn btn-falcon-default" type="button" value="light" data-theme-control="theme"><span class="fas fa-sun me-1" aria-hidden="true"></span>{{ __('layout.light') }}</button>
                    <button class="btn btn-falcon-default" type="button" value="dark" data-theme-control="theme"><span class="fas fa-moon me-1" aria-hidden="true"></span>{{ __('layout.dark') }}</button>
                    <button class="btn btn-falcon-default" type="button" value="auto" data-theme-control="theme"><span class="fas fa-adjust me-1" aria-hidden="true"></span>{{ __('layout.auto') }}</button>
                </div>
            </fieldset>
        </div>

        <nav class="erp-mobile-navigation-menu p-3" aria-label="{{ __('layout.main_navigation') }}">
            @include('layouts.partials.sidebar', ['menuItems' => $appMenuItems ?? app(\Modules\Core\Services\MenuService::class)->getMenu(), 'menuId' => 'erpMobileNavigationMenu', 'menuIdPrefix' => 'mobile-menu'])
        </nav>
    </div>
</aside>
