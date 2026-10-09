<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Services\ProcurementAuditService;
use Modules\Purchases\Services\PurchaseInvoiceCostAdjustmentService;
use Modules\Purchases\Services\PurchaseReceiptCostAllocationService;

final class ProductionInvoiceCostCorrectionService
{
    public const Kind = 'purchase_invoice_stage_cost';

    /** @return array<string, mixed> */
    public function preview(PurchaseInvoice $invoice, bool $reverse = false): array
    {
        Gate::authorize('purchase_invoices.view');
        Gate::authorize('production.runs.view');
        Gate::authorize('purchases.prices.view');

        return DB::transaction(function () use ($invoice, $reverse): array {
            $invoice = $this->invoice($invoice);
            $blocker = null;
            try {
                $plan = $this->calculate($invoice, $reverse);
                if ($plan === null || ($plan['stage_cost_graph']['runs'] ?? []) === []) {
                    $plan = null;
                    $blocker = __('production_invoice_cost.not_required');
                }
            } catch (DomainException $exception) {
                $plan = null;
                $blocker = $exception->getMessage();
            }

            return ['record' => $invoice, 'plan' => $plan, 'reverse' => $reverse, 'blocker' => $blocker,
                'fingerprint' => $plan === null ? null : self::digest($plan), 'history' => $this->history($invoice)];
        }, 3);
    }

    public function prepare(PurchaseInvoice $invoice, bool $reverse, string $reason, string $evidence, string $date, string $fingerprint): object
    {
        Gate::authorize('production.runs.correct');
        Gate::authorize('purchases.prices.view');
        Gate::authorize('purchase_invoices.view');
        Gate::authorize('production.runs.view');

        return DB::transaction(function () use ($invoice, $reverse, $reason, $evidence, $date, $fingerprint): object {
            $invoice = $this->invoice($invoice);
            if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000 || mb_strlen(trim($evidence)) < 5 || mb_strlen($evidence) > 2000) {
                throw new DomainException(__('production_invoice_cost.evidence_required'));
            }
            $plan = $this->calculate($invoice, $reverse);
            if ($plan === null || ($plan['stage_cost_graph']['runs'] ?? []) === [] || $date !== $plan['posting_date'] || ! hash_equals(self::digest($plan), $fingerprint)) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $definition = ['kind' => self::Kind, 'invoice_id' => (int) $invoice->id, 'reverse' => $reverse,
                'price_evidence' => trim($evidence), 'reason' => trim($reason), 'plan' => $plan];
            $pending = $this->rows($invoice)->whereIn('status', ['prepared', 'approved'])->lockForUpdate()->get()
                ->first(fn ($row): bool => json_decode($row->corrected_output, true)['reverse'] === $reverse);
            if ($pending !== null) {
                $this->assertSeal($pending);
                if (json_decode($pending->corrected_output, true) !== $definition) {
                    throw new DomainException(__('production_run_correction.reject_existing'));
                }

                return $pending;
            }
            $anchor = array_key_first($plan['stage_cost_graph']['runs']);
            $id = DB::table('production_run_corrections')->insertGetId([
                'company_id' => $invoice->company_id, 'production_run_id' => $anchor,
                'financial_period_id' => $plan['stage_cost_graph']['runs'][$anchor]['run']['financial_period_id'],
                'posting_financial_period_id' => $plan['posting_period_id'], 'posting_date' => $date,
                'correction_mode' => (int) $plan['stage_cost_graph']['runs'][$anchor]['run']['financial_period_id'] === (int) $plan['posting_period_id']
                    ? ProductionCorrectionContextService::OriginalPeriod : ProductionCorrectionContextService::LaterPeriod,
                'reason' => trim($reason), 'fingerprint' => self::digest($definition),
                'source_snapshot' => json_encode($plan, JSON_THROW_ON_ERROR), 'corrected_output' => json_encode($definition, JSON_THROW_ON_ERROR),
                'status' => 'prepared', 'prepared_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('production_run_corrections')->find($id);
            $this->audit($invoice, $row, 'prepared');

            return $row;
        }, 3);
    }

    public function approve(PurchaseInvoice $invoice, int $id): object
    {
        Gate::authorize('production.runs.correct_approve');
        Gate::authorize('purchases.prices.view');
        Gate::authorize('purchase_invoices.view');
        Gate::authorize('production.runs.view');

        return DB::transaction(function () use ($invoice, $id): object {
            $invoice = $this->invoice($invoice);
            $row = $this->row($invoice, $id);
            $this->assertSeal($row);
            if ((int) $row->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            $definition = json_decode($row->corrected_output, true, flags: JSON_THROW_ON_ERROR);
            $fresh = $this->calculate($invoice, $definition['reverse']);
            if ($fresh !== $definition['plan'] || ! in_array($row->status, ['prepared', 'approved'], true)) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            if ($row->status === 'approved') {
                $this->assertApproval($row);

                return $row;
            }
            DB::table('production_run_corrections')->where('id', $id)->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()->startOfSecond(), 'updated_at' => now()]);
            $row = $this->row($invoice, $id);
            $this->audit($invoice, $row, 'approved');

            return $row;
        }, 3);
    }

    public function reject(PurchaseInvoice $invoice, int $id): object
    {
        Gate::authorize('production.runs.correct_approve');
        Gate::authorize('purchases.prices.view');
        Gate::authorize('purchase_invoices.view');
        Gate::authorize('production.runs.view');

        return DB::transaction(function () use ($invoice, $id): object {
            $invoice = $this->invoice($invoice);
            $row = $this->row($invoice, $id);
            $this->assertSeal($row);
            if (! in_array($row->status, ['prepared', 'approved'], true)) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            DB::table('production_run_corrections')->where('id', $id)->update(['status' => 'rejected', 'rejected_at' => now(), 'updated_at' => now()]);
            $row = $this->row($invoice, $id);
            $this->audit($invoice, $row, 'rejected');

            return $row;
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function authorizedPlan(PurchaseInvoice $invoice, bool $reverse = false): ?array
    {
        $rows = $this->rows($invoice)->where('status', 'approved')->orderBy('id')->lockForUpdate()->get()
            ->filter(fn ($row): bool => json_decode($row->corrected_output, true)['reverse'] === $reverse);
        if ($rows->isEmpty()) {
            return null;
        }
        if ($rows->count() !== 1) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        $row = $rows->sole();
        $this->assertApproval($row);
        $plan = json_decode($row->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
        if ($this->calculate($invoice, $reverse) !== $plan) {
            throw new DomainException(__('production_run_correction.stale'));
        }

        return [...$plan, 'stage_correction_id' => (int) $row->id];
    }

    /** @param array<string, mixed> $plan */
    public function assertPostingAuthorization(PurchaseInvoice $invoice, array $plan, bool $reverse): void
    {
        if ((int) ($plan['stage_correction_id'] ?? 0) < 1) {
            throw new DomainException(__('production_invoice_cost.approval_required'));
        }
        $row = $this->row($invoice, (int) ($plan['stage_correction_id'] ?? 0));
        $this->assertApproval($row);
        $definition = json_decode($row->corrected_output, true, flags: JSON_THROW_ON_ERROR);
        $withoutId = $plan;
        unset($withoutId['stage_correction_id']);
        if ($row->status !== 'approved' || $definition['reverse'] !== $reverse || $definition['plan'] !== $withoutId
            || $this->calculate($invoice, $reverse) !== $withoutId) {
            throw new DomainException(__('production_run_correction.stale'));
        }
    }

    public function consume(PurchaseInvoice $invoice, InventoryValueAdjustment $adjustment, bool $reverse): void
    {
        Gate::authorize($reverse ? 'purchase_invoices.reverse' : 'purchase_invoices.approve');
        if (DB::transactionLevel() < 1) {
            throw new DomainException(__('production_execution.evidence.transaction_required'));
        }
        $plan = $adjustment->source_snapshot;
        if (! isset($plan['stage_correction_id'])) {
            return;
        }
        $row = $this->row($invoice, (int) $plan['stage_correction_id']);
        $this->assertApproval($row);
        if ($row->status !== 'approved' || $adjustment->source_type !== ($reverse ? PurchaseInvoiceCostAdjustmentService::ReversalSource : PurchaseInvoice::class)
            || (int) $adjustment->source_id !== (int) $invoice->id) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        $definition = json_decode($row->corrected_output, true, flags: JSON_THROW_ON_ERROR);
        $withoutId = $plan;
        unset($withoutId['stage_correction_id']);
        if ($definition['reverse'] !== $reverse || $definition['plan'] !== $withoutId
            || (int) $adjustment->company_id !== (int) $invoice->company_id || $adjustment->status !== InventoryValueAdjustment::StatusPosted) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        $definition['consumed_adjustment_id'] = (int) $adjustment->id;
        $updated = DB::table('production_run_corrections')->where('id', $row->id)->where('status', 'approved')
            ->update(['status' => 'consumed', 'corrected_output' => json_encode($definition, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        $this->audit($invoice, $this->row($invoice, (int) $row->id), 'consumed');
    }

    public function assertConsumed(InventoryValueAdjustment $adjustment): void
    {
        $invoice = PurchaseInvoice::withTrashed()->where('company_id', $adjustment->company_id)->findOrFail($adjustment->source_id);
        $row = $this->row($invoice, (int) ($adjustment->source_snapshot['stage_correction_id'] ?? 0));
        $this->assertApproval($row);
        $definition = json_decode($row->corrected_output, true, flags: JSON_THROW_ON_ERROR);
        $plan = $adjustment->source_snapshot;
        unset($plan['stage_correction_id']);
        if ($row->status !== 'consumed' || ($definition['consumed_adjustment_id'] ?? null) !== (int) $adjustment->id || $definition['plan'] !== $plan
            || $adjustment->source_type !== ($definition['reverse'] ? PurchaseInvoiceCostAdjustmentService::ReversalSource : PurchaseInvoice::class)) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        $this->assertAudit($invoice, $row, 'consumed');
    }

    private function invoice(PurchaseInvoice $invoice): PurchaseInvoice
    {
        app(PurchaseReceiptCostAllocationService::class)->lockCompany((int) $invoice->company_id);
        $invoice = PurchaseInvoice::query()->where('company_id', app(OperatingContextService::class)->snapshot(request())['company_id'])->lockForUpdate()->findOrFail($invoice->id);
        abort_unless(app(OperatingContextService::class)->allowedBranchQueryForCurrentCompany(request())->whereKey($invoice->branch_id)->exists(), 404);

        return $invoice;
    }

    /** @return array<string, mixed>|null */
    private function calculate(PurchaseInvoice $invoice, bool $reverse): ?array
    {
        if ($invoice->status !== ($reverse ? PurchaseInvoice::StatusApproved : PurchaseInvoice::StatusDraft) && ! ($reverse && $invoice->status === PurchaseInvoice::StatusClosed)) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        $plan = $reverse ? app(PurchaseInvoiceCostAdjustmentService::class)->planStageReversal($invoice, now()->toDateString(), (int) app(OperatingContextService::class)->snapshot(request())['financial_period_id'])
            : app(PurchaseInvoiceCostAdjustmentService::class)->plan($invoice, stageCorrection: true);
        if ($plan !== null) {
            app(InventoryReceiptCostProposalService::class)->assertImpactBranchAccess(request(), $plan);
            $this->assertScope($invoice, $plan);
            foreach ($plan['stage_cost_graph']['transfers'] ?? [] as $link) {
                if (substr($link['owner']['posting_date'], 0, 10) > $plan['posting_date']) {
                    throw new DomainException(__('inventory.movements.messages.receipt_completion_date_before_movements'));
                }
            }
            foreach ($plan['stage_cost_graph']['runs'] ?? [] as $state) {
                foreach ($state['output_owners'] as $owner) {
                    if ($owner['status'] === 'posted' && substr($owner['posting_date'], 0, 10) > $plan['posting_date']) {
                        throw new DomainException(__('inventory.movements.messages.receipt_completion_date_before_movements'));
                    }
                }
                if ((int) $state['run']['financial_period_id'] !== (int) $plan['posting_period_id']) {
                    Gate::authorize('production.runs.correct_later_period');
                }
            }
        }

        return $plan;
    }

    /** @return Collection<int, object> */
    private function history(PurchaseInvoice $invoice): Collection
    {
        $rows = $this->rows($invoice)->orderByDesc('id')->get();
        foreach ($rows as $row) {
            $this->assertSeal($row);
            $this->assertScope($invoice, json_decode($row->source_snapshot, true, flags: JSON_THROW_ON_ERROR));
        }

        return $rows;
    }

    /** @param array<string, mixed> $plan */
    private function assertScope(PurchaseInvoice $invoice, array $plan): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $scope = app(OperatingScopeAccessService::class);
        abort_unless($scope->canAccessCompany(auth()->user(), $company), 404);
        foreach ($plan['stage_cost_graph']['runs'] ?? [] as $state) {
            abort_unless(app(OperatingContextService::class)->allowedBranchQueryForCurrentCompany(request())->whereKey($state['run']['branch_id'])->exists()
                && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->whereKey($state['run']['financial_period_id'])->exists(), 404);
        }
    }

    private function rows(PurchaseInvoice $invoice): Builder
    {
        return DB::table('production_run_corrections')->where('company_id', $invoice->company_id)
            ->where('corrected_output->kind', self::Kind)->where('corrected_output->invoice_id', $invoice->id);
    }

    private function row(PurchaseInvoice $invoice, int $id): object
    {
        return $this->rows($invoice)->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function assertSeal(object $row): void
    {
        $definition = json_decode($row->corrected_output, true, flags: JSON_THROW_ON_ERROR);
        unset($definition['consumed_adjustment_id']);
        $anchor = array_key_first($definition['plan']['stage_cost_graph']['runs'] ?? []);
        if ($anchor === null || (int) $row->production_run_id !== (int) $anchor
            || (int) $row->company_id !== (int) $definition['plan']['stage_cost_graph']['runs'][$anchor]['run']['company_id']
            || (int) $row->financial_period_id !== (int) $definition['plan']['stage_cost_graph']['runs'][$anchor]['run']['financial_period_id']
            || ($definition['kind'] ?? null) !== self::Kind || $definition['plan'] !== json_decode($row->source_snapshot, true, flags: JSON_THROW_ON_ERROR)
            || $definition['reason'] !== $row->reason || $definition['plan']['posting_date'] !== $row->posting_date
            || (int) $definition['plan']['posting_period_id'] !== (int) $row->posting_financial_period_id
            || ! hash_equals($row->fingerprint, self::digest($definition))) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        $invoice = PurchaseInvoice::withTrashed()->where('company_id', $row->company_id)->findOrFail($definition['invoice_id']);
        $this->assertAudit($invoice, $row, 'prepared');
    }

    private function assertApproval(object $row): void
    {
        $this->assertSeal($row);
        if ($row->approved_by === null || $row->approved_at === null || (int) $row->prepared_by === (int) $row->approved_by) {
            throw new DomainException(__('production_run_correction.independent_approval'));
        }
        $invoice = PurchaseInvoice::withTrashed()->where('company_id', $row->company_id)->findOrFail(json_decode($row->corrected_output, true)['invoice_id']);
        $this->assertAudit($invoice, $row, 'approved');
    }

    private function audit(PurchaseInvoice $invoice, object $row, string $event): void
    {
        app(ProcurementAuditService::class)->record($invoice, 'purchase_invoice.stage_cost_'.$event, $this->auditData($row, $event));
    }

    private function assertAudit(PurchaseInvoice $invoice, object $row, string $event): void
    {
        $audit = DB::table('activity_log')->where('company_id', $row->company_id)->where('subject_type', PurchaseInvoice::class)
            ->where('subject_id', $invoice->id)->where('event', 'purchase_invoice.stage_cost_'.$event)->get()
            ->first(fn ($entry): bool => (int) (json_decode($entry->properties, true)['correction_id'] ?? 0) === (int) $row->id);
        if ($audit === null || ($event === 'prepared' && (int) $audit->causer_id !== (int) $row->prepared_by)
            || ($event === 'approved' && (int) $audit->causer_id !== (int) $row->approved_by)) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        $properties = json_decode($audit->properties, true, flags: JSON_THROW_ON_ERROR);
        foreach ($this->auditData($row, $event) as $key => $value) {
            if (($properties[$key] ?? null) !== $value) {
                throw new DomainException(__('production_run_correction.stale'));
            }
        }
    }

    /** @return array<string, mixed> */
    private function auditData(object $row, string $event): array
    {
        return ['correction_id' => (int) $row->id, 'seal' => $row->fingerprint, 'prepared_by' => (int) $row->prepared_by,
            ...($event === 'prepared' ? [] : ['approved_by' => $row->approved_by === null ? null : (int) $row->approved_by, 'approved_at' => $row->approved_at]),
            ...($event === 'consumed' ? ['adjustment_id' => json_decode($row->corrected_output, true)['consumed_adjustment_id']] : [])];
    }

    /** @param array<string, mixed> $value */
    public static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
