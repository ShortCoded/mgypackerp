<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderStageEvent;
use Modules\Production\Models\ProductProductionStage;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionRoutingService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

/** @return array<string, mixed> */
function unexecutedProductionFixture(): array
{
    test()->travelTo('2026-09-28 10:00:00');
    $f = manufacturingInventoryFixture('-UNEXECUTED', true);
    $f['branch']->update(['type' => Branch::TypeFactory]);
    foreach (['production.orders.view', 'production.orders.edit', 'production.orders.release', 'production.orders.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    test()->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
    $f['alternate'] = $f['finished']->replicate();
    $f['alternate']->fill(['doc_number' => (int) Product::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-PRODUCTION-ALTERNATE-'.$f['company']->id, 'name' => 'SYNTHETIC alternate finished product'])->save();
    ProductComponent::query()->create(['company_id' => $f['company']->id, 'product_id' => $f['alternate']->id,
        'component_product_id' => $f['raw']->id, 'unit_id' => $f['unit']->id,
        'calculation_method' => ProductComponent::CalculationDirect, 'quantity' => '3']);
    $f['header'] = ['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'branch_id' => $f['branch']->id, 'production_order_date' => '2026-09-28', 'source_type' => 'make_to_stock', 'priority' => 'normal'];
    $f['order'] = app(ProductionCycleService::class)->createMakeToStockOrder($f['header'], [
        ['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '2'],
    ]);

    return $f;
}

/** @param array<string, mixed> $f @return array<string, mixed> */
function unexecutedProductionPayload(array $f): array
{
    return ['source_type' => $f['order']->source_type, 'source_doc_num' => ($f['source'] ?? null)?->doc_num,
        'production_order_date' => '2026-09-28', 'priority' => 'normal',
        'amendment_reason' => 'SYNTHETIC reviewed pre-execution product correction',
        'amendment_token' => $f['order']->fresh()->amendmentToken(),
        'lines' => [['source_line_reference' => 'product:'.$f['alternate']->doc_num, 'quantity' => '3.00000001']]];
}

/** @param array<string, mixed> $f @return array<string, mixed> */
function unexecutedProductionSource(array $f, string $type): array
{
    $customer = Customer::query()->create(['company_id' => $f['company']->id, 'doc_number' => 99401,
        'doc_num' => 'SYNTHETIC-PRODUCTION-CUSTOMER-'.$f['company']->id, 'name' => 'SYNTHETIC source customer', 'status' => 'active']);
    $sourceClass = $type === 'sales_order' ? SalesOrder::class : CustomerInvoice::class;
    $source = $sourceClass::query()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'branch_id' => $f['branch']->id, 'branch_store_id' => $f['store']->id, 'doc_number' => 99401,
        'doc_num' => 'SYNTHETIC-PRODUCTION-SOURCE-'.$f['company']->id, 'customer_id' => $customer->id,
        'currency_id' => Currency::query()->where('company_id', $f['company']->id)->value('id'),
        $type === 'sales_order' ? 'order_date' : 'invoice_date' => '2026-09-28',
        'status' => $type === 'sales_order' ? SalesOrder::StatusApproved : CustomerInvoice::StatusPosted,
        ...($type === 'sales_order' ? ['credit_status' => 'approved', 'expected_delivery_date' => '2026-09-29'] : ['document_type' => CustomerInvoice::TypeInvoice]),
        'subtotal_amount' => '150', 'total_amount' => '150']);
    $sourceLines = collect([$f['finished'], $f['alternate']])->map(fn (Product $product, int $i) => $source->lines()->create([
        'line_number' => $i + 1, 'product_id' => $product->id, 'unit_id' => $f['unit']->id,
        'description' => $product->name, 'quantity' => '10', 'base_quantity' => '10', 'conversion_factor' => '1',
        'unit_price' => '5', 'line_total' => '50',
        ...($type === 'sales_order' ? ['product_classification_snapshot' => Product::ClassificationFinishedProduct,
            'specifications' => ['SYNTHETIC-color' => 'blue']] : ['is_service' => false]),
    ]));
    $header = [...$f['header'], 'source_type' => $type, 'source_id' => $source->id,
        'sales_order_id' => $type === 'sales_order' ? $source->id : null, 'customer_id' => $customer->id];
    $order = app(ProductionCycleService::class)->createMakeToStockOrder($header, $sourceLines->map(fn ($line) => [
        'product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'quantity' => '5',
        $type === 'sales_order' ? 'sales_order_line_id' : 'customer_invoice_line_id' => $line->id,
    ])->all());

    return [...$f, 'source' => $source, 'sourceLines' => $sourceLines, 'header' => $header, 'order' => $order];
}

test('pre-execution production correction replaces adds and removes lines with fresh BOM release', function (string $status): void {
    $f = unexecutedProductionFixture();
    $cycle = app(ProductionCycleService::class);
    if ($status === ProductionOrder::StatusReleased) {
        $f['order'] = $cycle->releaseOrder($f['order']);
    } else {
        $f['order']->update(['status' => $status]);
    }
    $before = $f['order']->lines()->get()->toArray();
    $ledger = DB::table('inventory_transactions')->where('company_id', $f['company']->id)->count();
    $journals = DB::table('journal_entries')->where('company_id', $f['company']->id)->count();
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get(route('admin.production.work-orders.edit', $f['order']))->assertOk()
            ->assertSee(__('production_execution.amendment.help'))->assertSee('amendment_token', false)
            ->assertSee('data-allow-source-amendment="1"', false);
    }
    $payload = unexecutedProductionPayload($f);
    $payload['lines'][] = ['source_line_reference' => 'product:'.$f['finished']->doc_num, 'quantity' => '1'];
    $url = route('admin.production.work-orders.update', $f['order']);
    $this->putJson($url, $payload)->assertOk();
    $updated = $f['order']->fresh('lines');
    expect($updated->status)->toBe(ProductionOrder::StatusDraft)->and($updated->released_at)->toBeNull()
        ->and($updated->lines->pluck('product_id')->all())->toBe([$f['alternate']->id, $f['finished']->id])
        ->and($updated->lines->pluck('base_quantity')->all())->toBe(['3.00000001', '1.00000000'])
        ->and($updated->lines->pluck('bom_snapshot')->filter())->toBeEmpty()
        ->and(DB::table('inventory_transactions')->where('company_id', $f['company']->id)->count())->toBe($ledger)
        ->and(DB::table('journal_entries')->where('company_id', $f['company']->id)->count())->toBe($journals);
    $audit = json_decode(DB::table('activity_log')->where('event', 'production.order.amended')->where('subject_id', $updated->id)->value('properties'), true);
    expect($audit['before']['lines'])->toBe($before)->and($audit['reason'])->toBe($payload['amendment_reason'])
        ->and($audit['after']['lines'])->toHaveCount(2);
    $this->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('production_order');
    $this->postJson(route('admin.production.work-orders.release', $updated))->assertOk();
    $released = $updated->fresh('lines');
    expect($released->status)->toBe(ProductionOrder::StatusReleased)
        ->and($released->lines->first()->bom_snapshot['finished_product_id'])->toBe($f['alternate']->id)
        ->and($released->lines->first()->bom_snapshot['components'][0]['quantity'])->toBe('3.00000000')
        ->and($released->lines->last()->bom_snapshot['finished_product_id'])->toBe($f['finished']->id);
})->with([ProductionOrder::StatusDraft, ProductionOrder::StatusPlanned, ProductionOrder::StatusReleased]);

test('linked unexecuted production correction preserves lineage and releases removed demand', function (string $type): void {
    $f = unexecutedProductionSource(unexecutedProductionFixture(), $type);
    $before = $f['source']->fresh()->getAttributes();
    $payload = unexecutedProductionPayload($f);
    $prefix = $type === 'sales_order' ? 'sales_order_line:' : 'customer_invoice_line:';
    $payload['lines'] = [['source_line_reference' => $prefix.$f['sourceLines']->last()->public_id, 'quantity' => '4']];
    $url = route('admin.production.work-orders.update', $f['order']);
    $this->putJson($url, $payload)->assertOk();
    $line = $f['order']->fresh('lines')->lines->sole();
    expect($line->product_id)->toBe($f['alternate']->id)->and($line->base_quantity)->toBe('4.00000000')
        ->and($f['source']->fresh()->getAttributes())->toBe($before)
        ->and($line->{$type === 'sales_order' ? 'sales_order_line_id' : 'customer_invoice_line_id'})->toBe($f['sourceLines']->last()->id);
    if ($type === 'sales_order') {
        expect($f['sourceLines']->first()->fresh()->production_requested_base_quantity)->toBe('0.00000000')
            ->and($f['sourceLines']->last()->fresh()->production_requested_base_quantity)->toBe('4.00000000')
            ->and($line->specifications)->toBe(['SYNTHETIC-color' => 'blue']);
    }
    $payload['amendment_token'] = $f['order']->fresh()->amendmentToken();
    $payload['lines'][] = ['source_line_reference' => $prefix.$f['sourceLines']->first()->public_id, 'quantity' => '2'];
    $this->putJson($url, $payload)->assertOk();
    expect($f['order']->fresh()->lines()->count())->toBe(2);
    $payload['amendment_token'] = $f['order']->fresh()->amendmentToken();
    $payload['lines'][0]['quantity'] = '10.00000001';
    $this->putJson($url, $payload)->assertUnprocessable();
    expect($f['order']->fresh('lines')->lines->first()->base_quantity)->toBe('4.00000000');
    $payload['source_type'] = 'make_to_stock';
    $payload['lines'] = [['source_line_reference' => 'product:'.$f['alternate']->doc_num, 'quantity' => '1']];
    $this->putJson($url, $payload)->assertUnprocessable();
    expect($f['order']->fresh()->source_id)->toBe($f['source']->id);
})->with(['sales_order', 'customer_invoice']);

test('correction lookup offers its own fully planned source lines without releasing another order demand', function (string $type): void {
    $f = unexecutedProductionSource(unexecutedProductionFixture(), $type);
    app(ProductionCycleService::class)->createMakeToStockOrder($f['header'], $f['sourceLines']->map(fn ($line) => [
        'product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'quantity' => '5',
        $type === 'sales_order' ? 'sales_order_line_id' : 'customer_invoice_line_id' => $line->id,
    ])->all());
    $params = ['source_type' => $type, 'source_doc_num' => $f['source']->doc_num, 'all' => 1];
    $this->getJson(route('admin.production.work-orders.select2.products', $params))->assertOk()->assertJsonCount(0, 'results');
    $params['production_order_doc_num'] = $f['order']->doc_num;
    $lookup = route('admin.production.work-orders.select2.products', $params);
    $this->getJson($lookup)->assertOk()->assertJsonCount(2, 'results')->assertJsonPath('results.0.required_quantity', '5.00000000');
    $payload = unexecutedProductionPayload($f);
    $payload['lines'] = [['source_line_reference' => ($type === 'sales_order' ? 'sales_order_line:' : 'customer_invoice_line:').$f['sourceLines']->first()->public_id, 'quantity' => '5.00000001']];
    $this->putJson(route('admin.production.work-orders.update', $f['order']), $payload)->assertUnprocessable();
    $f['user']->revokePermissionTo('production.orders.edit');
    $this->getJson($lookup)->assertForbidden();
    expect($f['order']->fresh('lines')->lines->pluck('base_quantity')->all())->toBe(['5.00000000', '5.00000000']);
})->with(['sales_order', 'customer_invoice']);

test('production correction preserves historical sales conversion and respects other planned demand', function (): void {
    $f = unexecutedProductionSource(unexecutedProductionFixture(), 'sales_order');
    $line = $f['sourceLines']->first();
    $carton = ItemUnit::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) ItemUnit::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-PRODUCTION-CARTON-'.$f['company']->id, 'name' => 'Carton', 'status' => 'active']);
    $f['finished']->update(['equivalent_unit_id' => $carton->id, 'equivalent_value' => '12']);
    $line->update(['unit_id' => $carton->id, 'conversion_factor' => '10', 'quantity' => '1', 'base_quantity' => '10', 'production_requested_quantity' => '0.5']);
    $f['order']->lines()->first()->update(['unit_id' => $carton->id, 'conversion_factor' => '10', 'quantity' => '0.5']);
    $payload = unexecutedProductionPayload($f);
    $payload['lines'] = [['source_line_reference' => 'sales_order_line:'.$line->public_id, 'quantity' => '0.3']];
    $this->putJson(route('admin.production.work-orders.update', $f['order']), $payload)->assertOk();
    $new = $f['order']->fresh('lines')->lines->sole();
    expect($new->unit_id)->toBe($carton->id)->and($new->conversion_factor)->toBe('10.00000000')->and($new->base_quantity)->toBe('3.00000000')
        ->and($line->fresh()->production_requested_quantity)->toBe('0.30000000')->and($line->fresh()->production_requested_base_quantity)->toBe('3.00000000');
    app(ProductionCycleService::class)->createMakeToStockOrder($f['header'], [['product_id' => $f['finished']->id,
        'unit_id' => $f['unit']->id, 'sales_order_line_id' => $line->id, 'quantity' => '6']]);
    $payload['amendment_token'] = $f['order']->fresh()->amendmentToken();
    $payload['lines'][0]['quantity'] = '0.40000001';
    $this->putJson(route('admin.production.work-orders.update', $f['order']), $payload)->assertUnprocessable();
    expect($new->fresh()->base_quantity)->toBe('3.00000000')->and($line->fresh()->production_requested_base_quantity)->toBe('9.00000000');
});

test('standalone correction retains configured selected units and rebuilds reviewed product and order routes', function (): void {
    $f = unexecutedProductionFixture();
    $carton = ItemUnit::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) ItemUnit::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-MTS-CARTON-'.$f['company']->id, 'name' => 'Carton', 'status' => 'active']);
    $f['finished']->update(['equivalent_unit_id' => $carton->id, 'equivalent_value' => '0.1']);
    $f['order']->lines()->sole()->update(['unit_id' => $carton->id, 'conversion_factor' => '10', 'base_quantity' => '20']);
    $routing = app(ProductionRoutingService::class);
    $stage = $routing->createStage(['name' => 'SYNTHETIC reviewed route', 'output_type' => 'finished_product', 'status' => 'active']);
    $orderStage = $routing->createStage(['name' => 'SYNTHETIC common order route', 'output_type' => 'finished_product', 'status' => 'active']);
    $routing->replaceProductRoute($f['alternate'], [['production_stage_id' => $stage->id]]);
    $routing->snapshotOrderRoute($f['order'], [$orderStage->public_id]);
    $productStage = ProductProductionStage::query()->where('product_id', $f['alternate']->id)->sole();
    $payload = unexecutedProductionPayload($f);
    $payload['order_stage_public_ids'] = [$orderStage->public_id];
    $payload['lines'] = [['source_line_reference' => 'product:'.$f['finished']->doc_num, 'quantity' => '3'],
        ['source_line_reference' => 'product:'.$f['alternate']->doc_num, 'quantity' => '1', 'stage_public_ids' => [$productStage->public_id]]];
    $this->putJson(route('admin.production.work-orders.update', $f['order']), $payload)->assertOk();
    $updated = $f['order']->fresh('lines.stageSnapshots');
    expect($updated->lines->first()->unit_id)->toBe($carton->id)
        ->and($updated->lines->first()->base_quantity)->toBe('30.00000000')
        ->and($updated->lines->last()->unit_id)->toBe($f['unit']->id)
        ->and($updated->lines->last()->stageSnapshots->sole()->product_production_stage_id)->toBe($productStage->id)
        ->and($updated->orderStageSnapshots()->sole()->production_stage_id)->toBe($orderStage->id)
        ->and($updated->stageSnapshots()->where('status', '!=', 'pending')->exists())->toBeFalse();
});

test('production correction rejects execution and historical or pending dependencies', function (string $dependency): void {
    $f = unexecutedProductionFixture();
    $cycle = app(ProductionCycleService::class);
    $f['order'] = $cycle->releaseOrder($f['order']);
    if ($dependency === 'run' || $dependency === 'deleted_run') {
        $run = $cycle->createRun($f['order']->lines->sole(), ['planned_quantity' => '1', 'planned_start_at' => now()->addHour(),
            'planned_end_at' => now()->addHours(2), 'production_machine_id' => $f['machine']->id, 'production_mold_id' => $f['mold']->id]);
        if ($dependency === 'deleted_run') {
            $run->update(['status' => 'cancelled']);
            $run->delete();
        }
    } elseif ($dependency === 'reservation') {
        InventoryReservation::query()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
            'branch_id' => $f['branch']->id, 'branch_store_id' => $f['store']->id, 'product_id' => $f['raw']->id,
            'production_order_id' => $f['order']->id, 'quantity' => '1', 'source_type' => 'production_order', 'source_id' => $f['order']->id]);
    } elseif ($dependency === 'document') {
        InventoryDocument::query()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
            'branch_id' => $f['branch']->id, 'branch_store_id' => $f['store']->id, 'doc_number' => 99401,
            'doc_num' => 'SYNTHETIC-PRODUCTION-ISSUE-'.$f['company']->id, 'document_type' => InventoryDocument::TypeIssue,
            'document_date' => '2026-09-28', 'production_order_id' => $f['order']->id, 'status' => 'draft']);
    } else {
        $stage = app(ProductionRoutingService::class)->createStage(['name' => 'Synthetic started stage', 'output_type' => 'finished_product', 'status' => 'active']);
        app(ProductionRoutingService::class)->snapshotOrderRoute($f['order'], [$stage->public_id]);
        ProductionOrderStageEvent::query()->create(['production_order_stage_snapshot_id' => $f['order']->orderStageSnapshots()->sole()->id,
            'event_type' => 'started', 'status' => 'pending', 'occurred_at' => now()]);
    }
    $before = $f['order']->fresh()->lines()->get()->toArray();
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->putJson(route('admin.production.work-orders.update', $f['order']), unexecutedProductionPayload($f))
            ->assertUnprocessable()->assertJsonValidationErrors('production_order')
            ->assertJsonPath('errors.production_order.0', __('production_execution.amendment.execution_blocked'));
    }
    expect($f['order']->fresh()->lines()->get()->toArray())->toBe($before);
})->with(['run', 'deleted_run', 'reservation', 'document', 'stage_event']);

test('production correction requires edit and release permissions separately and enforces factory scope', function (): void {
    $f = unexecutedProductionFixture();
    $f['order'] = app(ProductionCycleService::class)->releaseOrder($f['order']);
    $f['user']->revokePermissionTo('production.orders.release');
    $url = route('admin.production.work-orders.update', $f['order']);
    $this->putJson($url, unexecutedProductionPayload($f))->assertForbidden();
    $this->get(route('admin.production.work-orders.edit', $f['order']))->assertForbidden();
    $f['user']->givePermissionTo('production.orders.release');
    $f['user']->revokePermissionTo('production.orders.edit');
    $this->putJson($url, unexecutedProductionPayload($f))->assertForbidden();
    $f['user']->givePermissionTo('production.orders.edit');
    $other = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) Branch::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-PRODUCTION-OTHER-'.$f['company']->id, 'name' => 'Other factory', 'type' => Branch::TypeFactory, 'status' => 'active']);
    $this->withSession(manufacturingIntegritySession([...$f, 'branch' => $other]))->putJson($url, unexecutedProductionPayload($f))->assertNotFound();
    expect($f['order']->fresh()->status)->toBe(ProductionOrder::StatusReleased);
});

test('production correction rejects stale forms missing reasons closed periods and invalid products atomically', function (): void {
    $f = unexecutedProductionFixture();
    $url = route('admin.production.work-orders.update', $f['order']);
    $payload = unexecutedProductionPayload($f);
    $this->putJson($url, [...$payload, 'amendment_reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('amendment_reason');
    $f['order']->update(['production_notes' => 'SYNTHETIC newer modification']);
    $this->putJson($url, $payload)->assertUnprocessable();
    $payload['amendment_token'] = $f['order']->fresh()->amendmentToken();
    $f['period']->update(['is_closed' => true]);
    $this->putJson($url, $payload)->assertUnprocessable();
    $f['period']->update(['is_closed' => false]);
    $this->putJson($url, [...$payload, 'production_order_date' => '2026-10-01'])->assertUnprocessable();
    $f['alternate']->delete();
    $this->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.0.source_line_reference');
    $payload['lines'][0]['source_line_reference'] = 'product:'.$f['raw']->doc_num;
    $this->putJson($url, $payload)->assertUnprocessable();
    expect($f['order']->fresh('lines')->lines->sole()->product_id)->toBe($f['finished']->id)
        ->and(DB::table('activity_log')->where('event', 'production.order.amended')->where('subject_id', $f['order']->id)->exists())->toBeFalse();
});

test('failed production replacement rolls back released BOM source demand and audit together', function (): void {
    $f = unexecutedProductionSource(unexecutedProductionFixture(), 'sales_order');
    $cycle = app(ProductionCycleService::class);
    $f['order'] = $cycle->releaseOrder($f['order']);
    $before = $f['order']->getAttributes();
    $lines = $f['order']->lines()->get()->toArray();
    $source = $f['sourceLines']->map(fn ($line) => $line->fresh()->getAttributes())->all();
    expect(fn () => $cycle->updateDraftOrder($f['order'], [...$f['header'], 'amendment_token' => $f['order']->amendmentToken(),
        'amendment_reason' => 'SYNTHETIC deliberate second line failure'], [
            ['product_id' => $f['alternate']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1', 'sales_order_line_id' => $f['sourceLines']->last()->id],
            ['product_id' => $f['raw']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1'],
        ]))->toThrow(DomainException::class);
    expect($f['order']->fresh()->getAttributes())->toBe($before)->and($f['order']->lines()->get()->toArray())->toBe($lines)
        ->and($f['sourceLines']->map(fn ($line) => $line->fresh()->getAttributes())->all())->toBe($source)
        ->and(DB::table('activity_log')->where('event', 'production.order.amended')->where('subject_id', $f['order']->id)->exists())->toBeFalse();
});
