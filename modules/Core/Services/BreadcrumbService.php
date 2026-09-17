<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class BreadcrumbService
{
    public function __construct(
        private readonly MenuService $menu,
    ) {}

    /**
     * @param  array<int, array{label: string, route?: string|null, url?: string|null, active?: bool}>  $extra
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    public function forMenuRoute(string $routeName, array $extra = []): array
    {
        $breadcrumbs = $this->menuTrail($routeName);

        foreach ($extra as $item) {
            $breadcrumbs[] = [
                'label' => $item['label'],
                'url' => $item['url'] ?? $this->routeUrl($item['route'] ?? null),
                'active' => (bool) ($item['active'] ?? false),
            ];
        }

        if ($breadcrumbs !== []) {
            $lastKey = array_key_last($breadcrumbs);
            $breadcrumbs[$lastKey]['active'] = true;
            $breadcrumbs[$lastKey]['url'] = null;
        }

        return $breadcrumbs;
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function menuTrail(string $routeName): array
    {
        $dashboard = null;
        $targetTrail = [];

        foreach ($this->loadMenu() as $item) {
            $trail = $this->findTrail($item, $routeName);

            if (($item['route'] ?? null) === 'dashboard') {
                $dashboard = $this->breadcrumbItem($item);
            }

            if ($trail !== []) {
                $targetTrail = $trail;
            }
        }

        if ($dashboard !== null && $routeName !== 'dashboard') {
            $targetTrail = array_values(array_filter(
                $targetTrail,
                fn (array $item): bool => ($item['route'] ?? null) !== 'dashboard',
            ));

            array_unshift($targetTrail, $dashboard);
        }

        return array_map(fn (array $item): array => $this->breadcrumbItem($item), $targetTrail);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, array<string, mixed>>
     */
    private function findTrail(array $item, string $routeName): array
    {
        foreach (($item['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $childTrail = $this->findTrail($child, $routeName);

            if ($childTrail !== []) {
                return array_merge([$item], $childTrail);
            }
        }

        if ($this->matches($item, $routeName)) {
            return [$item];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matches(array $item, string $routeName): bool
    {
        if (($item['route'] ?? null) === $routeName) {
            return true;
        }

        $patterns = $item['active_patterns'] ?? $item['active'] ?? [];

        if (! is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{label: string, url: string|null, active: bool}
     */
    private function breadcrumbItem(array $item): array
    {
        return [
            'label' => $this->labelFor($item),
            'url' => $this->routeUrl($item['route'] ?? null),
            'active' => false,
        ];
    }

    private function routeUrl(mixed $route): ?string
    {
        if (! is_string($route) || $route === '' || ! Route::has($route)) {
            return null;
        }

        return route($route);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function labelFor(array $item): string
    {
        $label = (string) ($item['label'] ?? '');
        $breadcrumbKey = "breadcrumb.{$label}";
        $translationKey = "menu.{$label}";

        if ($label !== '' && __($breadcrumbKey) !== $breadcrumbKey) {
            return __($breadcrumbKey);
        }

        if ($label !== '' && __($translationKey) !== $translationKey) {
            return __($translationKey);
        }

        return (string) ($item['text'] ?? $item['title'] ?? Str::headline($label));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadMenu(): array
    {
        return $this->menu->navigationStructure();
    }
}
