<?php

namespace Modules\Core\Services\Generators;

use Illuminate\Support\Str;

class CrudGenerator
{
    public function __construct(
        private readonly CrudNameResolver $resolver = new CrudNameResolver,
        private readonly FieldParser $fields = new FieldParser,
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
        );
        $fields = $this->fields->parse((string) ($options['fields'] ?? ''));

        if ($fields === []) {
            $fields = $this->fields->parse('name:string:required:unique,notes:text:nullable');
        }

        $variables = $this->variables($names, $fields);

        $writer->write($this->migrationPath($names), $this->renderer->render('stubs/erp/crud/migration.stub', $variables));
        $writer->write("modules/{$names->module}/Models/{$names->modelClass}.php", $this->renderer->render('stubs/erp/crud/model.stub', $variables));
        $writer->write("modules/{$names->module}/Services/{$names->serviceClass}.php", $this->renderer->render('stubs/erp/crud/service.stub', $variables));
        $writer->write("modules/{$names->module}/Services/{$names->documentSettingsServiceClass}.php", $this->renderer->render('stubs/erp/crud/document-settings-service.stub', $variables));
        $writer->write("modules/{$names->module}/DataTables/{$names->dataTableClass}.php", $this->renderer->render('stubs/erp/crud/datatable.stub', $variables));
        $writer->write("modules/{$names->module}/Http/Controllers/{$names->controllerClass}.php", $this->renderer->render('stubs/erp/crud/controller.stub', $variables));
        $writer->write("modules/{$names->module}/Http/Requests/{$names->storeRequestClass}.php", $this->renderer->render('stubs/erp/crud/store-request.stub', $variables));
        $writer->write("modules/{$names->module}/Http/Requests/{$names->updateRequestClass}.php", $this->renderer->render('stubs/erp/crud/update-request.stub', $variables));
        $writer->write("modules/{$names->module}/Http/Requests/{$names->bulkRequestClass}.php", $this->renderer->render('stubs/erp/crud/bulk-delete-request.stub', $variables));
        $writer->write("modules/{$names->module}/Http/Requests/{$names->settingsRequestClass}.php", $this->renderer->render('stubs/erp/crud/document-settings-request.stub', $variables));

        foreach ($this->viewStubs($names) as $path => $stub) {
            $writer->write($path, $this->renderer->render($stub, $variables));
        }

        $writer->write($names->jsPath, $this->renderer->render('stubs/erp/crud/js.stub', $variables));

        $this->updateRoutes($writer, $names, $variables);
        $this->documentNumbers->update($writer, $names->docKey, (string) $options['prefix']);
        $this->menus->writeFullCrudManualBlock($writer, $names, (string) $options['menu']);
        $this->updateTranslations($writer, $names, $fields);

        return $writer->results();
    }

    /**
     * @param  list<ParsedField>  $fields
     * @return array<string, string>
     */
    private function variables(CrudNames $names, array $fields): array
    {
        $uniqueFields = array_values(array_filter($fields, fn (ParsedField $field): bool => $field->unique));
        $displayableFields = $this->displayableFields($fields);
        $loggableFields = $this->loggableFields($fields);
        $fillableFields = $this->fillableFields($fields);
        $nullableUniqueColumns = collect([
            "'doc_number'",
            "'doc_num'",
            ...array_map(fn (ParsedField $field): string => $field->nullable ? "'{$field->name}'" : '', $uniqueFields),
        ])->filter()->implode(', ');

        return [
            'module' => $names->module,
            'moduleLower' => $names->moduleLower,
            'model' => $names->modelClass,
            'controller' => $names->controllerClass,
            'service' => $names->serviceClass,
            'documentSettingsService' => $names->documentSettingsServiceClass,
            'dataTable' => $names->dataTableClass,
            'storeRequest' => $names->storeRequestClass,
            'updateRequest' => $names->updateRequestClass,
            'bulkRequest' => $names->bulkRequestClass,
            'settingsRequest' => $names->settingsRequestClass,
            'variable' => $names->camelSingular,
            'table' => $names->table,
            'routePrefix' => $names->routePrefix,
            'routeResource' => Str::after($names->routePrefix, 'admin.'),
            'routeParameter' => $names->routeParameter,
            'routeBinding' => '{'.$names->routeParameter.':doc_num}',
            'urlResource' => Str::after($names->urlPrefix, 'admin/'),
            'permissionPrefix' => $names->permissionPrefix,
            'docKey' => $names->docKey,
            'translationKey' => $names->translationKey,
            'viewPath' => $names->viewPath,
            'kebabSingular' => $names->kebabSingular,
            'kebabPlural' => $names->kebabPlural,
            'jsAsset' => Str::after($names->jsPath, 'public/'),
            'jsNamespace' => $names->jsNamespace,
            'activeUniqueIndexes' => $this->activeUniqueIndexes($names, $fields),
            'nullableUniqueColumns' => $nullableUniqueColumns,
            'migrationColumns' => $this->migrationColumns($fields),
            'fillable' => $this->quotedLines(['doc_number', 'doc_num', ...array_map(fn (ParsedField $field): string => $field->name, $fillableFields), 'created_by', 'updated_by', 'deleted_by', 'restored_by', 'restored_at'], 2),
            'serviceFillable' => $this->quotedLines(array_map(fn (ParsedField $field): string => $field->name, $fillableFields), 2),
            'casts' => $this->casts($fields),
            'relationMethods' => $this->relationMethods($fields),
            'storeRules' => $this->rules($fields, $names, update: false),
            'updateRules' => $this->rules($fields, $names, update: true),
            'validatedCasts' => $this->validatedCasts($fields),
            'validationMessages' => $this->validationMessages($fields, $names),
            'defaultValues' => $this->defaultValues($fields),
            'normalizeCases' => $this->normalizeCases($fields),
            'dataTableSelects' => $this->dataTableSelects($displayableFields, $names),
            'dataTableColumns' => $this->dataTableColumns($displayableFields, $names),
            'orderColumns' => $this->orderColumns($displayableFields, $names),
            'rawColumns' => $this->rawColumns($displayableFields),
            'searchTextColumns' => $this->searchTextColumns($displayableFields, $names),
            'searchDateColumns' => $this->searchDateColumns($displayableFields, $names),
            'tableHeaders' => $this->tableHeaders($displayableFields, $names),
            'jsColumns' => $this->jsColumns($displayableFields),
            'formFields' => $this->formFields($fields, $names),
            'displayField' => $this->displayField($displayableFields),
            'uniqueExceptionMap' => $this->uniqueExceptionMap($uniqueFields, $names),
            'publicProperties' => $this->publicProperties($loggableFields),
        ];
    }

    /**
     * @param  list<ParsedField>  $fields
     * @return list<ParsedField>
     */
    private function displayableFields(array $fields): array
    {
        return array_values(array_filter($fields, fn (ParsedField $field): bool => $field->isDisplayable));
    }

    /**
     * @param  list<ParsedField>  $fields
     * @return list<ParsedField>
     */
    private function loggableFields(array $fields): array
    {
        return array_values(array_filter($fields, fn (ParsedField $field): bool => $field->isLoggable));
    }

    /**
     * @param  list<ParsedField>  $fields
     * @return list<ParsedField>
     */
    private function fillableFields(array $fields): array
    {
        return array_values(array_filter($fields, fn (ParsedField $field): bool => $field->isFillable));
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
     * @return array<string, string>
     */
    private function viewStubs(CrudNames $names): array
    {
        return [
            "{$names->viewFolder}/index.blade.php" => 'stubs/erp/crud/view-index.stub',
            "{$names->viewFolder}/form.blade.php" => 'stubs/erp/crud/view-form.stub',
            "{$names->viewFolder}/partials/actions.blade.php" => 'stubs/erp/crud/partial-actions.stub',
            "{$names->viewFolder}/partials/checkbox.blade.php" => 'stubs/erp/crud/partial-checkbox.stub',
            "{$names->viewFolder}/partials/form-header.blade.php" => 'stubs/erp/crud/partial-form-header.stub',
            "{$names->viewFolder}/partials/form-footer.blade.php" => 'stubs/erp/crud/partial-form-footer.stub',
            "{$names->viewFolder}/partials/form-actions.blade.php" => 'stubs/erp/crud/partial-form-actions.stub',
        ];
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function activeUniqueIndexes(CrudNames $names, array $fields): string
    {
        $indexes = [
            'doc_number' => "{$names->table}_doc_number_unique_active",
            'doc_num' => "{$names->table}_doc_num_unique_active",
        ];

        foreach ($fields as $field) {
            if ($field->unique) {
                $indexes[$field->name] = "{$names->table}_{$field->name}_unique_active";
            }
        }

        return collect($indexes)
            ->map(fn (string $index, string $column): string => "        '{$column}' => '{$index}',")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function migrationColumns(array $fields): string
    {
        return collect($fields)
            ->map(fn (ParsedField $field): string => '                '.$this->migrationColumn($field))
            ->implode("\n");
    }

    private function migrationColumn(ParsedField $field): string
    {
        $nullable = $field->nullable ? '->nullable()' : '';
        $default = $field->default !== null ? '->default('.var_export($field->default, true).')' : '';

        return match ($field->type) {
            'string' => "\$table->string('{$field->name}'".($field->length ? ", {$field->length}" : '')."){$nullable}{$default};",
            'text' => "\$table->text('{$field->name}'){$nullable}{$default};",
            'integer' => "\$table->integer('{$field->name}'){$nullable}{$default};",
            'decimal' => "\$table->decimal('{$field->name}', {$field->precision}, {$field->scale}){$nullable}{$default};",
            'boolean' => "\$table->boolean('{$field->name}'){$nullable}{$default};",
            'date' => "\$table->date('{$field->name}'){$nullable}{$default};",
            'datetime' => "\$table->dateTime('{$field->name}'){$nullable}{$default};",
            'foreignId' => "\$table->foreignId('{$field->name}'){$nullable}; // TODO: add constrained table once the relation target is confirmed.",
            default => "\$table->string('{$field->name}'){$nullable}{$default};",
        };
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedLines(array $values, int $indentLevel): string
    {
        $indent = str_repeat('    ', $indentLevel);

        return collect($values)
            ->map(fn (string $value): string => "{$indent}'{$value}',")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function casts(array $fields): string
    {
        $casts = [];

        foreach ($fields as $field) {
            $cast = match ($field->type) {
                'integer', 'foreignId' => 'integer',
                'decimal' => 'decimal:'.$field->scale,
                'boolean' => 'boolean',
                'date' => 'date',
                'datetime' => 'datetime',
                default => null,
            };

            if ($cast !== null) {
                $casts[$field->name] = $cast;
            }
        }

        $casts['created_at'] = 'datetime';
        $casts['updated_at'] = 'datetime';
        $casts['deleted_at'] = 'datetime';
        $casts['restored_at'] = 'datetime';

        return collect($casts)
            ->map(fn (string $cast, string $field): string => "            '{$field}' => '{$cast}',")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function relationMethods(array $fields): string
    {
        $foreignIds = array_filter($fields, fn (ParsedField $field): bool => $field->isForeignId());

        if ($foreignIds === []) {
            return '';
        }

        return "\n".collect($foreignIds)
            ->map(fn (ParsedField $field): string => "    // TODO: Add a belongsTo relation for {$field->name} after confirming the target model.")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function rules(array $fields, CrudNames $names, bool $update): string
    {
        return collect($fields)
            ->map(function (ParsedField $field) use ($names, $update): string {
                $rules = [$field->required ? "'required'" : "'nullable'"];
                $rules[] = match ($field->type) {
                    'string', 'text' => "'string'",
                    'integer', 'foreignId' => "'integer'",
                    'decimal' => "'numeric'",
                    'boolean' => "'boolean'",
                    'date' => "'date'",
                    'datetime' => "'date'",
                    default => "'string'",
                };

                if ($field->type === 'string') {
                    $rules[] = "'max:".($field->length ?? 255)."'";
                }

                if ($field->enum !== []) {
                    $rules[] = 'Rule::in(['.collect($field->enum)->map(fn (string $value): string => "'{$value}'")->implode(', ').'])';
                }

                if ($field->unique) {
                    $ignore = $update ? "->ignore(\${$names->camelSingular}?->getKey())" : '';
                    $rules[] = "Rule::unique('{$names->table}', '{$field->name}')->withoutTrashed(){$ignore}";
                }

                return "            '{$field->name}' => [".implode(', ', $rules).'],';
            })
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function validatedCasts(array $fields): string
    {
        return collect($fields)
            ->filter(fn (ParsedField $field): bool => in_array($field->type, ['boolean', 'integer', 'decimal', 'foreignId'], true))
            ->map(function (ParsedField $field): string {
                $cast = match ($field->type) {
                    'boolean' => "\$this->boolean('{$field->name}')",
                    'integer', 'foreignId' => "(int) \$data['{$field->name}']",
                    'decimal' => "(float) \$data['{$field->name}']",
                    default => "\$data['{$field->name}']",
                };

                return "\n        if (array_key_exists('{$field->name}', \$data) && \$data['{$field->name}'] !== null && \$data['{$field->name}'] !== '') {\n            \$data['{$field->name}'] = {$cast};\n        }";
            })
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function validationMessages(array $fields, CrudNames $names): string
    {
        return collect($fields)
            ->filter(fn (ParsedField $field): bool => $field->unique)
            ->map(fn (ParsedField $field): string => "            '{$field->name}.unique' => __('{$names->translationKey}.validation.{$field->name}_unique'),")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function defaultValues(array $fields): string
    {
        return collect($fields)
            ->filter(fn (ParsedField $field): bool => $field->default !== null)
            ->map(fn (ParsedField $field): string => "        if (! array_key_exists('{$field->name}', \$values)) {\n            \$values['{$field->name}'] = ".var_export($field->default, true).";\n        }\n")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function normalizeCases(array $fields): string
    {
        return collect($fields)
            ->map(function (ParsedField $field): ?string {
                return match ($field->type) {
                    'string' => "            '{$field->name}' => ".($field->required ? '$this->normalizeString((string) $value)' : '$this->normalizeNullableString($value)').',',
                    'text', 'date', 'datetime' => "            '{$field->name}' => \$this->normalizeNullableString(\$value),",
                    'integer', 'foreignId' => "            '{$field->name}' => \$value === null || \$value === '' ? null : (int) \$value,",
                    'decimal' => "            '{$field->name}' => \$value === null || \$value === '' ? null : (float) \$value,",
                    'boolean' => "            '{$field->name}' => (bool) \$value,",
                    default => null,
                };
            })
            ->filter()
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function dataTableSelects(array $fields, CrudNames $names): string
    {
        return collect($fields)
            ->map(fn (ParsedField $field): string => "                '{$names->table}.{$field->name}',")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function dataTableColumns(array $fields, CrudNames $names): string
    {
        return collect($fields)
            ->map(fn (ParsedField $field): string => "            ->editColumn('{$field->name}', fn ({$names->modelClass} \${$names->camelSingular}): string => \$this->ellipsisText(\${$names->camelSingular}->{$field->name} === null ? null : (string) \${$names->camelSingular}->{$field->name}))")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function orderColumns(array $fields, CrudNames $names): string
    {
        return collect($fields)
            ->map(fn (ParsedField $field): string => "            ->orderColumn('{$field->name}', '{$names->table}.{$field->name} $1')")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function rawColumns(array $fields): string
    {
        return collect($fields)
            ->map(fn (ParsedField $field): string => "'{$field->name}', ")
            ->implode('');
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function searchTextColumns(array $fields, CrudNames $names): string
    {
        return collect($fields)
            ->reject(fn (ParsedField $field): bool => $field->isDateLike())
            ->map(fn (ParsedField $field): string => "                '{$names->table}.{$field->name}',")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function searchDateColumns(array $fields, CrudNames $names): string
    {
        return collect($fields)
            ->filter(fn (ParsedField $field): bool => $field->isDateLike())
            ->map(fn (ParsedField $field): string => "                '{$names->table}.{$field->name}',")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function tableHeaders(array $fields, CrudNames $names): string
    {
        return collect($fields)
            ->map(fn (ParsedField $field): string => "                                    <th class=\"text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis\">{{ __('{$names->translationKey}.attributes.{$field->name}') }}</th>")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function jsColumns(array $fields): string
    {
        return collect($fields)
            ->map(fn (ParsedField $field): string => "                { data: '{$field->name}', name: tableName + '.{$field->name}', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function formFields(array $fields, CrudNames $names): string
    {
        return collect($fields)
            ->map(function (ParsedField $field) use ($names): string {
                $label = "{{ __('{$names->translationKey}.attributes.{$field->name}') }}";
                $value = "{{ old('{$field->name}', \$record?->{$field->name}) }}";
                $required = $field->required ? ' required' : '';

                if ($field->isForeignId()) {
                    return <<<BLADE
                    {{-- TODO: Add a safe selector for {$field->name} after confirming the relation target. Do not expose internal IDs; prefer doc_num-backed lookups. --}}
BLADE;
                }

                if ($field->type === 'text') {
                    return <<<BLADE
                    <div class="col-12">
                        <label class="form-label" for="{$field->name}">{$label}</label>
                        @if (\$isView)
                            <x-forms.view-field for="{$field->name}" as="textarea" :value="old('{$field->name}', \$record?->{$field->name})" rows="4" />
                        @else
                            <textarea class="form-control" id="{$field->name}" name="{$field->name}" rows="4">{$value}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="{$field->name}"></div>
                    </div>
BLADE;
                }

                if ($field->type === 'boolean') {
                    return <<<BLADE
                    <div class="col-md-4">
                        @if (\$isView)
                            <x-forms.view-field for="{$field->name}" :label="__('{$names->translationKey}.attributes.{$field->name}')" :value="old('{$field->name}', \$record?->{$field->name}) ? __('common.actions.yes') : __('common.actions.no')" />
                        @else
                            <div class="form-check mt-4">
                                <input class="form-check-input" id="{$field->name}" name="{$field->name}" type="checkbox" value="1" @checked(old('{$field->name}', \$record?->{$field->name}))>
                                <label class="form-check-label" for="{$field->name}">{$label}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback" data-error-for="{$field->name}"></div>
                    </div>
BLADE;
                }

                $type = match ($field->type) {
                    'integer', 'decimal', 'foreignId' => 'number',
                    'date' => 'date',
                    'datetime' => 'datetime-local',
                    default => 'text',
                };

                $viewAttributes = $field->isDateLike() ? ' dir="ltr" input-class="date-value"' : '';

                return <<<BLADE
                    <div class="col-md-6">
                        <label class="form-label" for="{$field->name}">{$label}</label>
                        @if (\$isView)
                            <x-forms.view-field for="{$field->name}" :value="old('{$field->name}', \$record?->{$field->name})"{$viewAttributes} />
                        @else
                            <input class="form-control" id="{$field->name}" name="{$field->name}" type="{$type}" value="{$value}"{$required}>
                        @endif
                        <div class="invalid-feedback" data-error-for="{$field->name}"></div>
                    </div>
BLADE;
            })
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function displayField(array $fields): string
    {
        return collect($fields)->first(fn (ParsedField $field): bool => $field->name === 'name')?->name
            ?? $fields[0]->name
            ?? 'doc_num';
    }

    /**
     * @param  list<ParsedField>  $uniqueFields
     */
    private function uniqueExceptionMap(array $uniqueFields, CrudNames $names): string
    {
        return collect($uniqueFields)
            ->map(fn (ParsedField $field): string => "            '{$field->name}' => ['{$names->table}_{$field->name}_unique_active'],")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function publicProperties(array $fields): string
    {
        return collect($fields)
            ->take(4)
            ->map(fn (ParsedField $field): string => "            '{$field->name}' => \$record->{$field->name},")
            ->implode("\n");
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function updateRoutes(GeneratorFileWriter $writer, CrudNames $names, array $variables): void
    {
        $path = "modules/{$names->module}/Routes/web.php";
        $absolutePath = base_path($path);
        $contents = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : null;
        $routeBlock = $this->renderer->render('stubs/erp/crud/route-block.stub', $variables);

        if ($contents === null || ! str_contains($contents, "->prefix('admin')")) {
            $writer->manual(
                "storage/app/generated/erp/{$names->docKey}-routes.generated.php",
                "<?php\n\nuse {$names->controllerFqcn()};\n\n".$routeBlock,
                'module route file or admin group was not found; add this route block manually',
            );

            return;
        }

        if (str_contains($contents, "->name('{$variables['routeResource']}.index')")) {
            $writer->skip($path, "route prefix [{$names->routePrefix}] already exists");

            return;
        }

        if (! str_contains($contents, "use {$names->controllerFqcn()};")) {
            $firstRoutePosition = strpos($contents, "\n\nRoute::");

            if ($firstRoutePosition === false) {
                $writer->manual(
                    "storage/app/generated/erp/{$names->docKey}-routes.generated.php",
                    "<?php\n\nuse {$names->controllerFqcn()};\n\n".$routeBlock,
                    'could not locate route insertion point; add this use statement and route block manually',
                );

                return;
            }

            $contents = substr($contents, 0, $firstRoutePosition)."use {$names->controllerFqcn()};\n".substr($contents, $firstRoutePosition);
        }

        $position = strrpos($contents, "\n    });");

        if ($position === false) {
            $writer->manual(
                "storage/app/generated/erp/{$names->docKey}-routes.generated.php",
                "<?php\n\nuse {$names->controllerFqcn()};\n\n".$routeBlock,
                'could not locate admin route group insertion point; add this route block manually',
            );

            return;
        }

        $writer->update($path, substr($contents, 0, $position)."\n".$routeBlock.substr($contents, $position));
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    private function updateTranslations(GeneratorFileWriter $writer, CrudNames $names, array $fields): void
    {
        $english = $this->resourceTranslation($names, $fields, arabic: false);
        $arabic = $this->resourceTranslation($names, $fields, arabic: true);

        $this->translations->merge($writer, "resources/lang/en/{$names->translationFile}.php", $this->translationPayload($names, $english));
        $this->translations->merge($writer, "resources/lang/ar/{$names->translationFile}.php", $this->translationPayload($names, $arabic));
        $this->translations->merge($writer, 'resources/lang/en/menu.php', [$names->menuLabel => $english['title']]);
        $this->translations->merge($writer, 'resources/lang/ar/menu.php', [$names->menuLabel => $arabic['title']]);
    }

    /**
     * @param  list<ParsedField>  $fields
     * @return array<string, mixed>
     */
    private function resourceTranslation(CrudNames $names, array $fields, bool $arabic): array
    {
        $title = Str::headline($names->snakePlural);
        $singular = Str::headline($names->snakeSingular);
        $fieldLabels = [
            'doc_number' => $arabic ? 'رقم المستند' : 'Document Number',
            'doc_num' => $arabic ? 'رقم المستند' : 'Document Number',
        ];

        foreach ($fields as $field) {
            $fieldLabels[$field->name] = Str::headline($field->name);
        }

        return [
            'title' => $title,
            'singular' => $singular,
            'create' => $arabic ? "إنشاء {$singular}" : "Create {$singular}",
            'edit' => $arabic ? "تعديل {$singular}" : "Edit {$singular}",
            'view' => $arabic ? "عرض {$singular}" : "View {$singular}",
            'selected' => $arabic ? 'السجلات المحددة' : "Selected {$title}",
            'select_all' => $arabic ? 'تحديد كل السجلات' : "Select all {$title}",
            'select_record' => $arabic ? 'تحديد :record' : 'Select :record',
            'titles' => [
                'clone' => $arabic ? 'نسخ السجل' : "Clone {$singular}",
            ],
            'attributes' => $fieldLabels,
            'document_number_control' => [
                'helper' => $arabic ? 'اتركه فارغًا للإنشاء التلقائي. سيتم تطبيق البادئة وعدد الخانات تلقائيًا.' : 'Leave empty for automatic generation. Prefix and padding are applied automatically.',
                'placeholder' => $arabic ? 'تلقائي' : 'Auto',
            ],
            'document_number_settings' => [
                'description' => $arabic ? 'تحكم في طريقة إنشاء أرقام المستندات الجديدة.' : 'Control how new document numbers are generated.',
                'padding' => $arabic ? 'عدد الخانات' : 'Padding',
                'prefix' => $arabic ? 'البادئة' : 'Prefix',
                'save' => $arabic ? 'حفظ الإعدادات' : 'Save Settings',
                'title' => $arabic ? 'إعدادات رقم المستند' : 'Document Number Settings',
                'updated_successfully' => $arabic ? 'تم تحديث إعدادات رقم المستند بنجاح.' : 'Document number settings updated successfully.',
            ],
            'messages' => [
                'action_forbidden' => $arabic ? 'ليس لديك صلاحية لاستخدام إجراء الحفظ هذا.' : 'You do not have permission to use this save action.',
                'bulk_deleted' => $arabic ? 'تم حذف :count سجل بنجاح.' : ':count records deleted successfully.',
                'bulk_delete_confirm_text' => $arabic ? 'أنت على وشك حذف :count سجل.' : 'You are about to delete :count records.',
                'bulk_delete_confirm_title' => $arabic ? 'حذف السجلات المحددة؟' : 'Delete selected records?',
                'bulk_delete_confirm_yes' => $arabic ? 'نعم، احذف المحدد' : 'Yes, delete selected',
                'clone_not_allowed' => $arabic ? 'لا يمكن نسخ هذا السجل.' : 'This record cannot be cloned.',
                'cloned' => $arabic ? 'تم نسخ السجل بنجاح.' : 'Record cloned successfully.',
                'created' => $arabic ? 'تم إنشاء السجل بنجاح.' : 'Record created successfully.',
                'delete_confirm_text' => $arabic ? 'لا يمكن التراجع عن هذا الإجراء.' : 'This action cannot be undone.',
                'delete_confirm_title' => $arabic ? 'حذف السجل؟' : 'Delete record?',
                'delete_confirm_yes' => $arabic ? 'نعم، احذف' : 'Yes, delete it',
                'deleted' => $arabic ? 'تم حذف السجل بنجاح.' : 'Record deleted successfully.',
                'no_records_selected' => $arabic ? 'حدد سجلًا واحدًا على الأقل.' : 'Select at least one record.',
                'restore_not_allowed' => $arabic ? 'لا يمكن استعادة هذا السجل.' : 'This record cannot be restored.',
                'restore_confirm_text' => $arabic ? 'سيتم استعادة السجل المحدد.' : 'The selected record will be restored.',
                'restore_confirm_title' => $arabic ? 'استعادة السجل؟' : 'Restore record?',
                'restore_confirm_yes' => $arabic ? 'نعم، استعادة' : 'Yes, restore',
                'restored' => $arabic ? 'تم استعادة السجل بنجاح.' : 'Record restored successfully.',
                'updated' => $arabic ? 'تم تحديث السجل بنجاح.' : 'Record updated successfully.',
            ],
            'trash' => [
                'filter_label' => $arabic ? 'السجلات' : 'Records',
                'active' => $arabic ? 'النشطة' : 'Active',
                'trashed' => $arabic ? 'المحذوفة' : 'Trashed',
                'all' => $arabic ? 'الكل' : 'All',
            ],
            'validation' => [
                'doc_number_numeric' => $arabic ? 'يجب أن يحتوي رقم المستند على أرقام فقط.' : 'Document number must contain digits only.',
                'doc_number_unique' => $arabic ? 'رقم المستند موجود بالفعل.' : 'Document number already exists.',
                ...collect($fields)
                    ->filter(fn (ParsedField $field): bool => $field->unique)
                    ->mapWithKeys(fn (ParsedField $field): array => ["{$field->name}_unique" => $arabic ? 'القيمة موجودة بالفعل.' : Str::headline($field->name).' already exists.'])
                    ->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function translationPayload(CrudNames $names, array $payload): array
    {
        return str_contains($names->translationKey, '.')
            ? [$names->translationArrayKey => $payload]
            : $payload;
    }
}
