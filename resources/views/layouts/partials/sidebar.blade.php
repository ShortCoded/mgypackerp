@php
    $menuItems = $menuItems ?? $appMenuItems ?? app(\Modules\Core\Services\MenuService::class)->getMenu();
@endphp

<ul class="navbar-nav flex-column mb-3" id="{{ $menuId ?? 'navbarVerticalNav' }}">
    @include('layouts.partials.menu.vertical-items', ['items' => $menuItems, 'menuPath' => [], 'menuIdPrefix' => $menuIdPrefix ?? 'vertical-menu'])
</ul>
