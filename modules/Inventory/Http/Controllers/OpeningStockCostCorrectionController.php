<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Http\Requests\OpeningStockCostCorrectionRequest;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockCostCorrection;
use Modules\Inventory\Services\OpeningStockCostCorrectionService;

class OpeningStockCostCorrectionController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly OperatingScopeAccessService $access,
        private readonly OpeningStockCostCorrectionService $corrections,
    ) {}

    public function index(Request $request): View
    {
        $scope = $this->scope($request);
        $records = OpeningStock::query()->where('company_id', $scope['company_id'])->where('branch_id', $scope['branch_id'])
            ->where('approved', true)->where('status', OpeningStock::StatusApproved)
            ->whereIn('financial_period_id', $this->access->allowedFinancialPeriodQuery($request->user())->select('financial_periods.id'))
            ->latest('document_date')->latest('id')->paginate(25);

        return view('modules.inventory.opening-stock-cost-corrections.index', compact('records'));
    }

    public function show(Request $request, OpeningStock $openingStock): View
    {
        $this->assertSource($request, $openingStock);
        $record = $openingStock->load('lines.product');
        $proposals = OpeningStockCostCorrection::query()->where('opening_stock_id', $record->id)
            ->where('company_id', $record->company_id)->with(['preparedBy', 'approvedBy', 'valueAdjustment.journalEntry'])->latest('id')->get();
        foreach ($proposals as $proposal) {
            $this->guard(fn () => $this->corrections->assertImpactBranchAccess($request, $proposal->plan));
        }
        $selectedCounterpart = $this->accountQuery((int) $record->company_id)->whereKey(old('counterpart_account_id'))->first();

        return view('modules.inventory.opening-stock-cost-corrections.show', compact('record', 'proposals', 'selectedCounterpart'));
    }

    public function accounts(Request $request, Select2ResponseService $select2): JsonResponse
    {
        $scope = $this->scope($request);
        $query = $this->accountQuery($scope['company_id'])->when($request->filled('q'), fn ($query) => $query->where(function ($query) use ($request): void {
            $term = trim((string) $request->input('q'));
            $query->where('account_code', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%')->orWhere('name_en', 'like', '%'.$term.'%');
        }))->ordered();

        return response()->json($select2->paginated($query, $request, fn (Account $account): array => [
            'id' => (string) $account->id, 'text' => $account->codeNameLabel(),
        ]));
    }

    public function prepare(OpeningStockCostCorrectionRequest $request, OpeningStock $openingStock): RedirectResponse
    {
        $this->assertSource($request, $openingStock);
        $data = $request->validated();
        $this->guard(fn () => $this->corrections->prepare($request, $openingStock, $data, $data['unit_costs']));

        return to_route('admin.inventory.opening-stock-cost-corrections.show', $openingStock)
            ->with('success', __('opening_stock_cost_correction.messages.prepared'));
    }

    public function approve(Request $request, OpeningStock $openingStock, OpeningStockCostCorrection $correction): RedirectResponse
    {
        $this->assertSource($request, $openingStock);
        $data = $request->validate(['approval_reference' => ['required', 'string', 'min:5', 'max:255']]);
        $this->guard(fn () => $this->corrections->approve($request, $openingStock, $correction, $data['approval_reference']));

        return to_route('admin.inventory.opening-stock-cost-corrections.show', $openingStock)
            ->with('success', __('opening_stock_cost_correction.messages.approved'));
    }

    public function reject(Request $request, OpeningStock $openingStock, OpeningStockCostCorrection $correction): RedirectResponse
    {
        $this->assertSource($request, $openingStock);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->guard(fn () => $this->corrections->reject($request, $openingStock, $correction, $data['reason']));

        return to_route('admin.inventory.opening-stock-cost-corrections.show', $openingStock)
            ->with('success', __('opening_stock_cost_correction.messages.rejected'));
    }

    /** @return Builder<Account> */
    private function accountQuery(int $companyId): Builder
    {
        return Account::query()->forCompany($companyId)->eligibleForDirectPosting()->where('account_type', Account::TypeLiability)
            ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::InventoryCostCompletionClearing)->where('status', 'active'));
    }

    /** @return array{company_id: int, branch_id: int, financial_period_id: int} */
    private function scope(Request $request): array
    {
        abort_unless($request->user()?->canAny(['inventory.opening_stock_cost_corrections.prepare', 'inventory.opening_stock_cost_corrections.approve']), 403);
        $scope = $this->context->snapshot($request);
        abort_unless($scope['company_id'] && $scope['branch_id'] && $scope['financial_period_id'], 422);
        abort_unless($this->context->allowedBranchQueryForCurrentCompany($request)->whereKey($scope['branch_id'])->exists(), 403);
        abort_unless($this->access->allowedFinancialPeriodQuery($request->user())->where('financial_periods.company_id', $scope['company_id'])
            ->whereKey($scope['financial_period_id'])->exists(), 403);

        return $scope;
    }

    private function assertSource(Request $request, OpeningStock $source): void
    {
        $scope = $this->scope($request);
        abort_unless((int) $source->company_id === $scope['company_id'] && (int) $source->branch_id === $scope['branch_id']
            && $this->access->allowedFinancialPeriodQuery($request->user())->whereKey($source->financial_period_id)->exists(), 404);
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['opening_stock_cost' => $exception->getMessage()]);
        }
    }
}
