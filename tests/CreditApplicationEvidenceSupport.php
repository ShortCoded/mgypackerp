<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerCreditApplicationEvidenceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

/** @return array<string, mixed> */
function historicalCreditEvidenceFixture(string $paid = '0'): array
{
    $fixture = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    test()->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }
    request()->session()->put(salesCycleSession($fixture));
    foreach (['customer_invoices.view', 'customer_credits.prepare_application_evidence', 'customer_credits.approve_application_evidence', 'sales_returns.correct_closed'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $fixture['user']->givePermissionTo($ability);
    }
    $reviewer = DB::getDriverName() === 'pgsql' ? closureSyntheticUser() : User::factory()->create();
    $reviewer->givePermissionTo(['customer_invoices.view', 'customer_credits.approve_application_evidence', 'sales_returns.correct_closed']);
    $invoice = salesPostedServiceInvoice($fixture, '100', quantity: '2');
    if (bccomp($paid, '0', 4) > 0) {
        app(CustomerReceiptService::class)->createAndApprove([
            'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
            'branch_id' => $fixture['branch']->id, 'customer_id' => $fixture['customer']->id,
            'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->id, 'exchange_rate' => 1,
            'payment_method' => 'cash', 'cashbox_id' => $fixture['cashbox']->id, 'amount' => $paid,
            'receipt_type' => CustomerReceipt::TypeCollection,
        ], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->sole()->id, 'amount' => $paid]]);
    }
    $returns = app(SalesReturnService::class);
    $return = $returns->close($returns->authorize($returns->create($invoice, SalesReturn::ReasonOrderEntry,
        'SYNTHETIC historical application evidence acceptance', [['customer_invoice_line_id' => $invoice->lines->sole()->id, 'quantity' => '1']])));
    $credit = $return->creditNote;
    $originalApplication = $credit->credit_application_snapshot;
    $credit->forceFill(['credit_application_snapshot' => null])->save();
    $preview = app(CustomerCreditApplicationEvidenceService::class)->preview($credit);
    $payload = ['source_fingerprint' => $preview['fingerprint'], 'source_reference' => 'SYNTHETIC original credit application record',
        'reason' => 'SYNTHETIC reviewed original installment application', 'schedules' => $originalApplication['schedules']];

    return [...$fixture, 'reviewer' => $reviewer, 'invoice' => $invoice, 'return' => $return, 'credit' => $credit, 'payload' => $payload];
}
