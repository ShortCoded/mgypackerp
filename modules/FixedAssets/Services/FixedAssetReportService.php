<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDepreciation;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\FixedAssets\Models\FixedAssetMovement;

class FixedAssetReportService
{
    public const Register = 'register';

    public const Depreciation = 'depreciation';

    public const Schedule = 'schedule';

    public const Movements = 'movements';

    public const AdditionsDisposals = 'additions_disposals';

    public const Locations = 'locations';

    public const FullyDepreciated = 'fully_depreciated';

    public const Exceptions = 'exceptions';

    public const Reconciliation = 'reconciliation';

    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly FixedAssetBookValueService $bookValues,
        private readonly FixedAssetScheduleService $schedules,
        private readonly FixedAssetDepreciationService $depreciation,
        private readonly DateFormatService $dates,
    ) {}

    /** @return list<string> */
    public static function types(): array
    {
        return [self::Register, self::Depreciation, self::Schedule, self::Movements, self::AdditionsDisposals, self::Locations, self::FullyDepreciated, self::Exceptions, self::Reconciliation];
    }

    /** @return array<string, mixed> */
    public function filters(Request $request): array
    {
        $filters = $request->only(['type', 'from_date', 'to_date', 'financial_period_doc_num', 'asset_doc_num', 'asset_group_account_doc_num', 'status', 'entry_type', 'branch_doc_num', 'branch_hall_uuid', 'cost_center_doc_num', 'depreciable', 'posting_status']);
        $filters['type'] = in_array($filters['type'] ?? null, self::types(), true) ? $filters['type'] : self::Register;

        foreach (['from_date', 'to_date'] as $field) {
            $filters[$field] = $this->dates->normalizeForStorage(trim((string) ($filters[$field] ?? '')));
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== null && trim((string) $value) !== '');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{type: string, title: string, columns: array<string, string>, rows: Collection<int, array<string, mixed>>, filters: array<string, string>, totals: array<string, string>}
     */
    public function report(array $filters): array
    {
        $type = $filters['type'] ?? self::Register;
        [$columns, $rows] = match ($type) {
            self::Depreciation => $this->depreciationRegister($filters),
            self::Schedule => $this->schedule($filters),
            self::Movements => $this->movements($filters),
            self::AdditionsDisposals => $this->additionsDisposals($filters),
            self::Locations => $this->locations($filters),
            self::FullyDepreciated => $this->fullyDepreciated($filters),
            self::Exceptions => $this->exceptions($filters),
            self::Reconciliation => $this->reconciliation($filters),
            default => $this->register($filters),
        };

        return [
            'type' => $type,
            'title' => __('fixed_assets.reports.types.'.$type),
            'columns' => $columns,
            'rows' => $rows,
            'filters' => $this->filterSummary($filters),
            'totals' => $this->totals($type, $rows),
        ];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function register(array $filters): array
    {
        $query = FixedAsset::query()->forCompany($this->companies->requireCompanyId())->with(['assetGroupAccount', 'branch', 'branchHall', 'costCenter', 'currency', 'postedDepreciations']);
        $this->assetFilters($query, $filters);
        $rows = $query->orderBy('doc_number')->get()->map(function (FixedAsset $asset): array {
            $position = $this->bookValues->position($asset);

            return [
                'asset' => $asset->doc_num,
                'name' => $asset->asset_name,
                'classification' => $asset->assetGroupAccount?->codeNameLabel(),
                'serial' => $asset->serial_number,
                'acquisition_date' => $asset->acquisition_date ?: $asset->purchase_date,
                'service_date' => $asset->operation_date,
                'cost' => $position['acquisition_cost'],
                'accumulated_depreciation' => $position['accumulated_depreciation'],
                'net_book_value' => $position['net_book_value'],
                'residual_value' => $position['residual_value'],
                'branch' => $asset->branch?->name,
                'hall_location' => trim(implode(' / ', array_filter([$asset->branchHall?->name, $asset->location_address]))),
                'cost_center' => $asset->costCenter?->codeNameLabel(),
                'status' => __('fixed_assets.statuses.'.$asset->status),
            ];
        });

        return [$this->labels(array_keys($this->emptyRegisterRow())), $rows];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function depreciationRegister(array $filters): array
    {
        $query = FixedAssetDepreciation::query()
            ->where('fixed_asset_depreciations.company_id', $this->companies->requireCompanyId())
            ->with(['asset.assetGroupAccount', 'costCenter', 'branch', 'journalEntry', 'run', 'postedBy'])
            ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate('period_end', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate('period_end', '<=', $date))
            ->when($filters['posting_status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $this->depreciationAssetFilters($query, $filters);
        $rows = $query->orderBy('period_end')->orderBy('fixed_asset_id')->get()->map(fn (FixedAssetDepreciation $row): array => [
            'asset' => trim($row->asset?->doc_num.' / '.$row->asset?->asset_name),
            'classification' => $row->asset?->assetGroupAccount?->codeNameLabel(),
            'period' => $this->dates->formatDate($row->period_start, '').' - '.$this->dates->formatDate($row->period_end, ''),
            'opening_net_book_value' => bcadd((string) $row->closing_net_book_value, (string) $row->period_depreciation, 4),
            'depreciation_base' => $row->depreciation_base,
            'period_depreciation' => $row->period_depreciation,
            'accumulated_before' => $row->accumulated_before,
            'accumulated_after' => $row->accumulated_after,
            'net_book_value' => $row->closing_net_book_value,
            'cost_center' => $row->costCenter?->codeNameLabel(),
            'journal_entry' => $row->journalEntry?->doc_num,
            'status' => __('fixed_assets.lifecycle.statuses.'.$row->status),
            'posted_by_date' => trim(implode(' / ', array_filter([$row->postedBy?->name, $this->dates->formatDateTime($row->posted_at, '')]))),
            '_row_state' => $row->status,
        ]);

        return [$this->labels(array_keys($rows->first() ?? ['asset' => '', 'period' => ''])), $rows];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function schedule(array $filters): array
    {
        $companyId = $this->companies->requireCompanyId();
        $categoryId = $this->lookupId('accounts', $companyId, $filters['asset_group_account_doc_num'] ?? null);
        $assets = FixedAsset::query()
            ->forCompany($companyId)
            ->with('postedDepreciations')
            ->when($filters['asset_doc_num'] ?? null, fn ($query, $value) => $query->where('doc_num', $value))
            ->when($categoryId, fn ($query, $value) => $query->where('asset_group_account_id', $value))
            ->when(! isset($filters['asset_doc_num']) && $categoryId === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('doc_number')
            ->get();
        $rows = $assets->flatMap(function (FixedAsset $asset) use ($filters): array {
            return collect($this->schedules->schedule($asset)['rows'])
                ->filter(fn (array $row): bool => ! isset($filters['from_date']) || $row['period_end']->toDateString() >= $filters['from_date'])
                ->filter(fn (array $row): bool => ! isset($filters['to_date']) || $row['period_start']->toDateString() <= $filters['to_date'])
                ->map(fn (array $row): array => [
                    'asset' => $asset->doc_num.' / '.$asset->asset_name,
                    'period' => $this->dates->formatDate($row['period_start'], '').' - '.$this->dates->formatDate($row['period_end'], ''),
                    'opening_net_book_value' => $row['opening_net_book_value'],
                    'period_depreciation' => $row['period_depreciation'],
                    'accumulated_depreciation' => $row['accumulated_depreciation'],
                    'closing_net_book_value' => $row['closing_net_book_value'],
                    'status' => __('fixed_assets.lifecycle.statuses.'.$row['status']),
                    '_row_state' => $row['status'],
                ])->all();
        })->values();

        return [$this->labels(['asset', 'period', 'opening_net_book_value', 'period_depreciation', 'accumulated_depreciation', 'closing_net_book_value', 'status']), $rows];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function movements(array $filters): array
    {
        $companyId = $this->companies->requireCompanyId();
        $query = FixedAssetMovement::query()->where('company_id', $companyId)->with(['asset', 'sourceBranch', 'destinationBranch', 'sourceBranchHall', 'destinationBranchHall', 'sourceCostCenter', 'destinationCostCenter', 'requestedBy'])
            ->when($filters['asset_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('asset', fn ($query) => $query->where('doc_num', $value)))
            ->when($this->lookupId('branches', $companyId, $filters['branch_doc_num'] ?? null), fn ($query, $id) => $query->where('destination_branch_id', $id))
            ->when($this->lookupId('cost_centers', $companyId, $filters['cost_center_doc_num'] ?? null), fn ($query, $id) => $query->where('destination_cost_center_id', $id));
        $this->dateFilters($query, 'movement_date', $filters);
        $rows = $query->orderBy('movement_date')->get()->map(fn (FixedAssetMovement $row): array => [
            'document' => $row->doc_num,
            'asset' => $row->asset?->doc_num.' / '.$row->asset?->asset_name,
            'date' => $row->movement_date,
            'source' => trim(implode(' / ', array_filter([$row->sourceBranch?->name, $row->sourceBranchHall?->name, $row->source_location_address, $row->sourceCostCenter?->codeNameLabel()]))),
            'destination' => trim(implode(' / ', array_filter([$row->destinationBranch?->name, $row->destinationBranchHall?->name, $row->destination_location_address, $row->destinationCostCenter?->codeNameLabel()]))),
            'reason' => $row->reason,
            'user' => $row->requestedBy?->name,
        ]);

        return [$this->labels(array_keys($rows->first() ?? ['document' => '', 'asset' => ''])), $rows];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function additionsDisposals(array $filters): array
    {
        $companyId = $this->companies->requireCompanyId();
        $assets = FixedAsset::query()->forCompany($companyId)->with('assetGroupAccount');
        $this->assetFilters($assets, $filters);
        $additionRows = $assets->get()->map(function (FixedAsset $asset): array {
            $position = $this->bookValues->position($asset);

            return [
                'asset' => $asset->doc_num.' / '.$asset->asset_name,
                'date' => $asset->asset_date,
                'classification' => $asset->assetGroupAccount?->codeNameLabel(),
                'movement_type' => $asset->entry_type === FixedAsset::EntryTypeOpeningAsset ? __('fixed_assets.entry_types.opening_asset') : __('fixed_assets.reports.addition'),
                'addition_value' => $position['acquisition_cost'],
                'disposal_proceeds' => '0.0000',
                'net_book_value' => $position['net_book_value'],
                'gain' => '0.0000',
                'loss' => '0.0000',
                'status' => __('fixed_assets.statuses.'.$asset->status),
            ];
        });
        $disposals = FixedAssetDisposal::query()->where('company_id', $companyId)->where('status', FixedAssetDisposal::StatusPosted)->with('asset.assetGroupAccount')
            ->when($filters['asset_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('asset', fn ($query) => $query->where('doc_num', $value)))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->whereHas('asset', fn ($query) => $query->where('status', $value)))
            ->when($this->lookupId('accounts', $companyId, $filters['asset_group_account_doc_num'] ?? null), fn ($query, $id) => $query->whereHas('asset', fn ($query) => $query->where('asset_group_account_id', $id)))
            ->when($this->lookupId('branches', $companyId, $filters['branch_doc_num'] ?? null), fn ($query, $id) => $query->whereHas('asset', fn ($query) => $query->where('branch_id', $id)))
            ->when($this->lookupId('cost_centers', $companyId, $filters['cost_center_doc_num'] ?? null), fn ($query, $id) => $query->whereHas('asset', fn ($query) => $query->where('cost_center_id', $id)));
        $this->dateFilters($disposals, 'disposal_date', $filters);
        $disposalRows = $disposals->get()->map(fn (FixedAssetDisposal $row): array => [
            'asset' => $row->asset?->doc_num.' / '.$row->asset?->asset_name,
            'date' => $row->disposal_date,
            'classification' => $row->asset?->assetGroupAccount?->codeNameLabel(),
            'movement_type' => __('fixed_assets.lifecycle.disposition_types.'.$row->disposition_type),
            'addition_value' => '0.0000',
            'disposal_proceeds' => $row->proceeds,
            'net_book_value' => $row->net_book_value,
            'gain' => $row->gain_amount,
            'loss' => $row->loss_amount,
            'status' => __('fixed_assets.lifecycle.statuses.'.$row->status),
        ]);
        $rows = $additionRows->concat($disposalRows)->sortBy('date')->values();

        return [$this->labels(['date', 'asset', 'classification', 'movement_type', 'addition_value', 'disposal_proceeds', 'net_book_value', 'gain', 'loss', 'status']), $rows];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function locations(array $filters): array
    {
        [$columns, $rows] = $this->register($filters);

        return [array_intersect_key($columns, array_flip(['asset', 'name', 'classification', 'branch', 'hall_location', 'cost_center', 'status'])), $rows->map(fn (array $row): array => array_intersect_key($row, array_flip(['asset', 'name', 'classification', 'branch', 'hall_location', 'cost_center', 'status'])))];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function fullyDepreciated(array $filters): array
    {
        $filters['status'] = FixedAsset::StatusFullyDepreciated;
        [$columns, $rows] = $this->register($filters);
        $keys = ['asset', 'name', 'cost', 'residual_value', 'accumulated_depreciation', 'net_book_value', 'service_date', 'status', 'branch', 'hall_location'];

        return [
            array_intersect_key($columns, array_flip($keys)),
            $rows->map(fn (array $row): array => array_intersect_key($row, array_flip($keys))),
        ];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function exceptions(array $filters): array
    {
        if (! isset($filters['financial_period_doc_num'], $filters['to_date'])) {
            return [$this->labels(['asset', 'name', 'reason']), collect()];
        }

        $preview = $this->depreciation->preview(array_filter([
            'financial_period_doc_num' => $filters['financial_period_doc_num'],
            'posting_date' => $filters['to_date'],
            'asset_doc_nums' => isset($filters['asset_doc_num']) ? [$filters['asset_doc_num']] : null,
            'asset_group_account_doc_num' => $filters['asset_group_account_doc_num'] ?? null,
            'branch_doc_num' => $filters['branch_doc_num'] ?? null,
            'cost_center_doc_num' => $filters['cost_center_doc_num'] ?? null,
        ]));
        $rows = collect($preview['excluded'])->map(fn (array $row): array => ['asset' => $row['asset']->doc_num, 'name' => $row['asset']->asset_name, 'reason' => $row['reason']]);

        return [$this->labels(['asset', 'name', 'reason']), $rows];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function reconciliation(array $filters): array
    {
        $companyId = $this->companies->requireCompanyId();
        $assets = FixedAsset::query()
            ->forCompany($companyId)
            ->whereNotIn('status', FixedAsset::dispositionStatuses())
            ->with(['account', 'postedDepreciations']);
        $this->assetFilters($assets, $filters);
        $assets = $assets->get();
        $rows = collect();

        foreach ($assets->groupBy('account_id') as $accountId => $group) {
            $subledger = $group->reduce(fn (string $total, FixedAsset $asset): string => bcadd($total, $this->bookValues->position($asset)['base_acquisition_cost'], 4), '0.0000');
            $gl = $this->glSignedBalance($companyId, (int) $accountId, true, $filters);
            $account = $group->first()?->account;
            $rows->push($this->reconciliationRow('asset_cost', $account, $subledger, $gl));
        }

        $mappings = FixedAssetCategoryMapping::query()->where('company_id', $companyId)->with('accumulatedDepreciationAccount')->get()->keyBy('asset_group_account_id');
        foreach ($assets->groupBy('asset_group_account_id') as $categoryId => $group) {
            $mapping = $mappings->get($categoryId);
            if (! $mapping) {
                continue;
            }
            $subledger = $group->reduce(fn (string $total, FixedAsset $asset): string => bcadd($total, $this->bookValues->position($asset)['base_accumulated_depreciation'], 4), '0.0000');
            $gl = $this->glSignedBalance($companyId, (int) $mapping->accumulated_depreciation_account_id, false, $filters);
            $rows->push($this->reconciliationRow('accumulated_depreciation', $mapping->accumulatedDepreciationAccount, $subledger, $gl));
        }

        $financialPeriodId = $this->lookupId('financial_periods', $companyId, $filters['financial_period_doc_num'] ?? null);
        $periodDepreciation = FixedAssetDepreciation::query()->where('company_id', $companyId)->where('status', FixedAssetDepreciation::StatusPosted)
            ->when($financialPeriodId, fn ($query, $id) => $query->where('financial_period_id', $id))
            ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate('period_end', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate('period_end', '<=', $date))
            ->sum('base_period_depreciation');
        $expenseAccountIds = $mappings->pluck('depreciation_expense_account_id')->filter()->unique()->values();
        $glPeriod = DB::table('journal_entry_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)->whereNull('journal_entries.deleted_at')->where('journal_entries.is_posted', true)
            ->whereIn('journal_entries.source_type', ['fixed_asset_depreciation_run', 'fixed_asset_depreciation_reversal'])
            ->whereIn('journal_entry_lines.account_id', $expenseAccountIds)
            ->when($financialPeriodId, fn ($query, $id) => $query->where('journal_entries.financial_period_id', $id))
            ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate('journal_entries.entry_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate('journal_entries.entry_date', '<=', $date))
            ->selectRaw('COALESCE(SUM((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate), 0) as balance')->value('balance');
        $periodDifference = bcsub((string) $periodDepreciation, (string) $glPeriod, 4);
        $rows->push([
            'reconciliation_type' => __('fixed_assets.reports.reconciliation_types.period_depreciation'),
            'account' => __('fixed_assets.reports.all_expense_accounts'),
            'subledger' => (string) $periodDepreciation,
            'general_ledger' => (string) $glPeriod,
            'difference' => $periodDifference,
            'state' => $this->reconciliationState($periodDifference),
            '_row_state' => bccomp($periodDifference, '0', 4) === 0 ? 'reconciled' : 'difference',
        ]);

        return [$this->labels(['reconciliation_type', 'account', 'subledger', 'general_ledger', 'difference', 'state']), $rows];
    }

    private function assetFilters($query, array $filters): void
    {
        $companyId = $this->companies->requireCompanyId();
        $query
            ->when($filters['asset_doc_num'] ?? null, fn ($query, $value) => $query->where('doc_num', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['entry_type'] ?? null, fn ($query, $value) => $query->where('entry_type', $value))
            ->when(isset($filters['depreciable']), fn ($query) => $query->where('is_depreciable', filter_var($filters['depreciable'], FILTER_VALIDATE_BOOL)))
            ->when($this->lookupId('accounts', $companyId, $filters['asset_group_account_doc_num'] ?? null), fn ($query, $id) => $query->where('asset_group_account_id', $id))
            ->when($this->lookupId('branches', $companyId, $filters['branch_doc_num'] ?? null), fn ($query, $id) => $query->where('branch_id', $id))
            ->when($this->hallId($companyId, $filters['branch_hall_uuid'] ?? null), fn ($query, $id) => $query->where('branch_hall_id', $id))
            ->when($this->lookupId('cost_centers', $companyId, $filters['cost_center_doc_num'] ?? null), fn ($query, $id) => $query->where('cost_center_id', $id));
        $this->dateFilters($query, 'asset_date', $filters);
    }

    private function depreciationAssetFilters($query, array $filters): void
    {
        $companyId = $this->companies->requireCompanyId();
        $query
            ->when($filters['asset_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('asset', fn ($query) => $query->where('doc_num', $value)))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->whereHas('asset', fn ($query) => $query->where('status', $value)))
            ->when($this->lookupId('accounts', $companyId, $filters['asset_group_account_doc_num'] ?? null), fn ($query, $id) => $query->whereHas('asset', fn ($query) => $query->where('asset_group_account_id', $id)))
            ->when($this->lookupId('branches', $companyId, $filters['branch_doc_num'] ?? null), fn ($query, $id) => $query->where('branch_id', $id))
            ->when($this->lookupId('cost_centers', $companyId, $filters['cost_center_doc_num'] ?? null), fn ($query, $id) => $query->where('cost_center_id', $id))
            ->when($this->lookupId('financial_periods', $companyId, $filters['financial_period_doc_num'] ?? null), fn ($query, $id) => $query->where('financial_period_id', $id));
    }

    private function dateFilters($query, string $column, array $filters): void
    {
        $query->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate($column, '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate($column, '<=', $date));
    }

    private function lookupId(string $table, int $companyId, mixed $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        return $docNum === '' ? null : DB::table($table)->where('company_id', $companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->value('id');
    }

    private function hallId(int $companyId, mixed $publicUuid): ?int
    {
        $publicUuid = trim((string) $publicUuid);

        return $publicUuid === '' ? null : DB::table('branch_halls')
            ->join('branches', 'branches.id', '=', 'branch_halls.branch_id')
            ->where('branches.company_id', $companyId)
            ->where('branch_halls.public_uuid', $publicUuid)
            ->whereNull('branch_halls.deleted_at')
            ->whereNull('branches.deleted_at')
            ->value('branch_halls.id');
    }

    private function glSignedBalance(int $companyId, int $accountId, bool $debitNormal, array $filters): string
    {
        $balance = DB::table('journal_entry_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)->whereNull('journal_entries.deleted_at')->where('journal_entries.is_posted', true)->where('journal_entry_lines.account_id', $accountId)
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate('journal_entries.entry_date', '<=', $date))
            ->selectRaw('COALESCE(SUM((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate), 0) as balance')->value('balance');

        return $debitNormal ? (string) $balance : bcmul((string) $balance, '-1', 4);
    }

    private function reconciliationRow(string $type, ?Account $account, string $subledger, string $gl): array
    {
        return ['reconciliation_type' => __('fixed_assets.reports.reconciliation_types.'.$type), 'account' => $account?->codeNameLabel(), 'subledger' => $subledger, 'general_ledger' => $gl, 'difference' => bcsub($subledger, $gl, 4)];
    }

    /** @param list<string> $keys */
    private function labels(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => __('fixed_assets.reports.columns.'.$key)])->all();
    }

    private function emptyRegisterRow(): array
    {
        return array_fill_keys(['asset', 'name', 'classification', 'serial', 'acquisition_date', 'service_date', 'cost', 'accumulated_depreciation', 'net_book_value', 'residual_value', 'useful_life', 'method', 'annual_rate', 'branch', 'hall_location', 'cost_center', 'status'], '');
    }
}
