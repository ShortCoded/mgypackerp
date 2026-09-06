<?php

namespace Modules\FixedAssets\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Services\BreadcrumbService;
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
use Modules\FixedAssets\Services\FixedAssetAccessService;
use Modules\FixedAssets\Services\FixedAssetBookValueService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetImageResolver;
use Modules\FixedAssets\Services\FixedAssetLedgerService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetPdfService;
use Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService;
use Modules\FixedAssets\Services\FixedAssetScheduleService;
use Spatie\Activitylog\Models\Activity;

class FixedAssetLifecycleController extends Controller
{
    public function __construct(
        private readonly FixedAssetLifecycleService $lifecycle,
        private readonly FixedAssetBookValueService $bookValues,
        private readonly FixedAssetScheduleService $schedules,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly FixedAssetPdfService $pdf,
        private readonly FixedAssetImageResolver $images,
    ) {}

    public function show(FixedAsset $fixedAsset): View
    {
        app(FixedAssetAccessService::class)->assertAsset($fixedAsset);
        $relations = [
            'company', 'account', 'assetGroupAccount', 'creditAccount', 'costCenter', 'branch', 'branchHall', 'currency', 'mainImageUsage.file',
            'costMovements.journalEntry', 'categoryMapping.accumulatedDepreciationAccount', 'categoryMapping.depreciationExpenseAccount',
            'postedDepreciations.journalEntry', 'postedDepreciations.costCenter', 'postedDepreciations.branch', 'postedDepreciations.postedBy',
            'depreciations.run', 'depreciations.archiveFileUsages.file',
            'movements.sourceBranch', 'movements.destinationBranch', 'movements.sourceBranchHall', 'movements.destinationBranchHall', 'movements.sourceCostCenter', 'movements.destinationCostCenter', 'movements.requestedBy', 'movements.archiveFileUsages.file',
            'disposals.customer', 'disposals.proceedsAccount', 'disposals.journalEntry',
            'disposals.customerInvoice', 'disposals.gainLossJournalEntry', 'disposals.reversalJournalEntry', 'disposals.gainLossReversalJournalEntry',
            'disposals.archiveFileUsages.file', 'archiveFileUsages.file',
        ];
        if ($fixedAsset->source_type === FixedAssetPurchaseIntegrationService::SourceType) {
            $relations = [
                ...$relations,
                'purchaseInvoiceLine.product', 'purchaseInvoiceLine.purchaseOrderLine.purchaseOrder', 'purchaseInvoiceLine.receiptLine.receipt',
                'purchaseInvoiceLine.purchaseInvoice.supplier', 'purchaseInvoiceLine.purchaseInvoice.paymentAllocations.paymentContext.cashVoucher',
                'purchaseInvoiceLine.purchaseInvoice.paymentAllocations.paymentContext.bankAccount',
                'purchaseInvoiceLine.purchaseInvoice.paymentAllocations.paymentContext.cheque',
            ];
        }
        $fixedAsset->load($relations);
        $purchaseImprovements = $fixedAsset->movements()
            ->where('source_type', FixedAssetPurchaseIntegrationService::ImprovementSourceType)
            ->with([
                'purchaseInvoiceLine.purchaseOrderLine.purchaseOrder',
                'purchaseInvoiceLine.receiptLine.receipt',
                'purchaseInvoiceLine.purchaseInvoice.supplier',
                'purchaseInvoiceLine.purchaseInvoice.paymentAllocations.paymentContext.cashVoucher',
                'purchaseInvoiceLine.purchaseInvoice.paymentAllocations.paymentContext.bankAccount',
                'purchaseInvoiceLine.purchaseInvoice.paymentAllocations.paymentContext.cheque',
            ])
            ->latest('movement_date')
            ->latest('id')
            ->get();

        $displayMapping = new FixedAssetCategoryMapping(['company_id' => $fixedAsset->company_id]);
        $accountingWarnings = [];
        if ($fixedAsset->is_depreciable) {
            foreach (FixedAssetCategoryMapping::DepreciationAccounts as $field) {
                try {
                    $resolved = FixedAssetCategoryMapping::resolveForAsset($fixedAsset, [$field]);
                    $displayMapping->{$field} = $resolved->{$field};
                } catch (DomainException $exception) {
                    $accountingWarnings[] = $exception->getMessage();
                }
            }
        }
        $attachmentTargets = $fixedAsset->movements->map(fn (FixedAssetMovement $movement): array => [
            'type' => 'movement', 'document' => $movement->doc_num, 'date' => $movement->movement_date,
            'label' => __('fixed_assets.cycle.'.$movement->movement_type), 'usages' => $movement->archiveFileUsages,
        ])->concat($fixedAsset->depreciations->map(fn ($depreciation): array => [
            'type' => 'depreciation', 'document' => $depreciation->run->doc_num, 'date' => $depreciation->period_end,
            'label' => __('fixed_assets.cycle.depreciation'), 'usages' => $depreciation->archiveFileUsages,
        ]))->concat($fixedAsset->disposals->map(fn (FixedAssetDisposal $disposal): array => [
            'type' => 'disposal', 'document' => $disposal->doc_num, 'date' => $disposal->disposal_date,
            'label' => __('fixed_assets.cycle.disposal'), 'usages' => $disposal->archiveFileUsages,
        ]))->sortByDesc('date')->values()->prepend([
            'type' => 'asset', 'document' => $fixedAsset->doc_num, 'date' => $fixedAsset->asset_date,
            'label' => __('fixed_assets.product.asset_documents'), 'usages' => $fixedAsset->archiveFileUsages,
        ]);

        return view('modules.fixed-assets.lifecycle.show', [
            'displayMapping' => $displayMapping, 'accountingWarnings' => $accountingWarnings,
            'asset' => $fixedAsset,
            'purchaseImprovements' => $purchaseImprovements,
            'depreciationReadiness' => app(FixedAssetDepreciationService::class)->readiness($fixedAsset),
            'position' => $this->bookValues->position($fixedAsset),
            'schedule' => $this->schedules->schedule($fixedAsset),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.fixed-assets.assets.index', [
                ['label' => $fixedAsset->doc_num, 'url' => route('admin.fixed-assets.assets.show', $fixedAsset)],
                ['label' => __('fixed_assets.lifecycle.asset_card')],
            ]),
            'canEditMaster' => $fixedAsset->canEditMaster(),
            'canRecognize' => ! $fixedAsset->hasPostedRecognition() && ! $fixedAsset->isDisposed()
                && $fixedAsset->source_type !== FixedAssetPurchaseIntegrationService::SourceType,
            'today' => app(DateFormatService::class)->formatDate(now(), ''),
            'ledger' => app(FixedAssetLedgerService::class)->history($fixedAsset),
            'journals' => app(FixedAssetLedgerService::class)->journals($fixedAsset),
            'assetDocuments' => $fixedAsset->archiveFileUsages,
            'attachmentTargets' => $attachmentTargets,
            'activities' => Activity::query()->with('causer')->where('subject_type', $fixedAsset->getMorphClass())->where('subject_id', $fixedAsset->getKey())->latest()->limit(100)->get(),
            'custody' => $fixedAsset->movements()->where('movement_type', 'custody')->where('status', 'posted')->with('destinationCustodian')->first(),
        ]);
    }

    public function activate(ActivateFixedAssetRequest $request, FixedAsset $fixedAsset): RedirectResponse
    {
        try {
            $this->lifecycle->activate($fixedAsset, $request->validated('activation_date'), $request->validated('existing_journal_doc_num'));
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

    public function previewDisposal(StoreFixedAssetDisposalRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        try {
            return response()->json($this->lifecycle->previewDisposal($fixedAsset, $request->validated()));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
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
        $setupError = null;

        try {
            $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);
        } catch (DomainException $exception) {
            $setupError = $exception->getMessage();

            return view('modules.fixed-assets.lifecycle.accounting', [
                'categories' => collect(),
                'mappings' => collect(),
                'setupError' => $setupError,
            ]);
        }

        $categories = Account::query()
            ->join('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.company_id', $companyId)
            ->where('accounts.id', '!=', $root->getKey())
            ->where('accounts.is_group', true)
            ->where('accounts.is_postable', false)
            ->where('accounts.status', 'active')
            ->where('account_classifications.code', AccountClassification::FixedAssets)
            ->where('account_classifications.status', 'active')
            ->whereIn('accounts.id', app(BusinessPartnerAccountService::class)->selectableGroupIds(BusinessPartnerAccountService::FixedAsset))
            ->whereNull('accounts.deleted_at')
            ->select('accounts.*')
            ->orderBy('accounts.account_code')
            ->get();
        $mappings = FixedAssetCategoryMapping::query()
            ->where('company_id', $companyId)
            ->with(['assetGroupAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount', 'disposalGainAccount', 'disposalLossAccount', 'disposalClearingAccount'])
            ->get()
            ->keyBy('asset_group_account_id');

        return view('modules.fixed-assets.lifecycle.accounting', compact('categories', 'mappings', 'setupError'));
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

    public function printAsset(FixedAsset $fixedAsset): Response
    {
        app(FixedAssetAccessService::class)->assertAsset($fixedAsset);
        $fixedAsset->load([
            'company', 'account', 'assetGroupAccount', 'costCenter', 'branch', 'branchHall', 'currency', 'mainImageUsage.file',
            'categoryMapping.accumulatedDepreciationAccount', 'categoryMapping.depreciationExpenseAccount',
            'postedDepreciations.journalEntry', 'postedDepreciations.costCenter', 'postedDepreciations.branch', 'postedDepreciations.postedBy',
            'movements.sourceBranch', 'movements.destinationBranch', 'movements.sourceBranchHall', 'movements.destinationBranchHall', 'movements.sourceCostCenter', 'movements.destinationCostCenter',
            'disposals.journalEntry',
        ]);

        return $this->pdf->stream('reports.fixed-assets.asset-card', $fixedAsset->company, [
            'title' => __('fixed_assets.lifecycle.asset_card'),
            'asset' => $fixedAsset,
            'position' => $this->bookValues->position($fixedAsset),
            'assetImageSource' => $this->images->pdfSource($fixedAsset),
        ], 'fixed-asset-'.$fixedAsset->doc_num.'.pdf');
    }

    public function printMovement(FixedAssetMovement $movement): Response
    {
        app(FixedAssetAccessService::class)->assertAsset($movement->asset);
        $movement->load(['company', 'asset', 'sourceBranch', 'destinationBranch', 'sourceBranchHall', 'destinationBranchHall', 'sourceCostCenter', 'destinationCostCenter', 'requestedBy', 'approvedBy', 'postedBy']);

        return $this->pdf->stream('reports.fixed-assets.movement', $movement->company, [
            'title' => __('fixed_assets.cycle.'.$movement->movement_type),
            'movement' => $movement,
        ], 'asset-transfer-'.$movement->doc_num.'.pdf');
    }

    public function printDisposal(FixedAssetDisposal $disposal): Response
    {
        app(FixedAssetAccessService::class)->assertAsset($disposal->asset);
        $disposal->load(['company', 'financialPeriod', 'asset', 'customer', 'proceedsAccount', 'journalEntry', 'approvedBy', 'postedBy']);
        $title = __('fixed_assets.pdf.disposition_titles.'.$disposal->disposition_type);

        return $this->pdf->stream('reports.fixed-assets.disposition', $disposal->company, [
            'title' => $title,
            'disposal' => $disposal,
        ], 'asset-'.str_replace('_', '-', $disposal->disposition_type).'-'.$disposal->doc_num.'.pdf');
    }
}
