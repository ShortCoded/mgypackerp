<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Http\Controllers\ProductionMaterialRequestController;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionMaterialRequestService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::selectOne('select current_database() as name')->name;
$allowedDatabases = [
    'mgypack_bom_acceptance_20261013',
    'mgypack_oct14_endpoint_acceptance',
    'mgypack_production_demo_20261007',
];

if (! in_array($database, $allowedDatabases, true)) {
    throw new RuntimeException("Refusing endpoint acceptance against {$database}.");
}

$contextSession = static function (int $companyId, int $branchId, int $periodId): Store {
    $company = Company::query()->findOrFail($companyId);
    $branch = Branch::query()->findOrFail($branchId);
    $period = FinancialPeriod::query()->findOrFail($periodId);
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

    return $session;
};

$bindRequest = static function (Request $request, User $actor, Store $session) use ($app): void {
    $request->headers->set('Accept', 'application/json');
    $request->setLaravelSession($session);
    $request->setUserResolver(static fn (): User => $actor);
    Auth::setUser($actor);
    $app->instance('request', $request);
};

$fingerprint = static function (): array {
    $tables = [
        'activity_log',
        'inventory_documents',
        'inventory_document_lines',
        'inventory_layer_allocations',
        'inventory_receipt_layers',
        'inventory_reservations',
        'inventory_transactions',
        'journal_entries',
        'journal_entry_lines',
        'production_material_request_lines',
        'production_material_requests',
        'production_material_requirements',
        'production_runs',
        'user_notifications',
    ];

    return collect($tables)->mapWithKeys(static fn (string $table): array => [
        $table => hash('sha256', json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR)),
    ])->all();
};

if ($database === 'mgypack_bom_acceptance_20261013') {
    $actor = User::query()->findOrFail(3);
    $materialRequest = ProductionMaterialRequest::query()->orderBy('id')->firstOrFail();
    $run = ProductionRun::query()->findOrFail($materialRequest->production_run_id);
    $order = ProductionOrder::query()->findOrFail($materialRequest->production_order_id);
    $session = $contextSession($materialRequest->company_id, $materialRequest->branch_id, $materialRequest->financial_period_id);
    $before = $fingerprint();

    $issueRequest = Request::create('/admin/production/material-requests/'.$materialRequest->doc_num.'/issue', 'POST');
    $bindRequest($issueRequest, $actor, $session);
    $issueStatus = null;

    try {
        app(ProductionMaterialRequestController::class)->issue($issueRequest, $materialRequest);
    } catch (HttpExceptionInterface $exception) {
        $issueStatus = $exception->getStatusCode();
    }

    $endpointStatus = static function (string $url) use ($actor, $session, $bindRequest): int {
        $request = Request::create(parse_url($url, PHP_URL_PATH), 'GET');
        $bindRequest($request, $actor, $session);
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response->getStatusCode();
    };

    $statuses = [
        'material_request_issue_controller' => $issueStatus,
        'work_order_print_endpoint' => $endpointStatus(route('admin.production.work-orders.print', $order)),
        'production_run_show_endpoint' => $endpointStatus(route('admin.production.runs.show', $run)),
    ];

    if (collect($statuses)->contains(fn (?int $status): bool => $status !== 403) || $fingerprint() !== $before) {
        throw new RuntimeException('Denied endpoint/controller authorization or no-write assertions failed: '.json_encode($statuses));
    }

    echo json_encode([
        'database' => $database,
        'actor' => $actor->doc_num,
        'statuses' => $statuses,
        'row_fingerprints_unchanged' => true,
        'permission_grants_performed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

    exit(0);
}

$actor = User::query()->findOrFail(1);
$sourceRequest = ProductionMaterialRequest::query()->findOrFail(2);
$run = ProductionRun::query()->findOrFail($sourceRequest->production_run_id);
$requirement = $run->requirements()->orderBy('id')->firstOrFail();
$session = $contextSession($sourceRequest->company_id, $sourceRequest->branch_id, $sourceRequest->financial_period_id);
$baseline = $fingerprint();

if (! $actor->canAny(['production.material_requests.issue', 'inventory.documents.issue'])) {
    throw new RuntimeException('The selected existing fixture actor is no longer issue-authorized.');
}

DB::beginTransaction();

try {
    $setupRequest = Request::create('/acceptance/material-request-controller', 'POST');
    $bindRequest($setupRequest, $actor, $session);
    $service = app(ProductionMaterialRequestService::class);
    $materialRequest = $service->create(
        $run,
        (int) $sourceRequest->branch_store_id,
        [$requirement->id => '1.00000000'],
        true,
        'SYNTHETIC isolated authorized controller acceptance',
    );
    $materialRequest = $service->approve($materialRequest);
    $line = $materialRequest->lines()->sole();

    $request = Request::create('/admin/production/material-requests/'.$materialRequest->doc_num.'/issue', 'POST', [
        'lines' => [[
            'request_line_id' => $line->id,
            'quantity' => '1.00000000',
        ]],
    ]);
    $bindRequest($request, $actor, $session);
    $response = app(ProductionMaterialRequestController::class)->issue($request, $materialRequest);
    $payload = $response->getData(true);
    $document = InventoryDocument::query()->where('doc_num', $payload['doc_num'] ?? null)->firstOrFail();
    $documentLine = $document->lines()->sole();

    if ($response->getStatusCode() !== 200
        || ($payload['success'] ?? false) !== true
        || $document->status !== InventoryDocument::StatusPosted
        || (int) $document->production_material_request_id !== $materialRequest->id
        || (int) $documentLine->production_material_request_line_id !== $line->id
        || bccomp((string) $documentLine->total_cost, '0', 8) <= 0) {
        throw new RuntimeException('The issue-authorized controller path did not post the expected linked costed issue.');
    }

    $inside = [
        'response_status' => $response->getStatusCode(),
        'request' => $materialRequest->doc_num,
        'request_status' => $materialRequest->fresh()->status,
        'document' => $document->doc_num,
        'document_status' => $document->status,
        'request_line_id' => $line->id,
        'document_line_cost' => $documentLine->total_cost,
    ];

    DB::rollBack();

    if ($fingerprint() !== $baseline) {
        throw new RuntimeException('The isolated successful controller rows did not return to their complete retained fingerprints.');
    }

    echo json_encode([
        'database' => $database,
        'actor' => $actor->doc_num,
        'existing_issue_authorization' => true,
        'inside_transaction' => $inside,
        'row_fingerprints_restored' => true,
        'postgres_sequences_not_reset_or_claimed' => true,
        'permission_grants_performed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
