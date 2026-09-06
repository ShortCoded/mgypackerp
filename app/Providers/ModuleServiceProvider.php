<?php

namespace App\Providers;

use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * @var list<string>|null
     */
    private ?array $moduleNames = null;

    public function boot(): void
    {
        $this->loadModuleRoutes();

        if ($this->app->runningInConsole()) {
            $this->loadModuleMigrations();
        }
    }

    protected function loadModuleRoutes(): void
    {
        if ($this->routesAreCached()) {
            return;
        }

        foreach ($this->modules() as $module) {
            $this->loadModuleWebRoutes($module);
            $this->loadModuleApiRoutes($module);
        }

        $this->loadErpUiShellRoutes();
    }

    protected function loadModuleWebRoutes(string $module): void
    {
        $path = $this->modulePath($module, 'Routes/web.php');

        if (! file_exists($path)) {
            return;
        }

        Route::middleware(['web'])->group($path);
    }

    protected function loadModuleApiRoutes(string $module): void
    {
        $path = $this->modulePath($module, 'Routes/api.php');

        if (! file_exists($path)) {
            return;
        }

        Route::middleware(['api'])
            ->prefix('api/'.$this->routePrefix($module))
            ->as('api.'.$this->routeName($module).'.')
            ->group($path);
    }

    protected function loadErpUiShellRoutes(): void
    {
        $path = base_path('modules/Core/Routes/erp_ui_shell.php');

        if (! file_exists($path)) {
            return;
        }

        Route::middleware(['web'])->group($path);
    }

    protected function loadModuleMigrations(): void
    {
        foreach ($this->modules() as $module) {
            $path = $this->modulePath($module, 'Database/Migrations');

            if (is_dir($path)) {
                $this->loadMigrationsFrom($path);
            }
        }
    }

    protected function routesAreCached(): bool
    {
        return $this->app instanceof FoundationApplication
            && $this->app->routesAreCached();
    }

    protected function modules(): array
    {
        if ($this->moduleNames !== null) {
            return $this->moduleNames;
        }

        $modulesPath = base_path('modules');

        if (! is_dir($modulesPath)) {
            return $this->moduleNames = [];
        }

        return $this->moduleNames = collect(scandir($modulesPath))
            ->reject(fn (string $module) => in_array($module, ['.', '..'], true))
            ->filter(fn (string $module) => is_dir($modulesPath.DIRECTORY_SEPARATOR.$module))
            ->sort()
            ->values()
            ->all();
    }

    protected function modulePath(string $module, string $path = ''): string
    {
        $base = base_path('modules/'.$module);

        return $path
            ? $base.DIRECTORY_SEPARATOR.$path
            : $base;
    }

    protected function routePrefix(string $module): string
    {
        return strtolower(Str::kebab($module));
    }

    protected function routeName(string $module): string
    {
        return strtolower(Str::kebab($module));
    }
}
