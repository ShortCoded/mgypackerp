<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionMaterialSubstitutionService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

/** @return array<string, mixed> */
function materialSubstitutionFixture(string $method = InventoryCostPolicy::MovingAverage, bool $asBatch = false): array
{
    test()->travelTo('2026-09-28 10:00:00');
    $f = manufacturingInventoryFixture('-SUBSTITUTION', true);
    $f['branch']->update(['type' => Branch::TypeFactory]);
    $f['approver'] = closureSyntheticUser();
    foreach (['production.runs.view', 'production.runs.issue', 'production.runs.correct', 'production.runs.correct_approve', 'products.edit', 'production.orders.release'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
        $f['approver']->givePermissionTo($permission);
    }
    test()->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
    $f['alternate'] = $f['raw']->replicate();
    $f['alternate']->fill(['doc_number' => (int) Product::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-TECHNICALLY-APPROVED-RESIN-'.$f['company']->id, 'name' => 'SYNTHETIC technically compatible approved resin'])->save();
    $opening = InventoryTransaction::query()->where('product_id', $f['raw']->id)->firstOrFail()->replicate();
    $opening->fill(['posting_key' => 'synthetic-substitution-stock-'.$f['company']->id, 'product_id' => $f['alternate']->id,
        'quantity_in' => '100', 'unit_cost' => '3', 'total_cost' => '300', 'source_doc_num' => 'SYNTHETIC-SUBSTITUTION-OPEN'])->save();
    if ($method !== InventoryCostPolicy::MovingAverage) {
        InventoryCostPolicy::query()->create(['company_id' => $f['company']->id, 'scope_key' => 'company:'.$f['company']->id,
            'method' => $method, 'effective_from' => '2026-01-01', 'reason' => 'SYNTHETIC layer-cost fixture', 'created_by' => $f['user']->id]);
        foreach (InventoryTransaction::query()->where('company_id', $f['company']->id)->get() as $receipt) {
            $receipt->update(['cost_method' => $method]);
            app(InventoryLayerService::class)->recordInbound($receipt);
        }
    }
    $f += manufacturingIntegrityRun($f, asBatch: $asBatch);
    $f['cycle']->reserveRun($f['run'], $f['store']->id);
    $f['issue'] = $f['cycle']->issueMaterials($f['run'], $f['store']->id);
    $f['run'] = $f['cycle']->startRun($f['cycle']->completeSetup($f['cycle']->startSetup($f['run'])));
    $f['requirement'] = $f['run']->requirements()->sole();

    return $f;
}

/** @param array<string, mixed> $f @return array<string, mixed> */
function materialSubstitutionPayload(array $f, string $quantity = '1'): array
{
    return ['_submission_token' => (string) Str::uuid(), 'requirement_public_id' => $f['requirement']->public_id,
        'replacement_product_doc_num' => $f['alternate']->doc_num, 'branch_store_id' => $f['store']->id, 'quantity' => $quantity,
        'reason' => 'SYNTHETIC recording correction with reviewed compatible substitute',
        'fingerprint' => app(ProductionMaterialSubstitutionService::class)->preview($f['run'])['fingerprint']];
}

/** @param array<string, mixed> $f */
function materialSubstitutionPrepare(array $f, string $quantity = '1'): int
{
    $response = test()->postJson(route('admin.production.runs.material-substitutions.store', $f['run']), materialSubstitutionPayload($f, $quantity))->assertOk();

    return $response->json('data.substitution_id');
}

/** @return array<string, mixed> */
function materialSubstitutionApproval(): array
{
    return ['_submission_token' => (string) Str::uuid(), 'recipe_approved' => true,
        'recipe_approval_evidence' => 'SYNTHETIC technical recipe approval: same grade specification, base unit and authorized 1:1 substitution'];
}

/** @param array<string, mixed> $f @return array<string, mixed> */
function materialSubstitutionState(array $f): array
{
    $company = $f['company']->id;

    return ['requirements' => $f['run']->requirements()->orderBy('id')->get()->map->getAttributes()->all(),
        'documents' => DB::table('inventory_documents')->where('company_id', $company)->orderBy('id')->get()->all(),
        'transactions' => DB::table('inventory_transactions')->where('company_id', $company)->orderBy('id')->get()->all(),
        'layers' => DB::table('inventory_receipt_layers')->where('company_id', $company)->orderBy('id')->get()->all(),
        'allocations' => DB::table('inventory_layer_allocations')->whereIn('inventory_receipt_layer_id', DB::table('inventory_receipt_layers')->where('company_id', $company)->select('id'))->orderBy('id')->get()->all(),
        'reservations' => DB::table('inventory_reservations')->where('company_id', $company)->orderBy('id')->get()->all(),
        'journals' => DB::table('journal_entries')->where('company_id', $company)->orderBy('id')->get()->all(),
        'journal_lines' => DB::table('journal_entry_lines')->whereIn('journal_entry_id', DB::table('journal_entries')->where('company_id', $company)->select('id'))->orderBy('id')->get()->all(),
        'proposals' => DB::table('production_material_substitutions')->where('company_id', $company)->orderBy('id')->get()->all()];
}

/** @param array<string, mixed> $f @return array<string, array{quantity:string, value:string}> */
function materialSubstitutionStock(array $f): array
{
    $positions = [];
    foreach (['original' => $f['raw'], 'replacement' => $f['alternate']] as $kind => $product) {
        foreach (['available', 'production_staging'] as $status) {
            $row = InventoryTransaction::query()->where('company_id', $f['company']->id)->where('branch_store_id', $f['store']->id)
                ->where('product_id', $product->id)->where('stock_status', $status)
                ->when($status === 'production_staging', fn ($query) => $query->where('production_run_id', $f['run']->id))
                ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity, coalesce(sum(case when quantity_in > 0 then total_cost else -total_cost end), 0) as value')->first();
            $positions[$kind.'_'.$status] = ['quantity' => bcadd((string) $row->quantity, '0', 8), 'value' => bcadd((string) $row->value, '0', 8)];
        }
    }

    return $positions;
}

test('approved unconsumed substitution returns and issues atomically preserving lineage valuation and original BOM', function (string $quantity, string $status, string $method): void {
    $f = materialSubstitutionFixture($method);
    $f['run']->update(['status' => $status]);
    $beforeRun = $f['run']->fresh()->getAttributes();
    $beforeOrder = $f['order']->fresh()->getAttributes();
    $beforeLine = $f['orderLine']->fresh()->getAttributes();
    $beforeIssue = $f['issue']->fresh()->getAttributes();
    $beforeIssueLines = $f['issue']->lines()->get()->map->getAttributes()->all();
    $beforeStock = materialSubstitutionStock($f);
    $id = materialSubstitutionPrepare($f, $quantity);
    $beforeApproval = materialSubstitutionState($f);
    $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]), materialSubstitutionApproval())
        ->assertUnprocessable()->assertJsonPath('errors.substitution.0', __('production_material_substitution.independent'));
    expect(materialSubstitutionState($f))->toEqual($beforeApproval);
    $this->actingAs($f['approver'])->withSession(manufacturingIntegritySession($f));
    $url = route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]);
    $this->postJson($url, materialSubstitutionApproval())->assertOk()->assertJsonPath('data.status', 'approved');
    $proposal = DB::table('production_material_substitutions')->find($id);
    $original = $f['requirement']->fresh();
    $replacement = ProductionMaterialRequirement::query()->findOrFail($proposal->replacement_requirement_id);
    $returned = InventoryDocument::query()->findOrFail($proposal->return_document_id);
    $issued = InventoryDocument::query()->findOrFail($proposal->issue_document_id);
    expect($original->product_id)->toBe($f['raw']->id)->and($original->issued_quantity)->toBe('2.00000000')
        ->and($original->returned_quantity)->toBe(bcadd($quantity, '0', 8))->and($original->planned_quantity)->toBe(bcsub('2', $quantity, 8))
        ->and($replacement->product_id)->toBe($f['alternate']->id)->and($replacement->issued_quantity)->toBe(bcadd($quantity, '0', 8))
        ->and($replacement->component_snapshot['approved_substitution']['original_requirement_id'])->toBe($original->id)
        ->and($replacement->production_order_line_id)->toBe($original->production_order_line_id)
        ->and($returned->lines()->sole()->source_line_id)->toBe($original->id)
        ->and($returned->lines()->sole()->total_cost)->toBe(bcmul($quantity, '2', 8))
        ->and($issued->lines()->sole()->source_line_id)->toBe($replacement->id)
        ->and($issued->lines()->sole()->total_cost)->toBe(bcmul($quantity, '3', 8))
        ->and($f['run']->fresh()->getAttributes())->toEqual($beforeRun)
        ->and($f['order']->fresh()->getAttributes())->toEqual($beforeOrder)
        ->and($f['orderLine']->fresh()->getAttributes())->toEqual($beforeLine)
        ->and($f['issue']->fresh()->getAttributes())->toEqual($beforeIssue)
        ->and($f['issue']->lines()->get()->map->getAttributes()->all())->toEqual($beforeIssueLines)
        ->and(app(ProductionCostService::class)->runPosition($f['run'])['direct_material_cost'])->toBe(bcadd('4', $quantity, 8));
    foreach ([$returned, $issued] as $document) {
        expect($document->status)->toBe('posted')->and($document->source_document_id)->toBe($f['run']->id)
            ->and($document->production_order_id)->toBe($f['order']->id)->and($document->financial_period_id)->toBe($f['period']->id);
        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $document->journal_entry_id);
        expect(bcsub((string) (clone $lines)->sum('debit_amount'), (string) (clone $lines)->sum('credit_amount'), 4))->toBe('0.0000');
    }
    $afterApproval = materialSubstitutionState($f);
    $afterStock = materialSubstitutionStock($f);
    foreach (['original_available' => ['quantity' => $quantity, 'value' => bcmul($quantity, '2', 8)],
        'original_production_staging' => ['quantity' => bcsub('0', $quantity, 8), 'value' => bcmul($quantity, '-2', 8)],
        'replacement_available' => ['quantity' => bcsub('0', $quantity, 8), 'value' => bcmul($quantity, '-3', 8)],
        'replacement_production_staging' => ['quantity' => $quantity, 'value' => bcmul($quantity, '3', 8)]] as $position => $changes) {
        foreach ($changes as $field => $change) {
            expect(bcsub($afterStock[$position][$field], $beforeStock[$position][$field], 8))->toBe(bcadd($change, '0', 8));
        }
    }
    $this->postJson($url, materialSubstitutionApproval())->assertOk();
    expect(materialSubstitutionState($f))->toEqual($afterApproval);
    $this->get(route('admin.production.runs.material-substitutions.index', $f['run']))->assertOk()
        ->assertSee($returned->doc_num)->assertSee($issued->doc_num)->assertSee($proposal->recipe_approval_evidence);
    expect(DB::table('activity_log')->where('event', 'production.material_substitution.approved')->where('subject_id', $f['run']->id)->exists())->toBeTrue();
    $evidencePath = getenv('MGYPACK_SUBSTITUTION_EVIDENCE');
    if (DB::getDriverName() === 'pgsql' && $evidencePath === '/tmp/mgypack-substitution-execution-20261004-e9f52b.json') {
        $evidence = is_file($evidencePath) ? json_decode(file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR) : [];
        $evidence[$quantity.'-'.$method] = ['scenario' => 'SYNTHETIC approved technical substitution; no real replacement technical suitability asserted',
            'proposal_id' => $id, 'original_product' => $f['raw']->doc_num, 'replacement_product' => $f['alternate']->doc_num,
            'before_stock' => $beforeStock, 'after_stock' => $afterStock,
            'execution' => json_decode($proposal->execution_snapshot, true, 512, JSON_THROW_ON_ERROR)];
        file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        chmod($evidencePath, 0600);
    }
})->with([['1', ProductionRun::StatusRunning, InventoryCostPolicy::MovingAverage], ['2', ProductionRun::StatusHeld, InventoryCostPolicy::MovingAverage], ['1.00000001', ProductionRun::StatusRunning, InventoryCostPolicy::Fifo]]);

test('substitution shows scoped paginated product selection and independent recipe approval in both locales', function (string $locale): void {
    $f = materialSubstitutionFixture();
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $index = route('admin.production.runs.material-substitutions.index', $f['run']);
    $this->get(route('admin.production.runs.show', $f['run']))->assertOk()->assertSee($index, false);
    $this->get($index)->assertOk()->assertSee(__('production_material_substitution.title'))
        ->assertSee(__('production_material_substitution.cost_current').': 4')
        ->assertSee('data-placeholder="'.__('production_material_substitution.select_replacement').'"', false)
        ->assertSee('fingerprint', false)->assertSee('_submission_token', false);
    $lookup = route('admin.production.runs.material-substitutions.products', [$f['run'], 'requirement' => $f['requirement']->public_id]);
    $this->getJson($lookup)->assertOk()->assertJsonPath('results.0.id', $f['alternate']->doc_num)->assertJsonCount(1, 'results');
    $id = materialSubstitutionPrepare($f);
    $this->actingAs($f['approver']);
    $this->get($index)->assertOk()->assertSee('recipe_approved', false)->assertSee('recipe_approval_evidence', false);
    $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]), ['_submission_token' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors(['recipe_approved', 'recipe_approval_evidence'])
        ->assertJsonPath('errors.recipe_approved.0', __('validation.required', ['attribute' => __('production_material_substitution.technical_approval')]))
        ->assertJsonPath('errors.recipe_approval_evidence.0', __('validation.required', ['attribute' => __('production_material_substitution.evidence')]));
    $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]), materialSubstitutionApproval())->assertOk();
    $this->get($index)->assertOk()
        ->assertSee(__('production_material_substitution.cost_current').': 5')
        ->assertSee(__('production_material_substitution.cost_before').': 4')
        ->assertSee(__('production_material_substitution.cost_after').': 5');
})->with(['ar', 'en']);

test('substitution denies missing prepare or independent approval permissions', function (string $permission, string $action): void {
    $f = materialSubstitutionFixture();
    if ($action === 'prepare') {
        $payload = materialSubstitutionPayload($f);
        $f['user']->revokePermissionTo($permission);
        $this->postJson(route('admin.production.runs.material-substitutions.store', $f['run']), $payload)->assertForbidden();
    } else {
        $id = materialSubstitutionPrepare($f);
        $f['approver']->revokePermissionTo($permission);
        $this->actingAs($f['approver']);
        $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]), materialSubstitutionApproval())->assertForbidden();
    }
    expect($f['run']->requirements()->count())->toBe(1)->and($f['requirement']->fresh()->returned_quantity)->toBe('0.00000000');
})->with([['production.runs.correct', 'prepare'], ['production.runs.issue', 'prepare'], ['production.runs.correct_approve', 'approve'], ['products.edit', 'approve'], ['production.runs.issue', 'approve']]);

test('substitution rejects invalid quantities material compatibility and source state without writes', function (string $case): void {
    $f = materialSubstitutionFixture();
    $payload = materialSubstitutionPayload($f);
    match ($case) {
        'consumed' => $f['requirement']->update(['consumed_quantity' => '0.00000001']),
        'waste' => $f['requirement']->update(['waste_quantity' => '0.00000001']),
        'received' => $f['run']->update(['received_base_quantity' => '0.00000001']),
        'completed' => $f['run']->update(['status' => ProductionRun::StatusCompleted]),
        'closed' => $f['period']->update(['is_closed' => true]),
        'wrong_unit' => $f['alternate']->update(['item_unit_id' => null]),
        'wrong_class' => $f['alternate']->update(['item_classification' => Product::ClassificationFinishedProduct]),
        'serial' => $payload['replacement_product_doc_num'] = Product::query()->create(['company_id' => $f['company']->id,
            'doc_number' => (int) Product::withTrashed()->max('doc_number') + 1, 'doc_num' => 'SYNTHETIC-SERIAL-SUBSTITUTION',
            'name' => 'SYNTHETIC serial raw material', 'status' => 'active', 'item_unit_id' => $f['unit']->id,
            'item_classification' => Product::ClassificationRawMaterial, 'tracks_serials' => true])->doc_num,
        'expiry' => $f['alternate']->update(['tracks_expiry' => true]),
        'excess' => $payload['quantity'] = '2.00000001',
        'precision' => $payload['quantity'] = '0.000000001',
        'zero' => $payload['quantity'] = '0',
    };
    $before = materialSubstitutionState($f);
    $this->postJson(route('admin.production.runs.material-substitutions.store', $f['run']), $payload)->assertUnprocessable();
    expect(materialSubstitutionState($f))->toEqual($before);
})->with(['consumed', 'waste', 'received', 'completed', 'closed', 'wrong_unit', 'wrong_class', 'serial', 'expiry', 'excess', 'precision', 'zero']);

test('substitution detects source replacement stock and sealed proposal changes at approval', function (string $case): void {
    $f = materialSubstitutionFixture();
    $id = materialSubstitutionPrepare($f);
    match ($case) {
        'source' => $f['requirement']->update(['returned_quantity' => '0.1']),
        'stock' => InventoryTransaction::query()->where('product_id', $f['alternate']->id)->update(['total_cost' => '301']),
        'product' => $f['alternate']->update(['name' => 'SYNTHETIC changed technical specification']),
        'original_product' => $f['raw']->update(['name' => 'SYNTHETIC changed original specification']),
        'policy' => InventoryCostPolicy::query()->create(['company_id' => $f['company']->id, 'scope_key' => 'company:'.$f['company']->id,
            'method' => InventoryCostPolicy::Fifo, 'effective_from' => '2026-01-01', 'reason' => 'SYNTHETIC changed policy', 'created_by' => $f['user']->id]),
        'proposal' => DB::table('production_material_substitutions')->where('id', $id)->update(['quantity' => '2']),
        'day' => $this->travelTo('2026-09-29 10:00:00'),
    };
    if ($case === 'day') {
        $this->flushSession();
        $this->withSession(manufacturingIntegritySession($f));
    }
    $this->actingAs($f['approver']);
    $before = materialSubstitutionState($f);
    $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]), materialSubstitutionApproval())->assertUnprocessable()
        ->assertJsonPath('errors.substitution.0', __('production_material_substitution.stale'));
    expect(materialSubstitutionState($f))->toEqual($before);
})->with(['source', 'stock', 'product', 'original_product', 'policy', 'proposal', 'day']);

test('recorded output and shared batches remain outside material substitution', function (string $case): void {
    $f = materialSubstitutionFixture(asBatch: $case === 'batch');
    if ($case === 'output') {
        $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '0.1']);
    }
    $payload = materialSubstitutionPayload($f);
    $before = materialSubstitutionState($f);
    $this->postJson(route('admin.production.runs.material-substitutions.store', $f['run']), $payload)->assertUnprocessable()
        ->assertJsonPath('errors.substitution.0', __('production_material_substitution.'.($case === 'batch' ? 'active' : 'dependencies')));
    expect(materialSubstitutionState($f))->toEqual($before);
})->with(['output', 'batch']);

test('failed replacement reservation rolls back original return GL layers requirements and proposal state', function (): void {
    $f = materialSubstitutionFixture();
    InventoryTransaction::query()->where('product_id', $f['alternate']->id)->update(['quantity_in' => '0', 'total_cost' => '0']);
    $id = materialSubstitutionPrepare($f);
    $this->actingAs($f['approver']);
    $before = materialSubstitutionState($f);
    $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]), materialSubstitutionApproval())->assertUnprocessable();
    expect(materialSubstitutionState($f))->toEqual($before);
});

test('a failure after replacement posting rolls back both linked movements and their journals', function (): void {
    $f = materialSubstitutionFixture(InventoryCostPolicy::Fifo);
    $id = materialSubstitutionPrepare($f);
    $cycle = $f['cycle'];
    $mock = Mockery::mock(ProductionCycleService::class)->makePartial();
    $mock->shouldReceive('returnMaterials')->andReturnUsing(fn (...$arguments) => $cycle->returnMaterials(...$arguments));
    $mock->shouldReceive('issueMaterials')->andReturnUsing(function (...$arguments) use ($cycle): never {
        $cycle->issueMaterials(...$arguments);
        throw new DomainException('SYNTHETIC failure after replacement GL posting');
    });
    app()->instance(ProductionCycleService::class, $mock);
    $this->actingAs($f['approver']);
    $before = materialSubstitutionState($f);
    $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]), materialSubstitutionApproval())->assertUnprocessable();
    expect(materialSubstitutionState($f))->toEqual($before);
});

test('native material accounting continues with both retained and approved replacement requirements', function (): void {
    $f = materialSubstitutionFixture();
    $id = materialSubstitutionPrepare($f);
    $this->actingAs($f['approver']);
    $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $id]), materialSubstitutionApproval())->assertOk();
    $proposal = DB::table('production_material_substitutions')->find($id);
    $documents = $f['cycle']->accountMaterials($f['run'], $f['store']->id, [
        $f['requirement']->id => ['consumed_quantity' => '1', 'waste_quantity' => '0'],
        $proposal->replacement_requirement_id => ['consumed_quantity' => '1', 'waste_quantity' => '0'],
    ]);
    expect($documents['consumption']->lines()->count())->toBe(2)
        ->and(bcadd((string) $documents['consumption']->lines()->sum('total_cost'), '0', 8))->toBe('5.00000000')
        ->and($f['requirement']->fresh()->consumed_quantity)->toBe('1.00000000')
        ->and(ProductionMaterialRequirement::query()->findOrFail($proposal->replacement_requirement_id)->consumed_quantity)->toBe('1.00000000');
});

test('rejection frees a pending substitution and original scope is required for every operation', function (): void {
    $f = materialSubstitutionFixture();
    $payload = materialSubstitutionPayload($f);
    $url = route('admin.production.runs.material-substitutions.store', $f['run']);
    $first = $this->postJson($url, $payload)->assertOk()->json('data.substitution_id');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.substitution_id', $first);
    $payload['_submission_token'] = (string) Str::uuid();
    $payload['quantity'] = '2';
    $this->postJson($url, $payload)->assertUnprocessable();
    $this->actingAs($f['approver']);
    $this->postJson(route('admin.production.runs.material-substitutions.reject', [$f['run'], $first]))->assertOk();
    $this->actingAs($f['user']);
    $second = $this->postJson($url, $payload)->assertOk()->json('data.substitution_id');
    expect($second)->not->toBe($first);
    $before = materialSubstitutionState($f);
    $other = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) Branch::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-SUBSTITUTION-OTHER-BRANCH', 'name' => 'SYNTHETIC other branch', 'type' => Branch::TypeFactory, 'status' => 'active']);
    $session = manufacturingIntegritySession($f);
    $session[OperatingContextService::BranchIdKey] = $other->id;
    $session[OperatingContextService::BranchDocNumKey] = $other->doc_num;
    $this->actingAs($f['approver'])->withSession($session);
    $this->get(route('admin.production.runs.material-substitutions.index', $f['run']))->assertNotFound();
    $this->postJson(route('admin.production.runs.material-substitutions.approve', [$f['run'], $second]), materialSubstitutionApproval())->assertNotFound();
    expect(materialSubstitutionState($f))->toEqual($before);
});

test('inconsistent requirement parent lineage is rejected before any material movement', function (): void {
    $f = materialSubstitutionFixture();
    $other = $f['cycle']->createMakeToStockOrder(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'branch_id' => $f['branch']->id], [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1']]);
    $f['requirement']->update(['production_order_id' => $other->id]);
    $payload = materialSubstitutionPayload($f);
    $before = materialSubstitutionState($f);
    $this->postJson(route('admin.production.runs.material-substitutions.store', $f['run']), $payload)->assertUnprocessable()
        ->assertJsonPath('errors.substitution.0', __('production_material_substitution.lineage'));
    expect(materialSubstitutionState($f))->toEqual($before);
});
