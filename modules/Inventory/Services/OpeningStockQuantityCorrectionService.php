<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventorySerialIdentity;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockQuantityCorrection;

class OpeningStockQuantityCorrectionService
{
    public function __construct(
        private readonly InventoryMovementService $movements,
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryValuationService $valuation,
        private readonly InventoryLayerService $layers,
        private readonly InventorySerialService $serials,
        private readonly InventoryAccountingPostingService $accounting,
        private readonly InventoryCostPolicyService $costPolicies,
        private readonly FinancialPeriodService $periods,
        private readonly OperatingContextService $context,
        private readonly OperatingScopeAccessService $scopeAccess,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{posting_date: string, reason: string, source_reference: string}  $data
     * @param  array<int|string, array{target_quantity: string|int|float, unit_cost?: string|int|float|null, selected_receipt_layer_id?: int|string|null, serial_numbers?: mixed, serial_receipt_layer_ids?: mixed}>  $targets
     * @return array{can_prepare: bool, blockers: list<string>, lines: list<array<string, mixed>>, document_groups: list<array<string, mixed>>, source_snapshot: array<string, mixed>, fingerprint: string}
     */
    public function preview(Request $request, OpeningStock $openingStock, array $data, array $targets): array
    {
        Gate::forUser($request->user())->authorize('inventory.opening_stock_quantity_corrections.prepare');

        return DB::transaction(function () use ($request, $openingStock, $data, $targets): array {
            $source = $this->lockSource($openingStock);
            $this->assertContext($request, $source);
            $this->assertEligibleSource($source);
            $validated = $this->validatedHeader($source, $data, (int) $this->context->snapshot($request)['financial_period_id']);
            $evidence = $this->buildEvidence($source, $validated['posting_date'], $targets, (int) $this->context->snapshot($request)['financial_period_id']);

            return [
                'can_prepare' => $evidence['plan']['blockers'] === [],
                'blockers' => $evidence['plan']['blockers'],
                'lines' => $evidence['plan']['lines'],
                'document_groups' => $evidence['plan']['document_groups'],
                'source_snapshot' => $evidence['source_snapshot'],
                'fingerprint' => $evidence['fingerprint'],
            ];
        });
    }

    /**
     * @param  array{posting_date: string, reason: string, source_reference: string}  $data
     * @param  array<int|string, array{target_quantity: string|int|float, unit_cost?: string|int|float|null, selected_receipt_layer_id?: int|string|null, serial_numbers?: mixed, serial_receipt_layer_ids?: mixed}>  $targets
     */
    public function prepare(Request $request, OpeningStock $openingStock, array $data, array $targets): OpeningStockQuantityCorrection
    {
        Gate::forUser($request->user())->authorize('inventory.opening_stock_quantity_corrections.prepare');

        return DB::transaction(function () use ($request, $openingStock, $data, $targets): OpeningStockQuantityCorrection {
            $source = $this->lockSource($openingStock);
            $this->assertContext($request, $source);
            $this->assertEligibleSource($source);
            if (OpeningStockQuantityCorrection::query()->where('opening_stock_id', $source->id)
                ->where('status', OpeningStockQuantityCorrection::StatusPending)->lockForUpdate()->exists()) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.pending'));
            }

            $scope = $this->context->snapshot($request);
            $validated = $this->validatedHeader($source, $data, (int) $scope['financial_period_id']);
            $evidence = $this->buildEvidence($source, $validated['posting_date'], $targets, (int) $scope['financial_period_id']);
            if ($evidence['plan']['blockers'] !== []) {
                throw new DomainException(implode(' ', $evidence['plan']['blockers']));
            }

            $correction = OpeningStockQuantityCorrection::query()->create([
                'public_uuid' => (string) Str::uuid(),
                'company_id' => $source->company_id,
                'opening_stock_id' => $source->id,
                'financial_period_id' => $source->financial_period_id,
                'branch_id' => $source->branch_id,
                'posting_period_id' => $scope['financial_period_id'],
                'posting_date' => $validated['posting_date'],
                'status' => OpeningStockQuantityCorrection::StatusPending,
                'reason' => $validated['reason'],
                'source_reference' => $validated['source_reference'],
                'targets' => $evidence['targets'],
                'source_snapshot' => $evidence['source_snapshot'],
                'plan' => $evidence['plan'],
                'fingerprint' => $evidence['fingerprint'],
                'prepared_by' => $request->user()->id,
            ]);
            $this->log($request, $correction, __('opening_stock_quantity_correction.audit.prepared'));

            return $correction->refresh();
        }, attempts: 3);
    }

    public function approve(
        Request $request,
        OpeningStock $openingStock,
        OpeningStockQuantityCorrection $correction,
        string $approvalReference,
    ): OpeningStockQuantityCorrection {
        Gate::forUser($request->user())->authorize('inventory.opening_stock_quantity_corrections.approve');

        return DB::transaction(function () use ($request, $openingStock, $correction, $approvalReference): OpeningStockQuantityCorrection {
            $source = $this->lockSource($openingStock);
            $candidate = OpeningStockQuantityCorrection::query()->lockForUpdate()->findOrFail($correction->id);
            $this->assertCorrectionScope($request, $source, $candidate);

            if ($candidate->status === OpeningStockQuantityCorrection::StatusApproved) {
                $this->assertPostedDocumentReplay($candidate);

                return $candidate->refresh();
            }

            if ($candidate->status !== OpeningStockQuantityCorrection::StatusPending
                || (int) $candidate->prepared_by === (int) $request->user()->id
                || trim($approvalReference) === '') {
                throw new DomainException(__('opening_stock_quantity_correction.errors.approval_unavailable'));
            }

            $this->assertEligibleSource($source);
            $this->validatedHeader($source, ['posting_date' => $candidate->posting_date->toDateString(),
                'reason' => $candidate->reason, 'source_reference' => $candidate->source_reference], (int) $candidate->posting_period_id);
            $evidence = $this->buildEvidence($source, $candidate->posting_date->toDateString(), $candidate->targets, (int) $candidate->posting_period_id);
            if ($evidence['plan']['blockers'] !== []
                || $candidate->fingerprint !== $evidence['fingerprint']
                || $candidate->source_snapshot !== $evidence['source_snapshot']
                || $candidate->plan !== $evidence['plan']) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
            }

            $documentLinks = [];
            foreach ($evidence['plan']['document_groups'] as $group) {
                $document = $this->postDocumentGroup($candidate, $source, $group);
                $this->assertPostedGroupContract($candidate, $document, $group);
                $documentLinks[] = [
                    'id' => (int) $document->id,
                    'doc_num' => (string) $document->doc_num,
                    'document_type' => (string) $document->document_type,
                    'stock_status' => (string) $group['stock_status'],
                    'journal_entry_id' => $document->journal_entry_id === null ? null : (int) $document->journal_entry_id,
                ];
            }

            $candidate->forceFill([
                'status' => OpeningStockQuantityCorrection::StatusApproved,
                'approval_reference' => trim($approvalReference),
                'document_links' => $documentLinks,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ])->save();
            $this->log($request, $candidate, __('opening_stock_quantity_correction.audit.approved'));

            return $candidate->refresh();
        }, attempts: 3);
    }

    public function reject(
        Request $request,
        OpeningStock $openingStock,
        OpeningStockQuantityCorrection $correction,
        string $reason,
    ): OpeningStockQuantityCorrection {
        Gate::forUser($request->user())->authorize('inventory.opening_stock_quantity_corrections.approve');

        return DB::transaction(function () use ($request, $openingStock, $correction, $reason): OpeningStockQuantityCorrection {
            $source = $this->lockSource($openingStock);
            $candidate = OpeningStockQuantityCorrection::query()->lockForUpdate()->findOrFail($correction->id);
            $this->assertCorrectionScope($request, $source, $candidate);
            if ($candidate->status !== OpeningStockQuantityCorrection::StatusPending || trim($reason) === '') {
                throw new DomainException(__('opening_stock_quantity_correction.errors.approval_unavailable'));
            }

            $candidate->forceFill([
                'status' => OpeningStockQuantityCorrection::StatusRejected,
                'rejection_reason' => trim($reason),
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
            ])->save();
            $this->log($request, $candidate, __('opening_stock_quantity_correction.audit.rejected'));

            return $candidate->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<int|string, array{target_quantity: string|int|float, unit_cost?: string|int|float|null, selected_receipt_layer_id?: int|string|null, serial_numbers?: mixed, serial_receipt_layer_ids?: mixed}>  $targets
     * @return array{targets: array<int, array<string, mixed>>, source_snapshot: array<string, mixed>, plan: array<string, mixed>, fingerprint: string}
     */
    private function buildEvidence(OpeningStock $source, string $postingDate, array $targets, int $postingPeriodId): array
    {
        $lines = $source->lines()->orderBy('id')->lockForUpdate()->get();
        $normalizedTargets = $this->normalizeTargets($lines->modelKeys(), $targets);
        $approved = OpeningStockQuantityCorrection::query()
            ->where('opening_stock_id', $source->id)
            ->where('status', OpeningStockQuantityCorrection::StatusApproved)
            ->orderBy('id')->lockForUpdate()->get();
        $previousDeltas = [];
        foreach ($approved as $previous) {
            foreach ($previous->plan['lines'] ?? [] as $previousLine) {
                $lineId = (int) $previousLine['line_id'];
                $previousDeltas[$lineId] = bcadd($previousDeltas[$lineId] ?? '0', (string) $previousLine['delta_quantity'], 4);
            }
        }

        $policy = $this->costPolicies->resolve((int) $source->company_id, (int) $source->branch_store_id, $postingDate);
        $policyRecord = $policy['policy_id'] === null ? null : InventoryCostPolicy::query()->lockForUpdate()->find($policy['policy_id']);
        $policySnapshot = [
            'method' => $policy['method'],
            'policy_id' => $policy['policy_id'],
            'effective_from' => $policyRecord?->effective_from?->toDateString(),
            'branch_id' => $policyRecord?->branch_id,
            'branch_store_id' => $policyRecord?->branch_store_id,
            'updated_at' => $policyRecord?->updated_at?->toISOString(),
        ];
        $sourceLines = [];
        $plannedLines = [];
        $rootTransactions = [];
        $selectedSerialLayerIds = [];
        $blockers = [];
        $sourcePeriod = FinancialPeriod::query()->where('company_id', $source->company_id)
            ->whereKey($source->financial_period_id)->lockForUpdate()->firstOrFail();

        foreach ($lines as $line) {
            $root = InventoryTransaction::query()
                ->where('company_id', $source->company_id)
                ->where('source_type', OpeningStock::class)
                ->where('source_id', $source->id)
                ->where('source_line_type', OpeningStockLine::class)
                ->where('source_line_id', $line->id)
                ->where('posting_key', "opening-stock:{$source->id}:line:{$line->id}")
                ->lockForUpdate()->sole();
            $rootTransactions[(int) $line->id] = $root;
            InventoryTransaction::query()
                ->where('company_id', $root->company_id)
                ->where('branch_store_id', $root->branch_store_id)
                ->where('product_id', $root->product_id)
                ->where('stock_status', $root->stock_status)
                ->when($root->warehouse_location_id !== null,
                    fn ($query) => $query->where('warehouse_location_id', $root->warehouse_location_id),
                    fn ($query) => $query->whereNull('warehouse_location_id'))
                ->when($root->batch_lot !== null,
                    fn ($query) => $query->where('batch_lot', $root->batch_lot),
                    fn ($query) => $query->whereNull('batch_lot'))
                ->lockForUpdate()->get();
            $position = $this->availability->forProduct(
                (int) $root->company_id,
                (int) $root->branch_store_id,
                (int) $root->product_id,
                warehouseLocationId: $root->warehouse_location_id,
                stockStatus: (string) $root->stock_status,
                batchLot: $root->batch_lot,
                exactDimensions: true,
            );
            $effectiveTotal = $root->completedTotalCost();
            $effectiveUnit = $effectiveTotal === null || bccomp((string) $root->quantity_in, '0', 8) === 0
                ? null : bcdiv($effectiveTotal, (string) $root->quantity_in, 8);
            $correctedQuantity = bcadd((string) $line->quantity, $previousDeltas[$line->id] ?? '0', 4);

            $sourceLines[] = [
                'line_id' => (int) $line->id,
                'line_public_id' => (string) $line->public_id,
                'product_id' => (int) $line->product_id,
                'original_quantity' => (string) $line->quantity,
                'prior_approved_delta' => $previousDeltas[$line->id] ?? '0.0000',
                'corrected_opening_quantity' => $correctedQuantity,
                'root_transaction_id' => (int) $root->id,
                'root_unit_cost' => $root->unit_cost === null ? null : (string) $root->unit_cost,
                'root_total_cost' => $root->total_cost === null ? null : (string) $root->total_cost,
                'effective_total_cost' => $effectiveTotal,
                'effective_unit_cost' => $effectiveUnit,
                'position' => $position,
                'dimensions' => $this->rootDimensions($root),
            ];

            if (! isset($normalizedTargets[$line->id])) {
                continue;
            }
            $target = $normalizedTargets[$line->id];
            $delta = bcsub($target['target_quantity'], $correctedQuantity, 4);
            if (bccomp($delta, '0', 4) === 0) {
                continue;
            }
            $direction = bccomp($delta, '0', 4) > 0 ? 'increase' : 'decrease';
            $quantity = $direction === 'increase' ? $delta : bcsub('0', $delta, 4);
            if ($direction === 'increase' && $target['unit_cost'] === null) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.missing_cost'));
            }
            $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
            $lineBlockers = [];
            $selectedLayerSnapshot = null;
            $layerAllocations = [];
            $serialUnits = [];
            $previewUnitCost = $direction === 'increase' ? $target['unit_cost'] : null;
            $previewValue = $direction === 'increase' ? bcmul($quantity, (string) $target['unit_cost'], 8) : null;
            $valueBasis = $direction === 'increase' ? 'documented_unit_cost' : null;
            if ($product->tracks_serials) {
                if (bccomp($quantity, bcadd($quantity, '0', 0), 8) !== 0) {
                    $lineBlockers[] = __('opening_stock_quantity_correction.blockers.serial_integer_quantity', ['line_id' => $line->id]);
                } elseif ($direction === 'increase') {
                    try {
                        $numbers = $this->serials->receiptNumbers($product, $quantity, $target['serial_numbers']);
                        if ($target['serial_receipt_layer_ids'] !== []) {
                            throw new DomainException(__('inventory_serial.source_selection'));
                        }
                        $normalizedNumbers = array_map(fn (string $serial): string => Str::lower($serial), $numbers);
                        if (InventorySerialIdentity::query()->where('company_id', $root->company_id)
                            ->where('product_id', $root->product_id)->whereIn('normalized_serial', $normalizedNumbers)
                            ->lockForUpdate()->exists()) {
                            throw new DomainException(__('opening_stock_quantity_correction.blockers.serial_number_exists', ['line_id' => $line->id]));
                        }
                        foreach ($numbers as $serialNumber) {
                            $serialUnits[] = [
                                'serial_number' => $serialNumber,
                                'selected_receipt_layer_id' => null,
                                'inventory_serial_identity_id' => null,
                                'unit_cost' => $target['unit_cost'],
                                'value' => $target['unit_cost'],
                            ];
                        }
                    } catch (DomainException $error) {
                        $lineBlockers[] = $error->getMessage();
                    }
                } elseif ($target['serial_numbers'] !== [] || count($target['serial_receipt_layer_ids']) !== (int) $quantity
                    || count(array_unique($target['serial_receipt_layer_ids'])) !== count($target['serial_receipt_layer_ids'])) {
                    $lineBlockers[] = __('opening_stock_quantity_correction.blockers.serial_layers_required', ['line_id' => $line->id]);
                } else {
                    foreach ($target['serial_receipt_layer_ids'] as $layerId) {
                        $serialLayer = InventoryReceiptLayer::query()->with(['receiptTransaction', 'serialIdentity'])
                            ->withAuthoritativeCost()->whereKey($layerId)->lockForUpdate()->first();
                        $identity = $serialLayer?->serialIdentity;
                        if ($serialLayer === null || $identity === null || isset($selectedSerialLayerIds[$layerId])
                            || (int) $serialLayer->company_id !== (int) $root->company_id
                            || (int) $serialLayer->branch_id !== (int) $source->branch_id
                            || (int) $serialLayer->branch_store_id !== (int) $root->branch_store_id
                            || (int) $serialLayer->product_id !== (int) $root->product_id
                            || (int) $serialLayer->receiptTransaction?->unit_id !== (int) $root->unit_id
                            || $serialLayer->stock_status !== $root->stock_status
                            || ($serialLayer->warehouse_location_id ?? null) !== ($root->warehouse_location_id ?? null)
                            || ($serialLayer->batch_lot ?? null) !== ($root->batch_lot ?? null)
                            || ($serialLayer->manufacture_date?->toDateString() ?? null) !== ($root->manufacture_date?->toDateString() ?? null)
                            || ($serialLayer->expiry_date?->toDateString() ?? null) !== ($root->expiry_date?->toDateString() ?? null)
                            || $serialLayer->original_receipt_date->toDateString() > $postingDate
                            || bccomp((string) $serialLayer->remaining_quantity, '1', 8) < 0
                            || (int) $identity->company_id !== (int) $root->company_id
                            || (int) $identity->product_id !== (int) $root->product_id
                            || (int) $identity->current_receipt_layer_id !== (int) $serialLayer->id) {
                            $lineBlockers[] = __('opening_stock_quantity_correction.blockers.serial_layer_invalid', ['line_id' => $line->id, 'layer_id' => $layerId]);

                            continue;
                        }
                        $selectedSerialLayerIds[$layerId] = true;
                        try {
                            $selectedValue = $this->layers->previewIssueCost(
                                $this->previewIssueTransaction($root, $policy, $postingDate, '1', (int) $identity->id),
                                (int) $serialLayer->id,
                            );
                            $serialUnits[] = [
                                'serial_number' => (string) $identity->serial_number,
                                'selected_receipt_layer_id' => (int) $serialLayer->id,
                                'inventory_serial_identity_id' => (int) $identity->id,
                                'identity_current_receipt_layer_id' => (int) $identity->current_receipt_layer_id,
                                'identity_updated_at' => $identity->updated_at?->toISOString(),
                                'layer_remaining_quantity' => (string) $serialLayer->remaining_quantity,
                                'layer_updated_at' => $serialLayer->updated_at?->toISOString(),
                                'unit_cost' => $selectedValue['unit_cost'],
                                'value' => $selectedValue['total_cost'],
                                'layer_allocations' => $selectedValue['allocations'],
                            ];
                            $layerAllocations = [...$layerAllocations, ...$selectedValue['allocations']];
                        } catch (DomainException $error) {
                            $lineBlockers[] = $error->getMessage();
                        }
                    }
                    if (count($serialUnits) === count($target['serial_receipt_layer_ids'])) {
                        $previewValue = collect($serialUnits)->reduce(
                            fn (string $sum, array $unit): string => bcadd($sum, $unit['value'], 8),
                            '0.00000000',
                        );
                        $previewUnitCost = bcdiv($previewValue, $quantity, 8);
                        $valueBasis = 'canonical_serial_receipt_layer_allocator';
                    }
                }
            }
            if (! $product->tracks_serials && $direction === 'decrease' && $policy['method'] === InventoryCostPolicy::SpecificIdentification) {
                $selectedLayer = $target['selected_receipt_layer_id'] === null ? null : InventoryReceiptLayer::query()
                    ->with('receiptTransaction')->withAuthoritativeCost()->whereKey($target['selected_receipt_layer_id'])
                    ->lockForUpdate()->first();
                if ($selectedLayer === null) {
                    $lineBlockers[] = __('opening_stock_quantity_correction.blockers.specific_layer_required', ['line_id' => $line->id]);
                } elseif ((int) $selectedLayer->company_id !== (int) $root->company_id
                    || (int) $selectedLayer->branch_id !== (int) $source->branch_id
                    || (int) $selectedLayer->branch_store_id !== (int) $root->branch_store_id
                    || (int) $selectedLayer->product_id !== (int) $root->product_id
                    || (int) $selectedLayer->receiptTransaction?->unit_id !== (int) $root->unit_id
                    || $selectedLayer->stock_status !== $root->stock_status
                    || ($selectedLayer->warehouse_location_id ?? null) !== ($root->warehouse_location_id ?? null)
                    || ($selectedLayer->batch_lot ?? null) !== ($root->batch_lot ?? null)
                    || $selectedLayer->original_receipt_date->toDateString() > $postingDate) {
                    $lineBlockers[] = __('opening_stock_quantity_correction.blockers.specific_layer_invalid', ['line_id' => $line->id]);
                } elseif (bccomp((string) $selectedLayer->remaining_quantity, $quantity, 8) < 0) {
                    $lineBlockers[] = __('opening_stock_quantity_correction.blockers.specific_layer_insufficient', [
                        'line_id' => $line->id,
                        'requested' => $quantity,
                        'available' => $selectedLayer->remaining_quantity,
                    ]);
                } else {
                    $selectedValue = $this->layers->previewIssueCost($this->previewIssueTransaction($root, $policy, $postingDate, $quantity), (int) $selectedLayer->id);
                    if ($selectedValue === null) {
                        $lineBlockers[] = __('opening_stock_quantity_correction.blockers.financial_impact_unavailable', ['line_id' => $line->id]);
                    } else {
                        $previewUnitCost = $selectedValue['unit_cost'];
                        $previewValue = $selectedValue['total_cost'];
                        $valueBasis = 'canonical_receipt_layer_allocator';
                        $layerAllocations = $selectedValue['allocations'];
                    }
                    $selectedLayerSnapshot = [
                        'id' => (int) $selectedLayer->id,
                        'receipt_transaction_id' => (int) $selectedLayer->receipt_transaction_id,
                        'original_quantity' => (string) $selectedLayer->original_quantity,
                        'remaining_quantity' => (string) $selectedLayer->remaining_quantity,
                        'unit_cost' => $selectedLayer->unit_cost === null ? null : (string) $selectedLayer->unit_cost,
                        'book_unit_cost' => $selectedLayer->bookUnitCostForPolicy($policy['policy_id']),
                        'preview_unit_cost' => $selectedValue['unit_cost'] ?? null,
                        'preview_total_cost' => $selectedValue['total_cost'] ?? null,
                        'preview_value_basis' => $valueBasis,
                        'source_allocation_id' => $selectedLayer->source_allocation_id,
                        'updated_at' => $selectedLayer->updated_at?->toISOString(),
                    ];
                }
            } elseif (! $product->tracks_serials && $target['selected_receipt_layer_id'] !== null) {
                $lineBlockers[] = __('opening_stock_quantity_correction.blockers.specific_layer_invalid', ['line_id' => $line->id]);
            }
            if (! $product->tracks_serials && $direction === 'decrease' && $policy['method'] === InventoryCostPolicy::Fifo) {
                try {
                    $allocation = $this->layers->previewIssueCost($this->previewIssueTransaction($root, $policy, $postingDate, $quantity));
                    $previewUnitCost = $allocation['unit_cost'];
                    $previewValue = $allocation['total_cost'];
                    $layerAllocations = $allocation['allocations'];
                    $valueBasis = 'canonical_receipt_layer_allocator';
                } catch (DomainException $error) {
                    $lineBlockers[] = $error->getMessage();
                }
            }
            if ($direction === 'decrease' && bccomp($quantity, $position['available'], 8) > 0) {
                $lineBlockers[] = __('opening_stock_quantity_correction.blockers.insufficient_available', [
                    'line_id' => $line->id,
                    'requested' => $quantity,
                    'available' => $position['available'],
                ]);
            }
            $blockers = [...$blockers, ...$lineBlockers];
            $accountingContract = null;
            if ($lineBlockers === [] && $previewValue !== null) {
                $previewDocument = new InventoryDocument(['company_id' => $source->company_id, 'branch_id' => $source->branch_id,
                    'financial_period_id' => $postingPeriodId, 'branch_store_id' => $source->branch_store_id,
                    'document_type' => $direction === 'increase' ? InventoryDocument::TypeAdjustmentIn : InventoryDocument::TypeAdjustmentOut]);
                $previewLine = new InventoryDocumentLine(['product_id' => $product->id, 'unit_cost' => $previewUnitCost, 'total_cost' => $previewValue]);
                $previewLine->setRelation('product', $product);
                $accountingContract = $this->accounting->previewLinePosting($previewDocument, $previewLine);
                foreach ($serialUnits as &$serialUnit) {
                    $unitLine = new InventoryDocumentLine(['product_id' => $product->id, 'unit_cost' => $serialUnit['unit_cost'], 'total_cost' => $serialUnit['value']]);
                    $unitLine->setRelation('product', $product);
                    $serialUnit['accounting_contract'] = $this->accounting->previewLinePosting($previewDocument, $unitLine);
                }
                unset($serialUnit);
            }
            $plannedLines[] = [
                'line_id' => (int) $line->id,
                'line_public_id' => (string) $line->public_id,
                'product_id' => (int) $line->product_id,
                'product_code' => (string) ($line->product_snapshot['doc_num'] ?? $product->doc_num),
                'product_name' => (string) ($line->product_snapshot['name'] ?? $product->name),
                'product_snapshot' => $line->product_snapshot,
                'original_quantity' => (string) $line->quantity,
                'current_corrected_quantity' => $correctedQuantity,
                'effective_opening_quantity' => $correctedQuantity,
                'current_available' => $position['available'],
                'target_quantity' => $target['target_quantity'],
                'delta_quantity' => $delta,
                'quantity_delta' => $delta,
                'movement_quantity' => $quantity,
                'direction' => $direction,
                'unit_cost' => $previewUnitCost,
                'selected_receipt_layer_id' => $selectedLayerSnapshot['id'] ?? null,
                'selected_receipt_layer_snapshot' => $selectedLayerSnapshot,
                'value' => $previewValue,
                'value_basis' => $valueBasis,
                'layer_allocations' => $layerAllocations,
                'accounting_contract' => $accountingContract,
                'serial_units' => $serialUnits,
                'account_labels' => $direction === 'increase'
                    ? ['debit' => 'inventory', 'credit' => 'gain']
                    : ['debit' => 'loss', 'credit' => 'inventory'],
                'blocked_reasons' => $lineBlockers,
                'dependency_ids' => [
                    'root_transaction_id' => (int) $root->id,
                    'branch_store_id' => (int) $root->branch_store_id,
                    'warehouse_location_id' => $root->warehouse_location_id,
                    'cost_policy_id' => $policy['policy_id'],
                ],
                'unit_id' => $root->unit_id,
                'stock_status' => $root->stock_status,
                'warehouse_location_id' => $root->warehouse_location_id,
                'batch_lot' => $root->batch_lot,
                'manufacture_date' => $root->manufacture_date?->toDateString(),
                'expiry_date' => $root->expiry_date?->toDateString(),
            ];
        }

        if ($plannedLines === []) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.invalid_input'));
        }
        if (! InventoryCostPolicy::usesReceiptLayers($policy['method'])) {
            $plannedLines = $this->previewBookCostInPostingOrder($source, $plannedLines, $rootTransactions, $postingDate, $postingPeriodId);
            $blockers = collect($plannedLines)->pluck('blocked_reasons')->flatten()->all();
        }
        $groups = collect($plannedLines)->groupBy(fn (array $line): string => $line['direction'].'|'.$line['stock_status'])
            ->map(function ($lines, string $key): array {
                [$direction, $stockStatus] = explode('|', $key, 2);

                return [
                    'direction' => $direction,
                    'document_type' => $direction === 'increase' ? InventoryDocument::TypeAdjustmentIn : InventoryDocument::TypeAdjustmentOut,
                    'stock_status' => $stockStatus,
                    'lines' => $lines->values()->all(),
                ];
            })->values()->all();
        $sourceSnapshot = [
            'opening_stock_id' => (int) $source->id,
            'opening_stock_doc_num' => (string) $source->doc_num,
            'company_id' => (int) $source->company_id,
            'financial_period_id' => (int) $source->financial_period_id,
            'branch_id' => (int) $source->branch_id,
            'branch_store_id' => (int) $source->branch_store_id,
            'document_date' => $source->document_date->toDateString(),
            'source_period_closed' => (bool) $sourcePeriod->is_closed,
            'approved' => (bool) $source->approved,
            'status' => (string) $source->status,
            'deleted_at' => $source->deleted_at?->toISOString(),
            'updated_at' => $source->updated_at?->toISOString(),
            'lines' => $sourceLines,
        ];
        $plan = [
            'posting_date' => $postingDate,
            'posting_policy' => $policySnapshot,
            'previous_approved_corrections' => $approved->map(fn (OpeningStockQuantityCorrection $previous): array => [
                'id' => (int) $previous->id,
                'public_uuid' => (string) $previous->public_uuid,
                'fingerprint' => (string) $previous->fingerprint,
                'approved_at' => $previous->approved_at?->toISOString(),
            ])->all(),
            'lines' => $plannedLines,
            'document_groups' => $groups,
            'blockers' => array_values(array_unique($blockers)),
        ];
        $fingerprint = hash('sha256', json_encode([
            'targets' => $normalizedTargets,
            'source_snapshot' => $sourceSnapshot,
            'plan' => $plan,
        ], JSON_THROW_ON_ERROR));

        return [
            'targets' => $normalizedTargets,
            'source_snapshot' => $sourceSnapshot,
            'plan' => $plan,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Mirrors document grouping and serialized expansion before independent approval.
     * Physical allocations retain receipt history; financial values use canonical book costs.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, InventoryTransaction>  $roots
     * @return list<array<string, mixed>>
     */
    private function previewBookCostInPostingOrder(OpeningStock $source, array $lines, array $roots, string $postingDate, int $postingPeriodId): array
    {
        $groups = [];
        foreach ($lines as $index => $line) {
            $groups[$line['direction'].'|'.$line['stock_status']][] = $index;
        }
        $positions = [];
        foreach ($groups as $indices) {
            foreach ($indices as $index) {
                $line = $lines[$index];
                if ($line['blocked_reasons'] !== []) {
                    continue;
                }
                $root = $roots[$line['line_id']];
                $exactDimensions = $root->warehouse_location_id !== null || filled($root->batch_lot);
                $key = json_encode([$root->company_id, $root->branch_store_id, $root->product_id,
                    $root->stock_status, $root->warehouse_location_id, $root->batch_lot, $exactDimensions], JSON_THROW_ON_ERROR);
                $positions[$key] ??= $this->valuation->bookPositionForPosition(
                    (int) $root->company_id, (int) $root->branch_store_id, (int) $root->product_id,
                    (string) $root->stock_status, $root->warehouse_location_id, $root->batch_lot,
                    null, $postingDate, $exactDimensions,
                );
                $line['book_position_before'] = $positions[$key];
                $increase = $line['direction'] === 'increase';
                $units = $line['serial_units'] === [] ? [['quantity' => $line['movement_quantity']]]
                    : array_map(fn (array $unit): array => [...$unit, 'quantity' => '1'], $line['serial_units']);
                $total = '0.00000000';
                $previewDocument = new InventoryDocument(['company_id' => $source->company_id, 'branch_id' => $source->branch_id,
                    'financial_period_id' => $postingPeriodId, 'branch_store_id' => $source->branch_store_id,
                    'document_type' => $increase ? InventoryDocument::TypeAdjustmentIn : InventoryDocument::TypeAdjustmentOut]);
                $product = Product::query()->findOrFail($line['product_id']);
                foreach ($units as $unitIndex => $unit) {
                    try {
                        $cost = $this->valuation->previewBookPositionMovement($positions[$key],
                            $increase ? $unit['quantity'] : '0', $increase ? '0' : $unit['quantity'],
                            $increase ? $line['unit_cost'] : null);
                        if ($cost['unit_cost'] === null || $cost['total_cost'] === null) {
                            throw new DomainException(__('opening_stock_quantity_correction.blockers.financial_impact_unavailable', ['line_id' => $line['line_id']]));
                        }
                        $positions[$key] = $cost['position'];
                        $total = bcadd($total, $cost['total_cost'], 8);
                        if ($line['serial_units'] !== []) {
                            $line['serial_units'][$unitIndex]['physical_layer_allocations'] = $unit['layer_allocations'] ?? [];
                            $line['serial_units'][$unitIndex]['layer_allocations'] = array_map(fn (array $allocation): array => [
                                ...$allocation, 'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost'],
                            ], $unit['layer_allocations'] ?? []);
                            $line['serial_units'][$unitIndex]['unit_cost'] = $cost['unit_cost'];
                            $line['serial_units'][$unitIndex]['value'] = $cost['total_cost'];
                            $unitLine = new InventoryDocumentLine(['product_id' => $product->id,
                                'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost']]);
                            $unitLine->setRelation('product', $product);
                            $line['serial_units'][$unitIndex]['accounting_contract'] = $this->accounting->previewLinePosting($previewDocument, $unitLine);
                        }
                    } catch (DomainException $error) {
                        $line['blocked_reasons'][] = $error->getMessage();
                        break;
                    }
                }
                if ($line['blocked_reasons'] === []) {
                    $line['value'] = $total;
                    $line['unit_cost'] = $increase ? $line['unit_cost']
                        : ($line['serial_units'] === [] ? $cost['unit_cost'] : bcdiv($total, $line['movement_quantity'], 8));
                    if ($line['serial_units'] !== []) {
                        $line['layer_allocations'] = collect($line['serial_units'])->pluck('layer_allocations')->flatten(1)->all();
                    }
                    $line['value_basis'] = $increase ? 'documented_unit_cost' : 'canonical_position_book_unit_cost';
                    $previewLine = new InventoryDocumentLine(['product_id' => $product->id, 'unit_cost' => $line['unit_cost'], 'total_cost' => $total]);
                    $previewLine->setRelation('product', $product);
                    $line['accounting_contract'] = $this->accounting->previewLinePosting($previewDocument, $previewLine);
                    $line['book_position_after'] = $positions[$key];
                }
                $lines[$index] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<int>  $lineIds
     * @param  array<int|string, array{target_quantity: string|int|float, unit_cost?: string|int|float|null, selected_receipt_layer_id?: int|string|null, serial_numbers?: mixed, serial_receipt_layer_ids?: mixed}>  $targets
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTargets(array $lineIds, array $targets): array
    {
        $normalized = [];
        foreach ($targets as $lineId => $input) {
            $lineId = (int) $lineId;
            if (! is_array($input) || ! in_array($lineId, $lineIds, true)) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.invalid_target'));
            }
            $quantity = trim((string) ($input['target_quantity'] ?? ''));
            if (! preg_match('/^\d{1,11}(?:\.\d{1,4})?$/D', $quantity)) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.invalid_target'));
            }
            $unitCost = isset($input['unit_cost']) ? trim((string) $input['unit_cost']) : null;
            if ($unitCost === '') {
                $unitCost = null;
            }
            if ($unitCost !== null
                && (! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $unitCost) || bccomp($unitCost, '0', 8) <= 0)) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.missing_cost'));
            }
            $selectedLayerId = filled($input['selected_receipt_layer_id'] ?? null)
                ? filter_var($input['selected_receipt_layer_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : null;
            if ($selectedLayerId === false) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.invalid_target'));
            }
            $serialNumbers = $this->serials->numbers($input['serial_numbers'] ?? []);
            $serialLayerIds = $input['serial_receipt_layer_ids'] ?? [];
            if (! is_array($serialLayerIds) || count($serialLayerIds) > 10000) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.invalid_target'));
            }
            $serialLayerIds = array_map(function (mixed $id): int {
                $validated = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($validated === false) {
                    throw new DomainException(__('opening_stock_quantity_correction.errors.invalid_target'));
                }

                return $validated;
            }, array_values($serialLayerIds));
            $normalized[$lineId] = [
                'target_quantity' => bcadd($quantity, '0', 4),
                'unit_cost' => $unitCost === null ? null : bcadd($unitCost, '0', 8),
                'selected_receipt_layer_id' => $selectedLayerId,
                'serial_numbers' => $serialNumbers,
                'serial_receipt_layer_ids' => $serialLayerIds,
            ];
        }
        ksort($normalized);
        if ($normalized === []) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.invalid_input'));
        }

        return $normalized;
    }

    /** @param array<string, mixed> $group */
    private function postDocumentGroup(
        OpeningStockQuantityCorrection $correction,
        OpeningStock $source,
        array $group,
    ): InventoryDocument {
        $lines = collect($group['lines'])->flatMap(function (array $line) use ($correction): array {
            if ($line['direction'] === 'increase' && $line['unit_cost'] === null) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.missing_cost'));
            }

            $base = array_filter([
                'product_id' => $line['product_id'],
                'unit_id' => $line['unit_id'],
                'quantity' => $line['movement_quantity'],
                'selected_receipt_layer_id' => $line['selected_receipt_layer_id'],
                'warehouse_location_id' => $line['warehouse_location_id'],
                'batch_lot' => $line['batch_lot'],
                'manufacture_date' => $line['manufacture_date'],
                'expiry_date' => $line['expiry_date'],
                'source_line_type' => OpeningStockLine::class,
                'source_line_id' => $line['line_id'],
                'source_line_public_id' => $line['line_public_id'],
                'unit_cost' => $line['direction'] === 'increase' ? $line['unit_cost'] : null,
                'product_snapshot' => $line['product_snapshot'],
                'notes' => $correction->reason,
            ], fn (mixed $value): bool => $value !== null);

            if (($line['serial_units'] ?? []) === []) {
                return [$base];
            }
            if ($line['direction'] === 'increase') {
                return [[...$base, 'serial_numbers' => array_column($line['serial_units'], 'serial_number')]];
            }

            return collect($line['serial_units'])->map(fn (array $unit): array => [
                ...$base,
                'quantity' => '1',
                'selected_receipt_layer_id' => $unit['selected_receipt_layer_id'],
            ])->all();
        })->all();

        return $this->movements->createAndPost([
            'company_id' => $correction->company_id,
            'financial_period_id' => $correction->posting_period_id,
            'branch_id' => $correction->branch_id,
            'branch_store_id' => $source->branch_store_id,
            'document_type' => $group['document_type'],
            'document_date' => $correction->posting_date->toDateString(),
            'purpose' => __('opening_stock_quantity_correction.title'),
            'movement_reason' => $correction->reason,
            'source_stock_status' => $group['stock_status'],
            'destination_stock_status' => $group['stock_status'],
            'source_document_type' => OpeningStockQuantityCorrection::class,
            'source_document_id' => $correction->id,
            'source_doc_num' => $correction->public_uuid,
            'notes' => $correction->source_reference,
        ], $lines);
    }

    private function assertPostedDocumentReplay(OpeningStockQuantityCorrection $correction): void
    {
        $links = $correction->document_links ?? [];
        $sourceDocuments = InventoryDocument::query()
            ->where('source_document_type', OpeningStockQuantityCorrection::class)
            ->where('source_document_id', $correction->id)->get();
        if ($links === [] || $sourceDocuments->count() !== count($links)) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
        }
        foreach ($links as $link) {
            $document = InventoryDocument::query()->with('journalEntry')->find($link['id'] ?? 0);
            if ($document === null
                || $document->status !== InventoryDocument::StatusPosted
                || $document->source_document_type !== OpeningStockQuantityCorrection::class
                || (int) $document->source_document_id !== (int) $correction->id
                || $document->doc_num !== ($link['doc_num'] ?? null)
                || $document->document_type !== ($link['document_type'] ?? null)
                || (int) $document->financial_period_id !== (int) $correction->posting_period_id
                || $document->document_date->toDateString() !== $correction->posting_date->toDateString()
                || (int) $document->journal_entry_id !== (int) ($link['journal_entry_id'] ?? 0)) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
            }
            $group = collect($correction->plan['document_groups'])->first(fn ($group): bool => $group['document_type'] === $link['document_type']
                && $group['stock_status'] === $link['stock_status']);
            if ($group === null) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
            }
            $this->assertPostedGroupContract($correction, $document, $group);
        }
    }

    /** @param array<string, mixed> $group */
    private function assertPostedGroupContract(OpeningStockQuantityCorrection $correction, InventoryDocument $document, array $group): void
    {
        $document->loadMissing(['lines', 'transactions', 'journalEntry.lines']);
        $expectedLineCount = collect($group['lines'])->sum(fn (array $planned): int => ($planned['serial_units'] ?? []) === []
            ? 1 : count($planned['serial_units']));
        if ((int) $document->company_id !== (int) $correction->company_id || (int) $document->branch_id !== (int) $correction->branch_id
            || $document->lines->count() !== $expectedLineCount || $document->transactions->count() !== $expectedLineCount) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
        }
        $expectedJournal = [];
        foreach ($group['lines'] as $planned) {
            if (($planned['serial_units'] ?? []) !== []) {
                $this->assertPostedSerialUnits($correction, $document, $planned, $group['direction'], $expectedJournal);

                continue;
            }
            $line = $document->lines->firstWhere('source_line_id', $planned['line_id']);
            $transaction = $document->transactions->firstWhere('source_line_id', $planned['line_id']);
            $contract = $planned['accounting_contract'];
            $snapshot = $line?->product_snapshot['inventory_accounting'] ?? null;
            if ($line === null || $transaction === null || $line->source_line_type !== OpeningStockLine::class
                || $transaction->source_line_type !== OpeningStockLine::class || (int) $line->product_id !== (int) $planned['product_id']
                || (int) $transaction->product_id !== (int) $planned['product_id']
                || bccomp((string) $line->quantity, $planned['movement_quantity'], 8) !== 0
                || bccomp((string) $transaction->quantity_in, $group['direction'] === 'increase' ? $planned['movement_quantity'] : '0', 8) !== 0
                || bccomp((string) $transaction->quantity_out, $group['direction'] === 'decrease' ? $planned['movement_quantity'] : '0', 8) !== 0
                || bccomp((string) $line->total_cost, $planned['value'], 8) !== 0
                || bccomp((string) $transaction->total_cost, $planned['value'], 8) !== 0
                || $snapshot !== collect($contract)->only(['rounding_rule', 'debit_account_id', 'credit_account_id', 'debit_branch_id', 'credit_branch_id', 'cost_center_id', 'exact_total_cost', 'booked_amount'])->all()) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
            }
            if (bccomp($contract['booked_amount'], '0', 4) <= 0) {
                continue;
            }
            foreach (['debit', 'credit'] as $side) {
                $key = $contract[$side.'_account_id'].':'.$side.':'.($contract['cost_center_id'] ?? 'none');
                $expectedJournal[$key] = bcadd($expectedJournal[$key] ?? '0', $contract['booked_amount'], 4);
            }
        }
        $journal = $document->journalEntry;
        if ($expectedJournal === []) {
            if ($journal !== null) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
            }

            return;
        }
        if ($journal === null) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
        }
        $this->accounting->assertPostedJournalHeader($journal, (int) $correction->company_id, (int) $correction->posting_period_id,
            'inventory_document_posting', (int) $document->id, (int) $correction->branch_id, $correction->posting_date->toDateString(),
            (int) $group['lines'][0]['accounting_contract']['currency_id']);
        $actualJournal = [];
        foreach ($journal->lines as $line) {
            if ((int) $line->branch_id !== (int) $correction->branch_id || $line->customer_id !== null || $line->supplier_id !== null
                || $line->employee_id !== null || $line->department_id !== null || $line->bank_account_id !== null) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
            }
            foreach (['debit', 'credit'] as $side) {
                if (bccomp((string) $line->{$side.'_amount'}, '0', 4) <= 0) {
                    continue;
                }
                $key = $line->account_id.':'.$side.':'.($line->cost_center_id ?? 'none');
                $actualJournal[$key] = bcadd($actualJournal[$key] ?? '0', (string) $line->{$side.'_amount'}, 4);
            }
        }
        ksort($expectedJournal);
        ksort($actualJournal);
        if ($expectedJournal !== $actualJournal) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
        }
    }

    /**
     * @param  array<string, mixed>  $planned
     * @param  array<string, string>  $expectedJournal
     */
    private function assertPostedSerialUnits(
        OpeningStockQuantityCorrection $correction,
        InventoryDocument $document,
        array $planned,
        string $direction,
        array &$expectedJournal,
    ): void {
        $lines = $document->lines->where('source_line_id', $planned['line_id']);
        $transactions = $document->transactions->where('source_line_id', $planned['line_id']);
        if ($lines->count() !== count($planned['serial_units']) || $transactions->count() !== count($planned['serial_units'])) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
        }
        foreach ($planned['serial_units'] as $unit) {
            $line = $direction === 'increase'
                ? $lines->first(fn (InventoryDocumentLine $candidate): bool => (int) $candidate->inventory_serial_identity_id > 0
                    && $transactions->firstWhere('inventory_serial_identity_id', $candidate->inventory_serial_identity_id)?->serialIdentity?->serial_number === $unit['serial_number'])
                : $lines->firstWhere('selected_receipt_layer_id', $unit['selected_receipt_layer_id']);
            $transaction = $line === null ? null : $transactions->firstWhere('inventory_serial_identity_id', $line->inventory_serial_identity_id);
            $contract = $unit['accounting_contract'];
            $snapshot = $line?->product_snapshot['inventory_accounting'] ?? null;
            if ($line === null || $transaction === null || (int) $line->inventory_serial_identity_id <= 0
                || (int) $transaction->inventory_serial_identity_id !== (int) $line->inventory_serial_identity_id
                || $transaction->serialIdentity?->serial_number !== $unit['serial_number']
                || ($direction === 'decrease' && (int) $line->selected_receipt_layer_id !== (int) $unit['selected_receipt_layer_id'])
                || ($direction === 'decrease' && (int) $transaction->inventory_serial_identity_id !== (int) $unit['inventory_serial_identity_id'])
                || bccomp((string) $line->quantity, '1', 8) !== 0
                || bccomp((string) $transaction->quantity_in, $direction === 'increase' ? '1' : '0', 8) !== 0
                || bccomp((string) $transaction->quantity_out, $direction === 'decrease' ? '1' : '0', 8) !== 0
                || bccomp((string) $line->total_cost, $unit['value'], 8) !== 0
                || bccomp((string) $transaction->total_cost, $unit['value'], 8) !== 0
                || $snapshot !== collect($contract)->only(['rounding_rule', 'debit_account_id', 'credit_account_id', 'debit_branch_id', 'credit_branch_id', 'cost_center_id', 'exact_total_cost', 'booked_amount'])->all()) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
            }
            if ($direction === 'decrease') {
                $allocations = InventoryLayerAllocation::query()->where('issue_transaction_id', $transaction->id)->get();
                $expectedAllocations = $unit['layer_allocations'] ?? [];
                if ($allocations->count() !== count($expectedAllocations)) {
                    throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
                }
                foreach ($expectedAllocations as $expectedAllocation) {
                    $allocation = $allocations->firstWhere('inventory_receipt_layer_id', $expectedAllocation['layer_id']);
                    if ($allocation === null
                        || bccomp((string) $allocation->quantity, $expectedAllocation['quantity'], 8) !== 0
                        || bccomp((string) $allocation->cost_unit_snapshot, $expectedAllocation['unit_cost'], 8) !== 0
                        || bccomp((string) $allocation->cost_total_snapshot, $expectedAllocation['total_cost'], 8) !== 0) {
                        throw new DomainException(__('opening_stock_quantity_correction.errors.source_changed'));
                    }
                }
            }
            if (bccomp($contract['booked_amount'], '0', 4) <= 0) {
                continue;
            }
            foreach (['debit', 'credit'] as $side) {
                $key = $contract[$side.'_account_id'].':'.$side.':'.($contract['cost_center_id'] ?? 'none');
                $expectedJournal[$key] = bcadd($expectedJournal[$key] ?? '0', $contract['booked_amount'], 4);
            }
        }
    }

    /** @param array{method: string, policy_id: int|null} $policy */
    private function previewIssueTransaction(
        InventoryTransaction $root,
        array $policy,
        string $postingDate,
        string $quantity,
        ?int $serialIdentityId = null,
    ): InventoryTransaction {
        return new InventoryTransaction([
            'company_id' => $root->company_id, 'branch_id' => $root->branch_id,
            'branch_store_id' => $root->branch_store_id, 'product_id' => $root->product_id,
            'unit_id' => $root->unit_id, 'warehouse_location_id' => $root->warehouse_location_id,
            'batch_lot' => $root->batch_lot, 'stock_status' => $root->stock_status,
            'transaction_type' => InventoryDocument::TypeAdjustmentOut, 'transaction_date' => $postingDate,
            'quantity_in' => '0', 'quantity_out' => $quantity,
            'inventory_serial_identity_id' => $serialIdentityId,
            'cost_method' => $policy['method'], 'cost_policy_id' => $policy['policy_id'], 'is_reversal' => false,
        ]);
    }

    /** @return array{posting_date: string, reason: string, source_reference: string} */
    private function validatedHeader(OpeningStock $source, array $data, int $postingPeriodId): array
    {
        $postingDate = (string) ($data['posting_date'] ?? '');
        $reason = trim((string) ($data['reason'] ?? ''));
        $sourceReference = trim((string) ($data['source_reference'] ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $postingDate)
            || $postingDate < $source->document_date->toDateString() || $reason === '' || $sourceReference === '') {
            throw new DomainException(__('opening_stock_quantity_correction.errors.invalid_input'));
        }
        $this->periods->resolveOpenForPostingDate(
            (int) $source->company_id,
            $postingDate,
            expectedPeriodId: $postingPeriodId,
            lockForUpdate: true,
        );
        $this->costPolicies->assertPostingDateAllowed((int) $source->company_id, (int) $source->branch_store_id, $postingDate);

        return [
            'posting_date' => $postingDate,
            'reason' => $reason,
            'source_reference' => $sourceReference,
        ];
    }

    private function assertCorrectionScope(
        Request $request,
        OpeningStock $source,
        OpeningStockQuantityCorrection $correction,
    ): void {
        $this->assertContext($request, $source, (int) $correction->posting_period_id);
        if ((int) $correction->opening_stock_id !== (int) $source->id
            || (int) $correction->company_id !== (int) $source->company_id
            || (int) $correction->financial_period_id !== (int) $source->financial_period_id
            || (int) $correction->branch_id !== (int) $source->branch_id) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.context_mismatch'));
        }
    }

    private function assertContext(Request $request, OpeningStock $source, ?int $postingPeriodId = null): void
    {
        $scope = $this->context->snapshot($request);
        $postingPeriodId ??= (int) $scope['financial_period_id'];
        $companyDocNum = Company::query()->whereKey($source->company_id)->value('doc_num');
        $allowedPeriods = is_string($companyDocNum)
            ? $this->scopeAccess->allowedFinancialPeriodQuery($request->user(), [$companyDocNum])
                ->whereIn('financial_periods.id', [$source->financial_period_id, $postingPeriodId])->count()
            : 0;
        if ((int) $source->company_id !== (int) $scope['company_id']
            || (int) $source->branch_id !== (int) $scope['branch_id']
            || $postingPeriodId !== (int) $scope['financial_period_id']
            || $allowedPeriods !== count(array_unique([(int) $source->financial_period_id, $postingPeriodId]))
            || ! $this->context->allowedBranchQueryForCurrentCompany($request)->whereKey($source->branch_id)->exists()) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.context_mismatch'));
        }
    }

    private function assertEligibleSource(OpeningStock $source): void
    {
        $sourcePeriod = FinancialPeriod::query()->where('company_id', $source->company_id)
            ->whereKey($source->financial_period_id)->lockForUpdate()->first();
        if ($source->trashed() || ! $source->approved || $source->status !== OpeningStock::StatusApproved
            || $source->branch_store_id === null || $sourcePeriod === null || $source->lines()->count() === 0) {
            throw new DomainException(__('opening_stock_quantity_correction.errors.source_unavailable'));
        }
    }

    private function lockSource(OpeningStock $openingStock): OpeningStock
    {
        $companyId = (int) OpeningStock::query()->whereKey($openingStock->id)->value('company_id');
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

        return OpeningStock::query()->lockForUpdate()->findOrFail($openingStock->id);
    }

    /** @return array<string, mixed> */
    private function rootDimensions(InventoryTransaction $root): array
    {
        return [
            'branch_store_id' => (int) $root->branch_store_id,
            'warehouse_location_id' => $root->warehouse_location_id,
            'stock_status' => (string) $root->stock_status,
            'batch_lot' => $root->batch_lot,
            'manufacture_date' => $root->manufacture_date?->toDateString(),
            'expiry_date' => $root->expiry_date?->toDateString(),
            'unit_id' => $root->unit_id,
            'cost_method' => $root->cost_method,
            'cost_policy_id' => $root->cost_policy_id,
        ];
    }

    private function log(Request $request, OpeningStockQuantityCorrection $correction, string $action): void
    {
        $this->activity->log($request, 'inventory', $action, 'success', [
            'subject' => $correction,
            'company_id' => $correction->company_id,
            'properties_only' => true,
            'properties' => $correction->only([
                'public_uuid', 'opening_stock_id', 'financial_period_id', 'posting_period_id', 'posting_date',
                'status', 'source_reference', 'fingerprint', 'approval_reference', 'document_links',
            ]),
        ]);
    }
}
