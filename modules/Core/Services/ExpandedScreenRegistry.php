<?php

namespace Modules\Core\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ExpandedScreenRegistry
{
    public const StatusLive = 'live';

    public const StatusPlaceholder = 'placeholder';

    /**
     * @return list<array<string, mixed>>
     */
    public function modules(): array
    {
        return $this->moduleCollection()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function screens(): array
    {
        return $this->screenCollection()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function placeholderScreens(): array
    {
        return $this->screenCollection()
            ->filter(fn (array $screen): bool => $screen['status'] === self::StatusPlaceholder)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function placeholderPermissions(): array
    {
        return $this->screenCollection()
            ->filter(fn (array $screen): bool => $screen['status'] === self::StatusPlaceholder)
            ->flatMap(fn (array $screen): array => $this->permissionsFrom($screen['permission'] ?? null))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $screen = $this->screenCollection()
            ->first(fn (array $screen): bool => $screen['key'] === $key);

        return is_array($screen) ? $screen : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function menuItems(): array
    {
        $screensByModule = $this->screenCollection()->groupBy('module');

        return $this->moduleCollection()
            ->map(function (array $module) use ($screensByModule): array {
                $module['children'] = $screensByModule
                    ->get($module['key'], collect())
                    ->map(fn (array $screen): array => $this->menuItemForScreen($screen))
                    ->values()
                    ->all();

                return $this->menuItemForModule($module);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function placeholderMenuItems(): array
    {
        $screensByModule = $this->screenCollection()
            ->filter(fn (array $screen): bool => $screen['status'] === self::StatusPlaceholder)
            ->groupBy('module');

        return $this->moduleCollection()
            ->filter(fn (array $module): bool => $screensByModule->has($module['key']))
            ->map(function (array $module) use ($screensByModule): array {
                $module['children'] = $screensByModule
                    ->get($module['key'], collect())
                    ->map(fn (array $screen): array => $this->menuItemForScreen($screen))
                    ->values()
                    ->all();

                return $this->menuItemForModule($module);
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function moduleCollection(): Collection
    {
        return collect(config('erp_expanded_screens.modules', []))
            ->filter(fn (mixed $module, mixed $key): bool => is_string($key) && is_array($module))
            ->map(function (array $module, string $key): array {
                return [
                    'key' => $key,
                    'label' => $module['label'] ?? $key,
                    'title' => $this->translate($module['label'] ?? null, Str::headline($key)),
                    'order' => (int) ($module['order'] ?? 999),
                    'icon' => (string) ($module['icon'] ?? 'circle'),
                ];
            })
            ->sortBy([
                ['order', 'asc'],
                ['key', 'asc'],
            ])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function screenCollection(): Collection
    {
        return collect(config('erp_expanded_screens.screens', []))
            ->filter(fn (mixed $screen): bool => is_array($screen))
            ->map(fn (array $screen, int $index): array => $this->normalizeScreen($screen, $index))
            ->filter(fn (array $screen): bool => $screen['key'] !== '' && $screen['module'] !== '')
            ->sortBy([
                ['module_order', 'asc'],
                ['order', 'asc'],
                ['key', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $screen
     * @return array<string, mixed>
     */
    private function normalizeScreen(array $screen, int $index): array
    {
        $module = (string) ($screen['module'] ?? '');
        $key = (string) ($screen['key'] ?? '');
        $status = strtolower((string) ($screen['status'] ?? self::StatusPlaceholder));
        $status = in_array($status, [self::StatusLive, self::StatusPlaceholder], true) ? $status : self::StatusPlaceholder;
        $moduleOrder = (int) data_get(config('erp_expanded_screens.modules', []), "{$module}.order", 999);

        return [
            ...$screen,
            'module' => $module,
            'key' => $key,
            'label' => $screen['label'] ?? "erp_expanded_screens.screens.{$key}",
            'title' => $this->translate($screen['label'] ?? null, Str::headline($key)),
            'module_title' => $this->moduleTitle($module),
            'status' => $status,
            'route' => $screen['route'] ?? null,
            'path' => trim((string) ($screen['path'] ?? ''), '/'),
            'permission' => $screen['permission'] ?? null,
            'icon' => (string) ($screen['icon'] ?? 'circle'),
            'order' => (int) ($screen['order'] ?? ($index + 1) * 10),
            'module_order' => $moduleOrder,
            'expanded_required' => (bool) ($screen['expanded_required'] ?? $status === self::StatusPlaceholder),
        ];
    }

    private function moduleTitle(string $module): string
    {
        $moduleConfig = config("erp_expanded_screens.modules.{$module}", []);

        return is_array($moduleConfig)
            ? $this->translate($moduleConfig['label'] ?? null, Str::headline($module))
            : Str::headline($module);
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private function menuItemForModule(array $module): array
    {
        return [
            'label' => $module['key'],
            'title' => $module['title'],
            'icon' => $module['icon'],
            'route' => null,
            'phase_modes' => ['expanded'],
            'children' => $module['children'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $screen
     * @return array<string, mixed>
     */
    private function menuItemForScreen(array $screen): array
    {
        $actions = [];
        $permissions = $this->permissionsFrom($screen['permission'] ?? null);

        if ($permissions !== []) {
            $actions['view'] = $permissions[0];
        }

        return [
            'label' => $screen['key'],
            'title' => $screen['title'],
            'icon' => $screen['icon'],
            'route' => $screen['route'],
            'permission' => $screen['permission'],
            'actions' => $actions,
            'phase_modes' => ['expanded'],
            'status' => $screen['status'],
            'screen_key' => $screen['key'],
            'active' => is_string($screen['route']) && $screen['route'] !== '' ? [$screen['route'], "{$screen['route']}.*"] : [],
        ];
    }

    /**
     * @return list<string>
     */
    private function permissionsFrom(mixed $permission): array
    {
        if (is_string($permission)) {
            $permission = trim($permission);

            return $permission === '' ? [] : [$permission];
        }

        if (! is_array($permission)) {
            return [];
        }

        return collect($permission)
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => trim($name))
            ->values()
            ->all();
    }

    private function translate(mixed $key, string $fallback): string
    {
        if (! is_string($key) || trim($key) === '') {
            return $fallback;
        }

        return trans()->has($key) ? __($key) : $fallback;
    }
}
