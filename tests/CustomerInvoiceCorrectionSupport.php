<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Carbon\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\SalesFulfillmentService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';
require_once __DIR__.'/InventoryValueAdjustmentSupport.php';

/** @return array<string,mixed> */
function invoiceCorrectionFixture(bool $partialInvoice = false, bool $companyWarehouse = false, bool $completedReceiptCost = false, bool $createInvoice = true): array
{
    test()->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $f = productionCorrectionCompletedFixture(suffix: '-SYNTHETIC-INVOICE-CORRECTION-'.((int) Company::withTrashed()->max('doc_number') + 1), forSales: true, isolatedCompany: true);
    $classification = AccountClassification::query()->where('code', 'accounts_receivable')->firstOrFail();
    $parent = Account::query()->where('company_id', $f['company']->id)->where('account_classification_id', $classification->id)
        ->where('is_group', true)->orderByDesc('level')->firstOrFail();
    $account = Account::query()->create(['company_id' => $f['company']->id, 'doc_number' => 99123, 'doc_num' => 'SYNTHETIC-INVOICE-CUSTOMER-ACCOUNT',
        'account_code' => '112199123', 'name' => 'SYNTHETIC invoice correction customer', 'parent_id' => $parent->id,
        'level' => $parent->level + 1, 'account_classification_id' => $classification->id, 'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit, 'is_group' => false,
        'is_postable' => true, 'status' => 'active']);
    $f['salesOrder']->customer->update(['account_id' => $account->id]);
    $permissions = ['customer_invoices.correct_prepare', 'customer_invoices.correct_approve', 'customer_invoices.correct_later_period',
        'customer_invoices.view', 'production.runs.correct_later_period'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $f['user']->givePermissionTo($permissions);
    $f['approver']->givePermissionTo($permissions);
    invoiceCorrectionActor($f, $f['user']);
    $logistics = [];
    if ($companyWarehouse) {
        foreach (InventoryReservation::query()->where('sales_order_line_id', $f['salesLine']->id)
            ->where('status', InventoryReservation::StatusActive)->get() as $reservation) {
            app(SalesFulfillmentService::class)->releaseReservation($reservation, 'SYNTHETIC approved warehouse transfer');
        }
        $number = (int) Branch::withTrashed()->max('doc_number') + 1;
        $f['warehouseBranch'] = Branch::query()->create(['company_id' => $f['company']->id,
            'doc_number' => $number, 'doc_num' => 'SYNTHETIC-INVOICE-WAREHOUSE-'.$number, 'name' => 'SYNTHETIC different warehouse branch',
            'type' => Branch::TypeFactory, 'status' => 'active']);
        $f['warehouse'] = BranchStore::query()->create(['branch_id' => $f['warehouseBranch']->id, 'name' => 'SYNTHETIC invoice warehouse']);
        $f['transfer'] = app(InventoryMovementService::class)->createAndPost([
            'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
            'branch_store_id' => $f['store']->id, 'destination_branch_store_id' => $f['warehouse']->id,
            'document_type' => InventoryDocument::TypeTransfer, 'document_date' => '2026-09-30',
        ], [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '10', 'batch_lot' => 'INTEGRITY-LOT-001']]);
        $logistics['branch_store_id'] = $f['warehouse']->id;
    }
    if ($completedReceiptCost) {
        test()->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        foreach (['inventory.documents.propose_receipt_cost', 'inventory.documents.approve_receipt_cost'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $f['user']->givePermissionTo('inventory.documents.propose_receipt_cost');
        $f['approver']->givePermissionTo('inventory.documents.approve_receipt_cost');
        $f['completionStore'] = BranchStore::query()->create(['branch_id' => $f['branch']->id,
            'name' => 'SYNTHETIC approved completion store']);
        $numbers = app(DocumentNumberService::class)->nextForCompany('inventory_documents', InventoryDocument::class, $f['company']->id);
        $f['completionReceipt'] = InventoryDocument::query()->create([
            ...$numbers,
            'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
            'branch_store_id' => $f['completionStore']->id, 'document_type' => InventoryDocument::TypeReceipt,
            'document_date' => '2026-09-30', 'status' => 'draft', 'notes' => 'SYNTHETIC legacy unvalued receipt acceptance fixture',
        ]);
        $f['completionReceipt']->lines()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
            'line_number' => 1, 'product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '10']);
        $f['completionReceipt'] = app(InventoryDocumentPostingService::class)->post($f['completionReceipt']);
        $f['counterpart'] = app(PostingAccountResolver::class)->resolve($f['company']->id,
            PostingAccountResolver::InventoryAdjustmentGain, 'SYNTHETIC approved receipt completion');
        $f['preparer'] = $f['user'];
        receiptCompletionApprove($f, $f['completionReceipt'], receiptCompletionPrepare($f, $f['completionReceipt'], '2', '2026-09-30'));
        invoiceCorrectionActor($f, $f['user']);
        $logistics['branch_store_id'] = $f['completionStore']->id;
    }
    $delivery = app(SalesFulfillmentService::class)->deliver($f['salesOrder']->fresh(), [['sales_order_line_id' => $f['salesLine']->id, 'quantity' => '10']], $logistics, allowCompanyWarehouse: $companyWarehouse);
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $createInvoice ? $invoices->post($invoices->createFromOrder($f['salesOrder']->fresh(),
        [['sales_order_line_id' => $f['salesLine']->id, 'delivery_line_id' => $delivery->lines->sole()->id, 'quantity' => $partialInvoice ? '4' : '10']],
        [['due_date' => '2026-09-30', 'amount' => $partialInvoice ? '40' : '100']], $delivery)) : null;
    if ($completedReceiptCost) {
        receiptCompletionApprove($f, $f['completionReceipt'], receiptCompletionPrepare($f, $f['completionReceipt'], '3', '2026-09-30'));
        invoiceCorrectionActor($f, $f['user']);
    }

    return [...$f, 'delivery' => $delivery, 'invoice' => $invoice];
}

function invoiceCorrectionActor(array $f, User $user): void
{
    $session = manufacturingIntegritySession($f);
    if (isset($f['target'])) {
        $session[OperatingContextService::FinancialPeriodIdKey] = $f['target']->id;
        $session[OperatingContextService::FinancialPeriodDocNumKey] = $f['target']->doc_num;
    }
    test()->actingAs($user)->withSession($session);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put($session);
    request()->setUserResolver(fn (): User => $user);
}

function invoiceCorrectionCollection(array $f, CustomerInvoice $invoice, string $amount): CustomerReceipt
{
    $classification = AccountClassification::query()->where('code', 'cash')->firstOrFail();
    $parent = Account::query()->where('company_id', $f['company']->id)->where('account_code', '1111')->firstOrFail();
    $account = Account::query()->firstOrCreate(['company_id' => $f['company']->id, 'doc_num' => 'SYNTHETIC-INVOICE-CASH'],
        ['doc_number' => 99124, 'account_code' => '111199124', 'name' => 'SYNTHETIC invoice recovery cash', 'parent_id' => $parent->id,
            'level' => $parent->level + 1, 'account_classification_id' => $classification->id, 'account_type' => Account::TypeAsset,
            'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit,
            'is_group' => false, 'is_postable' => true, 'status' => 'active']);
    $cashbox = Cashbox::query()->firstOrCreate(['company_id' => $f['company']->id, 'doc_num' => 'SYNTHETIC-INVOICE-CASHBOX'],
        ['doc_number' => 99124, 'branch_id' => $f['branch']->id, 'account_id' => $account->id, 'name' => 'SYNTHETIC recovery cashbox', 'status' => 'active']);

    return app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $f['company']->id, 'financial_period_id' => ($f['target'] ?? $f['period'])->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $invoice->customer_id, 'receipt_date' => now()->toDateString(), 'currency_id' => $invoice->currency_id,
        'exchange_rate' => 1, 'payment_method' => 'cash', 'cashbox_id' => $cashbox->id, 'amount' => $amount,
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules()->sole()->id, 'amount' => $amount]]);
}

/** @return array<string,string> */
function invoiceCorrectionPayload(array $f): array
{
    return ['source_fingerprint' => app(CustomerInvoiceCorrectionService::class)->preview($f['invoice'])['fingerprint'],
        'reason' => 'SYNTHETIC corrected source evidence', 'posting_date' => now()->toDateString(), 'recovery_reference' => 'SYNTHETIC physical recovery reference'];
}
