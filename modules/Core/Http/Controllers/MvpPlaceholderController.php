<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\MvpPlaceholderService;

class MvpPlaceholderController extends Controller
{
    public function __construct(
        private readonly MvpPlaceholderService $placeholders,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function __invoke(string $module, string $screen): View
    {
        $placeholder = $this->placeholders->find($module, $screen);

        abort_if($placeholder === null, 404);

        return view('modules.core.mvp.placeholder', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.mvp.placeholder', [], [
                'module' => $module,
                'screen' => $screen,
            ]),
            'moduleLabel' => $placeholder['module_label'],
            'screenLabel' => $placeholder['screen_label'],
            'screenType' => $placeholder['type'],
            'icon' => $placeholder['icon'],
        ]);
    }
}
