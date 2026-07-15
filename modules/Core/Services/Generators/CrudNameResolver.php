<?php

namespace Modules\Core\Services\Generators;

use Illuminate\Support\Str;

class CrudNameResolver
{
    public function resolve(
        string $module,
        string $resource,
        string $table,
        string $routePrefix,
        string $urlPrefix,
        string $permissionPrefix,
        string $docKey,
        string $translationKey,
        string $mode = 'crud',
    ): CrudNames {
        $module = $this->resolveModule($module);
        $resourceWords = Str::of($resource)->replace(['_', '-'], ' ')->headline()->toString();
        $baseSingularStudly = Str::studly(Str::singular($resourceWords));
        $basePluralStudly = Str::studly(Str::plural($resourceWords));
        $modelPrefix = $mode === 'lookup' && $module === 'HR' ? 'Hr' : '';
        $singularStudly = Str::startsWith($baseSingularStudly, $modelPrefix) ? $baseSingularStudly : $modelPrefix.$baseSingularStudly;
        $pluralStudly = Str::startsWith($basePluralStudly, $modelPrefix) ? $basePluralStudly : $modelPrefix.$basePluralStudly;
        $snakeSingular = Str::snake($baseSingularStudly);
        $snakePlural = Str::snake($basePluralStudly);
        $kebabSingular = Str::kebab($baseSingularStudly);
        $kebabPlural = Str::kebab($basePluralStudly);
        $translationParts = explode('.', $translationKey);
        $translationFile = $translationParts[0] ?: $snakePlural;
        $translationArrayKey = $translationParts[1] ?? $translationFile;
        $routeKey = Str::afterLast($routePrefix, '.');
        $menuLabel = str_replace('.', '_', $translationKey);

        return new CrudNames(
            module: $module,
            moduleLower: Str::lower($module),
            resource: $resourceWords,
            table: $table,
            routePrefix: $routePrefix,
            urlPrefix: trim($urlPrefix, '/'),
            permissionPrefix: $permissionPrefix,
            docKey: $docKey,
            translationKey: $translationKey,
            translationFile: $translationFile,
            translationArrayKey: $translationArrayKey,
            singularStudly: $singularStudly,
            pluralStudly: $pluralStudly,
            camelSingular: Str::camel($baseSingularStudly),
            camelPlural: Str::camel($basePluralStudly),
            kebabSingular: $kebabSingular,
            kebabPlural: $kebabPlural,
            snakeSingular: $snakeSingular,
            snakePlural: $snakePlural,
            modelClass: $singularStudly,
            controllerClass: "{$singularStudly}Controller",
            serviceClass: "{$singularStudly}Service",
            documentSettingsServiceClass: "{$singularStudly}DocumentNumberSettingsService",
            dataTableClass: "{$pluralStudly}DataTable",
            storeRequestClass: "Store{$singularStudly}Request",
            updateRequestClass: "Update{$singularStudly}Request",
            bulkRequestClass: "BulkDelete{$pluralStudly}Request",
            settingsRequestClass: "Update{$singularStudly}DocumentNumberSettingsRequest",
            routeParameter: Str::camel($baseSingularStudly),
            viewPath: 'modules.'.Str::lower($module).'.'.$kebabPlural,
            viewFolder: 'resources/views/modules/'.Str::lower($module)."/{$kebabPlural}",
            jsPath: "public/assets/js/modules/{$module}/{$kebabPlural}.js",
            jsNamespace: Str::camel($module.' '.$basePluralStudly),
            menuLabel: $menuLabel,
            routeKey: $routeKey,
        );
    }

    private function resolveModule(string $module): string
    {
        $module = trim($module);
        $modulesPath = base_path('modules');

        if (is_dir($modulesPath)) {
            foreach (scandir($modulesPath) ?: [] as $directory) {
                if ($directory === '.' || $directory === '..') {
                    continue;
                }

                if (strcasecmp($directory, $module) === 0) {
                    return $directory;
                }
            }
        }

        return Str::upper($module) === $module ? $module : Str::studly($module);
    }
}
