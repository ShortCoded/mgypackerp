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
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Exports\InventoryPeriodicCostCloseExport;
use Modules\Inventory\Models\InventoryCostStandard;
use Modules\Inventory\Models\InventoryStandardCostSettlement;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Modules\Inventory\Services\InventoryStandardCostReport;
use Modules\Inventory\Services\InventoryStandardCostService;
use Modules\Production\Models\ProductionRun;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryStandardCostController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingContextService $context,
        private readonly InventoryCostPolicyService $policies,
        private readonly InventoryStandardCostService $standards,
        private readonly InventoryStandardCostReport $report,
    ) {}

    /** @return array{int, int} */
    private function scope(Request $request): array
    {
        $company = $this->companies->currentCompany($request);
        $branchId = $this->context->snapshot($request)['branch_id'];
        abort_unless($company && $branchId, 409);
        $this->policies->authorizeScopeOperation((int) $company->id, (int) $branchId, null, (int) $request->user()->id, 'inventory.cost_policies.standard.view');

        return [(int) $company->id, (int) $branchId];
    }

    public function index(Request $request): View
    {
        [$companyId, $branchId] = $this->scope($request);
        $versions = InventoryCostStandard::query()->where('company_id', $companyId)->where('branch_id', $branchId)->with('product')->latest('id')->paginate(25, ['*'], 'versions_page');
        $settlements = InventoryStandardCostSettlement::query()->where('company_id', $companyId)->where('branch_id', $branchId)->with('run')->latest('id')->paginate(25, ['*'], 'settlements_page');
        $product = Product::query()->where('company_id', $companyId)->where('item_classification', Product::ClassificationFinishedProduct)->find(old('product_id'));
        $run = ProductionRun::query()->where('company_id', $companyId)->where('branch_id', $branchId)->where('public_id', old('run_uuid'))->first();
        $accounts = Account::query()->forCompany($companyId)->eligibleForDirectPosting()->whereIn('id', array_filter([
            old('materials_variance_account_id'), old('labor_variance_account_id'), old('overhead_variance_account_id'), old('counterpart_account_id')]))->get()->keyBy('id');

        return view('modules.inventory.cost-policies.standard-index', compact('versions', 'settlements', 'product', 'run', 'accounts'));
    }

    public function lookup(Request $request, string $kind, Select2ResponseService $select2): JsonResponse
    {
        [$companyId, $branchId] = $this->scope($request);
        $term = '%'.trim((string) $request->input('q')).'%';
        if ($kind === 'products') {
            $query = Product::query()->where('company_id', $companyId)->where('status', 'active')->where('item_classification', Product::ClassificationFinishedProduct)
                ->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('doc_num', 'like', $term)->orWhere('name', 'like', $term)))->orderBy('doc_num');
            $label = fn ($row): array => ['id' => (string) $row->id, 'text' => $row->doc_num.' — '.$row->name];
        } elseif ($kind === 'runs') {
            $query = ProductionRun::query()->where('company_id', $companyId)->where('branch_id', $branchId)->where('status', ProductionRun::StatusCompleted)
                ->where('good_base_quantity', '>', 0)->whereColumn('received_base_quantity', 'good_base_quantity')
                ->when($request->filled('q'), fn ($query) => $query->where('run_number', 'like', $term))->orderBy('id');
            $label = fn ($row): array => ['id' => $row->public_id, 'text' => $row->run_number];
        } else {
            abort_unless(in_array($kind, ['variance-accounts', 'clearing-accounts'], true), 404);
            $query = Account::query()->forCompany($companyId)->eligibleForDirectPosting()
                ->when($kind === 'variance-accounts', fn ($query) => $query->where('account_type', Account::TypeExpense))
                ->when($kind === 'clearing-accounts', fn ($query) => $query->where('account_type', Account::TypeLiability)
                    ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::InventoryCostCompletionClearing)))
                ->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('account_code', 'like', $term)->orWhere('name', 'like', $term)->orWhere('name_en', 'like', $term)))->ordered();
            $label = fn ($row): array => ['id' => (string) $row->id, 'text' => $row->codeNameLabel()];
        }

        return response()->json($select2->paginated($query, $request, $label));
    }

    public function prepare(Request $request): RedirectResponse
    {
        [$companyId, $branchId] = $this->scope($request);
        $dates = app(DateFormatService::class);
        $request->merge(['effective_from' => $dates->parseDate((string) $request->input('effective_from'))?->toDateString(), 'effective_to' => $dates->parseDate((string) $request->input('effective_to'))?->toDateString()]);
        $rules = ['product_id' => ['required', 'integer'], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_from'], 'source_reference' => ['required', 'string', 'min:5', 'max:2000'], 'counterpart_account_id' => ['required', 'integer']];
        foreach (InventoryStandardCostService::Components as $part) {
            $request->merge([$part.'_unit_cost' => app(NumericFormatService::class)->normalizeForValidation($request->input($part.'_unit_cost'))]);
            $rules[$part.'_unit_cost'] = ['required', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'];
            $rules[$part.'_variance_account_id'] = ['required', 'integer'];
        }
        $data = $request->validate($rules);
        try {
            $record = $this->standards->prepareVersion($companyId, [...$data, 'branch_id' => $branchId], (int) $request->user()->id);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['standard' => $exception->getMessage()]);
        }

        return to_route('admin.inventory.standard-costs.show', ['kind' => 'versions', 'uuid' => $record->public_uuid]);
    }

    public function settle(Request $request): RedirectResponse
    {
        [$companyId, $branchId] = $this->scope($request);
        $request->merge(['posting_date' => app(DateFormatService::class)->parseDate((string) $request->input('posting_date'))?->toDateString()]);
        $data = $request->validate(['run_uuid' => ['required', 'uuid'], 'posting_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $run = ProductionRun::query()->where('company_id', $companyId)->where('branch_id', $branchId)->where('public_id', $data['run_uuid'])->firstOrFail();
        try {
            $record = $this->standards->prepareSettlement($run, $data['posting_date'], $data['reason'], (int) $request->user()->id);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['standard' => $exception->getMessage()]);
        }

        return to_route('admin.inventory.standard-costs.show', ['kind' => 'settlements', 'uuid' => $record->public_uuid]);
    }

    private function record(Request $request, string $kind, string $uuid): InventoryCostStandard|InventoryStandardCostSettlement
    {
        abort_unless(in_array($kind, ['versions', 'settlements'], true), 404);
        $company = $this->companies->currentCompany($request);
        abort_unless($company, 409);
        $record = ($kind === 'versions' ? InventoryCostStandard::query() : InventoryStandardCostSettlement::query())
            ->where('company_id', $company->id)->where('public_uuid', $uuid)->firstOrFail();
        $this->policies->authorizeScopeOperation((int) $record->company_id, (int) $record->branch_id, null, (int) $request->user()->id, 'inventory.cost_policies.standard.view');
        if ($record instanceof InventoryStandardCostSettlement) {
            app(InventoryReceiptCostProposalService::class)->assertImpactBranchAccess($request, $record->impact_snapshot);
        }

        return $record;
    }

    public function show(Request $request, string $kind, string $uuid): View
    {
        $record = $this->record($request, $kind, $uuid);
        $sections = $this->report->sections($record);

        return view('modules.inventory.cost-policies.standard-show', compact('record', 'kind', 'sections'));
    }

    public function decision(Request $request, string $kind, string $uuid, string $decision): RedirectResponse
    {
        $record = $this->record($request, $kind, $uuid);
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $data = $request->validate(['reference' => ['required', 'string', 'min:5', 'max:500']]);
        try {
            if ($decision === 'reject') {
                $this->standards->reject($record, (int) $request->user()->id, $data['reference']);
            } elseif ($record instanceof InventoryCostStandard) {
                $this->standards->approveVersion($record, (int) $request->user()->id, $data['reference']);
            } else {
                $this->standards->approveSettlement($record, (int) $request->user()->id, $data['reference']);
            }
        } catch (DomainException $exception) {
            return back()->withErrors(['standard' => $exception->getMessage()]);
        }

        return back();
    }

    public function output(Request $request, string $kind, string $uuid, string $format, ReportPdfService $pdf): BinaryFileResponse|Response
    {
        $record = $this->record($request, $kind, $uuid);
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);
        abort_unless($request->user()->can('inventory.cost_policies.standard.'.($format === 'pdf' ? 'print' : 'export')), 403);
        $sections = $this->report->sections($record);
        if ($format === 'pdf') {
            return $pdf->stream('reports.inventory.periodic-cost-close', ['sections' => $sections, 'title' => __('inventory_standard_cost.title').' — '.$record->doc_num,
                'companyPrintIdentity' => app(CompanyPrintIdentityService::class)->forCompany(Company::findOrFail($record->company_id))], $record->doc_num.'.pdf', 'L');
        }

        return Excel::download(new InventoryPeriodicCostCloseExport($sections, $format === 'csv'), $record->doc_num.'.'.$format, $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX);
    }
}
