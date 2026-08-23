<?php

namespace Modules\HR\Services;

use Modules\HR\Models\HrFoundationModel;

/**
 * @param  class-string<HrFoundationModel>  $modelClass
 */
final readonly class HrFoundationDefinition
{
    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $tableColumns
     */
    public function __construct(
        public string $key,
        public string $routeKey,
        public string $documentKey,
        public string $permissionPrefix,
        public string $table,
        public string $modelClass,
        public string $translationKey,
        public array $fields,
        public string $jsNamespace,
        public array $tableColumns = [],
        public bool $companyScoped = false,
        public bool $hasTaxBrackets = false,
        public bool $hasInsuranceComponents = false,
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
