<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ExpandedSetupPlaceholderController;
use Modules\Core\Services\ExpandedScreenRegistry;

$expandedScreens = app(ExpandedScreenRegistry::class);

Route::middleware(['auth', 'erp.expanded'])
    ->group(function () use ($expandedScreens): void {
        foreach ($expandedScreens->placeholderScreens() as $screen) {
            $routeName = $screen['route'] ?? null;
            $path = $screen['path'] ?? null;
            $permission = $screen['permission'] ?? null;

            if (! is_string($routeName) || $routeName === '' || Route::has($routeName)) {
                continue;
            }

            if (! is_string($path) || $path === '' || ! is_string($permission) || $permission === '') {
                continue;
            }

            Route::get($path, ExpandedSetupPlaceholderController::class)
                ->defaults('expanded_screen', $screen['key'])
                ->middleware("can:{$permission}")
                ->name($routeName);
        }
    });
