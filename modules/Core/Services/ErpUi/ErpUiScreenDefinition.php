<?php

namespace Modules\Core\Services\ErpUi;

use Illuminate\Support\Arr;

final class ErpUiScreenDefinition
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly array $attributes,
    ) {}

    public function key(): string
    {
        return (string) $this->attributes['key'];
    }

    public function module(): string
    {
        return (string) $this->attributes['module'];
    }

    public function kind(): string
    {
        return (string) ($this->attributes['kind'] ?? 'resource');
    }

    public function routePath(): string
    {
        return (string) $this->attributes['route_path'];
    }

    public function routeNamePrefix(): string
    {
        return (string) $this->attributes['route_name_prefix'];
    }

    public function route(string $action = 'index'): string
    {
        return $this->routeNamePrefix().'.'.$action;
    }

    public function permissionPrefix(): string
    {
        return (string) $this->attributes['permission_prefix'];
    }

    public function permission(string $action): string
    {
        return $this->permissionPrefix().'.'.$action;
    }

    public function hasAction(string $action): bool
    {
        return in_array($action, $this->actions(), true);
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_values(array_filter(
            $this->attributes['actions'] ?? [],
            fn (mixed $action): bool => is_string($action) && $action !== '',
        ));
    }

    public function supportsMode(string $mode): bool
    {
        return in_array($mode, $this->attributes['modes'] ?? [], true);
    }

    public function title(?string $locale = null): string
    {
        return $this->localized($this->attributes['title'] ?? null, $locale);
    }

    public function localized(mixed $value, ?string $locale = null): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $locale ??= app()->getLocale();

        return (string) ($value[$locale] ?? $value['en'] ?? $value['ar'] ?? '');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->attributes, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
