<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ExpandedScreenRegistry;

class ExpandedSetupPlaceholderController
{
    public function __construct(
        private readonly ExpandedScreenRegistry $screens,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function __invoke(Request $request): View
    {
        $screenKey = (string) $request->route('expanded_screen', '');
        $screen = $this->screens->find($screenKey);

        abort_unless(is_array($screen) && $screen['status'] === ExpandedScreenRegistry::StatusPlaceholder, 404);

        return view('modules.core.expanded-setup-placeholder', [
            'screen' => $screen,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute((string) $screen['route']),
        ]);
    }
}
