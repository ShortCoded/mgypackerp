@php
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isView = $mode === 'view';
    $isTrashed = $record?->trashed() ?? false;
    $canManageInCurrentBranch = $canManageInCurrentBranch ?? false;
@endphp

<div class="d-flex flex-wrap gap-2 justify-content-end">
    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-invoices.index') }}">
        <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
    </a>

    @if($isView && $record && ! $isTrashed)
        @can('purchase_invoices.print')
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-invoices.print', $record->doc_num) }}" target="_blank" rel="noopener">
                <span class="fas fa-print me-1"></span>{{ __('purchase_invoices.actions.print') }}
            </a>
        @endcan
    @endif

    @if($isView && $record && ! $isTrashed && $canManageInCurrentBranch)
        @can('purchase_invoices.edit')
            @if(! $record->isLockedForEditing())
                <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.purchase-invoices.edit', $record->doc_num) }}">
                    <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
                </a>
            @endif
        @endcan
        @can('purchase_invoices.clone')
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-invoices.clone', $record->doc_num) }}">
                <span class="fas fa-copy me-1"></span>{{ __('common.actions.clone') }}
            </a>
        @endcan
        @if($record->isDraft())
            @can('purchase_invoices.approve')
                <button class="btn btn-falcon-success btn-sm js-purchase-invoice-action" type="button" data-url="{{ route('admin.purchases.purchase-invoices.approve', $record->doc_num) }}" data-method="POST" data-action="approve">
                    <span class="fas fa-check me-1"></span>{{ __('purchase_invoices.actions.approve') }}
                </button>
            @endcan
            @can('purchase_invoices.reverse')
                <button class="btn btn-falcon-danger btn-sm js-purchase-invoice-action" type="button" data-url="{{ route('admin.purchases.purchase-invoices.cancel', $record->doc_num) }}" data-method="POST" data-action="cancel">
                    <span class="fas fa-ban me-1"></span>{{ __('purchase_invoices.actions.cancel') }}
                </button>
            @endcan
        @elseif($record->isApproved())
            @if($record->purchaseOrder)
                @can('purchases.supply_orders.create')
                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.supply-orders.create', ['purchase_invoice' => $record->doc_num]) }}">
                        <span class="fas fa-truck-loading me-1"></span>{{ __('Create Supply Order') }}
                    </a>
                @endcan
            @endif
            @if((float) $record->remaining_amount > 0)
                @can('supplier_payments.create')
                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.supplier-payments.create', ['invoice' => $record->doc_num]) }}">
                        <span class="fas fa-money-bill-wave me-1"></span>{{ __('Create Supplier payment') }}
                    </a>
                @endcan
            @endif
            @can('purchase_invoices.close')
                <button class="btn btn-falcon-primary btn-sm js-purchase-invoice-action" type="button" data-url="{{ route('admin.purchases.purchase-invoices.close', $record->doc_num) }}" data-method="POST" data-action="close">
                    <span class="fas fa-lock me-1"></span>{{ __('purchase_invoices.actions.close') }}
                </button>
            @endcan
            @can('purchase_invoices.reverse')
                <button class="btn btn-falcon-danger btn-sm js-purchase-invoice-action" type="button" data-url="{{ route('admin.purchases.purchase-invoices.reverse', $record->doc_num) }}" data-method="POST" data-action="cancel">
                    <span class="fas fa-undo me-1"></span>{{ __('Reverse posted Invoice') }}
                </button>
            @endcan
        @elseif($record->isClosed())
            @if($record->purchaseOrder)
                @can('purchases.supply_orders.create')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supply-orders.create', ['purchase_invoice' => $record->doc_num]) }}">
                        <span class="fas fa-truck-loading me-1"></span>{{ __('Create Supply Order') }}
                    </a>
                @endcan
            @endif
            @if((float) $record->remaining_amount > 0)
                @can('supplier_payments.create')
                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.supplier-payments.create', ['invoice' => $record->doc_num]) }}">
                        <span class="fas fa-money-bill-wave me-1"></span>{{ __('Create Supplier payment') }}
                    </a>
                @endcan
            @endif
            @can('purchase_invoices.cancel')
                <button class="btn btn-falcon-danger btn-sm js-purchase-invoice-action" type="button" data-url="{{ route('admin.purchases.purchase-invoices.reverse', $record->doc_num) }}" data-method="POST" data-action="cancel">
                    <span class="fas fa-undo me-1"></span>{{ __('Reverse posted Invoice') }}
                </button>
            @endcan
        @endif
    @endif

    @if($isTrashed && $record)
        @can('purchase_invoices.restore')
            <button class="btn btn-falcon-success btn-sm js-purchase-invoice-action" type="button" data-url="{{ route('admin.purchases.purchase-invoices.restore', $record->doc_num) }}" data-method="PATCH" data-action="restore">
                <span class="fas fa-trash-restore me-1"></span>{{ __('common.actions.restore') }}
            </button>
        @endcan
    @endif

    @unless($isView || $isTrashed)
        <div class="btn-group">
            <button class="btn btn-falcon-primary btn-sm js-purchase-invoice-save" type="submit" data-submit-action="save">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}
            </button>
            <button class="btn btn-falcon-primary btn-sm dropdown-toggle dropdown-caret-none" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
                <span class="fas fa-caret-down"></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end py-2">
                <button class="dropdown-item js-purchase-invoice-save" type="submit" data-submit-action="save_back">{{ __('common.actions.save_and_back') }}</button>
                <button class="dropdown-item js-purchase-invoice-save" type="submit" data-submit-action="save_view">{{ __('purchase_invoices.actions.save_view') }}</button>
                <button class="dropdown-item js-purchase-invoice-save" type="submit" data-submit-action="save_edit">{{ __('purchase_invoices.actions.save_edit') }}</button>
            </div>
        </div>
    @endunless
</div>
