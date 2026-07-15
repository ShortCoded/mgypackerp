<?php

namespace Modules\Core\Services\Generators;

use Illuminate\Support\Str;

class MenuConfigUpdater
{
    public function updateLookupMenu(
        GeneratorFileWriter $writer,
        CrudNames $names,
        string $menu,
        string $menuParent,
    ): void {
        $path = "config/menu/{$menu}.php";
        $absolutePath = base_path($path);
        $contents = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : null;
        $line = "                    \$hrLookupItem('{$names->menuLabel}', '{$names->pluralStudly}', 'circle', '{$names->routeKey}', '{$names->permissionPrefix}'),\n";

        if ($contents === null || ! str_contains($contents, '$hrLookupItem') || ! str_contains($menuParent, 'hr')) {
            $this->writeManualMenuBlock($writer, $names, $menu, 'menu file does not match the HR lookup closure pattern');

            return;
        }

        if (str_contains($contents, "'{$names->menuLabel}'") || str_contains($contents, "\"{$names->menuLabel}\"")) {
            $writer->skip($path, "menu label [{$names->menuLabel}] already exists");

            return;
        }

        $position = strrpos($contents, '                    $hrLookupItem(');

        if ($position === false) {
            $this->writeManualMenuBlock($writer, $names, $menu, 'could not locate the last HR lookup menu item');

            return;
        }

        $lineEnd = strpos($contents, "\n", $position);

        if ($lineEnd === false) {
            $this->writeManualMenuBlock($writer, $names, $menu, 'could not locate HR lookup menu insertion point');

            return;
        }

        $updated = substr($contents, 0, $lineEnd + 1).$line.substr($contents, $lineEnd + 1);
        $writer->update($path, $updated);
    }

    public function writeFullCrudManualBlock(
        GeneratorFileWriter $writer,
        CrudNames $names,
        string $menu,
    ): void {
        $this->writeManualMenuBlock($writer, $names, $menu, 'full CRUD menu insertion is emitted as a manual review block to avoid corrupting nested menu arrays');
    }

    private function writeManualMenuBlock(GeneratorFileWriter $writer, CrudNames $names, string $menu, string $reason): void
    {
        $block = <<<PHP
<?php

// Paste this item under the requested parent in config/menu/{$menu}.php.
[
    'label' => '{$names->menuLabel}',
    'title' => '{$names->pluralStudly}',
    'icon' => 'circle',
    'route' => '{$names->routePrefix}.index',
    'permission' => '{$names->permissionPrefix}.view',
    'actions' => [
        'view' => '{$names->permissionPrefix}.view',
        'create' => '{$names->permissionPrefix}.create',
        'edit' => '{$names->permissionPrefix}.edit',
        'clone' => '{$names->permissionPrefix}.clone',
        'delete' => '{$names->permissionPrefix}.delete',
        'document_number_control' => '{$names->permissionPrefix}.document_number.control',
        'document_number_settings_update' => '{$names->permissionPrefix}.document_number_settings.update',
    ],
    'active' => [
        '{$names->routePrefix}.*',
    ],
    'children' => [],
];
PHP;

        $writer->manual(
            'storage/app/generated/erp/'.Str::slug($names->docKey).'-menu.generated.php',
            $block,
            $reason,
        );
    }
}
