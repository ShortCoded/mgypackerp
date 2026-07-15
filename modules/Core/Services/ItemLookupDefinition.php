<?php

namespace Modules\Core\Services;

use Modules\Core\Models\ItemLookup;

/**
 * @param  class-string<ItemLookup>  $modelClass
 */
final readonly class ItemLookupDefinition
{
    public function __construct(
        public string $key,
        public string $routeKey,
        public string $documentKey,
        public string $permissionPrefix,
        public string $table,
        public string $modelClass,
        public string $translationKey,
        public string $viewPath,
        public string $jsNamespace,
        public ?string $routeNamePrefix = null,
    ) {}

    public function permission(string $action): string
    {
        return "{$this->permissionPrefix}.{$action}";
    }

    public function route(string $action): string
    {
        return "admin.{$this->routeNamePrefix()}.{$action}";
    }

    public function activity(string $action): string
    {
        return "{$this->permissionPrefix}.{$action}";
    }

    public function routeNamePrefix(): string
    {
        return $this->routeNamePrefix ?? $this->routeKey;
    }
}
