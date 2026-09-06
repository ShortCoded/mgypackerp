@php
    $prefix = 'admin.purchases.'.$definition['route'];
    $permission = 'purchases.'.$definition['permission'];
    $draft = $record->status === 'draft' && ($screen !== 'goods_receipts' || $record->posting_status === 'unposted');
@endphp
<div class="dropdown font-sans-serif position-static">
<button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" aria-label="{{ __('Actions') }}"><span class="fas fa-ellipsis-h"></span></button>
<div class="dropdown-menu dropdown-menu-end py-2">
@if(!$record->trashed())
<a class="dropdown-item" href="{{ route($prefix.'.show', $record->doc_num) }}">{{ __('common.actions.view') }}</a>
@if($draft && Route::has($prefix.'.edit')) @can($permission.'.edit')<a class="dropdown-item" href="{{ route($prefix.'.edit', $record->doc_num) }}">{{ __('common.actions.edit') }}</a>@endcan @endif
@if($screen === 'purchase_requisitions')
    @if($record->status === 'draft') @can($permission.'.submit')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.submit', $record->doc_num) }}">{{ __('Submit') }}</button>@endcan @endif
    @if($record->status === 'pending_approval')
        @can('purchases.purchase_requisition_approvals.approve')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.approve', $record->doc_num) }}">{{ __('Approve') }}</button>@endcan
        @can('purchases.purchase_requisition_approvals.reject')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.reject', $record->doc_num) }}" data-reason-field="rejection_reason">{{ __('Reject') }}</button>@endcan
    @endif
    @if(in_array($record->status, ['approved','partially_converted']))
        @can('purchase_orders.create') @can('purchases.prices.view')<a class="dropdown-item" href="{{ route('admin.purchases.purchase-orders.create', ['purchase_requisition_doc_nums' => [$record->doc_num]]) }}">{{ __('Create Purchase Order') }}</a>@endcan @endcan
        @can('purchases.request_for_quotations.create')<a class="dropdown-item" href="{{ route('admin.purchases.request-for-quotations.create', $record->doc_num) }}">{{ __('Create Request for Quotation') }}</a>@endcan
    @endif
    @if($record->status === 'approved') @can($permission.'.cancel')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.cancel', $record->doc_num) }}" data-reason-field="cancel_reason">{{ __('Cancel') }}</button>@endcan @endif
@endif
@if($screen === 'request_for_quotations' && $draft) @can($permission.'.approve')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.issue', $record->doc_num) }}">{{ __('Issue RFQ') }}</button>@endcan @endif
@if($screen === 'supplier_quotations' && $draft) @can($permission.'.edit')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.submit', $record->doc_num) }}">{{ __('Submit') }}</button>@endcan @endif
@if(($screen === 'goods_receipts' && $record->posting_status === 'posted') || ($screen === 'purchase_returns' && $record->status === 'posted')) @can($permission.'.reverse')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.reverse', $record->doc_num) }}" data-reason-field="reversal_reason">{{ __('Reverse') }}</button>@endcan @endif
@if($screen === 'goods_receipts' && $draft) @can($permission.'.post')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.post', $record->doc_num) }}">{{ __('Post') }}</button>@endcan @endif
@if($screen === 'purchase_returns' && $draft) @can($permission.'.approve')<button type="button" class="dropdown-item" data-procurement-action="{{ route($prefix.'.approve', $record->doc_num) }}">{{ __('Post') }}</button>@endcan @endif
@can($permission.'.print')<a class="dropdown-item" target="_blank" href="{{ route('admin.purchases.procurement.print', [$definition['type'], $record->doc_num]) }}">{{ __('Print') }}</a>@endcan
@if($draft && Route::has($prefix.'.destroy')) @can($permission.'.delete')<button type="button" class="dropdown-item text-danger" data-procurement-action="{{ route($prefix.'.destroy', $record->doc_num) }}" data-method="DELETE">{{ __('common.actions.delete') }}</button>@endcan @endif
@else
@can($permission.'.restore')<button type="button" class="dropdown-item text-success" data-procurement-action="{{ route('admin.purchases.procurement.restore', [$screen, $record->doc_num]) }}" data-method="PATCH">{{ __('common.actions.restore') }}</button>@endcan
@endif
</div></div>
