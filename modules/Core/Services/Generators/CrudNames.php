<?php

namespace Modules\Core\Services\Generators;

final readonly class CrudNames
{
    public function __construct(
        public string $module,
        public string $moduleLower,
        public string $resource,
        public string $table,
        public string $routePrefix,
        public string $urlPrefix,
        public string $permissionPrefix,
        public string $docKey,
        public string $translationKey,
        public string $translationFile,
        public string $translationArrayKey,
        public string $singularStudly,
        public string $pluralStudly,
        public string $camelSingular,
        public string $camelPlural,
        public string $kebabSingular,
        public string $kebabPlural,
        public string $snakeSingular,
        public string $snakePlural,
        public string $modelClass,
        public string $controllerClass,
        public string $serviceClass,
        public string $documentSettingsServiceClass,
        public string $dataTableClass,
        public string $storeRequestClass,
        public string $updateRequestClass,
        public string $bulkRequestClass,
        public string $settingsRequestClass,
        public string $routeParameter,
        public string $viewPath,
        public string $viewFolder,
        public string $jsPath,
        public string $jsNamespace,
        public string $menuLabel,
        public string $routeKey,
    ) {}

    public function modelFqcn(): string
    {
        return "Modules\\{$this->module}\\Models\\{$this->modelClass}";
    }

    public function controllerFqcn(): string
    {
        return "Modules\\{$this->module}\\Http\\Controllers\\{$this->controllerClass}";
    }

    public function dataTableFqcn(): string
    {
        return "Modules\\{$this->module}\\DataTables\\{$this->dataTableClass}";
    }

    public function serviceFqcn(): string
    {
        return "Modules\\{$this->module}\\Services\\{$this->serviceClass}";
    }
}
