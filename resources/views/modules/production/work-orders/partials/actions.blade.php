@php
    $isTrashed = $record->trashed();
    $canView = ! $isTrashed && auth()->user()?->can('production.orders.view');
    $canClone = ! $isTrashed && auth()->user()?->can('production.orders.clone');
    $canEdit = ! $isTrashed && $record->status === \Modules\Production\Models\ProductionOrder::StatusDraft && $record->runs_count === 0 && auth()->user()?->can('production.orders.edit');
    $canDelete = ! $isTrashed && $record->status === \Modules\Production\Models\ProductionOrder::StatusDraft && $record->runs_count === 0 && auth()->user()?->can('production.orders.delete');
    $canRestore = $isTrashed && auth()->user()?->can('production.orders.restore');
@endphp

@if ($canView || $canClone || $canEdit || $canDelete || $canRestore)
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.production.work-orders.show', $record) }}">{{ __('common.actions.view') }}</a>
            @endif
            @if ($canEdit)
                <a class="dropdown-item" href="{{ route('admin.production.work-orders.edit', $record) }}">{{ __('common.actions.edit') }}</a>
            @endif
            @if ($canClone)
                <a class="dropdown-item" href="{{ route('admin.production.work-orders.clone', $record) }}">{{ __('common.actions.clone_record') }}</a>
            @endif
            @if ($canDelete)
                <div class="dropdown-divider"></div>
                <button class="dropdown-item text-danger" type="button" data-action="delete" data-confirm="{{ __('production_execution.messages.confirm_delete') }}" data-url="{{ route('admin.production.work-orders.destroy', $record) }}">{{ __('common.actions.delete') }}</button>
            @endif
            @if ($canRestore)
                @if ($canView)
                    <div class="dropdown-divider"></div>
                @endif
                <button class="dropdown-item text-success" type="button" data-action="restore" data-url="{{ route('admin.production.work-orders.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>
            @endif
        </div>
    </div>
@endif
