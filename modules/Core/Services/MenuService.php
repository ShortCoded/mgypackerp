<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class MenuService
{
    public function __construct(
        private readonly RequestMemo $memo,
        private readonly MenuConfigFileOrder $menuFiles,
        private readonly ExpandedScreenRegistry $expandedScreens,
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
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    public function urlFor(array $item): string
    {
        $route = $item['route'] ?? null;

        if (! is_string($route) || $route === '' || ! Route::has($route)) {
            return '#!';
        }

        return route($route);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadMenu(): array
    {
        $phaseMode = $this->phaseMode();

        return $this->memo->remember("menu.config.normalized.{$phaseMode}", function () use ($phaseMode): array {
            $items = $phaseMode === 'expanded' ? $this->expandedScreens->menuItems() : $this->menuConfigItems();

            return array_map(fn (array $item): array => $this->normalizeItem($item), $items);
        });
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
