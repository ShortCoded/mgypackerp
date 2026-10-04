<?php

namespace Modules\Inventory\Http\Controllers;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Exports\InventoryPeriodicCostCloseExport;
use Modules\Inventory\Http\Requests\StoreInventoryPeriodicCostCloseRequest;
use Modules\Inventory\Models\InventoryPeriodicCostClose;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryPeriodicCostCloseReport;
use Modules\Inventory\Services\InventoryPeriodicCostCloseService;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryPeriodicCostCloseController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly InventoryCostPolicyService $policies,
        private readonly InventoryPeriodicCostCloseService $closes,
        private readonly InventoryReceiptCostProposalService $proposals,
    ) {}

    public function index(Request $request): View
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $allowed = $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();
        $records = InventoryPeriodicCostClose::query()->where('company_id', $company->id)->with(['scopeBranch', 'scopeStore'])
            ->when(! $this->scope->hasUnrestrictedBranchAccess($request->user()), fn ($query) => $query
                ->whereNotNull('scope_branch_id')->whereIn('scope_branch_id', $allowed ?: [0]))->latest('id')->paginate(25);
        $selectedBranch = $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
            ->where('branches.doc_num', old('branch_doc_num'))->first();
        $selectedStore = BranchStore::query()->whereIn('branch_id', $allowed ?: [0])->where('public_uuid', old('branch_store_uuid'))->first();
        $selectedAccount = Account::query()->forCompany((int) $company->id)->eligibleForDirectPosting()->whereKey(old('counterpart_account_id'))->first();

        return view('modules.inventory.cost-policies.periodic-index', compact('records', 'selectedBranch', 'selectedStore', 'selectedAccount'));
    }

    public function show(Request $request, InventoryPeriodicCostClose $close, InventoryPeriodicCostCloseReport $report): View
    {
        $this->authorizeClose($request, $close);
        $close->load(['scopeBranch', 'scopeStore', 'valueAdjustment.journalEntry']);
        $journalNumbers = $report->sourceJournalNumbers($close);

        return view('modules.inventory.cost-policies.periodic-show', compact('close', 'journalNumbers'));
    }

    public function accounts(Request $request, Select2ResponseService $select2): JsonResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $query = Account::query()->forCompany((int) $company->id)->eligibleForDirectPosting()->where('account_type', Account::TypeLiability)
            ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::InventoryCostCompletionClearing))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';
                $query->where('account_code', 'like', $term)->orWhere('name', 'like', $term)->orWhere('name_en', 'like', $term);
            }))->ordered();

        return response()->json($select2->paginated($query, $request, fn ($account): array => ['id' => (string) $account->id, 'text' => $account->codeNameLabel()]));
    }

    public function prepare(StoreInventoryPeriodicCostCloseRequest $request): RedirectResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $data = $request->validated();
        $branch = filled($data['branch_doc_num'] ?? null) ? $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
            ->where('branches.doc_num', $data['branch_doc_num'])->first() : null;
        abort_if(filled($data['branch_doc_num'] ?? null) && $branch === null, 403);
        $allowed = $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();
        $store = filled($data['branch_store_uuid'] ?? null) ? BranchStore::query()->whereIn('branch_id', $allowed ?: [0])
            ->where('public_uuid', $data['branch_store_uuid'])->first() : null;
        abort_if(filled($data['branch_store_uuid'] ?? null) && $store === null, 403);
        try {
            $close = $this->closes->prepare((int) $company->id, [...$data,
                'scope_branch_id' => $branch?->id, 'scope_store_id' => $store?->id], (int) $request->user()->id);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['close' => $exception->getMessage()]);
        }

        return to_route('admin.inventory.periodic-cost-closes.show', $close)->with('success', __('inventory_periodic_cost.prepared'));
    }

    public function export(Request $request, InventoryPeriodicCostClose $close, string $format, InventoryPeriodicCostCloseReport $report): BinaryFileResponse
    {
        $this->authorizeClose($request, $close);
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);
        $close->load(['scopeBranch', 'scopeStore', 'valueAdjustment.journalEntry']);

        return Excel::download(new InventoryPeriodicCostCloseExport($report->sections($close), $format === 'csv'), $close->doc_num.'.'.$format,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX);
    }

    public function print(Request $request, InventoryPeriodicCostClose $close, InventoryPeriodicCostCloseReport $report, ReportPdfService $pdf): Response
    {
        $this->authorizeClose($request, $close);
        $close->load(['scopeBranch', 'scopeStore', 'valueAdjustment.journalEntry']);

        return $pdf->stream('reports.inventory.periodic-cost-close', [
            'sections' => $report->sections($close), 'title' => __('inventory_periodic_cost.title').' — '.$close->doc_num,
            'companyPrintIdentity' => app(CompanyPrintIdentityService::class)->forCompany(Company::query()->findOrFail($close->company_id)),
        ], $close->doc_num.'.pdf', 'L');
    }

    public function approve(Request $request, InventoryPeriodicCostClose $close): RedirectResponse
    {
        $this->authorizeClose($request, $close);
        $data = $request->validate(['approval_reference' => ['required', 'string', 'min:5', 'max:500']]);
        try {
            $this->closes->approve($close, (int) $request->user()->id, $data['approval_reference']);
        } catch (DomainException $exception) {
            return back()->withErrors(['close' => $exception->getMessage()]);
        }

        return to_route('admin.inventory.periodic-cost-closes.show', $close)->with('success', __('inventory_periodic_cost.finalized'));
    }

    public function reject(Request $request, InventoryPeriodicCostClose $close): RedirectResponse
    {
        $this->authorizeClose($request, $close);
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:5', 'max:2000']]);
        try {
            $this->closes->reject($close, (int) $request->user()->id, $data['rejection_reason']);
        } catch (DomainException $exception) {
            return back()->withErrors(['close' => $exception->getMessage()]);
        }

        return to_route('admin.inventory.periodic-cost-closes.show', $close);
    }

    private function authorizeClose(Request $request, InventoryPeriodicCostClose $close): void
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null && (int) $company->id === (int) $close->company_id, 404);
        $this->policies->authorizeScopeOperation((int) $close->company_id, $close->scope_branch_id,
            $close->scope_store_id, (int) $request->user()->id, 'inventory.cost_policies.periodic.view');
        $this->proposals->assertImpactBranchAccess($request, $close->impact_snapshot);
    }
}
