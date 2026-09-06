<?php

use Illuminate\View\Engines\CompilerEngine;
use Laravel\Telescope\TelescopeServiceProvider;
use Livewire\LivewireServiceProvider;
use Yajra\DataTables\DataTablesServiceProvider;
use Yajra\DataTables\ExportServiceProvider;

test('unused interactive table exporters do not boot their application providers', function () {
    $providers = app()->getLoadedProviders();
    $bladeEngine = app('view.engine.resolver')->resolve('blade');

    expect($providers)
        ->toHaveKey(DataTablesServiceProvider::class)
        ->not->toHaveKey(TelescopeServiceProvider::class)
        ->not->toHaveKey(ExportServiceProvider::class)
        ->not->toHaveKey(LivewireServiceProvider::class)
        ->and($bladeEngine)->toBeInstanceOf(CompilerEngine::class)
        ->and($bladeEngine::class)->toBe(CompilerEngine::class)
        ->and(collect(app('router')->getRoutes())->contains(
            fn ($route): bool => str_starts_with($route->uri(), 'livewire'),
        ))->toBeFalse();
});
