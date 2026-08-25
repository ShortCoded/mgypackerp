<?php

namespace App\View\Composers;

use Illuminate\Contracts\View\View;
use Modules\Core\Services\BrandingService;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\PwaSettingsService;

class AppLayoutComposer
{
    private const DefaultNavbarPosition = 'double-top';

    private const NavbarPositions = ['vertical', 'top', 'combo', 'double-top'];

    public function __construct(
        private readonly BrandingService $branding,
        private readonly MenuService $menu,
        private readonly OperatingContextService $operatingContext,
        private readonly PwaSettingsService $pwaSettings,
    ) {}

    public function compose(View $view): void
    {
        $view->with([
            'appBranding' => $this->branding->current(),
            'appMenuItems' => $this->menu->getMenu(),
            'appNavbarPosition' => $this->navbarPosition(),
            'appOperatingContext' => auth()->check() ? $this->operatingContext->current(request()) : null,
            'appPwaSettings' => $this->pwaSettings->settings(),
        ]);
    }

    private function navbarPosition(): string
    {
        $position = request()->cookie('erp_navbar_position', self::DefaultNavbarPosition);

        return is_string($position) && in_array($position, self::NavbarPositions, true)
            ? $position
            : self::DefaultNavbarPosition;
    }
}
