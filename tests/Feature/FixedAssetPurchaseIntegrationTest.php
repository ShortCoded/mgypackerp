<?php

use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\FixedAssets\Services\FixedAssetBookValueService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

test('a purchased fixed asset keeps one accounting recognition and separately printable settlements', function (): void {
    $this->withoutExceptionHandling();
    Storage::fake('public');
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111095', 'Asset Supplier Payable');
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111095', 'Asset Purchase Cash');
    $bankGlAccount = procurementPostingAccount($fixture['company'], '1112', '1112095', 'Asset Purchase Bank');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();

    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Fixed Asset Cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $cashbox->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'is_default' => true,
        'status' => 'active',
    ]);
    $bankParent = Account::query()
        ->where('company_id', $fixture['company']->getKey())
        ->where('account_code', '1112')
        ->firstOrFail();
    $bankAccount = BankAccount::query()->create([
        'doc_number' => 9905,
        'doc_num' => 'BANK-ASSET-PURCHASE',
        'company_id' => $fixture['company']->getKey(),
        'bank_id' => $bankParent->getKey(),
        'account_id' => $bankGlAccount->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'account_name' => 'Fixed Asset Operating Account',
        'account_number' => 'ASSET-001',
        'status' => 'active',
    ]);
    $assetProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9195,
        'doc_num' => 'Product-FIXED-ASSET-PROC',
        'name' => 'Factory Cutting Machine',
        'item_classification' => Product::ClassificationOther,
        'item_unit_id' => $fixture['unit']->getKey(),
        'cost_as_inventory' => false,
        'status' => 'active',
    ]);
    $assetCategory = app(BusinessPartnerAccountService::class)->createGroup(
        BusinessPartnerAccountService::FixedAsset,
        'Purchased Production Machines',
    );

    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $invoices = app(PurchaseInvoiceService::class);
    $settlement = app(ProcurementSettlementService::class);

    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Approved capital purchase',
        'lines' => [[
            'product_doc_num' => $assetProduct->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 1,
            'unit_price' => 1000,
        ]],
    ])['record']);
    $orderLine = $order->lines->sole();
    $receipt = $receiving->postReceipt($receiving->createReceipt($order, [
        'document_date' => now()->toDateString(),
        'supplier_delivery_note' => 'ASSET-DELIVERY-1',
        'lines' => [[
            'purchase_order_line_public_id' => $orderLine->public_id,
            'delivered_quantity' => 1,
        ]],
    ]));
    $receiptLine = $receipt->lines()->firstOrFail();

    expect($receiptLine->accepted_quantity)->toBe('1.00000000')
        ->and($receiptLine->inventory_posted_quantity)->toBe('0.00000000')
        ->and(InventoryTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);

    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    $this->getJson(route('admin.purchases.select2.receipts', [
        'purpose' => 'invoice',
        'purchase_order' => $order->doc_num,
    ]))->assertOk()->assertJsonPath('results.0.id', $receipt->doc_num);
    procurementUseBranch($fixture, $fixture['branch']);

    $invoice = $invoices->create([
        'purchase_order_doc_num' => $order->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'supplier_invoice_number' => 'SUPPLIER-ASSET-1',
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'lines' => [[
            'product_doc_num' => $assetProduct->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $orderLine->public_id,
            'receipt_line_public_id' => $receiptLine->public_id,
            'quantity' => 1,
            'unit_price' => 1000,
        ]],
    ])['record'];
    $invoiceLine = $invoice->lines->sole();
    $readyForUseDate = now()->addDay()->toDateString();
    $depreciationStartDate = now()->addMonth()->startOfMonth()->toDateString();

    $this->actingAs($fixture['user'])
        ->get(route('admin.fixed-assets.assets.create', ['purchase_invoice_line' => $invoiceLine->public_id]))
        ->assertOk()
        ->assertSee($invoice->doc_num)
        ->assertSee($assetProduct->name);
    $this->postJson(route('admin.fixed-assets.assets.store'), [
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'source_type' => 'purchase_invoice_line',
        'source_id' => $invoiceLine->getKey(),
        'source_doc_num' => $invoice->doc_num,
        'asset_date' => now()->toDateString(),
        'asset_name' => 'Factory Cutting Machine Asset',
        'asset_group_account_doc_num' => $assetCategory->doc_num,
        'credit_account_doc_num' => $supplierAccount->doc_num,
        'branch_doc_num' => $fixture['branch']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'description' => 'Purchased through '.$invoice->doc_num,
        'purchase_date' => now()->toDateString(),
        'acquisition_date' => now()->toDateString(),
        'operation_date' => $readyForUseDate,
        'depreciation_start_date' => $depreciationStartDate,
        'purchase_value' => 1000,
        'exchange_rate' => 1,
        'salvage_value' => 0,
        'previous_depreciation' => 0,
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'useful_life' => 5,
        'is_depreciable' => true,
        'status' => FixedAsset::StatusDraft,
        'submit_action' => 'save_view',
    ])->assertOk();

    $asset = FixedAsset::query()
        ->where('source_type', 'purchase_invoice_line')
        ->where('source_id', $invoiceLine->getKey())
        ->firstOrFail();
    $invoice = $invoices->approve($invoice);
    $journal = $invoice->journalEntry()->with('lines')->firstOrFail();
    $recognition = $asset->costMovements()->sole();

    expect($invoice->status)->toBe(PurchaseInvoice::StatusApproved)
        ->and($asset->fresh()->status)->toBe(FixedAsset::StatusActive)
        ->and($recognition->movement_type)->toBe(FixedAssetMovement::TypeCapitalization)
        ->and($recognition->journal_entry_id)->toBe($journal->getKey())
        ->and((float) $journal->lines->firstWhere('account_id', $asset->account_id)?->debit_amount)->toBe(1000.0)
        ->and((float) $journal->lines->firstWhere('account_id', $supplierAccount->getKey())?->credit_amount)->toBe(1000.0)
        ->and(InventoryTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(1);

    $depreciationPreview = app(FixedAssetDepreciationService::class)->preview([
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'posting_date' => now()->endOfMonth()->toDateString(),
        'asset_doc_nums' => [$asset->doc_num],
    ]);
    expect($asset->fresh()->operation_date->toDateString())->toBe($readyForUseDate)
        ->and($asset->fresh()->depreciation_start_date->toDateString())->toBe($depreciationStartDate)
        ->and(collect($depreciationPreview['eligible'])->pluck('asset.doc_num'))->not->toContain($asset->doc_num)
        ->and(collect($depreciationPreview['excluded'])->pluck('asset.doc_num'))->toContain($asset->doc_num);

    $invoices->approve($invoice->fresh());
    expect(JournalEntry::query()->count())->toBe(1)
        ->and($asset->costMovements()->count())->toBe(1);

    $paymentData = [
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'payment_date' => now()->toDateString(),
        'currency_doc_num' => $fixture['currency']->doc_num,
        'cashbox_doc_num' => $cashbox->doc_num,
        'bank_account_doc_num' => $bankAccount->doc_num,
        'exchange_rate' => 1,
        'reason' => 'Settlement for '.$invoice->doc_num,
    ];
    $paymentSpecifications = [
        [SupplierPaymentContext::MethodCash, 300, []],
        [SupplierPaymentContext::MethodBank, 300, []],
        [SupplierPaymentContext::MethodCheque, 400, [
            'cheque_number' => 'ASSET-CHK-1',
            'cheque_date' => now()->toDateString(),
            'cheque_due_date' => now()->addWeek()->toDateString(),
        ]],
    ];
    $payments = collect();
    foreach ($paymentSpecifications as [$method, $amount, $methodData]) {
        $payment = $settlement->createSupplierPayment([
            ...$paymentData,
            ...$methodData,
            'payment_method' => $method,
            'amount' => $amount,
            'allocations' => [[
                'purchase_invoice_doc_num' => $invoice->doc_num,
                'amount' => $amount,
            ]],
        ]);
        $payments->push($settlement->approveSupplierPayment($payment));
    }

    expect($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPaid)
        ->and((float) $invoice->fresh()->remaining_amount)->toBe(0.0)
        ->and($payments->first()->cashVoucher)->not->toBeNull()
        ->and($payments->last()->cheque)->not->toBeNull()
        ->and(fn () => $invoices->reverse($invoice->fresh(), 'Blocked while settlements exist'))
        ->toThrow(DomainException::class);

    $this->get(route('admin.purchases.purchase-invoices.print', $invoice->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.finance.cash-payment-vouchers.print', $payments->first()->cashVoucher->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.purchases.procurement.print', ['supplier-payment', $payments[1]->doc_num]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.finance.cheques.print', $payments->last()->cheque->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.fixed-assets.lifecycle.show', $asset))
        ->assertOk()
        ->assertSee($order->doc_num)
        ->assertSee($receipt->doc_num)
        ->assertSee($invoice->doc_num)
        ->assertSee($payments->first()->doc_num)
        ->assertSee($payments->last()->doc_num);
    foreach ([$order, $receipt, $invoice, $payments->first(), $payments->first()->cashVoucher, $payments->last()->cheque, $asset] as $cycleDocument) {
        expect(app(ProcurementCycleReport::class)->documentChain($cycleDocument)->pluck('doc_num'))
            ->toContain($order->doc_num, $receipt->doc_num, $invoice->doc_num, $asset->doc_num, $payments->first()->doc_num);
    }

    $this->postJson(route('admin.fixed-assets.assets.store'), [
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'asset_date' => now()->toDateString(),
        'asset_name' => 'Non Depreciable Purchase Fixture',
        'asset_group_account_doc_num' => $assetCategory->doc_num,
        'credit_account_doc_num' => $supplierAccount->doc_num,
        'branch_doc_num' => $fixture['branch']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'description' => 'Boolean false request normalization',
        'purchase_date' => now()->toDateString(),
        'purchase_value' => 1,
        'exchange_rate' => 1,
        'is_depreciable' => false,
        'status' => FixedAsset::StatusDraft,
        'submit_action' => 'save_view',
    ])->assertOk();
    expect(FixedAsset::query()->where('asset_name', 'Non Depreciable Purchase Fixture')->firstOrFail()->is_depreciable)->toBeFalse();

    foreach ($payments as $payment) {
        $settlement->cancelSupplierPayment($payment, 'Acceptance reversal');
    }
    $invoice = $invoices->reverse($invoice->fresh(), 'Capital purchase cancelled');
    $recognition = $recognition->fresh();

    expect($invoice->status)->toBe(PurchaseInvoice::StatusCancelled)
        ->and($asset->fresh()->status)->toBe(FixedAsset::StatusDraft)
        ->and($recognition->status)->toBe(FixedAssetMovement::StatusReversed)
        ->and($recognition->reversal_journal_entry_id)->toBe($invoice->reversal_journal_entry_id)
        ->and(InventoryTransaction::query()->count())->toBe(0)
        ->and((float) JournalEntryLine::query()
            ->where('account_id', $supplierAccount->getKey())
            ->whereHas('journalEntry', fn ($query) => $query->where('is_posted', true))
            ->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) AS balance')
            ->value('balance'))->toBe(0.0);
});

test('a purchase invoice capital improvement reuses the invoice journal and reverses with its settlements', function (): void {
    $this->withoutExceptionHandling();
    Storage::fake('public');
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111096', 'Improvement Supplier Payable');
    $assetClearingAccount = procurementPostingAccount($fixture['company'], '1111', '1111097', 'Existing Asset Clearing');
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111096', 'Improvement Cash');
    $bankGlAccount = procurementPostingAccount($fixture['company'], '1112', '1112096', 'Improvement Bank');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();

    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Improvement Cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $cashbox->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'is_default' => true,
        'status' => 'active',
    ]);
    $bankParent = Account::query()
        ->where('company_id', $fixture['company']->getKey())
        ->where('account_code', '1112')
        ->firstOrFail();
    $bankAccount = BankAccount::query()->create([
        'doc_number' => 9906,
        'doc_num' => 'BANK-ASSET-IMPROVEMENT',
        'company_id' => $fixture['company']->getKey(),
        'bank_id' => $bankParent->getKey(),
        'account_id' => $bankGlAccount->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'account_name' => 'Improvement Operating Account',
        'account_number' => 'IMPROVEMENT-001',
        'status' => 'active',
    ]);
    $improvementProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9196,
        'doc_num' => 'Product-CAPITAL-IMPROVEMENT',
        'name' => 'Cutting Machine Control Upgrade',
        'item_classification' => Product::ClassificationOther,
        'item_unit_id' => $fixture['unit']->getKey(),
        'cost_as_inventory' => false,
        'status' => 'active',
    ]);
    $assetCategory = app(BusinessPartnerAccountService::class)->createGroup(
        BusinessPartnerAccountService::FixedAsset,
        'Improved Production Machines',
    );
    $assetDate = now()->toDateString();
    $targetAsset = app(FixedAssetService::class)->create([
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'asset_date' => $assetDate,
        'asset_name' => 'Existing Cutting Machine',
        'asset_group_account_doc_num' => $assetCategory->doc_num,
        'credit_account_doc_num' => $assetClearingAccount->doc_num,
        'branch_doc_num' => $fixture['branch']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'description' => 'Existing production asset',
        'purchase_date' => $assetDate,
        'acquisition_date' => $assetDate,
        'operation_date' => $assetDate,
        'depreciation_start_date' => $assetDate,
        'purchase_value' => 5000,
        'exchange_rate' => 1,
        'salvage_value' => 0,
        'previous_depreciation' => 0,
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'useful_life' => 5,
        'is_depreciable' => true,
        'status' => FixedAsset::StatusDraft,
    ])['record'];

    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $invoices = app(PurchaseInvoiceService::class);
    $settlement = app(ProcurementSettlementService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => $assetDate,
        'exchange_rate' => 1,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Approved machine upgrade',
        'lines' => [[
            'product_doc_num' => $improvementProduct->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 1,
            'unit_price' => 1000,
        ]],
    ])['record']);
    $orderLine = $order->lines->sole();
    $receipt = $receiving->postReceipt($receiving->createReceipt($order, [
        'document_date' => $assetDate,
        'supplier_delivery_note' => 'IMPROVEMENT-DELIVERY-1',
        'lines' => [[
            'purchase_order_line_public_id' => $orderLine->public_id,
            'delivered_quantity' => 1,
        ]],
    ]));
    $receiptLine = $receipt->lines()->firstOrFail();
    $invoice = $invoices->create([
        'purchase_order_doc_num' => $order->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => $assetDate,
        'supplier_invoice_number' => 'SUPPLIER-IMPROVEMENT-1',
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'lines' => [[
            'product_doc_num' => $improvementProduct->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $orderLine->public_id,
            'receipt_line_public_id' => $receiptLine->public_id,
            'quantity' => 1,
            'unit_price' => 1000,
        ]],
    ])['record'];
    $invoiceLine = $invoice->lines->sole();

    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    expect(app(FixedAssetPurchaseIntegrationService::class)->sourceLine($invoiceLine->public_id, true)->is($invoiceLine))->toBeTrue();
    $this->actingAs($fixture['user'])
        ->post(route('admin.purchases.purchase-invoices.asset-treatment', $invoice->doc_num), [
            'line_public_id' => $invoiceLine->public_id,
            'asset_treatment' => FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement,
            'target_fixed_asset_doc_num' => $targetAsset->doc_num,
            'asset_effective_date' => $assetDate,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('asset_treatment');

    $targetAsset = app(FixedAssetLifecycleService::class)->activate($targetAsset, $assetDate);
    $initialPosition = app(FixedAssetBookValueService::class)->position($targetAsset);
    $baselineJournalCount = JournalEntry::query()->count();

    $this->post(route('admin.purchases.purchase-invoices.asset-treatment', $invoice->doc_num), [
        'line_public_id' => $invoiceLine->public_id,
        'asset_treatment' => FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement,
        'target_fixed_asset_doc_num' => $targetAsset->doc_num,
        'asset_effective_date' => $assetDate,
    ])->assertRedirect()->assertSessionHasNoErrors();
    procurementUseBranch($fixture, $fixture['branch']);

    expect(SupplierPaymentContext::query()->count())->toBe(0)
        ->and(InventoryTransaction::query()->count())->toBe(0);

    $invoice = $invoices->approve($invoice->fresh());
    $journal = $invoice->journalEntry()->with('lines')->firstOrFail();
    $movement = FixedAssetMovement::query()
        ->where('source_type', FixedAssetPurchaseIntegrationService::ImprovementSourceType)
        ->where('source_id', $invoiceLine->getKey())
        ->firstOrFail();
    $improvedPosition = app(FixedAssetBookValueService::class)->position($targetAsset->fresh());

    expect($movement->fixed_asset_id)->toBe($targetAsset->getKey())
        ->and($movement->journal_entry_id)->toBe($journal->getKey())
        ->and($movement->source_doc_num)->toBe($invoice->doc_num)
        ->and((float) $journal->lines->firstWhere('account_id', $targetAsset->account_id)?->debit_amount)->toBe(1000.0)
        ->and((float) $journal->lines->firstWhere('account_id', $supplierAccount->getKey())?->credit_amount)->toBe(1000.0)
        ->and((float) $improvedPosition['acquisition_cost'])->toBe((float) $initialPosition['acquisition_cost'] + 1000)
        ->and(JournalEntry::query()->count())->toBe($baselineJournalCount + 1)
        ->and(InventoryTransaction::query()->count())->toBe(0);

    $invoices->approve($invoice->fresh());
    expect(FixedAssetMovement::query()
        ->where('source_type', FixedAssetPurchaseIntegrationService::ImprovementSourceType)
        ->where('source_id', $invoiceLine->getKey())
        ->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'purchase_invoice')->where('source_id', $invoice->getKey())->count())->toBe(1);

    $paymentData = [
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'payment_date' => $assetDate,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'cashbox_doc_num' => $cashbox->doc_num,
        'bank_account_doc_num' => $bankAccount->doc_num,
        'exchange_rate' => 1,
        'reason' => 'Settlement for '.$invoice->doc_num,
    ];
    $paymentSpecifications = [
        [SupplierPaymentContext::MethodCash, 300, []],
        [SupplierPaymentContext::MethodBank, 300, []],
        [SupplierPaymentContext::MethodCheque, 400, [
            'cheque_number' => 'IMPROVEMENT-CHK-1',
            'cheque_date' => $assetDate,
            'cheque_due_date' => now()->addWeek()->toDateString(),
        ]],
    ];
    $payments = collect();
    foreach ($paymentSpecifications as [$method, $amount, $methodData]) {
        $payment = $settlement->createSupplierPayment([
            ...$paymentData,
            ...$methodData,
            'payment_method' => $method,
            'amount' => $amount,
            'allocations' => [[
                'purchase_invoice_doc_num' => $invoice->doc_num,
                'amount' => $amount,
            ]],
        ]);
        $payments->push($settlement->approveSupplierPayment($payment));
    }

    expect($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPaid)
        ->and($payments->first()->cashVoucher)->not->toBeNull()
        ->and($payments->last()->cheque)->not->toBeNull()
        ->and(fn () => $invoices->reverse($invoice->fresh(), 'Blocked while settlements exist'))
        ->toThrow(DomainException::class);

    $this->get(route('admin.fixed-assets.prints.movement', $movement))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.purchases.purchase-invoices.print', $invoice->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.finance.cash-payment-vouchers.print', $payments->first()->cashVoucher->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.purchases.procurement.print', ['supplier-payment', $payments[1]->doc_num]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.finance.cheques.print', $payments->last()->cheque->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.fixed-assets.lifecycle.show', $targetAsset))
        ->assertOk()
        ->assertSee($movement->doc_num)
        ->assertSee($order->doc_num)
        ->assertSee($receipt->doc_num)
        ->assertSee($invoice->doc_num)
        ->assertSee($payments->first()->doc_num)
        ->assertSee($payments->last()->doc_num);

    foreach ([$targetAsset, $movement, $invoice, $payments->first()->cashVoucher, $payments->last()->cheque] as $cycleDocument) {
        expect(app(ProcurementCycleReport::class)->documentChain($cycleDocument)->pluck('doc_num'))
            ->toContain($order->doc_num, $receipt->doc_num, $invoice->doc_num, $targetAsset->doc_num, $movement->doc_num, $payments->first()->doc_num);
    }

    foreach ($payments as $payment) {
        $settlement->cancelSupplierPayment($payment, 'Improvement acceptance reversal');
    }
    $fixture['user']->revokePermissionTo('fixed_assets.improvement.reverse');
    expect(fn () => $invoices->reverse($invoice->fresh(), 'Missing asset reversal permission'))
        ->toThrow(DomainException::class);
    $fixture['user']->givePermissionTo('fixed_assets.improvement.reverse');
    $invoice = $invoices->reverse($invoice->fresh(), 'Improvement purchase cancelled');
    $restoredPosition = app(FixedAssetBookValueService::class)->position($targetAsset->fresh());

    expect($invoice->status)->toBe(PurchaseInvoice::StatusCancelled)
        ->and($movement->fresh()->status)->toBe(FixedAssetMovement::StatusReversed)
        ->and($movement->fresh()->reversal_journal_entry_id)->toBe($invoice->reversal_journal_entry_id)
        ->and($restoredPosition['acquisition_cost'])->toBe($initialPosition['acquisition_cost'])
        ->and(InventoryTransaction::query()->count())->toBe(0)
        ->and((float) JournalEntryLine::query()
            ->where('account_id', $supplierAccount->getKey())
            ->whereIn('journal_entry_id', [$journal->getKey(), $invoice->reversal_journal_entry_id])
            ->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) AS balance')
            ->value('balance'))->toBe(0.0);
});
