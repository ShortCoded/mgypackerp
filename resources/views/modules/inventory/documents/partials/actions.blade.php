<div class="dropdown position-static">
    <button class="btn btn-link btn-sm p-0 text-600" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('common.actions.view') }}">
        <span class="fas fa-ellipsis-h"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow-lg" style="z-index: 1080">
        @if($record->trashed())
            @can('inventory.documents.restore')<button class="dropdown-item text-success" type="button" data-action="restore" data-url="{{ route('admin.inventory.documents.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>@endcan
        @else
        <a class="dropdown-item" href="{{ route('admin.inventory.documents.show', $record) }}">{{ __('common.actions.view') }}</a>
        @can('inventory.documents.clone')<a class="dropdown-item" href="{{ route('admin.inventory.documents.clone', $record) }}">{{ __('common.actions.clone') }}</a>@endcan
        @if($record->status === \Modules\Inventory\Models\InventoryDocument::StatusDraft)
            @can('inventory.documents.edit')
                <a class="dropdown-item" href="{{ route('admin.inventory.documents.edit', $record) }}">{{ __('inventory.movements.actions.edit') }}</a>
            @endcan
            @can('inventory.documents.post')
                <button class="dropdown-item" type="button" data-action="post" data-url="{{ route('admin.inventory.documents.post', $record) }}">{{ __('inventory.movements.actions.post') }}</button>
            @endcan
            @can('inventory.documents.delete')<button class="dropdown-item text-danger" type="button" data-action="delete" data-url="{{ route('admin.inventory.documents.destroy', $record) }}">{{ __('common.actions.delete') }}</button>@endcan
        @endif
        @if($record->status !== \Modules\Inventory\Models\InventoryDocument::StatusDraft) @can('inventory.documents.print')
            <a class="dropdown-item" href="{{ route('admin.inventory.documents.print', $record) }}" target="_blank">{{ __('common.actions.print') }}</a>
        @endcan @endif
        @endif
    </div>
</div>
