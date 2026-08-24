<?php

namespace Modules\FixedAssets\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Http\Requests\FixedAssetDepreciationRunRequest;
use Modules\FixedAssets\Http\Requests\ReverseFixedAssetDocumentRequest;
use Modules\FixedAssets\Models\FixedAssetDepreciationRun;
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
            'financialPeriod' => $period,
            'postingDate' => app(DateFormatService::class)->formatDate(now(), ''),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.fixed-assets.depreciation.index'),
        ]);
    }

    public function preview(FixedAssetDepreciationRunRequest $request): View|RedirectResponse
    {
        try {
            $preview = $this->depreciation->preview($request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['depreciation' => $exception->getMessage()])->withInput();
        }

        return view('modules.fixed-assets.depreciation.index', [
            'preview' => $preview,
            'financialPeriod' => $preview['financialPeriod'],
            'postingDate' => $request->input('posting_date'),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.fixed-assets.depreciation.index'),
        ]);
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
        $run->load(['company', 'financialPeriod', 'branch', 'journalEntry', 'postedBy', 'lines.asset.assetGroupAccount', 'lines.asset.currency', 'lines.costCenter', 'lines.branch']);

        return $this->pdf->stream('reports.fixed-assets.depreciation-run', $run->company, [
            'title' => __('fixed_assets.lifecycle.depreciation_run'),
            'run' => $run,
        ], 'depreciation-run-'.$run->doc_num.'.pdf', 'L');
    }
}
