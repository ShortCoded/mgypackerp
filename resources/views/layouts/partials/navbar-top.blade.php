<nav class="navbar navbar-light navbar-glass navbar-top navbar-expand-lg" style="display:none;">
    <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarStandard" aria-controls="navbarStandard" aria-expanded="false" aria-label="{{ __('layout.toggle_navigation') }}">
        <span class="navbar-toggle-icon"><span class="toggle-line"></span></span>
    </button>
    @include('layouts.partials.brand', ['class' => 'me-1 me-sm-3'])
    <div class="collapse navbar-collapse scrollbar" id="navbarStandard">
        <ul class="navbar-nav" data-erp-top-navigation>
            @include('layouts.partials.menu.top-items', ['items' => $appMenuItems ?? app(\Modules\Core\Services\MenuService::class)->getMenu()])
        </ul>
    </div>
    @include('layouts.partials.topbar', ['topbarClass' => 'ms-auto'])
</nav>
