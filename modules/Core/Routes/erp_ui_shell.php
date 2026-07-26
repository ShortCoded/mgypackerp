<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ErpUiShellController;
use Modules\Core\Http\Middleware\AuthorizeErpUiShellScreen;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;

$erpUiScreens = app(ErpUiScreenRegistry::class);
$erpUiScreenIndexRoutes = collect($erpUiScreens->screens())
    ->map(fn ($screen): string => $screen->route('index'))
    ->all();

Route::middleware(['auth', 'erp.expanded'])
    ->group(function () use ($erpUiScreenIndexRoutes, $erpUiScreens): void {
        foreach ($erpUiScreens->screens() as $screen) {
            if (Route::has($screen->route('index'))) {
                continue;
            }

            Route::prefix($screen->routePath())
                ->as($screen->routeNamePrefix().'.')
                ->controller(ErpUiShellController::class)
                ->group(function () use ($screen): void {
                    Route::get('/', 'index')
                        ->defaults('erp_ui_screen', $screen->key())
                        ->middleware(AuthorizeErpUiShellScreen::class.':view')
                        ->name('index');
                    Route::get('/data', 'data')
                        ->defaults('erp_ui_screen', $screen->key())
                        ->middleware(AuthorizeErpUiShellScreen::class.':view')
                        ->name('data');

                    if ($screen->supportsMode('create')) {
                        Route::get('/create', 'create')
                            ->defaults('erp_ui_screen', $screen->key())
                            ->middleware(AuthorizeErpUiShellScreen::class.':create')
                            ->name('create');
                    }

                    if ($screen->supportsMode('view')) {
                        Route::get('/{doc_num}', 'show')
                            ->defaults('erp_ui_screen', $screen->key())
                            ->where('doc_num', '[A-Za-z0-9][A-Za-z0-9._-]*')
                            ->middleware(AuthorizeErpUiShellScreen::class.':view')
                            ->name('show');
                    }

                    if ($screen->supportsMode('edit')) {
                        Route::get('/{doc_num}/edit', 'edit')
                            ->defaults('erp_ui_screen', $screen->key())
                            ->where('doc_num', '[A-Za-z0-9][A-Za-z0-9._-]*')
                            ->middleware(AuthorizeErpUiShellScreen::class.':edit')
                            ->name('edit');
                    }

                    if ($screen->supportsMode('clone')) {
                        Route::get('/{doc_num}/clone', 'clone')
                            ->defaults('erp_ui_screen', $screen->key())
                            ->where('doc_num', '[A-Za-z0-9][A-Za-z0-9._-]*')
                            ->middleware(AuthorizeErpUiShellScreen::class.':clone')
                            ->name('clone');
                    }
                });
        }

        foreach ($erpUiScreens->legacyPlaceholderAliases() as $alias) {
            if (in_array($alias['route'], $erpUiScreenIndexRoutes, true) || Route::has($alias['route'])) {
                continue;
            }

            Route::get($alias['path'], [ErpUiShellController::class, 'index'])
                ->defaults('erp_ui_screen', $alias['target']->key())
                ->middleware(AuthorizeErpUiShellScreen::class.':view')
                ->name($alias['route']);
        }
    });
