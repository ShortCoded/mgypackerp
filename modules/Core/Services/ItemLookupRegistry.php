<?php

namespace Modules\Core\Services;

use InvalidArgumentException;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemGroup;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemSize;
use Modules\Core\Models\ItemUnit;

class ItemLookupRegistry
{
    /**
     * @return array<string, ItemLookupDefinition>
     */
    public function all(): array
    {
        return [
            'item_units' => new ItemLookupDefinition(
                key: 'item_units',
                routeKey: 'item-units',
                documentKey: 'item_units',
                permissionPrefix: 'item_units',
                table: 'item_units',
                modelClass: ItemUnit::class,
                translationKey: 'item_units',
                viewPath: 'modules.core.item-lookups',
                jsNamespace: 'itemUnits',
            ),
            'item_sizes' => new ItemLookupDefinition(
                key: 'item_sizes',
                routeKey: 'item-sizes',
                documentKey: 'item_sizes',
                permissionPrefix: 'item_sizes',
                table: 'item_sizes',
                modelClass: ItemSize::class,
                translationKey: 'item_sizes',
                viewPath: 'modules.core.item-lookups',
                jsNamespace: 'itemSizes',
            ),
            'item_colors' => new ItemLookupDefinition(
                key: 'item_colors',
                routeKey: 'item-colors',
                documentKey: 'item_colors',
                permissionPrefix: 'item_colors',
                table: 'item_colors',
                modelClass: ItemColor::class,
                translationKey: 'item_colors',
                viewPath: 'modules.core.item-lookups',
                jsNamespace: 'itemColors',
            ),
            'item_decals' => new ItemLookupDefinition(
                key: 'item_decals',
                routeKey: 'item-decals',
                documentKey: 'item_decals',
                permissionPrefix: 'item_decals',
                table: 'item_decals',
                modelClass: ItemDecal::class,
                translationKey: 'item_decals',
                viewPath: 'modules.core.item-lookups',
                jsNamespace: 'itemDecals',
            ),
            'item_models' => new ItemLookupDefinition(
                key: 'item_models',
                routeKey: 'item-models',
                documentKey: 'item_models',
                permissionPrefix: 'item_models',
                table: 'item_models',
                modelClass: ItemModel::class,
                translationKey: 'item_models',
                viewPath: 'modules.core.item-lookups',
                jsNamespace: 'itemModels',
            ),
            'item_categories' => new ItemLookupDefinition(
                key: 'item_categories',
                routeKey: 'item-categories',
                documentKey: 'item_categories',
                permissionPrefix: 'item_categories',
                table: 'item_categories',
                modelClass: ItemCategory::class,
                translationKey: 'item_categories',
                viewPath: 'modules.core.item-lookups',
                jsNamespace: 'itemCategories',
            ),
            'item_groups' => new ItemLookupDefinition(
                key: 'item_groups',
                routeKey: 'item-groups',
                documentKey: 'item_groups',
                permissionPrefix: 'item_groups',
                table: 'item_groups',
                modelClass: ItemGroup::class,
                translationKey: 'item_groups',
                viewPath: 'modules.core.item-lookups',
                jsNamespace: 'itemGroups',
            ),
            'item_origin_countries' => new ItemLookupDefinition(
                key: 'item_origin_countries',
                routeKey: 'item-origin-countries',
                documentKey: 'item_origin_countries',
                permissionPrefix: 'item_origin_countries',
                table: 'item_origin_countries',
                modelClass: ItemOriginCountry::class,
                translationKey: 'item_origin_countries',
                viewPath: 'modules.core.item-lookups',
                jsNamespace: 'itemOriginCountries',
            ),
        ];
    }

    public function get(string $key): ItemLookupDefinition
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown item lookup [{$key}].");
    }

    public function fromRouteName(?string $routeName): ItemLookupDefinition
    {
        $routeName = (string) $routeName;

        foreach ($this->all() as $definition) {
            if (str_contains($routeName, "admin.{$definition->routeNamePrefix()}.")) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unable to resolve item lookup from route [{$routeName}].");
    }
}
