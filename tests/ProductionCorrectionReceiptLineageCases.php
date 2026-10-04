<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Services\ProductionRunCorrectionService;

/** @return array<string, mixed> */
function receiptLineageCorrectionPayload(array $fixture): array
{
    $preview = app(ProductionRunCorrectionService::class)->preview($fixture['run']);

    return ['fingerprint' => $preview['fingerprint'], 'reason' => 'SYNTHETIC receipt date lineage', 'posting_date' => '2026-09-30',
        'output' => ['good_base_quantity' => '9', 'rejected_base_quantity' => '1', 'rework_base_quantity' => '0', 'scrap_base_quantity' => '0']];
}

test('production correction preserves separate partial receipt dates across replacement receipts', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $fixture = productionCorrectionCompletedFixture(true, '-SYNTHETIC-DATE-BASIS', [
        ['quantity' => '4', 'date' => '2026-09-29 12:00:00', 'batch' => 'SYNTHETIC-LOT-A'], ['quantity' => '6', 'date' => '2026-09-30 12:00:00', 'batch' => 'SYNTHETIC-LOT-B'],
    ], true);
    $run = $fixture['run'];
    $session = manufacturingIntegritySession($fixture);
    $this->actingAs($fixture['user'])->withSession($session);
    $payload = receiptLineageCorrectionPayload($fixture);
    $url = route('admin.production.runs.corrections.store', $run);
    $id = $this->postJson($url, $payload)->assertOk()->json('data.correction_id');
    $this->actingAs($fixture['approver'])->withSession($session)->get(route('admin.production.runs.corrections.index', $run))
        ->assertOk()->assertSee(__('production_run_correction.receipt_dates'));
    $this->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertOk();
    $fixture['finished']->update(['default_shelf_life_days' => 1]);
    $fixture['cycle']->accountMaterials($run->fresh(), $fixture['store']->id, [$fixture['requirement']->id => ['consumed_quantity' => '18', 'waste_quantity' => '2']]);
    $inspection = $fixture['cycle']->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
    $fixture['cycle']->reviewInspection($inspection, true);
    $first = $fixture['cycle']->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '3');
    $second = $fixture['cycle']->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '6');
    $lines = $first->lines->concat($second->lines)->values();
    expect($lines->map(fn ($line) => (string) $line->quantity)->all())->toBe(['3.00000000', '1.00000000', '5.00000000'])
        ->and($lines->map(fn ($line) => $line->manufacture_date->toDateString())->all())->toBe(['2026-09-29', '2026-09-29', '2026-09-30'])
        ->and($lines->map(fn ($line) => $line->expiry_date->toDateString())->all())->toBe(['2027-03-28', '2027-03-28', '2027-03-29'])
        ->and($lines->pluck('batch_lot')->all())->toBe(['SYNTHETIC-LOT-A', 'SYNTHETIC-LOT-A', 'SYNTHETIC-LOT-B'])
        ->and(bccomp((string) $lines->sum('total_cost'), '36', 8))->toBe(0)
        ->and($run->fresh()->received_base_quantity)->toBe('9.00000000');
    $reservations = $run->reservations()->whereNotNull('sales_order_line_id')->where('status', InventoryReservation::StatusActive)->get();
    expect(bccomp((string) $reservations->where('batch_lot', 'SYNTHETIC-LOT-A')->sum('quantity'), '4', 8))->toBe(0)
        ->and(bccomp((string) $reservations->where('batch_lot', 'SYNTHETIC-LOT-B')->sum('quantity'), '5', 8))->toBe(0)
        ->and($fixture['salesLine']->fresh()->produced_base_quantity)->toBe('9.00000000');
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id))->keyBy('key');
    expect($reconciliation['wip']['difference'])->toBe('0.0000')->and($reconciliation['finished_goods']['difference'])->toBe('0.0000');
});

test('production correction missing original expiry requires evidenced independently approved input and rejects malformed or changed dates', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $fixture = productionCorrectionCompletedFixture(true, '-SYNTHETIC-LEGACY-DATES');
    $run = $fixture['run'];
    $line = $fixture['receipt']->lines->sole();
    DB::table('inventory_document_lines')->where('id', $line->id)->update(['expiry_date' => null]);
    $session = manufacturingIntegritySession($fixture);
    $this->actingAs($fixture['user'])->withSession($session);
    $url = route('admin.production.runs.corrections.store', $run);
    $payload = receiptLineageCorrectionPayload($fixture);
    $this->get(route('admin.production.runs.corrections.index', $run))->assertOk()->assertSee('receipt_dates['.$line->id.'][expiry_date]', false);
    $this->postJson($url, $payload)->assertStatus(422)->assertJsonPath('errors.correction.0', __('production_run_correction.receipt_dates_required'));
    $this->postJson($url, [...$payload, 'receipt_dates' => 'malformed'])->assertUnprocessable()->assertJsonValidationErrors('receipt_dates');
    $this->postJson($url, [...$payload, 'receipt_dates' => [$line->id => 'malformed']])->assertUnprocessable()->assertJsonValidationErrors('receipt_dates.'.$line->id);
    $payload['receipt_dates'] = [$line->id => ['expiry_date' => '2027-03-29']];
    $this->postJson($url, $payload)->assertUnprocessable();
    $payload['receipt_date_evidence'] = 'SYNTHETIC original batch dated receipt evidence';
    $this->postJson($url, [...$payload, 'receipt_dates' => [$line->id => ['manufacture_date' => '2026-09-29', 'expiry_date' => '2027-03-29']]])
        ->assertUnprocessable()->assertJsonPath('errors.correction.0', __('production_run_correction.receipt_dates_immutable'));
    $id = $this->postJson($url, $payload)->assertOk()->json('data.correction_id');
    expect(DB::table('production_run_corrections')->where('id', $id)->value('receipt_date_evidence'))->toBe($payload['receipt_date_evidence']);
    $this->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertUnprocessable();
    $this->actingAs($fixture['approver'])->withSession($session)->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertOk();
    expect($run->fresh()->correction_receipt_basis[0]['expiry_date'])->toBe('2027-03-29')
        ->and(DB::table('inventory_document_lines')->where('id', $line->id)->value('expiry_date'))->toBeNull();
});

test('dated production correction migrations preserve independent manufacture expiry and receipt source evidence', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $fixture = productionCorrectionCompletedFixture(suffix: '-SYNTHETIC-MIGRATION-'.Str::random(8));
    $dated = require base_path('database/migrations/2026_10_03_065217_add_effective_dates_to_production_run_corrections.php');
    foreach (['correction_manufacture_date' => '2026-09-30', 'correction_expiry_date' => '2027-03-29'] as $column => $date) {
        DB::table('production_runs')->where('id', $fixture['run']->id)->update([$column => $date]);
        expect(fn () => $dated->down())->toThrow(RuntimeException::class)
            ->and(DB::table('production_runs')->where('id', $fixture['run']->id)->value($column))->toBe($date);
        DB::table('production_runs')->where('id', $fixture['run']->id)->update([$column => null]);
    }
    $this->actingAs($fixture['user'])->withSession(manufacturingIntegritySession($fixture));
    $id = $this->postJson(route('admin.production.runs.corrections.store', $fixture['run']), receiptLineageCorrectionPayload($fixture))->assertOk()->json('data.correction_id');
    DB::table('production_run_corrections')->where('id', $id)->update(['receipt_date_basis' => null, 'receipt_date_evidence' => 'SYNTHETIC independent source evidence']);
    $lineage = require base_path('database/migrations/2026_10_03_070303_add_receipt_date_lineage_to_production_run_corrections.php');
    expect(fn () => $lineage->down())->toThrow(RuntimeException::class)
        ->and(DB::table('production_run_corrections')->where('id', $id)->value('receipt_date_evidence'))->toBe('SYNTHETIC independent source evidence');
});
