<div class="dropdown position-static">
    <button class="btn btn-link btn-sm p-0 text-600" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('common.actions.view') }}">
        <span class="fas fa-ellipsis-h"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow-lg" style="z-index: 1080">
        <a class="dropdown-item" href="{{ route('admin.inventory.documents.show', $record) }}">{{ __('common.actions.view') }}</a>
        @if($record->status === \Modules\Inventory\Models\InventoryDocument::StatusDraft)
            @can('inventory.documents.edit')
                <a class="dropdown-item" href="{{ route('admin.inventory.documents.edit', $record) }}">{{ __('inventory.movements.actions.edit') }}</a>
            @endcan
            @can('inventory.documents.post')
                <button class="dropdown-item" type="button" data-action="post" data-url="{{ route('admin.inventory.documents.post', $record) }}">{{ __('inventory.movements.actions.post') }}</button>
            @endcan
        @endif
        @if($record->status !== \Modules\Inventory\Models\InventoryDocument::StatusDraft) @can('inventory.documents.print')
            <a class="dropdown-item" href="{{ route('admin.inventory.documents.print', $record) }}" target="_blank">{{ __('common.actions.print') }}</a>
        @endcan @endif
    </div>
</div>
