<?php

namespace Modules\FixedAssets\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\FixedAssets\Http\Requests\ActivateFixedAssetRequest;
use Modules\FixedAssets\Http\Requests\ConfigureFixedAssetCategoryMappingRequest;
use Modules\FixedAssets\Http\Requests\ReverseFixedAssetDocumentRequest;
use Modules\FixedAssets\Http\Requests\StoreFixedAssetDisposalRequest;
use Modules\FixedAssets\Http\Requests\StoreFixedAssetMovementRequest;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\FixedAssets\Services\FixedAssetBookValueService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetScheduleService;

class FixedAssetLifecycleController extends Controller
{
    public function __construct(
        private readonly FixedAssetLifecycleService $lifecycle,
        private readonly FixedAssetBookValueService $bookValues,
        private readonly FixedAssetScheduleService $schedules,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly CompanyPrintIdentityService $printIdentities,
    ) {}

    public function show(FixedAsset $fixedAsset): View
    {
        $fixedAsset->load([
            'company', 'account', 'assetGroupAccount', 'creditAccount', 'costCenter', 'branch', 'branchHall', 'currency', 'mainImageUsage.file',
            'categoryMapping.accumulatedDepreciationAccount', 'categoryMapping.depreciationExpenseAccount',
            'postedDepreciations.journalEntry', 'postedDepreciations.costCenter', 'postedDepreciations.branch', 'postedDepreciations.postedBy',
            'movements.sourceBranch', 'movements.destinationBranch', 'movements.sourceBranchHall', 'movements.destinationBranchHall', 'movements.sourceCostCenter', 'movements.destinationCostCenter', 'movements.requestedBy',
            'disposals.customer', 'disposals.proceedsAccount', 'disposals.journalEntry',
        ]);

        return view('modules.fixed-assets.lifecycle.show', [
            'asset' => $fixedAsset,
            'position' => $this->bookValues->position($fixedAsset),
            'schedule' => $this->schedules->schedule($fixedAsset),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.fixed-assets.assets.index', [
                ['label' => $fixedAsset->doc_num, 'url' => route('admin.fixed-assets.assets.show', $fixedAsset)],
                ['label' => __('fixed_assets.lifecycle.asset_card')],
            ]),
            'today' => app(DateFormatService::class)->formatDate(now(), ''),
        ]);
    }

    public function activate(ActivateFixedAssetRequest $request, FixedAsset $fixedAsset): RedirectResponse
    {
        try {
            $this->lifecycle->activate($fixedAsset, $request->validated('activation_date'));
        } catch (DomainException $exception) {
            return back()->withErrors(['activation_date' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', __('fixed_assets.lifecycle.messages.activated'));
    }

    public function transfer(StoreFixedAssetMovementRequest $request, FixedAsset $fixedAsset): RedirectResponse
    {
        try {
            $movement = $this->lifecycle->transfer($fixedAsset, $request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['transfer' => $exception->getMessage()])->withInput();
        }

        return to_route('admin.fixed-assets.lifecycle.show', $fixedAsset)->with('success', __('fixed_assets.lifecycle.messages.transferred', ['document' => $movement->doc_num]));
    }

    public function dispose(StoreFixedAssetDisposalRequest $request, FixedAsset $fixedAsset): RedirectResponse
    {
        try {
            $disposal = $this->lifecycle->dispose($fixedAsset, $request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['disposal' => $exception->getMessage()])->withInput();
        }

        return to_route('admin.fixed-assets.lifecycle.show', $fixedAsset)->with('success', __('fixed_assets.lifecycle.messages.disposed', ['document' => $disposal->doc_num]));
    }

    public function reverseDisposal(ReverseFixedAssetDocumentRequest $request, FixedAssetDisposal $disposal): RedirectResponse
    {
        try {
            $this->lifecycle->reverseDisposal($disposal, $request->validated('reason'));
        } catch (DomainException $exception) {
            return back()->withErrors(['reversal' => $exception->getMessage()]);
        }

        return back()->with('success', __('fixed_assets.lifecycle.messages.disposal_reversed'));
    }

    public function accounting(): View
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);
        $categories = Account::query()
            ->join('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.company_id', $companyId)
            ->where('accounts.id', '!=', $root->getKey())
            ->where('accounts.is_group', true)
            ->where('accounts.is_postable', false)
            ->where('accounts.status', 'active')
            ->where('account_classifications.code', BusinessPartnerAccountService::FixedAsset)
            ->whereNull('accounts.deleted_at')
            ->select('accounts.*')
            ->orderBy('accounts.account_code')
            ->get();
        $mappings = FixedAssetCategoryMapping::query()
            ->where('company_id', $companyId)
            ->with(['assetGroupAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount', 'disposalGainAccount', 'disposalLossAccount'])
            ->get()
            ->keyBy('asset_group_account_id');

        return view('modules.fixed-assets.lifecycle.accounting', compact('categories', 'mappings'));
    }

    public function configureAccounting(ConfigureFixedAssetCategoryMappingRequest $request): RedirectResponse
    {
        try {
            $this->lifecycle->configureCategoryMapping($request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['mapping' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', __('fixed_assets.lifecycle.messages.mapping_saved'));
    }

    public function printAsset(FixedAsset $fixedAsset): View
    {
        $fixedAsset->load([
            'company', 'account', 'assetGroupAccount', 'costCenter', 'branch', 'branchHall', 'currency', 'mainImageUsage.file',
            'categoryMapping.accumulatedDepreciationAccount', 'categoryMapping.depreciationExpenseAccount',
            'postedDepreciations.journalEntry', 'postedDepreciations.costCenter', 'postedDepreciations.branch', 'postedDepreciations.postedBy',
            'movements.sourceBranch', 'movements.destinationBranch', 'movements.sourceCostCenter', 'movements.destinationCostCenter', 'disposals',
        ]);

        return view('modules.fixed-assets.lifecycle.print-asset', [
            'asset' => $fixedAsset,
            'position' => $this->bookValues->position($fixedAsset),
            'companyPrintIdentity' => $this->printIdentities->forCompany($fixedAsset->company),
        ]);
    }

    public function printMovement(FixedAssetMovement $movement): View
    {
        $movement->load(['company', 'asset', 'sourceBranch', 'destinationBranch', 'sourceBranchHall', 'destinationBranchHall', 'sourceCostCenter', 'destinationCostCenter', 'requestedBy']);

        return view('modules.fixed-assets.lifecycle.print-movement', ['movement' => $movement, 'companyPrintIdentity' => $this->printIdentities->forCompany($movement->company)]);
    }

    public function printDisposal(FixedAssetDisposal $disposal): View
    {
        $disposal->load(['company', 'asset', 'customer', 'proceedsAccount', 'journalEntry']);

        return view('modules.fixed-assets.lifecycle.print-disposal', ['disposal' => $disposal, 'companyPrintIdentity' => $this->printIdentities->forCompany($disposal->company)]);
    }
}
