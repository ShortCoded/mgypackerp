<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionRunCorrectionService;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedDatabase = 'mgypack_acceptance_closure_20261003';
$database = DB::selectOne('select current_database() as name')->name;

if ($database !== $expectedDatabase) {
    throw new RuntimeException("Refusing acceptance run against {$database}.");
}

$run = ProductionRun::query()->whereKey(385)->where('status', ProductionRun::StatusCompleted)->firstOrFail();
$company = Company::query()->findOrFail($run->company_id);
$branch = Branch::query()->findOrFail($run->branch_id);
$period = FinancialPeriod::query()->findOrFail($run->financial_period_id);
$preparer = User::query()->findOrFail(3117);
$approver = User::query()->findOrFail(3118);
$session = new Store('acceptance', new ArraySessionHandler(120));
$session->start();
$session->replace([
    OperatingContextService::CompanyIdKey => $company->id,
    OperatingContextService::CompanyDocNumKey => $company->doc_num,
    OperatingContextService::BranchIdKey => $branch->id,
    OperatingContextService::BranchDocNumKey => $branch->doc_num,
    OperatingContextService::FinancialPeriodIdKey => $period->id,
    OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
]);
request()->setLaravelSession($session);

$actAs = static function (User $user): void {
    Auth::setUser($user);
    request()->setUserResolver(static fn (): User => $user);
};

$documentState = static fn (): array => InventoryDocument::query()
    ->where('production_run_id', $run->id)
    ->orderBy('id')
    ->get(['id', 'doc_num', 'document_type', 'status', 'journal_entry_id', 'reversal_journal_entry_id'])
    ->map(static fn (InventoryDocument $document): array => $document->getAttributes())
    ->all();

$baseline = [
    'run' => $run->getRawOriginal(),
    'documents' => $documentState(),
    'corrections' => DB::table('production_run_corrections')->count(),
    'transactions' => DB::table('inventory_transactions')->count(),
    'journals' => DB::table('journal_entries')->count(),
];

DB::beginTransaction();

try {
    $actAs($preparer);
    $service = app(ProductionRunCorrectionService::class);
    $preview = $service->preview($run);

    if ($preview['blockers'] !== []) {
        throw new RuntimeException('The selected completed run has correction blockers.');
    }

    $proposal = $service->propose(
        $run,
        [
            'good_base_quantity' => '9',
            'rejected_base_quantity' => '1',
            'rework_base_quantity' => '0',
            'scrap_base_quantity' => '0',
        ],
        'SYNTHETIC isolated completed-run correction acceptance',
        $preview['fingerprint'],
        $run->actual_end_at->toDateString(),
    );

    $sameActorDenial = null;

    try {
        $service->approve($run, $proposal->id);
    } catch (DomainException $exception) {
        $sameActorDenial = $exception->getMessage();
    }

    if ($sameActorDenial === null) {
        throw new RuntimeException('The preparer unexpectedly approved their own correction.');
    }

    $actAs($approver);
    $approved = $service->approve($run, $proposal->id);
    $insideRun = $run->fresh();
    $inside = [
        'correction_id' => $proposal->id,
        'correction_status' => $approved->status,
        'prepared_by' => $approved->prepared_by,
        'approved_by' => $approved->approved_by,
        'same_actor_denial' => $sameActorDenial,
        'run_status' => $insideRun->status,
        'good_base_quantity' => $insideRun->good_base_quantity,
        'rejected_base_quantity' => $insideRun->rejected_base_quantity,
        'received_base_quantity' => $insideRun->received_base_quantity,
        'correction_sequence' => $insideRun->correction_sequence,
        'active_correction_id' => $insideRun->active_correction_id,
        'documents' => $documentState(),
        'transaction_delta' => DB::table('inventory_transactions')->count() - $baseline['transactions'],
        'journal_delta' => DB::table('journal_entries')->count() - $baseline['journals'],
    ];

    if ($inside['correction_status'] !== 'approved'
        || $inside['run_status'] !== ProductionRun::StatusRunning
        || $inside['good_base_quantity'] !== '9.00000000'
        || $inside['rejected_base_quantity'] !== '1.00000000'
        || $inside['received_base_quantity'] !== '0.00000000'
        || (int) $inside['active_correction_id'] !== (int) $proposal->id
        || (int) $inside['prepared_by'] !== $preparer->id
        || (int) $inside['approved_by'] !== $approver->id
        || $inside['transaction_delta'] <= 0
        || $inside['journal_delta'] <= 0) {
        throw new RuntimeException('Completed-run correction acceptance assertions failed.');
    }

    DB::rollBack();

    $afterRun = ProductionRun::query()->findOrFail($run->id);
    $after = [
        'run' => $afterRun->getRawOriginal(),
        'documents' => $documentState(),
        'corrections' => DB::table('production_run_corrections')->count(),
        'transactions' => DB::table('inventory_transactions')->count(),
        'journals' => DB::table('journal_entries')->count(),
    ];

    if ($after !== $baseline) {
        throw new RuntimeException('The isolated database did not return to its exact retained baseline.');
    }

    echo json_encode([
        'database' => $database,
        'run' => $run->run_number,
        'preparer' => $preparer->doc_num,
        'approver' => $approver->doc_num,
        'preview_blockers' => $preview['blockers'],
        'inside_transaction' => $inside,
        'rolled_back_to_baseline' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
