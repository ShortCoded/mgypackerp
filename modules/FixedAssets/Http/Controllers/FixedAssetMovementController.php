<?php

namespace Modules\FixedAssets\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ArchiveFileUsageService;
use Modules\Core\Services\FilePickerService;
use Modules\FixedAssets\Http\Requests\ReverseFixedAssetDocumentRequest;
use Modules\FixedAssets\Http\Requests\StoreFixedAssetCostMovementRequest;
use Modules\FixedAssets\Http\Requests\StoreFixedAssetCustodyRequest;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\FixedAssets\Services\FixedAssetAccessService;
use Modules\FixedAssets\Services\FixedAssetCostMovementService;
use Modules\FixedAssets\Services\FixedAssetLedgerService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetReportService;

class FixedAssetMovementController extends Controller
{
    public function index(Request $request, FixedAssetReportService $reports): View
    {
        $request->merge(['type' => FixedAssetReportService::Movements]);
        $filters = $reports->filters($request);
        $report = $reports->report($filters);
        $rows = $report['rows']->sortByDesc('date')->values();
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('modules.fixed-assets.lifecycle.workflows', ['rows' => $paginator, 'filters' => $filters, 'movementTypes' => FixedAssetLedgerService::types()]);
    }

    public function addition(StoreFixedAssetCostMovementRequest $request, FixedAsset $fixedAsset, FixedAssetCostMovementService $service): RedirectResponse
    {
        try {
            $service->addition($fixedAsset, $request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['addition' => $exception->getMessage()])->withInput();
        }

        return to_route('admin.fixed-assets.lifecycle.show', $fixedAsset)->with('success', __('fixed_assets.cycle.addition').' — '.__('fixed_assets.lifecycle.statuses.posted'));
    }

    public function custody(StoreFixedAssetCustodyRequest $request, FixedAsset $fixedAsset, FixedAssetLifecycleService $service): RedirectResponse
    {
        try {
            $service->custody($fixedAsset, $request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['custody' => $exception->getMessage()])->withInput();
        }

        return to_route('admin.fixed-assets.lifecycle.show', $fixedAsset)->with('success', __('fixed_assets.cycle.custody').' — '.__('fixed_assets.lifecycle.statuses.posted'));
    }

    public function document(Request $request, FixedAsset $fixedAsset, FixedAssetAccessService $access): RedirectResponse
    {
        $access->assertAsset($fixedAsset);
        $data = $request->validate([
            'archive_file_doc_num' => ['required', 'string', 'max:255'],
            'document_target' => ['nullable', 'string', 'max:510'],
            'movement_doc_num' => ['nullable', 'string', 'max:255'],
        ]);
        $file = app(FilePickerService::class)->selectableFileByPublicId($data['archive_file_doc_num'], (int) $fixedAsset->company_id, FilePickerService::AcceptDocument);
        if (! $file) {
            return back()->withErrors(['document' => __('fixed_assets.validation.selected_file_unavailable')]);
        }
        [$targetType, $targetDocument] = $this->documentTarget($data, $fixedAsset);
        $target = match ($targetType) {
            'asset' => $fixedAsset,
            'movement' => $fixedAsset->movements()->where('doc_num', $targetDocument)->firstOrFail(),
            'depreciation' => $fixedAsset->depreciations()->whereHas('run', fn ($query) => $query->where('doc_num', $targetDocument))->firstOrFail(),
            'disposal' => $fixedAsset->disposals()->where('doc_num', $targetDocument)->firstOrFail(),
        };
        DB::transaction(function () use ($file, $fixedAsset, $target, $targetDocument): void {
            $fixedAsset->newQuery()->whereKey($fixedAsset->getKey())->lockForUpdate()->firstOrFail();
            if ($target->archiveFileUsages()->where('archive_file_id', $file->getKey())->where('collection', 'fixed_asset_documents')->exists()) {
                return;
            }
            app(ArchiveFileUsageService::class)->attachFileToRecord($file, $target, 'fixed_asset_documents');
            app(ActivityLogger::class)->log(request(), 'fixed_assets', 'attach', 'success', ['subject' => $fixedAsset, 'properties_only' => true, 'properties' => ['file' => $file->doc_num, 'document' => $targetDocument]]);
        });

        return back()->with('success', __('common.actions.save'));
    }

    /** @param array<string, mixed> $data
     * @return array{0: string, 1: string}
     */
    private function documentTarget(array $data, FixedAsset $fixedAsset): array
    {
        if (! empty($data['document_target'])) {
            [$type, $document] = array_pad(explode('|', $data['document_target'], 2), 2, null);
            if (! in_array($type, ['asset', 'movement', 'depreciation', 'disposal'], true)
                || blank($document)
                || ($type === 'asset' && $document !== $fixedAsset->doc_num)) {
                throw ValidationException::withMessages(['document_target' => __('fixed_assets.validation.selected_document_unavailable')]);
            }

            return [$type, (string) $document];
        }

        if (! empty($data['movement_doc_num'])) {
            return ['movement', (string) $data['movement_doc_num']];
        }

        return ['asset', $fixedAsset->doc_num];
    }

    public function reverse(ReverseFixedAssetDocumentRequest $request, FixedAssetMovement $movement, FixedAssetCostMovementService $service): RedirectResponse
    {
        try {
            $service->reverse($movement, $request->validated('reason'));
        } catch (DomainException $exception) {
            return back()->withErrors(['reversal' => $exception->getMessage()]);
        }

        return to_route('admin.fixed-assets.lifecycle.show', $movement->asset)->with('success', __('fixed_assets.lifecycle.reverse'));
    }
}
