@php
    $isTrashed = $record->trashed();
    $canRestore = $isTrashed
        && auth()->user()?->can($definition->permission('restore'))
        && $record->doc_num !== null;
    $canView = auth()->user()?->can($definition->permission('view'))
        && $record->doc_num !== null;
    $canClone = ! $isTrashed
        && auth()->user()?->can($definition->permission('clone'))
        && $record->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || auth()->user()?->can($definition->permission('edit')) || $canClone || auth()->user()?->can($definition->permission('delete')))) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route($definition->route('show'), $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-record-name="{{ $record->name }}" data-restore-url="{{ route($definition->route('restore'), $record->doc_num) }}" data-hr-restore-url="{{ route($definition->route('restore'), $record->doc_num) }}">
                        {{ __('hr.trash.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route($definition->route('show'), $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @can($definition->permission('edit'))
                    <a class="dropdown-item js-edit-record" href="{{ route($definition->route('edit'), $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endcan
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route($definition->route('clone'), $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @can($definition->permission('delete'))
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-record-name="{{ $record->name }}" data-delete-url="{{ route($definition->route('destroy'), $record->doc_num) }}" data-hr-delete-url="{{ route($definition->route('destroy'), $record->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endcan
            @endif
        </div>
    </div>
@endif
