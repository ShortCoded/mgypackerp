@php
    $editable = $record->canEditBasicData();
    $deletable = $record->canEditMaster();
@endphp
<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
        <span class="fas fa-ellipsis-h fs-10" aria-hidden="true"></span>
    </button>
    <div class="py-2 border dropdown-menu dropdown-menu-end">
        @if($record->trashed())
            @can('fixed_assets.view')<a class="dropdown-item" href="{{ route('admin.fixed-assets.assets.show', $record) }}">{{ __('common.actions.view') }}</a>@endcan
            @can('fixed_assets.restore')<div class="dropdown-divider"></div><button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.fixed-assets.assets.restore', $record) }}">{{ __('common.actions.restore') }}</button>@endcan
        @else
            @can('fixed_assets.view')<a class="dropdown-item" href="{{ route('admin.fixed-assets.assets.show', $record) }}">{{ __('common.actions.view') }}</a>@endcan
            @if($editable)@can('fixed_assets.edit')<a class="dropdown-item" href="{{ route('admin.fixed-assets.assets.edit', $record) }}">{{ __('common.actions.edit') }}</a>@endcan @endif
            @can('fixed_assets.movements.view')<a class="dropdown-item" href="{{ route('admin.fixed-assets.movements.index', ['asset_doc_num' => $record->doc_num]) }}">{{ __('fixed_assets.product.movements') }}</a>@endcan
            @if($deletable)@can('fixed_assets.delete')<div class="dropdown-divider"></div><button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-record-name="{{ $record->asset_name }}" data-delete-url="{{ route('admin.fixed-assets.assets.destroy', $record) }}">{{ __('common.actions.delete') }}</button>@endcan @endif
            @if(!$deletable)@can('fixed_assets.delete')<span class="dropdown-item text-500 disabled" aria-disabled="true" title="{{ __('fixed_assets.messages.delete_blocked_lifecycle') }}">{{ __('fixed_assets.product.delete_unavailable') }}</span>@endcan @endif
        @endif
    </div>
</div>
