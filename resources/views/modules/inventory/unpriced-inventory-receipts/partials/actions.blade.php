@php
    $isTrashed = $record->trashed();
    $canView = auth()->user()?->can('inventory.unpriced_inventory_receipts.view') && $record->doc_num !== null;
    $canApprove = ! $isTrashed && ! $record->isApproved() && ! $record->isClosed() && ! $record->isCancelled() && auth()->user()?->can('inventory.unpriced_inventory_receipts.approve') && $record->doc_num !== null;
    $canClose = ! $isTrashed && $record->isApproved() && ! $record->isClosed() && ! $record->isCancelled() && auth()->user()?->can('inventory.unpriced_inventory_receipts.close') && $record->doc_num !== null;
    $canCancel = ! $isTrashed && ! $record->isClosed() && ! $record->isCancelled() && auth()->user()?->can('inventory.unpriced_inventory_receipts.cancel') && $record->doc_num !== null;
    $canEdit = ! $isTrashed && ! $record->isLockedForEditing() && auth()->user()?->can('inventory.unpriced_inventory_receipts.edit') && $record->doc_num !== null;
    $canDelete = ! $isTrashed && ! $record->isLockedForEditing() && auth()->user()?->can('inventory.unpriced_inventory_receipts.delete') && $record->doc_num !== null;
    $canRestore = $isTrashed && auth()->user()?->can('inventory.unpriced_inventory_receipts.restore') && $record->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || $canApprove || $canClose || $canCancel || $canEdit || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.inventory.unpriced-inventory-receipts.show', $record->doc_num) }}">{{ __('common.actions.view') }}</a>
            @endif
            @if ($canApprove)
                <button class="dropdown-item js-approve-unpriced-inventory-receipt" type="button" data-url="{{ route('admin.inventory.unpriced-inventory-receipts.approve', $record->doc_num) }}">{{ __('inventory.unpriced_inventory_receipts.actions.approve') }}</button>
            @endif
            @if ($canClose)
                <button class="dropdown-item js-close-unpriced-inventory-receipt" type="button" data-url="{{ route('admin.inventory.unpriced-inventory-receipts.close', $record->doc_num) }}">{{ __('inventory.unpriced_inventory_receipts.actions.close') }}</button>
            @endif
            @if ($canCancel)
                <button class="dropdown-item text-warning js-cancel-unpriced-inventory-receipt" type="button" data-url="{{ route('admin.inventory.unpriced-inventory-receipts.cancel', $record->doc_num) }}">{{ __('inventory.unpriced_inventory_receipts.actions.cancel') }}</button>
            @endif
            @if ($canEdit)
                <a class="dropdown-item" href="{{ route('admin.inventory.unpriced-inventory-receipts.edit', $record->doc_num) }}">{{ __('common.actions.edit') }}</a>
            @endif
            @if ($canRestore)
                <button class="dropdown-item text-success js-restore-record" type="button" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.inventory.unpriced-inventory-receipts.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>
            @endif
            @if ($canDelete)
                <div class="dropdown-divider"></div>
                <button class="dropdown-item text-danger js-delete-record" type="button" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.inventory.unpriced-inventory-receipts.destroy', $record->doc_num) }}">{{ __('common.actions.delete') }}</button>
            @endif
        </div>
    </div>
@endif
