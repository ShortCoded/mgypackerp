<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionMaterialRequestService;
use Modules\Production\Services\ProductionQualityQuantityService;
use Modules\Production\Services\ProductionShiftEvidenceService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);
require_once __DIR__.'/ManufacturingInventorySupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_production_evidence_pg_20261005')->and($identity->host)->toBe('127.0.0.1')->and((int) $identity->port)->toBe(5432);
    $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
});

test('actual customer factory run reconciles available output and exposes customer configuration blockers', function (int $runId, int $cycles): void {
    $run = ProductionRun::query()->with(['requirements.product', 'requirements.unit', 'order.orderStageSnapshots', 'orderLine.stageSnapshots', 'fixedAsset'])->findOrFail($runId);
    expect($run->company_id)->toBe(1)->and($run->good_base_quantity)->toBe('0.00000000')->and($run->status)->toBe('running');
    $company = Company::query()->findOrFail($run->company_id);
    $branch = Branch::query()->findOrFail($run->branch_id);
    $period = FinancialPeriod::query()->findOrFail($run->financial_period_id);
    $user = closureSyntheticUser();
    $user->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $this->actingAs($user)->withSession(manufacturingIntegritySession(compact('company', 'branch', 'period')));
    request()->setUserResolver(fn () => $user);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(manufacturingIntegritySession(compact('company', 'branch', 'period')));
    $cycle = app(ProductionCycleService::class);
    $materials = app(ProductionMaterialRequestService::class);
    $costs = app(ProductionCostService::class);
    $shifts = app(ProductionShiftEvidenceService::class);
    $quality = QualityInspectionType::query()->create(['company_id' => $company->id, 'code' => 'SYNTHETIC-LOCAL-FACTORY-QA',
        'name' => 'SYNTHETIC local trial final quantity inspection; not customer approval', 'is_final_production' => true, 'is_active' => true]);
    $rawStore = $runId === 13 ? 4 : 2;
    $finishedStore = $runId === 13 ? 3 : 1;
    $roles = $runId === 13 ? ['checklist', 'manufacturing', 'quality', 'receipt'] : ['checklist', 'checklist', 'quality_notification', 'manufacturing', 'receipt'];
    $stageRoles = $run->order->orderStageSnapshots->sortBy('sequence')->values()->map(fn ($stage, int $index): array => [
        'stage_public_id' => $stage->public_id, 'role' => $roles[$index],
        'confirmation_reason' => in_array($roles[$index], ['checklist', 'quality_notification'], true)
            ? 'SYNTHETIC local trial: explicit review of original order, factory, machine and material requirements; no customer approval' : null,
    ])->all();
    $definitions = $run->requirements->map(fn ($requirement): array => ['requirement_public_id' => $requirement->public_id,
        'basis' => $runId === 13 ? 'measured_material' : 'output_components'])->all();
    $run = $cycle->enableOutputEvidence($run, $definitions, 'factory_workflow', $stageRoles);
    $employees = HrEmployee::query()->where('company_id', $company->id)->where('status', 'active')->assignedToPayrollBranchesDuring([$branch->id], now()->toDateString(), now()->toDateString())->orderBy('id')->limit(2)->get();
    expect($employees)->toHaveCount(2);
    $shift = $shifts->saveDefaults($run, ['shift_code' => 'SYN-LOCAL-A', 'shift_name' => 'SYNTHETIC local trial A', 'starts_at' => '12:00', 'ends_at' => '20:00',
        'crew' => [['employee_id' => $employees[0]->id, 'role' => 'technician'], ['employee_id' => $employees[1]->id, 'role' => 'supervisor']]]);
    $firstRequirement = $run->requirements->first();
    $entry = $shifts->record($run, ['production_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(),
        'sheet_fields' => ['sheet_kind' => $runId === 13 ? 'injection' : 'cover', 'pack_ratio' => $run->orderLine->bom_snapshot['basis_base_quantity'],
            'primary_material_requirement_public_id' => $firstRequirement->public_id], 'notes' => 'SYNTHETIC local production trial on actual customer order and assigned machine.']);
    $allIds = $run->requirements->mapWithKeys(fn ($r): array => [$r->id => '0'])->all();
    $additional = $materials->create($run, $rawStore, [...$allIds, $firstRequirement->id => '10'], additional: true,
        reason: 'SYNTHETIC local acceptance: measured process waste and return of unused issued material; no real customer approval');
    $additional = $materials->approve($additional);
    expect($additional->lines->sole()->reserved_quantity)->toBe('10.00000000');
    $issued = $materials->issue($additional);
    $baselinePosition = $costs->runPosition($run->fresh());
    if ($runId === 18) {
        $short = $run->requirements->firstWhere('product_id', 637);
        $open = $materials->create($run->fresh(), $rawStore, [...$allIds, $short->id => '4000'], additional: true,
            reason: 'SYNTHETIC local acceptance: complete remaining planned demand from actual available customer stock');
        $open = $materials->approve($open);
        expect($open->lines->sole()->reserved_quantity)->toBe('0.00000000')
            ->and($open->lines->sole()->shortage_quantity)->toBe('4000.00000000');
        expect(fn () => $materials->issue($open))->toThrow(DomainException::class);
    }
    $targetQuantity = $runId === 13 ? (string) $run->planned_base_quantity : '75.00000000';
    $wasteRejected = false;
    $received = '0';
    $batch = bcdiv($targetQuantity, (string) $cycles, 0);
    $documents = [];
    for ($index = 0; $index < $cycles; $index++) {
        $quantity = $index === $cycles - 1 ? bcsub($targetQuantity, $received, 8) : $batch;
        $evidence = $run->requirements->map(function ($requirement) use ($run, $runId, $quantity, $index, $firstRequirement): array {
            $row = ['requirement_public_id' => $requirement->public_id];
            if ($runId === 13) {
                $row['measured_quantity'] = bcdiv(bcmul((string) $requirement->planned_quantity, $quantity, 16), (string) $run->planned_base_quantity, 8);
            }
            if ($index === 0 && $requirement->id === $firstRequirement->id) {
                $row += ['waste_quantity' => '1', 'waste_classification' => $runId === 13 ? 'process_scrap' : 'packaging_loss',
                    'notes' => 'SYNTHETIC measured trial waste using actual issued customer material and native loss posting'];
            }

            return $row;
        })->all();
        if ($index === 0) {
            expect(fn () => $cycle->recordProgress($run->fresh(), ['good_base_quantity' => $quantity, 'production_shift_entry_id' => $entry->id,
                'material_evidence' => $evidence]))->toThrow(DomainException::class, 'abnormal_waste_loss');
            expect($run->fresh()->good_base_quantity)->toBe('0.00000000');
            $wasteRejected = true;
            $evidence = array_map(function (array $row): array {
                unset($row['waste_quantity'], $row['waste_classification'], $row['notes']);

                return $row;
            }, $evidence);
        }
        $progress = $cycle->recordProgress($run->fresh(), ['good_base_quantity' => $quantity, 'production_shift_entry_id' => $entry->id,
            'material_evidence' => $evidence, 'notes' => 'SYNTHETIC local output entry '.($index + 1).' of '.$cycles]);
        $inspection = $cycle->recordInspection($run->fresh(), ['quality_inspection_type_id' => $quality->id, 'result' => 'passed', 'disposition' => 'release',
            'affected_base_quantity' => $quantity, 'accepted_base_quantity' => $quantity, 'notes' => 'SYNTHETIC local quality simulation; no customer approval']);
        expect(app(ProductionQualityQuantityService::class)->availableQuantity($run->fresh()))->toBe(bcadd($quantity, '0', 8));
        $receipt = $cycle->receiveFinishedGoods($run->fresh(), $finishedStore, $quantity);
        $received = bcadd($received, $quantity, 8);
        expect($run->fresh()->status)->toBe('running')->and($run->fresh()->received_base_quantity)->toBe($received);
        expect(fn () => $cycle->receiveFinishedGoods($run->fresh(), $finishedStore, '1'))->toThrow(DomainException::class);
        $documents[] = ['progress_id' => $progress->id, 'inspection' => $inspection->doc_num, 'receipt' => $receipt->doc_num,
            'quantity' => $quantity, 'position' => $costs->runPosition($run->fresh())];
    }
    $return = null;
    if ($runId === 13) {
        $unused = $run->fresh()->requirements->mapWithKeys(fn ($r): array => [$r->id => bcsub(bcsub(bcadd((string) $r->issued_quantity, (string) $r->additional_issued_quantity, 8), (string) $r->returned_quantity, 8), bcadd((string) $r->consumed_quantity, (string) $r->waste_quantity, 8), 8)])->all();
        $return = $cycle->returnMaterials($run->fresh(), $rawStore, $unused);
        $run = $cycle->completeRun($run->fresh());
        expect($run->status)->toBe('completed')->and($run->received_base_quantity)->toBe($targetQuantity);
    } else {
        expect(fn () => $cycle->completeRun($run->fresh()))->toThrow(DomainException::class);
        $run = $run->fresh();
        expect($run->status)->toBe('running')->and($run->received_base_quantity)->toBe($targetQuantity);
        $shifts->close($run, $entry->id, now()->toDateTimeString());
    }
    $position = $costs->runPosition($run);
    if ($runId === 13) {
        expect($position['wip'])->toBe('0.00000000');
    } else {
        expect(bccomp($position['wip'], '0', 8))->toBe(1);
    }
    $staging = InventoryTransaction::query()->where('company_id', $company->id)->where('branch_id', $branch->id)->where('production_run_id', $run->id)
        ->where('stock_status', InventoryTransaction::StatusProductionStaging)->selectRaw('coalesce(sum(quantity_in-quantity_out),0) as quantity')->first();
    expect(bccomp((string) $staging->quantity, '0', 8))->toBe($runId === 13 ? 0 : 1);
    $newDocs = InventoryDocument::query()->where('company_id', $company->id)->where('production_run_id', $run->id)->where('document_date', now()->toDateString())->where('status', 'posted')->with('journalEntry.lines')->get();
    foreach ($newDocs as $document) {
        if ($document->document_type === InventoryDocument::TypeMaterialConsumption) {
            expect($document->journalEntry)->toBeNull();

            continue;
        }
        expect($document->journalEntry)->not->toBeNull();
        $totals = $document->journalEntry->lines()->reorder()->selectRaw('coalesce(sum(debit_amount),0) as total_debit, coalesce(sum(credit_amount),0) as total_credit')->first();
        expect(bccomp((string) $totals->total_debit, (string) $totals->total_credit, 8))->toBe(0);
    }
    $report = $shifts->report($run)->sole();
    expect($report->good_base_quantity)->toBe($targetQuantity)->and(count($report->crew_snapshot))->toBe(2)
        ->and($report->ended_at)->not->toBeNull();
    $proof = ['at_utc' => gmdate(DATE_ATOM), 'target' => DB::connection()->getDatabaseName(), 'run_id' => $runId, 'run_number' => $run->run_number,
        'branch_id' => $branch->id, 'actual_machine' => $run->fixedAsset->doc_num, 'cycles' => $cycles,
        'source' => 'Actual latest customer order/run/recipe/machine/stores/staff; all mutations rollback after test',
        'approval' => 'Synthetic local operational/QA simulation; no customer or live approval', 'material_request' => $additional->doc_num,
        'issue' => $issued->doc_num, 'return' => $return?->doc_num, 'entries' => $documents, 'final_position' => $position,
        'finished_quantity' => $run->received_base_quantity, 'staging_quantity' => (string) $staging->quantity,
        'native_journals_reconciled' => $newDocs->filter(fn ($document): bool => $document->journalEntry !== null)->count(),
        'waste_posting_safely_rejected' => $wasteRejected, 'customer_missing_account' => 'abnormal_waste_loss',
        'customer_stock_shortage' => $runId === 18 ? ['product_id' => 637, 'quantity' => '4000.00000000', 'missing_output_base' => '5.00000000'] : null,
        'completion' => $runId === 13 ? 'completed' : 'blocked by actual stock shortage; unused stock and WIP preserved', 'exit_gate' => 'passed'];
    file_put_contents(storage_path('app/test-artifacts/mgypack-customer-production-'.$runId.'-cycles-'.$cycles.'-20261005.json'), json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
})->with(['injection once' => [13, 1], 'injection twice' => [13, 2], 'injection ten' => [13, 10], 'cover once' => [18, 1], 'cover twice' => [18, 2], 'cover ten' => [18, 10]]);
