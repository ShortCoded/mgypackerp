@php
    $prefix = 'admin.purchases.'.$definition['route'];
    $permission = 'purchases.'.$definition['permission'];
    $draft = $record->status === 'draft' && ($screen !== 'goods_receipts' || $record->posting_status === 'unposted');
    $editableDraft = $draft && ($screen !== 'goods_receipts' || in_array($record->qc_status, ['pending_inspection', 'not_required'], true));
    $isOwnBranch = (int) ($record->branch_id ?? 0) === (int) ($activeBranchId ?? 0);
    $isDestinationBranch = (int) ($record->branchStore?->branch_id ?? 0) === (int) ($activeBranchId ?? 0);
@endphp
<div class="dropdown font-sans-serif position-static">
<button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" aria-label="{{ __('Actions') }}"><span class="fas fa-ellipsis-h"></span></button>
<div class="dropdown-menu dropdown-menu-end py-2">
@if(!$record->trashed())
<a class="dropdown-item" href="{{ route($prefix.'.show', $record->doc_num) }}">{{ __('common.actions.view') }}</a>
@if($editableDraft && Route::has($prefix.'.edit') && $isOwnBranch) @can($permission.'.edit')<a class="dropdown-item" href="{{ route($prefix.'.edit', $record->doc_num) }}">{{ __('common.actions.edit') }}</a>@endcan @endif
@if($screen === 'purchase_requisitions')
    @if($record->status === 'draft' && $isOwnBranch) @can($permission.'.submit')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.submit', $record->doc_num) }}">{{ __('Submit') }}</button>@endcan @endif
    @if($record->status === 'pending_approval' && ($isAdministrativeBranch ?? false))
        @can('purchases.purchase_requisition_approvals.approve')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.approve', $record->doc_num) }}">{{ __('Approve') }}</button>@endcan
        @can('purchases.purchase_requisition_approvals.reject')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.reject', $record->doc_num) }}" data-reason-field="rejection_reason">{{ __('Reject') }}</button>@endcan
    @endif
    @if(($isAdministrativeBranch ?? false) && in_array($record->status, ['approved','partially_converted','fully_converted']))
        @can('purchases.supplier_quotation_entry.create') @can('purchases.prices.view')<a class="dropdown-item" href="{{ route('admin.purchases.supplier-quotation-entry.create-source', [\Modules\Purchases\Models\SupplierQuotation::SourcePurchaseRequisition, $record->doc_num]) }}">{{ __('Enter supplier quotation') }}</a>@endcan @endcan
        @if($record->status !== 'fully_converted') @can('purchase_orders.create') @can('purchases.prices.view')<a class="dropdown-item" href="{{ route('admin.purchases.purchase-orders.create', ['purchase_requisition_doc_nums' => [$record->doc_num]]) }}">{{ __('Create Purchase Order') }}</a>@endcan @endcan @endif
    @endif
    @if($record->status === 'approved' && ($isOwnBranch || ($isAdministrativeBranch ?? false))) @can($permission.'.cancel')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.cancel', $record->doc_num) }}" data-reason-field="cancel_reason">{{ __('Cancel') }}</button>@endcan @endif
@endif
@if($screen === 'request_for_quotations' && $draft && $isOwnBranch) @can($permission.'.approve')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.issue', $record->doc_num) }}">{{ __('Issue RFQ') }}</button>@endcan @endif
@if($screen === 'supplier_quotations' && $draft && $isOwnBranch) @can($permission.'.edit')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.submit', $record->doc_num) }}">{{ __('Submit') }}</button>@endcan @endif
@if($screen === 'supply_orders')
    @if($draft && $isOwnBranch)
        @can($permission.'.issue')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.issue', $record->doc_num) }}">{{ __('Issue Supply Order') }}</button>@endcan
    @elseif(in_array($record->status, ['issued', 'partially_received'], true))
        @if($isDestinationBranch && ! ($isAdministrativeBranch ?? false)) @can('purchases.goods_receipt_inspection.create')<a class="dropdown-item" href="{{ route('admin.purchases.goods-receipt-inspection.create', $record->doc_num) }}">{{ __('Create Purchase Inspection') }}</a>@endcan @endif
        @if($isOwnBranch) @can($permission.'.cancel')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.cancel', $record->doc_num) }}" data-reason-field="cancel_reason">{{ __('Cancel') }}</button>@endcan @endif
    @endif
@endif
@if($screen === 'goods_receipt_inspections' && $isOwnBranch && $record->receipt_id === null && in_array($record->result, ['accepted', 'partially_accepted'], true))
    @can('purchases.goods_receipt_notes.create')<a class="dropdown-item" href="{{ route('admin.purchases.goods-receipt-notes.create', $record->doc_num) }}">{{ __('Create Goods Receipt') }}</a>@endcan
@endif
@if($isOwnBranch && (($screen === 'goods_receipts' && $record->posting_status === 'posted') || ($screen === 'purchase_returns' && $record->status === 'posted'))) @can($permission.'.reverse')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.reverse', $record->doc_num) }}" data-reason-field="reversal_reason">{{ __('Reverse') }}</button>@endcan @endif
@if($isOwnBranch && $screen === 'goods_receipts' && $draft && $record->qc_status !== 'pending_inspection')
    @can($permission.'.post')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.post', $record->doc_num) }}">{{ __('Post') }}</button>@endcan
@endif
@if($screen === 'purchase_returns' && $draft && $isOwnBranch) @can($permission.'.approve')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.approve', $record->doc_num) }}">{{ __('Post') }}</button>@endcan @endif
@can($permission.'.print')<a class="dropdown-item" target="_blank" href="{{ route('admin.purchases.procurement.print', [$definition['type'], $record->doc_num]) }}">{{ __('Print') }}</a>@endcan
@if($editableDraft && Route::has($prefix.'.destroy') && $isOwnBranch) @can($permission.'.delete')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.destroy', $record->doc_num) }}" data-method="DELETE">{{ __('common.actions.delete') }}</button>@endcan @endif
@else
@can($permission.'.restore')<button type="button" class="dropdown-item text-success" data-procurement-action="{{ route('admin.purchases.procurement.restore', [$screen, $record->doc_num]) }}" data-method="PATCH">{{ __('common.actions.restore') }}</button>@endcan
@endif
</div></div>
