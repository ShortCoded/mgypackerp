<?php

namespace Modules\Core\Support;

final class MvpMenu
{
    public const PlaceholderPermission = 'mvp.placeholders.view';

    /**
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    public static function placeholder(
        string $label,
        string $module,
        string $screen,
        string $icon,
        array $keywords = [],
        string $type = 'screen',
    ): array {
        return [
            'label' => $label,
            'title' => str($label)->replace('_', ' ')->headline()->toString(),
            'icon' => $icon,
            'route' => 'admin.mvp.placeholder',
            'route_params' => [
                'module' => $module,
                'screen' => $screen,
            ],
            'permission' => self::PlaceholderPermission,
            'keywords' => $keywords,
            'placeholder_type' => $type,
            'children' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function crudPermissions(string $prefix): array
    {
        $key = str($prefix)->replace('.', '_')->toString();

        return [
            "{$key}_view" => "{$prefix}.view",
            "{$key}_create" => "{$prefix}.create",
            "{$key}_clone" => "{$prefix}.clone",
            "{$key}_edit" => "{$prefix}.edit",
            "{$key}_delete" => "{$prefix}.delete",
            "{$key}_view_trashed" => "{$prefix}.view_trashed",
            "{$key}_restore" => "{$prefix}.restore",
            "{$key}_document_number_control" => "{$prefix}.document_number.control",
            "{$key}_document_number_settings_update" => "{$prefix}.document_number_settings.update",
        ];
    }

    /**
     * @param  list<string>  $prefixes
     * @return array<string, string>
     */
    public static function hiddenCrudPermissions(array $prefixes): array
    {
        $permissions = [];

        foreach ($prefixes as $prefix) {
            $permissions = [
                ...$permissions,
                ...self::crudPermissions($prefix),
            ];
        }

        return $permissions;
    }
}
