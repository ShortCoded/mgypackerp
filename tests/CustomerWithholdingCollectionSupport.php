<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerWithholdingSettlementService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesWithholdingService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

/** @param array<string, mixed> $fixture */
function withholdingActor(array $fixture, User $user): void
{
    test()->actingAs($user)->withSession(salesCycleSession($fixture));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($fixture));
    request()->setUserResolver(fn (): User => $user);
    auth()->login($user);
}

/** @return array<string, mixed> */
function withholdingFixture(): array
{
    $fixture = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    $approver = closureSyntheticUser();
    foreach (['customer_invoices.view', 'customer_invoices.view_prices', 'file_manager.view',
        'customer_withholding_settlements.view', 'customer_withholding_settlements.prepare',
        'customer_withholding_settlements.approve', 'customer_withholding_settlements.reverse'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $fixture['user']->givePermissionTo($ability);
        $approver->givePermissionTo($ability);
    }
    withholdingActor($fixture, $fixture['user']);
    $classification = AccountClassification::query()->where('code', 'withholding_tax_receivable')->firstOrFail();
    $account = Account::query()->where('company_id', $fixture['company']->id)->where('account_classification_id', $classification->id)->eligibleForClassifiedPosting()->first();
    if (! $account) {
        $account = $fixture['customer']->account->replicate();
        $account->fill(['doc_number' => (int) Account::withTrashed()->max('doc_number') + 1, 'doc_num' => 'SYNTHETIC-WHT-ACCOUNT-'.$fixture['company']->id, 'account_code' => '1124-99101',
            'name' => 'SYNTHETIC certificate withholding receivable', 'account_classification_id' => $classification->id]);
        $account->save();
    }
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, ['payment_schedules' => [],
        'withholding_rate' => '1', 'withholding_basis' => SalesWithholdingService::EtaNetExcludingTax,
        'lines' => [['product_id' => $fixture['service']->id, 'unit_id' => $fixture['unit']->id,
            'quantity' => '1', 'unit_price' => '1000', 'tax_amount' => '140']]])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order,
        [['sales_order_line_id' => $order->lines->sole()->id, 'quantity' => '1']],
        [['amount' => '1140', 'due_date' => now()->toDateString()]]));
    $schedule = $invoice->paymentSchedules->sole();
    $receipt = app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'financial_period_id' => $fixture['period']->id,
        'customer_id' => $fixture['customer']->id, 'currency_id' => $fixture['currency']->id, 'exchange_rate' => '1',
        'receipt_date' => now()->toDateString(), 'receipt_type' => CustomerReceipt::TypeCollection, 'payment_method' => 'bank',
        'bank_account_id' => $fixture['bankAccount']->id, 'amount' => '1130',
    ], [['customer_invoice_payment_schedule_id' => $schedule->id, 'amount' => '1130']]);
    $filesystemRoot = storage_path('framework/testing/disks/customer-withholding-'.Str::uuid());
    Storage::set('local', Storage::build(['driver' => 'local', 'root' => $filesystemRoot, 'throw' => true]));
    $content = '%PDF-1.4 SYNTHETIC certificate: actual withholding 10, cash 1130';
    $path = 'synthetic-wht/'.Str::uuid().'.pdf';
    Storage::disk('local')->put($path, $content);
    $certificate = ArchiveFile::query()->create(['doc_number' => (int) ArchiveFile::withTrashed()->max('doc_number') + 1,
        'doc_num' => (string) Str::uuid(), 'attachable_type' => (new Company)->getMorphClass(), 'attachable_id' => $fixture['company']->id,
        'module' => 'sales', 'record_type' => 'withholding_certificate', 'original_name' => basename($path), 'stored_name' => basename($path),
        'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => strlen($content),
        'checksum' => hash('sha256', $content), 'uploaded_by' => $fixture['user']->id]);

    $recoveryContent = '%PDF-1.4 SYNTHETIC corrected certificate recovery proof';
    $recoveryPath = 'synthetic-wht/'.Str::uuid().'-recovery.pdf';
    Storage::disk('local')->put($recoveryPath, $recoveryContent);
    $recovery = $certificate->replicate();
    $recovery->fill(['doc_number' => (int) ArchiveFile::withTrashed()->max('doc_number') + 1, 'doc_num' => (string) Str::uuid(),
        'path' => $recoveryPath, 'original_name' => basename($recoveryPath), 'stored_name' => basename($recoveryPath),
        'size_bytes' => strlen($recoveryContent), 'checksum' => hash('sha256', $recoveryContent)]);
    $recovery->save();

    return [...$fixture, ...compact('approver', 'account', 'order', 'invoice', 'schedule', 'receipt', 'certificate', 'recovery')];
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function withholdingPayload(array $fixture): array
{
    $source = app(CustomerWithholdingSettlementService::class)->preview($fixture['invoice'], $fixture['schedule']->id, $fixture['receipt']->id);

    return ['payment_schedule_id' => $fixture['schedule']->id, 'customer_receipt_id' => $fixture['receipt']->id,
        'source_fingerprint' => $source['fingerprint'], 'certificate_reference' => 'SYNTHETIC-CERT-1',
        'certificate_date' => now()->toDateString(), 'posting_date' => now()->toDateString(), 'amount' => '10',
        'attachment_doc_nums' => [$fixture['certificate']->doc_num], 'reason' => 'SYNTHETIC actual certificate reconciles to the received cash.'];
}
