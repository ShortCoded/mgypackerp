<?php

namespace Modules\Core\Services;

use Illuminate\Support\Str;

class MvpPlaceholderService
{
    public function __construct(
        private readonly RequestMemo $memo,
        private readonly MenuConfigFileOrder $menuFiles,
    ) {}

    /**
     * @return array{module_label: string, screen_label: string, type: string, icon: string}|null
     */
    public function find(string $module, string $screen): ?array
    {
        $trail = $this->findTrail([
            'module' => $module,
            'screen' => $screen,
        ]);

        if ($trail === []) {
            return null;
        }

        $screenItem = $trail[array_key_last($trail)];
        $moduleItem = $trail[0];

        return [
            'module_label' => $this->labelFor($moduleItem),
            'screen_label' => $this->labelFor($screenItem),
            'type' => (string) ($screenItem['placeholder_type'] ?? 'screen'),
            'icon' => (string) ($screenItem['icon'] ?? $moduleItem['icon'] ?? 'clipboard-list'),
        ];
    }

    /**
     * @param  array{module: string, screen: string}  $parameters
     * @return list<array<string, mixed>>
     */
    private function findTrail(array $parameters): array
    {
        foreach ($this->menuItems() as $item) {
            $trail = $this->findItemTrail($item, $parameters);

            if ($trail !== []) {
                return $trail;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{module: string, screen: string}  $parameters
     * @return list<array<string, mixed>>
     */
    private function findItemTrail(array $item, array $parameters): array
    {
        foreach (($item['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $childTrail = $this->findItemTrail($child, $parameters);

            if ($childTrail !== []) {
                return [$item, ...$childTrail];
            }
        }

        if (($item['route'] ?? null) !== 'admin.mvp.placeholder') {
            return [];
        }

        $routeParameters = $item['route_params'] ?? [];

        if (! is_array($routeParameters)) {
            return [];
        }

        return ($routeParameters['module'] ?? null) === $parameters['module']
            && ($routeParameters['screen'] ?? null) === $parameters['screen']
                ? [$item]
                : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function menuItems(): array
    {
        return $this->memo->remember('mvp.placeholders.menu_items', function (): array {
            $items = [];

            foreach ($this->menuFiles->files() as $file) {
                $moduleItems = require $file;

                if (is_array($moduleItems)) {
                    $items = array_merge($items, $moduleItems);
                }
            }

            return $items;
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function labelFor(array $item): string
    {
        $label = (string) ($item['label'] ?? '');
        $translationKey = "menu.{$label}";

        if ($label !== '' && trans()->has($translationKey)) {
            return __($translationKey);
        }

        return (string) ($item['title'] ?? Str::headline($label));
    }
}
