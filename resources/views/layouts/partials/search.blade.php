<ul class="navbar-nav align-items-center d-none d-md-block">
    <li class="nav-item">
        <div class="search-box" data-navigation-search>
            <form class="position-relative" data-bs-toggle="search" data-bs-display="static">
                <x-forms.input class="form-control search-input" id="navbar_search_input" type="search" placeholder="{{ __('navigation_search.search_pages') }}" aria-label="{{ __('navigation_search.search_pages') }}" title="{{ __('common.shortcuts.global_search') }}" data-bs-title="{{ __('common.shortcuts.global_search') }}" autocomplete="off" aria-expanded="false" aria-controls="navbar_navigation_search_results" />
                <span class="fas fa-search search-box-icon"></span>
            </form>
            <div class="btn-close-falcon-container position-absolute end-0 top-50 translate-middle shadow-none" data-bs-dismiss="search">
                <button class="btn btn-link btn-close-falcon p-0" aria-label="{{ __('layout.close') }}" type="button"></button>
            </div>
            <div class="dropdown-menu border font-base start-0 mt-2 py-0 overflow-hidden w-100">
                <div class="scrollbar py-2" id="navbar_navigation_search_results" style="max-height: 24rem;" data-navigation-search-results>
                    <div class="px-x1 py-3 text-center" data-navigation-search-empty>
                        <span class="fas fa-search text-400 fs-6 mb-2"></span>
                        <p class="mb-1 text-700 fw-semibold fs-10">{{ __('navigation_search.start_typing') }}</p>
                        <p class="mb-0 text-600 fs-11">{{ __('navigation_search.keyboard_hint') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </li>
</ul>
