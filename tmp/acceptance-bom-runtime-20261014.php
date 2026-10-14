<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionMaterialRequestService;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedDatabase = 'mgypack_bom_acceptance_20261013';
$database = DB::selectOne('select current_database() as name')->name;

if ($database !== $expectedDatabase) {
    throw new RuntimeException("Refusing acceptance run against {$database}.");
}

$run = ProductionRun::query()->findOrFail(2);
$company = Company::query()->findOrFail($run->company_id);
$branch = Branch::query()->findOrFail($run->branch_id);
$period = FinancialPeriod::query()->findOrFail($run->financial_period_id);
$actor = User::query()->findOrFail(3);
$primaryRequirement = ProductionMaterialRequirement::query()->findOrFail(3);
$alternativeRequirement = ProductionMaterialRequirement::query()->findOrFail(4);

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

$request = Request::create('/acceptance/bom-runtime', 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(static fn (): User => $actor);
$app->instance('request', $request);
Auth::setUser($actor);

$fingerprint = static function (): array {
    $tables = [
        'account_classifications',
        'activity_log',
        'inventory_documents',
        'inventory_document_lines',
        'inventory_layer_allocations',
        'inventory_receipt_cost_bases',
        'inventory_receipt_layers',
        'inventory_reservations',
        'inventory_transactions',
        'journal_entries',
        'journal_entry_lines',
        'products',
        'production_material_request_lines',
        'production_material_request_reallocations',
        'production_material_requests',
        'production_material_requirements',
        'production_orders',
        'production_order_lines',
        'production_runs',
        'user_notifications',
    ];

    return collect($tables)->mapWithKeys(static fn (string $table): array => [
        $table => hash('sha256', json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR)),
    ])->all();
};

$permissions = [
    'allowed' => collect([
        'production.material_requests.create',
        'production.material_requests.view',
        'production.material_requests.edit',
        'production.material_requests.approve',
    ])->mapWithKeys(static fn (string $permission): array => [$permission => Gate::forUser($actor)->allows($permission)])->all(),
    'denied' => collect([
        'production.material_requests.issue',
        'production.orders.print',
        'production.runs.view',
        'production.reports.materials.view',
        'products.edit',
    ])->mapWithKeys(static fn (string $permission): array => [$permission => Gate::forUser($actor)->denies($permission)])->all(),
];

if (in_array(false, $permissions['allowed'], true) || in_array(false, $permissions['denied'], true)) {
    throw new RuntimeException('The retained actor permission matrix changed unexpectedly.');
}

$baseline = $fingerprint();
$service = app(ProductionMaterialRequestService::class);
$cycle = app(ProductionCycleService::class);

DB::beginTransaction();

try {
    $remainingPlannedCapacity = $service->remainingRequestableFor($primaryRequirement);
    $overCapQuantity = bcadd($remainingPlannedCapacity, '1.00000000', 8);
    $capDenial = null;
    $requestCountBeforeCap = DB::table('production_material_requests')->count();

    try {
        $service->create(
            $run,
            3,
            [$primaryRequirement->id => $overCapQuantity],
        );
    } catch (DomainException $exception) {
        $capDenial = $exception->getMessage();
    }

    if ($capDenial !== __('production_execution.messages.material_request_exceeds_bom')
        || DB::table('production_material_requests')->count() !== $requestCountBeforeCap) {
        throw new RuntimeException('The planned BOM cap rejection was not atomic.');
    }

    $primaryProduct = Product::query()->findOrFail($primaryRequirement->product_id);
    $rawInventoryClassification = AccountClassification::query()
        ->where('code', 'raw_material_inventory')
        ->firstOrFail();
    $rawInventoryClassification->forceFill(['code' => 'semi_finished_goods_inventory'])->save();
    $primaryProduct->forceFill(['item_classification' => Product::ClassificationSemiFinished])->save();
    $semiFinishedRequest = $service->create(
        $run,
        3,
        [$primaryRequirement->id => '1.00000000'],
    );
    $semiFinishedRequest = $service->approve($semiFinishedRequest);
    $semiFinishedLine = $semiFinishedRequest->lines()->sole();
    $semiFinishedReservation = DB::table('inventory_reservations')
        ->where('production_material_request_line_id', $semiFinishedLine->id)
        ->where('status', 'active')
        ->sole();
    $semiFinishedIssue = $service->issue($semiFinishedRequest, [$semiFinishedLine->id => '1.00000000']);
    $semiFinishedReturn = $cycle->returnMaterials($run, 3, [$primaryRequirement->id => '1.00000000']);
    $semiFinishedIssueLine = $semiFinishedIssue->lines()->sole();
    $semiFinishedReturnLine = $semiFinishedReturn->lines()->sole();

    $semiFinishedComponentScopeMatch = Product::query()->componentItems()->whereKey($primaryProduct->id)->exists();
    $semiFinishedClassification = $primaryProduct->item_classification;

    if (! $semiFinishedComponentScopeMatch
        || $semiFinishedRequest->fresh()->status !== ProductionMaterialRequest::StatusIssued
        || (int) $semiFinishedLine->product_id !== $primaryProduct->id
        || (int) $semiFinishedReservation->product_id !== $primaryProduct->id
        || $semiFinishedIssue->status !== InventoryDocument::StatusPosted
        || $semiFinishedReturn->status !== InventoryDocument::StatusPosted
        || (int) $semiFinishedIssueLine->product_id !== $primaryProduct->id
        || (int) $semiFinishedReturnLine->product_id !== $primaryProduct->id
        || bccomp((string) $semiFinishedIssueLine->total_cost, '0', 8) <= 0
        || bccomp((string) $semiFinishedReturnLine->total_cost, '0', 8) <= 0) {
        throw new RuntimeException('Semi-finished primary request lifecycle assertions failed.');
    }

    $primaryProduct->forceFill(['item_classification' => Product::ClassificationRawMaterial])->save();
    $rawInventoryClassification->forceFill(['code' => 'raw_material_inventory'])->save();

    $additionalRequest = $service->create(
        $run,
        3,
        [$alternativeRequirement->id => '1.00000000'],
        true,
        'SYNTHETIC isolated alternative reservation/issue/return acceptance',
    );
    $additionalRequest = $service->approve($additionalRequest);
    $additionalLine = $additionalRequest->lines()->sole();
    $reservation = DB::table('inventory_reservations')
        ->where('production_material_request_line_id', $additionalLine->id)
        ->where('status', 'active')
        ->sole();

    $issue = $service->issue($additionalRequest, [$additionalLine->id => '1.00000000']);
    $return = $cycle->returnMaterials($run, 3, [$alternativeRequirement->id => '1.00000000']);

    $issueLine = $issue->lines()->sole();
    $returnLine = $return->lines()->sole();
    $consumedReservation = DB::table('inventory_reservations')->where('id', $reservation->id)->firstOrFail();
    $insideRequest = ProductionMaterialRequest::query()->findOrFail($additionalRequest->id);

    $inside = [
        'remaining_planned_capacity' => $remainingPlannedCapacity,
        'over_cap_quantity' => $overCapQuantity,
        'cap_denial' => $capDenial,
        'semi_finished_primary' => [
            'product_id' => $primaryProduct->id,
            'classification' => $semiFinishedClassification,
            'component_scope_match' => $semiFinishedComponentScopeMatch,
            'request' => $semiFinishedRequest->doc_num,
            'request_status' => $semiFinishedRequest->fresh()->status,
            'reservation_id' => $semiFinishedReservation->id,
            'issue' => $semiFinishedIssue->doc_num,
            'issue_cost' => $semiFinishedIssueLine->total_cost,
            'return' => $semiFinishedReturn->doc_num,
            'return_cost' => $semiFinishedReturnLine->total_cost,
        ],
        'request' => [
            'doc_num' => $insideRequest->doc_num,
            'status' => $insideRequest->status,
            'type' => $insideRequest->request_type,
            'product_id' => $additionalLine->product_id,
            'requested' => $additionalLine->requested_quantity,
            'reserved' => $additionalLine->reserved_quantity,
            'issued' => $additionalLine->fresh()->issued_quantity,
        ],
        'reservation' => [
            'id' => $reservation->id,
            'product_id' => $reservation->product_id,
            'quantity' => $reservation->quantity,
            'status_after_issue' => $consumedReservation->status,
            'consumed_quantity' => $consumedReservation->consumed_quantity,
        ],
        'issue' => [
            'doc_num' => $issue->doc_num,
            'type' => $issue->document_type,
            'status' => $issue->status,
            'product_id' => $issueLine->product_id,
            'quantity' => $issueLine->quantity,
            'source_line_id' => $issueLine->source_line_id,
            'request_line_id' => $issueLine->production_material_request_line_id,
            'total_cost' => $issueLine->total_cost,
        ],
        'return' => [
            'doc_num' => $return->doc_num,
            'type' => $return->document_type,
            'status' => $return->status,
            'product_id' => $returnLine->product_id,
            'quantity' => $returnLine->quantity,
            'source_line_id' => $returnLine->source_line_id,
            'reservation_id' => $returnLine->inventory_reservation_id,
            'total_cost' => $returnLine->total_cost,
        ],
    ];

    if ($insideRequest->status !== ProductionMaterialRequest::StatusIssued
        || $insideRequest->request_type !== 'additional'
        || (int) $additionalLine->product_id !== $alternativeRequirement->product_id
        || $additionalLine->requested_quantity !== '1.00000000'
        || $additionalLine->reserved_quantity !== '1.00000000'
        || $additionalLine->fresh()->issued_quantity !== '1.00000000'
        || $consumedReservation->status !== 'consumed'
        || $consumedReservation->consumed_quantity !== '1.00000000'
        || $issue->document_type !== InventoryDocument::TypeAdditionalMaterialIssue
        || $return->document_type !== InventoryDocument::TypeMaterialReturn
        || $issue->status !== InventoryDocument::StatusPosted
        || $return->status !== InventoryDocument::StatusPosted
        || (int) $issueLine->product_id !== $alternativeRequirement->product_id
        || (int) $returnLine->product_id !== $alternativeRequirement->product_id
        || (int) $issueLine->source_line_id !== $alternativeRequirement->id
        || (int) $returnLine->source_line_id !== $alternativeRequirement->id
        || (int) $issueLine->production_material_request_line_id !== $additionalLine->id
        || $returnLine->inventory_reservation_id !== null
        || bccomp((string) $issueLine->total_cost, '0', 8) <= 0
        || bccomp((string) $returnLine->total_cost, '0', 8) <= 0) {
        throw new RuntimeException('Alternative reservation/issue/return acceptance assertions failed.');
    }

    DB::rollBack();

    if ($fingerprint() !== $baseline) {
        throw new RuntimeException('The isolated BOM database rows did not return to their complete retained fingerprints.');
    }

    echo json_encode([
        'database' => $database,
        'actor' => $actor->doc_num,
        'permissions' => $permissions,
        'inside_transaction' => $inside,
        'row_fingerprints_restored' => true,
        'postgres_sequences_not_reset_or_claimed' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
