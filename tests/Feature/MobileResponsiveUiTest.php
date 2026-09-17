<?php

test('the shared mobile stylesheet preserves readable and touch friendly controls', function () {
    $css = file_get_contents(public_path('assets/css/user.css'));

    expect($css)
        ->toContain('-webkit-text-size-adjust: 100%')
        ->toContain('.dashboard-kpi-category')
        ->toContain('.btn-group > .dropdown-toggle-split')
        ->toContain('.navbar-nav-icons .theme-control-dropdown > .nav-link')
        ->toContain('.erp-pwa-navigation-shell[hidden]')
        ->toContain('.erp-pwa-navigation-button')
        ->toContain('.erp-pwa-standalone:not(.erp-virtual-keyboard-open) .content')
        ->toContain('.erp-mobile-header-tools')
        ->toContain('.erp-mobile-navigation')
        ->toContain('@media screen and (max-width: 1199.98px)')
        ->toContain('@media screen and (max-width: 575.98px)')
        ->toContain('.nav-tabs')
        ->toContain('scroll-snap-type: inline proximity')
        ->toContain('.erp-datatable-card th.dt-select')
        ->toContain('touch-action: pan-x pan-y')
        ->toContain('.card-header > .row.flex-between-center > .col')
        ->toContain('.erp-header-actions .erp-user-menu > .nav-link')
        ->toContain('.erp-header-actions .erp-notifications-menu > .nav-link')
        ->toContain('.notification-indicator:not(.has-unread)::before')
        ->toContain('.dropdown-menu-notification.dropdown-caret::after')
        ->toContain('inset-inline-end: 4.25rem')
        ->toContain('--swal2-background: var(--falcon-emphasis-bg)')
        ->not->toContain('@media screen and (max-width: 389.98px)');
});

test('line item cards keep mobile actions large and inside the card flow', function () {
    $css = file_get_contents(public_path('assets/css/line-item-cards.css'));

    expect($css)
        ->toContain('@media (max-width: 1023.98px)')
        ->toContain('@media (max-width: 575.98px)')
        ->toContain('.line-card-repeater .line-card-actions')
        ->toContain('position: static')
        ->toContain('min-height: 2.75rem')
        ->toContain('min-width: 2.75rem');
});

test('shared tables and topbar expose mobile accessibility hooks', function () {
    $tableCard = file_get_contents(resource_path('views/components/admin/report/table-card.blade.php'));
    $topbar = file_get_contents(resource_path('views/layouts/partials/topbar.blade.php'));
    $appLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $notifications = file_get_contents(resource_path('views/layouts/partials/notifications.blade.php'));
    $mobileNavigation = file_get_contents(resource_path('views/layouts/partials/mobile-navigation.blade.php'));
    $pwaNavigation = file_get_contents(resource_path('views/layouts/partials/pwa-navigation.blade.php'));
    $purchaseRequisition = file_get_contents(resource_path('views/modules/purchases/procurement/requisition-form.blade.php'));
    $userTasks = file_get_contents(resource_path('views/modules/core/user-tasks/index.blade.php'));
    $dataTables = file_get_contents(public_path('assets/js/modules/Core/datatables-defaults.js'));
    $layout = file_get_contents(public_path('assets/js/modules/Core/layout.js'));

    expect($tableCard)
        ->toContain('class="erp-datatable-scroll"')
        ->toContain('role="region"')
        ->toContain('tabindex="0"')
        ->and(substr_count($topbar, 'erp-theme-switch-item'))->toBe(2)
        ->and($topbar)->not->toContain("@include('layouts.partials.pwa-navigation')")
        ->and($appLayout)->toContain("@include('layouts.partials.mobile-navigation')")
        ->toContain("@include('layouts.partials.pwa-navigation')")
        ->and($notifications)
        ->not->toContain('data-push-notification-status')
        ->not->toContain('data-notification-sound-status')
        ->not->toContain('data-notifications-health')
        ->and($mobileNavigation)
        ->toContain('data-erp-mobile-navigation')
        ->toContain("@include('layouts.partials.sidebar'")
        ->toContain('data-navigation-search-open')
        ->toContain('data-erp-open-settings')
        ->and($pwaNavigation)
        ->toContain('data-erp-pwa-navigation hidden')
        ->toContain('role="group" dir="ltr"')
        ->toContain('data-erp-pwa-back')
        ->toContain('data-erp-pwa-forward')
        ->toContain('data-erp-pwa-page-reload')
        ->toContain('aria-label="{{ __(\'pwa.navigation.back\') }}"')
        ->and($purchaseRequisition)
        ->toContain('card-body p-0 table-responsive')
        ->toContain('aria-label="{{ __(\'Requirement lines\') }}"')
        ->and($userTasks)
        ->toContain('class="erp-datatable-scroll"')
        ->toContain('aria-label="{{ __(\'user_tasks.title\') }}"')
        ->and($dataTables)
        ->toContain('click.erpDataTableSelectAll')
        ->toContain('thead th.dt-select')
        ->toContain('checkbox.click()')
        ->and($layout)
        ->toContain("document.addEventListener('shown.bs.tab'")
        ->toContain('tab.scrollIntoView({')
        ->toContain("inline: 'center'");
});
