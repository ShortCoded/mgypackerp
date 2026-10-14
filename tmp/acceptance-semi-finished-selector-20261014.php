<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\Select2\ProductRawMaterialSelect2Controller;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedDatabase = 'mgypack_production_demo_20261007';
$database = DB::selectOne('select current_database() as name')->name;

if ($database !== $expectedDatabase) {
    throw new RuntimeException("Refusing acceptance run against {$database}.");
}

$company = Company::query()->findOrFail(3);
$branch = Branch::query()->findOrFail(8);
$period = FinancialPeriod::query()->findOrFail(3);
$actor = User::query()->findOrFail(1);
$rawMaterial = Product::query()
    ->forCompany($company->id)
    ->active()
    ->rawMaterials()
    ->whereHas('unit', static fn ($units) => $units->where('status', 'active'))
    ->orderBy('id')
    ->firstOrFail();
$baselineCount = Product::query()->withTrashed()->count();

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
Auth::setUser($actor);

$select = static function (string $docNum, bool $alternativeRawOnly) use ($actor, $session): array {
    $request = Request::create('/admin/select2/component-products', 'GET', [
        'selected_doc_num' => $docNum,
        'alternative_raw_only' => $alternativeRawOnly ? '1' : '0',
    ]);
    $request->setLaravelSession($session);
    $request->setUserResolver(static fn (): User => $actor);
    $route = new Route(['GET'], '/admin/select2/component-products', static fn (): null => null);
    $route->name('admin.select2.component-products');
    $request->setRouteResolver(static fn (): Route => $route);
    app()->instance('request', $request);

    return app(ProductRawMaterialSelect2Controller::class)($request)->getData(true);
};

DB::beginTransaction();

try {
    $semiFinished = $rawMaterial->replicate();
    $semiFinished->doc_number = ((int) Product::query()->withTrashed()->max('doc_number')) + 1;
    $semiFinished->doc_num = 'SYNTHETIC-SEMI-FINISHED-COMPONENT-20261014';
    $semiFinished->name = 'SYNTHETIC isolated semi-finished component';
    $semiFinished->item_classification = Product::ClassificationSemiFinished;
    $semiFinished->status = 'active';
    $semiFinished->created_by = $actor->id;
    $semiFinished->updated_by = null;
    $semiFinished->deleted_by = null;
    $semiFinished->restored_by = null;
    $semiFinished->restored_at = null;
    $semiFinished->save();

    $primaryResult = $select($semiFinished->doc_num, false);
    $semiAlternativeResult = $select($semiFinished->doc_num, true);
    $rawAlternativeResult = $select($rawMaterial->doc_num, true);

    $inside = [
        'component_classifications' => Product::componentItemClassifications(),
        'component_scope_matches_semi_finished' => Product::query()->componentItems()->whereKey($semiFinished->id)->exists(),
        'primary_selector_ids' => collect($primaryResult['results'] ?? [])->pluck('id')->all(),
        'semi_finished_alternative_selector_ids' => collect($semiAlternativeResult['results'] ?? [])->pluck('id')->all(),
        'raw_alternative_selector_ids' => collect($rawAlternativeResult['results'] ?? [])->pluck('id')->all(),
    ];

    if (! in_array(Product::ClassificationSemiFinished, $inside['component_classifications'], true)
        || ! $inside['component_scope_matches_semi_finished']
        || $inside['primary_selector_ids'] !== [$semiFinished->doc_num]
        || $inside['semi_finished_alternative_selector_ids'] !== []
        || $inside['raw_alternative_selector_ids'] !== [$rawMaterial->doc_num]) {
        throw new RuntimeException('Semi-finished component selector acceptance assertions failed.');
    }

    DB::rollBack();

    if (Product::query()->withTrashed()->count() !== $baselineCount
        || Product::query()->withTrashed()->where('doc_num', 'SYNTHETIC-SEMI-FINISHED-COMPONENT-20261014')->exists()) {
        throw new RuntimeException('The isolated product fixture did not return to its exact retained baseline.');
    }

    echo json_encode([
        'database' => $database,
        'actor' => $actor->doc_num,
        'semi_finished_primary' => $inside,
        'rolled_back_to_baseline' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
