<?php

namespace Modules\Core\Services\ErpUi;

use Illuminate\Support\Collection;
use LogicException;

class ErpUiScreenRegistry
{
    /**
     * @var list<string>
     */
    private const ModuleFiles = [
        'core',
        'product_data',
        'sales',
        'purchases',
        'inventory',
        'production',
        'quality',
        'maintenance',
        'finance',
        'fixed_assets',
        'costing',
        'hr',
        'reports',
        'tools',
    ];

    /**
     * @var Collection<int, ErpUiScreenDefinition>|null
     */
    private ?Collection $screens = null;

    /**
     * @var array<string, list<array{key: string, module: string, route: string, path: string, permission: string, target: ErpUiScreenDefinition}>>|null
     */
    private ?array $legacyAliasesByTarget = null;

    public function __construct(
        private readonly ErpUiScreenBlueprints $blueprints,
    ) {}

    /**
     * @return list<ErpUiScreenDefinition>
     */
    public function screens(): array
    {
        return $this->collection()->values()->all();
    }

    public function find(string $key): ?ErpUiScreenDefinition
    {
        $screen = $this->collection()->first(
            fn (ErpUiScreenDefinition $definition): bool => $definition->key() === $key,
        );

        return $screen instanceof ErpUiScreenDefinition ? $screen : null;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->collection()
            ->flatMap(fn (ErpUiScreenDefinition $screen): array => array_map(
                fn (string $action): string => $screen->permission($action),
                $screen->actions(),
            ))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, module: string, route: string, path: string, permission: string, target: ErpUiScreenDefinition}>
     */
    public function legacyPlaceholderAliases(): array
    {
        $targets = config('erp_ui_screen_aliases', []);

        if (! is_array($targets)) {
            return [];
        }

        $placeholderScreens = collect(config('erp_expanded_screens.screens', []))
            ->filter(fn (mixed $screen): bool => is_array($screen) && ($screen['status'] ?? null) === 'placeholder');

        $missingMappings = $placeholderScreens
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && ! array_key_exists($key, $targets));

        if ($missingMappings->isNotEmpty()) {
            throw new LogicException('Missing ERP UI Shell placeholder aliases: '.$missingMappings->implode(', '));
        }

        return $placeholderScreens
            ->map(function (array $placeholder) use ($targets): array {
                $key = (string) $placeholder['key'];
                $targetKey = (string) $targets[$key];
                $target = $this->find($targetKey);

                if (! $target instanceof ErpUiScreenDefinition) {
                    throw new LogicException("ERP UI Shell placeholder alias [{$key}] targets missing screen [{$targetKey}].");
                }

                return [
                    'key' => $key,
                    'module' => (string) $placeholder['module'],
                    'route' => (string) $placeholder['route'],
                    'path' => (string) $placeholder['path'],
                    'permission' => (string) $placeholder['permission'],
                    'target' => $target,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function legacyPlaceholderPermissions(): array
    {
        return collect($this->legacyPlaceholderAliases())
            ->pluck('permission')
            ->filter(fn (mixed $permission): bool => is_string($permission) && $permission !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function menuItems(): array
    {
        return $this->collection()
            ->filter(fn (ErpUiScreenDefinition $screen): bool => $screen->get('menu_visible', true) !== false)
            ->groupBy(fn (ErpUiScreenDefinition $screen): string => (string) $screen->get('menu_label'))
            ->map(function (Collection $moduleScreens): array {
                /** @var ErpUiScreenDefinition $first */
                $first = $moduleScreens->first();
                $groups = $moduleScreens
                    ->groupBy(fn (ErpUiScreenDefinition $screen): string => (string) $screen->get('group'))
                    ->map(function (Collection $groupScreens): array {
                        /** @var ErpUiScreenDefinition $groupFirst */
                        $groupFirst = $groupScreens->first();
                        $group = $groupFirst->get('group_definition', []);

                        return [
                            'label' => $groupFirst->module().'_'.$groupFirst->get('group'),
                            'title' => $groupFirst->localized($group['title'] ?? null),
                            'icon' => (string) ($group['icon'] ?? 'folder-open'),
                            'route' => null,
                            'permission' => null,
                            'phase_modes' => ['expanded'],
                            'order' => (int) ($group['order'] ?? 999),
                            'children' => $groupScreens
                                ->sortBy(fn (ErpUiScreenDefinition $screen): int => (int) $screen->get('order', 999))
                                ->map(fn (ErpUiScreenDefinition $screen): array => $this->menuItemForScreen($screen))
                                ->values()
                                ->all(),
                        ];
                    })
                    ->sortBy('order')
                    ->values()
                    ->all();

                return [
                    'label' => (string) $first->get('menu_label'),
                    'title' => $first->localized($first->get('menu_title')),
                    'icon' => (string) $first->get('menu_icon', 'folder'),
                    'route' => null,
                    'permission' => null,
                    'phase_modes' => ['expanded'],
                    'order' => (int) $first->get('menu_order', 999),
                    'children' => $groups,
                ];
            })
            ->sortBy('order')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ErpUiScreenDefinition>
     */
    private function collection(): Collection
    {
        if ($this->screens instanceof Collection) {
            return $this->screens;
        }

        $definitions = collect();

        foreach (self::ModuleFiles as $moduleIndex => $moduleFile) {
            $path = config_path("erp_ui_screens/{$moduleFile}.php");

            if (! is_file($path)) {
                continue;
            }

            $module = require $path;

            if (! is_array($module) || ! isset($module['module'], $module['screens']) || ! is_array($module['screens'])) {
                continue;
            }

            foreach (array_values($module['screens']) as $screenIndex => $screen) {
                if (! is_array($screen)) {
                    continue;
                }

                if (in_array($module['module'], ['inventory', 'production', 'quality'], true)
                    && ($screen['shell_enabled'] ?? true) === false
                    && ($screen['menu_visible'] ?? true) === false) {
                    continue;
                }

                $definitions->push(new ErpUiScreenDefinition(
                    $this->blueprints->build($module, $screen, (($moduleIndex + 1) * 10000) + (($screenIndex + 1) * 10)),
                ));
            }
        }

        $this->guardUnique($definitions, 'key', fn (ErpUiScreenDefinition $screen): string => $screen->key());
        $this->guardUnique($definitions, 'route name', fn (ErpUiScreenDefinition $screen): string => $screen->routeNamePrefix());
        $this->guardUnique($definitions, 'route path', fn (ErpUiScreenDefinition $screen): string => $screen->routePath());

        $this->screens = $definitions
            ->sortBy(fn (ErpUiScreenDefinition $screen): string => sprintf(
                '%04d-%08d',
                (int) $screen->get('menu_order', 999),
                (int) $screen->get('order', 999),
            ))
            ->values();

        return $this->screens;
    }

    /**
     * @param  Collection<int, ErpUiScreenDefinition>  $definitions
     * @param  callable(ErpUiScreenDefinition): string  $value
     */
    private function guardUnique(Collection $definitions, string $label, callable $value): void
    {
        $duplicates = $definitions
            ->groupBy($value)
            ->filter(fn (Collection $items): bool => $items->count() > 1)
            ->keys();

        if ($duplicates->isNotEmpty()) {
            throw new LogicException('Duplicate ERP UI Shell '.$label.': '.$duplicates->implode(', '));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function menuItemForScreen(ErpUiScreenDefinition $screen): array
    {
        $actions = [];
        $legacyAliases = collect($this->legacyAliasesForScreen($screen));

        foreach ($screen->actions() as $action) {
            $actions[str_replace('.', '_', $action)] = $screen->permission($action);
        }

        $viewPermissions = $legacyAliases
            ->pluck('permission')
            ->prepend($screen->permission('view'))
            ->unique()
            ->values()
            ->all();

        return [
            'label' => $screen->key(),
            'title' => $screen->title(),
            'icon' => (string) $screen->get('icon', 'file-alt'),
            'route' => $screen->route('index'),
            'permission' => count($viewPermissions) === 1 ? $viewPermissions[0] : $viewPermissions,
            'actions' => $actions,
            'phase_modes' => ['expanded'],
            'active' => [
                $screen->routeNamePrefix().'.*',
                ...$legacyAliases->pluck('route')->all(),
            ],
            'keywords' => [$screen->title('en'), $screen->title('ar')],
            'children' => [],
        ];
    }

    /**
     * @return list<array{key: string, module: string, route: string, path: string, permission: string, target: ErpUiScreenDefinition}>
     */
    private function legacyAliasesForScreen(ErpUiScreenDefinition $screen): array
    {
        if ($this->legacyAliasesByTarget === null) {
            $this->legacyAliasesByTarget = [];

            foreach ($this->legacyPlaceholderAliases() as $alias) {
                $this->legacyAliasesByTarget[$alias['target']->key()][] = $alias;
            }
        }

        return $this->legacyAliasesByTarget[$screen->key()] ?? [];
    }
}
