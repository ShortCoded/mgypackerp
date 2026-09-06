<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/ProcurementSupport.php';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'procurement_release_copy_')) {
        $this->markTestSkipped('Requires an isolated populated PostgreSQL procurement release copy.');
    }
});

test('procurement migrations preserve populated operational and financial data', function (): void {
    $tables = ['purchase_requisitions', 'purchase_requisition_lines', 'purchase_orders', 'purchase_order_lines',
        'unpriced_inventory_receipts', 'unpriced_inventory_receipt_lines', 'purchase_invoices', 'purchase_invoice_lines',
        'purchase_returns', 'supplier_payment_contexts', 'inventory_transactions', 'journal_entries', 'journal_entry_lines', 'companies'];
    $before = [];
    $columns = [];
    foreach ($tables as $table) {
        $columns[$table] = Schema::getColumnListing($table);
        $before[$table] = hash('sha256', DB::table($table)->orderBy('id')->get($columns[$table])->toJson());
    }
    $this->artisan('migrate', ['--path' => 'modules/Purchases/Database/Migrations', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
    foreach ($tables as $table) {
        expect(hash('sha256', DB::table($table)->orderBy('id')->get($columns[$table])->toJson()))->toBe($before[$table], $table);
    }
    $lineId = DB::table('purchase_invoice_lines')->value('id');
    if ($lineId) {
        DB::beginTransaction();
        try {
            DB::table('purchase_invoice_lines')->where('id', $lineId)->update(['quantity' => '1.12345678']);
            $precision = require base_path('modules/Purchases/Database/Migrations/2026_09_06_030522_align_purchase_invoice_quantity_precision.php');
            expect(fn () => $precision->down())->toThrow(RuntimeException::class, 'Cannot reduce purchase quantity precision');
            expect(DB::table('purchase_invoice_lines')->where('id', $lineId)->value('quantity'))->toBe('1.12345678');
        } finally {
            DB::rollBack();
        }
    }
    $path = 'modules/Purchases/Database/Migrations/2026_09_06_034231_add_procurement_submission_guards.php';
    $migration = require base_path($path);
    DB::transaction(function () use ($migration): void {
        $migration->down();
        $migration->up();
    });
    foreach ($tables as $table) {
        expect(hash('sha256', DB::table($table)->orderBy('id')->get($columns[$table])->toJson()))->toBe($before[$table], $table);
    }
});

test('procurement release provisions isolated browser data with real authentication', function (): void {
    $fixture = procurementFixture(true);
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $password = bin2hex(random_bytes(16));
    $fixture['user']->forceFill(['email' => 'procurement-smoke@example.test', 'password' => Hash::make($password), 'email_verified_at' => now()])->save();
    $sourcing = app(ProcurementSourcingService::class);
    $requisition = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10000)));
    $order = app(PurchaseOrderService::class)->create([
        'document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => array_map(fn (int $index): array => ['product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => $index + 1, 'unit_price' => 27], range(0, 14)),
    ])['record'];
    $state = ['email' => $fixture['user']->email, 'password' => $password, 'user_id' => $fixture['user']->id,
        'company' => $fixture['company']->doc_num, 'branch' => $fixture['branch']->doc_num, 'period' => $fixture['period']->doc_num,
        'order' => $order->doc_num, 'requisition' => $requisition->doc_num, 'supplier' => $fixture['firstSupplier']->doc_num];
    file_put_contents('/tmp/procurement-browser-fixture.json', json_encode($state, JSON_THROW_ON_ERROR));
    chmod('/tmp/procurement-browser-fixture.json', 0600);
    expect($order->lines)->toHaveCount(15);
});

test('postgresql concurrent operators cannot over receive bill or return one source line', function (): void {
    $fixture = procurementFixture(true);
    $supplierAccount = DB::transaction(fn () => procurementPostingAccount($fixture['company'], '2111', '2111099', 'Concurrent Supplier'));
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->id])->save();
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create([
        'document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'Concurrent acceptance',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 100, 'unit_price' => 2]],
    ])['record']);
    $line = $order->lines->sole();
    $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_starts_with(Illuminate\Support\Facades\DB::connection()->getDatabaseName(), 'procurement_release_copy_')) { exit(90); }
$input = json_decode(getenv('PROCUREMENT_WORKER'), true, flags: JSON_THROW_ON_ERROR);
$user = App\Models\User::findOrFail($input['user']);
auth()->login($user);
request()->setUserResolver(fn () => $user);
request()->setLaravelSession(app('session.store'));
session($input['context']);
file_put_contents($input['ready'], 'ready');
$deadline = microtime(true) + 15;
while (!file_exists($input['barrier'])) { if (microtime(true) > $deadline) { exit(91); } usleep(10000); }
try {
    $record = Illuminate\Support\Facades\DB::transaction(function () use ($input) {
        if ($input['operation'] === 'receipt') {
            return app(Modules\Purchases\Services\ProcurementReceivingService::class)->receive(Modules\Purchases\Models\PurchaseOrder::findOrFail($input['order']), $input['payload']);
        }
        if ($input['operation'] === 'invoice') {
            $service = app(Modules\Purchases\Services\PurchaseInvoiceService::class);
            return $service->approve($service->create($input['payload'])['record']);
        }
        $service = app(Modules\Purchases\Services\ProcurementSettlementService::class);
        return $service->approvePurchaseReturn($service->createPurchaseReturn($input['payload']));
    }, 3);
    echo json_encode(['result' => 'posted', 'id' => $record->id]);
} catch (DomainException $error) {
    echo json_encode(['result' => 'blocked', 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()); exit(1);
}
PHP;
    $race = function (string $operation, array $payload) use ($worker, $fixture, $order): array {
        $directory = sys_get_temp_dir().'/procurement-race-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $processes = [];
        for ($index = 0; $index < 2; $index++) {
            $input = ['operation' => $operation, 'payload' => $payload, 'user' => $fixture['user']->id, 'order' => $order->id,
                'context' => session()->all(), 'barrier' => $directory.'/go', 'ready' => $directory.'/ready-'.$index];
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker], base_path(), ['PROCUREMENT_WORKER' => json_encode($input)]);
            $process->setTimeout(45)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (count(glob($directory.'/ready-*')) < 2 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($directory.'/ready-*')))->toBe(2);
        touch($directory.'/go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['blocked', 'posted']);

        return collect($results)->firstWhere('result', 'posted');
    };
    $posted = $race('receipt', ['document_date' => now()->toDateString(),
        'lines' => [['purchase_order_line_public_id' => $line->public_id, 'delivered_quantity' => 100]]]);
    $receipt = UnpricedInventoryReceipt::findOrFail($posted['id'])->load('lines');
    app(ProcurementReceivingService::class)->inspect($receipt, ['lines' => [[
        'receipt_line_public_id' => $receipt->lines->sole()->public_id, 'accepted_quantity' => 100, 'rejected_quantity' => 0,
    ]]]);
    $posted = $race('invoice', ['purchase_order_doc_num' => $order->doc_num, 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'invoice_date' => now()->toDateString(), 'payment_type' => 'credit',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $line->public_id, 'receipt_line_public_id' => $receipt->lines->sole()->public_id, 'quantity' => 100, 'unit_price' => 2]],
    ]);
    $invoice = PurchaseInvoice::findOrFail($posted['id']);
    $race('return', ['purchase_order_doc_num' => $order->doc_num, 'purchase_invoice_doc_num' => $invoice->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'supplier_defect',
        'lines' => [['receipt_line_public_id' => $receipt->lines->sole()->public_id, 'quantity' => 100]],
    ]);
    expect((float) InventoryTransaction::where('company_id', $fixture['company']->id)->selectRaw('sum(quantity_in - quantity_out) as net')->value('net'))->toBe(0.0)
        ->and((float) JournalEntryLine::whereHas('journalEntry', fn ($query) => $query->where('company_id', $fixture['company']->id))->sum('debit_amount'))
        ->toBe((float) JournalEntryLine::whereHas('journalEntry', fn ($query) => $query->where('company_id', $fixture['company']->id))->sum('credit_amount'));
});

test('procurement browser fixtures cover fifteen line editors through the real document services', function (): void {
    DB::transaction(function (): void {
        if (! is_file('/tmp/procurement-browser-fixture.json')) {
            $this->markTestSkipped('Provision the isolated browser fixture first.');
        }
        $state = json_decode(file_get_contents('/tmp/procurement-browser-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
        $company = Company::query()->where('doc_num', $state['company'])->firstOrFail();
        $user = User::query()->findOrFail($state['user_id']);
        $order = PurchaseOrder::query()->where('company_id', $company->id)->where('doc_num', $state['order'])->firstOrFail();
        auth()->login($user);
        request()->setUserResolver(fn () => $user);
        request()->setLaravelSession(app('session.store'));
        $context = [OperatingContextService::CompanyIdKey => $company->id, OperatingContextService::CompanyDocNumKey => $company->doc_num,
            OperatingContextService::BranchIdKey => $order->branch_id, OperatingContextService::BranchDocNumKey => $state['branch'],
            OperatingContextService::FinancialPeriodIdKey => $order->financial_period_id, OperatingContextService::FinancialPeriodDocNumKey => $state['period']];
        session($context);
        $this->withSession($context);
        $orders = app(PurchaseOrderService::class);
        $order = $order->isApproved() ? $order->load('lines') : $orders->approve($orders->submit($order));
        $receiving = app(ProcurementReceivingService::class);
        $receiptPayload = ['document_date' => now()->toDateString(), 'lines' => $order->lines->map(fn ($line): array => [
            'purchase_order_line_public_id' => $line->public_id, 'delivered_quantity' => (float) $line->ordered_quantity / 2,
        ])->all()];
        $receipt = UnpricedInventoryReceipt::query()->where('purchase_order_id', $order->id)->where('posting_status', 'posted')->first()
            ?? $receiving->postReceipt($receiving->createReceipt($order, $receiptPayload));
        $receiving->inspect($receipt, ['lines' => $receipt->lines->map(fn ($line): array => [
            'receipt_line_public_id' => $line->public_id, 'accepted_quantity' => (float) $line->quantity, 'rejected_quantity' => 0,
        ])->all()]);
        $draftReceipt = UnpricedInventoryReceipt::query()->where('purchase_order_id', $order->id)->where('status', 'draft')->first()
            ?? $receiving->createReceipt($order->fresh(), $receiptPayload);
        $supplier = $order->supplier;
        if (! $supplier->account_id) {
            $supplier->forceFill(['account_id' => procurementPostingAccount($company, '2111', '2111082', 'Browser supplier')->id])->save();
        }
        $invoicePayload = ['invoice_date' => now()->toDateString(), 'supplier_doc_num' => $supplier->doc_num,
            'purchase_order_doc_num' => $order->doc_num, 'currency_doc_num' => $order->currency->doc_num,
            'exchange_rate' => 1, 'payment_type' => 'credit', 'supplier_invoice_number' => 'BROWSER-POSTED',
            'lines' => $receipt->lines->map(fn ($line): array => [
                'product_doc_num' => $line->product->doc_num, 'unit_doc_num' => $line->unit->doc_num,
                'purchase_order_line_public_id' => $line->purchaseOrderLine->public_id,
                'receipt_line_public_id' => $line->public_id, 'quantity' => (float) $line->quantity / 2, 'unit_price' => 27,
            ])->all()];
        $invoices = app(PurchaseInvoiceService::class);
        $invoice = $invoices->approve($invoices->create($invoicePayload)['record']);
        $draftInvoice = $invoices->create([...$invoicePayload, 'supplier_invoice_number' => 'BROWSER-DRAFT'])['record'];
        $return = app(ProcurementSettlementService::class)->createPurchaseReturn([
            'purchase_order_doc_num' => $order->doc_num, 'purchase_invoice_doc_num' => $invoice->doc_num,
            'return_date' => now()->toDateString(), 'reason_code' => 'supplier_defect',
            'lines' => $receipt->lines->map(fn ($line): array => ['receipt_line_public_id' => $line->public_id, 'quantity' => 0.1])->all(),
        ]);
        $request = app(ProcurementSourcingService::class)->createRequisition([
            'request_date' => now()->toDateString(), 'branch_store_uuid' => $order->branchStore->public_uuid,
            'lines' => $order->lines->map(fn ($line): array => ['product_doc_num' => $line->product->doc_num,
                'unit_doc_num' => $line->unit->doc_num, 'requested_quantity' => $line->ordered_quantity, 'source_type' => 'manual'])->all(),
        ]);
        foreach ([$order, $receipt, $draftReceipt, $invoice, $draftInvoice, $return, $request] as $document) {
            expect($document->lines)->toHaveCount(15);
        }
        file_put_contents('/tmp/procurement-browser-documents.json', json_encode([
            'requisition' => $request->doc_num, 'receipt' => $receipt->doc_num, 'draft_receipt' => $draftReceipt->doc_num,
            'invoice' => $invoice->doc_num, 'draft_invoice' => $draftInvoice->doc_num, 'return' => $return->doc_num,
        ], JSON_THROW_ON_ERROR));
    });
});

test('procurement quantity reports keep bounded queries with hundreds of orders and thousands of lines', function (): void {
    $fixture = procurementFixture(true);
    $orderService = app(PurchaseOrderService::class);
    $order = $orderService->approve($orderService->create([
        'document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'Report volume fixture',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 100, 'unit_price' => 2]],
    ])['record']);
    $header = $order->getAttributes();
    unset($header['id']);
    $line = $order->lines->sole()->getAttributes();
    unset($line['id']);
    DB::transaction(function () use ($header, $line): void {
        for ($index = 0; $index < 200; $index++) {
            $orderId = DB::table('purchase_orders')->insertGetId([...$header, 'doc_number' => 20000 + $index, 'doc_num' => 'PO-VOLUME-'.$index]);
            $lines = [];
            for ($lineNumber = 1; $lineNumber <= 15; $lineNumber++) {
                $lines[] = [...$line, 'public_id' => (string) Str::uuid(), 'purchase_order_id' => $orderId, 'line_number' => $lineNumber];
            }
            DB::table('purchase_order_lines')->insert($lines);
        }
    });
    $account = DB::transaction(fn () => procurementPostingAccount($fixture['company'], '2111', '2111098', 'Volume supplier'));
    $fixture['firstSupplier']->forceFill(['account_id' => $account->id])->save();
    $receiving = app(ProcurementReceivingService::class);
    $receipt = $receiving->receive($order, ['document_date' => now()->toDateString(),
        'lines' => [['purchase_order_line_public_id' => $order->lines->sole()->public_id, 'delivered_quantity' => 50]]]);
    $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->sole()->public_id, 'accepted_quantity' => 50, 'rejected_quantity' => 0]]]);
    $receipt = $receipt->fresh()->load('lines');
    $invoices = app(PurchaseInvoiceService::class);
    $invoice = $invoices->approve($invoices->create(['purchase_order_doc_num' => $order->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1, 'invoice_date' => now()->toDateString(), 'payment_type' => 'credit',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $order->lines->sole()->public_id, 'receipt_line_public_id' => $receipt->lines->sole()->public_id,
            'quantity' => 25, 'unit_price' => 2]],
    ])['record']);
    $template = function ($model): array {
        $attributes = $model->getAttributes();
        unset($attributes['id']);

        return $attributes;
    };
    $pr = procurementManualRequisition($fixture, 100);
    $prLine = $template($pr->lines->sole());
    $invoiceJournal = $invoice->journalEntry()->with('lines')->firstOrFail();
    DB::transaction(function () use ($template, $receipt, $invoice, $fixture, $prLine, $pr, $invoiceJournal): void {
        $prLines = [];
        for ($index = 1; $index <= 3000; $index++) {
            $prLines[] = [...$prLine, 'public_id' => (string) Str::uuid(), 'line_number' => $index + 1];
            if (count($prLines) === 100) {
                DB::table('purchase_requisition_lines')->insert($prLines);
                $prLines = [];
            }
        }
        foreach (PurchaseOrder::query()->where('company_id', $fixture['company']->id)->where('doc_num', 'like', 'PO-VOLUME-%')->with('lines')->get() as $index => $volumeOrder) {
            $volumeLine = $volumeOrder->lines->first();
            DB::table('purchase_orders')->where('id', $volumeOrder->id)->update(['purchase_requisition_id' => $pr->id]);
            DB::table('purchase_order_lines')->where('id', $volumeLine->id)->update(['purchase_requisition_line_id' => DB::table('purchase_requisition_lines')->where('purchase_requisition_id', $pr->id)->where('line_number', $index + 2)->value('id')]);
            $receiptId = DB::table('unpriced_inventory_receipts')->insertGetId([...$template($receipt),
                'doc_number' => 20000 + $index, 'doc_num' => 'GRN-VOLUME-'.$index, 'purchase_order_id' => $volumeOrder->id]);
            $receiptLineId = DB::table('unpriced_inventory_receipt_lines')->insertGetId([...$template($receipt->lines->sole()),
                'public_id' => (string) Str::uuid(), 'receipt_id' => $receiptId, 'purchase_order_line_id' => $volumeLine->id]);
            $invoiceId = DB::table('purchase_invoices')->insertGetId([...$template($invoice),
                'doc_number' => 20000 + $index, 'doc_num' => 'PINV-VOLUME-'.$index, 'purchase_order_id' => $volumeOrder->id]);
            DB::table('purchase_invoice_lines')->insert([...$template($invoice->lines->sole()), 'public_id' => (string) Str::uuid(),
                'purchase_invoice_id' => $invoiceId, 'purchase_order_line_id' => $volumeLine->id, 'receipt_line_id' => $receiptLineId]);
            $journalId = DB::table('journal_entries')->insertGetId([...$template($invoiceJournal), 'doc_number' => 20000 + $index,
                'doc_num' => 'JV-VOLUME-'.$index, 'source_id' => $invoiceId, 'source_doc_num' => 'PINV-VOLUME-'.$index]);
            foreach ($invoiceJournal->lines as $journalLine) {
                $attributes = $template($journalLine);
                if (isset($attributes['public_id'])) {
                    $attributes['public_id'] = (string) Str::uuid();
                }
                DB::table('journal_entry_lines')->insert([...$attributes, 'journal_entry_id' => $journalId]);
            }
            DB::table('purchase_invoices')->where('id', $invoiceId)->update(['journal_entry_id' => $journalId]);
        }
    });
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $report = app(ProcurementCycleReport::class);
    $results = [];
    foreach (['open_purchase_orders', 'purchase_ledger', 'price_history', 'supplier_statement', 'supplier_360', 'document_trace'] as $type) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $rows = match ($type) {
            'supplier_360' => $report->supplierOverview($fixture['firstSupplier']),
            'document_trace' => $report->documentChain($pr),
            default => $report->rows($type, ['branch_id' => $fixture['branch']->id], $fixture['company']->id, $fixture['period']->id),
        };
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $duration = microtime(true) - $start;
        $results[$type] = ['queries' => count($queries), 'seconds' => $duration, 'rows' => count($rows)];
        expect(count($queries))->toBeLessThan(70, $type)->and($duration)->toBeLessThan(20.0, $type);
        if ($type === 'open_purchase_orders') {
            expect($rows)->toHaveCount(3001)->and($rows->sum('remaining'))->toBe(290050.0);
        }
        if ($type === 'purchase_ledger') {
            expect($rows)->toHaveCount(201);
        }
    }
    file_put_contents('/tmp/procurement-report-performance.json', json_encode(['orders' => 201, 'order_lines' => 3001,
        'request_lines' => 3001, 'receipts' => 201, 'invoices' => 201, 'measurements' => $results], JSON_THROW_ON_ERROR));
});

test('procurement UI review prepares the existing isolated browser context', function (): void {
    $state = json_decode(file_get_contents('/tmp/procurement-browser-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $company = Company::query()->where('doc_num', $state['company'])->firstOrFail();
    $branch = \Modules\Core\Models\Branch::query()->where('company_id',$company->id)->where('doc_num',$state['branch'])->firstOrFail();
    $branch->update(['type' => \Modules\Core\Models\Branch::TypeFactory]);
    $employee = \Modules\HR\Models\HrEmployee::query()->firstOrCreate(['company_id' => $company->id,'doc_num'=>'EMP-UI-REVIEW'], ['doc_number' => 991,'branch_id'=>$branch->id,'full_name'=>'أحمد مسؤول المخزن','name'=>'أحمد مسؤول المخزن','status'=>'active']);
    $this->seed(PermissionSeeder::class);
    User::query()->findOrFail($state['user_id'])->givePermissionTo(Permission::query()->where('guard_name','web')->get());
    expect($employee->company_id)->toBe($company->id);
});
