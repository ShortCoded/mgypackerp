<?php

namespace Modules\FixedAssets\Imports;

use DomainException;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Imports\Concerns\ValidatesWithFormRequest;
use Modules\Core\Imports\Contracts\ExcelImportDefinition;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ExcelImportBatch;
use Modules\Core\Models\ExcelImportRow;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\FixedAssets\Http\Requests\StoreFixedAssetRequest;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Services\FixedAssetService;

class FixedAssetExcelImportDefinition implements ExcelImportDefinition
{
    use ValidatesWithFormRequest;

    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly BusinessPartnerAccountService $accounts,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function module(): string
    {
        return ExcelImportBatch::ModuleFixedAssets;
    }

    public function permissionPrefix(): string
    {
        return 'fixed_assets';
    }

    public function templateVersion(): string
    {
        return 'fixed-assets-v1';
    }

    public function dataSheet(): string
    {
        return 'FixedAssets';
    }

    /**
     * @return list<string>
     */
    public function expectedSheets(): array
    {
        return ['Instructions', $this->dataSheet(), 'Lookups', 'Meta'];
    }

    /**
     * @return array<string, array{label: string, required: bool, width?: int, comment?: string, lookup?: string}>
     */
    public function fields(): array
    {
        return [
            'entry_type' => $this->field('fixed_assets.attributes.entry_type', true, 18, 'EntryTypes'),
            'asset_date' => $this->field('fixed_assets.attributes.asset_date', true, 16),
            'asset_name' => $this->field('fixed_assets.attributes.asset_name', true, 34),
            'asset_group_account_doc_num' => $this->field('fixed_assets.attributes.asset_group_account', true, 25, 'AssetCategories'),
            'credit_account_doc_num' => $this->field('fixed_assets.attributes.credit_account', true, 25, 'CreditAccounts'),
            'cost_center_doc_num' => $this->field('fixed_assets.attributes.cost_center', false, 22, 'CostCenters'),
            'branch_doc_num' => $this->field('fixed_assets.attributes.branch', true, 18, 'Branches'),
            'branch_hall_uuid' => $this->field('fixed_assets.attributes.hall', false, 38, 'BranchHalls'),
            'description' => $this->field('fixed_assets.attributes.description', true, 32),
            'serial_number' => $this->field('fixed_assets.attributes.serial_number', false, 22),
            'purchase_date' => $this->field('fixed_assets.attributes.purchase_date', true, 16),
            'acquisition_date' => $this->field('fixed_assets.attributes.acquisition_date', false, 18),
            'operation_date' => $this->field('fixed_assets.attributes.operation_date', false, 18),
            'purchase_value' => $this->field('fixed_assets.attributes.purchase_value', true, 18),
            'currency_doc_num' => $this->field('fixed_assets.attributes.currency', true, 18, 'Currencies'),
            'exchange_rate' => $this->field('fixed_assets.attributes.exchange_rate', true, 16),
            'is_depreciable' => $this->field('fixed_assets.attributes.is_depreciable', true, 18, 'Booleans'),
            'depreciation_method' => $this->field('fixed_assets.attributes.depreciation_method', false, 28, 'DepreciationMethods'),
            'salvage_value' => $this->field('fixed_assets.attributes.salvage_value', false, 18),
            'previous_depreciation' => $this->field('fixed_assets.attributes.previous_depreciation', false, 22),
            'previous_depreciation_until_date' => $this->field('fixed_assets.attributes.previous_depreciation_until_date', false, 24),
            'annual_depreciation_rate' => $this->field('fixed_assets.attributes.annual_depreciation_rate', false, 22),
            'expected_usage_units' => $this->field('fixed_assets.attributes.expected_usage_units', false, 22),
            'useful_life' => $this->field('fixed_assets.attributes.useful_life', false, 18),
            'location_address' => $this->field('fixed_assets.attributes.location_address', false, 32),
            'status' => $this->field('fixed_assets.attributes.status', true, 14, 'Statuses'),
            'notes' => $this->field('fixed_assets.attributes.notes', false, 32),
        ];
    }

    /**
     * @return array<string, array{title: string, rows: list<array{reference: string, label: string}>}>
     */
    public function lookups(int $companyId): array
    {
        try {
            $root = $this->accounts->rootAccount(BusinessPartnerAccountService::FixedAsset);
            $assetCategories = Account::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->where('is_group', true)
                ->where('is_postable', false)
                ->where('id', '!=', $root->getKey())
                ->orderBy('account_code')
                ->get(['doc_num', 'account_code', 'name', 'name_en'])
                ->filter(fn (Account $account): bool => $this->accounts->isSelectableGroup(BusinessPartnerAccountService::FixedAsset, $account))
                ->map(fn (Account $account): array => [
                    'reference' => (string) $account->doc_num,
                    'label' => $account->codeNameLabel(),
                ])
                ->values()
                ->all();
        } catch (DomainException) {
            $assetCategories = [];
        }

        return [
            'EntryTypes' => ['title' => __('fixed_assets.attributes.entry_type'), 'rows' => $this->enumRows(FixedAsset::entryTypes(), 'fixed_assets.entry_types')],
            'Statuses' => ['title' => __('fixed_assets.attributes.status'), 'rows' => $this->enumRows(['active', 'inactive'], 'fixed_assets.statuses')],
            'Booleans' => ['title' => __('excel_imports.lookups.booleans'), 'rows' => [
                ['reference' => '1', 'label' => __('fixed_assets.booleans.yes')],
                ['reference' => '0', 'label' => __('fixed_assets.booleans.no')],
            ]],
            'DepreciationMethods' => ['title' => __('fixed_assets.attributes.depreciation_method'), 'rows' => $this->enumRows(FixedAsset::depreciationMethods(), 'fixed_assets.depreciation_methods')],
            'AssetCategories' => [
                'title' => __('fixed_assets.attributes.asset_group_account'),
                'rows' => $assetCategories,
            ],
            'CreditAccounts' => [
                'title' => __('fixed_assets.attributes.credit_account'),
                'rows' => Account::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'active')
                    ->where('is_group', false)
                    ->where('is_postable', true)
                    ->orderBy('account_code')
                    ->get(['doc_num', 'account_code', 'name', 'name_en'])
                    ->map(fn (Account $account): array => [
                        'reference' => (string) $account->doc_num,
                        'label' => $account->codeNameLabel(),
                    ])
                    ->all(),
            ],
            'CostCenters' => [
                'title' => __('fixed_assets.attributes.cost_center'),
                'rows' => CostCenter::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'active')
                    ->where('is_group', false)
                    ->orderBy('cost_center_code')
                    ->get(['doc_num', 'cost_center_code', 'name'])
                    ->map(fn (CostCenter $costCenter): array => [
                        'reference' => (string) $costCenter->doc_num,
                        'label' => $costCenter->codeNameLabel(),
                    ])
                    ->all(),
            ],
            'Branches' => $this->modelLookup(Branch::class, $companyId, __('fixed_assets.attributes.branch')),
            'Currencies' => [
                'title' => __('fixed_assets.attributes.currency'),
                'rows' => Currency::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'active')
                    ->orderByDesc('is_main')
                    ->orderBy('code')
                    ->get(['doc_num', 'code', 'name'])
                    ->map(fn (Currency $currency): array => [
                        'reference' => (string) $currency->doc_num,
                        'label' => trim(implode(' / ', array_filter([$currency->code, $currency->name]))),
                    ])
                    ->all(),
            ],
            'BranchHalls' => [
                'title' => __('fixed_assets.attributes.hall'),
                'rows' => BranchHall::query()
                    ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))
                    ->with('branch:id,doc_num,name')
                    ->orderBy('name')
                    ->get(['public_uuid', 'branch_id', 'name'])
                    ->map(fn (BranchHall $hall): array => [
                        'reference' => (string) $hall->public_uuid,
                        'label' => trim(implode(' / ', array_filter([$hall->branch?->doc_num, $hall->branch?->name, $hall->name]))),
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
            __('excel_imports.instructions.assets_1'),
            __('excel_imports.instructions.assets_2'),
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
        $rows = $rowsBySheet[$this->dataSheet()] ?? [];
        $issues = [];
        $add = function (int $row, ?string $column, string $code, string $message) use (&$issues): void {
            $issues[$row][] = $this->issue($column, $code, $message);
        };
        $assetNames = [];
        $serialNumbers = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            $assetName = trim((string) ($data['asset_name'] ?? ''));
            $serialNumber = trim((string) ($data['serial_number'] ?? ''));
            if ($assetName !== '') {
                $assetNames[$assetName][] = $row;
            }
            if ($serialNumber !== '') {
                $serialNumbers[$serialNumber][] = $row;
            }

            foreach (['asset_date', 'purchase_date', 'acquisition_date', 'operation_date', 'previous_depreciation_until_date'] as $field) {
                $value = trim((string) ($data[$field] ?? ''));
                if ($value !== '' && preg_match('/^\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}$/', $value) === 1) {
                    $add($row['excel_row'], $field, 'fixed_assets.date.ambiguous', __('excel_imports.validation.ambiguous_date'));
                }
            }
        }

        foreach ($assetNames as $duplicateRows) {
            if (count($duplicateRows) > 1) {
                foreach ($duplicateRows as $row) {
                    $add($row['excel_row'], 'asset_name', 'fixed_assets.name.duplicate_workbook', __('excel_imports.validation.duplicate_in_workbook'));
                }
            }
        }
        foreach ($serialNumbers as $duplicateRows) {
            if (count($duplicateRows) > 1) {
                foreach ($duplicateRows as $row) {
                    $add($row['excel_row'], 'serial_number', 'fixed_assets.serial.duplicate_workbook', __('excel_imports.validation.duplicate_in_workbook'));
                }
            }
        }

        $results = [];
        foreach ($rows as $row) {
            $validation = $this->validateWithFormRequest(StoreFixedAssetRequest::class, $row['data'], $request);
            foreach ($validation['errors'] as $field => $messages) {
                foreach ($messages as $message) {
                    $add($row['excel_row'], $field, 'fixed_assets.validation', $message);
                }
            }
            $rowIssues = $issues[$row['excel_row']] ?? [];
            $results[] = [
                'sheet_key' => $this->dataSheet(),
                'excel_row' => $row['excel_row'],
                'data' => $row['data'],
                'normalized_data' => $rowIssues === [] ? $validation['data'] : null,
                'import_key' => null,
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
        $rows = ExcelImportRow::query()
            ->where('batch_id', $batch->getKey())
            ->forSheet($this->dataSheet())
            ->orderBy('excel_row')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $asset = $this->assets->create($row->normalized_data ?? [])['record'];
            $row->forceFill(['created_doc_num' => $asset->doc_num])->save();
            $this->activityLogger->log($request, 'fixed_assets', 'fixed_assets.import', 'success', [
                'subject' => $asset,
                'properties_only' => true,
                'properties' => ActivityLogProperties::crudCreated('fixed_assets', $asset->asset_name, $asset->doc_num, [
                    'excel_row' => $row->excel_row,
                ]),
            ]);
            $created[] = [
                'sheet_key' => $this->dataSheet(),
                'excel_row' => $row->excel_row,
                'doc_num' => (string) $asset->doc_num,
                'name' => (string) $asset->asset_name,
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
    private function enumRows(array $values, string $translationPrefix): array
    {
        return array_map(fn (string $value): array => [
            'reference' => $value,
            'label' => __($translationPrefix.'.'.$value),
        ], $values);
    }

    /**
     * @param  class-string<Branch>  $model
     * @return array{title: string, rows: list<array{reference: string, label: string}>}
     */
    private function modelLookup(string $model, int $companyId, string $title): array
    {
        return [
            'title' => $title,
            'rows' => $model::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('doc_number')
                ->get(['doc_num', 'name'])
                ->map(fn (Branch $branch): array => [
                    'reference' => (string) $branch->doc_num,
                    'label' => trim(implode(' / ', array_filter([$branch->doc_num, $branch->name]))),
                ])
                ->all(),
        ];
    }
}
