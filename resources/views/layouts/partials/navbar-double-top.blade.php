<nav class="navbar navbar-light navbar-glass navbar-top navbar-expand-lg" data-double-top-nav="data-double-top-nav">
    <div class="w-100">
        <div class="d-flex flex-between-center">
            <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarDoubleTop" aria-controls="navbarDoubleTop" aria-expanded="false" aria-label="{{ __('layout.toggle_navigation') }}">
                <span class="navbar-toggle-icon"><span class="toggle-line"></span></span>
            </button>
            @include('layouts.partials.brand', ['class' => 'me-1 me-sm-3'])
            @include('layouts.partials.search')
            @include('layouts.partials.topbar', ['topbarClass' => 'ms-auto'])
        </div>
        <hr class="my-2 d-none d-lg-block">
        <div class="collapse navbar-collapse scrollbar py-lg-2" id="navbarDoubleTop">
            <ul class="navbar-nav" data-erp-top-navigation>
                @include('layouts.partials.menu.top-items', ['items' => $appMenuItems ?? app(\Modules\Core\Services\MenuService::class)->getMenu()])
            </ul>
        </div>
    </div>
</nav>
