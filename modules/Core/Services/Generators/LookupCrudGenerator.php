<?php

namespace Modules\Core\Services\Generators;

use Illuminate\Support\Str;

class LookupCrudGenerator
{
    public function __construct(
        private readonly CrudNameResolver $resolver = new CrudNameResolver,
        private readonly StubRenderer $renderer = new StubRenderer,
        private readonly DocumentNumberConfigUpdater $documentNumbers = new DocumentNumberConfigUpdater,
        private readonly MenuConfigUpdater $menus = new MenuConfigUpdater,
        private readonly TranslationFileUpdater $translations = new TranslationFileUpdater,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, list<array{path: string, reason?: string}>>
     */
    public function generate(array $options, GeneratorFileWriter $writer): array
    {
        $names = $this->resolver->resolve(
            module: (string) $options['module'],
            resource: (string) $options['resource'],
            table: (string) $options['table'],
            routePrefix: (string) $options['route'],
            urlPrefix: (string) $options['url'],
            permissionPrefix: (string) $options['permission'],
            docKey: (string) $options['doc_key'],
            translationKey: (string) $options['translation'],
            mode: 'lookup',
        );
        $variables = $this->variables($names);

        $writer->write($this->migrationPath($names), $this->renderer->render('stubs/erp/lookup-crud/migration.stub', $variables));
        $writer->write("modules/{$names->module}/Models/{$names->modelClass}.php", $this->renderer->render('stubs/erp/lookup-crud/model.stub', $variables));
        $writer->write("modules/{$names->module}/Services/{$names->serviceClass}.php", $this->renderer->render('stubs/erp/lookup-crud/service.stub', $variables));
        $writer->write("modules/{$names->module}/DataTables/{$names->dataTableClass}.php", $this->renderer->render('stubs/erp/lookup-crud/datatable.stub', $variables));
        $writer->write("modules/{$names->module}/Http/Controllers/{$names->controllerClass}.php", $this->renderer->render('stubs/erp/lookup-crud/controller.stub', $variables));

        $this->updateRegistry($writer, $names, $variables);
        $this->updateRoutes($writer, $names, $variables);
        $this->documentNumbers->update($writer, $names->docKey, (string) $options['prefix']);
        $this->menus->updateLookupMenu($writer, $names, (string) $options['menu'], (string) $options['menu_parent']);
        $this->updateTranslations($writer, $names);

        return $writer->results();
    }

    /**
     * @return array<string, string>
     */
    private function variables(CrudNames $names): array
    {
        return [
            'module' => $names->module,
            'moduleLower' => $names->moduleLower,
            'model' => $names->modelClass,
            'controller' => $names->controllerClass,
            'service' => $names->serviceClass,
            'dataTable' => $names->dataTableClass,
            'table' => $names->table,
            'routeKey' => $names->routeKey,
            'routeParameter' => $names->routeParameter,
            'docKey' => $names->docKey,
            'permissionPrefix' => $names->permissionPrefix,
            'translationArrayKey' => $names->translationArrayKey,
            'jsNamespace' => $names->jsNamespace,
        ];
    }

    private function migrationPath(CrudNames $names): string
    {
        return sprintf(
            'modules/%s/Database/Migrations/%s_create_%s_table.php',
            $names->module,
            date('Y_m_d_His'),
            $names->table,
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function updateRegistry(GeneratorFileWriter $writer, CrudNames $names, array $variables): void
    {
        $path = 'modules/HR/Services/HrLookupRegistry.php';
        $absolutePath = base_path($path);
        $contents = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : null;

        if ($contents === null) {
            $writer->manual(
                "storage/app/generated/erp/{$names->docKey}-hr-registry.generated.php",
                $this->renderer->render('stubs/erp/lookup-crud/registry-entry.stub', $variables),
                'HR lookup registry was not found; add this definition manually',
            );

            return;
        }

        if (str_contains($contents, "'{$names->routeKey}' => new HrLookupDefinition(")) {
            $writer->skip($path, "lookup registry key [{$names->routeKey}] already exists");

            return;
        }

        if (! str_contains($contents, "use {$names->modelFqcn()};")) {
            $classPosition = strpos($contents, "\nclass HrLookupRegistry");

            if ($classPosition === false) {
                $writer->manual(
                    "storage/app/generated/erp/{$names->docKey}-hr-registry.generated.php",
                    "use {$names->modelFqcn()};\n\n".$this->renderer->render('stubs/erp/lookup-crud/registry-entry.stub', $variables),
                    'could not locate registry class declaration; add this use statement and definition manually',
                );

                return;
            }

            $contents = substr($contents, 0, $classPosition)."use {$names->modelFqcn()};\n".substr($contents, $classPosition);
        }

        $entry = $this->renderer->render('stubs/erp/lookup-crud/registry-entry.stub', $variables);
        $needle = "        ];\n    }\n\n    public function get";

        if (! str_contains($contents, $needle)) {
            $writer->manual(
                "storage/app/generated/erp/{$names->docKey}-hr-registry.generated.php",
                $entry,
                'could not locate registry insertion point; add this definition manually',
            );

            return;
        }

        $writer->update($path, str_replace($needle, $entry."\n".$needle, $contents));
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function updateRoutes(GeneratorFileWriter $writer, CrudNames $names, array $variables): void
    {
        $path = "modules/{$names->module}/Routes/web.php";
        $absolutePath = base_path($path);
        $contents = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : null;
        $entry = $this->renderer->render('stubs/erp/lookup-crud/route-entry.stub', $variables);

        if ($contents === null) {
            $writer->manual(
                "storage/app/generated/erp/{$names->docKey}-routes.generated.php",
                $entry,
                'module routes file was not found; add this route entry manually',
            );

            return;
        }

        if (str_contains($contents, "'{$names->routeKey}' => [")) {
            $writer->skip($path, "lookup route key [{$names->routeKey}] already exists");

            return;
        }

        if (! str_contains($contents, "use {$names->controllerFqcn()};")) {
            $lookupPosition = strpos($contents, "\n\n\$lookupRoutes = [");

            if ($lookupPosition === false) {
                $writer->manual(
                    "storage/app/generated/erp/{$names->docKey}-routes.generated.php",
                    "use {$names->controllerFqcn()};\n\n".$entry,
                    'could not locate lookup route array; add this use statement and route entry manually',
                );

                return;
            }

            $contents = substr($contents, 0, $lookupPosition)."use {$names->controllerFqcn()};\n".substr($contents, $lookupPosition);
        }

        $needle = "];\n\nRoute::middleware('auth')";

        if (! str_contains($contents, $needle)) {
            $writer->manual(
                "storage/app/generated/erp/{$names->docKey}-routes.generated.php",
                $entry,
                'could not locate lookup route array insertion point; add this entry manually',
            );

            return;
        }

        $writer->update($path, str_replace($needle, $entry.$needle, $contents));
    }

    private function updateTranslations(GeneratorFileWriter $writer, CrudNames $names): void
    {
        $englishTitle = Str::headline($names->snakePlural);
        $englishSingular = Str::headline($names->snakeSingular);
        $resourceLines = [
            $names->translationArrayKey => [
                'title' => $englishTitle,
                'singular' => $englishSingular,
                'create' => "Create {$englishSingular}",
                'edit' => "Edit {$englishSingular}",
                'view' => "View {$englishSingular}",
                'fields' => [
                    'doc_number' => 'Document Number',
                    'doc_num' => 'Document Number',
                    'name' => 'Name',
                    'notes' => 'Notes',
                ],
            ],
        ];
        $arabicLines = [
            $names->translationArrayKey => [
                'title' => $englishTitle,
                'singular' => $englishSingular,
                'create' => "إنشاء {$englishSingular}",
                'edit' => "تعديل {$englishSingular}",
                'view' => "عرض {$englishSingular}",
                'fields' => [
                    'doc_number' => 'رقم المستند',
                    'doc_num' => 'رقم المستند',
                    'name' => 'الاسم',
                    'notes' => 'ملاحظات',
                ],
            ],
        ];

        $this->translations->merge($writer, 'resources/lang/en/hr.php', $resourceLines);
        $this->translations->merge($writer, 'resources/lang/ar/hr.php', $arabicLines);
        $this->translations->merge($writer, 'resources/lang/en/menu.php', [$names->menuLabel => $englishTitle]);
        $this->translations->merge($writer, 'resources/lang/ar/menu.php', [$names->menuLabel => $englishTitle]);
    }
}
