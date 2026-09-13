<?php

namespace Modules\Core\Imports;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Modules\Core\Http\Requests\StoreProductRequest;
use Modules\Core\Imports\Concerns\ValidatesWithFormRequest;
use Modules\Core\Imports\Contracts\ExcelImportDefinition;
use Modules\Core\Models\ExcelImportBatch;
use Modules\Core\Models\ExcelImportRow;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemGroup;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemSize;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\ProductBomService;
use Modules\Core\Services\ProductService;

class ProductExcelImportDefinition implements ExcelImportDefinition
{
    use ValidatesWithFormRequest;

    public function __construct(
        private readonly ProductService $products,
        private readonly ProductBomService $bom,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function module(): string
    {
        return ExcelImportBatch::ModuleProducts;
    }

    public function permissionPrefix(): string
    {
        return 'products';
    }

    public function templateVersion(): string
    {
        return 'products-v1';
    }

    public function dataSheet(): string
    {
        return 'Products';
    }

    public function componentSheet(): string
    {
        return 'Product Components';
    }

    /**
     * @return list<string>
     */
    public function expectedSheets(): array
    {
        return ['Instructions', $this->dataSheet(), $this->componentSheet(), 'Lookups', 'Meta'];
    }

    /**
     * @return array<string, array{label: string, required: bool, width?: int, comment?: string, lookup?: string}>
     */
    public function fields(): array
    {
        return [
            'import_key' => $this->field('excel_imports.columns.import_key', true, 18),
            'name' => $this->field('products.attributes.name', true, 34),
            'item_classification' => $this->field('products.attributes.item_classification', true, 22, 'ProductClassifications'),
            'barcode' => $this->field('products.attributes.barcode', false, 20),
            'reorder_point' => $this->field('products.attributes.reorder_point', false, 16),
            'status' => $this->field('products.attributes.status', true, 14, 'Statuses'),
            'item_unit_doc_num' => $this->field('products.attributes.item_unit_doc_num', false, 18, 'ItemUnits'),
            'equivalent_value' => $this->field('products.attributes.equivalent_value', false, 18),
            'equivalent_unit_doc_num' => $this->field('products.attributes.equivalent_unit_doc_num', false, 20, 'ItemUnits'),
            'item_category_doc_num' => $this->field('products.attributes.item_category_doc_num', false, 20, 'ItemCategories'),
            'item_group_doc_num' => $this->field('products.attributes.item_group_doc_num', false, 20, 'ItemGroups'),
            'item_size_doc_num' => $this->field('products.attributes.item_size_doc_num', false, 18, 'ItemSizes'),
            'item_model_doc_num' => $this->field('products.attributes.item_model_doc_num', false, 18, 'ItemModels'),
            'item_color_doc_num' => $this->field('products.attributes.item_color_doc_num', false, 18, 'ItemColors'),
            'item_decal_doc_num' => $this->field('products.attributes.item_decal_doc_num', false, 20, 'ItemDecals'),
            'item_origin_country_doc_num' => $this->field('products.attributes.item_origin_country_doc_num', false, 22, 'ItemOriginCountries'),
            'cost_as_inventory' => $this->field('products.attributes.cost_as_inventory', false, 18, 'Booleans'),
            'is_displayable' => $this->field('products.attributes.is_displayable', false, 18, 'Booleans'),
            'notes' => $this->field('products.attributes.notes', false, 30),
        ];
    }

    /**
     * @return array<string, array{label: string, required: bool, width?: int, comment?: string, lookup?: string}>
     */
    public function componentFields(): array
    {
        return [
            'parent_import_key' => $this->field('excel_imports.columns.parent_import_key', true, 20),
            'component_import_key' => $this->field('excel_imports.columns.component_import_key', true, 22),
            'component_product_doc_num' => $this->field('products.components.component_item', true, 24, 'ComponentProducts'),
            'unit_doc_num' => $this->field('products.components.unit', false, 18, 'ItemUnits'),
            'calculation_method' => $this->field('products.components.calculation_method', true, 20, 'CalculationMethods'),
            'quantity' => $this->field('products.components.value', false, 16),
            'percentage' => $this->field('products.components.percentage', false, 16),
            'input_source' => $this->field('products.components.input_source', false, 18, 'ComponentInputSources'),
            'reference_component_import_key' => $this->field('excel_imports.columns.reference_component_import_key', false, 28),
            'notes' => $this->field('products.attributes.notes', false, 28),
        ];
    }

    /**
     * @return array<string, array{title: string, rows: list<array{reference: string, label: string}>}>
     */
    public function lookups(int $companyId): array
    {
        return [
            'ImportKeys' => ['title' => __('excel_imports.lookups.import_keys'), 'rows' => []],
            'ProductClassifications' => [
                'title' => __('products.attributes.item_classification'),
                'rows' => array_map(fn (string $value): array => [
                    'reference' => $value,
                    'label' => __('products.classifications.'.$value),
                ], Product::productItemClassifications()),
            ],
            'Statuses' => ['title' => __('products.attributes.status'), 'rows' => $this->enumRows(['active', 'inactive'])],
            'Booleans' => ['title' => __('excel_imports.lookups.booleans'), 'rows' => [
                ['reference' => '1', 'label' => __('common.yes')],
                ['reference' => '0', 'label' => __('common.no')],
            ]],
            'CalculationMethods' => ['title' => __('products.components.calculation_method'), 'rows' => [
                ['reference' => ProductComponent::CalculationDirect, 'label' => __('products.components.direct')],
                ['reference' => ProductComponent::CalculationPercentage, 'label' => __('products.components.percentage')],
                ['reference' => ProductComponent::CalculationQuantity, 'label' => __('products.components.quantity')],
                ['reference' => ProductComponent::CalculationCount, 'label' => __('products.components.count')],
            ]],
            'ComponentInputSources' => ['title' => __('products.components.input_source'), 'rows' => [
                ['reference' => 'weight', 'label' => __('excel_imports.lookups.weight')],
                ['reference' => 'percentage', 'label' => __('products.components.percentage')],
            ]],
            'ItemUnits' => $this->lookupRows(ItemUnit::class, $companyId, __('products.attributes.item_unit_doc_num')),
            'ItemCategories' => $this->lookupRows(ItemCategory::class, $companyId, __('products.attributes.item_category_doc_num')),
            'ItemGroups' => $this->lookupRows(ItemGroup::class, $companyId, __('products.attributes.item_group_doc_num')),
            'ItemSizes' => $this->lookupRows(ItemSize::class, $companyId, __('products.attributes.item_size_doc_num')),
            'ItemModels' => $this->lookupRows(ItemModel::class, $companyId, __('products.attributes.item_model_doc_num')),
            'ItemColors' => $this->lookupRows(ItemColor::class, $companyId, __('products.attributes.item_color_doc_num')),
            'ItemDecals' => $this->lookupRows(ItemDecal::class, $companyId, __('products.attributes.item_decal_doc_num')),
            'ItemOriginCountries' => $this->lookupRows(ItemOriginCountry::class, $companyId, __('products.attributes.item_origin_country_doc_num')),
            'ComponentProducts' => [
                'title' => __('products.components.component_item'),
                'rows' => Product::query()
                    ->forCompany($companyId)
                    ->active()
                    ->componentItems()
                    ->orderBy('doc_number')
                    ->get(['doc_num', 'name'])
                    ->map(fn (Product $product): array => [
                        'reference' => (string) $product->doc_num,
                        'label' => trim(implode(' / ', array_filter([$product->doc_num, $product->name]))),
                    ])
                    ->all(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function instructions(): array
    {
        return [
            __('excel_imports.instructions.products_1'),
            __('excel_imports.instructions.products_2'),
            __('excel_imports.instructions.common_no_images'),
            __('excel_imports.instructions.common_references'),
            __('excel_imports.instructions.common_no_formulas'),
        ];
    }

    /**
     * @param  array<string, list<array{excel_row: int, data: array<string, string|null>}>>  $rowsBySheet
     * @return list<array{sheet_key: string, excel_row: int, data: array<string, mixed>, normalized_data: array<string, mixed>|null, import_key: string|null, issues: list<array<string, mixed>>}>
     */
    public function validateRows(array $rowsBySheet, Request $request): array
    {
        $masterRows = $rowsBySheet[$this->dataSheet()] ?? [];
        $componentRows = $rowsBySheet[$this->componentSheet()] ?? [];
        $issues = [];
        $add = function (string $sheet, int $row, ?string $column, string $code, string $message) use (&$issues): void {
            $issues["{$sheet}:{$row}"][] = $this->issue($column, $code, $message);
        };

        $mastersByKey = [];
        $masterByExcelRow = [];
        foreach ($masterRows as $row) {
            $excelRow = $row['excel_row'];
            $data = $row['data'];
            $importKey = trim((string) ($data['import_key'] ?? ''));
            $masterByExcelRow[$excelRow] = $row;

            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $importKey) !== 1) {
                $add($this->dataSheet(), $excelRow, 'import_key', 'products.import_key.invalid', __('excel_imports.validation.import_key'));
            }

            $mastersByKey[$importKey][] = $row;
        }

        foreach ($mastersByKey as $key => $rows) {
            if ($key !== '' && count($rows) > 1) {
                foreach ($rows as $row) {
                    $add($this->dataSheet(), $row['excel_row'], 'import_key', 'products.import_key.duplicate', __('excel_imports.validation.duplicate_import_key'));
                }
            }
        }

        $barcodes = [];
        foreach ($masterRows as $row) {
            $barcode = trim((string) ($row['data']['barcode'] ?? ''));
            if ($barcode !== '') {
                $barcodes[$barcode][] = $row;
            }
        }
        foreach ($barcodes as $rows) {
            if (count($rows) > 1) {
                foreach ($rows as $row) {
                    $add($this->dataSheet(), $row['excel_row'], 'barcode', 'products.barcode.duplicate_workbook', __('excel_imports.validation.duplicate_in_workbook'));
                }
            }
        }

        $componentsByParent = [];
        $componentRowsByKey = [];
        foreach ($componentRows as $row) {
            $data = $row['data'];
            $excelRow = $row['excel_row'];
            $parentKey = trim((string) ($data['parent_import_key'] ?? ''));
            $componentKey = trim((string) ($data['component_import_key'] ?? ''));

            if ($parentKey === '' || ! isset($mastersByKey[$parentKey]) || count($mastersByKey[$parentKey]) !== 1) {
                $add($this->componentSheet(), $excelRow, 'parent_import_key', 'components.parent.not_found', __('excel_imports.validation.component_parent_not_found'));
            }
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $componentKey) !== 1) {
                $add($this->componentSheet(), $excelRow, 'component_import_key', 'components.import_key.invalid', __('excel_imports.validation.component_import_key'));
            }

            $componentsByParent[$parentKey][] = $row;
            $componentRowsByKey[$componentKey][] = $row;
        }

        foreach ($componentRowsByKey as $key => $rows) {
            if ($key !== '' && count($rows) > 1) {
                foreach ($rows as $row) {
                    $add($this->componentSheet(), $row['excel_row'], 'component_import_key', 'components.import_key.duplicate', __('excel_imports.validation.duplicate_component_import_key'));
                }
            }
        }

        $componentClientKeys = collect(array_keys($componentRowsByKey))
            ->filter(fn (string $key): bool => $key !== '')
            ->mapWithKeys(fn (string $key): array => [$key => (string) Str::uuid()])
            ->all();
        $normalizedByRow = [];

        foreach ($masterRows as $masterRow) {
            $excelRow = $masterRow['excel_row'];
            $masterData = $masterRow['data'];
            $importKey = trim((string) ($masterData['import_key'] ?? ''));
            $childRows = $componentsByParent[$importKey] ?? [];
            $components = [];

            foreach ($childRows as $childRow) {
                $childData = $childRow['data'];
                $componentKey = trim((string) ($childData['component_import_key'] ?? ''));
                $referenceKey = trim((string) ($childData['reference_component_import_key'] ?? ''));
                $referenceClientKey = $referenceKey === '' ? null : ($componentClientKeys[$referenceKey] ?? null);

                if ($referenceKey !== '' && $referenceClientKey === null) {
                    $add($this->componentSheet(), $childRow['excel_row'], 'reference_component_import_key', 'components.reference.not_found', __('excel_imports.validation.component_reference_not_found'));
                }

                $components[] = [
                    'public_id' => null,
                    'client_key' => $componentClientKeys[$componentKey] ?? (string) Str::uuid(),
                    'component_product_doc_num' => $childData['component_product_doc_num'] ?? null,
                    'unit_doc_num' => $childData['unit_doc_num'] ?? null,
                    'calculation_method' => $childData['calculation_method'] ?? ProductComponent::CalculationDirect,
                    'quantity' => $childData['quantity'] ?? null,
                    'percentage' => $childData['percentage'] ?? null,
                    'input_source' => $childData['input_source'] ?? null,
                    'reference_component_key' => $referenceClientKey,
                    'notes' => $childData['notes'] ?? null,
                    '_delete' => false,
                ];
            }

            $payload = Arr::except($masterData, ['import_key']);
            $payload['components'] = $components;
            $validation = $this->validateWithFormRequest(StoreProductRequest::class, $payload, $request);

            foreach ($validation['errors'] as $field => $messages) {
                if (preg_match('/^components\.(\d+)\.(.+)$/', $field, $matches) === 1 && isset($childRows[(int) $matches[1]])) {
                    $target = $childRows[(int) $matches[1]];
                    foreach ($messages as $message) {
                        $add($this->componentSheet(), $target['excel_row'], $matches[2], 'products.components.validation', $message);
                    }

                    continue;
                }

                foreach ($messages as $message) {
                    $add($this->dataSheet(), $excelRow, $field, 'products.validation', $message);
                }
            }

            if ($validation['data'] !== null) {
                $normalizedByRow[$excelRow] = $validation['data'];
            }
        }

        $results = [];
        foreach ($masterRows as $row) {
            $rowIssues = $issues["{$this->dataSheet()}:{$row['excel_row']}"] ?? [];
            $results[] = [
                'sheet_key' => $this->dataSheet(),
                'excel_row' => $row['excel_row'],
                'data' => $row['data'],
                'normalized_data' => $rowIssues === [] ? ($normalizedByRow[$row['excel_row']] ?? null) : null,
                'import_key' => trim((string) ($row['data']['import_key'] ?? '')) ?: null,
                'issues' => $rowIssues,
            ];
        }
        foreach ($componentRows as $row) {
            $rowIssues = $issues["{$this->componentSheet()}:{$row['excel_row']}"] ?? [];
            $results[] = [
                'sheet_key' => $this->componentSheet(),
                'excel_row' => $row['excel_row'],
                'data' => $row['data'],
                'normalized_data' => null,
                'import_key' => trim((string) ($row['data']['component_import_key'] ?? '')) ?: null,
                'issues' => $rowIssues,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{sheet_key: string, excel_row: int, import_key?: string|null, doc_num: string, name: string}>
     */
    public function commit(ExcelImportBatch $batch, Request $request): array
    {
        $created = [];
        $recordsByImportKey = [];
        $rows = ExcelImportRow::query()
            ->where('batch_id', $batch->getKey())
            ->forSheet($this->dataSheet())
            ->orderBy('excel_row')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $data = $row->normalized_data ?? [];
            $components = $data['components'] ?? [];
            unset($data['components']);

            $product = $this->products->create($data)['record'];
            $recordsByImportKey[(string) $row->import_key] = ['product' => $product, 'components' => $components, 'row' => $row];
        }

        foreach ($recordsByImportKey as $importKey => $entry) {
            $this->bom->sync($entry['product'], $entry['components']);
            $entry['row']->forceFill(['created_doc_num' => $entry['product']->doc_num])->save();
            $this->activityLogger->log($request, 'core', 'products.import', 'success', [
                'subject' => $entry['product'],
                'properties_only' => true,
                'properties' => ActivityLogProperties::crudCreated('products', $entry['product']->name, $entry['product']->doc_num, [
                    'excel_import_key' => $importKey,
                    'excel_row' => $entry['row']->excel_row,
                ]),
            ]);
            $created[] = [
                'sheet_key' => $this->dataSheet(),
                'excel_row' => $entry['row']->excel_row,
                'import_key' => $importKey,
                'doc_num' => (string) $entry['product']->doc_num,
                'name' => (string) $entry['product']->name,
            ];
        }

        return $created;
    }

    /**
     * @return array{label: string, required: bool, width?: int, comment?: string, lookup?: string}
     */
    private function field(string $labelKey, bool $required, int $width, ?string $lookup = null): array
    {
        return [
            'label' => __($labelKey),
            'required' => $required,
            'width' => $width,
            'comment' => $required ? __('excel_imports.template.required_field') : __('excel_imports.template.optional_field'),
            ...($lookup ? ['lookup' => $lookup] : []),
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<array{reference: string, label: string}>
     */
    private function enumRows(array $values): array
    {
        return array_map(fn (string $value): array => [
            'reference' => $value,
            'label' => __('products.statuses.'.$value),
        ], $values);
    }

    /**
     * @param  class-string<ItemUnit|ItemCategory|ItemGroup|ItemSize|ItemModel|ItemColor|ItemDecal|ItemOriginCountry>  $model
     * @return array{title: string, rows: list<array{reference: string, label: string}>}
     */
    private function lookupRows(string $model, int $companyId, string $title): array
    {
        return [
            'title' => $title,
            'rows' => $model::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('doc_number')
                ->get(['doc_num', 'name'])
                ->map(fn ($record): array => [
                    'reference' => (string) $record->doc_num,
                    'label' => trim(implode(' / ', array_filter([$record->doc_num, $record->name]))),
                ])
                ->all(),
        ];
    }
}
