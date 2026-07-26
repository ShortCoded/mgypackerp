@php
    $canList = auth()->user()?->can('screen_data_visibility_rules.view');
    $canSave = ! $isView;
@endphp
<div class="d-flex flex-wrap justify-content-end gap-2">
    @if ($canList)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.screen-data-visibility-rules.index') }}" data-shortcut-action="form.back"><span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}</a>@endif
    @if ($isView && $record)
        @if (! $isTrashed)@can('screen_data_visibility_rules.edit')<a class="btn btn-primary btn-sm" href="{{ route('admin.screen-data-visibility-rules.edit', $record->doc_num) }}" data-shortcut-action="form.edit"><span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}</a>@endcan @endif
        @if (! $isTrashed)@can('screen_data_visibility_rules.clone')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.screen-data-visibility-rules.clone', $record->doc_num) }}" data-shortcut-action="form.clone"><span class="fas fa-copy me-1"></span>{{ __('common.actions.clone_record') }}</a>@endcan @endif
        @if ($isTrashed)@can('screen_data_visibility_rules.restore')<button type="button" class="btn btn-falcon-default text-success btn-sm js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.screen-data-visibility-rules.restore', $record->doc_num) }}"><span class="fas fa-undo me-1"></span>{{ __('common.actions.restore') }}</button>@endcan @endif
        @if (! $isTrashed)@can('screen_data_visibility_rules.delete')<button type="button" class="btn btn-falcon-default text-danger btn-sm js-delete-record" data-shortcut-action="form.delete" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.screen-data-visibility-rules.destroy', $record->doc_num) }}" data-redirect-url="{{ route('admin.screen-data-visibility-rules.index') }}"><span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}</button>@endcan @endif
    @endif
    @if ($canSave)
        <div class="btn-group btn-group-sm">
            <button type="submit" class="btn btn-primary js-screen-visibility-submit" data-submit-action="save" data-shortcut-action="form.save"><span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}</button>
            <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown"><span class="visually-hidden">{{ __('common.fields.actions') }}</span></button>
            <div class="dropdown-menu dropdown-menu-end py-2">
                @can('screen_data_visibility_rules.view')<button class="dropdown-item js-screen-visibility-submit" type="submit" data-submit-action="save_view">{{ __('common.actions.save_and_view') }}</button>@endcan
                @if(in_array($mode, ['create', 'clone'], true))@can('screen_data_visibility_rules.edit')<button class="dropdown-item js-screen-visibility-submit" type="submit" data-submit-action="save_edit">{{ __('common.actions.save_and_edit') }}</button>@endcan @endif
                @if($canList)<button class="dropdown-item js-screen-visibility-submit" type="submit" data-submit-action="save_back">{{ __('common.actions.save_and_back') }}</button>@endif
                @can('screen_data_visibility_rules.clone')<button class="dropdown-item js-screen-visibility-submit" type="submit" data-submit-action="save_clone">{{ __('common.actions.save_and_clone') }}</button>@endcan
            </div>
        </div>
    @endif
</div>
