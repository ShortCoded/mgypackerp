<?php

namespace App\Providers;

use App\View\Composers\AppLayoutComposer;
use App\View\Composers\AuthLayoutComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\ScreenDataVisibilityScopeRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ErpUiScreenRegistry::class);

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(ScreenDataVisibilityScopeRegistrar $screenDataVisibilityScopes): void
    {
        View::addNamespace('modules', app_path('Modules'));
        View::composer('layouts.app', AppLayoutComposer::class);
        View::composer('layouts.auth', AuthLayoutComposer::class);
        $screenDataVisibilityScopes->register();
    }
}
