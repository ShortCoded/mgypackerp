<?php

use App\Models\User;
use App\Services\DocumentCancellationReviewService;
use Carbon\Carbon;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\ProductionMaterialDemandService;
use Modules\Production\Services\ProductionMaterialRequestService;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProductionAnyStageMaterialSupport.php';
require_once __DIR__.'/../ProcurementCorrectionSupport.php';
require_once __DIR__.'/../CustomerInvoiceCorrectionSupport.php';

/** @param array<string, mixed> $fixture */
function grantCancellationPermissions(array $fixture): void
{
    foreach (['production.orders.view', 'production.orders.cancel', 'production.material_requests.cancel', 'production.runs.view', 'production.runs.cancel'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
}

test('material cancellation review is localized read only and hides execution from view only users', function (string $locale): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $record = app(ProductionMaterialRequestService::class)->create($f['runs'][1], $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '20']);
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $before = [$record->fresh()->getRawOriginal(), InventoryReservation::query()->count(), InventoryTransaction::query()->count()];
    $this->get(route('admin.production.material-requests.show', $record))->assertOk()
        ->assertSee(__('cancellation_review.title'))->assertSee('data-document-cancellation-form', false)
        ->assertSee(route('admin.production.material-requests.cancel', $record), false)
        ->assertDontSee('cancellation_review.');
    expect([$record->fresh()->getRawOriginal(), InventoryReservation::query()->count(), InventoryTransaction::query()->count()])->toBe($before);
    $viewer = User::factory()->create(['locale' => $locale]);
    $viewer->givePermissionTo('production.material_requests.view');
    $this->actingAs($viewer)->get(route('admin.production.material-requests.show', $record))->assertOk()
        ->assertSee(__('cancellation_review.permission_required'))->assertDontSee('data-document-cancellation-form', false);
    $this->postJson(route('admin.production.material-requests.cancel', $record), [
        'reason' => 'SYNTHETIC forbidden', '_submission_token' => (string) Str::uuid(),
    ])->assertForbidden();
    expect($record->fresh()->status)->toBe(ProductionMaterialRequest::StatusSubmitted);
})->with(['ar', 'en']);

test('unissued cancellation releases reservations and shared demand once while preserving approvals and audited reason', function (): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $service = app(ProductionMaterialRequestService::class);
    $record = $service->approve($service->create($f['runs'][1], $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '20']));
    $approvedAt = $record->approved_at->toISOString();
    $payload = ['reason' => 'SYNTHETIC withdrawn material demand', '_submission_token' => (string) Str::uuid()];
    $url = route('admin.production.material-requests.cancel', $record);
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', 'cancelled');
    $auditCount = DB::table('activity_log')->where('event', 'production_material_request.cancelled')->count();
    expect($auditCount)->toBe(1);
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($url, [...$payload, 'reason' => 'Different payload'])->assertConflict();
    expect($record->fresh()->trashed())->toBeFalse()
        ->and($record->fresh()->approved_at->toISOString())->toBe($approvedAt)
        ->and($record->fresh()->lines->sole()->requested_quantity)->toBe('20.00000000')
        ->and($record->fresh()->lines->sole()->reserved_quantity)->toBe('0.00000000')
        ->and($record->fresh()->lines->sole()->requirement->reserved_quantity)->toBe('0.00000000')
        ->and(InventoryReservation::query()->where('production_material_request_line_id', $record->lines->sole()->id)->where('status', 'active')->count())->toBe(0)
        ->and(app(ProductionMaterialDemandService::class)->remaining($f['runs'][0]->requirements->sole()))->toBe('200.00000000')
        ->and(DB::table('activity_log')->where('event', 'production_material_request.cancelled')->count())->toBe($auditCount);
    $activity = DB::table('activity_log')->where('subject_type', ProductionMaterialRequest::class)->where('subject_id', $record->id)->latest('id')->first();
    expect(json_decode($activity->properties, true)['reason'])->toBe($payload['reason']);
});

test('cancellation transaction rolls back reservations document and shared demand when audit fails', function (): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $service = app(ProductionMaterialRequestService::class);
    $record = $service->approve($service->create($f['runs'][1], $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '20']));
    $before = [$record->getRawOriginal(), $record->lines->sole()->getRawOriginal(),
        InventoryReservation::query()->get()->map->getRawOriginal()->all(), $record->lines->sole()->requirement->getRawOriginal()];
    $this->mock(ActivityLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('SYNTHETIC audit failure'));
    expect(fn () => app(ProductionMaterialRequestService::class)->cancelUnissued($record, 'SYNTHETIC rollback'))->toThrow(RuntimeException::class);
    expect([$record->fresh()->getRawOriginal(), $record->fresh()->lines->sole()->getRawOriginal(),
        InventoryReservation::query()->get()->map->getRawOriginal()->all(), $record->lines->sole()->requirement->fresh()->getRawOriginal()])->toBe($before);
});

test('issued requests closed periods and foreign contexts cannot be cancelled or shown an enabled cancellation', function (): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $service = app(ProductionMaterialRequestService::class);
    $record = $service->approve($service->create($f['runs'][1], $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '20']));
    $service->issue($record, [$record->lines->sole()->id => '1']);
    $f['user']->givePermissionTo(Permission::findOrCreate('production.runs.issue', 'web'));
    $stock = InventoryTransaction::query()->get()->map->getRawOriginal()->all();
    $this->get(route('admin.production.material-requests.show', $record))->assertOk()
        ->assertSee(__('cancellation_review.unissued_only'))->assertSee(__('cancellation_review.issued_material_owner'))
        ->assertSee(route('admin.production.runs.cancellation-owner', $record->run), false)
        ->assertSee(route('admin.production.runs.operation', [$record->run, 'return']), false)
        ->assertDontSee('data-document-cancellation-form', false);
    $this->postJson(route('admin.production.material-requests.cancel', $record), ['reason' => 'SYNTHETIC issued', '_submission_token' => (string) Str::uuid()])->assertUnprocessable();
    expect(InventoryTransaction::query()->get()->map->getRawOriginal()->all())->toBe($stock);
    $unissued = $service->create($f['runs'][2], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '20']);
    $f['period']->update(['is_closed' => true]);
    $this->postJson(route('admin.production.material-requests.cancel', $unissued), ['reason' => 'SYNTHETIC closed', '_submission_token' => (string) Str::uuid()])->assertUnprocessable();
    expect($unissued->fresh()->status)->toBe(ProductionMaterialRequest::StatusSubmitted);
    $this->withSession([OperatingContextService::CompanyIdKey => 999999])
        ->postJson(route('admin.production.material-requests.cancel', $unissued), ['reason' => 'SYNTHETIC foreign', '_submission_token' => (string) Str::uuid()])->assertStatus(404);
});

test('unexecuted production order cancellation preserves source lines is audited and retries once while executed orders stay blocked', function (): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $order = $f['cycle']->createMakeToStockOrder(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id], [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '10']]);
    $lines = $order->lines()->get()->map->getRawOriginal()->all();
    $payload = ['reason' => 'SYNTHETIC order withdrawn', '_submission_token' => (string) Str::uuid()];
    $this->get(route('admin.production.work-orders.show', $order))->assertOk()->assertSee('data-document-cancellation-form', false);
    $url = route('admin.production.work-orders.cancel', $order);
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($url, $payload)->assertOk();
    expect($order->fresh()->trashed())->toBeFalse()->and($order->lines()->get()->map->getRawOriginal()->all())->toBe($lines);
    $this->postJson(route('admin.production.work-orders.cancel', $f['order']), [...$payload, '_submission_token' => (string) Str::uuid()])->assertUnprocessable();
    expect($f['order']->fresh()->status)->toBe(ProductionOrder::StatusReleased);
    $this->get(route('admin.production.work-orders.show', $f['order']))->assertOk()->assertSee(__('cancellation_review.unexecuted_only'))->assertDontSee('data-document-cancellation-form', false);
});

test('a posted closed purchase invoice is actually reversed in the selected open period through the reviewed native action', function (string $locale): void {
    $this->travelTo(now()->setDate(2026, 9, 29));
    $f = procurementCorrectionFixture();
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->close($service->approve($service->create($f['invoice_data'])['record']));
    $original = $invoice->journalEntry;
    $originalRows = $original->lines->map->getRawOriginal()->all();
    $originalPeriodId = $invoice->financial_period_id;
    $f['period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $f['period'] = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
        'company_id' => $f['company']->id, 'name' => 'SYNTHETIC open correction period',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    $this->travelTo(now()->setDate(2026, 10, 5));
    procurementUseBranch($f, $f['admin']);
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $before = $invoice->fresh()->getRawOriginal();
    $this->get(route('admin.purchases.purchase-invoices.show', $invoice))->assertOk()
        ->assertSee(__('cancellation_review.reverse'))->assertSee('data-document-cancellation-form', false)
        ->assertDontSee('cancellation_review.');
    expect($invoice->fresh()->getRawOriginal())->toBe($before);
    $url = route('admin.purchases.purchase-invoices.reverse', $invoice);
    $this->postJson($url, ['cancel_reason' => 'SYNTHETIC supported closed-period reversal'])->assertOk();
    $inverse = $invoice->fresh()->reversalJournalEntry;
    expect($invoice->fresh()->status)->toBe('cancelled')->and($invoice->fresh()->financial_period_id)->toBe($originalPeriodId)
        ->and($invoice->fresh()->closed_at)->not->toBeNull()->and($invoice->fresh()->trashed())->toBeFalse()
        ->and($inverse->financial_period_id)->toBe($f['period']->id)
        ->and($inverse->entry_date->toDateString())->toBe('2026-10-05')
        ->and($original->fresh()->lines->map->getRawOriginal()->all())->toBe($originalRows)
        ->and((string) $inverse->lines()->sum('debit_amount'))->toBe((string) $inverse->lines()->sum('credit_amount'));
    $journalCount = JournalEntry::query()->count();
    $this->postJson($url, ['cancel_reason' => 'SYNTHETIC supported closed-period reversal'])->assertOk();
    expect(JournalEntry::query()->count())->toBe($journalCount);
})->with(['ar', 'en']);

test('an active production expense blocks both cancellation review and the native run API without releasing stock reservations', function (): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $run = $f['runs'][0];
    ProductionExpenseRequest::query()->create([
        'doc_number' => 99001, 'doc_num' => 'SYNTHETIC-CANCEL-EXPENSE',
        'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id,
        'production_order_id' => $f['order']->id, 'production_run_id' => $run->id,
        'request_date' => now()->toDateString(), 'currency_id' => Currency::query()->where('company_id', $f['company']->id)->value('id'),
        'amount' => '10', 'reason' => 'SYNTHETIC active expense', 'status' => 'submitted',
    ]);
    $before = [$run->fresh()->getRawOriginal(), InventoryReservation::query()->get()->map->getRawOriginal()->all()];
    $review = app(DocumentCancellationReviewService::class)->review($run, request());
    expect($review['action'])->toBeNull()->and($review['blockers'])->toContain(__('cancellation_review.expenses_active'));
    $this->postJson(route('admin.production.runs.cancel', $run), ['reason' => 'SYNTHETIC active expense', '_submission_token' => (string) Str::uuid()])->assertUnprocessable();
    expect([$run->fresh()->getRawOriginal(), InventoryReservation::query()->get()->map->getRawOriginal()->all()])->toBe($before);
});

test('linked cancellation review documents obey role branch and source period restrictions', function (): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $otherBranch = Branch::query()->create([
        'doc_number' => 99081, 'doc_num' => 'SYNTHETIC-RESTRICTED-BRANCH', 'company_id' => $f['company']->id,
        'name' => 'SYNTHETIC inaccessible factory', 'type' => Branch::TypeFactory, 'status' => 'active',
    ]);
    $f['runs'][0]->update(['branch_id' => $otherBranch->id]);
    $role = Role::query()->create([
        'name' => 'SYNTHETIC-cancellation-scope', 'guard_name' => 'web', 'doc_number' => 99081,
        'doc_num' => 'SYNTHETIC-CANCELLATION-ROLE', 'company_access_restricted' => false,
        'branch_access_restricted' => true, 'financial_period_access_restricted' => true,
    ]);
    DB::table('role_branch_access')->insert(['role_id' => $role->id, 'branch_id' => $f['branch']->id]);
    DB::table('role_financial_period_access')->insert(['role_id' => $role->id, 'financial_period_id' => $f['period']->id]);
    $f['user']->assignRole($role);
    $request = clone request();
    $request->attributes->replace([]);
    $request->setRouteResolver(fn (): Route => new Route('GET', 'synthetic-cancellation-scope', static fn () => null));
    app()->instance('request', $request);
    $review = app(DocumentCancellationReviewService::class)->review($f['order'], $request);
    expect(array_column($review['links'], 'label'))->toContain($f['runs'][1]->run_number)
        ->not->toContain($f['runs'][0]->run_number);
    DB::table('role_financial_period_access')->where('role_id', $role->id)->delete();
    DB::table('role_financial_period_access')->insert([
        'role_id' => $role->id, 'financial_period_id' => FinancialPeriod::query()->where('company_id', '!=', $f['company']->id)->value('id'),
    ]);
    $request->attributes->replace([]);
    expect(app(DocumentCancellationReviewService::class)->review($f['order'], $request))->toBeNull();
});

test('historical invoice review keeps native correction navigation and cash collection reversal retries preserve the source', function (): void {
    $f = invoiceCorrectionFixture();
    $f['user']->givePermissionTo(Permission::findOrCreate('customer_receipts.view', 'web'));
    $f['user']->givePermissionTo(Permission::findOrCreate('customer_receipts.cancel', 'web'));
    $receipt = invoiceCorrectionCollection($f, $f['invoice'], '20');
    $sourceJournal = Arr::except($receipt->journalEntry->getRawOriginal(), ['reversed_entry_id', 'updated_at']);
    $sourceLines = $receipt->journalEntry->lines->map->getRawOriginal()->all();
    $f['period']->update(['is_closed' => true, 'to_date' => '2026-09-30']);
    $f['target'] = FinancialPeriod::query()->create(['company_id' => $f['company']->id, 'doc_number' => 99319, 'doc_num' => 'SYNTHETIC-REVIEW-OCT',
        'name' => 'SYNTHETIC review October', 'from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'is_closed' => false]);
    $this->travelTo(Carbon::parse('2026-10-02 12:00:00'));
    invoiceCorrectionActor($f, $f['user']);
    $review = app(DocumentCancellationReviewService::class)->review($f['invoice']->fresh(), request());
    expect(array_column($review['links'], 'url'))->toContain(route('admin.sales.sales-invoices.corrections.index', $f['invoice']))
        ->and($review['action'])->toBeNull();
    $url = route('admin.sales.customer-receipts.reverse', $receipt);
    $this->get(route('admin.sales.customer-receipts.show', $receipt))->assertOk()->assertSee($url, false)
        ->assertSee(__('cancellation_review.manual_steps'))->assertDontSee('cancellation_review.');
    $payload = ['reason' => 'SYNTHETIC erroneous cash entry, no physical refund asserted', '_submission_token' => (string) Str::uuid()];
    $this->postJson($url, $payload)->assertOk();
    $count = JournalEntry::query()->count();
    $this->postJson($url, $payload)->assertOk();
    expect($receipt->fresh()->status)->toBe('cancelled')->and($receipt->fresh()->trashed())->toBeFalse()
        ->and(Arr::except($receipt->journalEntry->fresh()->getRawOriginal(), ['reversed_entry_id', 'updated_at']))->toBe($sourceJournal)
        ->and($receipt->journalEntry->fresh()->lines->map->getRawOriginal()->all())->toBe($sourceLines)
        ->and($receipt->fresh()->reversal_journal_entry_id)->not->toBeNull()
        ->and($f['invoice']->fresh()->paid_amount)->toBe('0.0000')
        ->and(JournalEntry::query()->count())->toBe($count);
    $inverse = JournalEntry::query()->findOrFail($receipt->fresh()->reversal_journal_entry_id);
    expect($inverse->financial_period_id)->toBe($f['target']->id)
        ->and($inverse->entry_date->toDateString())->toBe('2026-10-02');
    foreach ($sourceLines as $line) {
        $inverseLine = $inverse->lines()->where('account_id', $line['account_id'])->sole();
        expect(bcadd((string) $inverseLine->debit_amount, '0', 4))->toBe(bcadd((string) $line['credit_amount'], '0', 4))
            ->and(bcadd((string) $inverseLine->credit_amount, '0', 4))->toBe(bcadd((string) $line['debit_amount'], '0', 4));
    }
});

test('historical purchase return review executes the retained draft cancellation or posted owner inverse', function (bool $posted): void {
    $f = procurementCorrectionFixture();
    procurementUseBranch($f, $f['branch']);
    $settlements = app(ProcurementSettlementService::class);
    $return = $settlements->createPurchaseReturn(['purchase_order_doc_num' => $f['order']->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'SYNTHETIC incorrect owner entry',
        'lines' => [['receipt_line_public_id' => $f['receipts']->first()->lines->sole()->public_id, 'quantity' => '1', 'from_quarantine' => false]]]);
    if ($posted) {
        $return = $settlements->approvePurchaseReturn($return);
    }
    $lines = $return->lines->map->getRawOriginal()->all();
    $f['period']->update(['is_closed' => true, 'to_date' => now()->toDateString()]);
    $this->travelTo(now()->addDay());
    $target = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('financial_periods', FinancialPeriod::class, $f['company']->id),
        'company_id' => $f['company']->id, 'name' => 'SYNTHETIC return review target', 'from_date' => now()->toDateString(),
        'to_date' => now()->addMonth()->toDateString(), 'is_closed' => false,
    ]);
    session([OperatingContextService::FinancialPeriodIdKey => $target->id, OperatingContextService::FinancialPeriodDocNumKey => $target->doc_num]);
    $this->withSession([OperatingContextService::FinancialPeriodIdKey => $target->id, OperatingContextService::FinancialPeriodDocNumKey => $target->doc_num]);
    $action = route('admin.purchases.purchase-returns.'.($posted ? 'reverse' : 'cancel'), $return);
    $this->get(route('admin.purchases.purchase-returns.show', $return))->assertOk()->assertSee($action, false);
    $payload = [($posted ? 'reversal_reason' : 'cancel_reason') => 'SYNTHETIC correct erroneous stock entry', '_submission_token' => (string) Str::uuid()];
    $this->postJson($action, $payload)->assertOk();
    $count = JournalEntry::query()->count();
    $this->postJson($action, $payload)->assertOk();
    expect($return->fresh()->status)->toBe($posted ? 'reversed' : 'cancelled')->and($return->fresh()->trashed())->toBeFalse()
        ->and($return->fresh()->lines->map->getRawOriginal()->all())->toBe($lines)->and($return->fresh()->financial_period_id)->toBe($f['period']->id)
        ->and(JournalEntry::query()->count())->toBe($count);
    if ($posted) {
        $inverse = JournalEntry::query()->where('source_type', 'grni_purchase_return_reversal')->where('source_id', $return->id)->sole();
        expect($inverse->financial_period_id)->toBe($target->id)
            ->and($inverse->entry_date->toDateString())->toBe(now()->toDateString())
            ->and(bccomp((string) $inverse->lines()->sum('debit_amount'), (string) $inverse->lines()->sum('credit_amount'), 4))->toBe(0);
    }
})->with([false, true]);

test('cancelling unexecuted linked production demand releases the source sales quantity only once', function (): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $customer = Customer::query()->create([
        'company_id' => $f['company']->id, 'doc_number' => 99091, 'doc_num' => 'SYNTHETIC-CANCEL-DEMAND-CUSTOMER',
        'name' => 'SYNTHETIC cancellation demand', 'status' => 'active',
    ]);
    $salesOrder = SalesOrder::query()->create([
        'doc_number' => 99091, 'doc_num' => 'SYNTHETIC-CANCEL-DEMAND-SALES', 'company_id' => $f['company']->id,
        'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id, 'branch_store_id' => $f['store']->id,
        'customer_id' => $customer->id, 'currency_id' => Currency::query()->where('company_id', $f['company']->id)->value('id'),
        'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(),
        'status' => 'approved', 'credit_status' => 'approved',
        'subtotal_amount' => '100', 'total_amount' => '100',
    ]);
    $line = $salesOrder->lines()->create([
        'line_number' => 1, 'product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id,
        'description' => 'SYNTHETIC production demand', 'quantity' => '100', 'unit_price' => '1', 'line_total' => '100',
        'product_classification_snapshot' => Product::ClassificationFinishedProduct,
        'conversion_factor' => '1', 'base_quantity' => '100',
    ]);
    $order = app(SalesProductionDemandService::class)->create($salesOrder,
        [['sales_order_line_id' => $line->id, 'quantity' => '50']]);
    expect($line->fresh()->production_requested_base_quantity)->toBe('50.00000000');
    $payload = ['reason' => 'SYNTHETIC release order demand', '_submission_token' => (string) Str::uuid()];
    $line->update(['production_requested_quantity' => '0', 'production_requested_base_quantity' => '0']);
    $this->postJson(route('admin.production.work-orders.cancel', $order), $payload)->assertUnprocessable();
    expect($order->fresh()->status)->toBe(ProductionOrder::StatusDraft);
    $line->update(['production_requested_quantity' => '50', 'production_requested_base_quantity' => '50']);
    $this->postJson(route('admin.production.work-orders.cancel', $order), $payload)->assertOk();
    $this->postJson(route('admin.production.work-orders.cancel', $order), $payload)->assertOk();
    expect($line->fresh()->production_requested_quantity)->toBe('0.00000000')
        ->and($line->fresh()->production_requested_base_quantity)->toBe('0.00000000')
        ->and($line->fresh()->quantity)->toBe('100.00000000');
});

test('run cancellation requires explicit cancellation of unissued material requests before releasing their reservations', function (): void {
    $f = anyStageMaterialFixture();
    grantCancellationPermissions($f);
    $service = app(ProductionMaterialRequestService::class);
    $run = $f['runs'][1];
    $record = $service->approve($service->create($run, $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '20']));
    $payload = ['reason' => 'SYNTHETIC cancel in dependency order', '_submission_token' => (string) Str::uuid()];
    $url = route('admin.production.runs.cancel', $run);
    $review = app(DocumentCancellationReviewService::class)->review($run, request());
    expect($review['action'])->toBeNull()->and($review['blockers'])->toContain(__('cancellation_review.material_requests_active'));
    $this->postJson($url, $payload)->assertUnprocessable();
    expect($record->fresh()->status)->toBe(ProductionMaterialRequest::StatusApproved)
        ->and($record->fresh()->lines->sole()->reserved_quantity)->toBe('20.00000000');
    $this->postJson(route('admin.production.material-requests.cancel', $record), [
        'reason' => $payload['reason'], '_submission_token' => (string) Str::uuid(),
    ])->assertOk();
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();
    expect($run->fresh()->status)->toBe('cancelled')->and($record->fresh()->lines->sole()->reserved_quantity)->toBe('0.00000000');
});
