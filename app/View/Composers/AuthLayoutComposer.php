<?php

namespace App\View\Composers;

use Illuminate\Contracts\View\View;
use Modules\Core\Services\BrandingService;
use Modules\Core\Services\PwaSettingsService;

class AuthLayoutComposer
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly PwaSettingsService $pwaSettings,
    ) {}

    public function compose(View $view): void
    {
        $view->with([
            'authBranding' => $this->branding->current(),
            'authPwaSettings' => $this->pwaSettings->settings(),
        ]);
    }
}
