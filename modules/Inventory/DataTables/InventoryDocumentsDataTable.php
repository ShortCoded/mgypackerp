<?php

namespace Modules\Inventory\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Inventory\Models\InventoryDocument;
use Yajra\DataTables\Facades\DataTables;

class InventoryDocumentsDataTable
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly DataTableSearchService $search,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $context = $this->context->snapshot($request);
        $dateFormat = app(SettingService::class)->dateFormat();
        $query = match ($request->user()?->can('inventory.documents.view_trashed') ? $request->string('trash_filter')->toString() : 'active') {
            'trashed' => InventoryDocument::onlyTrashed(),
            'all' => InventoryDocument::withTrashed(),
            default => InventoryDocument::query(),
        };
        $query
            ->when(
                $context['company_id'] && $context['financial_period_id'] && $context['branch_id'],
                fn ($query) => $query
                    ->where('inventory_documents.company_id', $context['company_id'])
                    ->where('inventory_documents.financial_period_id', $context['financial_period_id'])
                    ->where('inventory_documents.branch_id', $context['branch_id']),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->leftJoin('branch_stores as source_stores', 'source_stores.id', '=', 'inventory_documents.branch_store_id')
            ->leftJoin('branch_stores as destination_stores', 'destination_stores.id', '=', 'inventory_documents.destination_branch_store_id')
            ->select([
                'inventory_documents.*',
                'source_stores.name as source_store_name',
                'destination_stores.name as destination_store_name',
            ])
            ->withCount('lines');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'inventory_documents.doc_num',
                            'inventory_documents.document_type',
                            'inventory_documents.movement_reason',
                            'inventory_documents.status',
                            'source_stores.name',
                            'destination_stores.name',
                        ],
                    ]);
                }
            })
            ->editColumn('doc_num', function (InventoryDocument $record) use ($request): string {
                $url = ! $record->trashed() && $record->status === InventoryDocument::StatusDraft && $request->user()?->can('inventory.documents.edit')
                    ? route('admin.inventory.documents.edit', $record)
                    : ($record->trashed() ? '#' : route('admin.inventory.documents.show', $record));

                return '<a class="fw-semibold dt-code-value" data-row-primary-link href="'.e($url).'">'.e($record->doc_num).'</a>';
            })
            ->editColumn('document_type', fn (InventoryDocument $record): string => e(__('inventory.movements.types.'.$record->document_type)))
            ->editColumn('document_date', fn (InventoryDocument $record): string => e($record->document_date?->format($dateFormat) ?? '—'))
            ->addColumn('source_store', fn (InventoryDocument $record): string => e($this->sourceStore($record)))
            ->addColumn('destination_store', fn (InventoryDocument $record): string => e($this->destinationStore($record)))
            ->addColumn('status_label', fn (InventoryDocument $record): string => '<span class="badge rounded-pill badge-subtle-'.$this->statusColor($record->status).'">'.e(__('inventory.movements.statuses.'.$record->status)).'</span>')
            ->addColumn('actions', fn (InventoryDocument $record): string => view('modules.inventory.documents.partials.actions', ['record' => $record])->render())
            ->orderColumn('source_store', 'source_stores.name $1')
            ->orderColumn('destination_store', 'destination_stores.name $1')
            ->orderColumn('status_label', 'inventory_documents.status $1')
            ->rawColumns(['doc_num', 'status_label', 'actions'])
            ->toJson();
    }

    private function sourceStore(InventoryDocument $record): string
    {
        return $record->source_store_name ?: '—';
    }

    private function destinationStore(InventoryDocument $record): string
    {
        if ($record->document_type === InventoryDocument::TypeTransfer) {
            return $record->destination_store_name ?: '—';
        }

        return '—';
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            InventoryDocument::StatusPosted => 'success',
            InventoryDocument::StatusReversed, InventoryDocument::StatusCancelled => 'danger',
            default => 'secondary',
        };
    }
}
