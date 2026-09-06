@php($editable = $record->canEditMaster())
<div class="d-flex gap-1 align-items-center">
    @can('fixed_assets.view')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.assets.show', $record) }}">{{ __('common.actions.view') }}</a>@endcan
    <div class="dropdown position-static">
        <button type="button" class="btn btn-link btn-sm text-600" data-bs-toggle="dropdown" aria-label="{{ __('common.fields.actions') }}"><span class="fas fa-ellipsis-h"></span></button>
        <div class="dropdown-menu dropdown-menu-end">
            @if($editable)@can('fixed_assets.edit')<a class="dropdown-item" href="{{ route('admin.fixed-assets.assets.edit', $record) }}">{{ __('common.actions.edit') }}</a>@endcan @endif
            @can('fixed_assets.view')<a class="dropdown-item" href="{{ route('admin.fixed-assets.movements.index', ['asset_doc_num' => $record->doc_num]) }}">{{ __('fixed_assets.product.movements') }}</a>@endcan
            @if($editable)@can('fixed_assets.delete')<button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.fixed-assets.assets.destroy', $record) }}">{{ __('common.actions.delete') }}</button>@endcan @endif
            @if($record->trashed())@can('fixed_assets.restore')<button type="button" class="dropdown-item js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.fixed-assets.assets.restore', $record) }}">{{ __('common.actions.restore') }}</button>@endcan @endif
        </div>
    </div>
</div>
