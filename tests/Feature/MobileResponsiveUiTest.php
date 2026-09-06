<?php

test('the shared mobile stylesheet preserves readable and touch friendly controls', function () {
    $css = file_get_contents(public_path('assets/css/user.css'));

    expect($css)
        ->toContain('-webkit-text-size-adjust: 100%')
        ->toContain('.dashboard-kpi-category')
        ->toContain('.btn-group > .dropdown-toggle-split')
        ->toContain('.navbar-nav-icons .theme-control-dropdown > .nav-link')
        ->toContain('.nav-tabs')
        ->toContain('scroll-snap-type: inline proximity')
        ->toContain('.erp-datatable-card th.dt-select')
        ->toContain('touch-action: pan-x pan-y')
        ->toContain('.card-header > .row.flex-between-center > .col')
        ->toContain('@media screen and (max-width: 389.98px)')
        ->toContain('.navbar-top .erp-theme-switch-item')
        ->toContain('@media screen and (max-width: 339.98px)');
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
    $purchaseRequisition = file_get_contents(resource_path('views/modules/purchases/procurement/requisition-form.blade.php'));
    $userTasks = file_get_contents(resource_path('views/modules/core/user-tasks/index.blade.php'));
    $dataTables = file_get_contents(public_path('assets/js/modules/Core/datatables-defaults.js'));
    $layout = file_get_contents(public_path('assets/js/modules/Core/layout.js'));

    expect($tableCard)
        ->toContain('class="erp-datatable-scroll"')
        ->toContain('role="region"')
        ->toContain('tabindex="0"')
        ->and(substr_count($topbar, 'erp-theme-switch-item'))->toBe(2)
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
