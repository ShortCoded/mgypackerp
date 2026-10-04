<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\FinancialPeriod;
use Modules\HR\Services\PayrollAccrualService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionQualityWorkflowService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);
require_once __DIR__.'/ManufacturingInventorySupport.php';
require_once __DIR__.'/ProductionLaterPeriodCompletionCases.php';

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
});

test('completed production correction preserves original period batch dates and piece entitlement across months', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $fixture = productionCorrectionCompletedFixture(true, '-SYNTHETIC-PG-CORR');
    $run = $fixture['run'];
    $ended = $run->actual_end_at->toDateTimeString();
    $originalLine = $fixture['receipt']->lines->sole();
    $fixture['period']->update(['to_date' => '2026-09-30']);
    $nextPeriod = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 99771, 'doc_num' => 'SYNTHETIC-CORRECTION-OCT',
        'name' => 'Synthetic October correction boundary', 'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    $oldDraft = $run->inspections()->create([
        'doc_number' => 99271, 'doc_num' => 'SYNTHETIC-CORRECTION-OLD-DRAFT',
        'inspection_date' => '2026-09-30',
        'company_id' => $run->company_id, 'financial_period_id' => $run->financial_period_id, 'branch_id' => $run->branch_id,
        'subject_type' => ProductionQualityInspection::SubjectProductionRun,
        'product_id' => $run->product_id, 'status' => 'draft', 'result' => 'pending', 'correction_sequence' => 0,
    ]);
    $this->travelTo(Carbon::parse('2026-10-04 12:00:00'));
    $session = manufacturingIntegritySession($fixture);
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.production.runs.corrections.index', $run))->assertOk();
    $service = app(ProductionRunCorrectionService::class);
    $preview = $service->preview($run);
    $payload = ['fingerprint' => $preview['fingerprint'], 'reason' => 'SYNTHETIC original manufacturing basis', 'posting_date' => '2026-10-04',
        'output' => ['good_base_quantity' => '9', 'rejected_base_quantity' => '1', 'rework_base_quantity' => '0', 'scrap_base_quantity' => '0']];
    $this->postJson(route('admin.production.runs.corrections.store', $run), $payload)->assertStatus(422);
    $payload['posting_date'] = '2026-09-30';
    $id = $this->postJson(route('admin.production.runs.corrections.store', $run), $payload)->assertOk()->json('data.correction_id');
    $this->actingAs($fixture['approver'])->withSession($session)->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertOk();
    $run->refresh();
    expect($run->actual_end_at->toDateTimeString())->toBe($ended)
        ->and(DB::table('production_piece_approvals')->where('production_run_id', $run->getKey())->whereNotNull('revoked_at')->count())->toBe(1);
    expect(fn () => app(ProductionQualityWorkflowService::class)->updateDraft($oldDraft, $run, ['subject_type' => $oldDraft->subject_type]))
        ->toThrow(DomainException::class, __('production_run_correction.old_inspection'));
    expect($oldDraft->fresh()->correction_sequence)->toBe(0);
    $fixture['cycle']->recordLabor($run, ['actual_labor_count' => 1, 'labor_details' => [['employee_id' => $fixture['employee']->getKey(), 'actual_hours' => '1', 'piece_quantity' => '9']]]);
    $fixture['cycle']->accountMaterials($run->fresh(), $fixture['store']->getKey(), [$fixture['requirement']->getKey() => ['consumed_quantity' => '18', 'waste_quantity' => '2']]);
    $inspection = $fixture['cycle']->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
    $fixture['cycle']->reviewInspection($inspection, true);
    $receipt = $fixture['cycle']->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '9');
    $run = $fixture['cycle']->completeRun($run->fresh());
    expect($receipt->financial_period_id)->toBe($fixture['period']->getKey())
        ->and($receipt->document_date->toDateString())->toBe('2026-09-30')
        ->and($receipt->lines->sole()->manufacture_date->toDateString())->toBe($originalLine->manufacture_date->toDateString())
        ->and($receipt->lines->sole()->expiry_date->toDateString())->toBe($originalLine->expiry_date->toDateString())
        ->and($run->actual_end_at->toDateTimeString())->toBe($ended);
    $assignment = (object) ['basic_salary' => null, 'effective_from' => '2026-09-01', 'effective_to' => null];
    $accrual = app(PayrollAccrualService::class);
    expect($accrual->calculate($fixture['employee']->fresh(), $assignment, '2026-09-01', '2026-09-30', [])['amount'])->toBe('90.0000')
        ->and(fn () => $accrual->calculate($fixture['employee']->fresh(), $assignment, '2026-10-01', '2026-10-31', []))->toThrow(DomainException::class);
    foreach ([$fixture['period'], $nextPeriod] as $period) {
        $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->getKey(), $period->getKey()))->keyBy('key');
        expect($reconciliation['wip']['difference'])->toBe('0.0000')->and($reconciliation['finished_goods']['difference'])->toBe('0.0000');
    }
    $second = $service->preview($run);
    $payload['fingerprint'] = $second['fingerprint'];
    $payload['reason'] = 'SYNTHETIC repeat correction proposal';
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.runs.corrections.store', $run), $payload)->assertOk();
});

test('completed production correction reverses source costs preserves issues and finishes corrected output with fresh quality and reconciled GL', function (): void {
    $fixture = productionCorrectionCompletedFixture(false, '-SYNTHETIC-PG-CORR');
    $run = $fixture['run'];
    $session = manufacturingIntegritySession($fixture);
    $url = route('admin.production.runs.corrections.index', $run);
    $this->withoutExceptionHandling();
    $this->actingAs($fixture['user'])->withSession($session)->get($url)->assertOk()->assertSee(__('production_run_correction.title'));
    $this->withExceptionHandling();
    $service = app(ProductionRunCorrectionService::class);
    $before = $service->preview($run);
    $originalProgress = $run->progressEntries()->firstOrFail()->getAttributes();
    $id = $this->postJson(route('admin.production.runs.corrections.store', $run), [
        'fingerprint' => $before['fingerprint'], 'reason' => 'SYNTHETIC count and waste correction', 'posting_date' => $run->actual_end_at->toDateString(),
        'output' => ['good_base_quantity' => '9', 'rejected_base_quantity' => '1', 'rework_base_quantity' => '0', 'scrap_base_quantity' => '0'],
    ])->assertOk()->json('data.correction_id');
    $this->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertStatus(422);
    $this->actingAs($fixture['approver'])->withSession($session)
        ->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertOk();
    $transactionCount = InventoryTransaction::query()->count();
    $this->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertOk();
    expect(InventoryTransaction::query()->count())->toBe($transactionCount)
        ->and($fixture['issue']->refresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($fixture['receipt']->refresh()->status)->toBe(InventoryDocument::StatusReversed)
        ->and($fixture['costDocuments']['consumption']->refresh()->status)->toBe(InventoryDocument::StatusReversed)
        ->and($fixture['costDocuments']['waste']->refresh()->status)->toBe(InventoryDocument::StatusReversed)
        ->and($run->refresh()->status)->toBe(ProductionRun::StatusRunning)
        ->and($run->received_base_quantity)->toBe('0.00000000')
        ->and($run->good_base_quantity)->toBe('9.00000000')
        ->and($run->progressEntries()->firstOrFail()->getAttributes())->toBe($originalProgress)
        ->and((float) $run->progressEntries()->sum('good_base_quantity'))->toBe(9.0)
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('40.00000000');
    $fixture['cycle']->accountMaterials($run, $fixture['store']->getKey(), [
        $fixture['requirement']->getKey() => ['consumed_quantity' => '18', 'waste_quantity' => '2'],
    ]);
    expect(fn () => $fixture['cycle']->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '9'))
        ->toThrow(DomainException::class, __('A final passed quality inspection is required before finished goods become available.'));
    $inspection = $fixture['cycle']->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
    $fixture['cycle']->reviewInspection($inspection, true);
    $newReceipt = $fixture['cycle']->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '9');
    $run = $fixture['cycle']->completeRun($run->fresh());
    expect($newReceipt->lines->sole()->total_cost)->toBe('36.00000000')
        ->and($run->status)->toBe(ProductionRun::StatusCompleted)
        ->and($run->orderLine->received_base_quantity)->toBe('9.00000000')
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000')
        ->and($inspection->refresh()->correction_sequence)->toBe(1)
        ->and(app(InventoryAvailabilityService::class)->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['finished']->getKey())['on_hand'])->toBe('9.00000000');
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->getKey(), $fixture['period']->getKey()))->keyBy('key');
    expect($reconciliation['wip']['difference'])->toBe('0.0000')
        ->and($reconciliation['finished_goods']['difference'])->toBe('0.0000');
    $this->get($url)->assertOk()->assertSee('SYNTHETIC count and waste correction');
});

require_once __DIR__.'/ProductionCorrectionReceiptLineageCases.php';

require_once __DIR__.'/ProductionLaterPeriodCorrectionCases.php';
