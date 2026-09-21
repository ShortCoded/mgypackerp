<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuConfigFileOrder;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\RequestMemo;

class PermissionRegistryService
{
    /**
     * @var list<string>
     */
    private const PermissionFields = [
        'actions',
        'permissions',
        'action',
        'abilities',
        'ability',
        'permission',
    ];

    public function __construct(
        private readonly RequestMemo $memo,
        private readonly MenuConfigFileOrder $menuFiles,
        private readonly ErpUiScreenRegistry $erpUiScreens,
        private readonly MenuService $menu,
    ) {}

    /**
     * @param  array<int, string>  $permissionNames
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string}>, children: list<array<string, mixed>>}>
     */
    public function groupedForForm(array $permissionNames): array
    {
        $formPermissions = collect($this->formAssignablePermissions())->flip();
        $available = collect($permissionNames)
            ->filter(fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
            ->map(fn (string $permission): string => trim($permission))
            ->map(fn (string $permission): string => $this->canonicalPermission($permission))
            ->filter(fn (string $permission): bool => $formPermissions->has($permission))
            ->unique()
            ->values();

        $remaining = $available->flip();
        $groups = [];
        $generalChildren = [];

        foreach ($this->menuItems() as $item) {
            if (! is_array($item)) {
                continue;
            }

            $node = $this->menuNodeForForm($item, $remaining);

            if ($node === null) {
                continue;
            }

            $children = $item['children'] ?? [];
            $itemKey = $this->menuItemKey($item);

            if (is_array($children) && $children !== []) {
                $groups[] = $node;

                continue;
            }

            if ($itemKey !== 'dashboard') {
                $groups[] = [
                    'key' => $this->nodeKey('section', $node['key']),
                    'label' => $node['label'],
                    'permissions' => [],
                    'children' => [$node],
                ];

                continue;
            }

            $generalChildren[] = $node;
        }

        if ($generalChildren !== []) {
            array_unshift($groups, [
                'key' => 'general',
                'label' => __('common.groups.general'),
                'permissions' => [],
                'children' => $generalChildren,
            ]);
        }

        return $groups;
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->memo->remember('permissions.registry.all', function (): array {
            $permissions = array_merge(
                $this->fromMenus(),
                $this->erpUiScreens->permissions(),
            );

            $permissions = array_filter($permissions, fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '');
            $permissions = array_map(fn (string $permission): string => trim($permission), $permissions);
            $permissions = array_map(fn (string $permission): string => $this->canonicalPermission($permission), $permissions);
            $permissions = array_values(array_unique($permissions));

            sort($permissions);

            return $permissions;
        });
    }

    /**
     * @return array<int, string>
     */
    public function formAssignablePermissions(): array
    {
        return $this->memo->remember('permissions.registry.form_assignable', function (): array {
            $permissions = array_merge(
                $this->erpUiScreens->permissions(),
            );

            foreach ($this->menuConfigFiles() as $file) {
                $items = require $file;

                if (is_array($items)) {
                    $permissions = array_merge($permissions, $this->extractFormAssignableFromMenuItems($items));
                }
            }

            $permissions = array_filter($permissions, fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '');
            $permissions = array_map(fn (string $permission): string => trim($permission), $permissions);
            $permissions = array_map(fn (string $permission): string => $this->canonicalPermission($permission), $permissions);
            $permissions = array_values(array_unique($permissions));

            sort($permissions);

            return $permissions;
        });
    }

    /**
     * @return array<int, string>
     */
    public function fromMenus(): array
    {
        return $this->memo->remember('permissions.registry.from_menus', function (): array {
            $permissions = [];

            foreach ($this->menuConfigFiles() as $file) {
                $items = require $file;

                if (is_array($items)) {
                    $permissions = array_merge($permissions, $this->extractFromMenuItems($items));
                }
            }

            return $permissions;
        });
    }

    /**
     * @return array<string, string>
     */
    public function legacyPermissionMap(): array
    {
        $map = [
            'users.index' => 'users.view',
            'users.bulk_delete' => 'users.delete',
            'roles.bulk_delete' => 'roles.delete',
            'roles.company_access.manage' => 'roles.operating_scope.manage',
            'companies.index' => 'companies.view',
            'companies.bulk_delete' => 'companies.delete',
            'file_manager.index' => 'file_manager.view',
            'file_manager.bulk_download' => 'file_manager.download',
            'file_manager.bulk_delete' => 'file_manager.delete',
        ];

        foreach ($this->hrLookupPermissionPrefixes() as $prefix) {
            $map["{$prefix}.index"] = "{$prefix}.view";
            $map["{$prefix}.bulk_delete"] = "{$prefix}.delete";
        }

        return $map;
    }

    public function canonicalPermission(string $permission): string
    {
        $permission = trim($permission);

        return $this->legacyPermissionMap()[$permission] ?? $permission;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    public function extractFromMenuItems(array $items): array
    {
        $permissions = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $permissions = array_merge($permissions, array_values($this->permissionsForMenuItem($item)));

            if (isset($item['children']) && is_array($item['children'])) {
                $permissions = array_merge($permissions, $this->extractFromMenuItems($item['children']));
            }
        }

        return $permissions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function extractFormAssignableFromMenuItems(array $items): array
    {
        $permissions = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($this->menuItemHasAssignableFormPermissions($item)) {
                $permissions = array_merge($permissions, array_values($this->permissionsForMenuItem($item)));
            }

            if (isset($item['children']) && is_array($item['children'])) {
                $permissions = array_merge($permissions, $this->extractFormAssignableFromMenuItems($item['children']));
            }
        }

        return $permissions;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function menuLabel(array $item): string
    {
        $label = isset($item['label']) && is_string($item['label']) ? $item['label'] : null;

        if ($label && trans()->has("menu.{$label}")) {
            return __("menu.{$label}");
        }

        return isset($item['title']) && is_string($item['title']) ? $item['title'] : (string) $label;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function permissionsForMenuItem(array $item): array
    {
        $permissions = [];

        foreach (self::PermissionFields as $permissionField) {
            if (! array_key_exists($permissionField, $item)) {
                continue;
            }

            foreach ($this->permissionRowsFromValue($item[$permissionField], $this->defaultActionForField($permissionField)) as $row) {
                $this->addPermission($permissions, $row['action'], $row['permission']);
            }
        }

        return $permissions;
    }

    /**
     * @return list<array{action: string, permission: string}>
     */
    private function permissionRowsFromValue(mixed $value, string $fallbackAction, ?string $currentAction = null): array
    {
        if (is_string($value)) {
            $permission = $this->normalizedPermissionCandidate($value);

            return $permission === null
                ? []
                : [[
                    'action' => $this->canonicalAction($currentAction ?? $fallbackAction, $permission),
                    'permission' => $permission,
                ]];
        }

        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $key => $nestedValue) {
            $nextAction = $currentAction;

            if (is_string($key) && ! in_array($key, self::PermissionFields, true)) {
                $nextAction = $key;
            }

            $rows = array_merge(
                $rows,
                $this->permissionRowsFromValue($nestedValue, $fallbackAction, $nextAction),
            );
        }

        return $rows;
    }

    private function normalizedPermissionCandidate(string $permission): ?string
    {
        $permission = $this->canonicalPermission($permission);

        if (! $this->looksLikePermissionName($permission)) {
            return null;
        }

        return $permission;
    }

    private function looksLikePermissionName(string $permission): bool
    {
        $permission = trim($permission);

        if ($permission === '' || str_starts_with($permission, 'admin.')) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)+$/', $permission);
    }

    /**
     * @param  array<string, string>  $permissions
     */
    private function addPermission(array &$permissions, string $action, string $permission): void
    {
        if (in_array($permission, $permissions, true)) {
            return;
        }

        if (! array_key_exists($action, $permissions)) {
            $permissions[$action] = $permission;

            return;
        }

        $index = 2;
        $baseAction = $action;

        while (array_key_exists($action, $permissions)) {
            $action = "{$baseAction}_{$index}";
            $index++;
        }

        $permissions[$action] = $permission;
    }

    private function defaultActionForField(string $field): string
    {
        return match ($field) {
            'permission' => 'view',
            'ability', 'abilities' => 'ability',
            'action', 'actions' => 'action',
            default => 'permission',
        };
    }

    public function labelForPermission(string $permission, ?string $action = null): string
    {
        if ($action !== null && $action !== '' && $this->usesPermissionSpecificLabel($action)) {
            $translated = $this->translatedPermissionLabel($permission);

            if ($translated !== null) {
                return $translated;
            }
        }

        if ($action !== null && $action !== '') {
            $actionKey = "roles.permission_labels.{$action}";

            if (trans()->has($actionKey)) {
                return __($actionKey);
            }

            $shellActionKey = "erp_ui_shell.permission_actions.{$action}";

            if (trans()->has($shellActionKey)) {
                return __($shellActionKey);
            }
        }

        $translated = $this->translatedPermissionLabel($permission);

        if ($translated !== null) {
            return $translated;
        }

        $legacyKey = 'roles.permission_labels.'.str_replace('.', '_', $permission);

        if (trans()->has($legacyKey)) {
            return __($legacyKey);
        }

        return $this->readablePermissionFallback($permission);
    }

    private function usesPermissionSpecificLabel(string $action): bool
    {
        return in_array($action, ['account_code_control', 'approve', 'cancel', 'print'], true);
    }

    private function permissionLabel(string $action, string $permission): string
    {
        return $this->labelForPermission($permission, $action);
    }

    private function canonicalAction(string $action, string $permission): string
    {
        return match ($action) {
            'index' => str_ends_with($permission, '.view') ? 'view' : $action,
            'bulk_delete' => str_ends_with($permission, '.delete') ? 'delete' : $action,
            'bulk_download' => str_ends_with($permission, '.download') ? 'download' : $action,
            default => $action,
        };
    }

    private function translatedPermissionLabel(string $permission): ?string
    {
        foreach (['permissions', 'erp_expanded_screens.permissions'] as $translationGroup) {
            $labels = __($translationGroup);

            if (! is_array($labels)) {
                continue;
            }

            if (array_key_exists($permission, $labels) && is_string($labels[$permission])) {
                return $labels[$permission];
            }

            $nested = data_get($labels, $permission);

            if (is_string($nested)) {
                return $nested;
            }
        }

        return null;
    }

    private function readablePermissionFallback(string $permission): string
    {
        $parts = collect(explode('.', $permission))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values();

        if ($parts->isEmpty()) {
            return '';
        }

        $action = (string) $parts->pop();
        $subject = $parts
            ->map(function (string $part, int $index): string {
                $words = str_replace(['-', '_'], ' ', $part);

                return Str::headline($index === 0 ? Str::singular($words) : $words);
            })
            ->implode(' ');

        $actionLabel = Str::headline(str_replace(['-', '_'], ' ', $action));

        return trim($actionLabel.' '.$subject);
    }

    /**
     * @return list<string>
     */
    private function hrLookupPermissionPrefixes(): array
    {
        return [
            'hr.countries',
            'hr.governorates',
            'hr.cities',
            'hr.areas',
            'hr.nationalities',
            'hr.religions',
            'hr.qualifications',
            'hr.universities',
            'hr.faculties',
            'hr.specializations',
            'hr.military_services',
            'hr.allowances',
            'hr.hiring_statuses',
            'hr.identifications',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function menuItemKey(array $item): string
    {
        $rawKey = null;

        foreach (['key', 'label', 'route', 'title'] as $field) {
            if (isset($item[$field]) && is_string($item[$field]) && trim($item[$field]) !== '') {
                $rawKey = trim($item[$field]);

                break;
            }
        }

        if (! $rawKey) {
            $permissions = $this->permissionsForMenuItem($item);
            $rawKey = $permissions === [] ? 'resource' : (string) reset($permissions);
        }

        return str($rawKey)
            ->replace(['.', '/', '\\'], '_')
            ->slug('_')
            ->toString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function menuItems(): array
    {
        return $this->menu->permissionStructure();
    }

    /**
     * @return list<string>
     */
    private function menuConfigFiles(): array
    {
        return $this->menuFiles->files();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, int>  $remaining
     * @return array{key: string, label: string, permissions: list<array{name: string, label: string}>, children: list<array<string, mixed>>}|null
     */
    private function menuNodeForForm(array $item, Collection $remaining, string $parentKey = ''): ?array
    {
        $nodeKey = $this->nodeKey($parentKey, $this->menuItemKey($item));
        $children = [];
        $childItems = $item['children'] ?? [];

        if (is_array($childItems)) {
            foreach ($childItems as $child) {
                if (! is_array($child)) {
                    continue;
                }

                $childNode = $this->menuNodeForForm($child, $remaining, $nodeKey);

                if ($childNode !== null) {
                    $children[] = $childNode;
                }
            }
        }

        $permissions = $this->menuItemHasAssignableFormPermissions($item)
            ? $this->permissionRowsForMenuItem($item, $remaining)
            : [];

        if ($permissions === [] && $children === []) {
            return null;
        }

        return [
            'key' => $nodeKey,
            'label' => $this->menuLabel($item),
            'permissions' => $permissions,
            'children' => $children,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, int>  $remaining
     * @return list<array{name: string, label: string}>
     */
    private function permissionRowsForMenuItem(array $item, Collection $remaining): array
    {
        $permissions = [];

        foreach ($this->permissionsForMenuItem($item) as $action => $permission) {
            if (! $remaining->has($permission)) {
                continue;
            }

            $permissions[] = [
                'name' => $permission,
                'label' => $this->permissionLabel((string) $action, $permission),
            ];
            $remaining->forget($permission);
        }

        return $permissions;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function menuItemHasAssignableFormPermissions(array $item): bool
    {
        if ($this->permissionsForMenuItem($item) === []) {
            return false;
        }

        $route = $item['route'] ?? null;

        return is_string($route) && trim($route) !== '';
    }

    private function nodeKey(string ...$parts): string
    {
        return collect($parts)
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->implode('_');
    }
}
