<?php

use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\ScreenDataVisibilityRegistry;

test('retired sales project setup screens are absent from the runtime surface', function (): void {
    $routeNames = collect(app('router')->getRoutes())->pluck('action.as')->filter()->values();
    $routeUris = collect(app('router')->getRoutes())->pluck('uri')->filter()->values();
    $salesMenu = require config_path('menu/sales.php');
    $menuLabels = collect($salesMenu[0]['children'] ?? [])->pluck('label');
    $permissions = collect(app(PermissionRegistryService::class)->all());
    $visibility = app(ScreenDataVisibilityRegistry::class);

    expect($routeNames->contains(fn (string $name): bool => str_starts_with($name, 'admin.sales.project-structures.')))->toBeFalse()
        ->and($routeNames->contains(fn (string $name): bool => str_starts_with($name, 'admin.sales.project-structure-models.')))->toBeFalse()
        ->and($routeNames)->not->toContain('admin.sales.select2.project-structures')
        ->and($routeUris->contains(fn (string $uri): bool => str_contains($uri, 'project-structures') || str_contains($uri, 'project-structure-models')))->toBeFalse()
        ->and($menuLabels)->not->toContain('project_structures', 'project_structure_models')
        ->and($permissions->contains(fn (string $permission): bool => str_starts_with($permission, 'project_structures.') || str_starts_with($permission, 'project_structure_models.')))->toBeFalse()
        ->and($visibility->definition('project_structures'))->toBeNull()
        ->and($visibility->definition('project_structure_models'))->toBeNull()
        ->and(config('document_numbers.project_structures'))->toBeNull()
        ->and(config('document_numbers.project_structure_models'))->toBeNull();
});

test('retired sales project setup implementation files and labels are removed', function (): void {
    $paths = [
        base_path('modules/Sales/Http/Controllers/ProjectStructureController.php'),
        base_path('modules/Sales/Http/Controllers/ProjectStructureModelController.php'),
        base_path('modules/Sales/Models/ProjectStructure.php'),
        base_path('modules/Sales/Models/ProjectStructureModel.php'),
        resource_path('views/modules/sales/project-structures'),
        resource_path('views/modules/sales/project-structure-models'),
        public_path('assets/js/modules/Sales/project-structures.js'),
        public_path('assets/js/modules/Sales/project-structure-models.js'),
        lang_path('ar/project_structures.php'),
        lang_path('en/project_structures.php'),
        lang_path('ar/project_structure_models.php'),
        lang_path('en/project_structure_models.php'),
    ];

    foreach ($paths as $path) {
        expect(file_exists($path))->toBeFalse($path);
    }

    expect(__('menu.project_structures', locale: 'ar'))->toBe('menu.project_structures')
        ->and(__('menu.project_structure_models', locale: 'ar'))->toBe('menu.project_structure_models')
        ->and(__('menu.project_structures', locale: 'en'))->toBe('menu.project_structures')
        ->and(__('menu.project_structure_models', locale: 'en'))->toBe('menu.project_structure_models');
});
