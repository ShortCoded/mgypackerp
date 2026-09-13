<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuService;

test('shared setup UI shells are removed from navigation permissions and routes', function (): void {
    $slugs = [
        'document-sequence-definitions',
        'numbering-policies',
        'status-definitions',
        'status-transition-rules',
        'approval-workflow-definitions',
        'notification-rules',
        'attachment-categories',
        'document-templates',
        'print-templates',
        'payment-terms',
        'delivery-terms',
        'warranty-terms',
        'units-conversion-policies',
        'product-classification-settings',
    ];
    $registry = app(ErpUiScreenRegistry::class);
    $permissions = collect(app(PermissionRegistryService::class)->all());
    $menuLabels = collect(app(MenuService::class)->structure())
        ->flatMap(function (array $domain): array {
            $flatten = function (array $items) use (&$flatten): array {
                return collect($items)->flatMap(
                    fn (array $item): array => [$item['label'] ?? null, ...$flatten($item['children'] ?? [])],
                )->filter()->values()->all();
            };

            return $flatten([$domain]);
        });

    collect($slugs)->each(function (string $slug) use ($registry, $permissions, $menuLabels): void {
        $key = 'core_'.str_replace('-', '_', $slug);
        $permissionPrefix = 'core.'.str_replace('-', '_', $slug).'.';

        expect($registry->find($key))->toBeNull()
            ->and($permissions->contains(fn (string $permission): bool => str_starts_with($permission, $permissionPrefix)))->toBeFalse()
            ->and($menuLabels)->not->toContain($key)
            ->and(Route::has("admin.core.{$slug}.index"))->toBeFalse()
            ->and(Route::has("admin.core.{$slug}.data"))->toBeFalse();
    });

    expect(config('menu_sections.subgroups'))->not->toHaveKey('shared_setup')
        ->and(config('menu_sections.source_subgroups'))->not->toContain('shared_setup')
        ->and(config('erp_ui_screens.core.groups'))->not->toHaveKey('document_governance');
});
