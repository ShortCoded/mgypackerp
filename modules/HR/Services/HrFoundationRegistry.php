<?php

namespace Modules\HR\Services;

use InvalidArgumentException;

class HrFoundationRegistry
{
    /**
     * @return array<string, HrFoundationDefinition>
     */
    public function all(): array
    {
        return array_map(
            fn (HrFoundationDefinition $definition): HrFoundationDefinition => $this->withoutCode($definition),
            HrEnterpriseFoundationDefinitions::definitions(),
        );
    }

    public function get(string $key): HrFoundationDefinition
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown HR foundation resource [{$key}].");
    }

    public function fromRouteName(?string $routeName): HrFoundationDefinition
    {
        $routeName = (string) $routeName;

        foreach ($this->all() as $definition) {
            if (str_contains($routeName, "admin.hr.{$definition->routeKey}.")) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unable to resolve HR foundation resource from route [{$routeName}].");
    }

    private function withoutCode(HrFoundationDefinition $definition): HrFoundationDefinition
    {
        return new HrFoundationDefinition(
            key: $definition->key,
            routeKey: $definition->routeKey,
            documentKey: $definition->documentKey,
            permissionPrefix: $definition->permissionPrefix,
            table: $definition->table,
            modelClass: $definition->modelClass,
            translationKey: $definition->translationKey,
            fields: array_values(array_filter(
                $definition->fields,
                fn (array $field): bool => ($field['name'] ?? null) !== 'code',
            )),
            jsNamespace: $definition->jsNamespace,
            tableColumns: array_values(array_filter(
                $definition->tableColumns,
                fn (array $column): bool => ($column['name'] ?? null) !== 'code',
            )),
            companyScoped: $definition->companyScoped,
            hasTaxBrackets: $definition->hasTaxBrackets,
            hasInsuranceComponents: $definition->hasInsuranceComponents,
        );
    }
}
