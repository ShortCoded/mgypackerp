<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;

class FixedAssetPurchaseIntegrationService
{
    public const SourceType = 'purchase_invoice_line';

    public const ImprovementSourceType = 'purchase_invoice_line_improvement';

    public const TreatmentNone = 'none';

    public const TreatmentNewAsset = 'new_asset';

    public const TreatmentCapitalImprovement = 'capital_improvement';

    public function __construct(
        private readonly OperatingContextService $operatingContext,
        private readonly PurchaseInvoiceCalculationService $calculator,
        private readonly FixedAssetLifecycleService $lifecycle,
        private readonly FixedAssetCostMovementService $costMovements,
        private readonly CrudAuditService $audit,
    ) {}

    public function sourceLine(string $publicId, bool $requireDraftInvoice = false): PurchaseInvoiceLine
    {
        $context = $this->operatingContext->snapshot(request());
        $line = PurchaseInvoiceLine::query()
            ->where('company_id', (int) ($context['company_id'] ?? 0))
            ->where('public_id', trim($publicId))
            ->with([
                'product.unit', 'unit', 'costCenter',
                'purchaseInvoice.supplier.account', 'purchaseInvoice.branch', 'purchaseInvoice.currency',
                'purchaseOrderLine.purchaseOrder', 'receiptLine.receipt', 'fixedAssets.account', 'targetFixedAsset.account', 'assetImprovementMovement',
            ])
            ->firstOrFail();

        $invoice = $line->purchaseInvoice;
        if (! $invoice instanceof PurchaseInvoice
            || (int) $invoice->branch_id !== (int) ($context['branch_id'] ?? 0)
            || ($requireDraftInvoice && ! $invoice->isDraft())) {
            throw new DomainException(__('fixed_assets.purchase_source.invoice_must_be_draft'));
        }

        if (! $line->product instanceof Product || $line->product->isService() || $line->product->cost_as_inventory) {
            throw new DomainException(__('fixed_assets.purchase_source.non_inventory_required'));
        }

        return $line;
    }

    /** @param array<string, mixed> $data */
    public function configureTreatment(PurchaseInvoice $invoice, array $data): PurchaseInvoiceLine
    {
        return DB::transaction(function () use ($invoice, $data): PurchaseInvoiceLine {
            $context = $this->operatingContext->snapshot(request());
            $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            if (! $invoice->isDraft()
                || (int) $invoice->company_id !== (int) ($context['company_id'] ?? 0)
                || (int) $invoice->branch_id !== (int) ($context['branch_id'] ?? 0)) {
                throw new DomainException(__('fixed_assets.purchase_source.invoice_must_be_draft'));
            }

            $line = $invoice->lines()->with(['product', 'fixedAssets'])->where('public_id', $data['line_public_id'])->lockForUpdate()->firstOrFail();
            $this->assertEligibleLine($line);
            $treatment = (string) $data['asset_treatment'];
            if ($line->fixedAssets->isNotEmpty() && $treatment !== self::TreatmentNewAsset) {
                throw new DomainException(__('fixed_assets.purchase_source.remove_assets_first'));
            }

            $values = ['asset_treatment' => $treatment === self::TreatmentNone ? null : $treatment];
            if ($treatment === self::TreatmentCapitalImprovement) {
                $target = $this->improvementTarget($line, (string) $data['target_fixed_asset_doc_num']);
                $effectiveDate = Carbon::parse($data['asset_effective_date'])->startOfDay();
                if ($effectiveDate->lt($invoice->invoice_date)) {
                    throw new DomainException(__('fixed_assets.purchase_source.improvement_effective_date'));
                }
                app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $invoice->company_id, $effectiveDate);
                $values['target_fixed_asset_id'] = $target->getKey();
                $values['asset_effective_date'] = $effectiveDate;
            } else {
                $values['target_fixed_asset_id'] = null;
                $values['asset_effective_date'] = null;
            }

            $line->forceFill([...$values, 'updated_by' => auth()->id()])->save();

            return $line->refresh()->load(['fixedAssets.account', 'targetFixedAsset.account', 'assetImprovementMovement']);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function defaults(PurchaseInvoiceLine $line): array
    {
        $invoice = $line->purchaseInvoice;
        $remaining = bcsub($this->lineNetAmount($line), $this->allocatedAmount($line), 4);
        $date = $invoice->invoice_date?->toDateString();

        return [
            'source_type' => self::SourceType,
            'source_id' => $line->getKey(),
            'source_doc_num' => $invoice->doc_num,
            'asset_name' => $line->product?->name,
            'description' => __('fixed_assets.purchase_source.description', [
                'item' => $line->product?->name,
                'invoice' => $invoice->doc_num,
            ]),
            'asset_date' => $date,
            'purchase_date' => $date,
            'acquisition_date' => $date,
            'operation_date' => $date,
            'purchase_value' => bccomp($remaining, '0', 4) > 0 ? $remaining : null,
            'status' => FixedAsset::StatusDraft,
            'branch_option' => $invoice->branch ? [
                'id' => $invoice->branch->doc_num,
                'text' => trim(implode(' / ', array_filter([$invoice->branch->doc_num, $invoice->branch->name]))),
            ] : null,
            'currency_option' => $invoice->currency ? [
                'id' => $invoice->currency->doc_num,
                'text' => trim(implode(' / ', array_filter([$invoice->currency->code, $invoice->currency->name]))),
            ] : null,
            'credit_account_option' => $invoice->supplier?->account ? [
                'id' => $invoice->supplier->account->doc_num,
                'text' => $invoice->supplier->account->codeNameLabel(),
            ] : null,
            'cost_center_option' => $line->costCenter ? [
                'id' => $line->costCenter->doc_num,
                'text' => $line->costCenter->codeNameLabel(),
            ] : null,
            'exchange_rate' => $invoice->exchange_rate,
        ];
    }

    /** @param array<string, mixed> $data */
    public function assertAssetPayload(array $data, ?FixedAsset $current = null): void
    {
        if (($data['source_type'] ?? null) !== self::SourceType) {
            return;
        }

        $line = PurchaseInvoiceLine::query()
            ->with(['purchaseInvoice.supplier.account', 'purchaseInvoice.branch', 'purchaseInvoice.currency', 'product', 'fixedAssets'])
            ->lockForUpdate()
            ->find((int) ($data['source_id'] ?? 0));

        if (! $line instanceof PurchaseInvoiceLine) {
            throw new DomainException(__('fixed_assets.purchase_source.invalid'));
        }

        $invoice = $line->purchaseInvoice;
        $context = $this->operatingContext->snapshot(request());
        if (! $invoice instanceof PurchaseInvoice || ! $invoice->isDraft()
            || (int) $invoice->company_id !== (int) ($context['company_id'] ?? 0)
            || (int) $invoice->branch_id !== (int) ($context['branch_id'] ?? 0)) {
            throw new DomainException(__('fixed_assets.purchase_source.invoice_must_be_draft'));
        }

        if (! $line->product instanceof Product || $line->product->isService() || $line->product->cost_as_inventory) {
            throw new DomainException(__('fixed_assets.purchase_source.non_inventory_required'));
        }

        $invoiceDate = $invoice->invoice_date?->toDateString();
        $operationDate = filled($data['operation_date'] ?? null) ? Carbon::parse($data['operation_date'])->toDateString() : null;
        $matchesSource = ($data['entry_type'] ?? null) === FixedAsset::EntryTypeNewAsset
            && ($data['status'] ?? null) === FixedAsset::StatusDraft
            && ($data['source_doc_num'] ?? null) === $invoice->doc_num
            && ($data['branch_doc_num'] ?? null) === $invoice->branch?->doc_num
            && ($data['currency_doc_num'] ?? null) === $invoice->currency?->doc_num
            && ($data['credit_account_doc_num'] ?? null) === $invoice->supplier?->account?->doc_num
            && bccomp((string) ($data['exchange_rate'] ?? '0'), (string) $invoice->exchange_rate, 6) === 0
            && ($data['asset_date'] ?? null) === $invoiceDate
            && ($data['purchase_date'] ?? null) === $invoiceDate
            && ($data['acquisition_date'] ?? null) === $invoiceDate
            && ($operationDate === null || $operationDate >= $invoiceDate);

        if (! $matchesSource) {
            throw new DomainException(__('fixed_assets.purchase_source.context_mismatch'));
        }

        $allocated = $line->fixedAssets
            ->reject(fn (FixedAsset $asset): bool => $current instanceof FixedAsset && (int) $asset->getKey() === (int) $current->getKey())
            ->reduce(fn (string $sum, FixedAsset $asset): string => bcadd($sum, (string) $asset->purchase_value, 4), '0.0000');
        $requested = (string) ($data['purchase_value'] ?? '0');

        if (bccomp(bcadd($allocated, $requested, 4), $this->lineNetAmount($line), 4) > 0) {
            throw new DomainException(__('fixed_assets.purchase_source.allocation_exceeds_line'));
        }
    }

    public function assertDraftInvoiceAssetsValid(PurchaseInvoice $invoice): void
    {
        $invoice->loadMissing('lines.product', 'lines.fixedAssets', 'lines.targetFixedAsset.account', 'lines.assetImprovementMovement');

        foreach ($invoice->lines as $line) {
            $hasNewAssets = $line->fixedAssets->isNotEmpty();
            $isImprovement = $line->asset_treatment === self::TreatmentCapitalImprovement;
            if (! $hasNewAssets && ! $isImprovement && $line->asset_treatment !== self::TreatmentNewAsset) {
                continue;
            }

            $this->assertEligibleLine($line);

            if ($isImprovement) {
                if ($hasNewAssets || ! $line->targetFixedAsset instanceof FixedAsset || $line->assetImprovementMovement?->status === FixedAssetMovement::StatusReversed) {
                    throw new DomainException(__('fixed_assets.purchase_source.improvement_context_mismatch'));
                }
                $this->improvementTarget($line, $line->targetFixedAsset->doc_num);
                if (! $line->asset_effective_date || $line->asset_effective_date->lt($invoice->invoice_date)
                    || bccomp($this->lineNetAmount($line), '0', 4) <= 0) {
                    throw new DomainException(__('fixed_assets.purchase_source.improvement_effective_date'));
                }
                app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $invoice->company_id, $line->asset_effective_date);

                continue;
            }

            if ($line->target_fixed_asset_id !== null || bccomp($this->allocatedAmount($line), $this->lineNetAmount($line), 4) > 0) {
                throw new DomainException(__('fixed_assets.purchase_source.allocation_exceeds_line'));
            }
        }
    }

    /** @return Collection<int, array{asset: FixedAsset, amount: string, description: string, cost_center_id: int|null}> */
    public function postingsForLine(PurchaseInvoiceLine $line, string $lineAmount): Collection
    {
        if ($line->asset_treatment === self::TreatmentCapitalImprovement) {
            if (! auth()->user()?->can('fixed_assets.improvement.post')) {
                throw new DomainException(__('fixed_assets.purchase_source.improvement_permission_required'));
            }
            $asset = $this->improvementTarget($line, (string) $line->targetFixedAsset?->doc_num);
            if (bccomp($lineAmount, '0', 4) <= 0) {
                throw new DomainException(__('fixed_assets.purchase_source.improvement_amount_required'));
            }

            return collect([[
                'asset' => $asset,
                'amount' => $lineAmount,
                'description' => __('fixed_assets.purchase_source.improvement_journal_line', ['asset' => $asset->doc_num]),
                'cost_center_id' => $asset->cost_center_id,
            ]]);
        }

        $assets = $this->assetsForPosting($line, $lineAmount);

        return $assets->map(fn (FixedAsset $asset): array => [
            'asset' => $asset,
            'amount' => (string) $asset->purchase_value,
            'description' => __('fixed_assets.purchase_source.journal_line', ['asset' => $asset->doc_num]),
            'cost_center_id' => $asset->cost_center_id,
        ])->values();
    }

    /** @return Collection<int, FixedAsset> */
    public function assetsForPosting(PurchaseInvoiceLine $line, string $lineAmount): Collection
    {
        $assets = $line->fixedAssets()->with('account')->lockForUpdate()->get();
        if ($assets->isEmpty()) {
            if ($line->asset_treatment === self::TreatmentNewAsset) {
                throw new DomainException(__('fixed_assets.purchase_source.allocation_must_match_line'));
            }

            return $assets;
        }

        if ($assets->contains(fn (FixedAsset $asset): bool => ! $asset->account
                || $asset->account->is_group
                || ! $asset->account->is_postable
                || $asset->account->status !== 'active'
                || $asset->status !== FixedAsset::StatusDraft
                || $asset->hasPostedRecognition())
            || bccomp($assets->reduce(fn (string $sum, FixedAsset $asset): string => bcadd($sum, (string) $asset->purchase_value, 4), '0.0000'), $lineAmount, 4) !== 0) {
            throw new DomainException(__('fixed_assets.purchase_source.allocation_must_match_line'));
        }

        return $assets;
    }

    public function recognize(PurchaseInvoice $invoice, JournalEntry $journal): void
    {
        $invoice->loadMissing('supplier.account', 'lines.fixedAssets', 'lines.targetFixedAsset.currency', 'lines.assetImprovementMovement');
        foreach ($invoice->lines->flatMap->fixedAssets as $asset) {
            if (! $asset->hasPostedRecognition()) {
                $this->lifecycle->activate($asset, $invoice->invoice_date->toDateString(), $journal->doc_num);
            }
        }

        foreach ($invoice->lines->where('asset_treatment', self::TreatmentCapitalImprovement) as $line) {
            if ($line->assetImprovementMovement?->status === FixedAssetMovement::StatusPosted) {
                continue;
            }

            $asset = $this->improvementTarget($line, (string) $line->targetFixedAsset?->doc_num);
            $this->costMovements->addition($asset, [
                'submission_key' => $line->public_id,
                'movement_date' => $line->asset_effective_date->toDateString(),
                'amount' => $this->lineNetAmount($line),
                'exchange_rate' => $invoice->exchange_rate,
                'counter_account_doc_num' => $invoice->supplier?->account?->doc_num,
                'description' => __('fixed_assets.purchase_source.improvement_description', [
                    'invoice' => $invoice->doc_num,
                    'asset' => $asset->doc_num,
                ]),
                'notes' => $line->notes,
            ], $journal, [
                'source_type' => self::ImprovementSourceType,
                'source_id' => $line->getKey(),
                'source_doc_num' => $invoice->doc_num,
            ]);
        }
    }

    public function assertReversible(PurchaseInvoice $invoice): void
    {
        $invoice->loadMissing('lines.fixedAssets.postedDepreciations', 'lines.fixedAssets.movements', 'lines.fixedAssets.disposals', 'lines.assetImprovementMovement.asset');

        foreach ($invoice->lines->flatMap->fixedAssets as $asset) {
            $hasLaterActivity = $asset->postedDepreciations->isNotEmpty()
                || $asset->disposals->isNotEmpty()
                || $asset->movements->contains(fn (FixedAssetMovement $movement): bool => $movement->status === FixedAssetMovement::StatusPosted
                    && $movement->movement_type !== FixedAssetMovement::TypeCapitalization);
            if ($hasLaterActivity) {
                throw new DomainException(__('fixed_assets.purchase_source.reverse_downstream_first'));
            }
        }

        $improvements = $invoice->lines->pluck('assetImprovementMovement')->filter(fn ($movement): bool => $movement?->status === FixedAssetMovement::StatusPosted);
        if ($improvements->isNotEmpty() && ! auth()->user()?->can('fixed_assets.improvement.reverse')) {
            throw new DomainException(__('fixed_assets.purchase_source.improvement_reverse_permission_required'));
        }
        $batchMovementIds = $improvements->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($improvements->sortByDesc(fn (FixedAssetMovement $movement): string => $movement->movement_date->format('Y-m-d').str_pad((string) $movement->getKey(), 20, '0', STR_PAD_LEFT)) as $movement) {
            $this->costMovements->assertExternalAdditionReversible($movement, $batchMovementIds);
        }
    }

    public function reverseRecognitions(PurchaseInvoice $invoice, JournalEntry $reversal, string $reason): void
    {
        $invoice->loadMissing('lines.fixedAssets', 'lines.assetImprovementMovement.asset');
        foreach ($invoice->lines->flatMap->fixedAssets as $asset) {
            $movement = $asset->costMovements()
                ->where('movement_type', FixedAssetMovement::TypeCapitalization)
                ->where('journal_entry_id', $invoice->journal_entry_id)
                ->where('status', FixedAssetMovement::StatusPosted)
                ->lockForUpdate()
                ->first();
            if (! $movement instanceof FixedAssetMovement) {
                continue;
            }

            $movement->forceFill([
                'status' => FixedAssetMovement::StatusReversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversal_date' => $reversal->entry_date,
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ])->save();
            $this->audit->saveUpdate($asset, [
                'capitalized_at' => null,
                'capitalized_by' => null,
                'locked_at' => null,
                'status' => FixedAsset::StatusDraft,
                'net_value' => $asset->purchase_value,
            ]);
        }

        $improvements = $invoice->lines->pluck('assetImprovementMovement')->filter(fn ($movement): bool => $movement?->status === FixedAssetMovement::StatusPosted)
            ->sortByDesc(fn (FixedAssetMovement $movement): string => $movement->movement_date->format('Y-m-d').str_pad((string) $movement->getKey(), 20, '0', STR_PAD_LEFT));
        foreach ($improvements as $movement) {
            $this->costMovements->reverseExternalAddition($movement, $reversal, $reason);
        }
    }

    public function lineNetAmount(PurchaseInvoiceLine $line): string
    {
        $invoice = $line->purchaseInvoice;
        if (! $invoice instanceof PurchaseInvoice) {
            return '0.0000';
        }

        return $this->calculator->netAmountsByLine($invoice)[$line->getKey()] ?? '0.0000';
    }

    public function allocatedAmount(PurchaseInvoiceLine $line): string
    {
        if ($line->asset_treatment === self::TreatmentCapitalImprovement && $line->target_fixed_asset_id !== null) {
            return $this->lineNetAmount($line);
        }

        $line->loadMissing('fixedAssets');

        return $line->fixedAssets->reduce(
            fn (string $sum, FixedAsset $asset): string => bcadd($sum, (string) $asset->purchase_value, 4),
            '0.0000',
        );
    }

    private function assertEligibleLine(PurchaseInvoiceLine $line): void
    {
        if (! $line->product instanceof Product || $line->product->isService() || $line->product->cost_as_inventory) {
            throw new DomainException(__('fixed_assets.purchase_source.non_inventory_required'));
        }
    }

    private function improvementTarget(PurchaseInvoiceLine $line, string $docNum): FixedAsset
    {
        $invoice = $line->purchaseInvoice;
        $asset = FixedAsset::query()
            ->where('company_id', $line->company_id)
            ->where('branch_id', $invoice?->branch_id)
            ->where('doc_num', trim($docNum))
            ->whereIn('status', [FixedAsset::StatusActive, FixedAsset::StatusSuspended])
            ->whereHas('costMovements', fn ($query) => $query->where('status', FixedAssetMovement::StatusPosted)
                ->whereIn('movement_type', [FixedAssetMovement::TypeCapitalization, FixedAssetMovement::TypeOpening]))
            ->with(['account', 'currency'])
            ->lockForUpdate()
            ->first();
        if (! $asset instanceof FixedAsset
            || ! $asset->account
            || $asset->account->is_group
            || ! $asset->account->is_postable
            || $asset->account->status !== 'active'
            || (int) $asset->currency_id !== (int) $invoice?->currency_id) {
            throw new DomainException(__('fixed_assets.purchase_source.improvement_context_mismatch'));
        }

        return $asset;
    }
}
