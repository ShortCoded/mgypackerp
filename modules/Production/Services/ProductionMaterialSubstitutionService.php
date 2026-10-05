<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryReservationService;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionRun;

final class ProductionMaterialSubstitutionService
{
    private const Table = 'production_material_substitutions';

    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly ProductionCycleService $cycle,
        private readonly InventoryReservationService $reservations,
        private readonly ProductionCostService $costs,
        private readonly ActivityLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(ProductionRun $run, ?string $requirementId = null): array
    {
        abort_unless(auth()->user()?->canAny(['production.runs.correct', 'production.runs.correct_approve']), 403);
        $run = $this->scopedRun($run);
        $snapshot = $this->snapshot($run);
        $run->load(['requirements.product', 'requirements.unit', 'orderLine']);
        $selected = $requirementId === null ? $run->requirements->first() : $run->requirements->firstWhere('public_id', $requirementId);
        abort_if($requirementId !== null && $selected === null, 404);
        $blocker = null;
        try {
            $this->assertEligible($run);
            if ($selected !== null) {
                $this->assertRequirement($run, $selected, '0.00000001');
            }
        } catch (DomainException $exception) {
            $blocker = $exception->getMessage();
        }

        return ['record' => $run, 'selected' => $selected, 'blocker' => $blocker,
            'fingerprint' => $this->digest($snapshot), 'impact' => $this->costs->runPosition($run),
            'stores' => BranchStore::query()->where('branch_id', $run->branch_id)->orderBy('name')->get(),
            'proposals' => DB::table(self::Table)->where('company_id', $run->company_id)->where('production_run_id', $run->id)->orderByDesc('id')->simplePaginate(15)->withQueryString()];
    }

    public function replacementQuery(ProductionRun $run, string $requirementId): Builder
    {
        Gate::authorize('production.runs.correct');
        Gate::authorize('production.runs.issue');
        $run = $this->scopedRun($run);
        $requirement = $run->requirements()->where('public_id', $requirementId)->firstOrFail();

        return Product::query()->where('company_id', $run->company_id)->where('status', 'active')
            ->where('item_classification', Product::ClassificationRawMaterial)->where('item_unit_id', $requirement->unit_id)
            ->where('tracks_serials', false)->where('tracks_expiry', false)
            ->whereNotIn('id', $run->requirements()->select('product_id'))->orderBy('doc_num');
    }

    /** @param array{requirement_public_id:string, replacement_product_doc_num:string, branch_store_id:int, quantity:string, reason:string, fingerprint:string} $input */
    public function prepare(ProductionRun $run, array $input): object
    {
        Gate::authorize('production.runs.correct');
        Gate::authorize('production.runs.issue');

        return DB::transaction(function () use ($run, $input): object {
            Company::query()->whereKey($this->companies->requireCompanyId())->lockForUpdate()->firstOrFail();
            $run = $this->scopedRun($run, true);
            $snapshot = $this->snapshot($run, true);
            $this->assertEligible($run);
            if (! hash_equals($this->digest($snapshot), $input['fingerprint'])) {
                throw new DomainException(__('production_material_substitution.stale'));
            }
            $requirement = $run->requirements()->where('public_id', $input['requirement_public_id'])->lockForUpdate()->firstOrFail();
            $quantity = $this->quantity((string) $input['quantity']);
            $this->assertRequirement($run, $requirement, $quantity);
            $product = Product::query()->where('company_id', $run->company_id)->where('doc_num', $input['replacement_product_doc_num'])->lockForUpdate()->firstOrFail();
            $this->assertReplacement($run, $requirement, $product);
            $store = BranchStore::query()->where('branch_id', $run->branch_id)->whereKey($input['branch_store_id'])->lockForUpdate()->firstOrFail();
            $this->assertCostPolicy($run, $store);
            if (trim($input['reason']) === '' || mb_strlen($input['reason']) > 2000) {
                throw new DomainException(__('production_material_substitution.reason_required'));
            }
            $payload = ['company_id' => $run->company_id, 'branch_id' => $run->branch_id,
                'financial_period_id' => $run->financial_period_id, 'production_run_id' => $run->id,
                'original_requirement_id' => $requirement->id, 'replacement_product_id' => $product->id,
                'branch_store_id' => $store->id, 'quantity' => $quantity, 'reason' => trim($input['reason']),
                'posting_date' => now()->toDateString(), 'fingerprint' => $input['fingerprint'],
                'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'replacement_snapshot' => json_encode($this->replacementSnapshot($product, $store), JSON_THROW_ON_ERROR),
                'prepared_by' => auth()->id()];
            $pending = DB::table(self::Table)->where('production_run_id', $run->id)->where('status', 'prepared')->lockForUpdate()->first();
            if ($pending !== null) {
                if (hash_equals($pending->proposal_seal, $this->digest($payload))) {
                    return $pending;
                }
                throw new DomainException(__('production_material_substitution.pending'));
            }
            $id = DB::table(self::Table)->insertGetId([...$payload, 'proposal_seal' => $this->digest($payload),
                'status' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
            $this->log($run, $id, 'prepared', ['proposal' => $payload]);

            return DB::table(self::Table)->find($id);
        }, attempts: 3);
    }

    public function approve(ProductionRun $run, int $id, string $evidence): object
    {
        Gate::authorize('production.runs.correct_approve');
        Gate::authorize('products.edit');
        Gate::authorize('production.runs.issue');

        return DB::transaction(function () use ($run, $id, $evidence): object {
            Company::query()->whereKey($this->companies->requireCompanyId())->lockForUpdate()->firstOrFail();
            $run = $this->scopedRun($run, true);
            $proposal = $this->proposal($run, $id);
            $this->assertSeal($proposal);
            if ((int) $proposal->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_material_substitution.independent'));
            }
            if ($proposal->status === 'approved') {
                $execution = json_decode($proposal->execution_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if (! hash_equals($proposal->execution_seal, $this->digest($execution))
                    || (int) $execution['approved_by'] !== (int) $proposal->approved_by
                    || $execution['recipe_approval_evidence'] !== $proposal->recipe_approval_evidence
                    || (int) $execution['return']['document']['id'] !== (int) $proposal->return_document_id
                    || (int) $execution['issue']['document']['id'] !== (int) $proposal->issue_document_id
                    || (int) $execution['replacement_requirement']['id'] !== (int) $proposal->replacement_requirement_id
                    || $this->digest($this->documentEvidence(InventoryDocument::query()->findOrFail($proposal->return_document_id))) !== $this->digest($execution['return'])
                    || $this->digest($this->documentEvidence(InventoryDocument::query()->findOrFail($proposal->issue_document_id))) !== $this->digest($execution['issue'])) {
                    throw new DomainException(__('production_material_substitution.stale'));
                }

                return $proposal;
            }
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('production_material_substitution.state'));
            }
            $snapshot = $this->snapshot($run, true);
            $this->assertEligible($run);
            if ($proposal->posting_date !== now()->toDateString() || ! hash_equals($proposal->fingerprint, $this->digest($snapshot))) {
                throw new DomainException(__('production_material_substitution.stale'));
            }
            $requirement = $run->requirements()->whereKey($proposal->original_requirement_id)->lockForUpdate()->firstOrFail();
            $quantity = $this->quantity((string) $proposal->quantity);
            $this->assertRequirement($run, $requirement, $quantity);
            $product = Product::query()->where('company_id', $run->company_id)->whereKey($proposal->replacement_product_id)->lockForUpdate()->firstOrFail();
            $store = BranchStore::query()->where('branch_id', $run->branch_id)->whereKey($proposal->branch_store_id)->lockForUpdate()->firstOrFail();
            $this->assertReplacement($run, $requirement, $product);
            $this->assertCostPolicy($run, $store);
            if (! hash_equals($this->digest(json_decode($proposal->replacement_snapshot, true, 512, JSON_THROW_ON_ERROR)), $this->digest($this->replacementSnapshot($product, $store)))) {
                throw new DomainException(__('production_material_substitution.stale'));
            }
            if (trim($evidence) === '' || mb_strlen($evidence) > 2000) {
                throw new DomainException(__('production_material_substitution.evidence_required'));
            }
            $before = $this->costs->runPosition($run);
            DB::table(self::Table)->where('id', $id)->update(['status' => 'applying']);
            $returned = $this->cycle->returnMaterials($run, $store->id, [$requirement->id => $quantity]);
            $requirement->refresh();
            $requirement->update(['planned_quantity' => bcsub((string) $requirement->planned_quantity, $quantity, 8)]);
            $replacement = $run->requirements()->create([
                'production_order_id' => $requirement->production_order_id, 'production_order_line_id' => $requirement->production_order_line_id,
                'line_number' => (int) $run->requirements()->max('line_number') + 1, 'product_component_id' => null,
                'product_id' => $product->id, 'unit_id' => $requirement->unit_id, 'calculation_method' => $requirement->calculation_method,
                'component_quantity_snapshot' => bcdiv($quantity, bcmul((string) $run->planned_base_quantity, (string) ($run->orderLine->bom_snapshot['basis_base_quantity'] ?? '1'), 8), 8), 'planned_quantity' => $quantity,
                'component_snapshot' => ['product_id' => $product->id, 'base_unit_id' => $requirement->unit_id,
                    'approved_substitution' => ['proposal_id' => $id, 'original_requirement_id' => $requirement->id,
                        'original_product_id' => $requirement->product_id, 'quantity' => $quantity,
                        'approved_by' => auth()->id(), 'recipe_approval_evidence' => trim($evidence)]]]);
            $this->reservations->reserveForProductionAcrossPositions($replacement, $store->id, $quantity);
            $quantities = $run->requirements()->get()->mapWithKeys(fn (ProductionMaterialRequirement $line): array => [$line->id => $line->id === $replacement->id ? $quantity : '0'])->all();
            $issued = $this->cycle->issueMaterials($run, $store->id, $quantities);
            if ($returned->lines()->whereNull('total_cost')->exists() || $issued->lines()->whereNull('total_cost')->exists()) {
                throw new DomainException(__('production_material_substitution.valuation'));
            }
            $execution = ['approved_by' => auth()->id(), 'recipe_approval_evidence' => trim($evidence),
                'original_requirement' => $requirement->fresh()->getAttributes(), 'replacement_requirement' => $replacement->fresh()->getAttributes(),
                'return' => $this->documentEvidence($returned), 'issue' => $this->documentEvidence($issued),
                'before_cost' => $before, 'after_cost' => $this->costs->runPosition($run)];
            DB::table(self::Table)->where('id', $id)->update(['status' => 'approved', 'approved_by' => auth()->id(),
                'recipe_approval_evidence' => trim($evidence), 'approved_at' => now(), 'replacement_requirement_id' => $replacement->id,
                'return_document_id' => $returned->id, 'issue_document_id' => $issued->id,
                'execution_snapshot' => json_encode($execution, JSON_THROW_ON_ERROR), 'execution_seal' => $this->digest($execution), 'updated_at' => now()]);
            $this->log($run, $id, 'approved', $execution);

            return DB::table(self::Table)->find($id);
        }, attempts: 3);
    }

    public function reject(ProductionRun $run, int $id): void
    {
        Gate::authorize('production.runs.correct_approve');
        DB::transaction(function () use ($run, $id): void {
            Company::query()->whereKey($this->companies->requireCompanyId())->lockForUpdate()->firstOrFail();
            $run = $this->scopedRun($run, true);
            $proposal = $this->proposal($run, $id);
            $this->assertSeal($proposal);
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('production_material_substitution.state'));
            }
            DB::table(self::Table)->where('id', $id)->update(['status' => 'rejected', 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'updated_at' => now()]);
            $this->log($run, $id, 'rejected');
        });
    }

    private function scopedRun(ProductionRun $run, bool $lock = false): ProductionRun
    {
        $company = $this->companies->currentCompany();
        abort_unless($company !== null && $this->scope->canAccessCompany(auth()->user(), $company), 404);
        $run = ProductionRun::query()->where('company_id', $company->id)->whereKey($run->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($run->order()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)->exists(), 404);
        abort_unless($run->orderLine()->where('production_order_id', $run->production_order_id)->exists(), 404);
        abort_unless($this->scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $run->branch_id)->exists(), 404);
        abort_unless((int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $run->branch_id, 404);
        abort_unless((int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey) === (int) $run->financial_period_id, 404);
        abort_unless($this->scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $run->financial_period_id)->exists(), 404);

        return $run;
    }

    private function assertEligible(ProductionRun $run): void
    {
        app(FinancialPeriodService::class)->resolveOpenForPostingDate($run->company_id, now()->toDateString(), $run->financial_period_id, lockForUpdate: DB::transactionLevel() > 0);
        if (! in_array($run->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld], true)
            || $run->production_run_batch_id !== null || $run->active_correction_id !== null || $run->correction_sequence > 0) {
            throw new DomainException(__('production_material_substitution.active'));
        }
        if (bccomp((string) $run->planned_base_quantity, '0', 8) <= 0 || ! $this->costs->runPosition($run)['material_valuation_complete']) {
            throw new DomainException(__('production_material_substitution.valuation'));
        }
        if ($run->requirements()->where(fn ($query) => $query->where('consumed_quantity', '>', 0)->orWhere('waste_quantity', '>', 0))->exists()
            || bccomp((string) $run->received_base_quantity, '0', 8) > 0
            || bccomp((string) $run->good_base_quantity, '0', 8) > 0
            || bccomp((string) $run->rejected_base_quantity, '0', 8) > 0
            || bccomp((string) $run->rework_base_quantity, '0', 8) > 0
            || bccomp((string) $run->scrap_base_quantity, '0', 8) > 0
            || DB::table('production_progress_entries')->where('production_run_id', $run->id)->exists()
            || DB::table('quality_inspections')->where('production_run_id', $run->id)->exists()
            || DB::table('inventory_documents')->where('production_run_id', $run->id)->whereIn('document_type', [InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste, InventoryDocument::TypeProductionReceipt])->exists()
            || DB::table('inventory_transactions')->where('production_run_id', $run->id)->whereIn('transaction_type', [InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste, InventoryDocument::TypeProductionReceipt])->exists()
            || DB::table('production_expense_requests')->where('production_run_id', $run->id)->exists()
            || DB::table('production_piece_approvals')->where('production_run_id', $run->id)->exists()
            || DB::table('cost_overhead_allocation_lines')->where('production_run_id', $run->id)->exists()
            || DB::table('inventory_value_adjustment_lines')->where('production_run_id', $run->id)->exists()) {
            throw new DomainException(__('production_material_substitution.dependencies'));
        }
    }

    private function assertRequirement(ProductionRun $run, ProductionMaterialRequirement $requirement, string $quantity): void
    {
        if ((int) $requirement->production_order_id !== (int) $run->production_order_id
            || (int) $requirement->production_order_line_id !== (int) $run->production_order_line_id
            || (int) $requirement->production_run_id !== (int) $run->id) {
            throw new DomainException(__('production_material_substitution.lineage'));
        }
        $remaining = bcsub(bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8), (string) $requirement->returned_quantity, 8);
        if (($requirement->component_snapshot['approved_substitution'] ?? false)
            || bccomp($quantity, $remaining, 8) > 0 || bccomp($quantity, (string) $requirement->planned_quantity, 8) > 0
            || bccomp((string) $requirement->issued_quantity, (string) $requirement->planned_quantity, 8) !== 0
            || bccomp((string) $requirement->additional_issued_quantity, '0', 8) !== 0
            || InventoryReservation::query()->where('production_material_requirement_id', $requirement->id)->where('status', InventoryReservation::StatusActive)->exists()
            || DB::table('production_material_request_lines')->where('production_material_requirement_id', $requirement->id)->exists()
            || DB::table(self::Table)->where('original_requirement_id', $requirement->id)->where('status', 'approved')->exists()) {
            throw new DomainException(__('production_material_substitution.quantity'));
        }
        $product = $requirement->product;
        if ($product === null || $product->trashed() || $product->status !== 'active' || $product->company_id !== $run->company_id
            || $product->item_classification !== Product::ClassificationRawMaterial || $product->tracks_serials || $product->tracks_expiry
            || (int) $product->item_unit_id !== (int) $requirement->unit_id || $requirement->unit === null || $requirement->unit->trashed() || $requirement->unit->status !== 'active') {
            throw new DomainException(__('production_material_substitution.compatibility'));
        }
    }

    private function assertReplacement(ProductionRun $run, ProductionMaterialRequirement $requirement, Product $product): void
    {
        if ($product->status !== 'active' || $product->item_classification !== Product::ClassificationRawMaterial
            || (int) $product->item_unit_id !== (int) $requirement->unit_id || $product->tracks_serials || $product->tracks_expiry
            || $run->requirements()->where('product_id', $product->id)->exists()) {
            throw new DomainException(__('production_material_substitution.compatibility'));
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ProductionRun $run, bool $lock = false): array
    {
        $query = fn (string $table) => DB::table($table)->where('production_run_id', $run->id)->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate())->get();
        $documents = $query('inventory_documents');
        $transactions = $query('inventory_transactions');
        $layers = DB::table('inventory_receipt_layers')->whereIn('receipt_transaction_id', $transactions->pluck('id'))->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate())->get();

        return ['run' => $run->getAttributes(), 'order' => $run->order()->when($lock, fn ($builder) => $builder->lockForUpdate())->firstOrFail()->getAttributes(),
            'order_line' => $run->orderLine()->when($lock, fn ($builder) => $builder->lockForUpdate())->firstOrFail()->getAttributes(),
            'company' => Company::query()->findOrFail($run->company_id)->getAttributes(),
            'requirements' => $query('production_material_requirements'), 'reservations' => $query('inventory_reservations'),
            'products' => DB::table('products')->whereIn('id', $run->requirements()->select('product_id'))->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate())->get(),
            'units' => DB::table('item_units')->whereIn('id', $run->requirements()->select('unit_id'))->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate())->get(),
            'documents' => $documents, 'transactions' => $transactions, 'layers' => $layers,
            'allocations' => DB::table('inventory_layer_allocations')->whereIn('inventory_receipt_layer_id', $layers->pluck('id'))->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate())->get(),
            'document_lines' => DB::table('inventory_document_lines')->whereIn('inventory_document_id', $documents->pluck('id'))->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate())->get(),
            'journals' => DB::table('journal_entries')->whereIn('id', $documents->pluck('journal_entry_id')->filter())->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate())->get(),
            'journal_lines' => DB::table('journal_entry_lines')->whereIn('journal_entry_id', $documents->pluck('journal_entry_id')->filter())->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate())->get()];
    }

    /** @return array<string, mixed> */
    private function replacementSnapshot(Product $product, BranchStore $store): array
    {
        $query = fn (string $table) => DB::table($table)->where('company_id', $product->company_id)->where('product_id', $product->id)->where('branch_store_id', $store->id)->orderBy('id')->lockForUpdate()->get();

        return ['product' => $product->getAttributes(), 'store' => $store->getAttributes(),
            'policy' => app(InventoryCostPolicyService::class)->resolve($product->company_id, $store->id, now()->toDateString()),
            'transactions' => $query('inventory_transactions'), 'layers' => $query('inventory_receipt_layers'), 'reservations' => $query('inventory_reservations')];
    }

    /** @return array<string, mixed> */
    private function documentEvidence(InventoryDocument $document): array
    {
        return ['document' => $document->fresh()->getAttributes(), 'lines' => $document->lines()->orderBy('id')->get()->map->getAttributes()->all(),
            'transactions' => $document->transactions()->orderBy('id')->get()->map->getAttributes()->all(),
            'journal' => DB::table('journal_entries')->find($document->journal_entry_id),
            'journal_lines' => DB::table('journal_entry_lines as lines')->join('accounts', 'accounts.id', '=', 'lines.account_id')
                ->where('lines.journal_entry_id', $document->journal_entry_id)->orderBy('lines.id')->get(['lines.*', 'accounts.account_code'])->all()];
    }

    private function proposal(ProductionRun $run, int $id): object
    {
        return DB::table(self::Table)->where('company_id', $run->company_id)->where('production_run_id', $run->id)->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function assertSeal(object $proposal): void
    {
        $keys = ['company_id', 'branch_id', 'financial_period_id', 'production_run_id', 'original_requirement_id', 'replacement_product_id',
            'branch_store_id', 'quantity', 'reason', 'posting_date', 'fingerprint', 'source_snapshot', 'replacement_snapshot', 'prepared_by'];
        $payload = [];
        foreach ($keys as $key) {
            $payload[$key] = $key === 'quantity' ? bcadd((string) $proposal->{$key}, '0', 8) : $proposal->{$key};
        }
        if (! hash_equals($proposal->proposal_seal, $this->digest($payload))) {
            throw new DomainException(__('production_material_substitution.stale'));
        }
    }

    private function quantity(string $quantity): string
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $quantity) || bccomp($quantity, '0', 8) <= 0) {
            throw new DomainException(__('production_material_substitution.quantity'));
        }

        return bcadd($quantity, '0', 8);
    }

    private function assertCostPolicy(ProductionRun $run, BranchStore $store): void
    {
        if (app(InventoryCostPolicyService::class)->resolve($run->company_id, $store->id, now()->toDateString())['method'] === InventoryCostPolicy::SpecificIdentification) {
            throw new DomainException(__('production_material_substitution.specific'));
        }
    }

    /** @param array<mixed> $data */
    private function digest(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string, mixed> $properties */
    private function log(ProductionRun $run, int $id, string $action, array $properties = []): void
    {
        $this->audit->log(request(), 'production', 'production.material_substitution.'.$action, 'success',
            ['subject' => $run, 'company_id' => $run->company_id, 'properties_only' => true, 'properties' => ['substitution_id' => $id, ...$properties]]);
    }
}
