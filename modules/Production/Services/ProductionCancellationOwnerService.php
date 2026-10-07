<?php

namespace Modules\Production\Services;

use App\Services\DocumentOwnerEffectProofService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Models\SalesOrderLine;

final class ProductionCancellationOwnerService
{
    /** @return array<string, mixed> */
    public function preview(ProductionOrder|ProductionRun $record): array
    {
        Gate::authorize($record instanceof ProductionRun ? 'production.runs.view' : 'production.orders.view');
        $record = $this->scoped($record);
        $this->schema();
        $snapshot = $this->snapshot($record);
        $blockers = [];
        foreach ($record instanceof ProductionRun ? ['document_error'] : ['document_error', 'stop_remaining'] as $treatment) {
            try {
                $this->ready($record, $treatment);
                $blockers[$treatment] = null;
            } catch (DomainException $exception) {
                $blockers[$treatment] = $exception->getMessage();
            }
        }

        return ['record' => $record, 'fingerprint' => $this->digest($snapshot), 'blockers' => $blockers,
            'documents' => $record instanceof ProductionRun ? $this->documents($record)->where('status', InventoryDocument::StatusPosted)
                ->whereIn('document_type', [InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste])->get() : collect(),
            'owners' => $this->owners($record)->orderByDesc('id')->get()];
    }

    /** @param array<string, mixed> $data */
    public function prepare(ProductionOrder|ProductionRun $record, array $data): object
    {
        $this->authorize($record);
        $this->schema();

        return DB::transaction(function () use ($record, $data): object {
            $record = $this->locked($record);
            $documentId = isset($data['inventory_document_id']) ? (int) $data['inventory_document_id'] : null;
            $treatment = $documentId === null ? $data['treatment'] : 'material_document_error';
            $this->ready($record, $treatment, $documentId);
            $source = $this->snapshot($record);
            if (! hash_equals($data['fingerprint'], $this->digest($source)) || mb_strlen(trim($data['reason'])) < 5 || mb_strlen(trim($data['evidence'])) < 5) {
                throw new DomainException(__('production_cancellation_owner.stale'));
            }
            if ($this->owners($record)->where('inventory_document_id', $documentId)->whereIn('status', ['prepared', 'applying', 'approved'])->exists()) {
                throw new DomainException(__('production_cancellation_owner.pending'));
            }
            $run = $record instanceof ProductionRun ? $record : $record->runs()->orderBy('id')->firstOrFail();
            $period = app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());
            $data = ['public_id' => (string) Str::uuid(), 'company_id' => $record->company_id, 'branch_id' => $record->branch_id,
                'financial_period_id' => $record->financial_period_id, 'posting_financial_period_id' => $period->id,
                'production_order_id' => $record instanceof ProductionRun ? $record->production_order_id : $record->id,
                'production_run_id' => $record instanceof ProductionRun ? $record->id : null, 'inventory_document_id' => $documentId,
                'posting_date' => now()->toDateString(), 'treatment' => $treatment, 'reason' => trim($data['reason']), 'evidence' => trim($data['evidence']),
                'prepared_by' => auth()->id(), 'source_snapshot' => $source];
            $id = DB::table('production_cancellation_owners')->insertGetId([...$data, 'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR),
                'status' => 'prepared', 'proposal_seal' => $this->digest($data), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($record, 'prepared', ['owner_id' => $id, 'proposal_seal' => $this->digest($data)]);

            return DB::table('production_cancellation_owners')->find($id);
        }, 3);
    }

    public function approve(ProductionOrder|ProductionRun $record, int $id): object
    {
        $this->authorize($record);
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($record, $id): object {
            $record = $this->locked($record);
            $owner = $this->owner($record, $id);
            if ((int) $owner->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($owner->status === 'approved') {
                return $this->assertApproved($record, $id);
            }
            if ($owner->status !== 'prepared' || $owner->posting_date !== now()->toDateString()
                || ! hash_equals($this->digest(json_decode($owner->source_snapshot, true, flags: JSON_THROW_ON_ERROR)), $this->digest($this->snapshot($record)))) {
                throw new DomainException(__('production_cancellation_owner.stale'));
            }
            $run = $record instanceof ProductionRun ? $record : $record->runs()->orderBy('id')->firstOrFail();
            if ((int) app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, $owner->posting_date)->id !== (int) $owner->posting_financial_period_id) {
                throw new DomainException(__('production_run_correction.target_changed'));
            }
            $this->ready($record, $owner->treatment, $owner->inventory_document_id === null ? null : (int) $owner->inventory_document_id);
            DB::table('production_cancellation_owners')->where('id', $id)->update(['status' => 'applying']);
            $reason = $owner->reason.' — '.$owner->evidence;
            if ($owner->treatment === 'material_document_error') {
                $document = $this->documents($record)->findOrFail($owner->inventory_document_id);
                app(InventoryDocumentPostingService::class)->reverseForProductionMaterialCorrection($document, $id);
                foreach ($document->lines()->orderBy('id')->get() as $line) {
                    $requirement = $record->requirements()->lockForUpdate()->findOrFail($line->source_line_id);
                    $field = $document->document_type === InventoryDocument::TypeProductionWaste ? 'waste_quantity' : 'consumed_quantity';
                    if (bccomp((string) $requirement->{$field}, (string) $line->quantity, 8) < 0) {
                        throw new DomainException(__('production_run_correction.lineage_invalid'));
                    }
                    $requirement->update([$field => bcsub((string) $requirement->{$field}, (string) $line->quantity, 8)]);
                }
            } elseif ($record instanceof ProductionRun) {
                app(ProductionCycleService::class)->cancelRun($record, $reason);
            } elseif ($owner->treatment === 'stop_remaining') {
                Gate::authorize('production.orders.short_close');
                app(ProductionCycleService::class)->shortCloseOrder($record, $reason);
            } else {
                app(ProductionCycleService::class)->cancelRecoveredOrder($record, $id, $reason);
            }
            $execution = $this->execution($record->fresh(), $owner);
            DB::table('production_cancellation_owners')->where('id', $id)->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(),
                'execution_snapshot' => json_encode($execution, JSON_THROW_ON_ERROR), 'execution_seal' => $this->digest($execution), 'updated_at' => now()]);
            $this->audit($record, 'approved', ['owner_id' => $id, 'proposal_seal' => $owner->proposal_seal, 'execution_seal' => $this->digest($execution)]);

            return $this->assertApproved($record->fresh(), $id);
        }, 3);
    }

    public function reject(ProductionOrder|ProductionRun $record, int $id): void
    {
        Gate::authorize('production.runs.correct_approve');
        DB::transaction(function () use ($record, $id): void {
            $record = $this->locked($record);
            $owner = $this->owner($record, $id);
            if ($owner->status !== 'prepared') {
                throw new DomainException(__('production_cancellation_owner.stale'));
            }
            DB::table('production_cancellation_owners')->where('id', $id)->update(['status' => 'rejected', 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'updated_at' => now()]);
            $this->audit($record, 'rejected', ['owner_id' => $id, 'proposal_seal' => $owner->proposal_seal]);
        }, 3);
    }

    public function assertApproved(ProductionOrder|ProductionRun $record, int $id): object
    {
        $owner = $this->owner($record, $id);
        $execution = json_decode($owner->execution_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        if ($owner->status !== 'approved' || $owner->approved_at === null || (int) $owner->prepared_by === (int) $owner->approved_by
            || ! hash_equals($owner->execution_seal ?? '', $this->digest($execution))
            || ! DB::table('activity_log')->where('company_id', $record->company_id)->where('subject_type', $record::class)->where('subject_id', $record->id)
                ->where('event', 'production.cancellation_owner.approved')->where('causer_id', $owner->approved_by)
                ->where('properties->owner_id', $id)->where('properties->execution_seal', $owner->execution_seal)->exists()) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        if ($owner->treatment === 'material_document_error') {
            $document = $this->documents($record)->findOrFail($owner->inventory_document_id);
            $this->assertReversedDocument($document);
            if (($execution['document'] ?? null) !== $document->getRawOriginal()) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
        } elseif ($record->status !== ($owner->treatment === 'stop_remaining' ? ProductionOrder::StatusShortClosed : ProductionOrder::StatusCancelled)) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        } elseif ($owner->treatment === 'document_error') {
            if ($record instanceof ProductionRun) {
                $this->assertRecoveredRun($record);
            } else {
                foreach ($record->runs()->withTrashed()->orderBy('id')->get() as $run) {
                    $this->assertRecoveredRun($run);
                    $runOwner = $this->owners($run)->whereNull('inventory_document_id')->where('status', 'approved')->sole();
                    $this->assertApproved($run, (int) $runOwner->id);
                }
                if ($record->lines()->where('received_base_quantity', '!=', 0)->exists()) {
                    throw new DomainException(__('production_run_correction.lineage_invalid'));
                }
            }
        }

        return $owner;
    }

    public function executionForInventoryDocument(int $id, int $documentId): object
    {
        Gate::authorize('production.runs.correct_approve');
        $owner = DB::table('production_cancellation_owners')->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId())->find($id);
        if ($owner === null || DB::transactionLevel() < 1 || $owner->status !== 'applying' || $owner->treatment !== 'material_document_error'
            || (int) $owner->inventory_document_id !== $documentId || (int) $owner->prepared_by === (int) auth()->id()) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $run = $this->scoped(ProductionRun::query()->findOrFail($owner->production_run_id));
        $this->owner($run, $id);
        $this->ready($run, 'material_document_error', $documentId);

        return $owner;
    }

    private function ready(ProductionOrder|ProductionRun $record, string $treatment, ?int $documentId = null): void
    {
        if ($treatment === 'material_document_error' && $record instanceof ProductionRun) {
            Gate::authorize('production.runs.correct');
            app(ProductionStageTransferService::class)->assertRunRecovery($record);
            if (bccomp((string) $record->total_output_base_quantity, '0', 8) !== 0 || $record->status !== ProductionRun::StatusRunning) {
                throw new DomainException(__('production_cancellation_owner.recover_first'));
            }
            $document = $this->documents($record)->findOrFail($documentId);
            if ($document->trashed() || $document->status !== InventoryDocument::StatusPosted
                || ! in_array($document->document_type, [InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste], true)
                || (int) $document->company_id !== (int) $record->company_id || (int) $document->branch_id !== (int) $record->branch_id
                || (int) $document->production_run_id !== (int) $record->id || $document->source_document_type !== ProductionRun::class
                || (int) $document->source_document_id !== (int) $record->id
                || $document->lines()->where(fn ($query) => $query->whereNull('production_run_id')->orWhere('production_run_id', '<>', $record->id)
                    ->orWhere('source_line_type', '<>', ProductionMaterialRequirement::class)->orWhereNull('source_line_type')->orWhereNull('source_line_id'))->exists()) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            foreach ($document->lines()->orderBy('id')->get() as $line) {
                if (! $record->requirements()->where('company_id', $record->company_id)->where('product_id', $line->product_id)->whereKey($line->source_line_id)->exists()) {
                    throw new DomainException(__('production_run_correction.lineage_invalid'));
                }
            }

            return;
        }
        if ($record instanceof ProductionRun) {
            if ($treatment !== 'document_error' || in_array($record->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_cancellation_owner.recover_first'));
            }
            $this->assertRecoveredRun($record);

            return;
        }
        if (! in_array($treatment, ['document_error', 'stop_remaining'], true) || $record->runs()->doesntExist()
            || in_array($record->status, [ProductionOrder::StatusCancelled, ProductionOrder::StatusShortClosed, ProductionOrder::StatusCompleted], true)) {
            throw new DomainException(__('production_cancellation_owner.recover_first'));
        }
        foreach ($record->runs()->withTrashed()->orderBy('id')->get() as $run) {
            if ($run->trashed() || ! in_array($run->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_cancellation_owner.close_runs_first'));
            }
            if ($treatment === 'document_error' || $run->status === ProductionRun::StatusCancelled) {
                $this->assertRecoveredRun($run);
                $owners = $this->owners($run)->whereNull('inventory_document_id')->where('status', 'approved')->get();
                if ($owners->count() !== 1) {
                    throw new DomainException(__('production_cancellation_owner.close_runs_first'));
                }
                $this->assertApproved($run, (int) $owners->sole()->id);
            } else {
                $cost = app(ProductionCostService::class)->runPosition($run);
                if (bccomp($cost['wip'], '0', 8) !== 0 || app(ProductionShiftEvidenceService::class)->pendingDailyReports($run)) {
                    throw new DomainException(__('production_execution.evidence.close_requires_zero_wip'));
                }
            }
        }
        $runIds = $record->runs()->select('id');
        if (DB::table('production_expense_requests')->where('production_order_id', $record->id)->whereNull('production_run_id')->exists()
            || DB::table('inventory_document_lines')->where('production_order_id', $record->id)
                ->where(fn ($query) => $query->whereNull('production_run_id')->orWhereNotIn('production_run_id', $runIds))->exists()
            || InventoryDocument::withTrashed()->where('production_order_id', $record->id)->whereNull('production_run_id')
                ->whereDoesntHave('lines', fn ($query) => $query->whereIn('production_run_id', $record->runs()->select('id')))
                ->where('status', '<>', InventoryDocument::StatusCancelled)->exists()) {
            throw new DomainException(__('production_cancellation_owner.recover_first'));
        }
        if ($treatment === 'document_error' && $record->lines()->where('received_base_quantity', '!=', 0)->exists()) {
            throw new DomainException(__('production_cancellation_owner.recover_first'));
        }
    }

    private function assertRecoveredRun(ProductionRun $run): void
    {
        if ($run->trashed() || bccomp((string) $run->total_output_base_quantity, '0', 8) !== 0 || bccomp((string) $run->received_base_quantity, '0', 8) !== 0) {
            throw new DomainException(__('production_cancellation_owner.recover_first'));
        }
        foreach (ProductionStageOutputCostService::OutputFields as $field) {
            if (bccomp((string) $run->{$field}, '0', 8) !== 0 || bccomp((string) $run->progressEntries()->sum($field), '0', 8) !== 0) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
        }
        app(ProductionStageTransferService::class)->assertRunRecovery($run);
        app(ProductionQualityQuantityService::class)->assertDailyCorrectionWithdrawals($run);
        foreach ($run->requirements()->orderBy('id')->get() as $requirement) {
            if (bccomp(bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8), (string) $requirement->returned_quantity, 8) !== 0
                || bccomp(bcadd((string) $requirement->consumed_quantity, (string) $requirement->waste_quantity, 8), '0', 8) !== 0) {
                throw new DomainException(__('production_cancellation_owner.recover_first'));
            }
        }
        if ($run->expenseRequests()->whereNotIn('status', ['rejected', 'reversed'])->exists()
            || $run->materialRequests()->whereIn('status', ['draft', 'submitted', 'approved', 'shortage', 'partially_issued'])->exists()
            || DB::table('production_piece_approvals')->where('production_run_id', $run->id)->whereNull('revoked_at')->exists()
            || app(ProductionCorrectionDependencyService::class)->steps(app(ProductionCorrectionDependencyService::class)->snapshot($run, collect(), collect())) !== []) {
            throw new DomainException(__('production_cancellation_owner.recover_first'));
        }
        $cost = app(ProductionCostService::class)->runPosition($run);
        foreach (['wip', 'direct_material_cost', 'other_direct_cost', 'direct_labor_cost', 'allocated_overhead', 'finished_goods'] as $field) {
            if (bccomp($cost[$field], '0', 8) !== 0) {
                throw new DomainException(__('production_cancellation_owner.recover_first'));
            }
        }
        foreach ($this->documents($run)->orderBy('id')->get() as $document) {
            if ($document->trashed()) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            if ($document->status === InventoryDocument::StatusReversed) {
                $this->assertReversedDocument($document);
            } elseif ($document->status === InventoryDocument::StatusPosted && ! in_array($document->document_type,
                [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue, InventoryDocument::TypeMaterialReturn], true)) {
                throw new DomainException(__('production_cancellation_owner.recover_first'));
            } elseif (! in_array($document->status, [InventoryDocument::StatusPosted, InventoryDocument::StatusCancelled], true)) {
                throw new DomainException(__('production_cancellation_owner.recover_first'));
            }
            if ($document->status === InventoryDocument::StatusPosted) {
                app(InventoryDocumentPostingService::class)->assertProductionOwnerOriginal($document);
            } elseif ($document->status === InventoryDocument::StatusCancelled
                && ($document->transactions()->exists() || $document->journal_entry_id !== null || $document->cancelled_at === null || $document->cancelled_by === null || blank($document->cancel_reason))) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
        }
        foreach ($run->expenseRequests()->where('status', 'reversed')->orderBy('id')->get() as $expense) {
            $original = JournalEntry::query()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)
                ->where('source_type', 'production_expense_payment')->where('source_id', $expense->id)->findOrFail($expense->journal_entry_id);
            $inverse = JournalEntry::query()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)
                ->where('source_type', 'production_expense_reversal')->where('source_id', $expense->id)->findOrFail($expense->reversal_journal_entry_id);
            app(JournalEntryService::class)->assertPostedReversal($original, $inverse);
            if ($expense->reversed_at === null || $expense->reversed_by === null || blank($expense->reversal_reason)) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
        }
    }

    private function assertReversedDocument(InventoryDocument $document): void
    {
        if ($document->status !== InventoryDocument::StatusReversed || $document->reversed_at === null || $document->reversed_by === null || blank($document->reversal_reason)) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        foreach ($document->transactions()->where('is_reversal', false)->orderBy('id')->get() as $source) {
            if (! app(DocumentOwnerEffectProofService::class)->stockHasExactInverse($source)) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
        }
        if ($document->journal_entry_id !== null) {
            $original = JournalEntry::query()->findOrFail($document->journal_entry_id);
            $inverse = JournalEntry::query()->findOrFail($original->reversed_entry_id);
            app(JournalEntryService::class)->assertPostedReversal($original, $inverse);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ProductionOrder|ProductionRun $record): array
    {
        $order = $record instanceof ProductionRun ? $record->order : $record;
        $runs = $record instanceof ProductionRun ? collect([$record]) : $record->runs()->orderBy('id')->get();
        $rows = [];
        foreach ($runs as $run) {
            $documents = $this->documents($run)->orderBy('id')->get();
            $rows[] = ['run' => $run->getRawOriginal(), 'progress' => $run->progressEntries()->orderBy('id')->get()->map->getRawOriginal()->all(),
                'requirements' => $run->requirements()->orderBy('id')->get()->map->getRawOriginal()->all(),
                'expenses' => $run->expenseRequests()->orderBy('id')->get()->map->getRawOriginal()->all(),
                'material_requests' => $run->materialRequests()->orderBy('id')->get()->map->getRawOriginal()->all(),
                'dependencies' => app(ProductionCorrectionDependencyService::class)->snapshot($run, collect(), collect()),
                'documents' => $documents->map(fn ($doc): array => ['header' => $doc->getRawOriginal(), 'lines' => $doc->lines()->orderBy('id')->get()->map->getRawOriginal()->all(),
                    'transactions' => $doc->transactions()->orderBy('id')->get()->map->getRawOriginal()->all(), 'journal' => $doc->journalEntry?->getRawOriginal(),
                    'journal_lines' => $doc->journalEntry?->lines()->orderBy('id')->get()->map->getRawOriginal()->all()])->all()];
        }

        return ['record' => $record->getRawOriginal(), 'order' => $order->getRawOriginal(), 'lines' => $order->lines()->orderBy('id')->get()->map->getRawOriginal()->all(),
            'sales_lines' => SalesOrderLine::query()->whereIn('id', $order->lines()->whereNotNull('sales_order_line_id')->select('sales_order_line_id'))->orderBy('id')->get()->map->getRawOriginal()->all(), 'runs' => $rows];
    }

    /** @return array<string, mixed> */
    private function execution(ProductionOrder|ProductionRun $record, object $owner): array
    {
        return ['record' => $record->getRawOriginal(), 'document' => $owner->inventory_document_id === null ? null : $this->documents($record)->findOrFail($owner->inventory_document_id)->getRawOriginal(),
            'approved_by' => auth()->id(), 'lines' => ($record instanceof ProductionRun ? $record->order : $record)->lines()->orderBy('id')->get()->map->getRawOriginal()->all()];
    }

    private function owners(ProductionOrder|ProductionRun $record): Builder
    {
        return DB::table('production_cancellation_owners')->where('company_id', $record->company_id)->where('production_order_id', $record instanceof ProductionRun ? $record->production_order_id : $record->id)
            ->where('production_run_id', $record instanceof ProductionRun ? $record->id : null);
    }

    private function documents(ProductionRun $run): \Illuminate\Database\Eloquent\Builder
    {
        return InventoryDocument::withTrashed()->where('company_id', $run->company_id)->where(fn ($query) => $query->where('production_run_id', $run->id)
            ->orWhereIn('id', DB::table('inventory_document_lines')->where('production_run_id', $run->id)->select('inventory_document_id')));
    }

    private function owner(ProductionOrder|ProductionRun $record, int $id): object
    {
        $this->schema();
        $owner = $this->owners($record)->lockForUpdate()->find($id);
        abort_if($owner === null, 404);
        $data = [];
        foreach (['public_id', 'company_id', 'branch_id', 'financial_period_id', 'posting_financial_period_id', 'production_order_id', 'production_run_id',
            'inventory_document_id', 'posting_date', 'treatment', 'reason', 'evidence', 'prepared_by', 'source_snapshot'] as $field) {
            $data[$field] = $field === 'source_snapshot' ? json_decode($owner->{$field}, true, flags: JSON_THROW_ON_ERROR) : $owner->{$field};
        }
        if (! hash_equals($owner->proposal_seal, $this->digest($data))) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }

        return $owner;
    }

    private function scoped(ProductionOrder|ProductionRun $record): ProductionOrder|ProductionRun
    {
        $company = app(OperatingCompanyContextService::class)->currentCompany();
        $scope = app(OperatingScopeAccessService::class);
        $record = $record::query()->where('company_id', $company->id)->where('branch_id', request()->session()->get(OperatingContextService::BranchIdKey))->findOrFail($record->id);
        abort_unless($scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $record->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $record->financial_period_id)->exists(), 404);

        return $record;
    }

    private function locked(ProductionOrder|ProductionRun $record): ProductionOrder|ProductionRun
    {
        Company::query()->whereKey(app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->firstOrFail();
        $record = $this->scoped($record);

        return $record::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
    }

    private function authorize(ProductionOrder|ProductionRun $record): void
    {
        Gate::authorize($record instanceof ProductionRun ? 'production.runs.cancel' : 'production.orders.cancel');
    }

    private function schema(): void
    {
        if (! Schema::hasTable('production_cancellation_owners')) {
            throw new DomainException(__('production_cancellation_owner.migration_required'));
        }
    }

    /** @param array<string, mixed> $data */
    private function digest(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string, mixed> $properties */
    private function audit(Model $record, string $event, array $properties): void
    {
        app(ActivityLogger::class)->log(request(), 'production', 'production.cancellation_owner.'.$event, 'success', [
            'subject' => $record, 'company_id' => $record->company_id, 'properties_only' => true, 'properties' => $properties]);
    }
}
