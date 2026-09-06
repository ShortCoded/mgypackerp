@php
    $docNum = $record->doc_num;
    $isTrashed = $record->trashed();
@endphp

<div class="btn-reveal-trigger position-static">
    <button class="btn btn-sm dropdown-toggle dropdown-caret-none transition-none btn-reveal fs-10" type="button" data-bs-toggle="dropdown" data-boundary="window" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
        <span class="fas fa-ellipsis-h fs-10"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end py-2">
        @can('purchase_orders.view')
            <a class="dropdown-item" href="{{ route('admin.purchases.purchase-orders.show', $docNum) }}">
                <span class="fas fa-eye me-2"></span>{{ __('common.actions.view') }}
            </a>
        @endcan

        @if(! $isTrashed && ! $record->isLockedForEditing())
            @can('purchase_orders.edit')
                <a class="dropdown-item" href="{{ route('admin.purchases.purchase-orders.edit', $docNum) }}">
                    <span class="fas fa-edit me-2"></span>{{ __('common.actions.edit') }}
                </a>
            @endcan
        @endif

        @if(! $isTrashed)
            @can('purchase_orders.print')
                <a class="dropdown-item" href="{{ route('admin.purchases.purchase-orders.print', $docNum) }}" target="_blank" rel="noopener">
                    <span class="fas fa-print me-2"></span>{{ __('purchase_orders.actions.print') }}
                </a>
            @endcan
        @endif

        @if(!$isTrashed && $record->status === 'draft')
            @can('purchase_orders.submit')<button class="dropdown-item js-purchase-order-row-action" type="button" data-url="{{ route('admin.purchases.purchase-orders.submit', $docNum) }}" data-action="submit">{{ __('Submit') }}</button>@endcan
        @endif
        @if(!$isTrashed && $record->status === 'submitted')
            @can('purchase_orders.reject')<button class="dropdown-item text-danger js-purchase-order-row-action" type="button" data-url="{{ route('admin.purchases.purchase-orders.reject', $docNum) }}" data-action="reject">{{ __('Reject') }}</button>@endcan
        @endif
        @if(!$isTrashed && $record->isApproved())
            @can('purchases.supplier_quotation_entry.create') @can('purchases.prices.view')<a class="dropdown-item" href="{{ route('admin.purchases.supplier-quotation-entry.create-source', [\Modules\Purchases\Models\SupplierQuotation::SourcePurchaseOrder, $docNum]) }}">{{ __('Enter supplier quotation') }}</a>@endcan @endcan
            @can('purchases.supply_orders.create')<a class="dropdown-item" href="{{ route('admin.purchases.supply-orders.create', ['purchase_order' => $docNum]) }}">{{ __('Create Supply Order') }}</a>@endcan
            @can('purchase_invoices.create')<a class="dropdown-item" href="{{ route('admin.purchases.purchase-invoices.create', ['purchase_order' => $docNum]) }}">{{ __('Create supplier invoice') }}</a>@endcan
            @if(!$record->sent_at) @can('purchase_orders.send')<button class="dropdown-item js-purchase-order-row-action" type="button" data-url="{{ route('admin.purchases.purchase-orders.sent', $docNum) }}" data-action="send">{{ __('Mark as sent') }}</button>@endcan @endif
        @endif
        @if(! $isTrashed && $record->status === 'submitted')
            @can('purchase_orders.approve')
                <button class="dropdown-item js-purchase-order-row-action" type="button" data-url="{{ route('admin.purchases.purchase-orders.approve', $docNum) }}" data-method="POST" data-action="approve">
                    <span class="fas fa-check me-2"></span>{{ __('purchase_orders.actions.approve') }}
                </button>
            @endcan
        @endif

        @if(! $isTrashed && $record->isApproved())
            @can('purchase_orders.close')
                <button class="dropdown-item js-purchase-order-row-action" type="button" data-url="{{ route('admin.purchases.purchase-orders.close', $docNum) }}" data-method="POST" data-action="close">
                    <span class="fas fa-lock me-2"></span>{{ __('purchase_orders.actions.close') }}
                </button>
            @endcan
        @endif

        @if(! $isTrashed && ! $record->isClosed() && ! $record->isCancelled() && ! $record->hasReceipts())
            @can('purchase_orders.cancel')
                <button class="dropdown-item text-danger js-purchase-order-row-action" type="button" data-url="{{ route('admin.purchases.purchase-orders.cancel', $docNum) }}" data-method="POST" data-action="cancel">
                    <span class="fas fa-ban me-2"></span>{{ __('purchase_orders.actions.cancel') }}
                </button>
            @endcan
        @endif

        @if($isTrashed)
            @can('purchase_orders.restore')
                <button class="dropdown-item text-success js-purchase-order-row-action" type="button" data-url="{{ route('admin.purchases.purchase-orders.restore', $docNum) }}" data-method="PATCH" data-action="restore">
                    <span class="fas fa-trash-restore me-2"></span>{{ __('common.actions.restore') }}
                </button>
            @endcan
        @elseif($record->isDeletable())
            @can('purchase_orders.delete')
                <div class="dropdown-divider"></div>
                <button class="dropdown-item text-danger js-purchase-order-row-action" type="button" data-url="{{ route('admin.purchases.purchase-orders.destroy', $docNum) }}" data-method="DELETE" data-action="delete">
                    <span class="fas fa-trash-alt me-2"></span>{{ __('common.actions.delete') }}
                </button>
            @endcan
        @endif
    </div>
</div>
