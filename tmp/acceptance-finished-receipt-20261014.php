<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedDatabase = 'mgypack_production_demo_20261007';
$database = DB::selectOne('select current_database() as name')->name;

if ($database !== $expectedDatabase) {
    throw new RuntimeException("Refusing acceptance run against {$database}.");
}

$run = ProductionRun::query()->findOrFail(33);
$company = Company::query()->findOrFail($run->company_id);
$branch = Branch::query()->findOrFail($run->branch_id);
$period = FinancialPeriod::query()->findOrFail($run->financial_period_id);
$preparer = User::query()->findOrFail(1);
$approver = User::query()->findOrFail(5);
$branchStoreId = 4;
$requirement = $run->requirements()->orderBy('id')->firstOrFail();

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
$bindActor = static function (User $actor) use ($app, $session): Request {
    $request = Request::create('/acceptance/finished-receipt', 'POST');
    $request->setLaravelSession($session);
    $request->setUserResolver(static fn (): User => $actor);
    $app->instance('request', $request);
    Auth::setUser($actor);

    return $request;
};

$bindActor($preparer);

$fingerprint = static function (): array {
    $tables = [
        'activity_log',
        'branch_stores',
        'inventory_allocation_cost_completions',
        'inventory_documents',
        'inventory_document_lines',
        'inventory_layer_allocations',
        'inventory_receipt_cost_proposals',
        'inventory_receipt_cost_bases',
        'inventory_receipt_layers',
        'inventory_reservations',
        'inventory_transactions',
        'inventory_value_adjustment_lines',
        'inventory_value_adjustments',
        'journal_entries',
        'journal_entry_lines',
        'production_order_lines',
        'production_order_stage_snapshots',
        'production_orders',
        'production_quality_receipt_allocations',
        'quality_inspections',
        'production_runs',
        'user_notifications',
    ];

    return collect($tables)->mapWithKeys(static fn (string $table): array => [
        $table => hash('sha256', json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR)),
    ])->all();
};

$baseline = $fingerprint();
$cycle = app(ProductionCycleService::class);
$costs = app(ProductionCostService::class);
$movements = app(InventoryMovementService::class);
$receiptCosts = app(InventoryReceiptCostProposalService::class);

DB::beginTransaction();

try {
    $finalInspection = $run->inspections()->orderByDesc('id')->firstOrFail();
    $finalInspection->forceFill([
        'quality_inspection_type_id' => 1,
        'sampled_at' => now(),
        'status' => ProductionQualityInspection::StatusApproved,
        'result' => 'passed',
        'disposition' => 'release',
        'approved_by' => $preparer->id,
        'approved_at' => now(),
    ])->save();

    DB::beginTransaction();
    $unvaluedIssue = InventoryDocument::query()->create([
        'doc_number' => ((int) InventoryDocument::query()->withTrashed()->max('doc_number')) + 1,
        'doc_num' => 'SYNTHETIC-UNVALUED-ISSUE-20261014',
        'company_id' => $company->id,
        'financial_period_id' => $period->id,
        'branch_id' => $branch->id,
        'branch_store_id' => $branchStoreId,
        'production_run_id' => $run->id,
        'document_type' => InventoryDocument::TypeMaterialIssue,
        'document_date' => now()->toDateString(),
        'status' => InventoryDocument::StatusPosted,
        'purpose' => 'SYNTHETIC isolated unvalued production issue',
        'created_by' => $preparer->id,
    ]);
    $unvaluedLine = $unvaluedIssue->lines()->create([
        'company_id' => $company->id,
        'financial_period_id' => $period->id,
        'line_number' => 1,
        'production_run_id' => $run->id,
        'product_id' => $requirement->product_id,
        'unit_id' => $requirement->unit_id,
        'quantity' => '1.00000000',
        'unit_cost' => null,
        'total_cost' => null,
    ]);

    $missingCostDenial = null;

    try {
        $cycle->receiveFinishedGoods($run->fresh(), $branchStoreId, '1.00000000');
    } catch (DomainException $exception) {
        $missingCostDenial = $exception->getMessage();
    }

    if ($missingCostDenial !== __('production_execution.messages.unvalued_material_cost')) {
        throw new RuntimeException('The native receipt did not reject with the expected unvalued-material message: '.($missingCostDenial ?? 'no exception'));
    }

    $unvaluedIssueDocNum = $unvaluedIssue->doc_num;
    DB::rollBack();

    $costCompletionStore = BranchStore::query()->create([
        'branch_id' => $branch->id,
        'name' => 'SYNTHETIC isolated native cost completion',
        'created_by' => $preparer->id,
    ]);
    $unpricedReceipt = $movements->createAndPost([
        'company_id' => $company->id,
        'financial_period_id' => $period->id,
        'branch_id' => $branch->id,
        'branch_store_id' => $costCompletionStore->id,
        'document_type' => InventoryDocument::TypeReceipt,
        'document_date' => now()->toDateString(),
        'purpose' => 'SYNTHETIC isolated unpriced material receipt',
    ], [[
        'product_id' => $requirement->product_id,
        'unit_id' => $requirement->unit_id,
        'quantity' => '1.00000000',
    ]]);
    $unpricedReceiptLine = $unpricedReceipt->lines()->sole();

    $counterpart = app(PostingAccountResolver::class)->resolve(
        $company->id,
        PostingAccountResolver::InventoryAdjustmentGain,
        'SYNTHETIC isolated native receipt cost completion',
    );
    $proposal = $receiptCosts->prepare(request(), $unpricedReceipt, [
        'basis' => InventoryReceiptCostProposal::BasisEstimate,
        'source_reference' => 'SYNTHETIC isolated source cost evidence',
        'basis_note' => 'SYNTHETIC native service preparation before finished receipt',
        'posting_date' => now()->toDateString(),
        'counterpart_account_id' => $counterpart->id,
    ], [$unpricedReceiptLine->id => '1.00000000'], null);

    $bindActor($approver);
    $receiptCosts->approve(
        request(),
        $unpricedReceipt,
        $proposal,
        (string) $proposal->source_reference,
        'SYNTHETIC independent native cost completion approval',
    );

    $bindActor($preparer);
    $pricedProductionIssue = $movements->createAndPost([
        'company_id' => $company->id,
        'financial_period_id' => $period->id,
        'branch_id' => $branch->id,
        'branch_store_id' => $costCompletionStore->id,
        'production_run_id' => $run->id,
        'document_type' => InventoryDocument::TypeMaterialIssue,
        'document_date' => now()->toDateString(),
        'purpose' => 'SYNTHETIC native issue after independent cost completion',
    ], [[
        'production_run_id' => $run->id,
        'product_id' => $requirement->product_id,
        'unit_id' => $requirement->unit_id,
        'quantity' => '1.00000000',
    ]]);
    $pricedProductionIssueLine = $pricedProductionIssue->lines()->sole();
    $completedReceiptCost = $costs->receiptCost($run->fresh(), '1.00000000');
    $receipt = $cycle->receiveFinishedGoods($run->fresh(), $branchStoreId, '1.00000000');
    $receiptLine = $receipt->lines()->sole();
    $insideRun = $run->fresh();

    if ($receipt->status !== InventoryDocument::StatusPosted
        || $proposal->fresh()->status !== InventoryReceiptCostProposal::StatusApproved
        || (int) $proposal->prepared_by !== (int) $preparer->id
        || (int) $proposal->fresh()->approved_by !== (int) $approver->id
        || bccomp((string) $pricedProductionIssueLine->total_cost, '0', 8) <= 0
        || $receipt->document_type !== InventoryDocument::TypeProductionReceipt
        || (int) $receipt->production_run_id !== $run->id
        || bccomp((string) $receiptLine->total_cost, '0', 8) <= 0
        || bccomp((string) $receiptLine->total_cost, $completedReceiptCost, 8) !== 0
        || bccomp((string) $insideRun->received_base_quantity, '1.00000000', 8) !== 0
        || ! $receipt->transactions()->exists()
        || ! DB::table('journal_entries')->where('id', $receipt->journal_entry_id)->exists()) {
        throw new RuntimeException('The native finished receipt did not post its expected inventory, cost, counter, and journal lineage.');
    }

    $inside = [
        'run' => $run->run_number,
        'missing_cost_denial' => $missingCostDenial,
        'native_cost_completion' => [
            'unpriced_receipt' => $unpricedReceipt->doc_num,
            'missing_cost_fixture' => $unvaluedIssueDocNum,
            'production_issue' => $pricedProductionIssue->doc_num,
            'production_issue_cost' => $pricedProductionIssueLine->total_cost,
            'proposal_id' => $proposal->id,
            'proposal_status' => $proposal->fresh()->status,
            'prepared_by' => $preparer->doc_num,
            'approved_by' => $approver->doc_num,
            'source_receipt_line_cost_remained_null' => $unpricedReceiptLine->fresh()->total_cost === null,
            'adjustment_id' => $proposal->fresh()->valueAdjustment?->id,
        ],
        'completed_receipt_cost' => $completedReceiptCost,
        'receipt' => $receipt->doc_num,
        'receipt_status' => $receipt->status,
        'receipt_line_cost' => $receiptLine->total_cost,
        'received_base_quantity' => $insideRun->received_base_quantity,
        'journal_entry_id' => $receipt->journal_entry_id,
    ];

    DB::rollBack();

    if ($fingerprint() !== $baseline) {
        throw new RuntimeException('The isolated finished-receipt rows did not return to their complete retained fingerprints.');
    }

    echo json_encode([
        'database' => $database,
        'preparer' => $preparer->doc_num,
        'approver' => $approver->doc_num,
        'inside_transaction' => $inside,
        'row_fingerprints_restored' => true,
        'postgres_sequences_not_reset_or_claimed' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
