<?php

use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionOutputEvidenceService;
use Modules\Production\Services\ProductionQualityQuantityService;
use Modules\Production\Services\ProductionQualityWorkflowService;

require_once __DIR__.'/ProductionPartialOutputEvidenceSupport.php';

test('active partial output retains unused staging quantity and cost through repeated quality and receipt cycles', function (): void {
    $f = partialOutputFixture();
    foreach (['1', '2', '7'] as $quantity) {
        $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => $quantity]);
        partialOutputApprove($f, $quantity);
        $receipt = $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, $quantity);
        expect($f['run']->fresh()->status)->toBe(ProductionRun::StatusRunning)
            ->and($receipt->transactions->sole()->total_cost)->toBe(bcmul($quantity, '20', 8));
        $position = app(ProductionCostService::class)->runPosition($f['run']->fresh());
        $expectedResidual = bcsub('200', bcmul((string) $f['run']->fresh()->received_base_quantity, '20', 8), 8);
        expect($position['wip'])->toBe($expectedResidual);
    }
    $run = $f['cycle']->completeRun($f['run']->fresh());
    expect($run->status)->toBe(ProductionRun::StatusCompleted)->and($run->received_base_quantity)->toBe('10.00000000')
        ->and($f['requirement']->fresh()->consumed_quantity)->toBe('20.00000000')
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000');
    expect(bccomp((string) InventoryTransaction::query()->where('production_run_id', $run->id)->where('stock_status', 'production_staging')->selectRaw('sum(quantity_in-quantity_out) as quantity')->first()->quantity, '0', 8))->toBe(0);
});

test('quality accepted quantity bounds partial receipt and refuses reusing an inspected output batch', function (): void {
    $f = partialOutputFixture();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '2']);
    partialOutputApprove($f, '2', '1');
    expect(app(ProductionQualityQuantityService::class)->availableQuantity($f['run']->fresh()))->toBe('1.00000000');
    expect(fn () => $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '2'))->toThrow(DomainException::class);
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '1');
    expect(fn () => partialOutputApprove($f, '1'))->toThrow(DomainException::class);
    expect(fn () => $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '1'))->toThrow(DomainException::class);
    expect($f['run']->fresh()->received_base_quantity)->toBe('1.00000000');
});

test('progress and receipt retries are idempotent and old manual consumption cannot sweep residual staging', function (): void {
    $f = partialOutputFixture();
    $payload = ['_submission_token' => (string) Str::uuid(), 'good_base_quantity' => '1'];
    $url = route('admin.production.runs.progress', $f['run']);
    $first = $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.entry', $first->json('data.entry'));
    expect($f['run']->fresh()->good_base_quantity)->toBe('1.00000000')->and($f['requirement']->fresh()->consumed_quantity)->toBe('2.00000000');
    expect(fn () => $f['cycle']->accountMaterials($f['run']->fresh(), $f['store']->id, [$f['requirement']->id => ['consumed_quantity' => '18', 'waste_quantity' => '0']]))->toThrow(DomainException::class);
    partialOutputApprove($f, '1');
    $payload = ['_submission_token' => (string) Str::uuid(), 'base_quantity' => '1', 'branch_store_id' => $f['store']->id];
    $url = route('admin.production.runs.receive', $f['run']);
    $first = $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.doc_num', $first->json('data.doc_num'));
    expect($f['run']->fresh()->received_base_quantity)->toBe('1.00000000')->and(app(ProductionCostService::class)->runPosition($f['run']->fresh())['wip'])->toBe('180.00000000');
});

test('measured material and timestamped waste require evidence and remain atomic on overdraw', function (): void {
    $f = partialOutputFixture('measured_material');
    $row = ['requirement_public_id' => $f['requirement']->public_id, 'measured_quantity' => '2'];
    expect(fn () => $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '1']))->toThrow(DomainException::class);
    $entry = $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '1', 'material_evidence' => [$row]]);
    expect($entry->material_evidence[0]['consumed_quantity'])->toBe('2.00000000');
    $before = InventoryDocument::query()->count();
    expect(fn () => $f['cycle']->recordProgress($f['run']->fresh(), ['notes' => 'waste', 'material_evidence' => [[...$row, 'measured_quantity' => '0', 'waste_quantity' => '19', 'waste_classification' => 'roll_trim', 'notes' => 'measured roll trim']]]))->toThrow(DomainException::class);
    expect(InventoryDocument::query()->count())->toBe($before);
    $f['run']->update(['status' => 'held']);
    expect(fn () => $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '1', 'material_evidence' => [$row]]))->toThrow(DomainException::class);
    $f['cycle']->recordProgress($f['run']->fresh(), ['notes' => 'Timestamped stop diagnosis without output']);
    $entry = $f['cycle']->recordProgress($f['run']->fresh(), ['notes' => 'measured waste during operation', 'material_evidence' => [[...$row, 'measured_quantity' => '0', 'waste_quantity' => '1', 'waste_classification' => 'roll_trim', 'notes' => 'actual cut trim']]]);
    expect($entry->recorded_at)->not->toBeNull()->and($f['requirement']->fresh()->waste_quantity)->toBe('1.00000000')
        ->and(app(ProductionCostService::class)->runPosition($f['run']->fresh())['waste'])->toBe('10.00000000');
});

test('native quality reinspection releases the remaining accepted quantity without duplicating output', function (): void {
    $f = partialOutputFixture();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '2']);
    $quality = app(ProductionQualityWorkflowService::class);
    $draft = $quality->create($f['run']->fresh(), ['quality_inspection_type_id' => $f['qualityType']->id, 'affected_base_quantity' => '2']);
    $started = $quality->start($quality->receive($draft));
    $approved = $quality->submit($started, ['result' => 'passed', 'disposition' => 'release', 'affected_base_quantity' => '2', 'accepted_base_quantity' => '1']);
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '1');
    $closed = $quality->close($approved);
    $second = $quality->reinspect($closed);
    $second = $quality->start($quality->receive($second));
    expect(fn () => $quality->submit($second, ['result' => 'passed', 'disposition' => 'release', 'affected_base_quantity' => '2', 'accepted_base_quantity' => '0']))->toThrow(DomainException::class);
    $quality->submit($second, ['result' => 'passed', 'disposition' => 'release', 'affected_base_quantity' => '2', 'accepted_base_quantity' => '2']);
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '1');
    expect($f['run']->fresh()->good_base_quantity)->toBe('2.00000000')->and($f['run']->fresh()->received_base_quantity)->toBe('2.00000000');
});

test('an output inspection draft reserves quantity and deletion and restore cannot overbook it', function (): void {
    $f = partialOutputFixture();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '1']);
    $quality = app(ProductionQualityWorkflowService::class);
    $draft = $quality->create($f['run']->fresh(), ['quality_inspection_type_id' => $f['qualityType']->id, 'affected_base_quantity' => '1']);
    expect(fn () => partialOutputApprove($f, '1'))->toThrow(DomainException::class);
    $quality->deleteDraft($draft);
    partialOutputApprove($f, '1');
    expect(fn () => $quality->restoreDraft($draft))->toThrow(DomainException::class);
    expect(ProductionQualityInspection::withTrashed()->findOrFail($draft->id)->trashed())->toBeTrue();
});

test('explicit common checklist mapping preserves physical manufacturing and quantity quality gates', function (): void {
    $f = partialOutputFixture(factoryWorkflow: true);
    $stages = $f['order']->orderStageSnapshots->sortBy('sequence')->values();
    expect($f['run']->production_order_stage_snapshot_id)->toBe($stages[1]->id)
        ->and($stages[0]->fresh()->status)->toBe('completed')->and($stages[2]->fresh()->status)->toBe('pending');
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '1']);
    expect(fn () => $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '1'))->toThrow(DomainException::class);
    partialOutputApprove($f, '1');
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '1');
    expect($f['run']->fresh()->status)->toBe('running')->and($stages[2]->fresh()->status)->toBe('in_progress')
        ->and($stages[3]->fresh()->status)->toBe('in_progress');
    expect(fn () => app(ProductionOutputEvidenceService::class)->policy($f['run']->fresh(),
        [['requirement_public_id' => $f['requirement']->public_id, 'basis' => 'output_components']], 'factory_workflow', []))->toThrow(DomainException::class);
});
