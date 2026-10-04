<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\OpeningBalance;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderChangeRequest;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once dirname(__DIR__, 2).'/SalesCycleSupport.php';
require_once dirname(__DIR__, 2).'/ProcurementSupport.php';

function openDocumentsActor(array $permissions = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}

/** @param array<string, mixed> $payload */
function openDocumentsConfirm(object $test, array $payload): TestResponse
{
    $selection = array_intersect_key($payload, array_flip(['document_type', 'from_number', 'to_number']));
    $preview = $test->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()->json();

    return $test->postJson(route('admin.tools.open-documents.store'), [
        ...$payload,
        'preview_token' => $preview['preview_token'],
    ]);
}

function openDocumentsContext(object $test): array
{
    static $number = 12000;

    $number++;

    $company = Company::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Company-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Open Documents Company '.$number,
        'status' => 'active',
        'is_main' => ! Company::query()->exists(),
    ]);

    $branch = openDocumentsBranch($company, Branch::TypeWarehouse);

    $period = FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Open Documents Period '.$number,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $currency = openDocumentsCurrency();

    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return compact('company', 'branch', 'period', 'currency');
}

function openDocumentsBranch(Company $company, string $type): Branch
{
    static $number = 13000;

    $number++;

    return Branch::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Branch-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Open Documents Branch '.$number,
        'type' => $type,
        'status' => 'active',
    ]);
}

function openDocumentsPeriod(Company $company): FinancialPeriod
{
    static $number = 14000;

    $number++;

    return FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Other Period '.$number,
        'from_date' => '2027-01-01',
        'to_date' => '2027-12-31',
        'is_closed' => false,
    ]);
}

function openDocumentsCurrency(): Currency
{
    static $number = 15000;

    $number++;

    return Currency::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Currency-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Currency '.$number,
        'code' => 'OD'.$number,
        'is_main' => false,
        'status' => 'active',
    ]);
}

function openDocumentsOpeningBalance(Company $company, FinancialPeriod $period, Currency $currency, int $docNumber, array $overrides = []): OpeningBalance
{
    return OpeningBalance::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'currency_id' => $currency->getKey(),
        'doc_number' => $docNumber,
        'doc_num' => 'OB-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-02-01',
        'exchange_rate' => 1,
        'is_closed' => true,
        'approved' => false,
        'status' => OpeningBalance::StatusDraft,
        ...$overrides,
    ]);
}

function openDocumentsOpeningStock(Company $company, FinancialPeriod $period, Branch $branch, int $docNumber, array $overrides = []): OpeningStock
{
    return OpeningStock::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'doc_number' => $docNumber,
        'doc_num' => 'OS-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-02-01',
        'is_closed' => true,
        'approved' => false,
        'status' => OpeningStock::StatusClosed,
        ...$overrides,
    ]);
}

function openDocumentsOpeningStockPricing(Company $company, FinancialPeriod $period, Branch $branch, Currency $currency, int $docNumber, array $overrides = []): OpeningStockPricing
{
    $openingStock = openDocumentsOpeningStock($company, $period, $branch, $docNumber + 1000);

    return OpeningStockPricing::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'opening_stock_id' => $openingStock->getKey(),
        'currency_id' => $currency->getKey(),
        'doc_number' => $docNumber,
        'doc_num' => 'OSP-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-02-01',
        'exchange_rate' => 1,
        'total_amount' => 0,
        'is_closed' => true,
        'status' => OpeningStockPricing::StatusClosed,
        ...$overrides,
    ]);
}

test('Open Document menu appears under Tools with permission', function (): void {
    $this->seed(PermissionSeeder::class);

    $actor = openDocumentsActor(['tools.open_documents.view']);
    $menu = app(MenuService::class)->getMenu($actor);
    $tools = collect($menu)->firstWhere('label', 'tools');
    $filesAndDocuments = collect($tools['children'] ?? [])->firstWhere('label', 'files_documents');
    $openDocuments = collect($filesAndDocuments['children'] ?? [])->firstWhere('label', 'open_documents');

    expect($openDocuments)->not->toBeNull()
        ->and($openDocuments['route'])->toBe('admin.tools.open-documents.index')
        ->and($openDocuments['permission'])->toContain('tools.open_documents.view', 'sales_orders.reopen')
        ->and(Permission::query()->where('name', 'tools.open_documents.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'tools.open_documents.execute')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'purchase_orders.reopen')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'purchases.purchase_requisitions.reopen')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'production.material_requests.reopen')->exists())->toBeTrue();
});

test('Open Document reviews and reverses a posted purchase receipt through its purchasing workflow', function (): void {
    $fixture = procurementFixture();
    foreach (['purchases.goods_receipt_notes.reverse', 'purchases.goods_receipt_notes.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10,
            'unit_price' => 2,
        ]],
    ])['record']);
    $receipt = $receiving->createReceipt($order, [
        'document_date' => now()->toDateString(),
        'supplier_delivery_note' => 'SYNTHETIC-RECEIPT-REVERSAL',
        'lines' => [[
            'purchase_order_line_public_id' => $order->lines->first()->public_id,
            'delivered_quantity' => 4,
        ]],
    ]);
    if ($receipt->qc_status === 'pending_inspection') {
        $receiving->inspect($receipt, ['lines' => [[
            'receipt_line_public_id' => $receipt->lines->first()->public_id,
            'accepted_quantity' => 4,
            'rejected_quantity' => 0,
        ]]]);
    }
    $receipt = $receiving->postReceipt($receipt->fresh());
    $originalMovement = InventoryTransaction::query()->where('posting_key', 'purchase-receipt:'.$receipt->lines->first()->getKey())->sole();
    $originalMovement->forceFill(['posting_key' => 'synthetic-broken-receipt-lineage'])->save();
    expect($receiving->receiptReversalPlan($receipt)['can_reverse'])->toBeFalse();
    expect(fn () => $receiving->reverseReceipt($receipt, 'Incomplete source lineage'))
        ->toThrow(DomainException::class, __('open_documents.corrections.receipt_lineage_incomplete'));
    $originalMovement->forceFill(['posting_key' => 'purchase-receipt:'.$receipt->lines->first()->getKey()])->save();
    $this->travelTo($fixture['period']->to_date->copy()->addDay());
    expect($receiving->receiptReversalPlan($receipt)['can_reverse'])->toBeFalse();
    $this->travelBack();
    $selection = [
        'document_type' => 'purchase_receipts',
        'from_number' => $receipt->doc_number,
        'to_number' => $receipt->doc_number,
    ];

    $this->actingAs($fixture['user'])->get(route('admin.tools.open-documents.index'))
        ->assertOk()->assertSee('value="purchase_receipts"', false);
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), $selection)
        ->assertOk()
        ->assertJsonPath('documents.0.decision', 'ready_reverse')
        ->assertJsonPath('documents.0.correction_lines.0.quantity', '4.00000000')
        ->assertJsonPath('documents.0.correction_lines.0.after_quantity', '0.00000000')
        ->json();
    $this->postJson(route('admin.tools.open-documents.store'), [...$selection, 'reason' => 'Synthetic receipt correction'])
        ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
    $this->postJson(route('admin.tools.open-documents.store'), [
        ...$selection,
        'reason' => 'Synthetic receipt correction',
        'preview_token' => $preview['preview_token'],
    ])->assertOk()->assertJsonPath('summary.opened', 1);

    expect($receipt->fresh()->status)->toBe(UnpricedInventoryReceipt::StatusReversed)
        ->and($receipt->fresh()->reversal_reason)->toBe('Synthetic receipt correction')
        ->and($order->fresh()->total_remaining_quantity)->toBe('10.00000000')
        ->and(InventoryTransaction::query()->where('posting_key', 'purchase-receipt:'.$receipt->lines->first()->getKey().':reversal')->count())->toBe(1);
    $this->postJson(route('admin.tools.open-documents.store'), [
        ...$selection,
        'reason' => 'Duplicate correction attempt',
        'preview_token' => $preview['preview_token'],
    ])->assertUnprocessable();
    $this->postJson(route('admin.tools.open-documents.preview'), $selection)
        ->assertOk()->assertJsonPath('documents.0.decision', 'already_corrected');
    expect(InventoryTransaction::query()->where('posting_key', 'purchase-receipt:'.$receipt->lines->first()->getKey().':reversal')->count())->toBe(1);
});

test('Open Document previews and reverses a posted supplier invoice with its journal', function (): void {
    $fixture = procurementFixture();
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111001', 'Synthetic Supplier Payable');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();
    foreach (['purchase_invoices.reverse', 'purchase_invoices.view', 'purchases.prices.view', 'supplier_payments.view', 'supplier_payments.cancel'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->approve($service->create([
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'purchase_type' => 'direct',
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Synthetic direct procurement exception.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'unit_price' => 10,
        ]],
        'payment_schedules' => [[
            'due_date' => now()->toDateString(),
            'amount' => 20,
            'payment_source_type' => PurchaseInvoice::SourceScheduled,
        ]],
    ])['record']);
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111981', 'Synthetic Cashbox');
    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $administrativeBranch->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Synthetic Supplier Payment Cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $cashbox->getKey(), 'currency_id' => $fixture['currency']->getKey(),
        'is_default' => true, 'status' => 'active',
    ]);
    $draftPayment = app(ProcurementSettlementService::class)->createSupplierPayment([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'payment_date' => now()->toDateString(),
        'cashbox_doc_num' => $cashbox->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'amount' => 5,
        'reason' => 'Synthetic unpaid draft allocation',
        'allocations' => [[
            'purchase_invoice_doc_num' => $invoice->doc_num,
            'amount' => 5,
        ]],
    ]);
    $blockedPlan = $service->reversalPlan($invoice);
    expect($blockedPlan['can_reverse'])->toBeFalse()
        ->and($blockedPlan['dependent_documents'][__('open_documents.dependents.supplierPayments')])->toContain($draftPayment->doc_num);
    expect(fn () => $service->reverse($invoice, 'Draft payment remains allocated'))
        ->toThrow(DomainException::class, __('open_documents.corrections.invoice_payments_active'));

    $invoice->forceFill(['status' => PurchaseInvoice::StatusCancelled])->save();
    expect(fn () => app(ProcurementSettlementService::class)->approveSupplierPayment($draftPayment))
        ->toThrow(DomainException::class, __('open_documents.corrections.payment_invoice_inactive'));
    $invoice->forceFill(['status' => PurchaseInvoice::StatusApproved])->save();
    expect($draftPayment->fresh()->status)->toBe('draft');

    $this->actingAs($fixture['user'])->get(route('admin.purchases.supplier-payments.show', $draftPayment))
        ->assertOk()->assertSee(route('admin.purchases.supplier-payments.cancel', $draftPayment), false);
    $this->postJson(route('admin.purchases.supplier-payments.cancel', $draftPayment), [
        'cancel_reason' => 'Replace the invoice allocation before correction',
    ])->assertOk();
    expect($draftPayment->fresh()->status)->toBe('cancelled')
        ->and(Cashbox::query()->whereKey($cashbox->getKey())->exists())->toBeTrue()
        ->and(CashVoucher::query()->withTrashed()->findOrFail($draftPayment->cash_voucher_id)->trashed())->toBeTrue();

    $this->travelTo($fixture['period']->to_date->copy()->addDay());
    expect($service->reversalPlan($invoice)['can_reverse'])->toBeFalse();
    $this->travelBack();
    $selection = [
        'document_type' => 'purchase_invoices',
        'from_number' => $invoice->doc_number,
        'to_number' => $invoice->doc_number,
    ];

    $fixture['user']->revokePermissionTo('purchases.prices.view');
    $this->actingAs($fixture['user'])->postJson(route('admin.tools.open-documents.preview'), $selection)->assertForbidden();
    $fixture['user']->givePermissionTo('purchases.prices.view');
    $this->actingAs($fixture['user'])->get(route('admin.tools.open-documents.index'))
        ->assertOk()->assertSee('value="purchase_invoices"', false);
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), $selection)
        ->assertOk()->assertJsonPath('documents.0.decision', 'ready_reverse')
        ->assertJsonPath('documents.0.source_url', route('admin.purchases.purchase-invoices.show', $invoice))
        ->assertJsonPath('documents.0.current_total', '20.0000')
        ->assertJsonPath('documents.0.correction_lines.0.value_delta', '-20.0000')
        ->json();
    $this->postJson(route('admin.tools.open-documents.store'), [
        ...$selection, 'reason' => 'Synthetic supplier invoice correction',
        'preview_token' => $preview['preview_token'],
    ])->assertOk()->assertJsonPath('summary.opened', 1);

    expect($invoice->fresh()->status)->toBe(PurchaseInvoice::StatusCancelled)
        ->and($invoice->fresh()->reversal_reason)->toBe('Synthetic supplier invoice correction')
        ->and($invoice->fresh()->reversal_journal_entry_id)->not->toBeNull()
        ->and($invoice->fresh()->journalEntry->reversed_entry_id)->toBe($invoice->fresh()->reversal_journal_entry_id)
        ->and($invoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($invoice->fresh()->paymentSchedules()->sole()->status)->toBe(PurchaseInvoicePaymentSchedule::StatusCancelled);
    $this->postJson(route('admin.tools.open-documents.store'), [
        ...$selection, 'reason' => 'Duplicate correction',
        'preview_token' => $preview['preview_token'],
    ])->assertUnprocessable();
});

test('Open Document screen and execute action require their permissions', function (): void {
    $viewer = openDocumentsActor(['tools.open_documents.view']);
    $executorOnly = openDocumentsActor(['tools.open_documents.execute']);

    $this->actingAs($executorOnly)
        ->get(route('admin.tools.open-documents.index'))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'opening_balances',
            'from_number' => 1,
            'to_number' => 1,
        ])
        ->assertForbidden();
});

test('Open Document requires a reviewed token before executing an opening-balance action', function (): void {
    openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);

    $this->actingAs($actor)->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'opening_balances',
        'from_number' => 1,
        'to_number' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
});

test('sales reopen permission grants only its own type on the central screen', function (): void {
    openDocumentsContext($this);
    $actor = openDocumentsActor(['sales_orders.reopen']);
    $menu = app(MenuService::class)->getMenu($actor);
    $tools = collect($menu)->firstWhere('label', 'tools');
    $filesAndDocuments = collect($tools['children'] ?? [])->firstWhere('label', 'files_documents');
    $openDocuments = collect($filesAndDocuments['children'] ?? [])->firstWhere('label', 'open_documents');

    expect($openDocuments)->not->toBeNull();

    $this->actingAs($actor)
        ->get(route('admin.tools.open-documents.index'))
        ->assertOk()
        ->assertSee('value="sales_orders"', false)
        ->assertDontSee('value="opening_balances"', false)
        ->assertDontSee('value="customer_invoices"', false);

    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'opening_balances',
        'from_number' => 1,
        'to_number' => 1,
    ])->assertForbidden();

    openDocumentsConfirm($this, [
        'document_type' => 'sales_orders',
        'from_number' => 1,
        'to_number' => 1,
        'reason' => 'Correct this approved sales order.',
    ])->assertOk()->assertJsonPath('summary.total_found', 0);
});

test('Open Document form exposes opening documents and hides unauthorized workflows', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view']);

    $this->actingAs($actor)
        ->get(route('admin.tools.open-documents.index'))
        ->assertOk()
        ->assertSee('value="opening_balances"', false)
        ->assertSee('value="opening_stocks"', false)
        ->assertSee('value="opening_stock_pricings"', false)
        ->assertDontSee('value="production_material_requests"', false)
        ->assertDontSee('value="customers"', false);
});

test('Open Document form preselects an authorized document linked from its detail page', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view', 'sales_orders.reopen']);

    $this->actingAs($actor)
        ->get(route('admin.tools.open-documents.index', [
            'document_type' => 'sales_orders',
            'from_number' => 57,
            'to_number' => 57,
        ]))
        ->assertOk()
        ->assertSee('value="sales_orders" selected', false)
        ->assertSee('name="from_number"', false)
        ->assertSee('name="to_number"', false)
        ->assertSee('value="57"', false);
});

test('Open Document form does not show stale validation errors before submission', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view']);

    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag([
        'document_type' => 'Error from a previous screen',
        'from_number' => 'Error from a previous screen',
        'to_number' => 'Error from a previous screen',
    ]));

    $this->actingAs($actor)
        ->withSession(['errors' => $errors])
        ->get(route('admin.tools.open-documents.index'))
        ->assertOk()
        ->assertDontSee('Error from a previous screen')
        ->assertDontSee('is-invalid', false);
});

test('Open Document rejects invalid document type and reversed ranges', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);

    $this->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'customers',
            'from_number' => 1,
            'to_number' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_type']);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $context['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $context['company']->doc_num,
        OperatingContextService::BranchIdKey => $context['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $context['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $context['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $context['period']->doc_num,
    ])->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'opening_balances',
            'from_number' => 5,
            'to_number' => 3,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from_number']);
});

test('Open Document limits a posted-invoice batch before any financial reversal', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute', 'customer_invoices.reopen']);

    $this->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'customer_invoices',
            'from_number' => 1,
            'to_number' => 11,
            'reason' => 'Correct invoice descriptions.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to_number');
});

test('Open Document reopens only closed unapproved Opening Balances in current company and period', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);
    $otherCompanyContext = openDocumentsContext($this);
    $otherPeriod = openDocumentsPeriod($context['company']);

    $eligible = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 10);
    $approved = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 11, [
        'approved' => true,
        'approved_at' => now(),
        'approved_by' => $actor->getKey(),
        'status' => OpeningBalance::StatusApproved,
    ]);
    $alreadyOpen = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 12, [
        'is_closed' => false,
    ]);
    $deleted = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 13);
    $deleted->delete();
    $otherCompany = openDocumentsOpeningBalance($otherCompanyContext['company'], $otherCompanyContext['period'], $otherCompanyContext['currency'], 14);
    $otherPeriodRecord = openDocumentsOpeningBalance($context['company'], $otherPeriod, $context['currency'], 15);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $context['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $context['company']->doc_num,
        OperatingContextService::BranchIdKey => $context['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $context['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $context['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $context['period']->doc_num,
    ])->actingAs($actor);
    openDocumentsConfirm($this, [
        'document_type' => 'opening_balances',
        'from_number' => 10,
        'to_number' => 15,
    ])
        ->assertOk()
        ->assertJsonPath('summary.total_found', 4)
        ->assertJsonPath('summary.opened', 1)
        ->assertJsonPath('summary.skipped_approved', 1)
        ->assertJsonPath('summary.skipped_already_open', 1)
        ->assertJsonPath('summary.skipped_deleted', 1)
        ->assertJsonPath('summary.not_found', 2)
        ->assertJsonMissingPath('id')
        ->assertJsonMissingPath('summary.id');

    expect($eligible->refresh()->is_closed)->toBeFalse()
        ->and($eligible->status)->toBe(OpeningBalance::StatusDraft)
        ->and($approved->refresh()->is_closed)->toBeTrue()
        ->and($approved->approved)->toBeTrue()
        ->and($alreadyOpen->refresh()->is_closed)->toBeFalse()
        ->and(OpeningBalance::withTrashed()->findOrFail($deleted->getKey())->is_closed)->toBeTrue()
        ->and($otherCompany->refresh()->is_closed)->toBeTrue()
        ->and($otherPeriodRecord->refresh()->is_closed)->toBeTrue();

    $activity = Activity::query()->where('event', 'tools.open_documents.open')->first();
    $properties = $activity?->properties?->toArray() ?? [];

    expect($properties['record']['doc_num'] ?? null)->toBe($eligible->doc_num)
        ->and($properties['meta']['document_number'] ?? null)->toBe((int) $eligible->doc_number)
        ->and($properties)->not->toHaveKey('id')
        ->and($properties['record'] ?? [])->not->toHaveKey('id');
});

test('Open Document keeps a generic opening document closed when its financial period is closed', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);
    $opening = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 88);
    $context['period']->forceFill(['is_closed' => true])->save();

    $this->actingAs($actor);
    openDocumentsConfirm($this, [
        'document_type' => 'opening_balances',
        'from_number' => 88,
        'to_number' => 88,
    ])->assertUnprocessable();
    expect($opening->fresh()->is_closed)->toBeTrue();
});

test('Open Document reopens Opening Stock across all branches in current company and period', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);
    $otherBranch = openDocumentsBranch($context['company'], Branch::TypeWarehouse);
    $otherPeriod = openDocumentsPeriod($context['company']);

    $branchDocument = openDocumentsOpeningStock($context['company'], $context['period'], $otherBranch, 20);
    $approved = openDocumentsOpeningStock($context['company'], $context['period'], $context['branch'], 21, [
        'approved' => true,
        'approved_at' => now(),
        'approved_by' => $actor->getKey(),
        'status' => OpeningStock::StatusApproved,
    ]);
    $otherPeriodRecord = openDocumentsOpeningStock($context['company'], $otherPeriod, $otherBranch, 22);

    $this->actingAs($actor);
    openDocumentsConfirm($this, [
        'document_type' => 'opening_stocks',
        'from_number' => 20,
        'to_number' => 22,
    ])
        ->assertOk()
        ->assertJsonPath('summary.total_found', 2)
        ->assertJsonPath('summary.opened', 1)
        ->assertJsonPath('summary.skipped_approved', 1);

    expect($branchDocument->refresh()->is_closed)->toBeFalse()
        ->and($branchDocument->branch_id)->toBe($otherBranch->getKey())
        ->and($approved->refresh()->is_closed)->toBeTrue()
        ->and($approved->approved)->toBeTrue()
        ->and($otherPeriodRecord->refresh()->is_closed)->toBeTrue();
});

test('Open Document reopens Opening Stock Pricing across all branches in current company and period', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);
    $otherBranch = openDocumentsBranch($context['company'], Branch::TypeWarehouse);
    $otherCompanyContext = openDocumentsContext($this);

    $branchPricing = openDocumentsOpeningStockPricing($context['company'], $context['period'], $otherBranch, $context['currency'], 30);
    $otherCompanyPricing = openDocumentsOpeningStockPricing($otherCompanyContext['company'], $otherCompanyContext['period'], $otherBranch, $otherCompanyContext['currency'], 31);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $context['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $context['company']->doc_num,
        OperatingContextService::BranchIdKey => $context['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $context['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $context['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $context['period']->doc_num,
    ])->actingAs($actor);
    openDocumentsConfirm($this, [
        'document_type' => 'opening_stock_pricings',
        'from_number' => 30,
        'to_number' => 31,
    ])
        ->assertOk()
        ->assertJsonPath('summary.total_found', 1)
        ->assertJsonPath('summary.opened', 1);

    expect($branchPricing->refresh()->is_closed)->toBeFalse()
        ->and($branchPricing->status)->toBe(OpeningStockPricing::StatusDraft)
        ->and($branchPricing->branch_id)->toBe($otherBranch->getKey())
        ->and($otherCompanyPricing->refresh()->is_closed)->toBeTrue();
});

test('Open Document returns no-reopenable message when no documents are eligible', function (): void {
    openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);

    $this->actingAs($actor);
    openDocumentsConfirm($this, [
        'document_type' => 'opening_balances',
        'from_number' => 99,
        'to_number' => 99,
    ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('type', 'no_changes')
        ->assertJsonPath('message', __('open_documents.messages.none_reopenable'))
        ->assertJsonPath('summary.opened', 0);
});

test('Open Document routes approved sales order reopening through its workflow and enforces its permission', function (): void {
    $fixture = salesCycleFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute']);
    $actor->revokePermissionTo('sales_orders.reopen');
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertDontSee('value="sales_orders"', false);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number, 'reason' => 'Correct the agreed quantity.',
    ])->assertForbidden();
    expect($order->fresh()->status)->toBe(SalesOrder::StatusApproved);

    $actor->givePermissionTo('sales_orders.reopen');
    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertSee('value="sales_orders"', false);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number,
    ])->assertInvalid('reason');
    openDocumentsConfirm($this, [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number, 'reason' => 'Correct the agreed quantity.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);

    $reopened = $order->fresh();
    expect($reopened->status)->toBe(SalesOrder::StatusReopened)
        ->and($reopened->reopen_reason)->toBe('Correct the agreed quantity.')
        ->and($reopened->canCancelSafely())->toBeFalse();
    Permission::findOrCreate('sales_orders.cancel', 'web');
    $actor->givePermissionTo('sales_orders.cancel');
    $this->postJson(route('admin.sales.sales-orders.cancel', $order), ['reason' => 'Delete the previously approved order.'])->assertUnprocessable();
    expect($order->fresh()->status)->toBe(SalesOrder::StatusReopened);

    $orders = app(SalesOrderService::class);
    $orders->approve($orders->submit($order->fresh()));
    $order->forceFill(['status' => SalesOrder::StatusReopened])->save();
    expect($order->fresh()->isEditable())->toBeFalse()
        ->and(fn () => $orders->submit($order->fresh()))->toThrow(DomainException::class);
});

test('a previously approved sales order cannot be deleted when a stale state reports draft', function (): void {
    $fixture = salesCycleFixture();
    $actor = $fixture['user'];
    foreach (['sales_orders.delete', 'sales_orders.view', 'sales_orders.edit', 'sales_orders.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['sales_orders.delete', 'sales_orders.view', 'sales_orders.edit', 'sales_orders.reopen']);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));

    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture)));
    $order->forceFill(['status' => SalesOrder::StatusDraft])->save();

    expect($order->fresh()->isEditable())->toBeFalse();
    $this->postJson(route('admin.tools.open-documents.preview'), [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number,
        'reason' => 'Review the inconsistent state.',
    ])->assertOk()->assertJsonPath('documents.0.decision', 'blocked');
    $this->get(route('admin.sales.sales-orders.edit', $order))->assertConflict();
    expect(fn () => $orders->submit($order->fresh()))->toThrow(DomainException::class);
    expect(fn () => $orders->delete($order->fresh()))->toThrow(DomainException::class)
        ->and($order->fresh()->trashed())->toBeFalse();
    $this->deleteJson(route('admin.sales.sales-orders.destroy', $order))->assertConflict();
    $actions = $this->getJson(route('admin.sales.sales-orders.index', ['draw' => 1, 'length' => 10, 'start' => 0]))
        ->assertOk()->json('data.0.actions');
    expect($order->fresh()->trashed())->toBeFalse()
        ->and($actions)->not->toContain('data-method="DELETE"');
});

test('Open Document previews a scoped sales order and refuses a stale confirmation', function (): void {
    $fixture = salesCycleFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen', 'sales_orders.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen', 'sales_orders.view']);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $selection = [
        'document_type' => 'sales_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
        'reason' => 'Correct the approved quantity.',
    ];

    $preview = $this->postJson(route('admin.tools.open-documents.preview'), collect($selection)->except('reason')->all())->assertOk()
        ->assertJsonPath('documents.0.doc_num', $order->doc_num)
        ->assertJsonPath('documents.0.decision', 'ready')
        ->assertJsonPath('documents.0.source_url', route('admin.sales.sales-orders.show', $order))
        ->json();
    expect($preview['preview_token'])->toBeString()->not->toBeEmpty();

    $order->forceFill(['notes' => 'Concurrent correction'])->save();
    $this->postJson(route('admin.tools.open-documents.store'), [
        ...$selection,
        'preview_token' => $preview['preview_token'],
    ])->assertInvalid('document_type');
    expect($order->fresh()->status)->toBe(SalesOrder::StatusApproved);

    $currentPreview = $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()->json();
    $this->postJson(route('admin.tools.open-documents.store'), [
        ...$selection,
        'preview_token' => $currentPreview['preview_token'],
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($order->fresh()->status)->toBe(SalesOrder::StatusReopened);
});

test('Open Document cannot reopen a sales order in a closed financial period', function (): void {
    $fixture = salesCycleFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen']);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $fixture['period']->forceFill(['is_closed' => true])->save();

    openDocumentsConfirm($this, [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number, 'reason' => 'Correct the agreed quantity.',
    ])->assertUnprocessable();
    expect($order->fresh()->status)->toBe(SalesOrder::StatusApproved);
});

test('Open Document delegates sales request and posted invoice reopening to their audited workflows', function (): void {
    $fixture = salesCycleFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'sales_requests.reopen', 'customer_invoices.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'sales_requests.reopen', 'customer_invoices.reopen']);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));

    $requestService = app(SalesRequestService::class);
    $salesRequest = $requestService->save([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'request_date' => now()->toDateString(),
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity' => '2',
        ]],
    ]);
    $requestService->transition($salesRequest, 'submitted');
    $requestService->transition($salesRequest->fresh(), SalesRequest::StatusApproved);

    $salesRequest->forceFill(['status' => SalesRequest::StatusDraft])->save();
    expect($salesRequest->fresh()->isEditable())->toBeFalse();
    $salesRequest->forceFill(['status' => SalesRequest::StatusApproved])->save();

    openDocumentsConfirm($this, [
        'document_type' => 'sales_requests', 'from_number' => $salesRequest->doc_number,
        'to_number' => $salesRequest->doc_number, 'reason' => 'Correct the customer request.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($salesRequest->fresh()->status)->toBe(SalesRequest::StatusReopened);

    $invoice = salesPostedServiceInvoice($fixture, '100.0000');
    $originalJournalId = (int) $invoice->journal_entry_id;
    $originalLines = DB::table('journal_entry_lines')->where('journal_entry_id', $originalJournalId)->get();
    $invoicePreview = $this->postJson(route('admin.tools.open-documents.preview'), [
        'document_type' => 'customer_invoices', 'from_number' => $invoice->doc_number,
        'to_number' => $invoice->doc_number,
    ])->assertOk()->assertJsonPath('documents.0.decision', 'ready')
        ->assertJsonPath('documents.0.current_total', '100.0000')
        ->assertJsonPath('documents.0.source_url', null)
        ->json('documents.0');
    expect($invoicePreview['posting_effect'])->toBe(__('open_documents.effects.reverse_original_journal'));
    openDocumentsConfirm($this, [
        'document_type' => 'customer_invoices', 'from_number' => $invoice->doc_number,
        'to_number' => $invoice->doc_number, 'reason' => 'Correct the service description.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    $reopenedInvoice = $invoice->fresh();
    expect($reopenedInvoice->status)->toBe(CustomerInvoice::StatusReopened)
        ->and($reopenedInvoice->is_closed)->toBeFalse()
        ->and($reopenedInvoice->reversal_journal_entry_id)->not->toBeNull();
    $reversalJournal = DB::table('journal_entries')->find($reopenedInvoice->reversal_journal_entry_id);
    $originalJournal = DB::table('journal_entries')->find($originalJournalId);
    $reversalLines = DB::table('journal_entry_lines')->where('journal_entry_id', $reversalJournal->id)->get();

    expect((int) $originalJournal->reversed_entry_id)->toBe((int) $reversalJournal->id)
        ->and((int) $reversalJournal->company_id)->toBe((int) $originalJournal->company_id)
        ->and((int) $reversalJournal->branch_id)->toBe((int) $originalJournal->branch_id)
        ->and((int) $reversalJournal->financial_period_id)->toBe((int) $originalJournal->financial_period_id)
        ->and($reversalLines)->toHaveCount($originalLines->count());
    foreach ($originalLines as $originalLine) {
        $reversalLine = $reversalLines->firstWhere('account_id', $originalLine->account_id);
        expect($reversalLine)->not->toBeNull()
            ->and((string) $reversalLine->debit_amount)->toBe((string) $originalLine->credit_amount)
            ->and((string) $reversalLine->credit_amount)->toBe((string) $originalLine->debit_amount);
    }
});

test('Open Document reopens an unsent purchase order and blocks orders with downstream commitments', function (): void {
    $fixture = procurementFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'purchase_orders.reopen', 'purchase_orders.view', 'purchase_orders.cancel', 'purchases.prices.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'purchase_orders.reopen', 'purchase_orders.view', 'purchase_orders.cancel', 'purchases.prices.view']);
    $this->actingAs($actor);

    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'document_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Test direct purchasing.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 5,
            'unit_price' => 10,
            'tax_rate' => 0,
        ]],
    ])['record']);

    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertSee('value="purchase_orders"', false);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
    ])->assertInvalid('reason');
    openDocumentsConfirm($this, [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
        'reason' => 'Correct the requested quantity.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);

    expect($order->fresh()->status)->toBe(PurchaseOrder::StatusDraft)
        ->and($order->fresh()->approved_at)->not->toBeNull()
        ->and($order->fresh()->isDeletable())->toBeFalse()
        ->and(Activity::query()->where('event', 'purchase_order.reopened')->exists())->toBeTrue();
    expect(fn () => $orders->delete($order->fresh()))->toThrow(DomainException::class);
    expect(fn () => $orders->cancel($order->fresh(), 'Do not cancel an approved order after reopening.'))->toThrow(DomainException::class)
        ->and($order->fresh()->status)->toBe(PurchaseOrder::StatusDraft);
    $this->get(route('admin.purchases.purchase-orders.show', $order->doc_num))
        ->assertOk()
        ->assertDontSee(route('admin.purchases.purchase-orders.cancel', $order->doc_num), false);

    $order = $orders->approve($order->fresh());
    $order->forceFill(['sent_at' => now(), 'sent_by' => $actor->getKey()])->save();
    openDocumentsConfirm($this, [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
        'reason' => 'Unsafe after supplier dispatch.',
    ])->assertOk()->assertJsonPath('summary.opened', 0)->assertJsonPath('summary.skipped_blocked', 1);
    expect($order->fresh()->status)->toBe(PurchaseOrder::StatusApproved);

    $order->forceFill(['sent_at' => null, 'sent_by' => null])->save();
    $inspection = GoodsReceiptInspection::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'GRI-REOPEN-BLOCK',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'purchase_order_id' => $order->getKey(),
        'inspection_at' => now(),
    ]);
    expect($order->fresh()->canReopenSafely())->toBeFalse();
    $blockedPreview = $this->postJson(route('admin.tools.open-documents.preview'), [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
    ])->assertOk()->assertJsonPath('documents.0.decision', 'blocked')->json('documents.0.dependent_documents');
    expect(collect($blockedPreview)->flatten()->all())->toContain('GRI-REOPEN-BLOCK');
    $inspection->delete();
    expect($order->fresh()->canReopenSafely())->toBeFalse();
    expect(fn () => $orders->cancel($order->fresh(), 'The inspection was deleted.'))->toThrow(DomainException::class);
    $inspection->forceDelete();

    $changeRequest = PurchaseOrderChangeRequest::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'POCR-REOPEN-BLOCK',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'purchase_order_id' => $order->getKey(),
        'request_date' => now()->toDateString(),
        'original_values' => [],
        'requested_values' => ['notes' => 'stale'],
        'reason' => 'Pending amendment.',
    ]);
    expect($order->fresh()->canReopenSafely())->toBeFalse();
    $changeRequest->forceFill(['status' => 'rejected'])->save();
    expect($order->fresh()->canReopenSafely())->toBeTrue();

    $orders->close($order->fresh());
    openDocumentsConfirm($this, [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
        'reason' => 'Revise a closed order without discarding its history.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($order->fresh()->closed_at)->not->toBeNull()
        ->and($order->fresh()->isDeletable())->toBeFalse();
    expect(fn () => $orders->cancel($order->fresh(), 'Do not cancel a previously closed order.'))->toThrow(DomainException::class)
        ->and(fn () => $orders->delete($order->fresh()))->toThrow(DomainException::class);
});

test('Open Document reopens a purchase requisition and preserves downstream sourcing locks', function (): void {
    $fixture = procurementFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'purchases.purchase_requisitions.reopen', 'purchases.purchase_requisitions.view', 'purchases.purchase_requisitions.cancel'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'purchases.purchase_requisitions.reopen', 'purchases.purchase_requisitions.view', 'purchases.purchase_requisitions.cancel']);
    $this->actingAs($actor);

    $sourcing = app(ProcurementSourcingService::class);
    $requisition = procurementManualRequisition($fixture);
    $sourcing->submitRequisition($requisition);
    $sourcing->approveRequisition($requisition->fresh());

    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertSee('value="purchase_requisitions"', false);
    openDocumentsConfirm($this, [
        'document_type' => 'purchase_requisitions',
        'from_number' => $requisition->doc_number,
        'to_number' => $requisition->doc_number,
        'reason' => 'Correct the source request.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($requisition->fresh()->status)->toBe(PurchaseRequisition::StatusDraft)
        ->and($requisition->fresh()->approved_at)->not->toBeNull()
        ->and((string) $requisition->fresh()->lines()->firstOrFail()->approved_quantity)->toBe('0.00000000');
    expect(fn () => $sourcing->deleteRequisition($requisition->fresh()))->toThrow(DomainException::class);
    expect(fn () => $sourcing->finishRequisition($requisition->fresh(), PurchaseRequisition::StatusCancelled, 'Do not cancel an approved request after reopening.'))->toThrow(DomainException::class)
        ->and($requisition->fresh()->status)->toBe(PurchaseRequisition::StatusDraft);
    $this->get(route('admin.purchases.purchase-requisitions.show', $requisition->doc_num))
        ->assertOk()
        ->assertDontSee(route('admin.purchases.purchase-requisitions.cancel', $requisition->doc_num), false);

    $sourcing->submitRequisition($requisition->fresh());
    $sourcing->approveRequisition($requisition->fresh());
    PurchaseOrder::query()->create([
        'doc_number' => 99005,
        'doc_num' => 'PO-REOPEN-REQ-BLOCK',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'supplier_id' => $fixture['firstSupplier']->getKey(),
        'document_date' => now()->toDateString(),
        'purchase_requisition_id' => $requisition->getKey(),
    ]);
    openDocumentsConfirm($this, [
        'document_type' => 'purchase_requisitions',
        'from_number' => $requisition->doc_number,
        'to_number' => $requisition->doc_number,
        'reason' => 'Unsafe after an order.',
    ])->assertOk()->assertJsonPath('summary.opened', 0)->assertJsonPath('summary.skipped_blocked', 1);
    expect($requisition->fresh()->status)->toBe(PurchaseRequisition::StatusApproved);
    expect(fn () => $sourcing->finishRequisition($requisition->fresh(), PurchaseRequisition::StatusCancelled, 'Attempt to cancel a converted request.'))->toThrow(DomainException::class)
        ->and($requisition->fresh()->status)->toBe(PurchaseRequisition::StatusApproved);

    $closedRequisition = procurementManualRequisition($fixture);
    $sourcing->submitRequisition($closedRequisition);
    $sourcing->approveRequisition($closedRequisition->fresh());
    $sourcing->finishRequisition($closedRequisition->fresh(), PurchaseRequisition::StatusClosed);
    openDocumentsConfirm($this, [
        'document_type' => 'purchase_requisitions',
        'from_number' => $closedRequisition->doc_number,
        'to_number' => $closedRequisition->doc_number,
        'reason' => 'Revise the closed request.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($closedRequisition->fresh()->status)->toBe(PurchaseRequisition::StatusDraft)
        ->and($closedRequisition->fresh()->closed_at)->not->toBeNull();
    expect(fn () => $sourcing->finishRequisition($closedRequisition->fresh(), PurchaseRequisition::StatusCancelled, 'No longer needed.'))->toThrow(DomainException::class)
        ->and(fn () => $sourcing->deleteRequisition($closedRequisition->fresh()))->toThrow(DomainException::class);
    $this->get(route('admin.purchases.purchase-requisitions.edit', $closedRequisition->doc_num))->assertForbidden();
    Permission::findOrCreate('purchases.purchase_requisitions.edit', 'web');
    $actor->givePermissionTo('purchases.purchase_requisitions.edit');
    $this->get(route('admin.purchases.purchase-requisitions.edit', $closedRequisition->doc_num))->assertOk();
    $closedLine = $closedRequisition->fresh()->lines()->firstOrFail();
    $requester = HrEmployee::query()->create([
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'doc_number' => 99081, 'doc_num' => 'HR-SYNTHETIC-REQ-99081',
        'full_name' => 'Synthetic purchase requester', 'name' => 'Synthetic purchase requester', 'status' => 'active',
    ]);
    $this->putJson(route('admin.purchases.purchase-requisitions.update', $closedRequisition->doc_num), [
        'requester_employee_id' => $requester->getKey(),
        'request_date' => now()->toDateString(), 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [[
            'public_id' => $closedLine->public_id, 'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => '125.5', 'source_type' => 'manual',
        ]],
    ])->assertOk();
    expect($closedLine->fresh()->requested_quantity)->toBe('125.50000000')
        ->and($closedLine->fresh()->approved_quantity)->toBe('0.00000000');
    $sourcing->submitRequisition($closedRequisition->fresh());
    $sourcing->approveRequisition($closedRequisition->fresh());
    expect($closedRequisition->fresh()->isLockedForEditing())->toBeTrue()
        ->and($closedLine->fresh()->approved_quantity)->toBe('125.50000000');
});

/** @param array<string, mixed> $fixture */
function openDocumentsProductionRun(array $fixture, int $number, string $suffix): ProductionRun
{
    $order = ProductionOrder::query()->firstOrCreate([
        'company_id' => $fixture['company']->id, 'doc_number' => $number,
    ], [
        'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'doc_num' => 'SYNTHETIC-OPEN-PROD-'.$number, 'production_order_date' => '2026-09-01',
        'source_type' => 'make_to_stock', 'status' => ProductionOrder::StatusReleased,
    ]);
    $line = $order->lines()->firstOrCreate(['line_number' => 1], [
        'product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id,
        'description' => 'SYNTHETIC routing acceptance', 'quantity' => '10', 'base_quantity' => '10',
    ]);

    return ProductionRun::query()->create([
        'company_id' => $order->company_id, 'financial_period_id' => $order->financial_period_id, 'branch_id' => $order->branch_id,
        'production_order_id' => $order->id, 'production_order_line_id' => $line->id,
        'product_id' => $line->product_id, 'unit_id' => $line->unit_id,
        'run_number' => 'SYNTHETIC-OPEN-RUN-'.$suffix, 'status' => ProductionRun::StatusCompleted,
        'planned_quantity' => '10', 'planned_base_quantity' => '10',
        'planned_start_at' => '2026-09-01 08:00:00', 'planned_end_at' => '2026-09-01 18:00:00',
    ]);
}

test('Open Document finds every production run by its order number and routes to the actual correction without mutation', function (string $permission): void {
    $fixture = salesCycleFixture();
    $runs = [openDocumentsProductionRun($fixture, 71, 'A'), openDocumentsProductionRun($fixture, 71, 'B')];
    $otherBranch = $fixture['branch']->replicate();
    $otherBranch->fill(['doc_number' => 9903, 'doc_num' => 'SYNTHETIC-OPEN-OTHER-BRANCH', 'name' => 'SYNTHETIC other branch'])->save();
    openDocumentsProductionRun([...$fixture, 'branch' => $otherBranch], 72, 'FOREIGN-BRANCH');
    $otherPeriod = $fixture['period']->replicate();
    $otherPeriod->fill(['doc_number' => 9904, 'doc_num' => 'SYNTHETIC-OPEN-OTHER-PERIOD', 'name' => 'SYNTHETIC other period'])->save();
    openDocumentsProductionRun([...$fixture, 'period' => $otherPeriod], 73, 'FOREIGN-PERIOD');
    $otherCompany = $fixture['company']->replicate();
    $otherCompany->fill(['doc_number' => 9905, 'doc_num' => 'SYNTHETIC-OPEN-OTHER-COMPANY', 'name' => 'SYNTHETIC other company', 'is_main' => false])->save();
    openDocumentsProductionRun([...$fixture, 'company' => $otherCompany], 71, 'FOREIGN-COMPANY');
    $actor = openDocumentsActor([$permission]);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));
    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertSee('production_runs', false);
    $selection = ['document_type' => 'production_runs', 'from_number' => 71, 'to_number' => 73];
    $response = $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()
        ->assertJsonPath('navigation_only', true)->assertJsonPath('not_found', 2)->assertJsonCount(2, 'documents');
    foreach ($runs as $index => $run) {
        $url = route('admin.production.runs.corrections.index', $run);
        $response->assertJsonPath('documents.'.$index.'.doc_num', $run->run_number)
            ->assertJsonPath('documents.'.$index.'.doc_number', 71)->assertJsonPath('documents.'.$index.'.correction_url', $url);
        $this->get($url)->assertOk();
    }
    $before = [InventoryTransaction::count(), DB::table('journal_entries')->count(), $runs[0]->fresh()->status];
    $this->postJson(route('admin.tools.open-documents.store'), [...$selection, 'preview_token' => $response->json('preview_token')])
        ->assertUnprocessable()->assertJsonValidationErrors('document_type');
    expect([InventoryTransaction::count(), DB::table('journal_entries')->count(), $runs[0]->fresh()->status])->toBe($before);
    $fixture['period']->update(['is_closed' => true]);
    $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()
        ->assertJsonPath('documents.0.decision', 'closed_period')->assertJsonPath('documents.0.correction_url', null);
    $this->actingAs(openDocumentsActor(['tools.open_documents.execute']))->withSession(salesCycleSession($fixture));
    $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertForbidden();
})->with(['prepare' => 'production.runs.correct', 'independent approval' => 'production.runs.correct_approve']);

test('Open Document routes each sales return state only with its own correction permission', function (string $status, string $permission): void {
    $fixture = salesCycleFixture();
    $return = SalesReturn::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'customer_id' => $fixture['customer']->id, 'currency_id' => $fixture['currency']->id,
        'doc_number' => 81, 'doc_num' => 'SYNTHETIC-OPEN-RETURN-81', 'return_date' => '2026-09-01',
        'status' => $status, 'reason_code' => SalesReturn::ReasonOrderEntry, 'total_amount' => '22.5000',
    ]);
    $foreign = $return->replicate();
    $otherBranch = $fixture['branch']->replicate();
    $otherBranch->fill(['doc_number' => 9903, 'doc_num' => 'SYNTHETIC-OPEN-RETURN-BRANCH', 'name' => 'SYNTHETIC return other branch'])->save();
    $foreign->fill(['branch_id' => $otherBranch->id, 'doc_number' => 82, 'doc_num' => 'SYNTHETIC-OPEN-RETURN-82'])->save();
    $actor = openDocumentsActor(['sales_returns.view', $permission]);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));
    $selection = ['document_type' => 'sales_returns', 'from_number' => 81, 'to_number' => 82];
    $url = route('admin.sales.sales-returns.show', $return);
    $response = $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()->assertJsonCount(1, 'documents')
        ->assertJsonPath('navigation_only', true)->assertJsonPath('not_found', 1)->assertJsonPath('documents.0.correction_url', $url);
    $this->get($url)->assertOk();
    $before = [InventoryTransaction::count(), DB::table('journal_entries')->count(), $return->fresh()->status];
    $this->postJson(route('admin.tools.open-documents.store'), [...$selection, 'preview_token' => $response->json('preview_token')])
        ->assertUnprocessable()->assertJsonValidationErrors('document_type');
    expect([InventoryTransaction::count(), DB::table('journal_entries')->count(), $return->fresh()->status])->toBe($before);
    $wrongPermission = $permission === 'sales_returns.correct_receipt' ? 'sales_returns.correct_closed' : 'sales_returns.correct_receipt';
    $this->actingAs(openDocumentsActor(['sales_returns.view', $wrongPermission]))->withSession(salesCycleSession($fixture));
    $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()->assertJsonPath('documents.0.correction_url', null);
    $this->actingAs(openDocumentsActor([$permission]))->withSession(salesCycleSession($fixture));
    $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertForbidden();
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));
    $fixture['period']->update(['is_closed' => true]);
    if ($status === SalesReturn::StatusClosed) {
        $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()
            ->assertJsonPath('documents.0.correction_url', null)->assertJsonPath('documents.0.decision', 'closed_period');
        $actor->givePermissionTo(Permission::findOrCreate('sales_returns.correct_later_period', 'web'));
        $actor->givePermissionTo(Permission::findOrCreate('sales_returns.correct_prepare', 'web'));
        $this->actingAs($actor)->withSession(salesCycleSession($fixture));
        $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()
            ->assertJsonPath('documents.0.correction_url', route('admin.sales.sales-returns.corrections.index', $return));
    } else {
        $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()
            ->assertJsonPath('documents.0.correction_url', null)->assertJsonPath('documents.0.decision', 'closed_period');
    }
    $return->delete();
    $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()
        ->assertJsonPath('documents.0.decision', 'deleted')->assertJsonPath('documents.0.correction_url', null);
})->with([
    'received' => [SalesReturn::StatusReceived, 'sales_returns.correct_receipt'],
    'inspected' => [SalesReturn::StatusInspected, 'sales_returns.correct_disposition'],
    'closed' => [SalesReturn::StatusClosed, 'sales_returns.correct_closed'],
]);
