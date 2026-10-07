<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Production\Exports\ProductionReportExport;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionReportService;
use Modules\Sales\Services\SalesCycleReadService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductionReportController extends Controller
{
    /** @var list<string> */
    private const Sections = ['overview', 'orders', 'runs', 'materials', 'quality', 'receipts', 'control'];

    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionReportService $reports,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
        private readonly SalesCycleReadService $salesCycle,
        private readonly NumericFormatService $numbers,
        private readonly DateFormatService $dates,
        private readonly Select2ResponseService $select2,
    ) {}

    public function controlLookup(Request $request, string $kind): JsonResponse
    {
        $this->authorizeSection($request, 'control', 'view');
        abort_unless(in_array($kind, ['product', 'machine', 'shift', 'stage', 'order'], true), 404);
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'], 422, __('production_execution.messages.operating_context_required'));
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'branch_doc_num' => ['nullable', 'string', 'max:50'],
        ]);
        $branches = $this->context->allowedBranchQueryForCurrentCompany($request)
            ->where('branches.type', Branch::TypeFactory)
            ->get(['branches.id', 'branches.doc_num']);
        $branch = filled($input['branch_doc_num'] ?? null)
            ? $branches->firstWhere('doc_num', $input['branch_doc_num'])
            : null;
        abort_if(filled($input['branch_doc_num'] ?? null) && $branch === null, 403);

        $query = DB::table('production_runs as runs')
            ->where('runs.company_id', $context['company_id'])
            ->where('runs.financial_period_id', $context['financial_period_id'])
            ->whereIn('runs.branch_id', $branch ? [(int) $branch->getKey()] : ($branches->modelKeys() ?: [0]))
            ->whereNull('runs.deleted_at');
        $shiftName = 'coalesce('.DB::connection()->getQueryGrammar()->wrap('shift_entry.sheet_fields->shift_name').', production_shifts.name)';
        [$column, $label] = match ($kind) {
            'product' => ['products.doc_num', 'products.name'],
            'machine' => ['coalesce(fixed_assets.asset_name, production_machines.name)', 'coalesce(fixed_assets.asset_name, production_machines.name)'],
            'shift' => [$shiftName, $shiftName],
            'stage' => ['production_order_stage_snapshots.stage_name', 'production_order_stage_snapshots.stage_name'],
            'order' => ['production_orders.doc_num', 'production_orders.doc_num'],
        };
        $query = match ($kind) {
            'product' => $query->join('products', 'products.id', '=', 'runs.product_id'),
            'machine' => $query->leftJoin('fixed_assets', 'fixed_assets.id', '=', 'runs.fixed_asset_id')
                ->leftJoin('production_machines', 'production_machines.id', '=', 'runs.production_machine_id'),
            'shift' => $query->leftJoin('production_shifts', 'production_shifts.id', '=', 'runs.production_shift_id')
                ->leftJoin('production_shift_entries as shift_entry', fn ($join) => $join->on('shift_entry.production_run_id', '=', 'runs.id')
                    ->on('shift_entry.company_id', '=', 'runs.company_id')->on('shift_entry.branch_id', '=', 'runs.branch_id')),
            'stage' => $query->join('production_order_stage_snapshots', 'production_order_stage_snapshots.id', '=', 'runs.production_order_stage_snapshot_id'),
            'order' => $query->join('production_orders', 'production_orders.id', '=', 'runs.production_order_id'),
        };
        $search = trim((string) ($input['q'] ?? ''));
        if ($search !== '') {
            $query->whereRaw('lower('.$column.') like ?', ['%'.mb_strtolower(addcslashes($search, '%_\\')).'%']);
        }

        return response()->json($this->select2->paginated(
            $query->whereNotNull(DB::raw($column))->selectRaw($column.' as id, '.$label.' as label')
                ->distinct()->orderBy('id'),
            $request,
            fn (object $row): array => ['id' => (string) $row->id, 'text' => (string) $row->id.($row->label !== $row->id ? ' — '.$row->label : '')],
        ));
    }

    public function index(Request $request): View
    {
        $section = $this->section($request);
        $this->authorizeSection($request, $section, 'view');
        [, $report] = $this->report($request, $section);

        return view('modules.production.reports.index', [
            ...$report,
            'section' => $section,
            'numbers' => $this->numbers,
            'dates' => $this->dates,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $section = $this->section($request);
        $this->authorizeSection($request, $section, 'export');
        [, $report] = $this->report($request, $section);

        return Excel::download(
            new ProductionReportExport($report, $section),
            'production-'.$section.'-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function exportCsv(Request $request): BinaryFileResponse
    {
        $section = $this->section($request);
        abort_unless($section === 'control', 404);
        $this->authorizeSection($request, $section, 'export');
        $datasets = ['products', 'daily', 'daily_materials', 'machines', 'material_summary', 'runs', 'materials'];
        $dataset = $request->validate(['dataset' => ['required', Rule::in($datasets)]])['dataset'];
        [, $report] = $this->report($request, $section);
        $sheet = (new ProductionReportExport($report, $section))->sheets()[array_search($dataset, $datasets, true)];

        return Excel::download(
            $sheet,
            'production-control-'.$dataset.'-'.now()->format('Ymd-His').'.csv',
            \Maatwebsite\Excel\Excel::CSV,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function print(Request $request): Response
    {
        $section = $this->section($request);
        $this->authorizeSection($request, $section, 'print');
        [$context, $report] = $this->report($request, $section);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.production.operations', [
            ...$report,
            'section' => $section,
            'numbers' => $this->numbers,
            'dates' => $this->dates,
            'title' => __('production_execution.reports.sections.'.$section),
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], 'production-'.$section.'-report.pdf');
    }

    public function showControlRun(Request $request, ProductionRun $productionRun): View
    {
        $this->assertControlRunVisible($request, $productionRun, 'view');

        return view('modules.production.runs.show', [
            'record' => $productionRun->load([
                'order.branch', 'order.salesOrder', 'orderLine.stageSnapshots', 'product', 'fixedAsset', 'stageSnapshot',
                'requirements.product', 'requirements.unit', 'progressEntries', 'inspections.results',
                'inventoryDocuments.journalEntry', 'materialRequests', 'expenseRequests',
            ]),
            'stores' => collect(),
            'workers' => collect(),
            'readOnlyReport' => true,
        ]);
    }

    public function printControlRun(Request $request, ProductionRun $productionRun): Response
    {
        $this->assertControlRunVisible($request, $productionRun, 'print');
        $record = $productionRun->load([
            'order.company', 'order.salesOrder', 'orderLine.product.unit', 'orderLine.product.equivalentUnit',
            'product', 'fixedAsset', 'stageSnapshot', 'shift', 'requirements.product', 'requirements.unit',
            'progressEntries', 'inspections.results',
        ]);

        return $this->pdf->stream('reports.production.run-sheet', [
            'title' => __('Print traveler'),
            'record' => $record,
            'companyPrintIdentity' => $record->order->print_identity_snapshot ?: $this->printIdentity->forCompany($record->order->company),
        ], str('production-traveler-'.$record->run_number)->slug().'.pdf');
    }

    private function assertControlRunVisible(Request $request, ProductionRun $productionRun, string $action): void
    {
        $this->authorizeSection($request, 'control', $action);
        $context = $this->context->snapshot($request);
        abort_unless(
            $context['company_id']
            && $context['financial_period_id']
            && (int) $productionRun->company_id === (int) $context['company_id']
            && (int) $productionRun->financial_period_id === (int) $context['financial_period_id']
            && $this->context->allowedBranchQueryForCurrentCompany($request)
                ->whereKey($productionRun->branch_id)
                ->where('branches.type', Branch::TypeFactory)
                ->exists(),
            404,
        );
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function report(Request $request, string $section): array
    {
        $context = $this->context->snapshot($request);
        if ($section === 'control') {
            abort_unless($context['company_id'] && $context['financial_period_id'], 422, __('production_execution.messages.operating_context_required'));
            $filters = $request->validate([
                'branch_id' => ['nullable', 'integer'],
                'branch_doc_num' => ['nullable', 'string', 'max:50'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date', 'after_or_equal:from'],
                'status' => ['nullable', 'string', 'max:40'],
                'product' => ['nullable', 'string', 'max:100'],
                'machine' => ['nullable', 'string', 'max:100'],
                'shift' => ['nullable', 'string', 'max:100'],
                'stage' => ['nullable', 'string', 'max:100'],
                'order' => ['nullable', 'string', 'max:100'],
            ]);
            $branches = $this->context->allowedBranchQueryForCurrentCompany($request)
                ->where('branches.type', Branch::TypeFactory)
                ->orderBy('branches.name')
                ->get();
            $branchIds = $branches->modelKeys();
            abort_if(filled($filters['branch_id'] ?? null) && ! in_array((int) $filters['branch_id'], $branchIds, true), 403);
            $selectedBranch = filled($filters['branch_doc_num'] ?? null)
                ? $branches->firstWhere('doc_num', $filters['branch_doc_num'])
                : (filled($filters['branch_id'] ?? null) ? $branches->firstWhere('id', (int) $filters['branch_id']) : null);
            abort_if(filled($filters['branch_doc_num'] ?? null) && ! $selectedBranch, 403);
            abort_if($selectedBranch && filled($filters['branch_id'] ?? null) && (int) $selectedBranch->getKey() !== (int) $filters['branch_id'], 403);
            $filters['branch_id'] = $selectedBranch?->getKey();
            $filters['branch_doc_num'] = $selectedBranch?->doc_num;

            return [$context, [
                ...$this->reports->controlReport((int) $context['company_id'], (int) $context['financial_period_id'], $branchIds, $filters),
                'controlBranches' => $branches,
                'controlFilters' => $filters,
                'controlCompanyDocNum' => $context['company_doc_num'],
            ]];
        }

        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));
        $report = $this->reports->report(
            $context['company_id'],
            $context['financial_period_id'],
            $context['branch_id'],
            [
                ...$request->only(['status', 'from', 'to', 'operational_focus']),
                'production_run_id' => $request->integer('production_run_id') ?: null,
            ],
            false,
        );

        $report['backorders'] = $this->salesCycle->backorders((int) $context['company_id'], (int) $context['branch_id']);

        return [$context, $report];
    }

    private function section(Request $request): string
    {
        $section = (string) ($request->route('section') ?: $request->query('section', 'overview'));

        abort_unless(in_array($section, self::Sections, true), 404);

        return $section;
    }

    private function authorizeSection(Request $request, string $section, string $action): void
    {
        $permission = 'production.reports.'.$section;

        abort_unless($request->user()?->can("{$permission}.view"), 403);

        if ($action !== 'view') {
            abort_unless($request->user()?->can("{$permission}.{$action}"), 403);
        }
    }
}
