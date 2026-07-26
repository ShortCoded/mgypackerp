@php($isTrashed = $record->trashed())
<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-label="{{ __('common.fields.actions') }}"><span class="fas fa-ellipsis-h fs-10"></span></button>
    <div class="py-2 border dropdown-menu dropdown-menu-end">
        @can('screen_data_visibility_rules.view')<a class="dropdown-item" href="{{ route('admin.screen-data-visibility-rules.show', $record->doc_num) }}">{{ __('common.actions.view') }}</a>@endcan
        @if (! $isTrashed)
            @can('screen_data_visibility_rules.edit')<a class="dropdown-item js-edit-record" href="{{ route('admin.screen-data-visibility-rules.edit', $record->doc_num) }}">{{ __('common.actions.edit') }}</a>@endcan
            @can('screen_data_visibility_rules.clone')<a class="dropdown-item" href="{{ route('admin.screen-data-visibility-rules.clone', $record->doc_num) }}">{{ __('common.actions.clone_record') }}</a>@endcan
            @can('screen_data_visibility_rules.delete')<div class="dropdown-divider"></div><button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.screen-data-visibility-rules.destroy', $record->doc_num) }}">{{ __('common.actions.delete') }}</button>@endcan
        @else
            @can('screen_data_visibility_rules.restore')<div class="dropdown-divider"></div><button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.screen-data-visibility-rules.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>@endcan
        @endif
    </div>
</div>
