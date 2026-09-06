<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDepreciation;
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
        return [self::Register, self::Depreciation, self::Schedule, self::Movements, self::AdditionsDisposals, self::Locations, self::FullyDepreciated, self::Exceptions, self::Reconciliation, 'net_book_value', 'capital_additions', 'transfers', 'custody', 'disposals', 'gain_loss', 'by_branch', 'by_location', 'by_cost_center', 'by_category'];
    }

    /** @return list<string> */
    public static function visibleTypes(): array
    {
        return [self::Register, self::Depreciation, self::Schedule, 'capital_additions', 'transfers', 'custody', 'disposals', 'gain_loss', self::Movements, 'net_book_value', self::FullyDepreciated, self::Reconciliation, self::Exceptions];
    }

    /** @return array<string, mixed> */
    public function filters(Request $request): array
    {
        $filters = $request->only(['type', 'from_date', 'to_date', 'financial_period_doc_num', 'asset_doc_num', 'asset_group_account_doc_num', 'status', 'entry_type', 'branch_doc_num', 'branch_hall_uuid', 'cost_center_doc_num', 'depreciable', 'posting_status', 'movement_type', 'user']);
        $filters['type'] = in_array($filters['type'] ?? null, self::types(), true) ? $filters['type'] : self::Register;

        foreach (['from_date', 'to_date'] as $field) {
            $filters[$field] = $this->dates->normalizeForStorage(trim((string) ($filters[$field] ?? '')));
        }

        if ($filters['type'] !== self::Movements) {
            unset($filters['movement_type'], $filters['user']);
        }

        if ($filters['type'] !== self::Depreciation) {
            unset($filters['posting_status']);
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== null && trim((string) $value) !== '');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{type: string, title: string, columns: array<string, string>, rows: Collection<int, array<string, mixed>>, filters: array<string, string>, totals: array<string, string>}
     */
    public function report(array $filters): array
    {
        if (! empty($filters['financial_period_doc_num'])) {
            $period = FinancialPeriod::query()->where('company_id', $this->companies->requireCompanyId())->where('doc_num', $filters['financial_period_doc_num'])->firstOrFail();
            app(FixedAssetAccessService::class)->assertPeriod($period);
            $filters['to_date'] = min($filters['to_date'] ?? now()->toDateString(), $period->to_date->toDateString());
        }
        $type = $filters['type'] ?? self::Register;
        if (isset($period) && in_array($type, [self::Depreciation, self::Schedule, self::Movements, self::AdditionsDisposals, 'capital_additions', 'transfers', 'custody', 'disposals', 'gain_loss'], true)) {
            $filters['from_date'] = max($filters['from_date'] ?? $period->from_date->toDateString(), $period->from_date->toDateString());
        }
        [$columns, $rows] = match ($type) {
            'net_book_value', 'by_branch', 'by_location', 'by_cost_center', 'by_category' => $this->register($filters),
            'capital_additions', 'transfers', 'custody' => $this->movementDocuments($filters),
            'disposals', 'gain_loss' => $this->additionsDisposals($filters),
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
        $rows = $this->reportAssets($filters)->map(function (FixedAsset $asset) use ($filters): array {
            $position = $this->bookValues->position($asset, Carbon::parse($filters['to_date'] ?? now())->endOfDay());
            $disposed = $asset->isDisposed();

            return [
                '_asset_url' => auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $asset) : null,
                'asset' => $asset->doc_num,
                'name' => $asset->asset_name,
                'classification' => $asset->assetGroupAccount?->codeNameLabel(),
                'serial' => $asset->serial_number,
                'currency' => $asset->currency?->code,
                'acquisition_date' => $asset->acquisition_date ?: $asset->purchase_date,
                'service_date' => $asset->operation_date,
                'cost' => $disposed ? '0.0000' : $position['acquisition_cost'],
                'accumulated_depreciation' => $disposed ? '0.0000' : $position['accumulated_depreciation'],
                'net_book_value' => $disposed ? '0.0000' : $position['net_book_value'],
                'residual_value' => $position['residual_value'],
                'branch' => $asset->branch?->name,
                'hall_location' => trim(implode(' / ', array_filter([$asset->branchHall?->name, $asset->location_address]))),
                'cost_center' => $asset->costCenter?->codeNameLabel(),
                'status' => __('fixed_assets.statuses.'.$asset->status),
            ];
        });

        $sort = match ($filters['type'] ?? '') {
            'by_branch' => 'branch', 'by_location' => 'hall_location', 'by_cost_center' => 'cost_center', 'by_category' => 'classification', default => 'asset'
        };

        return [$this->labels(array_keys($this->emptyRegisterRow())), $rows->sortBy($sort)->values()];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function depreciationRegister(array $filters): array
    {
        $query = FixedAssetDepreciation::query()
            ->where('fixed_asset_depreciations.company_id', $this->companies->requireCompanyId())
            ->whereIn('fixed_asset_depreciations.branch_id', app(FixedAssetAccessService::class)->branchIds())
            ->with(['asset.assetGroupAccount', 'asset.currency', 'costCenter', 'branch', 'journalEntry', 'run', 'postedBy'])
            ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate('period_end', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate('period_end', '<=', $date))
            ->when($filters['posting_status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $this->depreciationAssetFilters($query, $filters);
        $rows = $query->orderBy('period_end')->orderBy('fixed_asset_id')->get()->map(fn (FixedAssetDepreciation $row): array => [
            '_asset_url' => $row->asset && auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $row->asset) : null,
            '_journal_url' => $row->journalEntry && auth()->user()?->can('journal_entries.view') ? route('admin.accounting.journal-entries.show', $row->journalEntry) : null,
            'asset' => trim($row->asset?->doc_num.' / '.$row->asset?->asset_name),
            'currency' => $row->asset?->currency?->code,
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

        return [$this->labels([
            'asset', 'currency', 'classification', 'period', 'opening_net_book_value', 'depreciation_base', 'period_depreciation',
            'accumulated_before', 'accumulated_after', 'net_book_value', 'cost_center', 'journal_entry', 'status', 'posted_by_date',
        ]), $rows];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function schedule(array $filters): array
    {
        $assets = $this->reportAssets($filters);
        $rows = $assets->flatMap(function (FixedAsset $asset) use ($filters): array {
            return collect($this->schedules->schedule($asset)['rows'])
                ->filter(fn (array $row): bool => ! isset($filters['from_date']) || $row['period_end']->toDateString() >= $filters['from_date'])
                ->filter(fn (array $row): bool => ! isset($filters['to_date']) || $row['period_start']->toDateString() <= $filters['to_date'])
                ->map(fn (array $row): array => [
                    '_asset_url' => auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $asset) : null,
                    'asset' => $asset->doc_num.' / '.$asset->asset_name,
                    'currency' => $asset->currency?->code,
                    'period' => $this->dates->formatDate($row['period_start'], '').' - '.$this->dates->formatDate($row['period_end'], ''),
                    'opening_net_book_value' => $row['opening_net_book_value'],
                    'period_depreciation' => $row['period_depreciation'],
                    'accumulated_depreciation' => $row['accumulated_depreciation'],
                    'closing_net_book_value' => $row['closing_net_book_value'],
                    'status' => __('fixed_assets.lifecycle.statuses.'.$row['status']),
                    '_row_state' => $row['status'],
                ])->all();
        })->values();

        return [$this->labels(['asset', 'currency', 'period', 'opening_net_book_value', 'period_depreciation', 'accumulated_depreciation', 'closing_net_book_value', 'status']), $rows];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function movements(array $filters): array
    {
        $rows = $this->reportAssets($filters, false)->flatMap(function (FixedAsset $asset) use ($filters): Collection {
            return app(FixedAssetLedgerService::class)->history($asset, forReport: true)->filter(fn (array $row): bool => $this->entryMatches($row, $filters)
                || ($row['destination_branch_id'] && $this->entryMatches([...$row, 'branch_id' => $row['destination_branch_id'], 'cost_center_id' => $row['destination_cost_center_id']], $filters)))
                ->filter(fn (array $row): bool => empty($filters['movement_type']) || ($filters['movement_type'] === 'reversal' ? str_ends_with($row['type'], '_reversal') : $row['type'] === $filters['movement_type']))
                ->filter(fn (array $row): bool => empty($filters['user']) || mb_stripos((string) $row['user'], (string) $filters['user']) !== false)
                ->map(fn (array $row): array => ['_url' => $row['url'], '_asset_url' => route('admin.fixed-assets.assets.show', $asset), '_journal_url' => $row['journal'] ? route('admin.accounting.journal-entries.show', $row['journal']) : null, '_type' => $row['type'], '_reversed' => $row['reversed'], 'date' => $row['date'], 'document' => $row['document'], 'asset' => $asset->doc_num.' / '.$asset->asset_name, 'currency' => $asset->currency?->code,
                    'movement_type' => __('fixed_assets.cycle.'.$row['type']), 'amount' => $row['amount'], 'reason' => $row['detail'], 'user' => $row['user'], 'journal_entry' => $row['journal']?->doc_num,
                    'status' => $row['reversed'] ? __('fixed_assets.lifecycle.statuses.reversed') : __('fixed_assets.lifecycle.statuses.posted')]);
        })->sortBy('date')->values();

        return [$this->labels(['date', 'document', 'asset', 'currency', 'movement_type', 'amount', 'reason', 'user', 'journal_entry', 'status']), $rows];
    }

    private function movementDocuments(array $filters): array
    {
        $type = match ($filters['type'] ?? '') {
            'capital_additions' => 'addition', 'custody' => 'custody', default => 'transfer'
        };
        $companyId = $this->companies->requireCompanyId();
        $assetIds = $this->reportAssets($filters, false)->modelKeys();
        $query = FixedAssetMovement::query()->where('company_id', $companyId)->whereIn('fixed_asset_id', $assetIds)->where('movement_type', $type)
            ->with(['asset.currency', 'sourceBranch', 'destinationBranch', 'sourceCostCenter', 'destinationCostCenter', 'sourceCustodian', 'destinationCustodian', 'journalEntry', 'requestedBy']);
        $this->dateFilters($query, 'movement_date', $filters);
        $rows = $query->get()->filter(fn ($row): bool => $this->entryMatches(['date' => $row->movement_date, 'period_id' => $row->financial_period_id, 'branch_id' => $row->destination_branch_id, 'cost_center_id' => $row->destination_cost_center_id], $filters)
            || $this->entryMatches(['date' => $row->movement_date, 'period_id' => $row->financial_period_id, 'branch_id' => $row->source_branch_id, 'cost_center_id' => $row->source_cost_center_id], $filters))->map(fn ($row): array => [
                '_url' => $row->asset && auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $row->asset, false).'#movement-'.$row->doc_num : null,
                '_asset_url' => $row->asset && auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $row->asset) : null,
                '_journal_url' => $row->journalEntry && auth()->user()?->can('journal_entries.view') ? route('admin.accounting.journal-entries.show', $row->journalEntry) : null,
                'date' => $row->movement_date, 'document' => $row->doc_num, 'asset' => $row->asset?->doc_num.' / '.$row->asset?->asset_name,
                'currency' => $row->asset?->currency?->code, 'amount' => $row->amount,
                'source' => $type === 'custody' ? $row->sourceCustodian?->full_name : implode(' / ', array_filter([$row->sourceBranch?->name, $row->source_location_address, $row->sourceCostCenter?->name])),
                'destination' => $type === 'custody' ? $row->destinationCustodian?->full_name : implode(' / ', array_filter([$row->destinationBranch?->name, $row->destination_location_address, $row->destinationCostCenter?->name])),
                'reason' => $row->reason, 'journal_entry' => $row->journalEntry?->doc_num, 'user' => $row->requestedBy?->name, 'status' => __('fixed_assets.lifecycle.statuses.'.$row->status),
            ])->sortBy('date')->values();

        return [$this->labels(['date', 'document', 'asset', 'currency', 'amount', 'source', 'destination', 'reason', 'journal_entry', 'user', 'status']), $rows];
    }

    private function additionsDisposals(array $filters): array
    {
        $assets = $this->reportAssets($filters, false);
        $rows = collect();
        foreach ($assets as $asset) {
            if (! in_array($filters['type'] ?? '', ['disposals', 'gain_loss'], true)) {
                foreach ($asset->costMovements->where('status', 'posted') as $movement) {
                    if (! $this->entryMatches(['date' => $movement->movement_date, 'period_id' => $movement->financial_period_id, 'branch_id' => $movement->source_branch_id, 'cost_center_id' => $movement->source_cost_center_id], $filters)) {
                        continue;
                    }
                    $rows->push(['_url' => auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $asset, false).'#movement-'.$movement->doc_num : null, '_asset_url' => auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $asset) : null, '_journal_url' => $movement->journalEntry && auth()->user()?->can('journal_entries.view') ? route('admin.accounting.journal-entries.show', $movement->journalEntry) : null, 'date' => $movement->movement_date, 'asset' => $asset->doc_num, 'document' => $movement->doc_num, 'classification' => $asset->assetGroupAccount?->codeNameLabel(), 'currency' => $asset->currency?->code,
                        'movement_type' => __('fixed_assets.cycle.'.$movement->movement_type), 'addition_value' => $movement->amount, 'disposal_proceeds' => '0', 'disposal_expenses' => '0', 'net_proceeds' => '0', 'net_book_value' => $this->bookValues->position($asset, $movement->movement_date)['net_book_value'], 'gain' => '0', 'loss' => '0', 'status' => __('fixed_assets.lifecycle.statuses.posted')]);
                }
            }
            foreach ($asset->disposals->where('status', 'posted') as $disposal) {
                $costLine = $disposal->journalEntry?->lines->where('account_id', $asset->account_id)->first();
                if (! $this->entryMatches(['date' => $disposal->disposal_date, 'period_id' => $disposal->financial_period_id, 'branch_id' => $disposal->branch_id ?: $costLine?->branch_id, 'cost_center_id' => $disposal->cost_center_id ?? $costLine?->cost_center_id], $filters)) {
                    continue;
                }
                $rows->push(['_url' => auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $asset, false).'#disposal-'.$disposal->doc_num : null, '_asset_url' => auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $asset) : null, '_journal_url' => $disposal->journalEntry && auth()->user()?->can('journal_entries.view') ? route('admin.accounting.journal-entries.show', $disposal->journalEntry) : null, 'date' => $disposal->disposal_date, 'asset' => $asset->doc_num, 'document' => $disposal->doc_num, 'classification' => $asset->assetGroupAccount?->codeNameLabel(), 'currency' => $asset->currency?->code,
                    'movement_type' => __('fixed_assets.lifecycle.disposition_types.'.$disposal->disposition_type), 'addition_value' => '0', 'disposal_proceeds' => $disposal->proceeds, 'disposal_expenses' => $disposal->disposal_expenses, 'net_proceeds' => $disposal->net_proceeds ?? $disposal->proceeds, 'net_book_value' => $disposal->net_book_value, 'gain' => $disposal->gain_amount, 'loss' => $disposal->loss_amount, 'status' => __('fixed_assets.lifecycle.statuses.posted')]);
            }
        }

        return [$this->labels(['date', 'document', 'asset', 'classification', 'currency', 'movement_type', 'addition_value', 'disposal_proceeds', 'disposal_expenses', 'net_proceeds', 'net_book_value', 'gain', 'loss', 'status']), $rows->sortBy('date')->values()];
    }

    private function locations(array $filters): array
    {
        [$columns, $rows] = $this->register($filters);

        return [array_intersect_key($columns, array_flip(['asset', 'name', 'classification', 'branch', 'hall_location', 'cost_center', 'status'])), $rows->map(fn (array $row): array => [...array_intersect_key($row, array_flip(['asset', 'name', 'classification', 'branch', 'hall_location', 'cost_center', 'status'])), '_asset_url' => $row['_asset_url'] ?? null])];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function fullyDepreciated(array $filters): array
    {
        [$columns, $rows] = $this->register($filters);
        $rows = $rows->filter(fn (array $row): bool => bccomp($row['cost'], '0', 4) > 0 && bccomp($row['net_book_value'], $row['residual_value'], 4) <= 0);
        $keys = ['asset', 'name', 'currency', 'cost', 'residual_value', 'accumulated_depreciation', 'net_book_value', 'service_date', 'status', 'branch', 'hall_location'];

        return [
            array_intersect_key($columns, array_flip($keys)),
            $rows->map(fn (array $row): array => [...array_intersect_key($row, array_flip($keys)), '_asset_url' => $row['_asset_url'] ?? null]),
        ];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>} */
    private function exceptions(array $filters): array
    {
        $through = Carbon::parse($filters['to_date'] ?? now())->startOfDay();
        $cutoff = $through->isSameDay($through->copy()->endOfMonth()) ? $through : $through->copy()->startOfMonth()->subDay();
        $rows = collect();
        foreach ($this->reportAssets($filters) as $asset) {
            $reasons = [];
            $position = $this->bookValues->position($asset, $through);
            if (! $asset->hasPostedRecognition()) {
                $reasons[] = __('fixed_assets.cycle.pending_recognition');
            }
            if (bccomp($position['net_book_value'], $position['residual_value'], 4) < 0 || bccomp($position['accumulated_depreciation'], '0', 4) < 0) {
                $reasons[] = __('fixed_assets.cycle.invalid_basis');
            }
            if ($asset->is_depreciable && ! $asset->isDisposed()) {
                try {
                    FixedAssetCategoryMapping::resolveForAsset($asset, FixedAssetCategoryMapping::DepreciationAccounts);
                } catch (\DomainException $exception) {
                    $reasons[] = $exception->getMessage();
                }
                $next = $this->depreciation->nextUnpostedDate($asset, $through);
                if ($next && $next->lte($cutoff) && bccomp($position['remaining_depreciable_amount'], '0', 4) > 0) {
                    $reasons[] = __('fixed_assets.cycle.missing_period', ['period' => $next->format('Y-m')]);
                }
            }
            foreach (array_unique($reasons) as $reason) {
                $rows->push(['_asset_url' => auth()->user()?->can('fixed_assets.view') ? route('admin.fixed-assets.assets.show', $asset) : null, 'asset' => $asset->doc_num, 'name' => $asset->asset_name, 'reason' => $reason]);
            }
        }

        return [$this->labels(['asset', 'name', 'reason']), $rows];
    }

    private function reconciliation(array $filters): array
    {
        $companyId = $this->companies->requireCompanyId();
        $selectedIds = $this->reportAssets($filters, false)->modelKeys();
        $accountFilters = array_diff_key($filters, array_flip(['asset_doc_num', 'asset_group_account_doc_num', 'status', 'entry_type', 'depreciable', 'branch_hall_uuid']));
        $assets = $this->reportAssets($accountFilters, false);
        $entries = collect();
        $chart = Account::query()->where('company_id', $companyId)->with('classification')->get()->keyBy('id');
        foreach ($assets as $asset) {
            $mapping = $asset->categoryMapping;
            try {
                $mapping = FixedAssetCategoryMapping::resolveForAsset($asset, FixedAssetCategoryMapping::DepreciationAccounts, $chart);
            } catch (\DomainException) {
                // An unresolved chart must not hide existing historical account snapshots.
            }
            if ($asset->hasLegacyRecognition()) {
                $baseline = $asset->legacy_recognition;
                $common = ['asset_id' => $asset->getKey(), 'date' => Carbon::parse($baseline['asset_date']), 'period_id' => $baseline['period_id'], 'branch_id' => $baseline['branch_id'], 'cost_center_id' => $baseline['cost_center_id']];
                $entries->push([...$common, 'account_id' => $baseline['account_id'], 'type' => 'asset_cost', 'amount' => (string) ($baseline['base_acquisition_value'] ?? bcmul((string) $baseline['purchase_value'], (string) $baseline['exchange_rate'], 4))]);
                if (bccomp((string) $baseline['previous_depreciation'], '0', 4) > 0) {
                    $entries->push([...$common, 'account_id' => $mapping?->accumulated_depreciation_account_id, 'type' => 'accumulated_depreciation', 'amount' => bcmul((string) $baseline['previous_depreciation'], (string) $baseline['exchange_rate'], 4)]);
                }
            }
            foreach ($asset->costMovements->where('status', 'posted') as $movement) {
                $common = ['asset_id' => $asset->getKey(), 'date' => $movement->movement_date, 'period_id' => $movement->financial_period_id, 'branch_id' => $movement->source_branch_id, 'cost_center_id' => $movement->source_cost_center_id];
                $entries->push([...$common, 'account_id' => data_get($movement->snapshot, 'account_id', $asset->account_id), 'type' => 'asset_cost', 'amount' => (string) $movement->base_amount]);
                $accountId = data_get($movement->snapshot, 'accumulated_account_id');
                if ($accountId && bccomp((string) $movement->base_opening_accumulated, '0', 4) > 0) {
                    $entries->push([...$common, 'account_id' => $accountId, 'type' => 'accumulated_depreciation', 'amount' => (string) $movement->base_opening_accumulated]);
                }
            }
            if (! $asset->hasPostedRecognition()) {
                $entries->push(['asset_id' => $asset->getKey(), 'date' => $asset->asset_date, 'period_id' => $asset->period_id, 'branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id, 'account_id' => $asset->account_id, 'type' => 'asset_cost', 'amount' => '0.0000']);
            }
            foreach ($asset->postedDepreciations as $depreciation) {
                $common = ['asset_id' => $asset->getKey(), 'date' => $depreciation->period_end, 'period_id' => $depreciation->financial_period_id, 'branch_id' => $depreciation->branch_id, 'cost_center_id' => $depreciation->cost_center_id, 'amount' => (string) $depreciation->base_period_depreciation];
                $entries->push([...$common, 'account_id' => $depreciation->accumulated_account_id ?: $mapping?->accumulated_depreciation_account_id, 'type' => 'accumulated_depreciation']);
                $entries->push([...$common, 'account_id' => $depreciation->expense_account_id ?: $mapping?->depreciation_expense_account_id, 'type' => 'period_depreciation']);
            }
            foreach ($asset->disposals->where('status', 'posted') as $disposal) {
                $costLine = $disposal->journalEntry?->lines->where('account_id', $asset->account_id)->first();
                $common = ['asset_id' => $asset->getKey(), 'date' => $disposal->disposal_date, 'period_id' => $disposal->financial_period_id, 'branch_id' => $disposal->branch_id ?: $costLine?->branch_id, 'cost_center_id' => $disposal->cost_center_id ?? $costLine?->cost_center_id];
                $entries->push([...$common, 'account_id' => $asset->account_id, 'type' => 'asset_cost', 'amount' => bcmul((string) $disposal->base_original_cost, '-1', 4)]);
                $entries->push([...$common, 'account_id' => $disposal->accumulated_account_id ?: $mapping?->accumulated_depreciation_account_id, 'type' => 'accumulated_depreciation', 'amount' => bcmul((string) $disposal->base_accumulated_depreciation, '-1', 4)]);
            }
        }
        $allowedBranchIds = app(FixedAssetAccessService::class)->branchIds();
        $entries = $entries->filter(fn (array $entry): bool => $entry['account_id'] && $this->entryMatches($entry, $filters, $allowedBranchIds));
        $accounts = Account::withTrashed()->where('company_id', $companyId)->with('classification')->get()->keyBy('id');
        $balances = $this->glBalances($companyId, $accounts->modelKeys(), $filters);
        $rows = collect();
        foreach ($entries->groupBy(fn (array $entry): string => $entry['type'].':'.$entry['account_id']) as $group) {
            if (! $group->contains(fn (array $entry): bool => in_array($entry['asset_id'], $selectedIds, true))) {
                continue;
            }
            $first = $group->first();
            $subledger = $group->reduce(fn (string $sum, array $entry): string => bcadd($sum, $entry['amount'], 4), '0.0000');
            $account = $accounts->get($first['account_id']);
            $gl = (string) ($balances[$first['account_id']] ?? '0.0000');
            if ($first['type'] === 'accumulated_depreciation') {
                $gl = bcmul($gl, '-1', 4);
            }
            $rows->push($this->reconciliationRow($first['type'], $account, $subledger, $gl));
        }

        if (! array_intersect_key($filters, array_flip(['asset_doc_num', 'asset_group_account_doc_num', 'status', 'entry_type', 'depreciable', 'branch_hall_uuid']))) {
            $accountIds = $entries->pluck('account_id')->unique();
            $unmatched = $accounts->filter(fn (Account $account): bool => $account->is_postable && ! $accountIds->contains($account->getKey()) && in_array($account->classification?->code, ['fixed_assets', 'accumulated_depreciation'], true));
            foreach ($unmatched as $account) {
                $costAccount = $account->classification->code === 'fixed_assets';
                $gl = (string) ($balances[$account->getKey()] ?? '0.0000');
                if (! $costAccount) {
                    $gl = bcmul($gl, '-1', 4);
                }
                if (bccomp($gl, '0', 4) !== 0) {
                    $rows->push($this->reconciliationRow($costAccount ? 'asset_cost' : 'accumulated_depreciation', $account, '0.0000', $gl));
                }
            }
        }

        return [[...$this->labels(['reconciliation_type', 'account', 'subledger', 'general_ledger', 'difference', 'state']), 'scope' => __('fixed_assets.cycle.reconciliation_scope')], $rows];
    }

    private function entryMatches(array $entry, array $filters, ?array $allowedBranchIds = null): bool
    {
        $companyId = $this->companies->requireCompanyId();
        if (! in_array((int) $entry['branch_id'], $allowedBranchIds ?? app(FixedAssetAccessService::class)->branchIds(), true)) {
            return false;
        }
        foreach (['branch_doc_num' => ['branches', 'branch_id'], 'cost_center_doc_num' => ['cost_centers', 'cost_center_id'], 'financial_period_doc_num' => ['financial_periods', 'period_id']] as $filter => [$table, $field]) {
            if (isset($filters[$filter]) && (int) $entry[$field] !== (int) $this->lookupId($table, $companyId, $filters[$filter])) {
                if ($field === 'period_id' && $entry[$field] === null) {
                    $period = FinancialPeriod::query()->where('company_id', $companyId)->where('doc_num', $filters[$filter])->first();
                    if ($period && $entry['date']->betweenIncluded($period->from_date, $period->to_date)) {
                        continue;
                    }
                }

                return false;
            }
        }

        return (! isset($filters['from_date']) || $entry['date']->toDateString() >= $filters['from_date'])
            && $entry['date']->toDateString() <= ($filters['to_date'] ?? now()->toDateString());
    }

    /** @return Collection<int, FixedAsset> */
    private function reportAssets(array $filters, bool $dimensions = true): Collection
    {
        $query = FixedAsset::query()->forCompany($this->companies->requireCompanyId())->with(['account', 'assetGroupAccount', 'branch', 'branchHall', 'costCenter', 'currency', 'categoryMapping', 'costMovements.journalEntry', 'disposals.journalEntry.lines', 'postedDepreciations', 'movements.sourceBranch', 'movements.destinationBranch', 'movements.sourceBranchHall', 'movements.destinationBranchHall', 'movements.sourceCostCenter', 'movements.destinationCostCenter']);
        $baseFilters = array_diff_key($filters, array_flip(['from_date', 'to_date', 'branch_doc_num', 'branch_hall_uuid', 'cost_center_doc_num', 'status']));
        $this->assetFilters($query, $baseFilters);
        $through = Carbon::parse($filters['to_date'] ?? now())->endOfDay();
        $query->where(fn ($dates) => $dates->whereDate('asset_date', '<=', $through)
            ->orWhereHas('costMovements', fn ($movements) => $movements->where('status', 'posted')->whereDate('movement_date', '<=', $through)));

        $allowedBranchIds = app(FixedAssetAccessService::class)->branchIds();

        return $query->get()->map(fn (FixedAsset $asset): FixedAsset => $this->assetAt($asset, $through))->filter(function (FixedAsset $asset) use ($filters, $dimensions, $allowedBranchIds): bool {
            if ($dimensions && ! in_array((int) $asset->branch_id, $allowedBranchIds, true)) {
                return false;
            }
            if (! $dimensions && ! in_array((int) $asset->branch_id, $allowedBranchIds, true) && ! $asset->movements->contains(fn ($row): bool => in_array((int) $row->source_branch_id, $allowedBranchIds, true))) {
                return false;
            }
            if (isset($filters['status']) && $asset->status !== $filters['status']) {
                return false;
            }
            if (! $dimensions) {
                return true;
            }
            foreach (['branch_doc_num' => ['branches', 'branch_id'], 'cost_center_doc_num' => ['cost_centers', 'cost_center_id']] as $filter => [$table, $field]) {
                if (isset($filters[$filter]) && (int) $asset->{$field} !== (int) $this->lookupId($table, (int) $asset->company_id, $filters[$filter])) {
                    return false;
                }
            }

            return ! isset($filters['branch_hall_uuid']) || (int) $asset->branch_hall_id === (int) $this->hallId((int) $asset->company_id, $filters['branch_hall_uuid']);
        })->values();
    }

    private function assetAt(FixedAsset $original, Carbon $through): FixedAsset
    {
        $asset = clone $original;
        $future = $asset->movements->filter(fn ($row): bool => $row->movement_type === 'transfer' && $row->status === 'posted' && $row->movement_date->gt($through))->sortBy([['movement_date', 'asc'], ['id', 'asc']])->first();
        if ($future) {
            foreach (['branch_id', 'branch_hall_id', 'cost_center_id', 'location_address'] as $field) {
                $asset->{$field} = $future->{'source_'.$field};
            }
            $asset->setRelation('branch', $future->sourceBranch)->setRelation('branchHall', $future->sourceBranchHall)->setRelation('costCenter', $future->sourceCostCenter);
        }
        $disposal = $asset->disposals->where('status', 'posted')->first();
        if ($disposal && $disposal->disposal_date->gt($through)) {
            $asset->status = $disposal->asset_status_before;
            $asset->disposed_at = null;
        }
        if (in_array($asset->status, [FixedAsset::StatusActive, FixedAsset::StatusFullyDepreciated], true) && $asset->is_depreciable) {
            $position = $this->bookValues->position($asset, $through);
            if ($position['recognized']) {
                $asset->status = bccomp($position['remaining_depreciable_amount'], '0', 4) <= 0 ? FixedAsset::StatusFullyDepreciated : FixedAsset::StatusActive;
            }
        }

        return $asset;
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

        return $docNum === '' ? null : (int) (DB::table($table)->where('company_id', $companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->value('id') ?? -1);
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

    private function glBalances(int $companyId, array $accountIds, array $filters): Collection
    {
        $balances = DB::table('journal_entry_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)->whereNull('journal_entries.deleted_at')->where('journal_entries.is_posted', true)->whereIn('journal_entry_lines.account_id', $accountIds)
            ->whereIn('journal_entry_lines.branch_id', app(FixedAssetAccessService::class)->branchIds())
            ->when($this->lookupId('branches', $companyId, $filters['branch_doc_num'] ?? null), fn ($query, $id) => $query->where('journal_entry_lines.branch_id', $id))
            ->when($this->lookupId('cost_centers', $companyId, $filters['cost_center_doc_num'] ?? null), fn ($query, $id) => $query->where('journal_entry_lines.cost_center_id', $id))
            ->when($this->lookupId('financial_periods', $companyId, $filters['financial_period_doc_num'] ?? null), fn ($query, $id) => $query->where('journal_entries.financial_period_id', $id))
            ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate('journal_entries.entry_date', '>=', $date))
            ->whereDate('journal_entries.entry_date', '<=', $filters['to_date'] ?? now()->toDateString())
            ->groupBy('journal_entry_lines.account_id')
            ->selectRaw('journal_entry_lines.account_id, COALESCE(SUM((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate), 0) as balance')->pluck('balance', 'account_id');

        return $balances->map(fn ($balance): string => is_float($balance) ? number_format($balance, 4, '.', '') : (string) $balance);
    }

    private function reconciliationRow(string $type, ?Account $account, string $subledger, string $gl): array
    {
        $difference = bcsub($subledger, $gl, 4);

        return [
            '_account_url' => $account && auth()->user()?->can('accounts.view') ? route('admin.accounting.accounts.show', $account) : null,
            'reconciliation_type' => __('fixed_assets.reports.reconciliation_types.'.$type),
            'account' => $account?->codeNameLabel(),
            'subledger' => $subledger,
            'general_ledger' => $gl,
            'difference' => $difference,
            'state' => $this->reconciliationState($difference),
            '_row_state' => bccomp($difference, '0', 4) === 0 ? 'reconciled' : 'difference',
            'scope' => __('fixed_assets.cycle.account_total_scope'),
        ];
    }

    private function reconciliationState(string $difference): string
    {
        return bccomp($difference, '0', 4) === 0
            ? __('fixed_assets.pdf.reconciliation.reconciled')
            : __('fixed_assets.pdf.reconciliation.difference');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function filterSummary(array $filters): array
    {
        $companyId = $this->companies->requireCompanyId();
        $summary = [];

        if (isset($filters['from_date'])) {
            $summary[__('fixed_assets.reports.from_date')] = $this->dates->formatDate($filters['from_date'], '');
        }

        if (isset($filters['to_date'])) {
            $summary[__('fixed_assets.reports.to_date')] = $this->dates->formatDate($filters['to_date'], '');
        }

        foreach ([
            'asset_doc_num' => ['fixed_assets.reports.columns.asset', 'fixed_assets', 'asset_name'],
            'asset_group_account_doc_num' => ['fixed_assets.attributes.asset_group_account', 'accounts', 'name'],
            'financial_period_doc_num' => ['fixed_assets.lifecycle.financial_period', 'financial_periods', 'name'],
            'branch_doc_num' => ['fixed_assets.attributes.branch', 'branches', 'name'],
            'cost_center_doc_num' => ['fixed_assets.attributes.cost_center', 'cost_centers', 'name'],
        ] as $filter => [$labelKey, $table, $nameColumn]) {
            if (! isset($filters[$filter])) {
                continue;
            }

            $summary[__($labelKey)] = $this->documentLabel($table, $nameColumn, $companyId, (string) $filters[$filter]);
        }

        if (isset($filters['branch_hall_uuid'])) {
            $summary[__('fixed_assets.attributes.hall')] = (string) DB::table('branch_halls')
                ->join('branches', 'branches.id', '=', 'branch_halls.branch_id')
                ->where('branches.company_id', $companyId)
                ->where('branch_halls.public_uuid', $filters['branch_hall_uuid'])
                ->value('branch_halls.name');
        }

        if (isset($filters['status'])) {
            $summary[__('fixed_assets.attributes.status')] = __('fixed_assets.statuses.'.$filters['status']);
        }

        if (isset($filters['entry_type'])) {
            $summary[__('fixed_assets.attributes.entry_type')] = __('fixed_assets.entry_types.'.$filters['entry_type']);
        }

        if (isset($filters['depreciable'])) {
            $summary[__('fixed_assets.attributes.is_depreciable')] = filter_var($filters['depreciable'], FILTER_VALIDATE_BOOL)
                ? __('fixed_assets.booleans.yes')
                : __('fixed_assets.booleans.no');
        }

        if (isset($filters['posting_status'])) {
            $summary[__('fixed_assets.pdf.filters.posting_status')] = __('fixed_assets.lifecycle.statuses.'.$filters['posting_status']);
        }

        if (isset($filters['movement_type'])) {
            $summary[__('fixed_assets.cycle.type')] = __('fixed_assets.cycle.'.$filters['movement_type']);
        }

        if (isset($filters['user'])) {
            $summary[__('fixed_assets.cycle.user')] = $filters['user'];
        }

        return array_filter($summary, fn (string $value): bool => trim($value) !== '');
    }

    private function documentLabel(string $table, string $nameColumn, int $companyId, string $docNum): string
    {
        $row = DB::table($table)
            ->where('company_id', $companyId)
            ->where('doc_num', $docNum)
            ->first(['doc_num', $nameColumn]);

        if (! $row) {
            return $docNum;
        }

        return trim(implode(' / ', array_filter([(string) $row->doc_num, (string) $row->{$nameColumn}])));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function totals(string $type, Collection $rows): array
    {
        if ($rows->pluck('currency')->filter()->unique()->count() > 1) {
            return [];
        }

        return match ($type) {
            self::Register, self::FullyDepreciated, 'net_book_value' => [
                __('fixed_assets.pdf.totals.asset_cost') => $this->sumRows($rows, 'cost'),
                __('fixed_assets.pdf.totals.accumulated_depreciation') => $this->sumRows($rows, 'accumulated_depreciation'),
                __('fixed_assets.pdf.totals.net_book_value') => $this->sumRows($rows, 'net_book_value'),
            ],
            self::Depreciation, self::Schedule => [
                __('fixed_assets.pdf.totals.period_depreciation') => $this->sumRows($rows, 'period_depreciation'),
            ],
            self::AdditionsDisposals, 'capital_additions', 'disposals', 'gain_loss' => [
                __('fixed_assets.pdf.totals.additions') => $this->sumRows($rows, 'addition_value'),
                __('fixed_assets.pdf.totals.disposal_proceeds') => $this->sumRows($rows, 'disposal_proceeds'),
                __('fixed_assets.pdf.totals.disposal_expenses') => $this->sumRows($rows, 'disposal_expenses'),
                __('fixed_assets.pdf.totals.net_proceeds') => $this->sumRows($rows, 'net_proceeds'),
                __('fixed_assets.pdf.totals.gains') => $this->sumRows($rows, 'gain'),
                __('fixed_assets.pdf.totals.losses') => $this->sumRows($rows, 'loss'),
            ],
            default => [],
        };
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function sumRows(Collection $rows, string $key): string
    {
        return $rows->reduce(
            fn (string $total, array $row): string => bcadd($total, (string) (data_get($row, $key) ?: '0'), 4),
            '0.0000',
        );
    }

    /** @param list<string> $keys */
    private function labels(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => __('fixed_assets.reports.columns.'.$key)])->all();
    }

    private function emptyRegisterRow(): array
    {
        return array_fill_keys(['asset', 'name', 'classification', 'serial', 'currency', 'acquisition_date', 'service_date', 'cost', 'accumulated_depreciation', 'net_book_value', 'residual_value', 'branch', 'hall_location', 'cost_center', 'status'], '');
    }
}
