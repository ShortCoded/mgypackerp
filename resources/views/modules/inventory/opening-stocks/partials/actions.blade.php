@php
    $isTrashed = $record->trashed();
    $canView = auth()->user()?->can('inventory.opening_stocks.view') && $record->doc_num !== null;
    $canApprove = ! $isTrashed && ! $record->approved && auth()->user()?->can('inventory.opening_stocks.approve') && $record->doc_num !== null;
    $canEdit = ! $isTrashed && ! $record->isLockedForEditing() && auth()->user()?->can('inventory.opening_stocks.edit') && $record->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can('inventory.opening_stocks.clone') && $record->doc_num !== null;
    $canDelete = ! $isTrashed && ! $record->isLockedForEditing() && auth()->user()?->can('inventory.opening_stocks.delete') && $record->doc_num !== null;
    $canRestore = $isTrashed && auth()->user()?->can('inventory.opening_stocks.restore') && $record->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || $canApprove || $canEdit || $canClone || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.inventory.opening-stocks.show', $record->doc_num) }}">{{ __('common.actions.view') }}</a>
            @endif
            @if ($canApprove)
                <button class="dropdown-item js-approve-opening-stock" type="button" data-url="{{ route('admin.inventory.opening-stocks.approve', $record->doc_num) }}">{{ __('inventory.opening_stocks.actions.approve') }}</button>
            @endif
            @if ($canEdit)
                <a class="dropdown-item" href="{{ route('admin.inventory.opening-stocks.edit', $record->doc_num) }}">{{ __('common.actions.edit') }}</a>
            @endif
            @if ($canClone)
                <a class="dropdown-item" href="{{ route('admin.inventory.opening-stocks.clone', $record->doc_num) }}">{{ __('common.actions.clone_record') }}</a>
            @endif
            @if ($canRestore)
                <button class="dropdown-item text-success js-restore-record" type="button" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.inventory.opening-stocks.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>
            @endif
            @if ($canDelete)
                <div class="dropdown-divider"></div>
                <button class="dropdown-item text-danger js-delete-record" type="button" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.inventory.opening-stocks.destroy', $record->doc_num) }}">{{ __('common.actions.delete') }}</button>
            @endif
        </div>
    </div>
@endif
