<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** @param list<array<string, mixed>> $operations @return list<array<string, mixed>> */
function closurePostgresRace(array $operations, bool $orderedCompanyLock = false): array
{
    $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
if ($identity->db !== 'mgypack_acceptance_closure_20261003' || $identity->host !== '127.0.0.1' || (int)$identity->port !== 5432) { exit(90); }
$input = json_decode(getenv('MGYPACK_CLOSURE_WORKER'), true, flags: JSON_THROW_ON_ERROR);
if (isset($input['clock'])) {
    Carbon\Carbon::setTestNow($input['clock']);
    Carbon\CarbonImmutable::setTestNow($input['clock']);
}
$user = App\Models\User::findOrFail($input['user']);
auth()->login($user);
request()->setUserResolver(fn () => $user);
request()->setLaravelSession(app('session.store'));
session($input['context']);
$rootTransactionAttempts = 0;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionBeginning::class, function ($event) use (&$rootTransactionAttempts) {
    if ($event->connection->transactionLevel() === 1) { $rootTransactionAttempts++; }
});
$backend = Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid');
file_put_contents($input['ready'], json_encode(['pid' => (int) $backend->pid]));
$deadline = microtime(true) + 15;
while (!file_exists($input['barrier'])) { if (microtime(true) > $deadline) { exit(91); } usleep(10000); }
if ($input['pause_on_company_lock'] ?? false) {
    $paused = false;
    Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\QueryExecuted::class, function ($event) use ($input, &$paused) {
        if ($paused || !str_contains($event->sql, '"companies"') || !str_contains(strtolower($event->sql), 'for update')) { return; }
        $paused = true;
        file_put_contents($input['lock_ready'], 'locked');
        $deadline = microtime(true) + 15;
        while (!file_exists($input['lock_release'])) { if (microtime(true) > $deadline) { throw new RuntimeException('SYNTHETIC ordered lock checkpoint timed out'); } usleep(10000); }
    });
}
try {
    $service = app(Modules\Inventory\Services\InventoryCostPolicyTransitionService::class);
    if ($input['operation'] === 'activate') {
        $record = $service->activate(Modules\Inventory\Models\InventoryCostPolicyTransition::findOrFail($input['transition']), $user->id);
    } elseif ($input['operation'] === 'cancel') {
        $record = $service->cancel(Modules\Inventory\Models\InventoryCostPolicyTransition::findOrFail($input['transition']), $user->id, 'SYNTHETIC concurrent cancel');
    } elseif ($input['operation'] === 'production-correction') {
        $record = app(Modules\Production\Services\ProductionRunCorrectionService::class)->approve(Modules\Production\Models\ProductionRun::findOrFail($input['run']), $input['correction']);
    } elseif ($input['operation'] === 'customer-invoice-correction') {
        $record = app(Modules\Sales\Services\CustomerInvoiceCorrectionService::class)->approve(
            Modules\Sales\Models\CustomerInvoice::findOrFail($input['invoice']), $input['correction'], 'SYNTHETIC concurrent independent invoice recovery');
    } elseif ($input['operation'] === 'production-receipt') {
        $record = app(Modules\Production\Services\ProductionCycleService::class)->receiveFinishedGoods(
            Modules\Production\Models\ProductionRun::findOrFail($input['run']), $input['store'], $input['quantity']);
    } elseif ($input['operation'] === 'credit-application-approve') {
        $record = app(Modules\Sales\Services\CustomerCreditApplicationEvidenceService::class)->approve(
            Modules\Sales\Models\CustomerInvoice::findOrFail($input['credit']), $input['evidence'], 'SYNTHETIC concurrent independent review');
    } elseif ($input['operation'] === 'sales-return-closed-correction') {
        $record = app(Modules\Sales\Services\SalesReturnService::class)->correctClosed(
            Modules\Sales\Models\SalesReturn::findOrFail($input['return']), 'SYNTHETIC concurrent approved credit correction');
    } elseif ($input['operation'] === 'sales-return-later-approve') {
        $record = app(Modules\Sales\Services\SalesReturnCorrectionService::class)->approve(
            Modules\Sales\Models\SalesReturn::findOrFail($input['return']), $input['correction'], 'SYNTHETIC concurrent independent later-period review');
    } elseif ($input['operation'] === 'sales-credit-allocate') {
        $record = app(Modules\Sales\Services\CustomerCreditService::class)->allocate(Modules\Sales\Models\CustomerInvoice::findOrFail($input['credit']),
            Modules\Sales\Models\CustomerInvoice::findOrFail($input['invoice']), '10', '2026-10-02');
    } elseif ($input['operation'] === 'sales-credit-refund') {
        $record = app(Modules\Sales\Services\CustomerCreditService::class)->refund(Modules\Sales\Models\CustomerInvoice::findOrFail($input['credit']), $input['data']);
    } elseif ($input['operation'] === 'payroll-correction-approve') {
        $record = app(Modules\HR\Services\PayrollCorrectionService::class)->approve($input['run'], $input['correction']);
    } elseif ($input['operation'] === 'payroll-payment-create') {
        $record = app(Modules\HR\Services\PayrollPaymentService::class)->createCashPayment($input['run'], $input['company'], $input['data'])['payment'];
    } elseif ($input['operation'] === 'cost-allocation-approve') {
        $record = app(Modules\Accounting\Services\OverheadAllocationService::class)->approve(Modules\Accounting\Models\OverheadAllocationRun::findOrFail($input['allocation']));
    } elseif ($input['operation'] === 'cost-source-reverse') {
        $record = app(Modules\Accounting\Services\JournalEntryService::class)->createPostedReversalFromSource(Modules\Accounting\Models\JournalEntry::findOrFail($input['journal']), $input['header']);
    } elseif ($input['operation'] === 'cash-voucher-cancel') {
        $record = app(Modules\Finance\Services\CashVoucherService::class)->cancelGeneric(Modules\Finance\Models\CashVoucher::TypePayment, Modules\Finance\Models\CashVoucher::findOrFail($input['voucher']), 'SYNTHETIC concurrent overhead payment reversal');
    } elseif ($input['operation'] === 'purchase-scheduled-payment-correct') {
        $record = app(Modules\Finance\Services\CashVoucherService::class)->correctScheduledPurchasePayment(Modules\Finance\Models\CashVoucher::findOrFail($input['voucher']), 'SYNTHETIC concurrent scheduled payment recovery');
    } elseif ($input['operation'] === 'cash-voucher-approve') {
        $record = app(Modules\Finance\Services\CashVoucherService::class)->approveGeneric(Modules\Finance\Models\CashVoucher::TypePayment, Modules\Finance\Models\CashVoucher::findOrFail($input['voucher']));
    } elseif ($input['operation'] === 'cash-voucher-update') {
        $record = app(Modules\Finance\Services\CashVoucherService::class)->update(Modules\Finance\Models\CashVoucher::TypePayment, Modules\Finance\Models\CashVoucher::findOrFail($input['voucher']), $input['data'])['record'];
    } elseif ($input['operation'] === 'receipt-cost-completion-approve') {
        $record = app(Modules\Inventory\Services\InventoryReceiptCostProposalService::class)->approve(request(),
            Modules\Inventory\Models\InventoryDocument::findOrFail($input['receipt']),
            Modules\Inventory\Models\InventoryReceiptCostProposal::findOrFail($input['proposal']),
            'SYNTHETIC costing evidence', 'SYNTHETIC concurrent accounting approval');
    } elseif ($input['operation'] === 'opening-cost-correction-approve') {
        $record = app(Modules\Inventory\Services\OpeningStockCostCorrectionService::class)->approve(request(),
            Modules\Inventory\Models\OpeningStock::findOrFail($input['opening']),
            Modules\Inventory\Models\OpeningStockCostCorrection::findOrFail($input['correction']),
            'SYNTHETIC concurrent opening correction review');
    } elseif ($input['operation'] === 'opening-quantity-correction-approve') {
        $record = app(Modules\Inventory\Services\OpeningStockQuantityCorrectionService::class)->approve(request(),
            Modules\Inventory\Models\OpeningStock::findOrFail($input['opening']),
            Modules\Inventory\Models\OpeningStockQuantityCorrection::findOrFail($input['correction']),
            'SYNTHETIC concurrent opening quantity correction review');
    } elseif ($input['operation'] === 'manual-inventory-correction-approve') {
        $record = app(Modules\Inventory\Services\InventoryMovementCorrectionService::class)->approve(
            Modules\Inventory\Models\InventoryDocument::findOrFail($input['document']), $input['correction'],
            'SYNTHETIC concurrent independent manual movement review');
    } elseif ($input['operation'] === 'periodic-cost-close-approve') {
        $record = app(Modules\Inventory\Services\InventoryPeriodicCostCloseService::class)->approve(
            Modules\Inventory\Models\InventoryPeriodicCostClose::findOrFail($input['close']), $user->id, 'SYNTHETIC concurrent period approval');
    } elseif ($input['operation'] === 'standard-cost-settlement-approve') {
        $record = app(Modules\Inventory\Services\InventoryStandardCostService::class)->approveSettlement(
            Modules\Inventory\Models\InventoryStandardCostSettlement::findOrFail($input['settlement']), $user->id, 'SYNTHETIC concurrent standard approval');
    } elseif ($input['operation'] === 'purchase-receipt-reverse') {
        $record = app(Modules\Purchases\Services\ProcurementReceivingService::class)->reverseReceipt(
            Modules\Inventory\Models\UnpricedInventoryReceipt::findOrFail($input['receipt']), 'SYNTHETIC ordered receipt correction');
    } elseif ($input['operation'] === 'purchase-invoice-create') {
        $record = app(Modules\Purchases\Services\PurchaseInvoiceService::class)->create($input['data'])['record'];
    } elseif ($input['operation'] === 'purchase-invoice-reverse') {
        $record = app(Modules\Purchases\Services\PurchaseInvoiceService::class)->reverse(
            Modules\Purchases\Models\PurchaseInvoice::findOrFail($input['invoice']), 'SYNTHETIC ordered invoice correction');
    } elseif ($input['operation'] === 'supplier-payment-approve') {
        $record = app(Modules\Purchases\Services\ProcurementSettlementService::class)->approveSupplierPayment(
            Modules\Purchases\Models\SupplierPaymentContext::findOrFail($input['payment']));
    } elseif ($input['operation'] === 'supplier-payment-cancel') {
        $record = app(Modules\Purchases\Services\ProcurementSettlementService::class)->cancelSupplierPayment(
            Modules\Purchases\Models\SupplierPaymentContext::findOrFail($input['payment']), 'SYNTHETIC confirmed concurrent payment recovery');
    } elseif ($input['operation'] === 'production-progress') {
        $record = app(Modules\Production\Services\ProductionCycleService::class)->recordProgress(Modules\Production\Models\ProductionRun::findOrFail($input['run']), ['good_base_quantity' => '100']);
    } elseif ($input['operation'] === 'batch-start') {
        $record = Illuminate\Support\Facades\DB::transaction(function () use ($input) {
            $cycle = app(Modules\Production\Services\ProductionCycleService::class);
            $batch = $cycle->createRunBatch(Modules\Production\Models\ProductionOrder::findOrFail($input['order']), $input['batch']);
            $cycle->startRun($cycle->completeSetup($cycle->startSetup($batch->runs->sole())));
            return $batch;
        });
    } else {
        $record = app(Modules\Inventory\Services\InventoryMovementService::class)->createAndPost($input['header'], $input['lines']);
    }
    echo json_encode(['result' => 'applied', 'operation' => $input['operation'], 'id' => $record->id, 'root_transaction_attempts' => $rootTransactionAttempts]);
} catch (DomainException $error) {
    echo json_encode(['result' => 'blocked', 'operation' => $input['operation'], 'message' => $error->getMessage(), 'root_transaction_attempts' => $rootTransactionAttempts]);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()); exit(1);
}
WORKER;
    $directory = sys_get_temp_dir().'/mgypack-closure-race-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $processes = [];
    try {
        foreach ($operations as $index => $operation) {
            $input = [...$operation, 'context' => $operation['context'] ?? session()->all(),
                'barrier' => $directory.($orderedCompanyLock && $index === 0 ? '/first-go' : '/go'), 'ready' => $directory.'/ready-'.$index,
                'pause_on_company_lock' => $orderedCompanyLock && $index === 0,
                'lock_ready' => $directory.'/lock-ready', 'lock_release' => $directory.'/lock-release'];
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker], base_path(), ['MGYPACK_CLOSURE_WORKER' => json_encode($input, JSON_THROW_ON_ERROR), 'SESSION_DRIVER' => 'array']);
            $process->setTimeout(45)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (count(glob($directory.'/ready-*')) < count($operations) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($directory.'/ready-*')))->toBe(count($operations));
        if ($orderedCompanyLock) {
            expect(count($operations))->toBe(2);
            touch($directory.'/first-go');
            $deadline = microtime(true) + 15;
            while (! file_exists($directory.'/lock-ready') && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect(file_exists($directory.'/lock-ready'))->toBeTrue();
            touch($directory.'/go');
            $firstPid = json_decode(file_get_contents($directory.'/ready-0'), true, flags: JSON_THROW_ON_ERROR)['pid'];
            $secondPid = json_decode(file_get_contents($directory.'/ready-1'), true, flags: JSON_THROW_ON_ERROR)['pid'];
            $waiting = false;
            $deadline = microtime(true) + 15;
            while (! $waiting && microtime(true) < $deadline) {
                $witness = DB::selectOne('select ?::integer = any(pg_blocking_pids(?::integer)) as waiting', [$firstPid, $secondPid]);
                $waiting = (bool) $witness->waiting;
                if (! $waiting) {
                    usleep(10000);
                }
            }
            expect($waiting)->toBeTrue('Second PostgreSQL connection must actually wait for the first company lock.');
            touch($directory.'/lock-release');
        } else {
            touch($directory.'/go');
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
