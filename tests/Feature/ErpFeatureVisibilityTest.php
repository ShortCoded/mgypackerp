<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @param  list<array<string, mixed>>  $items
 * @return list<string>
 */
function erpFeatureMenuLabels(array $items): array
{
    $labels = [];

    foreach ($items as $item) {
        $label = $item['label'] ?? null;

        if (is_string($label) && $label !== '') {
            $labels[] = $label;
        }

        $children = $item['children'] ?? [];

        if (is_array($children)) {
            $labels = [...$labels, ...erpFeatureMenuLabels($children)];
        }
    }

    return $labels;
}

test('obsolete Tools UI shell screens are removed while working utilities remain', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $removedSlugs = [
        'workflow-designer',
        'approval-matrix',
        'notification-center-settings',
        'email-template-settings',
        'sms-template-settings',
        'whatsapp-template-settings',
        'import-templates',
        'data-import',
        'data-export',
        'integration-settings',
        'api-settings',
        'barcode-settings',
        'label-templates',
        'print-template-designer',
        'integration-logs',
        'background-job-monitor',
        'system-health',
        'backup-settings',
        'numbering-review',
        'permission-review',
        'menu-review',
    ];
    $menuLabels = erpFeatureMenuLabels(app(MenuService::class)->structure());
    $permissions = app(PermissionRegistryService::class)->all();

    expect(collect(app(ErpUiScreenRegistry::class)->screens())->where('module', 'tools'))->toBeEmpty()
        ->and($menuLabels)->toContain(
            'open_documents',
            'file_manager',
            'calendar',
            'my_board',
            'team_board',
            'chat',
            'pwa_settings',
            'activity_logs',
            'auth_logs',
            'auth_sessions',
        );

    foreach ($removedSlugs as $slug) {
        $resource = str_replace('-', '_', $slug);

        expect($menuLabels)->not->toContain('tools_'.$resource)
            ->and($permissions)->not->toContain('tools.'.$resource.'.view')
            ->and(Route::has('admin.tools.'.$slug.'.index'))->toBeFalse();
    }
});

test('data visibility management is hidden and inaccessible by default', function (): void {
    expect(config('erp_features.screen_data_visibility_rules.enabled'))->toBeFalse();

    $menuLabels = erpFeatureMenuLabels(app(MenuService::class)->structure());
    $permissionRegistry = app(PermissionRegistryService::class);
    $permissions = $permissionRegistry->all();
    $formPermissions = $permissionRegistry->formAssignablePermissions();
    $visibilityPermissions = [
        'screen_data_visibility_rules.view',
        'screen_data_visibility_rules.create',
        'screen_data_visibility_rules.clone',
        'screen_data_visibility_rules.edit',
        'screen_data_visibility_rules.delete',
        'screen_data_visibility_rules.view_trashed',
        'screen_data_visibility_rules.restore',
        'screen_data_visibility_rules.bypass',
    ];

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('screen_data_visibility_rules.view', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo('screen_data_visibility_rules.view');

    expect($menuLabels)->not->toContain('screen_data_visibility_rules')
        ->and(collect($permissions)->intersect($visibilityPermissions))->toBeEmpty()
        ->and(collect($formPermissions)->intersect($visibilityPermissions))->toBeEmpty();

    $this->actingAs($actor)
        ->get(route('admin.screen-data-visibility-rules.index'))
        ->assertNotFound();
});

test('data visibility management menu permissions and route are restored by configuration', function (): void {
    config()->set('erp_features.screen_data_visibility_rules.enabled', true);

    $menuLabels = erpFeatureMenuLabels(app(MenuService::class)->structure());
    $permissionRegistry = app(PermissionRegistryService::class);
    $permissions = $permissionRegistry->all();
    $formPermissions = $permissionRegistry->formAssignablePermissions();
    $visibilityPermissions = [
        'screen_data_visibility_rules.view',
        'screen_data_visibility_rules.create',
        'screen_data_visibility_rules.clone',
        'screen_data_visibility_rules.edit',
        'screen_data_visibility_rules.delete',
        'screen_data_visibility_rules.view_trashed',
        'screen_data_visibility_rules.restore',
        'screen_data_visibility_rules.bypass',
    ];

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('screen_data_visibility_rules.view', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo('screen_data_visibility_rules.view');

    expect($menuLabels)->toContain('screen_data_visibility_rules')
        ->and($permissions)->toContain(...$visibilityPermissions)
        ->and($formPermissions)->toContain(...$visibilityPermissions);

    $this->actingAs($actor)
        ->get(route('admin.screen-data-visibility-rules.index'))
        ->assertOk();
});
