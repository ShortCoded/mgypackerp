<?php

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Models\Role;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

/** @return array<string, mixed> */
function sourcingCancellationFixture(bool $withSelection = true): array
{
    $f = procurementFixture();
    $service = app(ProcurementSourcingService::class);
    $requisition = $service->approveRequisition($service->submitRequisition(procurementManualRequisition($f)));
    $admin = procurementAdministrativeBranch($f);
    procurementUseBranch($f, $admin);
    foreach (['purchases.request_for_quotations', 'purchases.supplier_quotation_entry', 'purchases.supplier_selection'] as $prefix) {
        foreach (['view', 'cancel'] as $action) {
            $f['user']->givePermissionTo(Permission::findOrCreate($prefix.'.'.$action, 'web'));
        }
    }
    $rfq = $service->issueRequestForQuotation($service->createRequestForQuotation($requisition, [
        'issue_date' => now()->toDateString(), 'supplier_doc_nums' => [$f['firstSupplier']->doc_num],
        'lines' => [['requisition_line_public_id' => $requisition->lines->sole()->public_id, 'quantity' => '10']],
    ]));
    $quotation = $service->submitSupplierQuotation($service->createSupplierQuotation($rfq, [
        'supplier_doc_num' => $f['firstSupplier']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
        'quotation_date' => now()->toDateString(), 'exchange_rate' => '1',
        'lines' => [['rfq_line_public_id' => $rfq->lines->sole()->public_id, 'offered_quantity' => '10', 'unit_price' => '2', 'tax_rate' => '14']],
    ]));
    $selection = $withSelection ? $service->createSupplierSelection($rfq, [
        'selection_date' => now()->toDateString(), 'selection_reason' => 'SYNTHETIC award',
        'lines' => [['quotation_line_public_id' => $quotation->lines->sole()->public_id, 'selected_quantity' => '10']],
    ]) : null;
    test()->actingAs($f['user']);

    return [...$f, ...compact('admin', 'requisition', 'rfq', 'quotation', 'selection')];
}

test('sourcing cancellation follows owner order retains original awards and restores capacity without stock or GL changes', function (): void {
    $f = sourcingCancellationFixture();
    $service = app(ProcurementSourcingService::class);
    $orders = $service->approveSelection($f['selection']);
    $before = [$f['rfq']->fresh()->lines->toArray(), $f['quotation']->fresh()->lines->toArray(), $f['selection']->fresh()->lines->toArray()];
    $ledger = [JournalEntry::count(), InventoryTransaction::count()];
    foreach (['rfq', 'quotation', 'selection'] as $key) {
        expect(fn () => $service->cancelSourcingDocument($f[$key], 'SYNTHETIC blocked'))
            ->toThrow(DomainException::class, __('cancellation_review.sourcing_dependencies'));
    }
    app(PurchaseOrderService::class)->cancel($orders->sole(), 'SYNTHETIC unexecuted order withdrawn');
    $approvedAt = $f['selection']->fresh()->approved_at->toISOString();
    $service->cancelSourcingDocument($f['selection'], 'SYNTHETIC award withdrawn');
    $replacement = $service->createSupplierSelection($f['rfq']->fresh(), [
        'selection_date' => now()->toDateString(),
        'lines' => [['quotation_line_public_id' => $f['quotation']->lines->sole()->public_id, 'selected_quantity' => '10']],
    ]);
    $service->cancelSourcingDocument($replacement, 'SYNTHETIC replacement withdrawn');
    $service->cancelSourcingDocument($f['quotation'], 'SYNTHETIC offer withdrawn');
    $service->cancelSourcingDocument($f['rfq'], 'SYNTHETIC sourcing withdrawn');
    expect([$f['rfq']->fresh()->lines->toArray(), $f['quotation']->fresh()->lines->toArray(), $f['selection']->fresh()->lines->toArray()])->toBe($before)
        ->and($f['selection']->fresh()->approved_at->toISOString())->toBe($approvedAt)
        ->and($orders->sole()->fresh()->status)->toBe(PurchaseOrder::StatusCancelled)
        ->and([$f['rfq']->fresh()->trashed(), $f['quotation']->fresh()->trashed(), $f['selection']->fresh()->trashed()])->toBe([false, false, false])
        ->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($ledger);
    $newRfq = $service->createRequestForQuotation($f['requisition']->fresh(), [
        'issue_date' => now()->toDateString(), 'supplier_doc_nums' => [$f['firstSupplier']->doc_num],
        'lines' => [['requisition_line_public_id' => $f['requisition']->lines->sole()->public_id, 'quantity' => '10']],
    ]);
    expect($newRfq->lines->sole()->quantity)->toBe('10.00000000');
    $audit = DB::table('activity_log')->where('event', 'sourcing_document.cancelled')->where('subject_type', $f['selection']::class)->where('subject_id', $f['selection']->id)->sole();
    expect(json_decode($audit->properties, true))->toMatchArray(['reason' => 'SYNTHETIC award withdrawn', 'original_status' => 'approved']);
});

test('sourcing cancellation is localized permission scoped and submission retries are idempotent', function (string $locale): void {
    $f = sourcingCancellationFixture();
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $url = route('admin.purchases.supplier-selection.cancel', $f['selection']);
    $this->get(route('admin.purchases.supplier-selection.show', $f['selection']))->assertOk()
        ->assertSee(__('cancellation_review.manual_steps'))->assertSee($url, false)->assertDontSee('cancellation_review.');
    $payload = ['reason' => 'SYNTHETIC duplicate request', '_submission_token' => (string) Str::uuid()];
    $this->postJson($url, ['reason' => $payload['reason']])->assertUnprocessable();
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, [...$payload, 'reason' => 'SYNTHETIC altered payload'])->assertConflict();
    expect(DB::table('activity_log')->where('event', 'sourcing_document.cancelled')->count())->toBe(1);
    $this->get(route('admin.purchases.supplier-selection.show', $f['selection']))->assertOk()->assertSee($payload['reason']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('purchases.supplier_quotation_entry.view', 'web'));
    $this->actingAs($viewer)->get(route('admin.purchases.supplier-quotation-entry.show', $f['quotation']))->assertOk()
        ->assertSee(__('cancellation_review.permission_required'))->assertDontSee('data-document-cancellation-form', false);
    $this->postJson(route('admin.purchases.supplier-quotation-entry.cancel', $f['quotation']), $payload)->assertForbidden();
    expect($f['quotation']->fresh()->status)->toBe('submitted');
})->with(['ar', 'en']);

test('historical sourcing cancellation records current open period and retains original dates', function (): void {
    $f = sourcingCancellationFixture();
    $original = $f['selection']->getRawOriginal();
    $f['period']->update(['is_closed' => true, 'to_date' => now()->toDateString()]);
    $this->travelTo(now()->addDay());
    $current = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('financial_periods', FinancialPeriod::class, $f['company']->id),
        'company_id' => $f['company']->id, 'name' => 'SYNTHETIC cancellation period',
        'from_date' => now()->toDateString(), 'to_date' => now()->addMonth()->toDateString(), 'is_closed' => false,
    ]);
    session([OperatingContextService::FinancialPeriodIdKey => $current->id, OperatingContextService::FinancialPeriodDocNumKey => $current->doc_num]);
    $this->withSession([OperatingContextService::FinancialPeriodIdKey => $current->id, OperatingContextService::FinancialPeriodDocNumKey => $current->doc_num]);
    $this->get(route('admin.purchases.supplier-selection.show', $f['selection']))->assertOk()->assertSee('data-document-cancellation-form', false);
    $this->postJson(route('admin.purchases.supplier-selection.cancel', $f['selection']), ['reason' => 'SYNTHETIC historical award withdrawn', '_submission_token' => (string) Str::uuid()])->assertOk();
    $audit = DB::table('activity_log')->where('event', 'sourcing_document.cancelled')->sole();
    expect(json_decode($audit->properties, true))->toMatchArray(['source_financial_period_id' => $f['period']->id, 'cancellation_financial_period_id' => $current->id])
        ->and($f['selection']->fresh()->financial_period_id)->toBe($original['financial_period_id'])
        ->and($f['selection']->fresh()->getRawOriginal('selection_date'))->toBe($original['selection_date']);
    $current->update(['is_closed' => true]);
    expect(fn () => app(ProcurementSourcingService::class)->cancelSourcingDocument($f['quotation'], 'SYNTHETIC closed target'))->toThrow(DomainException::class);
    expect($f['quotation']->fresh()->status)->toBe('submitted');
});

test('sourcing cancellation rolls back when audit fails and rejects cancelled children without owner evidence', function (): void {
    $f = sourcingCancellationFixture(false);
    $this->mock(ActivityLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('SYNTHETIC audit failure'));
    expect(fn () => app(ProcurementSourcingService::class)->cancelSourcingDocument($f['quotation'], 'SYNTHETIC rollback'))->toThrow(RuntimeException::class);
    expect($f['quotation']->fresh()->status)->toBe('submitted');
    $f['quotation']->update(['status' => 'cancelled']);
    expect(fn () => app(ProcurementSourcingService::class)->cancelSourcingDocument($f['rfq'], 'SYNTHETIC unproven child'))
        ->toThrow(DomainException::class, __('cancellation_review.sourcing_dependencies'));
    expect($f['rfq']->fresh()->status)->toBe('issued');
});

test('sourcing cancellation rejects foreign branch company and inaccessible source period without writes', function (): void {
    $f = sourcingCancellationFixture(false);
    $before = $f['quotation']->fresh()->getRawOriginal();
    procurementUseBranch($f, $f['branch']);
    expect(fn () => app(ProcurementSourcingService::class)->cancelSourcingDocument($f['quotation'], 'SYNTHETIC wrong branch'))->toThrow(DomainException::class);
    procurementUseBranch($f, $f['admin']);
    $role = Role::query()->create(['name' => 'SYNTHETIC sourcing scope', 'guard_name' => 'web',
        'doc_number' => 99081, 'doc_num' => 'SYNTHETIC-SOURCING-SCOPE', 'financial_period_access_restricted' => true]);
    $f['user']->assignRole($role);
    $request = clone request();
    $request->attributes->replace([]);
    $request->setRouteResolver(fn (): Route => new Route('GET', 'synthetic-sourcing-scope', static fn () => null));
    app()->instance('request', $request);
    expect(fn () => app(ProcurementSourcingService::class)->cancelSourcingDocument($f['quotation'], 'SYNTHETIC forbidden period'))->toThrow(DomainException::class);
    $this->get(route('admin.purchases.supplier-quotation-entry.show', $f['quotation']))->assertNotFound();
    session([OperatingContextService::CompanyIdKey => 999999]);
    $this->withSession([OperatingContextService::CompanyIdKey => 999999])->postJson(route('admin.purchases.supplier-quotation-entry.cancel', $f['quotation']),
        ['reason' => 'SYNTHETIC foreign company', '_submission_token' => (string) Str::uuid()])->assertNotFound();
    expect($f['quotation']->fresh()->getRawOriginal())->toBe($before);
});
