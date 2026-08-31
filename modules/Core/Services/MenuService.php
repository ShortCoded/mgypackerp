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
                $this->filterByPermissions($this->nestNavigationItems($this->loadMenu()), $user)
            )
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function nestNavigationItems(array $items): array
    {
        $configuredChildren = config('menu_sections.navigation_children', []);

        if (! is_array($configuredChildren) || $configuredChildren === []) {
            return $items;
        }

        $itemsByLabel = [];
        $itemLabels = [];

        foreach ($items as $item) {
            $label = is_string($item['label'] ?? null) ? $item['label'] : '';

            if ($label === '') {
                continue;
            }

            $itemsByLabel[$label] = $item;
            $itemLabels[] = $label;
        }

        $childrenByParent = [];
        $parentByChild = [];

        foreach ($configuredChildren as $parentLabel => $childLabels) {
            if (! is_string($parentLabel) || ! isset($itemsByLabel[$parentLabel]) || ! is_array($childLabels)) {
                continue;
            }

            foreach ($childLabels as $childLabel) {
                if (! is_string($childLabel) || ! isset($itemsByLabel[$childLabel])) {
                    continue;
                }

                if ($childLabel === $parentLabel) {
                    throw new LogicException("Navigation item [{$childLabel}] cannot be its own parent.");
                }

                if (isset($parentByChild[$childLabel]) && $parentByChild[$childLabel] !== $parentLabel) {
                    throw new LogicException("Navigation item [{$childLabel}] has multiple configured parents.");
                }

                $childrenByParent[$parentLabel][] = $childLabel;
                $parentByChild[$childLabel] = $parentLabel;
            }
        }

        $buildItem = function (string $label, array $ancestors = []) use (&$buildItem, $childrenByParent, $itemsByLabel): array {
            if (in_array($label, $ancestors, true)) {
                throw new LogicException("Circular navigation hierarchy detected at [{$label}].");
            }

            $item = $itemsByLabel[$label];

            foreach ($childrenByParent[$label] ?? [] as $childLabel) {
                $item['children'][] = $buildItem($childLabel, [...$ancestors, $label]);
            }

            return $item;
        };

        $nested = [];

        foreach ($itemLabels as $label) {
            if (isset($parentByChild[$label])) {
                continue;
            }

            $nested[] = $buildItem($label);
        }

        return $nested;
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
                'key' => $label,
                'title' => Str::headline($label),
                'icon' => (string) ($definition['icon'] ?? 'folder'),
                'route' => null,
                'permission' => null,
                'active' => [],
                'keywords' => [],
                'children' => [],
                'subgroups' => [],
            ];
        }

        $dashboard = null;

        foreach ($items as $item) {
            $this->distributeItem(
                item: $item,
                inheritedSection: null,
                inheritedSubgroup: null,
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

            if (isset($sections[$label]) && ($sections[$label]['children'] !== [] || $sections[$label]['subgroups'] !== [])) {
                $organized[] = $this->finalizeSection($sections[$label]);
            }
        }

        $seenDestinations = [];

        return $this->deduplicateRoutes($organized, $seenDestinations);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $sections
     * @param  array<string, mixed>|null  $dashboard
     */
    private function distributeItem(
        array $item,
        ?string $inheritedSection,
        ?string $inheritedSubgroup,
        array &$sections,
        ?array &$dashboard,
        bool $ancestorHidden = false,
        bool $ancestorVisible = true,
        array $ancestorPhaseModes = [],
    ): void {
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        $section = $this->sectionFor($label, $children === [], $inheritedSection);
        $subgroup = $children === []
            ? $this->leafSubgroupFor($item, $inheritedSubgroup)
            : $this->containerSubgroupFor($item, $inheritedSubgroup);
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
                    inheritedSubgroup: $subgroup,
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

        $this->appendToSection($sections[$section], $item, $subgroup);
    }

    private function sectionFor(string $label, bool $isLeaf, ?string $inheritedSection): ?string
    {
        $leafSections = config('menu_sections.leaf_sections', []);

        if ($isLeaf && is_array($leafSections) && array_key_exists($label, $leafSections)) {
            return is_string($leafSections[$label]) ? $leafSections[$label] : null;
        }

        $sourceSections = config('menu_sections.source_sections', []);

        if (is_array($sourceSections) && array_key_exists($label, $sourceSections)) {
            return is_string($sourceSections[$label]) ? $sourceSections[$label] : null;
        }

        return $inheritedSection;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function leafSubgroupFor(array $item, ?string $inheritedSubgroup): ?string
    {
        $subgroup = $item['subgroup'] ?? null;

        if (is_string($subgroup) && $subgroup !== '') {
            return $subgroup;
        }

        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        $leafSubgroups = config('menu_sections.leaf_subgroups', []);

        if (is_array($leafSubgroups) && array_key_exists($label, $leafSubgroups)) {
            return is_string($leafSubgroups[$label]) ? $leafSubgroups[$label] : null;
        }

        return $inheritedSubgroup;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function containerSubgroupFor(array $item, ?string $inheritedSubgroup): ?string
    {
        $subgroup = $item['subgroup'] ?? null;

        if (is_string($subgroup) && $subgroup !== '') {
            return $subgroup;
        }

        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        $sourceSubgroups = config('menu_sections.source_subgroups', []);

        if (is_array($sourceSubgroups) && array_key_exists($label, $sourceSubgroups)) {
            return is_string($sourceSubgroups[$label]) ? $sourceSubgroups[$label] : null;
        }

        return $inheritedSubgroup;
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $item
     */
    private function appendToSection(array &$section, array $item, ?string $subgroup): void
    {
        if ($subgroup === null || $subgroup === '') {
            $section['children'][] = $item;

            return;
        }

        $definitions = config('menu_sections.subgroups', []);
        $definition = is_array($definitions[$subgroup] ?? null) ? $definitions[$subgroup] : null;

        if ($definition === null) {
            throw new LogicException("Menu subgroup [{$subgroup}] is not registered.");
        }

        $domain = is_string($definition['domain'] ?? null) ? $definition['domain'] : null;

        if ($domain !== $section['label']) {
            throw new LogicException("Menu subgroup [{$subgroup}] does not belong to business domain [{$section['label']}].");
        }

        if (! isset($section['subgroups'][$subgroup])) {
            $section['subgroups'][$subgroup] = [
                'label' => $subgroup,
                'key' => $subgroup,
                'title' => Str::headline($subgroup),
                'icon' => (string) ($definition['icon'] ?? 'folder-open'),
                'route' => null,
                'permission' => null,
                'active' => [],
                'keywords' => [],
                'children' => [],
                'order' => (int) ($definition['order'] ?? 999),
            ];
        }

        $section['subgroups'][$subgroup]['children'][] = $item;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function finalizeSection(array $section): array
    {
        $subgroups = array_values($section['subgroups']);

        usort($subgroups, fn (array $first, array $second): int => [
            (int) ($first['order'] ?? 999),
            (string) ($first['key'] ?? ''),
        ] <=> [
            (int) ($second['order'] ?? 999),
            (string) ($second['key'] ?? ''),
        ]);

        $section['children'] = [...$section['children'], ...$subgroups];
        unset($section['subgroups']);

        return $section;
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
     * @param  array<string, true>  $seenDestinations
     * @return list<array<string, mixed>>
     */
    private function deduplicateRoutes(array $items, array &$seenDestinations): array
    {
        $deduplicated = [];

        foreach ($items as $item) {
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            $item['children'] = $this->deduplicateRoutes($children, $seenDestinations);
            $route = $item['route'] ?? null;

            if (is_string($route) && $route !== '') {
                $parameters = is_array($item['route_params'] ?? null) ? $item['route_params'] : [];
                $routeFingerprint = 'route:'.$route.'|'.json_encode($parameters);
                $urlFingerprint = 'url:'.$this->normalizedUrlFor($item);

                if (isset($seenDestinations[$routeFingerprint]) || isset($seenDestinations[$urlFingerprint])) {
                    continue;
                }

                $seenDestinations[$routeFingerprint] = true;
                $seenDestinations[$urlFingerprint] = true;
            }

            if ($route === null && $item['children'] === []) {
                continue;
            }

            $deduplicated[] = $item;
        }

        return array_values($deduplicated);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalizedUrlFor(array $item): string
    {
        $url = $this->urlFor($item);
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $normalizedPath = is_string($path) && $path !== '' ? '/'.ltrim($path, '/') : '/';

        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        if (! is_string($query) || $query === '') {
            return $normalizedPath;
        }

        parse_str($query, $parameters);
        ksort($parameters);

        return $normalizedPath.'?'.http_build_query($parameters);
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
        $key = is_string($item['key'] ?? null) && $item['key'] !== '' ? $item['key'] : $label;
        $children = $item['children'] ?? [];
        $activePatterns = $item['active_patterns'] ?? $item['active'] ?? [];

        $item['label'] = $label;
        $item['key'] = $key;
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
            $permissionNames = $this->memo->remember(
                'menu.user.permissions.'.(string) $user->getKey(),
                fn (): array => $user->getAllPermissions()
                    ->pluck('name')
                    ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                    ->mapWithKeys(fn (string $name): array => [trim($name) => true])
                    ->all(),
            );

            if (is_array($permission)) {
                return collect($permission)
                    ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                    ->contains(fn (string $name): bool => isset($permissionNames[trim($name)]));
            }

            return isset($permissionNames[$permission]);
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
