<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Throwable;

class MenuService
{
    public function __construct(
        private readonly RequestMemo $memo,
        private readonly MenuConfigFileOrder $menuFiles,
        private readonly ErpUiScreenRegistry $erpUiScreens,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMenu(?User $user = null): array
    {
        $user ??= auth()->user();
        $userKey = $user instanceof User ? (string) $user->getKey() : 'guest';
        $routeName = request()->route()?->getName() ?: 'none';
        $locale = app()->getLocale();
        $phaseMode = $this->phaseMode();

        return $this->memo->remember(
            "menu.visible.{$locale}.{$routeName}.{$userKey}.{$phaseMode}",
            fn (): array => $this->markActive(
                $this->filterByPermissions($this->loadMenu(), $user)
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function structure(): array
    {
        return $this->loadMenu();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function permissionStructure(): array
    {
        $locale = app()->getLocale();

        return $this->memo->remember(
            "menu.permissions.structure.{$locale}",
            fn (): array => array_map(
                fn (array $item): array => $this->normalizeItem($item),
                $this->domainMenuItems(includeExpanded: true),
            ),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function filterByPermissions(array $items, ?User $user): array
    {
        $visibleItems = [];

        foreach ($items as $item) {
            $item = $this->normalizeItem($item);

            if (! $item['visible']) {
                continue;
            }

            if (! $this->isVisibleInCurrentPhase($item)) {
                continue;
            }

            if (! $this->canView($item, $user)) {
                continue;
            }

            $item['children'] = $this->filterByPermissions($item['children'], $user);

            if ($item['route'] === null && $item['children'] === []) {
                continue;
            }

            $visibleItems[] = $item;
        }

        return array_values($visibleItems);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function markActive(array $items): array
    {
        return array_map(function (array $item): array {
            $item['children'] = $this->markActive($item['children']);

            $isActive = $this->isItemActive($item);
            $hasActiveChild = collect($item['children'])->contains(fn (array $child): bool => (bool) ($child['active'] ?? false) || (bool) ($child['open'] ?? false));

            $item['active'] = $isActive || $hasActiveChild;
            $item['open'] = $hasActiveChild;

            return $item;
        }, $items);
    }

    public function isItemActive(array $item): bool
    {
        $routeName = request()->route()?->getName();

        if (! $routeName) {
            return false;
        }

        $patterns = $item['active_patterns'] ?? [];

        if ($patterns === [] && $item['route']) {
            $patterns[] = $item['route'];
        }

        foreach ($patterns as $pattern) {
            if (! Str::is($pattern, $routeName)) {
                continue;
            }

            return $this->routeParametersMatch($item);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function routeParametersMatch(array $item): bool
    {
        $expected = $item['route_params'] ?? [];

        if (! is_array($expected) || $expected === []) {
            return true;
        }

        $actual = request()->route()?->parameters() ?? [];

        foreach ($expected as $key => $value) {
            $actualValue = $actual[$key] ?? null;

            if (is_object($actualValue) && method_exists($actualValue, 'getRouteKey')) {
                $actualValue = $actualValue->getRouteKey();
            }

            if ((string) $actualValue !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    public function urlFor(array $item): string
    {
        $route = $item['route'] ?? null;

        if (! is_string($route) || $route === '' || ! Route::has($route)) {
            return '#!';
        }

        $parameters = is_array($item['route_params'] ?? null) ? $item['route_params'] : [];

        return route($route, $parameters);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadMenu(): array
    {
        $phaseMode = $this->phaseMode();
        $locale = app()->getLocale();

        return $this->memo->remember("menu.config.normalized.{$locale}.{$phaseMode}", function () use ($phaseMode): array {
            $items = $this->domainMenuItems(includeExpanded: $phaseMode === 'expanded');

            return array_map(fn (array $item): array => $this->normalizeItem($item), $items);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function domainMenuItems(bool $includeExpanded): array
    {
        $items = $this->menuConfigItems();

        if ($includeExpanded) {
            $items = array_merge($items, $this->erpUiScreens->menuItems());
        }

        return $this->organizeByDomain($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function organizeByDomain(array $items): array
    {
        $order = config('menu_sections.order', []);
        $definitions = config('menu_sections.sections', []);

        if (! is_array($order) || ! is_array($definitions)) {
            throw new LogicException('The menu section registry is invalid.');
        }

        $sections = [];

        foreach ($order as $label) {
            if (! is_string($label) || $label === '' || $label === 'dashboard') {
                continue;
            }

            $definition = is_array($definitions[$label] ?? null) ? $definitions[$label] : [];
            $sections[$label] = [
                'label' => $label,
                'title' => Str::headline($label),
                'icon' => (string) ($definition['icon'] ?? 'folder'),
                'route' => null,
                'permission' => null,
                'active' => [],
                'keywords' => [],
                'children' => [],
            ];
        }

        foreach ($items as $item) {
            if (($item['label'] ?? null) !== 'human_resources') {
                continue;
            }

            if (array_key_exists('hidden', $item)) {
                $sections['human_resources']['hidden'] = (bool) $item['hidden'];
            }

            if (array_key_exists('visible', $item)) {
                $sections['human_resources']['visible'] = (bool) $item['visible'];
            }
        }

        $dashboard = null;

        foreach ($items as $item) {
            $this->distributeItem(
                item: $item,
                inheritedSection: null,
                sections: $sections,
                dashboard: $dashboard,
            );
        }

        $organized = [];

        foreach ($order as $label) {
            if ($label === 'dashboard') {
                if (is_array($dashboard)) {
                    $organized[] = $dashboard;
                }

                continue;
            }

            if (isset($sections[$label]) && $sections[$label]['children'] !== []) {
                $organized[] = $sections[$label];
            }
        }

        $seenRoutes = [];

        return $this->deduplicateRoutes($organized, $seenRoutes);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $sections
     * @param  array<string, mixed>|null  $dashboard
     */
    private function distributeItem(
        array $item,
        ?string $inheritedSection,
        array &$sections,
        ?array &$dashboard,
        bool $ancestorHidden = false,
        bool $ancestorVisible = true,
        array $ancestorPhaseModes = [],
    ): void {
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        $section = $this->sectionFor($label, $children === [], $inheritedSection);
        $hidden = $ancestorHidden || (bool) ($item['hidden'] ?? false);
        $visible = $ancestorVisible && (bool) ($item['visible'] ?? ! $hidden);
        $phaseModes = $this->combinedPhaseModes($ancestorPhaseModes, $item);

        if ($children !== []) {
            foreach ($children as $child) {
                if (! is_array($child)) {
                    continue;
                }

                $this->distributeItem(
                    item: $child,
                    inheritedSection: $section,
                    sections: $sections,
                    dashboard: $dashboard,
                    ancestorHidden: $hidden,
                    ancestorVisible: $visible,
                    ancestorPhaseModes: $phaseModes,
                );
            }

            return;
        }

        $route = $item['route'] ?? null;

        if (! is_string($route) || $route === '') {
            return;
        }

        if ($section === null || $section === '') {
            throw new LogicException("Menu entry [{$label}] does not belong to a registered business domain.");
        }

        $item['hidden'] = $hidden;
        $item['visible'] = $visible && ! $hidden;
        $item['phase_modes'] = $phaseModes;
        $item['children'] = [];

        if ($section === 'dashboard') {
            $dashboard = $item;

            return;
        }

        if (! isset($sections[$section])) {
            throw new LogicException("Menu entry [{$label}] targets unknown business domain [{$section}].");
        }

        $sections[$section]['children'][] = $item;
    }

    private function sectionFor(string $label, bool $isLeaf, ?string $inheritedSection): ?string
    {
        $mappingKey = $isLeaf ? 'leaf_sections' : 'container_sections';
        $mapping = config("menu_sections.{$mappingKey}", []);

        if (is_array($mapping) && array_key_exists($label, $mapping)) {
            return is_string($mapping[$label]) ? $mapping[$label] : null;
        }

        $sourceSections = config('menu_sections.source_sections', []);

        if (is_array($sourceSections) && array_key_exists($label, $sourceSections)) {
            return is_string($sourceSections[$label]) ? $sourceSections[$label] : null;
        }

        return $inheritedSection;
    }

    /**
     * @param  list<string>  $ancestorPhaseModes
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function combinedPhaseModes(array $ancestorPhaseModes, array $item): array
    {
        $itemPhaseModes = $this->phaseModesFor($item);

        if ($ancestorPhaseModes === []) {
            return $itemPhaseModes;
        }

        if ($itemPhaseModes === []) {
            return $ancestorPhaseModes;
        }

        return array_values(array_intersect($ancestorPhaseModes, $itemPhaseModes));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, true>  $seenRoutes
     * @return list<array<string, mixed>>
     */
    private function deduplicateRoutes(array $items, array &$seenRoutes): array
    {
        $deduplicated = [];

        foreach ($items as $item) {
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            $item['children'] = $this->deduplicateRoutes($children, $seenRoutes);
            $route = $item['route'] ?? null;

            if (is_string($route) && $route !== '') {
                $parameters = is_array($item['route_params'] ?? null) ? $item['route_params'] : [];
                $fingerprint = $route.'|'.json_encode($parameters);

                if (isset($seenRoutes[$fingerprint])) {
                    continue;
                }

                $seenRoutes[$fingerprint] = true;
            }

            if ($route === null && $item['children'] === []) {
                continue;
            }

            $deduplicated[] = $item;
        }

        return array_values($deduplicated);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function menuConfigItems(): array
    {
        $items = [];

        foreach ($this->menuFiles->files() as $file) {
            $moduleItems = require $file;

            if (is_array($moduleItems)) {
                $items = array_merge($items, $moduleItems);
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $label = (string) ($item['label'] ?? '');
        $children = $item['children'] ?? [];
        $activePatterns = $item['active_patterns'] ?? $item['active'] ?? [];

        $item['label'] = $label;
        $item['title'] = (string) ($item['title'] ?? Str::headline($label));
        $item['icon'] = (string) ($item['icon'] ?? 'circle');
        $item['icon_class'] = $this->iconClass($item['icon']);
        $item['route'] = $item['route'] ?? null;
        $item['url'] = $this->urlFor($item);
        $item['permission'] = $item['permission'] ?? null;
        $item['hidden'] = (bool) ($item['hidden'] ?? false);
        $item['visible'] = (bool) ($item['visible'] ?? ! $item['hidden']);
        $item['phase_modes'] = $this->phaseModesFor($item);
        $item['active_patterns'] = is_array($activePatterns) ? $activePatterns : [];
        $item['actions'] = $item['actions'] ?? [];
        $item['children'] = is_array($children) ? array_map(fn (array $child): array => $this->normalizeItem($child), $children) : [];
        $item['text'] = $this->labelFor($item);
        $item['active'] = false;
        $item['open'] = false;

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isVisibleInCurrentPhase(array $item): bool
    {
        $phaseModes = $item['phase_modes'] ?? [];

        if (! is_array($phaseModes) || $phaseModes === []) {
            return true;
        }

        return in_array($this->phaseMode(), $phaseModes, true);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function phaseModesFor(array $item): array
    {
        $phaseModes = $item['phase_modes'] ?? $item['phase'] ?? null;

        if ($phaseModes === null || $phaseModes === '' || $phaseModes === 'all') {
            return [];
        }

        if (is_string($phaseModes)) {
            $phaseModes = [$phaseModes];
        }

        if (! is_array($phaseModes)) {
            return [];
        }

        return collect($phaseModes)
            ->filter(fn (mixed $phaseMode): bool => is_string($phaseMode))
            ->map(fn (string $phaseMode): string => strtolower(trim($phaseMode)))
            ->filter(fn (string $phaseMode): bool => $phaseMode !== '' && in_array($phaseMode, $this->allowedPhaseModes(), true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function allowedPhaseModes(): array
    {
        $phaseModes = config('erp.phase_modes', ['legacy', 'expanded']);

        if (! is_array($phaseModes)) {
            return ['legacy', 'expanded'];
        }

        $normalized = collect($phaseModes)
            ->filter(fn (mixed $phaseMode): bool => is_string($phaseMode))
            ->map(fn (string $phaseMode): string => strtolower(trim($phaseMode)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $normalized === [] ? ['legacy', 'expanded'] : $normalized;
    }

    private function phaseMode(): string
    {
        $phaseMode = strtolower(trim((string) config('erp.phase_mode', 'legacy')));

        return in_array($phaseMode, $this->allowedPhaseModes(), true) ? $phaseMode : 'legacy';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function canView(array $item, ?User $user): bool
    {
        $permission = $item['permission'] ?? null;

        if ($permission === null || $permission === '') {
            return true;
        }

        if (! $user) {
            return false;
        }

        try {
            if (is_array($permission)) {
                return collect($permission)
                    ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                    ->contains(fn (string $name): bool => $user->can(trim($name)));
            }

            return $user->can($permission);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function labelFor(array $item): string
    {
        $label = (string) ($item['label'] ?? '');
        $translationKey = "menu.{$label}";

        if ($label !== '' && __($translationKey) !== $translationKey) {
            return __($translationKey);
        }

        return (string) ($item['title'] ?? Str::headline($label));
    }

    private function iconClass(string $icon): string
    {
        return match ($icon) {
            'auth_logs', 'clock' => 'fas fa-clock',
            'dashboard', 'home' => 'fas fa-home',
            'key', 'permissions' => 'fas fa-key',
            'roles', 'shield' => 'fas fa-shield-alt',
            'settings', 'administration' => 'fas fa-cog',
            'users' => 'fas fa-users',
            default => "fas fa-{$icon}",
        };
    }
}
