@php
    $editable = $record->canEditBasicData();
    $deletable = $record->canEditMaster();
@endphp
<div class="d-flex gap-1 align-items-center justify-content-end">
    @can('fixed_assets.view')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.assets.show', $record) }}">{{ __('common.actions.view') }}</a>@endcan
    <div class="dropdown position-static">
        <button type="button" class="btn btn-falcon-default btn-sm px-2 white-space-nowrap" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}" title="{{ __('common.fields.actions') }}">
            <span class="fw-bold lh-1" aria-hidden="true">•••</span>
        </button>
        <div class="dropdown-menu dropdown-menu-end">
            @if($editable)@can('fixed_assets.edit')<a class="dropdown-item" href="{{ route('admin.fixed-assets.assets.edit', $record) }}"><span class="fas fa-edit me-2" aria-hidden="true"></span>{{ __('common.actions.edit') }}</a>@endcan @endif
            @can('fixed_assets.view')<a class="dropdown-item" href="{{ route('admin.fixed-assets.movements.index', ['asset_doc_num' => $record->doc_num]) }}"><span class="fas fa-exchange-alt me-2" aria-hidden="true"></span>{{ __('fixed_assets.product.movements') }}</a>@endcan
            @if($deletable)@can('fixed_assets.delete')<button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.fixed-assets.assets.destroy', $record) }}"><span class="fas fa-trash-alt me-2" aria-hidden="true"></span>{{ __('common.actions.delete') }}</button>@endcan @endif
            @if(!$deletable && !$record->trashed())@can('fixed_assets.delete')<span class="dropdown-item text-500 disabled" aria-disabled="true" title="{{ __('fixed_assets.messages.delete_blocked_lifecycle') }}"><span class="fas fa-lock me-2" aria-hidden="true"></span>{{ __('fixed_assets.product.delete_unavailable') }}</span>@endcan @endif
            @if($record->trashed())@can('fixed_assets.restore')<button type="button" class="dropdown-item js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.fixed-assets.assets.restore', $record) }}"><span class="fas fa-undo me-2" aria-hidden="true"></span>{{ __('common.actions.restore') }}</button>@endcan @endif
        </div>
    </div>
</div>
