<?php

namespace Modules\FixedAssets\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Http\Requests\FixedAssetDepreciationRunRequest;
use Modules\FixedAssets\Http\Requests\ReverseFixedAssetDocumentRequest;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetDepreciationRun;
use Modules\FixedAssets\Services\FixedAssetAccessService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetPdfService;

class FixedAssetDepreciationController extends Controller
{
    public function __construct(
        private readonly FixedAssetDepreciationService $depreciation,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly FixedAssetPdfService $pdf,
    ) {}

    public function index(): View
    {
        $context = app(OperatingContextService::class)->snapshot(request());
        $period = $context['financial_period_id'] && $context['company_id']
            ? FinancialPeriod::query()->forCompany((int) $context['company_id'])->find($context['financial_period_id'])
            : null;

        return view('modules.fixed-assets.depreciation.index', [
            'preview' => null,
            'selectedAssets' => $this->selectedAssets(),
            'financialPeriod' => $period,
            'postingDate' => app(DateFormatService::class)->formatDate(app(DateFormatService::class)->normalizeForStorage(request('posting_date')) ?: now()->endOfMonth(), ''),
            'recentRuns' => $this->recentRuns($context['company_id'] ? (int) $context['company_id'] : null),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.fixed-assets.depreciation.index'),
        ]);
    }

    public function openPreview(): RedirectResponse
    {
        return to_route('admin.fixed-assets.depreciation.index');
    }

    public function preview(FixedAssetDepreciationRunRequest $request): View|RedirectResponse|JsonResponse
    {
        try {
            $preview = $this->depreciation->preview($request->validated());
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withErrors(['depreciation' => $exception->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['rows' => collect($preview['eligible'])->map(fn (array $row): array => ['asset' => $row['asset']->doc_num, 'cost' => $row['acquisition_cost'], 'accumulated_before' => $row['accumulated_before'], 'period_depreciation' => $row['period_depreciation'], 'accumulated_after' => $row['accumulated_after'], 'closing_net_book_value' => $row['closing_net_book_value'], 'requires_usage_units' => $row['requires_usage_units'] ?? false]), 'excluded' => collect($preview['excluded'])->map(fn (array $row): array => ['asset' => $row['asset']->doc_num, 'reason' => $row['reason']])]);
        }

        return view('modules.fixed-assets.depreciation.index', [
            'preview' => $preview,
            'selectedAssets' => $this->selectedAssets(),
            'financialPeriod' => $preview['financialPeriod'],
            'postingDate' => $request->input('posting_date'),
            'recentRuns' => $this->recentRuns((int) $preview['financialPeriod']->company_id),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.fixed-assets.depreciation.index'),
        ]);
    }

    private function selectedAssets(): Collection
    {
        return app(FixedAssetAccessService::class)->scopeAssets(FixedAsset::query())
            ->whereIn('doc_num', (array) request('asset_doc_nums', []))->get(['id', 'doc_num', 'asset_name']);
    }

    private function recentRuns(?int $companyId): Collection
    {
        $branchIds = app(FixedAssetAccessService::class)->branchIds();

        if (! $companyId || $branchIds === []) {
            return new Collection;
        }

        return FixedAssetDepreciationRun::query()
            ->where('company_id', $companyId)
            ->whereHas('lines')
            ->whereDoesntHave('lines', fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('branch_id')
                ->orWhereNotIn('branch_id', $branchIds)))
            ->with(['financialPeriod', 'journalEntry', 'postedBy'])
            ->withCount('lines')
            ->latest('posting_date')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    public function post(FixedAssetDepreciationRunRequest $request): RedirectResponse
    {
        try {
            $run = $this->depreciation->post($request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['depreciation' => $exception->getMessage()])->withInput();
        }

        return to_route('admin.fixed-assets.depreciation.show', $run)->with('success', __('fixed_assets.lifecycle.messages.depreciation_posted'));
    }

    public function show(FixedAssetDepreciationRun $run): View
    {
        foreach ($run->lines()->with('asset')->get() as $line) {
            app(FixedAssetAccessService::class)->assertAsset($line->asset);
        }
        $run->load(['company', 'financialPeriod', 'branch', 'journalEntry', 'reversalJournalEntry', 'postedBy', 'lines.asset', 'lines.costCenter', 'lines.branch']);

        return view('modules.fixed-assets.depreciation.show', compact('run'));
    }

    public function reverse(ReverseFixedAssetDocumentRequest $request, FixedAssetDepreciationRun $run): RedirectResponse
    {
        try {
            $this->depreciation->reverse($run, $request->validated('reason'));
        } catch (DomainException $exception) {
            return back()->withErrors(['reversal' => $exception->getMessage()]);
        }

        return back()->with('success', __('fixed_assets.lifecycle.messages.depreciation_reversed'));
    }

    public function print(FixedAssetDepreciationRun $run): Response
    {
        foreach ($run->lines()->with('asset')->get() as $line) {
            app(FixedAssetAccessService::class)->assertAsset($line->asset);
        }
        $run->load(['company', 'financialPeriod', 'branch', 'journalEntry', 'postedBy', 'lines.asset.assetGroupAccount', 'lines.asset.currency', 'lines.costCenter', 'lines.branch']);

        return $this->pdf->stream('reports.fixed-assets.depreciation-run', $run->company, [
            'title' => __('fixed_assets.lifecycle.depreciation_run'),
            'run' => $run,
        ], 'depreciation-run-'.$run->doc_num.'.pdf', 'L');
    }
}
