<?php

namespace Modules\HR\Services;

use Modules\HR\Models\HrLookupModel;

/**
 * @param class-string<HrLookupModel> $modelClass
 */
final readonly class HrLookupDefinition
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
    ) {}

    public function permission(string $action): string
    {
        return "{$this->permissionPrefix}.{$action}";
    }

    public function route(string $action): string
    {
        return "admin.hr.{$this->routeKey}.{$action}";
    }

    public function activity(string $action): string
    {
        return "{$this->permissionPrefix}.{$action}";
    }
}
