@php
    $canView = auth()->user()?->can('inventory.stock_counts.view');
    $canApprove = ! $record->trashed() && $record->status === \Modules\Inventory\Models\StockCount::StatusCounted && auth()->user()?->can('inventory.stock_counts.approve');
    $canEdit = $record->isEditable() && auth()->user()?->can('inventory.stock_counts.edit');
    $canClone = ! $record->trashed() && auth()->user()?->can('inventory.stock_counts.clone');
    $canDelete = $record->isEditable() && auth()->user()?->can('inventory.stock_counts.delete');
    $canRestore = $record->trashed() && auth()->user()?->can('inventory.stock_counts.restore');
@endphp

@if($canView || $canApprove || $canEdit || $canClone || $canDelete || $canRestore)
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if($canView)<a class="dropdown-item" href="{{ route('admin.inventory.stock-counts.show', $record) }}">{{ __('common.actions.view') }}</a>@endif
            @if($canEdit)<a class="dropdown-item" href="{{ route('admin.inventory.stock-counts.edit', $record) }}">{{ __('common.actions.edit') }}</a>@endif
            @if($canApprove)<button class="dropdown-item js-approve-stock-count" type="button" data-url="{{ route('admin.inventory.stock-counts.approve', $record) }}">{{ __('inventory.stock_counts.actions.approve') }}</button>@endif
            @if($canClone)<a class="dropdown-item" href="{{ route('admin.inventory.stock-counts.clone', $record) }}">{{ __('common.actions.clone_record') }}</a>@endif
            @if($canRestore)<button class="dropdown-item text-success js-restore-record" type="button" data-restore-url="{{ route('admin.inventory.stock-counts.restore', $record) }}">{{ __('common.actions.restore') }}</button>@endif
            @if($canDelete)
                <div class="dropdown-divider"></div>
                <button class="dropdown-item text-danger js-delete-record" type="button" data-delete-url="{{ route('admin.inventory.stock-counts.destroy', $record) }}">{{ __('common.actions.delete') }}</button>
            @endif
        </div>
    </div>
@endif
