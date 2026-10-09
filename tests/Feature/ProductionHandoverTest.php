<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryStandardCostService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionHandoverService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProductionHandoverSupport.php';
require_once __DIR__.'/../InventoryStandardCostSupport.php';

test('production handover CRUD uses shared controls and saves localized dates without posting stock', function (string $locale): void {
    $f = productionHandoverFixture();
    $this->withSession(['locale' => $locale]);
    app()->setLocale($locale);
    app(SettingService::class)->set('date_format', 'd/m/Y');
    $run = $f['runs']->first();
    $index = route('admin.production.handovers.index');
    $this->get($index)->assertOk()->assertSee('crud-index-toolbar', false)
        ->assertSee('data-server-table', false)->assertSee(__('production_handover.list_help'));
    $form = $this->get(route('admin.production.handovers.create', $run))->assertOk()
        ->assertSee('data-line-card-editor', false)->assertSee('js-date-picker', false)
        ->assertSee('js-select2-local', false)->assertSee('data-submit-action="save_back"', false)
        ->assertSee(__('common.actions.save_and_view'))->assertDontSee('data-submit-action="save_edit"', false);
    expect(substr_count($form->getContent(), 'name="_submission_token"'))->toBe(1);
    foreach ($f['runs'] as $item) {
        $form->assertSee($item->product->name)->assertSee($item->unit->name);
    }
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    $this->post(route('admin.production.handovers.store', $run), [
        '_submission_token' => (string) Str::uuid(), 'submit_action' => 'save_back',
        'branch_store_id' => $f['store']->id, 'document_date' => '29/09/2026',
        'lines' => [['run_public_id' => $run->public_id, 'quantity' => '4.12345678']],
        'notes' => 'SYNTHETIC shared CRUD notes',
    ])->assertRedirect($index)->assertSessionHasNoErrors();
    $handover = InventoryDocument::query()->where('document_type', InventoryDocument::TypeProductionHandover)->sole();
    expect($handover->document_date->toDateString())->toBe('2026-09-29')
        ->and($handover->lines->sole()->transaction_quantity)->toBe('4.12345678')
        ->and([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    $this->get(route('admin.production.handovers.show', $handover))->assertOk()
        ->assertSee('SYNTHETIC shared CRUD notes')->assertSee(__('production_handover.approve'));
    $data = $this->getJson($index.'?'.http_build_query(['draw' => 1, 'length' => 10, 'search' => ['value' => $handover->doc_num]]))
        ->assertOk()->assertJsonMissingPath('error')->assertJsonPath('recordsFiltered', 1)->json('data.0');
    expect(array_keys($data))->toEqualCanonicalizing(['doc_num', 'document_date', 'source_doc_num', 'store', 'status', 'actions'])
        ->and($data['actions'])->toContain(__('common.actions.view'));
    $this->getJson($index.'?'.http_build_query(['draw' => 2, 'search' => ['value' => 'SYNTHETIC no match']]))
        ->assertOk()->assertJsonPath('recordsFiltered', 0);
    $this->withSession(['current_branch_id' => 999999])->getJson($index.'?draw=3')->assertUnprocessable()->assertJsonMissingPath('data');
})->with(['ar', 'en']);

test('warehouse receipt CRUD retains partial item quantities and independent approval actions', function (): void {
    $f = productionHandoverFixture();
    $service = app(ProductionHandoverService::class);
    $run = $f['runs']->first();
    $handover = $service->approveHandover($service->createHandover($run, $f['store']->id, now()->toDateString(),
        [['run_public_id' => $run->public_id, 'quantity' => '10']]));
    productionHandoverActor($f, $f['warehouse']);
    $this->get(route('admin.inventory.production-receipts.create', $handover))->assertOk()
        ->assertSee('data-line-card-editor', false)->assertSee('js-date-picker', false)
        ->assertSee('data-submit-action="save_back"', false)->assertSee(__('production_handover.actual_quantity'));
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    $receipt = $service->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $handover->lines->sole()->public_id, 'quantity' => '3']]);
    $this->get(route('admin.inventory.production-receipts.show', $receipt))->assertOk()
        ->assertSee(__('production_handover.approve_receipt'))->assertDontSee('form.edit', false);
    $this->getJson(route('admin.inventory.production-receipts.index', ['draw' => 1]))->assertOk()->assertJsonMissingPath('error')->assertJsonPath('recordsTotal', 1)->assertJsonPath('recordsFiltered', 1);
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($before)
        ->and($receipt->lines->sole()->transaction_quantity)->toBe('3.00000000');
    $f['warehouse']->revokePermissionTo('inventory.production_receipts.approve');
    $this->get(route('admin.inventory.production-receipts.show', $receipt))->assertOk()->assertDontSee(__('production_handover.approve_receipt'));
    $f['warehouse']->revokePermissionTo('inventory.production_receipts.view');
    $this->getJson(route('admin.inventory.production-receipts.index', ['draw' => 2]))->assertForbidden();
});

test('three item handover has no stock or GL and separate warehouse approval receives actual partial quantities once', function (): void {
    $f = productionHandoverFixture();
    $service = app(ProductionHandoverService::class);
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    $progress = $f['runs']->map(fn (ProductionRun $run) => $run->progressEntries->map->getRawOriginal()->all())->all();
    $handover = $service->createHandover($f['runs']->first(), $f['store']->id, now()->toDateString(),
        $f['runs']->map(fn (ProductionRun $run): array => ['run_public_id' => $run->public_id, 'quantity' => '10'])->all());
    expect($handover->lines)->toHaveCount(3)->and($handover->status)->toBe('draft')
        ->and([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    expect(fn () => app(InventoryDocumentPostingService::class)->post($handover))->toThrow(DomainException::class);
    $handover = $service->approveHandover($handover);
    $originalLines = $handover->lines->map->getRawOriginal()->all();
    expect($handover->status)->toBe('approved')->and($handover->journal_entry_id)->toBeNull()
        ->and([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    productionHandoverActor($f, $f['warehouse']);
    $lines = $handover->lines;
    $receipt = $service->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $lines[0]->public_id, 'quantity' => '4'], ['line_public_id' => $lines[1]->public_id, 'quantity' => '3']]);
    expect($receipt->status)->toBe('draft')->and([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    expect(fn () => app(InventoryDocumentPostingService::class)->post($receipt))->toThrow(DomainException::class);
    $receipt = $service->approveWarehouseReceipt($receipt);
    $after = [InventoryTransaction::count(), JournalEntry::count()];
    $service->approveWarehouseReceipt($receipt);
    expect($receipt->status)->toBe('posted')->and($receipt->journal_entry_id)->not->toBeNull()
        ->and([InventoryTransaction::count(), JournalEntry::count()])->toBe($after)
        ->and($f['runs']->map(fn (ProductionRun $run) => $run->fresh()->received_base_quantity)->all())->toBe(['4.00000000', '3.00000000', '0.00000000'])
        ->and($handover->fresh()->lines->map->getRawOriginal()->all())->toBe($originalLines)
        ->and($f['runs']->map(fn (ProductionRun $run) => $run->fresh()->progressEntries->map->getRawOriginal()->all())->all())->toBe($progress);
    foreach ($f['runs'] as $index => $run) {
        $stock = app(InventoryAvailabilityService::class)->forProduct($f['company']->id, $f['store']->id, $run->product_id);
        expect(bcadd($stock['on_hand'], '0', 8))->toBe(['4.00000000', '3.00000000', '0.00000000'][$index]);
    }
    foreach (app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id) as $row) {
        expect($row['difference'])->toBe('0.0000');
    }
    productionHandoverActor($f, $f['user']);
    expect(fn () => $service->cancelHandover($handover, 'SYNTHETIC no cascading stock cancellation'))->toThrow(DomainException::class);
});

test('warehouse partial receipts over separate days retain handover dates and reject excess without posting effects', function (): void {
    $f = productionHandoverFixture();
    $service = app(ProductionHandoverService::class);
    $handover = $service->approveHandover($service->createHandover($f['runs']->first(), $f['store']->id, now()->toDateString(),
        $f['runs']->map(fn (ProductionRun $run): array => ['run_public_id' => $run->public_id, 'quantity' => '10'])->all()));
    productionHandoverActor($f, $f['warehouse']);
    $source = $handover->lines->first();
    $first = $service->approveWarehouseReceipt($service->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $source->public_id, 'quantity' => '4']]));
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    expect(fn () => $service->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $source->public_id, 'quantity' => '7']]))->toThrow(DomainException::class);
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    $second = $service->approveWarehouseReceipt($service->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $source->public_id, 'quantity' => '6']]));
    expect($second->document_date->toDateString())->toBe('2026-09-30')
        ->and($second->lines->sole()->manufacture_date->toDateString())->toBe('2026-09-29')
        ->and($second->journalEntry->entry_date->toDateString())->toBe('2026-09-30')
        ->and(bcadd((string) $service->remainingLines($handover)->first()->remaining_quantity, '0', 8))->toBe('0.00000000')
        ->and($f['runs']->first()->fresh()->received_base_quantity)->toBe('10.00000000')
        ->and($first->fresh()->status)->toBe('posted');
});

test('quality quantity bounds handover approval and cancelling an unreceived handover retains original evidence', function (): void {
    $f = partialOutputFixture();
    foreach (['production.handovers.create', 'production.handovers.approve', 'production.handovers.cancel'] as $key) {
        $f['user']->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '10']);
    partialOutputApprove($f, '10', '6');
    $service = app(ProductionHandoverService::class);
    $tooMuch = $service->createHandover($f['run']->fresh(), $f['store']->id, now()->toDateString(), [['run_public_id' => $f['run']->public_id, 'quantity' => '10']]);
    expect(fn () => $service->approveHandover($tooMuch))->toThrow(DomainException::class);
    $service->cancelHandover($tooMuch, 'SYNTHETIC quality eligible quantity only');
    $handover = $service->approveHandover($service->createHandover($f['run']->fresh(), $f['store']->id, now()->toDateString(), [['run_public_id' => $f['run']->public_id, 'quantity' => '6']]));
    expect($service->quantities($f['run']->fresh())['committed'])->toBe('6.00000000');
    $lines = $handover->lines->map->getRawOriginal()->all();
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    $service->cancelHandover($handover, 'SYNTHETIC unreceived dispatch withdrawn');
    $service->cancelHandover($handover, 'SYNTHETIC repeat');
    expect($handover->fresh()->status)->toBe('cancelled')->and($handover->fresh()->trashed())->toBeFalse()
        ->and($handover->fresh()->lines->map->getRawOriginal()->all())->toBe($lines)
        ->and($service->quantities($f['run']->fresh())['committed'])->toBe('0.00000000')
        ->and([InventoryTransaction::count(), JournalEntry::count()])->toBe($before)
        ->and(DB::table('activity_log')->where('subject_type', InventoryDocument::class)->where('subject_id', $handover->id)->where('event', 'production.handover.cancelled')->count())->toBe(1);
});

test('ordinary running production retains native dated receipt posting into an authorized later open period', function (): void {
    $f = productionHandoverFixture();
    $service = app(ProductionHandoverService::class);
    $run = $f['runs']->first();
    $inputs = [['run_public_id' => $run->public_id, 'quantity' => '10']];
    $handover = $service->approveHandover($service->createHandover($run, $f['store']->id, now()->toDateString(), $inputs));
    $f['period']->update(['to_date' => '2026-09-30']);
    $next = FinancialPeriod::query()->create(['company_id' => $f['company']->id,
        'doc_number' => (int) FinancialPeriod::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-HANDOVER-NEXT', 'name' => 'SYNTHETIC next handover period',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false]);
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    request()->session()->put(OperatingContextService::FinancialPeriodIdKey, $next->id);
    request()->session()->put(OperatingContextService::FinancialPeriodDocNumKey, $next->doc_num);
    request()->attributes->replace([]);
    productionHandoverActor($f, $f['warehouse']);
    request()->session()->put(OperatingContextService::FinancialPeriodIdKey, $next->id);
    request()->session()->put(OperatingContextService::FinancialPeriodDocNumKey, $next->doc_num);
    request()->attributes->replace([]);
    $receipt = $service->approveWarehouseReceipt($service->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $handover->lines->sole()->public_id, 'quantity' => '10']]));
    expect($receipt->financial_period_id)->toBe($next->id)
        ->and($receipt->journalEntry->financial_period_id)->toBe($next->id)
        ->and($receipt->transactions->sole()->financial_period_id)->toBe($next->id)
        ->and($run->fresh()->financial_period_id)->toBe($f['period']->id)
        ->and($receipt->lines->sole()->manufacture_date->toDateString())->toBe('2026-09-29')
        ->and($run->fresh()->received_base_quantity)->toBe('10.00000000');
    $next->update(['is_closed' => true]);
    expect(fn () => $service->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $handover->lines->sole()->public_id, 'quantity' => '1']]))->toThrow(DomainException::class);

});

test('unapproved correction period metadata cannot authorize a new handover', function (): void {
    $f = productionHandoverFixture();
    $run = $f['runs']->first();
    $run->update(['correction_posting_financial_period_id' => $f['period']->id]);
    $before = [InventoryDocument::count(), InventoryTransaction::count(), JournalEntry::count()];
    expect(fn () => app(ProductionHandoverService::class)->createHandover($run->fresh(), $f['store']->id, now()->toDateString(),
        [['run_public_id' => $run->public_id, 'quantity' => '10']]))
        ->toThrow(DomainException::class, __('production_run_correction.lineage_invalid'));
    expect([InventoryDocument::count(), InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
});

test('native standard cost settlements use only their item lines from a three item warehouse receipt', function (): void {
    $f = productionHandoverFixture();
    $handovers = app(ProductionHandoverService::class);
    $handover = $handovers->approveHandover($handovers->createHandover($f['runs']->first(), $f['store']->id, now()->toDateString(),
        $f['runs']->map(fn (ProductionRun $run): array => ['run_public_id' => $run->public_id, 'quantity' => '10'])->all()));
    productionHandoverActor($f, $f['warehouse']);
    $receipt = $handovers->approveWarehouseReceipt($handovers->createWarehouseReceipt($handover, now()->toDateString(),
        $handover->lines->map(fn ($line): array => ['line_public_id' => $line->public_id, 'quantity' => '10'])->all()));
    $original = $receipt->lines->map->getRawOriginal()->all();
    $reviewer = closureSyntheticUser();
    foreach (['prepare', 'settle', 'view'] as $permission) {
        $f['user']->givePermissionTo(Permission::findOrCreate('inventory.cost_policies.standard.'.$permission, 'web'));
    }
    $reviewer->givePermissionTo(Permission::findOrCreate('inventory.cost_policies.standard.approve', 'web'));
    $f = standardCostAccounts([...$f, 'preparer' => $f['user'], 'approver' => $reviewer]);
    $service = app(InventoryStandardCostService::class);
    foreach ($f['runs'] as $run) {
        productionHandoverActor($f, $f['user']);
        $f['cycle']->completeRun($run->fresh());
        $standard = prepareSyntheticStandard([...$f, 'finished' => $run->product]);
        productionHandoverActor($f, $reviewer);
        $service->approveVersion($standard, $reviewer->id, 'SYNTHETIC independent item standard');
        productionHandoverActor($f, $f['user']);
        $settlement = $service->prepareSettlement($run->fresh(), now()->toDateString(), 'SYNTHETIC per-item shared receipt settlement', $f['user']->id);
        expect($settlement->impact_snapshot['good_quantity'])->toBe('10.00000000')
            ->and($settlement->impact_snapshot['components']['materials']['actual_total'])->toBe('40.00000000');
        foreach ($settlement->impact_snapshot['wip_sources']['balances'] as $balance) {
            expect(bccomp($balance, '0', 8))->toBe(0);
        }
        productionHandoverActor($f, $reviewer);
        $service->approveSettlement($settlement, $reviewer->id, 'SYNTHETIC independent shared receipt settlement');
    }
    expect($receipt->fresh()->lines->map->getRawOriginal()->all())->toBe($original);
    foreach (app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id) as $row) {
        expect($row['difference'])->toBe('0.0000');
    }
});
